<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\MansourConversationBurstService;
use App\Services\AiChatbot\MansourConversationRevival;
use App\Services\AiChatbot\MansourHumanDelay;
use App\Services\AiChatbot\MansourLines;
use App\Services\AiChatbot\AppsPrompt;
use App\Services\AiChatbot\DimaEngine;
use App\Services\AiChatbot\DimaPrompt;
use App\Services\AiChatbot\InvitePrompt;
use App\Services\AiChatbot\MansourBotService;
use App\Services\AiChatbot\MansourPrompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * After شيماء's listening window: one reply for the whole customer burst.
 * A newer line before send drops this draft so the next run can regenerate it.
 */
class ProcessMansourConversationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(
        public int $instanceId,
        public int $conversationId,
        public int $expectedVersion,
        public string $chatId,
    ) {}

    public function handle(
        AiChatbotService $chatbotService,
        ChatbotGreenApiService $greenApi,
        MansourConversationBurstService $burstService,
    ): void {
        $conversation = ChatbotConversation::query()->find($this->conversationId);
        if ($conversation === null || $burstService->currentVersion($conversation) !== $this->expectedVersion) {
            return;
        }

        $lock = Cache::lock('mansour-burst-process-'.$this->conversationId, 90);
        $deadline = microtime(true) + (app()->runningUnitTests() ? 1 : 40);
        $acquired = false;
        while (microtime(true) < $deadline) {
            $current = ChatbotConversation::query()->find($this->conversationId);
            if ($current === null || $burstService->currentVersion($current) !== $this->expectedVersion) {
                return;
            }
            if ($lock->get()) {
                $acquired = true;
                break;
            }
            usleep(app()->runningUnitTests() ? 20_000 : 200_000);
        }
        if (! $acquired) {
            return;
        }

        try {
            $this->reply($chatbotService, $greenApi, $burstService);
        } finally {
            optional($lock)->release();
        }
    }

    private function reply(
        AiChatbotService $chatbotService,
        ChatbotGreenApiService $greenApi,
        MansourConversationBurstService $burstService,
    ): void {
        $instance = ChatbotInstance::query()->find($this->instanceId);
        $conversation = ChatbotConversation::query()->find($this->conversationId);
        $user = $instance?->user;
        if ($instance === null || $conversation === null || $user === null) {
            return;
        }
        if ($instance->outboundHeld() || ! $instance->isBotGloballyActive() || ! $conversation->allowsAutomaticReply()) {
            return;
        }
        if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
            return;
        }

        $burst = $burstService->collectUnprocessedBurst($conversation);
        if ($burst === []) {
            $burstService->closeBurst($conversation, $this->expectedVersion);

            return;
        }

        $combined = trim(implode("\n", array_map(
            static fn (ChatbotMessage $message): string => trim((string) $message->message),
            $burst,
        )));
        $firstId = (int) $burst[0]->id;
        $previousAssistant = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('id', '<', $firstId)
            ->orderByDesc('id')
            ->value('message');
        $spokeBefore = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->where('id', '<', $firstId)
            ->exists();

        $assistant = null;
        $fromAudio = false;
        foreach ($burst as $message) {
            if ((string) $message->message_type === 'audio') {
                $fromAudio = true;
            }
        }

        $previousText = is_string($previousAssistant) ? $previousAssistant : null;
        $listed = $instance->mansourContacts()->where('chat_id', $this->chatId)->first(['name']);
        $personName = $listed !== null ? trim((string) $listed->name) : trim((string) $conversation->contact_name);
        if ($instance->isDimaBot()) {
            if (! DimaEngine::allowsOutbound()) {
                Log::info('dima.outbound-blocked', [
                    'instance_id' => $instance->id,
                    'conversation_id' => $conversation->id,
                ]);
                $burstService->markBurstProcessed($burst, $this->expectedVersion);
                $burstService->closeBurst($conversation, $this->expectedVersion);

                return;
            }
            $decision = DimaEngine::nextReply($this->dialogue($conversation), $personName, DimaEngine::read($conversation), true);
            if (trim($decision['text']) === '') {
                return;
            }
            DimaEngine::remember($conversation, $decision['stance'], $decision['note'], $decision['close'], $decision['memory'] ?? [], $decision['stage'] ?? null, $decision['identity'] ?? null);
            if (($decision['video'] ?? false) === true) {
                $this->deliverNoVoteVideo($greenApi, $burstService, $conversation, $decision['text'], $burst);

                return;
            }
            $clips = is_array($decision['clips'] ?? null) ? $decision['clips'] : [];
            if ($clips !== []) {
                $this->deliverImpactClips($greenApi, $burstService, $conversation, $decision['text'], $clips, $burst);

                return;
            }
            $pauseAfter = $decision['close'];
            $pushback = false;
            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => $decision['text'],
                'delivery_status' => 'pending',
            ]);
        } elseif ($instance->isInviteBot()) {
            $variant = InvitePrompt::variantOf($instance);
            $justInvited = false;
            if (! InvitePrompt::delivered($conversation) && ! InvitePrompt::wantsOut($combined)) {
                $justInvited = app(MansourBotService::class)->deliverInviteInvitation($instance, $conversation);
            }
            $decision = InvitePrompt::nextReply($this->dialogue($conversation), $personName, InvitePrompt::read($conversation), $variant);
            if ($justInvited && (trim($decision['text']) === '' || InvitePrompt::invitationIsEnough($decision, $variant))) {
                InvitePrompt::remember($conversation, InvitePrompt::OPEN, 'انبعتت الدعوة', false);
                $burstService->markBurstProcessed($burst, $this->expectedVersion);
                $burstService->closeBurst($conversation->fresh() ?? $conversation, $this->expectedVersion);
                $conversation->recordAssistantActivity();

                return;
            }
            if (trim($decision['text']) === '') {
                return;
            }
            InvitePrompt::remember($conversation, $decision['stance'], $decision['note'], $decision['close'], (bool) ($decision['plea'] ?? false));
            $pauseAfter = $decision['close'];
            $pushback = false;
            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => $decision['text'],
                'delivery_status' => 'pending',
            ]);
        } elseif ($instance->isAppsBot()) {
            if ($this->skipUntilFreshTrigger($instance, $burstService, $burst, $conversation)) {
                return;
            }
            $memoryVersion = AppsPrompt::memoryVersion($conversation);
            $decision = AppsPrompt::nextReply(
                $this->dialogue($conversation),
                $personName,
                AppsPrompt::links($instance),
                AppsPrompt::memoryOf($conversation),
                $instance,
                true,
            );
            AppsPrompt::storeMemory($conversation, $decision['memory'] ?? [], $memoryVersion);
            $pauseAfter = $decision['pause'];
            $pushback = false;
            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => $decision['text'],
                'delivery_status' => 'pending',
            ]);
        } else {
        $ephemeral = $burstService->burstPrompt($burst);
        if (MansourPrompt::isPermissionAsk($previousText) && MansourPrompt::voteGroup($combined) === null && ! MansourPrompt::isIdentityAsk($combined)) {
            $cue = MansourPrompt::voteYesNoInstruction(
                MansourPrompt::isFemaleName($personName) ? 'female' : 'male',
                $personName,
            );
            $ephemeral = $ephemeral === null ? $cue : $cue."\n\n".$ephemeral;
        }
        $pauseAfter = MansourPrompt::shouldPauseAfterReply($combined, $previousText);
        $pushback = MansourPrompt::isPushback($combined)
            && MansourPrompt::voteGroup($combined) === null
            && ! MansourPrompt::affirmsVoting($combined);
        $askingVote = MansourPrompt::isPermissionAsk($previousText)
            && MansourPrompt::voteGroup($combined) === null
            && ! MansourPrompt::isIdentityAsk($combined);
        $guide = $pauseAfter
            ? MansourPrompt::closeInstruction()
            : (MansourPrompt::voteGroup($combined) === null && ! MansourPrompt::isPushback($combined) && ! $askingVote
                ? MansourPrompt::steerInstruction()
                : null);
        if ($guide !== null) {
            $ephemeral = $ephemeral === null ? $guide : $guide."\n\n".$ephemeral;
        }

        if (MansourPrompt::shouldSendContinuation($combined, $previousText, $spokeBefore)) {
            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => MansourPrompt::continuationFor($personName),
                'delivery_status' => 'pending',
            ]);
        } elseif ($pushback) {
            $gender = MansourPrompt::isFemaleName($personName) ? 'female' : 'male';
            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => MansourPrompt::previousIsPushbackAsk($previousText)
                    ? MansourPrompt::pushbackClose($personName, $gender)
                    : MansourPrompt::pushbackAsk($personName, $gender),
                'delivery_status' => 'pending',
            ]);
        } else {
            $address = MansourPrompt::addressInstruction($personName);
            $ephemeral = $ephemeral === null ? $address : $address."\n\n".$ephemeral;
            try {
                $result = $chatbotService->generateAssistantReplyForConversation(
                    $conversation->fresh() ?? $conversation,
                    $instance,
                    ChatbotConversation::CHANNEL_WHATSAPP,
                    false,
                    $ephemeral,
                );
            } catch (Throwable $e) {
                Log::error('Mansour burst AI failed', [
                    'instance_id' => $this->instanceId,
                    'conversation_id' => $this->conversationId,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            $assistant = $result['assistant_message'] ?? null;
            if ($assistant instanceof ChatbotMessage) {
                $text = (string) $assistant->message;
                if (! $fromAudio) {
                    $text = MansourPrompt::withoutVoiceComplaint($text);
                }
                $assistant->forceFill([
                    'message' => $text,
                    'delivery_status' => 'pending',
                ])->save();
            }
        }
        }

        if (! $assistant instanceof ChatbotMessage) {
            return;
        }

        $conversation->refresh();
        if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
            $burstService->discardUnsentReply($conversation);

            return;
        }

        $reply = (string) $assistant->message;
        $lines = app(MansourLines::class);
        if (! $lines->canDeliver($instance, $conversation)) {
            $burstService->discardUnsentReply($conversation);

            return;
        }

        if ($instance->isAppsBot()) {
            $ok = $this->sendAppsBubbles($greenApi, $lines, $burstService, $conversation, $assistant, $reply);
        } else {
            $waitMs = MansourHumanDelay::millisecondsForText($reply, $instance->isDimaBot());
            if (! $this->waitWhileLatest($burstService, $greenApi, $instance, $waitMs)) {
                $burstService->discardUnsentReply($conversation);

                return;
            }

            $sendResult = $lines->send($instance, $conversation, $this->chatId, $reply, 1000);
            $ok = ($sendResult['status'] ?? 0) >= 200 && ($sendResult['status'] ?? 0) < 300;
            $assistant->forceFill([
                'delivery_status' => $ok ? 'sent' : 'failed',
            ])->save();
        }

        if (! $ok) {
            return;
        }

        if ($pauseAfter) {
            $conversation->forceFill([
                'bot_mode' => ChatbotConversation::BOT_MODE_PAUSED,
            ])->save();
        } elseif (! $pushback && ($spokeBefore || $combined !== '')) {
            app(MansourConversationRevival::class)->arm(
                (int) $conversation->id,
                (int) $assistant->id,
            );
        }

        $burstService->markBurstProcessed($burst, $this->expectedVersion);
        $burstService->closeBurst($conversation->fresh() ?? $conversation, $this->expectedVersion);
        $conversation->recordAssistantActivity();
    }

    /**
     * @param  list<array{id?:string,disk?:string,path?:string,name?:string}>  $clips
     * @param  list<ChatbotMessage>  $burst
     */
    private function deliverImpactClips(
        ChatbotGreenApiService $greenApi,
        MansourConversationBurstService $burstService,
        ChatbotConversation $conversation,
        string $text,
        array $clips,
        array $burst,
    ): void {
        $instance = $conversation->instance ?? ChatbotInstance::query()->find($this->instanceId);
        if (! $instance instanceof ChatbotInstance) {
            return;
        }
        $lines = app(MansourLines::class);
        $sendUrl = $lines->urlFor($instance, $conversation, true);
        foreach ($clips as $clip) {
            $path = trim((string) ($clip['path'] ?? ''));
            $disk = trim((string) ($clip['disk'] ?? 'public'));
            if ($path === '' || ! Storage::disk($disk)->exists($path)) {
                continue;
            }
            $video = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'video',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => '',
                'delivery_status' => 'pending',
                'attachment_disk' => $disk,
                'attachment_path' => $path,
                'attachment_mime' => 'video/mp4',
                'metadata' => ['dima_impact_clip' => (string) ($clip['id'] ?? '')],
            ]);
            $sent = $greenApi->sendLocalFile($sendUrl, $this->chatId, Storage::disk($disk)->path($path), (string) ($clip['name'] ?? 'dima-impact.mp4'));
            $ok = ($sent['status'] ?? 0) >= 200 && ($sent['status'] ?? 0) < 300;
            $video->forceFill(['delivery_status' => $ok ? 'sent' : 'failed'])->save();
        }
        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => $text,
            'delivery_status' => 'pending',
        ]);
        $textSent = $lines->send($instance, $conversation, $this->chatId, $text, 1000);
        $textOk = ($textSent['status'] ?? 0) >= 200 && ($textSent['status'] ?? 0) < 300;
        $assistant->forceFill(['delivery_status' => $textOk ? 'sent' : 'failed'])->save();
        if (! $textOk) {
            return;
        }
        $burstService->markBurstProcessed($burst, $this->expectedVersion);
        $burstService->closeBurst($conversation->fresh() ?? $conversation, $this->expectedVersion);
        $conversation->recordAssistantActivity();
    }

    /**
     * @param  list<ChatbotMessage>  $burst
     */
    private function deliverNoVoteVideo(
        ChatbotGreenApiService $greenApi,
        MansourConversationBurstService $burstService,
        ChatbotConversation $conversation,
        string $text,
        array $burst,
    ): void {
        $instance = $conversation->instance ?? ChatbotInstance::query()->find($this->instanceId);
        if (! $instance instanceof ChatbotInstance) {
            return;
        }
        $already = $conversation->messages()
            ->where('role', 'assistant')
            ->where('message_type', 'video')
            ->where('metadata->dima_no_vote_video', true)
            ->exists();
        $video = null;
        if (! $already) {
            $video = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'video',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => '',
                'delivery_status' => 'pending',
                'attachment_disk' => DimaPrompt::NO_VOTE_VIDEO_DISK,
                'attachment_path' => DimaPrompt::NO_VOTE_VIDEO_PATH,
                'attachment_mime' => 'video/mp4',
                'metadata' => ['dima_no_vote_video' => true],
            ]);
        }
        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => $text,
            'delivery_status' => 'pending',
        ]);

        $conversation->refresh();
        if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
            $burstService->discardUnsentReply($conversation);

            return;
        }
        $lines = app(MansourLines::class);
        if (! $lines->canDeliver($instance, $conversation)) {
            $burstService->discardUnsentReply($conversation);

            return;
        }

        $ok = true;
        if ($video instanceof ChatbotMessage) {
            $sendUrl = $lines->urlFor($instance, $conversation, true);
            $sent = $greenApi->sendFileByUrl($sendUrl, $this->chatId, DimaPrompt::NO_VOTE_VIDEO_URL, 'dima-no-vote.mp4');
            $videoOk = ($sent['status'] ?? 0) >= 200 && ($sent['status'] ?? 0) < 300;
            $video->forceFill(['delivery_status' => $videoOk ? 'sent' : 'failed'])->save();
            if (! $videoOk) {
                Log::warning('dima-agent.no-vote-video', ['status' => $sent['status'] ?? 0]);
            }
        }
        $textSent = $lines->send($instance, $conversation, $this->chatId, $text, 1000);
        $ok = ($textSent['status'] ?? 0) >= 200 && ($textSent['status'] ?? 0) < 300;
        $assistant->forceFill(['delivery_status' => $ok ? 'sent' : 'failed'])->save();
        if (! $ok) {
            return;
        }

        app(MansourConversationRevival::class)->arm((int) $conversation->id, (int) $assistant->id);
        $burstService->markBurstProcessed($burst, $this->expectedVersion);
        $burstService->closeBurst($conversation->fresh() ?? $conversation, $this->expectedVersion);
        $conversation->recordAssistantActivity();
    }

    private function sendAppsBubbles(
        ChatbotGreenApiService $greenApi,
        MansourLines $lines,
        MansourConversationBurstService $burstService,
        ChatbotConversation $conversation,
        ChatbotMessage $assistant,
        string $reply,
    ): bool {
        $instance = $conversation->instance ?? ChatbotInstance::query()->find($this->instanceId);
        if (! $instance instanceof ChatbotInstance) {
            return false;
        }

        $parts = AppsPrompt::bubbles($reply);
        $assistant->forceFill(['message' => $parts[0]])->save();
        $messages = [$assistant];
        foreach (array_slice($parts, 1) as $part) {
            $messages[] = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => $part,
                'delivery_status' => 'pending',
            ]);
        }

        foreach ($messages as $message) {
            if (! $this->stillLatest($burstService)) {
                $burstService->discardUnsentReply($conversation);

                return false;
            }

            $text = (string) $message->message;
            $video = AppsPrompt::videoDelivery($text, AppsPrompt::links($instance)['video']);
            if ($video['video'] !== null) {
                $sendUrl = $lines->urlFor($instance, $conversation);
                $sendResult = $greenApi->sendFileByUrl(
                    $sendUrl,
                    $this->chatId,
                    $video['video'],
                    'aswatkum-guide.mp4',
                    $video['text'] !== '' ? $video['text'] : null,
                    true,
                );
                $ok = ($sendResult['status'] ?? 0) >= 200 && ($sendResult['status'] ?? 0) < 300;
                if (! $ok) {
                    Log::warning('apps-agent.video-send', ['status' => $sendResult['status'] ?? 0]);
                    $sendResult = $lines->send($instance, $conversation, $this->chatId, $text, AppsPrompt::TYPING_MS);
                    $ok = ($sendResult['status'] ?? 0) >= 200 && ($sendResult['status'] ?? 0) < 300;
                } else {
                    $message->forceFill([
                        'message' => $video['text'] !== '' ? $video['text'] : 'فيديو الشرح',
                        'message_type' => 'video',
                    ])->save();
                }
            } else {
                $sendResult = $lines->send(
                    $instance,
                    $conversation,
                    $this->chatId,
                    $text,
                    AppsPrompt::TYPING_MS,
                );
                $ok = ($sendResult['status'] ?? 0) >= 200 && ($sendResult['status'] ?? 0) < 300;
            }
            $message->forceFill([
                'delivery_status' => $ok ? 'sent' : 'failed',
            ])->save();
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Messages from before this activation stay unanswered.
     * A trigger sent after the bot is on starts the new campaign.
     *
     * @param  list<ChatbotMessage>  $burst
     */
    private function skipUntilFreshTrigger(
        ChatbotInstance $instance,
        MansourConversationBurstService $burstService,
        array $burst,
        ChatbotConversation $conversation,
    ): bool {
        $activatedAt = $instance->bot_activated_at;
        if ($activatedAt === null) {
            return false;
        }

        $contact = $instance->mansourContacts()->where('chat_id', $this->chatId)->first(['triggered_at']);
        if ($contact !== null && $contact->triggered_at !== null && $contact->triggered_at->greaterThanOrEqualTo($activatedAt)) {
            return false;
        }

        $burstService->markBurstProcessed($burst, $this->expectedVersion);
        $burstService->closeBurst($conversation, $this->expectedVersion);

        return true;
    }

    /**
     * @return list<array{role:string,message:string}>
     */
    private function dialogue(ChatbotConversation $conversation): array
    {
        $rows = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get(['id', 'role', 'message', 'message_type', 'attachment_disk', 'attachment_path', 'attachment_mime']);
        $lastImageId = null;
        foreach ($rows as $row) {
            if ($row->role === 'user' && $row->message_type === 'image') {
                $lastImageId = $row->id;
            }
        }

        return $rows->map(function (ChatbotMessage $message) use ($lastImageId): array {
            $turn = [
                'role' => (string) $message->role,
                'message' => (string) $message->message,
            ];
            if ($message->id === $lastImageId) {
                $image = $this->imageDataUrl($message);
                if ($image !== null) {
                    $turn['image'] = $image;
                }
            }

            return $turn;
        })->all();
    }

    private function imageDataUrl(ChatbotMessage $message): ?string
    {
        $path = is_string($message->attachment_path) ? trim($message->attachment_path) : '';
        $mime = is_string($message->attachment_mime) ? strtolower(trim($message->attachment_mime)) : '';
        if ($path === '' || ! str_starts_with($mime, 'image/')) {
            return null;
        }
        $disk = is_string($message->attachment_disk) && $message->attachment_disk !== ''
            ? $message->attachment_disk
            : (string) config('malan.media.disk', 'local');
        try {
            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }
            $binary = Storage::disk($disk)->get($path);
            if (! is_string($binary) || $binary === '' || strlen($binary) > 4 * 1024 * 1024) {
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode($binary);
        } catch (Throwable) {
            return null;
        }
    }

    private function waitWhileLatest(
        MansourConversationBurstService $burstService,
        ChatbotGreenApiService $greenApi,
        ChatbotInstance $instance,
        int $waitMs,
    ): bool {
        if (! $this->stillLatest($burstService)) {
            return false;
        }
        if (app()->runningUnitTests() || $waitMs <= 0) {
            return true;
        }

        $sendUrl = app(MansourLines::class)->urlFor($instance, ChatbotConversation::query()->find($this->conversationId));
        if ($sendUrl !== '') {
            try {
                $greenApi->sendTyping($sendUrl, $this->chatId, min(20000, $waitMs));
            } catch (Throwable) {
                // Typing is optional. The reply still sends if the version is current.
            }
        }

        $deadline = microtime(true) + ($waitMs / 1000);
        while (microtime(true) < $deadline) {
            if (! $this->stillLatest($burstService)) {
                return false;
            }
            $slice = min(1.0, $deadline - microtime(true));
            if ($slice > 0.05) {
                usleep((int) round($slice * 1_000_000));
            }
        }

        return $this->stillLatest($burstService);
    }

    private function stillLatest(MansourConversationBurstService $burstService): bool
    {
        $conversation = ChatbotConversation::query()->find($this->conversationId);

        return $conversation !== null
            && $burstService->currentVersion($conversation) === $this->expectedVersion
            && $conversation->allowsAutomaticReply();
    }
}
