<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One Dima turn: context, one model call, and a technical fallback only if that call fails.
 */
class DimaConversationService
{
    public static ?Closure $testResponder = null;

    public function __construct(
        private readonly DimaContextBuilder $context,
        private readonly DimaKnowledgeService $knowledge,
        private readonly DimaResponseValidator $validator,
        private readonly DimaConversationState $state,
    ) {}

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array<string, mixed>  $prior
     * @return array{text:string,stance:string,note:string,close:bool,video:bool,stage:string,identity:mixed,memory:array<string, mixed>}
     */
    public function turn(array $turns, ?string $name, array $prior = [], bool $live = false, bool $nudge = false): array
    {
        $latest = $this->context->latestUser($turns);
        if (! $nudge && $this->state->asksToStop($latest)) {
            $this->logTurn(0, 0, true, false);

            return $this->state->stopped($prior);
        }
        if (! $nudge && DimaPrompt::explicitNoVote($turns)) {
            $this->logTurn(0, 0, false, false);

            return $this->fixed($prior, $this->state->noVoteReply($name), DimaPrompt::STAGE_RESPONSE_RECEIVED, true);
        }
        if (! $nudge && $this->state->declinesPermission($latest) && $this->state->permissionWasRequested($turns) && ! $this->state->voteQuestionAlreadyAsked($turns, $prior)) {
            $this->logTurn(0, 0, false, false);

            return $this->fixed($prior, 'تمام.', DimaPrompt::STAGE_INTRODUCTION);
        }
        if (! $nudge && $this->state->asksIdentity($latest)) {
            $this->logTurn(0, 0, false, false);
            $includeVote = ! $this->state->voteQuestionAlreadyAsked($turns, $prior)
                && ! $this->state->statedVotingIntention($turns);
            $stage = $includeVote
                ? DimaPrompt::STAGE_VOTING_INTENTION_ASKED
                : (string) ($prior['stage'] ?? DimaPrompt::STAGE_INTRODUCTION);

            return $this->fixed($prior, $this->state->identityAnswer($name, $includeVote), $stage);
        }
        if (! $nudge && $this->state->shouldAskVotingIntention($turns, $prior, $latest)) {
            $this->logTurn(0, 0, false, false);

            return $this->fixed($prior, $this->state->votingQuestion($name), DimaPrompt::STAGE_VOTING_INTENTION_ASKED);
        }
        $target = ! $nudge ? $this->state->voteTarget($latest, $turns) : null;
        if ($target === 'united' && ! $this->state->alreadySaid($turns, 'نهارك سعيد')) {
            $this->logTurn(0, 0, false, false);

            return $this->fixed($prior, $this->state->unitedThanks($name), DimaPrompt::STAGE_CLOSED, false, true, DimaPrompt::UNITED);
        }
        if ($target === 'democrats' && ! $this->state->alreadySaid($turns, 'غريب إنه نسمع')) {
            $this->logTurn(0, 0, false, false);

            return $this->withClip($prior, $this->state->democratsReply($name), 'impact-5');
        }
        if ($target === 'joint' && ! $this->state->alreadySaid($turns, 'المشتركة خيار بنحترمه')) {
            $this->logTurn(0, 0, false, false);

            return $this->withClip($prior, $this->state->jointReply($name), 'impact-2');
        }
        if (! $nudge && $this->state->affirmsVoteOnly($latest, $turns)) {
            $this->logTurn(0, 0, false, false);

            return $this->fixed($prior, $this->state->whoQuestion($name), DimaPrompt::STAGE_VOTING_INTENTION_ASKED);
        }
        if (! $nudge && $this->state->shouldSendImpactClips($prior, $latest)) {
            $this->logTurn(0, 0, false, false);
            $clip = $this->state->pickImpactClip($prior, $latest);
            if ($clip === null) {
                $clip = $this->state->impactClips()[0];
            }
            $decision = $this->fixed($prior, $clip['line'], DimaPrompt::STAGE_RESPONSE_RECEIVED);
            $already = trim((string) ($decision['memory']['clips'] ?? ''));
            $decision['memory']['clips'] = $already === '' ? (string) $clip['id'] : $already.','.$clip['id'];
            $decision['clips'] = [$clip];

            return $decision;
        }

        $packet = $this->context->build($turns, $name, $prior)."\n\n".$this->knowledge->forMessage(
            $latest,
            (string) (DimaPrompt::memoryFrom($prior['memory'] ?? null)['topic'] ?? ''),
        );
        $messages = [
            ['role' => 'system', 'content' => $nudge ? $this->nudgePrompt() : $this->prompt()],
            ['role' => 'user', 'content' => $packet],
        ];
        $raw = $this->complete($messages, $live);
        $accepted = $this->validator->accept($raw, $prior);
        if ($accepted === null) {
            $this->logTurn(1, mb_strlen($packet), false, true);

            return $this->failed($prior, $nudge);
        }
        $this->logTurn(1, mb_strlen($packet), false, false);
        $text = $accepted['text'];
        if ($this->state->isVoteQuestion($text) && ($this->state->voteQuestionAlreadyAsked($turns, $prior) || $this->state->statedVotingIntention($turns))) {
            $text = 'تمام، سمعت موقفك.';
        }
        $text = $this->state->withoutTrailingQuestion($text, $latest, $this->state->previousAssistant($turns));
        $stage = $this->state->voteQuestionAlreadyAsked($turns, $prior) || $this->state->statedVotingIntention($turns)
            ? DimaPrompt::STAGE_RESPONSE_RECEIVED
            : DimaPrompt::STAGE_FOLLOW_UP;

        return [
            'text' => $text,
            'stance' => $accepted['stance'],
            'note' => $accepted['note'],
            'close' => false,
            'video' => false,
            'stage' => $stage,
            'identity' => $prior['identity'] ?? null,
            'memory' => $accepted['memory'],
        ];
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public function nudge(array $turns, ?string $name, bool $live = false): string
    {
        return $this->turn($turns, $name, [], $live, true)['text'];
    }

    /**
     * @param  array<string, mixed>  $prior
     * @return array{text:string,stance:string,note:string,close:bool,video:bool,stage:string,identity:mixed,memory:array<string, mixed>}
     */
    private function fixed(array $prior, string $text, string $stage, bool $video = false, bool $close = false, string $stance = DimaPrompt::OPEN): array
    {
        $memory = DimaPrompt::memoryFrom($prior['memory'] ?? null);
        if ($stage === DimaPrompt::STAGE_VOTING_INTENTION_ASKED) {
            $memory['open_question'] = $text;
        }

        return [
            'text' => $text,
            'stance' => $stance,
            'note' => '',
            'close' => $close,
            'video' => $video,
            'stage' => $stage,
            'identity' => $prior['identity'] ?? null,
            'memory' => $memory,
        ];
    }

    /**
     * @param  array<string, mixed>  $prior
     * @return array{text:string,stance:string,note:string,close:bool,video:bool,stage:string,identity:mixed,memory:array<string, mixed>}
     */
    private function withClip(array $prior, string $text, string $clipId): array
    {
        $decision = $this->fixed($prior, $text, DimaPrompt::STAGE_RESPONSE_RECEIVED);
        $sent = array_values(array_filter(array_map('trim', explode(',', (string) ($decision['memory']['clips'] ?? '')))));
        $clip = null;
        foreach ($this->state->impactClips() as $candidate) {
            if ($candidate['id'] === $clipId && ! in_array($clipId, $sent, true)) {
                $clip = $candidate;
                break;
            }
        }
        if ($clip === null) {
            return $decision;
        }
        $decision['memory']['clips'] = $sent === [] ? $clipId : implode(',', $sent).','.$clipId;
        $decision['clips'] = [$clip];

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $prior
     * @return array{text:string,stance:string,note:string,close:bool,video:bool,stage:string,identity:mixed,memory:array<string, mixed>}
     */
    private function failed(array $prior, bool $nudge): array
    {
        $memory = DimaPrompt::memoryFrom($prior['memory'] ?? null);

        return [
            'text' => $nudge ? '' : 'صار خلل بالرد. عيدي آخر جملة بجملة وحدة.',
            'stance' => DimaPrompt::normalizeStance((string) ($prior['stance'] ?? DimaPrompt::OPEN)),
            'note' => '',
            'close' => false,
            'video' => false,
            'stage' => DimaPrompt::STAGE_FOLLOW_UP,
            'identity' => $prior['identity'] ?? null,
            'memory' => $memory,
        ];
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
أنت ديما. بتحكي واتساب باللهجة الفلسطينية مع ناس من الوسط العربي.
إذا سألك مين بحكي معه قبل ما تعرفي عن حالك: معك ديما من مركز اتصالات للوسط العربي، متأسفة اذا رسالتي اجت فجأة، وبنحاول نسمع من الناس عن الأوضاع. بعدين سطر فاضي وسؤال الإذن: بتسمحلي أسألك سؤال صغير؟ للأنثى معكي وبتسمحيلي أسالكي. إذا سأل مين معي أو مين الجهة، عرفي حالك: أنا ديما من مركز استطلاعات للوسط العربي. بهالمرحلة لا تذكري القائمة العربية الموحدة. إذا سؤال التصويت لسا ما انسأل، اسأليه هل رح تطلع تصوت هاي المرة ولا كيف، وللأنثى هل رح تطلعي تصوتي هاي المرة ولا كيف. إذا التعريف انحكى، لا تعيديه.

جاوبي على آخر رسالة أولا، من سياق الحديث كله.
إذا وافق على سؤال الإذن، السؤال التالي حرفيًا: ناوي تصوت بالانتخابات الجاي؟ وللأنثى ناوية تصوتي بالانتخابات الجاي؟ ممنوع تستبدليه بسؤال عن يومه أو شو شاغل باله. إذا حكى نية التصويت، لا تعيدي هالسؤال.
إذا قال إنه رح يصوت وما سمى قائمة، اسأليه: لمين رح تصوت المره هاي؟ وللأنثى لمين رح تصوتي المره هاي؟ لا تشكريه ولا تسكّري الحكي بهالمرحلة.
الشكر والنهاية فقط إذا قال إنه مصوت للموحدة: تمام شكرا الك كتير على وقتك، نهارك سعيد. أي قائمة ثانية بداية الحكي.
إذا سمى الديمقراطيين: غريب إنه نسمع عربي بصوت لحزب مثلهم، غولان وزملاؤه، تجنيد الكل والانفصال عن الفلسطينيين، وبعدين ليش ما لحزب مثل الموحدة. بلا هدم وبلا أربعة مقاعد.
إذا سمى المشتركة: ردي بلطف وكملي الحديث، بلا ختام.
السؤال مش خاتمة الرسالة. لا تسألي بنهاية كل رد. سؤال المتابعة اختياري، وفقط إذا معلومة ناقصة وما بتقدري تجاوبي بدونها. إذا سألتي سؤال بالتري السابق، لا تسألي سؤال توضيحي ثاني وراه.
إذا الاعتراض واضح، جاوي عليه بمضمون من الوقائع. لا تعيدي جملته بصياغة ثانية، ولا تسألي شو السبب أو شو التغيير إذا هو حكاه.
إذا انبعث فيديو قبل هالرسالة، كملي الحوار من كلامه. لا تطلبي منه يشوف فيديو، ولا تعيدي جملة الفيديو. إذا سأل أو حكى إنه ما اقتنع، جاوي على كلامه باختصار.
إذا تحدّى جملة قلتيها، مثل هيك انت شايف يعني، جاوبي على التحدي. افصلي بين واقعة مكتوبة بالدليل وبين تفسيرك. لا تعيدي الخطاب السابق ولا تسألي عن التصويت.
إذا غيّر الموضوع، اتبعي الموضوع الجديد. إذا رجع لموضوع قديم، كملي من هناك.
إذا صحّح فهمك، اعترفي بالتصحيح.
إذا سأل سؤالين، جاوبي الاثنين باختصار.
إذا طلب مصدر، أعطي المصدر المكتوب بالوقائع فقط.
لما الموضوع انتخابات أو مشاركة أو تمثيل: السجل المنشور غير كراس القائمة، والكراس وثيقة حزبية مش تقرير مستقل. لا تستنتجي أيديولوجيا من جواب واحد، ولا تستخدمي نيته أو اعتراضه لتقنعيه بقائمة.
احترمي اختياره السياسي. لا تضغطي عليه يصوت لقائمة، ولا تكرري حجة اعترض عليها.
جملة أو جملتين، إلا إذا طلب تفصيل.
close دائما false. الوقف الصريح بتتعالج قبل ما توصلك الرسالة.
حدّثي الذاكرة من كلامه هو، مش من افتراضك. known اللي قاله. topic موضوع آخر رسالة. answered سؤال انجاوب. open_question سؤال لسا مفتوح. concern اعتراضه. wants_to_continue true.
لا تخترعي رقم أو تاريخ أو حادثة. إذا الوقائع ما فيها الجواب، قولي إنك ما بتعرفي.
PROMPT;
    }

    private function nudgePrompt(): string
    {
        return 'أنت ديما. الشخص سكت. جملة قصيرة تكملي آخر موضوع من غير ما تعيدي نفس السؤال، وبلا ضغط تصويت. close false.';
    }

    /**
     * @param  list<array{role:string,content:string}>  $messages
     */
    private function complete(array $messages, bool $live): ?string
    {
        if (self::$testResponder instanceof Closure) {
            $value = (self::$testResponder)($messages);

            return is_string($value) && trim($value) !== '' ? $value : null;
        }
        if (app()->runningUnitTests()) {
            return null;
        }

        $apiKey = MowahadaOpenAi::key($live ? MowahadaOpenAi::DIMA : null);
        if ($apiKey === '') {
            Log::warning('dima-v2.missing-key');

            return null;
        }
        $model = trim((string) config('openai.mansour_chat_model', 'gpt-6-astra'));
        if ($model === '') {
            $model = 'gpt-6-astra';
        }
        try {
            $response = Http::timeout(70)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'max_completion_tokens' => 1400,
                    'reasoning_effort' => 'high',
                    'messages' => $messages,
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'dima_reply',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['reply', 'stance', 'note', 'close', 'memory'],
                                'properties' => [
                                    'reply' => ['type' => 'string'],
                                    'stance' => ['type' => 'string', 'enum' => DimaPrompt::STANCES],
                                    'note' => ['type' => 'string'],
                                    'close' => ['type' => 'boolean'],
                                    'memory' => [
                                        'type' => 'object',
                                        'additionalProperties' => false,
                                        'required' => ['known', 'topic', 'answered', 'open_question', 'concern', 'wants_to_continue'],
                                        'properties' => [
                                            'known' => ['type' => 'string'],
                                            'topic' => ['type' => 'string'],
                                            'answered' => ['type' => 'string'],
                                            'open_question' => ['type' => 'string'],
                                            'concern' => ['type' => 'string'],
                                            'wants_to_continue' => ['type' => 'boolean'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            Log::warning('dima-v2.timeout', ['model' => $model]);

            return null;
        } catch (Throwable $exception) {
            Log::warning('dima-v2.http', ['error' => $exception::class]);

            return null;
        }
        if (! $response->successful()) {
            Log::warning('dima-v2.status', ['model' => $model, 'status' => $response->status()]);

            return null;
        }
        $content = trim((string) $response->json('choices.0.message.content'));

        return $content !== '' ? $content : null;
    }

    private function logTurn(int $calls, int $contextChars, bool $stopped, bool $fallback): void
    {
        Log::info('dima-v2.turn', [
            'model_calls' => $calls,
            'context_chars' => $contextChars,
            'stopped' => $stopped,
            'fallback' => $fallback,
            'replaced' => false,
        ]);
    }
}
