<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotInstance;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Download + transcribe inbound WhatsApp voice notes for chat storage and AI.
 */
class WhatsAppVoiceInboundService
{
    public function __construct(
        protected GreenApiMediaDownloader $mediaDownloader,
        protected WhatsAppAudioTranscriber $transcriber,
    ) {}

    /**
     * @return array{
     *     message_text: string,
     *     message_type: string,
     *     unclear: bool,
     *     transcript: ?string,
     *     attachment_disk: ?string,
     *     attachment_path: ?string,
     *     attachment_mime: ?string,
     *     metadata: array<string, mixed>
     * }
     */
    public function prepare(GreenApiIncomingMessage $incoming, ?ChatbotInstance $instance = null): array
    {
        $fallback = $incoming->customerFacingText() ?? '[رسالة صوتية عبر WhatsApp]';
        $result = [
            'message_text' => $fallback,
            'message_type' => 'audio',
            'unclear' => true,
            'transcript' => null,
            'attachment_disk' => null,
            'attachment_path' => null,
            'attachment_mime' => null,
            'metadata' => [
                'whatsapp_voice' => true,
                'voice_unclear' => true,
            ],
        ];

        if (! $incoming->isAudio()) {
            $result['message_type'] = 'text';
            $result['unclear'] = false;
            $result['metadata'] = [];

            return $result;
        }

        if ($incoming->downloadUrl === null || $incoming->downloadUrl === '') {
            $result['message_text'] = WhatsAppAudioTranscriber::UNCLEAR_DISPLAY_AR;

            return $result;
        }

        try {
            $stored = $this->mediaDownloader->downloadToPrivateStorage(
                $incoming->downloadUrl,
                $incoming->mimeType ?: 'audio/ogg',
            );
            $result['attachment_disk'] = $stored['disk'] ?? config('malan.media.disk', 'local');
            $result['attachment_path'] = $stored['path'];
            $result['attachment_mime'] = $stored['mime_type'];
        } catch (Throwable $e) {
            Log::warning('WhatsApp voice download failed', [
                'chat_id' => $incoming->chatId,
                'error' => $e->getMessage(),
            ]);
            $result['message_text'] = WhatsAppAudioTranscriber::UNCLEAR_DISPLAY_AR;
            $result['metadata']['voice_download_failed'] = true;

            return $result;
        }

        $domain = ($instance !== null && $instance->hasKamanWhatsappIntegration()) ? 'kaman' : 'malan';

        $transcription = $this->transcriber->transcribe(
            (string) $result['attachment_disk'],
            (string) $result['attachment_path'],
            $result['attachment_mime'],
            $domain,
        );

        $result['metadata']['transcription_ok'] = (bool) ($transcription['ok'] ?? false);
        if (! empty($transcription['error'])) {
            $result['metadata']['transcription_error'] = $transcription['error'];
        }
        foreach (['model' => 'transcription_model', 'confidence' => 'voice_confidence', 'languages' => 'voice_languages'] as $key => $metaKey) {
            if (($transcription[$key] ?? null) !== null && $transcription[$key] !== []) {
                $result['metadata'][$metaKey] = $transcription[$key];
            }
        }

        if (! empty($transcription['unclear']) || empty($transcription['transcript'])) {
            $result['unclear'] = true;
            $result['transcript'] = is_string($transcription['transcript'] ?? null)
                ? $transcription['transcript']
                : null;
            $result['message_text'] = WhatsAppAudioTranscriber::UNCLEAR_DISPLAY_AR;
            $result['metadata']['voice_unclear'] = true;
            $result['metadata']['voice_reject_reason'] = $transcription['reason'] ?? null;
            // Keep the rejected guess so staff can read what we heard.
            if (is_string($result['transcript']) && $result['transcript'] !== '') {
                $result['metadata']['voice_best_guess'] = $result['transcript'];
            }

            Log::info('WhatsApp voice unclear', [
                'chat_id' => $incoming->chatId,
                'reason' => $transcription['reason'] ?? null,
                'model' => $transcription['model'] ?? null,
            ]);

            return $result;
        }

        $transcript = trim((string) $transcription['transcript']);
        $result['unclear'] = false;
        $result['transcript'] = $transcript;
        // Store transcript as the chat text so staff see what was said and AI can reply.
        $result['message_text'] = $transcript;
        $result['metadata']['voice_unclear'] = false;
        $result['metadata']['transcript'] = $transcript;
        $result['metadata']['voice_label'] = WhatsAppAudioTranscriber::VOICE_LABEL_AR;

        return $result;
    }

    public function unclearReplyText(): string
    {
        return WhatsAppAudioTranscriber::UNCLEAR_REPLY_AR;
    }
}
