<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Transcribe WhatsApp voice notes with OpenAI speech-to-text.
 *
 * The audio is spoken Palestinian Arabic mixed with Hebrew telecom terms,
 * recorded on phones in noisy places, so every request carries domain context
 * and every transcript is scored before the bot is allowed to answer it.
 */
class WhatsAppAudioTranscriber
{
    public const UNCLEAR_REPLY_AR = 'الصوت مش واضح معي، ما قدرت أفهمك… ابعتلي تاني أو اكتبلي.';

    public const UNCLEAR_DISPLAY_AR = 'رسالة صوتية (غير واضحة)';

    public const VOICE_LABEL_AR = 'رسالة صوتية';

    private const ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';

    private const CHAT_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /** Models that take keywords[] / languages[] instead of a single language. */
    private const LANGUAGE_LIST_MODELS = ['gpt-transcribe', 'gpt-live-transcribe'];

    /** Container formats the transcription endpoint accepts. */
    private const SUPPORTED_EXTENSIONS = ['flac', 'm4a', 'mp3', 'mp4', 'mpeg', 'mpga', 'oga', 'ogg', 'wav', 'webm'];

    /** What the recording is, so ambiguous words resolve our way. */
    private const CONTEXT_PROMPT = 'رسالة صوتية على واتساب من زبون بالطيبة برد على سالي من شركة ملان انترنت. الحكي عربي عامية فلسطينية (مش فصحى) وفيه مصطلحات عبرية عن الانترنت والاتصالات. الردود القصيرة شائعة: اه، لا، مش حابب، ما بدي، قديش السعر، بدي أعرف. انسخ العبري كما هو: סיב אופטי، משרד התקשורת، תשתית، ספק، מגדיל טווח.';

    private const KAMAN_CONTEXT_PROMPT = 'رسالة صوتية على واتساب من صاحب مطعم أو مخبز أو كشك برد على KAMAN POS. الحكي عربي عامية فلسطينية مخلوط عبري عن كاشير وكوباه ومعريخت وهات وأفيف وتسيود ومطبخ وتوصيل وشليح.';

    /**
     * Literal terms that come back mangled without a hint.
     *
     * @var list<string>
     */
    private const KEYWORDS = [
        'ملان',
        'سبيدكوم',
        'سالي',
        'الطيبة',
        'رهط',
        'اللقية',
        'كفر قاسم',
        'סיב אופטי',
        'משרד התקשורת',
        'תשתית',
        'ספק',
        'מגדיל טווח',
        'بيزك',
        'בזק',
        'هوت',
        'הוט',
        'انترنت',
        'راوتر',
        'فايبر',
        'ميجا',
        'شيقل',
        'تركيب',
        'اشتراك',
        'تقطيعات',
        'دعم فني',
        'اه',
        'لا',
        'مش حابب',
        'ما بدي',
        'قديش السعر',
        'الطيبه',
    ];

    /**
     * @var list<string>
     */
    private const KAMAN_KEYWORDS = [
        'KAMAN',
        'كوباه',
        'كوبا',
        'معريخت',
        'هات',
        'HAAT',
        'האט',
        'אביב',
        'تابيت',
        'لينكوبوت',
        'تسيود',
        'ציוד',
        'مدبيست',
        'מדפיס',
        'عمداه',
        'עמדה',
        'כללי',
        'سوخن',
        'סוכן',
        'شليح',
        'שליח',
        'زيكوي',
        'بيتوليم',
        'ביטולים',
        'DaaS',
        'سريكا',
        'مطبخ',
        'مخبز',
        'مطعم',
        'شيقل',
    ];

    /**
     * Hebrew terms come back spelled out in Arabic letters ("السيف أوبتي"), which
     * hides them from the intent rules downstream. Put them back.
     *
     * @var array<string, string>
     */
    private const TERM_SPELLINGS = [
        '/(?:ال)?س[يىا]?[بفڤ]\s*[أاإ]و[بفڤ]ت?[يى]/u' => 'סיב אופטי',
        '/\b(?:مالان|ميلان|malan)\b/iu' => 'ملان',
        '/\b(?:سالى|sally)\b/iu' => 'سالي',
        '/(?:ال)?طيبه/u' => 'الطيبة',
        '/\b(?:بيزيك|بزك|bezeq)\b/iu' => 'بيزك',
        '/(?:مجديل|مجدل)\s*(?:تووح|טווח)/u' => 'מגדיל טווח',
        '/(?:تشتيت|تسحتيت)/u' => 'תשתית',
    ];

    /**
     * @var array<string, string>
     */
    private const KAMAN_TERM_SPELLINGS = [
        '/\b(?:aviv|افي[فڤ]|اڤيڤ)\b/iu' => 'אביב',
        '/\b(?:haat|الهاات)\b/iu' => 'هات',
        '/\b(?:kupa|كوباة)\b/iu' => 'كوباه',
    ];

    private string $domain = 'malan';

    /**
     * @var array{last_assistant?:?string,turns?:list<array{role:string,text:string}>}
     */
    private array $conversationContext = [];

    /**
     * Subtitle and video-channel filler that models emit for silence or noise.
     *
     * @var list<string>
     */
    private const HALLUCINATION_PHRASES = [
        'اشترك في القناة',
        'الاشتراك في القناة',
        'لا تنسوا الاشتراك',
        'لا تنسى الاشتراك',
        'شكرا لمشاهدة',
        'شكرًا لمشاهدة',
        'شكرا للمشاهدة',
        'أراكم في الفيديو القادم',
        'اراكم في الفيديو القادم',
        'نراكم في الفيديو القادم',
        'ترجمة نانسي قنقر',
        'ترجمة وتعديل',
        'المزيد من الفيديوهات',
        'thanks for watching',
        'thank you for watching',
        'please subscribe',
        'like and subscribe',
        'subtitles by',
        'amara.org',
        'תרגום וכתוביות',
    ];

    /**
     * @param  string|null  $domain  malan (default) or kaman
     * @param  array{last_assistant?:?string,turns?:list<array{role:string,text:string}>}  $conversationContext
     * @return array{
     *     ok: bool,
     *     unclear: bool,
     *     transcript: ?string,
     *     error: ?string,
     *     model: ?string,
     *     languages: list<string>,
     *     confidence: ?float,
     *     reason: ?string,
     *     repaired: bool,
     *     raw_transcript: ?string
     * }
     */
    public function transcribe(
        string $disk,
        string $path,
        ?string $mimeType = null,
        ?string $domain = null,
        array $conversationContext = [],
    ): array {
        $this->domain = $domain === 'kaman' ? 'kaman' : 'malan';
        $this->conversationContext = $conversationContext;
        $apiKey = (string) (config('services.openai.api_key') ?: env('OPENAI_API_KEY', ''));
        if ($apiKey === '') {
            return $this->failure('missing_api_key');
        }

        if (! Storage::disk($disk)->exists($path)) {
            return $this->failure('file_missing');
        }

        try {
            $binary = Storage::disk($disk)->get($path);
        } catch (Throwable $e) {
            Log::warning('WhatsApp voice read failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return $this->failure('read_failed');
        }

        if (! is_string($binary) || $binary === '') {
            return $this->failure('empty_file');
        }

        // Below this it is a mis-tap on the mic button, not speech.
        if (strlen($binary) < $this->minBytes()) {
            return $this->failure('audio_too_short');
        }

        $filename = 'voice.'.$this->resolveExtension($binary, $mimeType, $path);

        $anyResponse = false;
        $lastError = null;
        $best = null;

        foreach ($this->modelChain() as $model) {
            $attempt = $this->requestWithRetry($model, $apiKey, $binary, $filename);

            if (($attempt['ok'] ?? false) !== true) {
                $lastError = (string) ($attempt['error'] ?? 'request_failed');

                continue;
            }

            $anyResponse = true;
            $text = $this->applyDomainSpelling($this->normalizeTranscript((string) ($attempt['text'] ?? '')));
            $confidence = $attempt['confidence'] ?? null;
            $reason = $this->rejectionReason($text, $confidence, $attempt['no_speech'] ?? null);

            if ($reason === null) {
                Log::info('WhatsApp voice transcribed', [
                    'model' => $model,
                    'chars' => mb_strlen($text),
                    'languages' => $attempt['languages'] ?? [],
                    'confidence' => $confidence,
                ]);

                return [
                    'ok' => true,
                    'unclear' => false,
                    'transcript' => $text,
                    'error' => null,
                    'model' => $model,
                    'languages' => $attempt['languages'] ?? [],
                    'confidence' => $confidence,
                    'reason' => null,
                ];
            }

            Log::info('WhatsApp voice transcript rejected', [
                'model' => $model,
                'reason' => $reason,
                'chars' => mb_strlen($text),
                'confidence' => $confidence,
                'guess' => mb_substr($text, 0, 120),
            ]);

            // Keep the first guess so staff can still read what we heard.
            $best ??= [
                'text' => $text,
                'model' => $model,
                'reason' => $reason,
                'confidence' => $confidence,
            ];
        }

        return [
            'ok' => $anyResponse,
            'unclear' => true,
            'transcript' => ($best['text'] ?? '') !== '' ? $best['text'] : null,
            'error' => $anyResponse ? null : $lastError,
            'model' => $best['model'] ?? null,
            'languages' => [],
            'confidence' => $best['confidence'] ?? null,
            'reason' => $best['reason'] ?? $lastError,
        ];
    }

    public function isUsableTranscript(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        // Strip punctuation / symbols; require real letters or digits.
        $meaningful = preg_replace('/[^\p{L}\p{N}]+/u', '', $trimmed) ?? '';
        if (mb_strlen($meaningful) < 2) {
            return false;
        }

        // Extremely short noise after cleanup.
        if (mb_strlen($trimmed) < 2) {
            return false;
        }

        return true;
    }

    /**
     * Video-channel filler is never something a customer said to us.
     */
    public function looksLikeHallucination(string $text): bool
    {
        $normalized = $this->normalizeTranscript($text);
        if ($normalized === '') {
            return false;
        }

        foreach (self::HALLUCINATION_PHRASES as $phrase) {
            if (mb_stripos($normalized, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decoder loops ("لا لا لا لا لا لا") and held-vowel noise ("اااااااا").
     */
    public function looksRepetitive(string $text): bool
    {
        $words = array_values(array_filter(
            preg_split('/\s+/u', $this->normalizeTranscript($text)) ?: [],
            static fn ($word) => $word !== '',
        ));
        $total = count($words);

        if ($total >= 6 && count(array_unique($words)) === 1) {
            return true;
        }

        if ($total >= 10 && (count(array_unique($words)) / $total) <= 0.25) {
            return true;
        }

        $letters = preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? '';
        if (mb_strlen($letters) >= 8) {
            $chars = preg_split('//u', $letters, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count(array_unique($chars)) === 1) {
                return true;
            }
        }

        return false;
    }

    private function rejectionReason(string $text, ?float $confidence, ?float $noSpeech): ?string
    {
        if (! $this->isUsableTranscript($text)) {
            return 'unusable';
        }

        if ($this->looksLikeHallucination($text)) {
            return 'hallucination';
        }

        if ($this->looksRepetitive($text)) {
            return 'repetition';
        }

        $maxNoSpeech = (float) config('malan.voice.transcription_max_no_speech', 0.75);
        if ($noSpeech !== null && $noSpeech >= $maxNoSpeech && mb_strlen($text) < 60) {
            return 'no_speech';
        }

        $minConfidence = (float) config('malan.voice.transcription_min_confidence', -1.25);
        if ($confidence !== null && $confidence < $minConfidence) {
            return 'low_confidence';
        }

        return null;
    }

    /**
     * @return array{ok:bool,status:int,retryable:bool,error:?string,text?:string,languages?:list<string>,confidence?:?float,no_speech?:?float}
     */
    private function requestWithRetry(string $model, string $apiKey, string $binary, string $filename): array
    {
        $withLogprobs = $this->wantsLogprobs($model);
        $attemptsLeft = 2;
        $result = ['ok' => false, 'status' => 0, 'retryable' => false, 'error' => 'not_attempted'];

        while ($attemptsLeft > 0) {
            $result = $this->request($model, $apiKey, $binary, $filename, $withLogprobs);

            if (($result['ok'] ?? false) === true) {
                return $result;
            }

            // include[]=logprobs is only a scoring nicety — drop it and try again.
            if ($withLogprobs && (int) ($result['status'] ?? 0) === 400) {
                $withLogprobs = false;

                continue;
            }

            $attemptsLeft--;
            if (($result['retryable'] ?? false) !== true || $attemptsLeft === 0) {
                break;
            }

            usleep(app()->runningUnitTests() ? 1_000 : 700_000);
        }

        return $result;
    }

    /**
     * @return array{ok:bool,status:int,retryable:bool,error:?string,text?:string,languages?:list<string>,confidence?:?float,no_speech?:?float}
     */
    private function request(
        string $model,
        string $apiKey,
        string $binary,
        string $filename,
        bool $withLogprobs,
    ): array {
        $isWhisper = $this->isWhisper($model);

        $http = Http::withToken($apiKey)
            ->timeout($this->timeout())
            ->acceptJson();

        if (! (bool) config('services.openai.verify_ssl', true)) {
            $http = $http->withOptions(['verify' => false]);
        }

        $http = $http
            ->attach('file', $binary, $filename)
            ->attach('model', $model)
            ->attach('prompt', $this->promptFor($model))
            ->attach('response_format', $isWhisper ? 'verbose_json' : 'json');

        if ($isWhisper) {
            // Segment scores are how we catch invented words; temperature 0 keeps it literal.
            // "0" would be dropped as a falsy multipart value, so send it as a float string.
            $http = $http->attach('temperature', '0.0');
        }

        if ($this->usesLanguageList($model)) {
            foreach ($this->keywords() as $keyword) {
                $http = $http->attach('keywords[]', $keyword);
            }
            foreach ($this->languages() as $language) {
                $http = $http->attach('languages[]', $language);
            }
        } else {
            $language = $this->primaryLanguage();
            if ($language !== '') {
                $http = $http->attach('language', $language);
            }
        }

        if (! $isWhisper && $withLogprobs) {
            $http = $http->attach('include[]', 'logprobs');
        }

        try {
            $response = $http->post(self::ENDPOINT);
        } catch (Throwable $e) {
            Log::warning('WhatsApp voice transcription exception', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'status' => 0, 'retryable' => true, 'error' => 'exception'];
        }

        if (! $response->successful()) {
            $status = $response->status();
            Log::warning('WhatsApp voice transcription failed', [
                'model' => $model,
                'file' => $filename,
                'status' => $status,
                'body' => mb_substr($response->body(), 0, 400),
            ]);

            return [
                'ok' => false,
                'status' => $status,
                'retryable' => $status === 408 || $status === 429 || $status >= 500,
                'error' => 'http_'.$status,
            ];
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        return [
            'ok' => true,
            'status' => $response->status(),
            'retryable' => false,
            'error' => null,
            'text' => (string) ($payload['text'] ?? ''),
            'languages' => $this->detectedLanguages($payload['languages'] ?? null),
            'confidence' => $this->meanLogProbability($payload),
            'no_speech' => $this->maxNoSpeechProbability($payload['segments'] ?? null),
        ];
    }

    private function promptFor(string $model): string
    {
        $keywords = $this->keywords();
        $context = $this->contextPrompt();

        // Whisper treats the prompt as a spelling dictionary, not as instructions.
        if ($this->isWhisper($model)) {
            return implode('، ', $keywords);
        }

        if ($this->usesLanguageList($model)) {
            return $context;
        }

        // Older gpt-4o-transcribe has no keywords field — fold the vocabulary in.
        return $context."\n".implode('، ', $keywords);
    }

    /**
     * @return list<string>
     */
    private function keywords(): array
    {
        return $this->domain === 'kaman' ? self::KAMAN_KEYWORDS : self::KEYWORDS;
    }

    private function contextPrompt(): string
    {
        return $this->domain === 'kaman' ? self::KAMAN_CONTEXT_PROMPT : self::CONTEXT_PROMPT;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function meanLogProbability(array $payload): ?float
    {
        $logprobs = $payload['logprobs'] ?? null;
        if (is_array($logprobs)) {
            $values = [];
            foreach ($logprobs as $entry) {
                if (is_array($entry) && is_numeric($entry['logprob'] ?? null)) {
                    $values[] = (float) $entry['logprob'];
                }
            }
            if ($values !== []) {
                return array_sum($values) / count($values);
            }
        }

        $segments = $payload['segments'] ?? null;
        if (is_array($segments)) {
            $values = [];
            foreach ($segments as $segment) {
                if (is_array($segment) && is_numeric($segment['avg_logprob'] ?? null)) {
                    $values[] = (float) $segment['avg_logprob'];
                }
            }
            if ($values !== []) {
                return array_sum($values) / count($values);
            }
        }

        return null;
    }

    private function maxNoSpeechProbability(mixed $segments): ?float
    {
        if (! is_array($segments) || $segments === []) {
            return null;
        }

        $highest = null;
        foreach ($segments as $segment) {
            if (is_array($segment) && is_numeric($segment['no_speech_prob'] ?? null)) {
                $value = (float) $segment['no_speech_prob'];
                $highest = $highest === null ? $value : max($highest, $value);
            }
        }

        return $highest;
    }

    /**
     * @return list<string>
     */
    private function detectedLanguages(mixed $languages): array
    {
        if (! is_array($languages)) {
            return [];
        }

        $codes = [];
        foreach ($languages as $language) {
            if (is_string($language) && trim($language) !== '') {
                $codes[] = trim($language);

                continue;
            }
            if (is_array($language) && is_string($language['code'] ?? null)) {
                $codes[] = trim($language['code']);
            }
        }

        return array_values(array_unique(array_filter($codes)));
    }

    private function normalizeTranscript(string $text): string
    {
        // Zero-width marks survive ASR output and break comparisons.
        $clean = preg_replace('/[\x{200B}-\x{200F}\x{FEFF}]/u', '', $text) ?? $text;
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        return trim($clean);
    }

    public function applyDomainSpelling(string $text, ?string $domain = null): string
    {
        $active = $domain === 'kaman' || ($domain === null && $this->domain === 'kaman')
            ? 'kaman'
            : 'malan';

        $map = self::TERM_SPELLINGS;
        if ($active === 'kaman') {
            $map = array_merge($map, self::KAMAN_TERM_SPELLINGS);
        }

        foreach ($map as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function modelChain(): array
    {
        $models = [
            trim((string) config('malan.voice.transcription_model', 'gpt-transcribe')),
            trim((string) config('malan.voice.transcription_fallback_model', 'whisper-1')),
        ];

        return array_values(array_unique(array_filter($models, static fn ($model) => $model !== '')));
    }

    /**
     * @return list<string>
     */
    private function languages(): array
    {
        $configured = (string) config('malan.voice.transcription_languages', 'ar,he');
        $codes = array_map('trim', explode(',', $configured));

        return array_values(array_unique(array_filter($codes, static fn ($code) => $code !== '')));
    }

    private function primaryLanguage(): string
    {
        return trim((string) config('malan.voice.transcription_language', 'ar'));
    }

    private function timeout(): int
    {
        return max(10, (int) config('malan.voice.transcription_timeout', 60));
    }

    private function minBytes(): int
    {
        return max(0, (int) config('malan.voice.transcription_min_bytes', 900));
    }

    private function isWhisper(string $model): bool
    {
        return str_starts_with($model, 'whisper');
    }

    private function usesLanguageList(string $model): bool
    {
        return in_array($model, self::LANGUAGE_LIST_MODELS, true);
    }

    private function wantsLogprobs(string $model): bool
    {
        return ! $this->isWhisper($model)
            && (bool) config('malan.voice.transcription_logprobs', true);
    }

    /**
     * @return array{ok:bool,unclear:bool,transcript:null,error:string,model:null,languages:list<string>,confidence:null,reason:string}
     */
    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'unclear' => true,
            'transcript' => null,
            'error' => $error,
            'model' => null,
            'languages' => [],
            'confidence' => null,
            'reason' => $error,
        ];
    }

    /**
     * The endpoint rejects containers it cannot read, so trust the bytes over
     * the declared MIME type or whatever extension the download left behind.
     */
    private function resolveExtension(string $binary, ?string $mimeType, string $path): string
    {
        $sniffed = $this->extensionFromMagicBytes($binary);
        if ($sniffed !== null) {
            return $sniffed;
        }

        $fromMime = $this->extensionFromMime($mimeType);
        if ($fromMime !== null) {
            return $fromMime;
        }

        $fromPath = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($fromPath, self::SUPPORTED_EXTENSIONS, true)) {
            return $fromPath;
        }

        return 'ogg';
    }

    private function extensionFromMagicBytes(string $binary): ?string
    {
        if (str_starts_with($binary, 'OggS')) {
            return 'ogg';
        }
        if (str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WAVE') {
            return 'wav';
        }
        if (str_starts_with($binary, "\x1A\x45\xDF\xA3")) {
            return 'webm';
        }
        if (substr($binary, 4, 4) === 'ftyp') {
            return 'm4a';
        }
        if (str_starts_with($binary, 'fLaC')) {
            return 'flac';
        }
        if (str_starts_with($binary, 'ID3')
            || str_starts_with($binary, "\xFF\xFB")
            || str_starts_with($binary, "\xFF\xF3")
            || str_starts_with($binary, "\xFF\xF2")) {
            return 'mp3';
        }

        return null;
    }

    private function extensionFromMime(?string $mimeType): ?string
    {
        $mime = strtolower(trim((string) $mimeType));
        if ($mime === '') {
            return null;
        }

        return match (true) {
            // Opus rides inside an Ogg container, which the endpoint does accept.
            str_contains($mime, 'ogg'), str_contains($mime, 'opus') => 'ogg',
            str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a'), str_contains($mime, 'aac') => 'm4a',
            str_contains($mime, 'wav') => 'wav',
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'flac') => 'flac',
            default => null,
        };
    }
}
