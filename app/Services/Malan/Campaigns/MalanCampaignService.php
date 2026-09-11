<?php

declare(strict_types=1);

namespace App\Services\Malan\Campaigns;

use App\Jobs\ProcessMalanCampaignBlastJob;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\Malan\MalanCampaign;
use App\Models\Malan\MalanCampaignContact;
use App\Models\User;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\Malan\MalanConversationContextService;
use App\Services\Malan\MalanPhoneNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MalanCampaignService
{
    public function __construct(
        protected MalanCampaignExcelParser $excelParser,
        protected MalanPhoneNormalizer $phoneNormalizer,
        protected MalanConversationContextService $contextService,
        protected ChatbotGreenApiService $greenApiService,
    ) {}

    public function webhookUrl(MalanCampaign $campaign): string
    {
        return route('ai-chatbot.greenapi.campaign-webhook', [
            'token' => $campaign->greenapi_webhook_token,
        ]);
    }

    public function findByWebhookToken(string $token): ?MalanCampaign
    {
        return MalanCampaign::query()
            ->where('greenapi_webhook_token', $token)
            ->first();
    }

    /**
     * @param  array{
     *     name: string,
     *     greenapi_url: string,
     *     opening_message?: string|null,
     *     excel: UploadedFile
     * }  $data
     */
    public function create(ChatbotInstance $instance, User $user, array $data): MalanCampaign
    {
        if (! $instance->hasMalanIntegration()) {
            throw new RuntimeException('Campaigns are only available for Malan chatbots.');
        }

        $rows = $this->excelParser->parse($data['excel']);
        if ($rows === []) {
            throw new RuntimeException('No valid phone numbers found in the spreadsheet.');
        }

        $disk = 'local';
        $path = $data['excel']->storeAs(
            'malan-campaigns/'.$instance->id,
            Str::uuid()->toString().'.'.$data['excel']->getClientOriginalExtension(),
            $disk,
        );

        $opening = trim((string) ($data['opening_message'] ?? ''));
        if ($opening === '') {
            $opening = $this->defaultOpeningMessage($instance);
        }

        return DB::transaction(function () use ($instance, $user, $data, $rows, $disk, $path, $opening) {
            $campaign = MalanCampaign::query()->create([
                'chatbot_instance_id' => $instance->id,
                'created_by_user_id' => $user->id,
                'name' => trim($data['name']),
                'status' => MalanCampaign::STATUS_READY,
                'is_active' => true,
                'greenapi_url' => trim($data['greenapi_url']),
                'opening_message' => $opening,
                'system_prompt' => trim((string) ($data['system_prompt'] ?? '')) !== ''
                    ? trim((string) $data['system_prompt'])
                    : MalanCampaign::defaultLeadSystemPrompt($instance),
                'excel_disk' => $disk,
                'excel_path' => $path,
                'excel_original_name' => $data['excel']->getClientOriginalName(),
                'contacts_count' => count($rows),
            ]);

            $this->insertContacts($campaign, $rows);

            return $campaign->fresh('contacts') ?? $campaign;
        });
    }

    /**
     * @param  array{
     *     name?: string,
     *     greenapi_url?: string,
     *     opening_message?: string,
     *     excel?: UploadedFile|null
     * }  $data
     */
    public function updateSettings(MalanCampaign $campaign, array $data): MalanCampaign
    {
        if ($campaign->isRunning()) {
            throw new RuntimeException('Stop the campaign before changing settings.');
        }

        if (isset($data['name'])) {
            $campaign->name = trim((string) $data['name']);
        }
        if (array_key_exists('greenapi_url', $data)) {
            $campaign->greenapi_url = trim((string) $data['greenapi_url']);
        }
        if (array_key_exists('opening_message', $data)) {
            $msg = trim((string) $data['opening_message']);
            if ($msg !== '') {
                $campaign->opening_message = $msg;
            }
        }
        if (array_key_exists('system_prompt', $data)) {
            $prompt = trim((string) $data['system_prompt']);
            $campaign->system_prompt = $prompt !== ''
                ? $prompt
                : MalanCampaign::defaultLeadSystemPrompt($campaign->instance);
        }
        if (array_key_exists('is_active', $data)) {
            $campaign->is_active = (bool) $data['is_active'];
        }

        if (($data['excel'] ?? null) instanceof UploadedFile) {
            $rows = $this->excelParser->parse($data['excel']);
            if ($rows === []) {
                throw new RuntimeException('No valid phone numbers found in the spreadsheet.');
            }

            $disk = 'local';
            $path = $data['excel']->storeAs(
                'malan-campaigns/'.$campaign->chatbot_instance_id,
                Str::uuid()->toString().'.'.$data['excel']->getClientOriginalExtension(),
                $disk,
            );

            if (is_string($campaign->excel_path) && $campaign->excel_path !== '') {
                Storage::disk((string) ($campaign->excel_disk ?: 'local'))->delete($campaign->excel_path);
            }

            $campaign->excel_disk = $disk;
            $campaign->excel_path = $path;
            $campaign->excel_original_name = $data['excel']->getClientOriginalName();
            $campaign->contacts_count = count($rows);
            $campaign->messages_triggered_count = 0;
            $campaign->responded_count = 0;
            $campaign->leads_stored_count = 0;
            $campaign->status = MalanCampaign::STATUS_READY;
            $campaign->started_at = null;
            $campaign->stopped_at = null;
            $campaign->completed_at = null;

            $campaign->contacts()->delete();
            $campaign->save();
            $this->insertContacts($campaign, $rows);
        } else {
            if ($campaign->contacts_count > 0 && $campaign->hasGreenApiCredentials()) {
                $campaign->status = MalanCampaign::STATUS_READY;
            }
            $campaign->save();
        }

        return $campaign->fresh() ?? $campaign;
    }

    /**
     * @param  list<int>|null  $contactIds  When null, all contacts (legacy). When set, only these are blasted.
     * @return array{campaign: MalanCampaign, queued: int, sent: int, resent: bool}
     */
    public function start(MalanCampaign $campaign, ?array $contactIds = null): array
    {
        if (! $campaign->canStart() && $campaign->status !== MalanCampaign::STATUS_RUNNING) {
            throw new RuntimeException('Campaign is not ready to start. Check Green API URL, opening message, and contacts.');
        }

        $selectedIds = $this->normalizeSelectedContactIds($campaign, $contactIds);
        if ($selectedIds === []) {
            throw new RuntimeException('No contacts selected.');
        }

        $resent = $campaign->contacts()
            ->whereIn('id', $selectedIds)
            ->whereNotNull('message_sent_at')
            ->exists();

        // Selected contacts: queue for (re)send.
        foreach ($campaign->contacts()->whereIn('id', $selectedIds)->orderBy('id')->cursor() as $contact) {
            $fixed = $this->rebuildChatIdForContact($contact);
            $contact->forceFill([
                'chat_id' => $fixed !== '' ? $fixed : $contact->chat_id,
                'phone_normalized' => $this->normalizePhoneDigits((string) $contact->phone) ?: $contact->phone_normalized,
                'status' => MalanCampaignContact::STATUS_PENDING,
                'message_sent_at' => null,
                'last_error' => null,
            ])->save();
        }

        // Unselected pending rows must not be picked up by this blast.
        $campaign->contacts()
            ->whereNotIn('id', $selectedIds)
            ->whereNull('message_sent_at')
            ->whereIn('status', [
                MalanCampaignContact::STATUS_PENDING,
                MalanCampaignContact::STATUS_QUEUED,
                MalanCampaignContact::STATUS_FAILED,
            ])
            ->update([
                'status' => MalanCampaignContact::STATUS_SKIPPED,
            ]);

        $queued = count($selectedIds);

        $campaign->forceFill([
            'status' => MalanCampaign::STATUS_RUNNING,
            'started_at' => $campaign->started_at ?? now(),
            'stopped_at' => null,
            'completed_at' => null,
        ])->save();

        // Ensure campaign chats can auto-reply after a previous emergency pause.
        ChatbotConversation::query()
            ->where('campaign_id', $campaign->id)
            ->where('bot_mode', ChatbotConversation::BOT_MODE_PAUSED)
            ->update(['bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE]);

        // Shared hosting often has no queue worker — process inline in chunks.
        // Also enqueue a follow-up job when a worker is available.
        $this->processUntilIdleOrStopped($campaign);
        ProcessMalanCampaignBlastJob::dispatch((int) $campaign->id);

        $campaign = $campaign->fresh() ?? $campaign;
        $remaining = $campaign->contacts()
            ->whereIn('id', $selectedIds)
            ->whereNull('message_sent_at')
            ->whereIn('status', [
                MalanCampaignContact::STATUS_PENDING,
                MalanCampaignContact::STATUS_QUEUED,
            ])
            ->count();

        return [
            'campaign' => $campaign,
            'queued' => $queued,
            'sent' => max(0, $queued - $remaining),
            'resent' => $resent,
        ];
    }

    /**
     * @param  list<int>|null  $contactIds
     * @return list<int>
     */
    private function normalizeSelectedContactIds(MalanCampaign $campaign, ?array $contactIds): array
    {
        if ($contactIds === null) {
            return $campaign->contacts()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $wanted = array_values(array_unique(array_filter(array_map(
            static fn ($id) => (int) $id,
            $contactIds
        ), static fn (int $id) => $id > 0)));

        if ($wanted === []) {
            return [];
        }

        return $campaign->contacts()
            ->whereIn('id', $wanted)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Reset contacts so Trigger can send the opening WhatsApp message again.
     */
    public function requeueAllContactsForResend(MalanCampaign $campaign): void
    {
        foreach ($campaign->contacts()->orderBy('id')->get() as $contact) {
            $fixed = $this->rebuildChatIdForContact($contact);
            $contact->forceFill([
                'chat_id' => $fixed !== '' ? $fixed : $contact->chat_id,
                'phone_normalized' => $this->normalizePhoneDigits((string) $contact->phone) ?: $contact->phone_normalized,
                'status' => MalanCampaignContact::STATUS_PENDING,
                'message_sent_at' => null,
                'last_error' => null,
            ])->save();
        }
    }

    /**
     * Send pending campaign messages until stopped, completed, or a safety cap is hit.
     */
    public function processUntilIdleOrStopped(MalanCampaign $campaign, int $batchSize = 25, int $maxBatches = 40): void
    {
        for ($i = 0; $i < $maxBatches; $i++) {
            $campaign->refresh();
            if (! $campaign->isRunning()) {
                return;
            }

            $more = $this->processPendingBatch($campaign, $batchSize);
            if (! $more) {
                return;
            }
        }
    }

    /**
     * @return bool True when more pending contacts remain.
     */
    public function processPendingBatch(MalanCampaign $campaign, int $batchSize = 20): bool
    {
        $campaign->refresh();
        if (! $campaign->isRunning()) {
            return false;
        }

        $contacts = MalanCampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->whereNull('message_sent_at')
            ->whereIn('status', [
                MalanCampaignContact::STATUS_PENDING,
                MalanCampaignContact::STATUS_QUEUED,
            ])
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        if ($contacts->isEmpty()) {
            $campaign->forceFill([
                'status' => MalanCampaign::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();
            $this->refreshAnalytics($campaign);

            return false;
        }

        foreach ($contacts as $contact) {
            $campaign->refresh();
            if (! $campaign->isRunning()) {
                return true;
            }

            try {
                $this->sendToContact($campaign, $contact);
            } catch (Throwable $e) {
                $contact->forceFill([
                    'status' => MalanCampaignContact::STATUS_FAILED,
                    'last_error' => $e->getMessage(),
                ])->save();
            }

            usleep(350_000);
        }

        $campaign->refresh();
        if (! $campaign->isRunning()) {
            return false;
        }

        $remaining = MalanCampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->whereNull('message_sent_at')
            ->whereIn('status', [
                MalanCampaignContact::STATUS_PENDING,
                MalanCampaignContact::STATUS_QUEUED,
            ])
            ->exists();

        if (! $remaining) {
            $campaign->forceFill([
                'status' => MalanCampaign::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();
            $this->refreshAnalytics($campaign);

            return false;
        }

        return true;
    }

    public function stop(MalanCampaign $campaign): MalanCampaign
    {
        if (! $campaign->canStop()) {
            // Still pause chats even if already stopped/completed — stops self-reply loops.
            $this->pauseCampaignConversations($campaign);

            return $campaign;
        }

        $campaign->forceFill([
            'status' => MalanCampaign::STATUS_STOPPED,
            'stopped_at' => now(),
        ])->save();

        $this->pauseCampaignConversations($campaign);

        return $campaign->fresh() ?? $campaign;
    }

    public function pauseCampaignConversations(MalanCampaign $campaign): void
    {
        ChatbotConversation::query()
            ->where('campaign_id', $campaign->id)
            ->where('bot_mode', '!=', ChatbotConversation::BOT_MODE_PAUSED)
            ->update([
                'bot_mode' => ChatbotConversation::BOT_MODE_PAUSED,
            ]);
    }

    public function resumeCampaignConversations(MalanCampaign $campaign): void
    {
        ChatbotConversation::query()
            ->where('campaign_id', $campaign->id)
            ->where('bot_mode', ChatbotConversation::BOT_MODE_PAUSED)
            ->update(['bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE]);
    }

    public function usesSameGreenApiAsInstance(MalanCampaign $campaign, ChatbotInstance $instance): bool
    {
        $campaignId = $this->greenApiService->greenApiInstanceIdFromUrl($campaign->greenapi_url);
        $mainId = $this->greenApiService->greenApiInstanceIdFromUrl($instance->greenapi_url);

        if ($campaignId === null || $mainId === null) {
            $a = trim((string) $campaign->greenapi_url);
            $b = trim((string) $instance->greenapi_url);

            return $a !== '' && $b !== '' && hash_equals($a, $b);
        }

        return hash_equals($campaignId, $mainId);
    }

    /**
     * Find the campaign contact that owns this WhatsApp chat (after a blast was sent).
     *
     * @return array{campaign: MalanCampaign, contact: MalanCampaignContact}|null
     */
    public function findActiveCampaignRoute(ChatbotInstance $instance, string $chatId): ?array
    {
        $contact = $this->findContactAcrossCampaigns($instance, $chatId);
        if ($contact === null) {
            return null;
        }

        $campaign = $contact->campaign;
        if ($campaign === null || (int) $campaign->chatbot_instance_id !== (int) $instance->id) {
            return null;
        }

        return ['campaign' => $campaign, 'contact' => $contact];
    }

    public function findContactForChat(MalanCampaign $campaign, string $chatId): ?MalanCampaignContact
    {
        $contact = MalanCampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->where('chat_id', $chatId)
            ->first();

        if ($contact !== null) {
            return $contact;
        }

        $digits = preg_replace('/\D+/', '', explode('@', $chatId)[0] ?? '') ?? '';
        if ($digits === '') {
            return null;
        }

        return MalanCampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->where(function ($q) use ($digits): void {
                $q->where('phone_normalized', $digits)
                    ->orWhere('phone_normalized', '0'.substr($digits, -9))
                    ->orWhere('phone', 'like', '%'.substr($digits, -9).'%');
            })
            ->first();
    }

    private function findContactAcrossCampaigns(ChatbotInstance $instance, string $chatId): ?MalanCampaignContact
    {
        $digits = preg_replace('/\D+/', '', explode('@', $chatId)[0] ?? '') ?? '';

        return MalanCampaignContact::query()
            ->whereHas('campaign', function ($q) use ($instance): void {
                $q->where('chatbot_instance_id', $instance->id);
            })
            ->whereNotNull('message_sent_at')
            ->where(function ($q) use ($chatId, $digits): void {
                $q->where('chat_id', $chatId);
                if ($digits !== '') {
                    $q->orWhere('phone_normalized', $digits)
                        ->orWhere('phone_normalized', '0'.substr($digits, -9))
                        ->orWhere('chat_id', 'like', '%'.$digits.'%');
                }
            })
            ->with('campaign')
            ->orderByDesc('id')
            ->first();
    }

    public function setBotActive(MalanCampaign $campaign, bool $active): MalanCampaign
    {
        $campaign->forceFill(['is_active' => $active])->save();

        if (! $active) {
            $this->pauseCampaignConversations($campaign);
        } else {
            $this->resumeCampaignConversations($campaign);
        }

        return $campaign->fresh() ?? $campaign;
    }


    public function refreshAnalytics(MalanCampaign $campaign): MalanCampaign
    {
        $campaign->forceFill([
            'contacts_count' => $campaign->contacts()->count(),
            'messages_triggered_count' => $campaign->contacts()->whereNotNull('message_sent_at')->count(),
            'responded_count' => $campaign->contacts()->whereNotNull('responded_at')->count(),
            'leads_stored_count' => $campaign->contacts()->whereNotNull('lead_created_at')->count(),
        ])->save();

        return $campaign->fresh() ?? $campaign;
    }

    /**
     * Send the opening blast to one contact and create a leads-only conversation.
     */
    public function sendToContact(MalanCampaign $campaign, MalanCampaignContact $contact): void
    {
        $campaign->refresh();
        if (! $campaign->isRunning()) {
            return;
        }

        if ($contact->message_sent_at !== null) {
            return;
        }

        $instance = $campaign->instance;
        if ($instance === null || ! $instance->hasMalanIntegration()) {
            $contact->forceFill([
                'status' => MalanCampaignContact::STATUS_FAILED,
                'last_error' => 'Malan instance missing.',
            ])->save();

            return;
        }

        $sendUrl = trim((string) $campaign->greenapi_url);
        if ($sendUrl === '') {
            $contact->forceFill([
                'status' => MalanCampaignContact::STATUS_FAILED,
                'last_error' => 'Missing Green API URL.',
            ])->save();

            return;
        }

        $chatId = $this->resolveChatId($contact);
        if ($chatId === '') {
            $contact->forceFill([
                'status' => MalanCampaignContact::STATUS_SKIPPED,
                'last_error' => 'Invalid phone number.',
            ])->save();

            return;
        }

        try {
            $conversation = $this->ensureConversation($campaign, $instance, $contact, $chatId);
            // Every trigger blast starts clean — no stale name/number flags or old bubbles.
            $this->contextService->resetCampaignConversationForResend($conversation, $instance);

            $message = trim((string) $campaign->opening_message);
            $sendResult = $this->greenApiService->sendMessage($sendUrl, $chatId, $message);
            $httpOk = ($sendResult['status'] ?? 0) >= 200 && ($sendResult['status'] ?? 0) < 300;
            $body = $sendResult['body'] ?? null;
            $idMessage = is_array($body) ? ($body['idMessage'] ?? null) : null;
            $ok = $httpOk && is_string($idMessage) && $idMessage !== '';

            $errorDetail = null;
            if (! $ok) {
                if (is_array($body)) {
                    $errorDetail = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif (is_string($body) && $body !== '') {
                    $errorDetail = $body;
                } else {
                    $errorDetail = 'Green API send failed (HTTP '.($sendResult['status'] ?? 0).') chatId='.$chatId;
                }
            }

            ChatbotMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'sender_type' => 'ai',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'delivery_status' => $ok ? 'sent' : 'failed',
                'message_type' => 'text',
                'message' => $message,
                'metadata' => [
                    'campaign_id' => $campaign->id,
                    'campaign_contact_id' => $contact->id,
                    'campaign_blast' => true,
                    'greenapi_chat_id' => $chatId,
                    'greenapi_status' => $sendResult['status'] ?? null,
                    'greenapi_id_message' => $idMessage,
                    'greenapi_body' => is_array($body) ? $body : null,
                ],
            ]);

            // Keep a stable local external id (campaign-scoped) so we never collide with
            // the main WhatsApp inbox conversation for the same phone on this instance.
            $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
            $meta['whatsapp_chat_id'] = $chatId;
            $conversation->forceFill([
                'contact_phone' => preg_replace('/@.*$/', '', $chatId) ?: $conversation->contact_phone,
                'metadata' => $meta,
            ])->save();
            $conversation->recordAssistantActivity();

            if (! $ok) {
                $contact->forceFill([
                    'status' => MalanCampaignContact::STATUS_FAILED,
                    'conversation_id' => $conversation->id,
                    'chat_id' => $chatId,
                    'last_error' => mb_substr((string) $errorDetail, 0, 1000),
                ])->save();

                return;
            }

            $contact->forceFill([
                'status' => MalanCampaignContact::STATUS_SENT,
                'conversation_id' => $conversation->id,
                'chat_id' => $chatId,
                'phone_normalized' => $this->normalizePhoneDigits((string) $contact->phone) ?: $contact->phone_normalized,
                'message_sent_at' => now(),
                'last_error' => null,
            ])->save();

            $campaign->increment('messages_triggered_count');
        } catch (Throwable $e) {
            $contact->forceFill([
                'status' => MalanCampaignContact::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ])->save();
        }
    }

    public function markResponded(MalanCampaignContact $contact): void
    {
        if ($contact->responded_at !== null) {
            return;
        }

        $updates = [
            'responded_at' => now(),
        ];
        if ($contact->status !== MalanCampaignContact::STATUS_LEAD_CREATED) {
            $updates['status'] = MalanCampaignContact::STATUS_RESPONDED;
        }
        $contact->forceFill($updates)->save();
        $contact->campaign?->increment('responded_count');
    }

    public function markLeadCreated(ChatbotConversation $conversation, ?int $leadId = null): void
    {
        if ($conversation->campaign_id === null) {
            return;
        }

        $contact = MalanCampaignContact::query()
            ->where('campaign_id', $conversation->campaign_id)
            ->where(function ($q) use ($conversation): void {
                $q->where('conversation_id', $conversation->id);
            })
            ->first();

        if ($contact === null) {
            return;
        }

        if ($contact->lead_created_at !== null) {
            return;
        }

        $contact->forceFill([
            'status' => MalanCampaignContact::STATUS_LEAD_CREATED,
            'lead_created_at' => now(),
            'malan_lead_id' => $leadId,
        ])->save();

        $contact->campaign?->increment('leads_stored_count');
    }

    public function defaultOpeningMessage(?\App\Models\AiChatbot\ChatbotInstance $instance = null): string
    {
        $base = "أهلين 🌟\nأنا سالي من ملان إنترنت.\nحبّينا نفحص إذا مهتم/ة باشتراك إنترنت جديد — بتجاوبني بجملة قصيرة ونكمّل مع بعض؟";

        return \App\Support\InternetCompanyProfile::forInstance($instance)->localize($base);
    }

    /**
     * @param  list<array{name:?string,phone:string,city:?string}>  $rows
     */
    private function insertContacts(MalanCampaign $campaign, array $rows): void
    {
        $now = now();
        $payload = [];
        foreach ($rows as $row) {
            $normalizedDigits = $this->normalizePhoneDigits($row['phone']);
            $digits = $normalizedDigits ?? (preg_replace('/\D+/', '', $row['phone']) ?? '');

            $payload[] = [
                'campaign_id' => $campaign->id,
                'name' => $row['name'],
                'phone' => $row['phone'],
                'phone_normalized' => $digits !== '' ? $digits : null,
                'city' => $row['city'],
                'chat_id' => $digits !== '' ? $this->chatIdFromDigits($digits) : null,
                'status' => MalanCampaignContact::STATUS_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 200) as $chunk) {
            MalanCampaignContact::query()->insert($chunk);
        }
    }

    /**
     * Add a manually entered phone to a campaign (or return the existing row for the same number).
     *
     * @return array{contact: MalanCampaignContact, created: bool}
     */
    public function addCustomContact(MalanCampaign $campaign, string $phone, ?string $name = null): array
    {
        $raw = trim($phone);
        if ($raw === '') {
            throw new RuntimeException('Phone number is required.');
        }

        $digits = $this->normalizePhoneDigits($raw);
        if ($digits === null || $digits === '') {
            throw new RuntimeException('Invalid phone number.');
        }

        $chatId = $this->chatIdFromDigits($digits);
        if ($chatId === '') {
            throw new RuntimeException('Invalid phone number.');
        }

        $existing = MalanCampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->where(function ($q) use ($digits, $chatId): void {
                $q->where('chat_id', $chatId)
                    ->orWhere('phone_normalized', $digits);
                if (preg_match('/^0(5\d{8})$/', $digits, $m)) {
                    $q->orWhere('phone_normalized', '972'.$m[1])
                        ->orWhere('phone_normalized', $m[1]);
                }
            })
            ->first();

        $cleanName = is_string($name) ? trim($name) : '';
        if ($cleanName === '') {
            $cleanName = null;
        }

        if ($existing !== null) {
            $updates = [];
            if ($cleanName !== null && trim((string) ($existing->name ?? '')) === '') {
                $updates['name'] = $cleanName;
            }
            if ($existing->chat_id !== $chatId) {
                $updates['chat_id'] = $chatId;
            }
            if ((string) $existing->phone_normalized !== $digits) {
                $updates['phone_normalized'] = $digits;
            }
            if ($updates !== []) {
                $existing->forceFill($updates)->save();
            }

            return [
                'contact' => $existing->fresh() ?? $existing,
                'created' => false,
            ];
        }

        $contact = MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'name' => $cleanName,
            'phone' => $raw,
            'phone_normalized' => $digits,
            'city' => null,
            'chat_id' => $chatId,
            'status' => MalanCampaignContact::STATUS_PENDING,
        ]);

        $campaign->forceFill([
            'contacts_count' => $campaign->contacts()->count(),
        ])->save();

        return [
            'contact' => $contact,
            'created' => true,
        ];
    }

    private function resolveChatId(MalanCampaignContact $contact): string
    {
        $digits = is_string($contact->phone_normalized) && $contact->phone_normalized !== ''
            ? $contact->phone_normalized
            : (preg_replace('/\D+/', '', (string) $contact->phone) ?? '');

        $chatId = $this->chatIdFromDigits($digits);
        if ($chatId !== '') {
            return $chatId;
        }

        if (is_string($contact->chat_id) && $contact->chat_id !== '') {
            // Rebuild if stored chat id is missing country code.
            $fromStored = preg_replace('/\D+/', '', explode('@', $contact->chat_id)[0] ?? '') ?? '';

            return $this->chatIdFromDigits($fromStored);
        }

        return '';
    }

    /**
     * Public helper for ops repair / UI diagnostics.
     */
    public function rebuildChatIdForContact(MalanCampaignContact $contact): string
    {
        return $this->resolveChatId($contact);
    }

    /**
     * Normalize spreadsheet phones into local 05… or international digits.
     */
    public function normalizePhoneDigits(string $raw): ?string
    {
        $normalized = $this->phoneNormalizer->normalize($raw);
        if ($normalized['valid']) {
            return (string) $normalized['normalized'];
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        // 9725XXXXXXXX → 05XXXXXXXX
        if (str_starts_with($digits, '972') && strlen($digits) >= 12) {
            $local = '0'.ltrim(substr($digits, 3), '0');
            if (preg_match('/^05\d{8}$/', $local)) {
                return $local;
            }
        }

        // Bare Israeli mobile without leading 0: 5XXXXXXXX (9 digits)
        if (preg_match('/^5\d{8}$/', $digits)) {
            return '0'.$digits;
        }

        return $digits;
    }

    private function chatIdFromDigits(string $digits): string
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if ($digits === '') {
            return '';
        }

        // Local Israeli mobile 05XXXXXXXX → 9725XXXXXXXX
        if (preg_match('/^0(5\d{8})$/', $digits, $m)) {
            return '972'.$m[1].'@c.us';
        }

        // Bare mobile without 0: 5XXXXXXXX
        if (preg_match('/^5\d{8}$/', $digits)) {
            return '972'.$digits.'@c.us';
        }

        // Already international without +: 9725XXXXXXXX
        if (preg_match('/^9725\d{8}$/', $digits)) {
            return $digits.'@c.us';
        }

        // Fallback: if starts with 0 and looks like local, strip 0 and prefix 972
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '972'.substr($digits, 1).'@c.us';
        }

        // Last resort — still attach @c.us so Green API gets a chatId shape
        if (strlen($digits) >= 8 && strlen($digits) <= 15) {
            return $digits.'@c.us';
        }

        return '';
    }

    private function ensureConversation(
        MalanCampaign $campaign,
        ChatbotInstance $instance,
        MalanCampaignContact $contact,
        string $chatId,
    ): ChatbotConversation {
        if ($contact->conversation_id) {
            $existing = ChatbotConversation::query()->find($contact->conversation_id);
            if ($existing !== null) {
                return $existing;
            }
        }

        $existing = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->where('campaign_id', $campaign->id)
            ->where('external_chat_id', $chatId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // Campaign chats share the Malan chatbot instance with the main inbox, so the
        // real WhatsApp chatId can already exist there. Keep a scoped external id.
        $scopedExternalId = 'campaign:'.$campaign->id.':'.$chatId;

        $existingScoped = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->where('campaign_id', $campaign->id)
            ->where('external_chat_id', $scopedExternalId)
            ->first();

        if ($existingScoped !== null) {
            return $existingScoped;
        }

        $meta = [
            'campaign_id' => $campaign->id,
            'campaign_contact_id' => $contact->id,
            'campaign_lead_bot' => true,
            'source' => 'malan_campaign',
            'whatsapp_chat_id' => $chatId,
        ];

        return ChatbotConversation::query()->create([
            'user_id' => $instance->user_id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => $contact->name ?: ($contact->phone_normalized ?: $contact->phone),
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => $scopedExternalId,
            'contact_phone' => preg_replace('/@.*$/', '', $chatId) ?: ($contact->phone_normalized ?: $contact->phone),
            'contact_name' => $contact->name,
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
            'metadata' => $meta,
        ]);
    }
}
