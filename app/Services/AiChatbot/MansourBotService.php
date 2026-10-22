<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotConversationContext;
use App\Models\AiChatbot\ChatbotConversationInstruction;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\AiChatbot\ChatbotToolExecution;
use App\Models\AiChatbot\MansourContact;
use App\Jobs\SendInviteNudgeJob;
use App\Models\User;
use App\Services\Malan\Campaigns\MalanCampaignExcelParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class MansourBotService
{
    public function __construct(
        protected MalanCampaignExcelParser $parser,
        protected ChatbotGreenApiService $greenApi,
    ) {}

    public function ensureDefaultBot(User $user): ChatbotInstance
    {
        $existing = ChatbotInstance::query()
            ->where('user_id', $user->id)
            ->where('integration_type', 'mansour')
            ->orderBy('id')
            ->first();

        if ($existing instanceof ChatbotInstance) {
            return $existing;
        }

        return $this->create($user, 'بوت الفرز', MansourPrompt::defaultCampaignPrompt(), MansourPrompt::DEFAULT_OPENING, null);
    }

    public const APPS_NAME = 'بوت تطبيق أصواتكم';

    /**
     * Turn the user's «منصور 2» copy into the أصواتكم app bot. Leaves every other bot alone.
     */
    public function ensureAppsBot(User $user): ?ChatbotInstance
    {
        $ids = app(ChatbotAuthorizationService::class)->instancesForUser($user)->pluck('id');
        $bots = ChatbotInstance::query()
            ->whereIn('id', $ids)
            ->where('integration_type', 'mansour')
            ->orderBy('id')
            ->get();

        $existing = $bots->first(fn (ChatbotInstance $bot): bool => $bot->isAppsBot());
        if ($existing instanceof ChatbotInstance) {
            if ($this->isMansourTwo((string) $existing->name) || $existing->name === 'بوت التطبيقات') {
                $existing->forceFill(['name' => self::APPS_NAME])->save();
                $existing = $existing->fresh() ?? $existing;
            }

            return $this->refreshAppsCopy($existing);
        }

        $target = $bots->first(fn (ChatbotInstance $bot): bool => $this->isMansourTwo((string) $bot->name));
        if (! $target instanceof ChatbotInstance) {
            return null;
        }

        $settings = is_array($target->integration_settings) ? $target->integration_settings : [];
        $settings['role'] = 'apps';
        $settings['opening_message'] = AppsPrompt::DEFAULT_OPENING;
        if (trim((string) ($settings['muwahada_url'] ?? '')) === '') {
            $settings['muwahada_url'] = AppsPrompt::MUWAHADA_URL;
        }

        $target->forceFill([
            'name' => self::APPS_NAME,
            'system_prompt' => AppsPrompt::defaultCampaignPrompt(),
            'integration_settings' => $settings,
        ])->save();

        return $target->fresh();
    }

    public const DIMA_NAME = 'ديما';

    /**
     * Add ديما beside الفرز and التطبيقات. Never rewrites those two.
     */
    public function ensureDimaBot(User $user): ?ChatbotInstance
    {
        $ids = app(ChatbotAuthorizationService::class)->instancesForUser($user)->pluck('id');
        $bots = ChatbotInstance::query()
            ->whereIn('id', $ids)
            ->where('integration_type', 'mansour')
            ->orderBy('id')
            ->get();
        if ($bots->isEmpty()) {
            return null;
        }

        $existing = $bots->first(fn (ChatbotInstance $bot): bool => $bot->isDimaBot());
        if ($existing instanceof ChatbotInstance) {
            return $this->refreshDimaCopy($existing);
        }

        $created = $this->create($user, self::DIMA_NAME, DimaPrompt::instructions(), DimaPrompt::DEFAULT_OPENING, null);
        $settings = is_array($created->integration_settings) ? $created->integration_settings : [];
        $settings['role'] = 'dima';
        $settings['opening_message'] = DimaPrompt::DEFAULT_OPENING;
        $created->forceFill(['integration_settings' => $settings])->save();

        return $created->fresh();
    }

    public const INVITE_NAME = 'دعوه نسائيه';

    public const YOUTH_INVITE_NAME = 'دعوة الشباب';

    /**
     * Add دعوه نسائيه beside the other Mowahada bots. Never rewrites them.
     */
    public function ensureInviteBot(User $user): ?ChatbotInstance
    {
        $ids = app(ChatbotAuthorizationService::class)->instancesForUser($user)->pluck('id');
        $bots = ChatbotInstance::query()
            ->whereIn('id', $ids)
            ->where('integration_type', 'mansour')
            ->orderBy('id')
            ->get();
        if ($bots->isEmpty()) {
            return null;
        }

        $existing = $bots->first(fn (ChatbotInstance $bot): bool => $bot->isWomenInviteBot());
        if ($existing instanceof ChatbotInstance) {
            $existing = $this->refreshInviteCopy($existing);
            $this->shareInviteBot($existing);

            return $existing;
        }

        $created = $this->create($user, self::INVITE_NAME, InvitePrompt::instructions(), InvitePrompt::template(), null);
        $settings = is_array($created->integration_settings) ? $created->integration_settings : [];
        $settings['role'] = 'invite';
        $settings['opening_message'] = InvitePrompt::template();
        $created->forceFill(['integration_settings' => $settings])->save();
        $created = $created->fresh() ?? $created;
        $this->shareInviteBot($created);

        return $created;
    }

    /**
     * Add دعوة الشباب beside the women's invitation bot.
     */
    public function ensureYouthInviteBot(User $user): ?ChatbotInstance
    {
        $ids = app(ChatbotAuthorizationService::class)->instancesForUser($user)->pluck('id');
        $bots = ChatbotInstance::query()
            ->whereIn('id', $ids)
            ->where('integration_type', 'mansour')
            ->orderBy('id')
            ->get();
        if ($bots->isEmpty()) {
            return null;
        }

        $existing = $bots->first(fn (ChatbotInstance $bot): bool => $bot->isYouthInviteBot());
        if ($existing instanceof ChatbotInstance) {
            $existing = $this->refreshYouthCopy($existing);
            $this->shareInviteBot($existing);

            return $existing;
        }

        $created = $this->create($user, self::YOUTH_INVITE_NAME, InvitePrompt::instructions(InvitePrompt::YOUTH), InvitePrompt::template(InvitePrompt::YOUTH), null);
        $settings = is_array($created->integration_settings) ? $created->integration_settings : [];
        $settings['role'] = 'youth';
        $settings['opening_message'] = InvitePrompt::template(InvitePrompt::YOUTH);
        $created->forceFill(['integration_settings' => $settings])->save();
        $created = $created->fresh() ?? $created;
        $this->shareInviteBot($created);

        return $created;
    }

    /**
     * Anyone who can already open a Mowahada bot can open the invitation bots too.
     */
    public function shareInviteBot(?ChatbotInstance $invite = null): int
    {
        $ownerId = User::query()->where('email', User::MOWAHADA_EMAIL)->value('id');
        if ($ownerId === null) {
            return 0;
        }

        if (! $invite instanceof ChatbotInstance) {
            $granted = 0;
            $bots = ChatbotInstance::query()
                ->where('user_id', $ownerId)
                ->where('integration_type', 'mansour')
                ->orderBy('id')
                ->get()
                ->filter(fn (ChatbotInstance $bot): bool => $bot->isInviteBot());
            foreach ($bots as $bot) {
                $granted += $this->shareInviteBot($bot);
            }

            return $granted;
        }

        $botIds = ChatbotInstance::query()
            ->where('user_id', $ownerId)
            ->where('integration_type', 'mansour')
            ->where('id', '!=', $invite->id)
            ->pluck('id');
        if ($botIds->isEmpty()) {
            return 0;
        }

        $granted = 0;
        $authz = app(ChatbotAuthorizationService::class);
        $memberships = ChatbotInstanceUser::query()
            ->whereIn('instance_id', $botIds)
            ->get()
            ->groupBy('user_id');
        foreach ($memberships as $userId => $rows) {
            $user = User::query()->find($userId);
            if (! $user instanceof User || (int) $user->id === (int) $ownerId) {
                continue;
            }
            if ($authz->membership($invite, $user) !== null) {
                continue;
            }
            $permissions = $rows->contains(fn (ChatbotInstanceUser $row): bool => (bool) data_get($row->permissions, 'run_tests'))
                ? ['run_tests' => true]
                : null;
            $authz->grantAccess($invite, $user, $this->strongestRole($rows), $permissions);
            $granted++;
        }

        return $granted;
    }

    /**
     * @param  Collection<int, ChatbotInstanceUser>  $rows
     */
    private function strongestRole(Collection $rows): string
    {
        $rank = [
            ChatbotInstanceUser::ROLE_VIEWER => 1,
            ChatbotInstanceUser::ROLE_AGENT => 2,
            ChatbotInstanceUser::ROLE_MANAGER => 3,
            ChatbotInstanceUser::ROLE_OWNER => 4,
        ];
        $best = ChatbotInstanceUser::ROLE_VIEWER;
        foreach ($rows as $row) {
            $role = (string) $row->role;
            if (($rank[$role] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $role;
            }
        }

        return $best;
    }

    public function copy(ChatbotInstance $source, User $user): ChatbotInstance
    {
        $this->assertMansour($source);

        $prompt = trim((string) $source->system_prompt);

        $copy = $this->create(
            $user,
            $this->nextName($user, $source->name),
            $prompt !== '' ? $prompt : MansourPrompt::defaultCampaignPrompt(),
            $source->isAppsBot()
                ? AppsPrompt::DEFAULT_OPENING
                : MansourPrompt::opening($source),
            null, // a WhatsApp number belongs to one bot; the copy starts without numbers
        );
        if ($source->isAppsBot()) {
            $settings = is_array($copy->integration_settings) ? $copy->integration_settings : [];
            $sourceSettings = is_array($source->integration_settings) ? $source->integration_settings : [];
            $settings['role'] = 'apps';
            foreach (['delegate_name', 'aswatkum_url', 'muwahada_url', 'guide_video_url'] as $key) {
                if (isset($sourceSettings[$key])) {
                    $settings[$key] = $sourceSettings[$key];
                }
            }
            $copy->forceFill(['integration_settings' => $settings])->save();
        }
        if ($source->isDimaBot()) {
            $settings = is_array($copy->integration_settings) ? $copy->integration_settings : [];
            $settings['role'] = 'dima';
            $settings['opening_message'] = DimaPrompt::DEFAULT_OPENING;
            $copy->forceFill([
                'system_prompt' => DimaPrompt::instructions(),
                'integration_settings' => $settings,
            ])->save();
        }
        if ($source->isYouthInviteBot()) {
            $settings = is_array($copy->integration_settings) ? $copy->integration_settings : [];
            $settings['role'] = 'youth';
            $settings['opening_message'] = InvitePrompt::template(InvitePrompt::YOUTH);
            $copy->forceFill([
                'system_prompt' => InvitePrompt::instructions(InvitePrompt::YOUTH),
                'integration_settings' => $settings,
            ])->save();
        } elseif ($source->isInviteBot()) {
            $settings = is_array($copy->integration_settings) ? $copy->integration_settings : [];
            $settings['role'] = 'invite';
            $settings['opening_message'] = InvitePrompt::template();
            $copy->forceFill([
                'system_prompt' => InvitePrompt::instructions(),
                'integration_settings' => $settings,
            ])->save();
        }

        return $copy->fresh() ?? $copy;
    }

    /**
     * @param  list<string>|null  $greenApiUrls  one per Green API instance; the live one is kept or picked automatically. Null leaves the lines as they are
     */
    /**
     * @param  array{delegate_name?:string,aswatkum_url?:string,muwahada_url?:string,guide_video_url?:string}|null  $apps
     */
    /**
     * @param  list<string>|null  $activeLineIds
     */
    public function saveSettings(ChatbotInstance $instance, string $name, ?array $greenApiUrls, string $prompt, string $opening, ?string $liveLine = null, ?array $apps = null, ?array $activeLineIds = null): void
    {
        $this->assertMansour($instance);
        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $settings['opening_message'] = trim($opening);
        if ($instance->isAppsBot() && $apps !== null) {
            $settings['delegate_name'] = trim((string) ($apps['delegate_name'] ?? ''));
            $settings['aswatkum_url'] = trim((string) ($apps['aswatkum_url'] ?? ''));
            $settings['muwahada_url'] = trim((string) ($apps['muwahada_url'] ?? ''));
            $settings['guide_video_url'] = trim((string) ($apps['guide_video_url'] ?? ''));
        }

        $instance->forceFill([
            'name' => trim($name) !== '' ? trim($name) : $instance->name,
            'system_prompt' => trim($prompt),
            'integration_settings' => $settings,
        ])->save();

        if ($greenApiUrls !== null) {
            app(MansourLines::class)->sync($instance, $greenApiUrls, $liveLine, $activeLineIds);
        }
    }

    public function replaceContacts(ChatbotInstance $instance, UploadedFile $file): int
    {
        $this->assertMansour($instance);
        $rows = $this->parser->parse($file);
        $instance->mansourContacts()->whereNull('triggered_at')->delete();

        $added = 0;
        foreach ($rows as $row) {
            $phone = $this->chatIdDigits((string) $row['phone']);
            if ($phone === '') {
                continue;
            }
            $legacy = $this->chatIdDigits((string) ($row['legacy_phone'] ?? ''));
            if ($legacy !== '' && $legacy !== $phone && $this->repairLegacyContact($instance, $legacy, $phone, $row['name'] ?? null)) {
                continue;
            }
            $normalized = $phone;
            $exists = MansourContact::query()
                ->where('instance_id', $instance->id)
                ->where('phone_normalized', $normalized)
                ->exists();
            if ($exists) {
                continue;
            }
            MansourContact::query()->create([
                'instance_id' => $instance->id,
                'name' => HebrewName::arabic(isset($row['name']) ? (string) $row['name'] : null) ?: null,
                'phone' => $phone,
                'phone_normalized' => $normalized,
                'chat_id' => $phone.'@c.us',
            ]);
            $added++;
        }

        return $added;
    }

    public function addContact(ChatbotInstance $instance, string $phone, ?string $name): ?MansourContact
    {
        $this->assertMansour($instance);
        $digits = $this->chatIdDigits($phone);
        if ($digits === '') {
            return null;
        }

        $existing = MansourContact::query()
            ->where('instance_id', $instance->id)
            ->where('phone_normalized', $digits)
            ->first();
        $cleanName = HebrewName::arabic($name);
        if ($existing instanceof MansourContact) {
            if ($cleanName !== '' && trim((string) $existing->name) === '') {
                $existing->forceFill(['name' => $cleanName])->save();
            }

            return $existing;
        }

        return MansourContact::query()->create([
            'instance_id' => $instance->id,
            'name' => $cleanName !== '' ? $cleanName : null,
            'phone' => $digits,
            'phone_normalized' => $digits,
            'chat_id' => $digits.'@c.us',
        ]);
    }

    /**
     * Put a typed number on the Aswatkum "did not reply" list so the follow-up can be sent to it.
     *
     * @return array{ok:bool, code?:string, id?:int}
     */
    public function queueFollowUp(ChatbotInstance $instance, string $phone, ?string $name): array
    {
        $contact = $this->addContact($instance, $phone, $name);
        if ($contact === null) {
            return ['ok' => false, 'code' => 'bad_phone'];
        }

        $contact->load('conversation:id,metadata,last_customer_message_at');
        $replied = $this->customerReplyIds([$contact->conversation_id]);
        $conversationId = (int) ($contact->conversation_id ?? 0);
        $answered = $conversationId > 0 && isset($replied[$conversationId]);
        if (! $answered && $contact->conversation?->last_customer_message_at !== null) {
            $answered = true;
        }
        if ($answered) {
            return ['ok' => false, 'code' => 'followup_replied'];
        }
        if ($contact->triggered_at === null) {
            $contact->forceFill(['followup_queued_at' => now()])->save();
        }

        return ['ok' => true, 'id' => (int) $contact->id];
    }

    /**
     * @param  list<int>  $contactIds
     */
    public function deleteContacts(ChatbotInstance $instance, array $contactIds): int
    {
        $this->assertMansour($instance);
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        if ($ids === []) {
            return 0;
        }

        return $instance->mansourContacts()->whereIn('id', $ids)->delete();
    }

    /**
     * Send the opening line only to the chosen contacts. Does nothing unless this method is called.
     *
     * @param  list<int>  $contactIds
     * @param  null|callable(array<string, mixed>): void  $onEach
     * @return array{sent:int, failed:int, results?: list<array<string, mixed>>}
     */
    public function trigger(ChatbotInstance $instance, array $contactIds, ?callable $onEach = null, ?string $lineId = null, string $kind = 'opening'): array
    {
        $this->assertMansour($instance);
        if ($instance->isYouthInviteBot()) {
            $instance = $this->refreshYouthCopy($instance);
        } elseif ($instance->isWomenInviteBot()) {
            $instance = $this->refreshInviteCopy($instance);
        }
        if ($instance->isDimaBot() && ! DimaEngine::allowsOutbound()) {
            throw new RuntimeException('dima_outbound_off');
        }
        if ($kind === 'followup' && ! $instance->isAppsBot()) {
            throw new RuntimeException('missing_opening');
        }
        if ($instance->outboundHeld()) {
            return ['sent' => 0, 'failed' => 0];
        }
        $lines = app(MansourLines::class);
        $line = $this->triggerLine($instance, $lines, $lineId);
        $sendUrl = $line['url'] ?? trim((string) ($instance->greenapi_url ?? ''));
        $batchLine = $line;
        $batchUrl = $sendUrl;
        $whapiReady = app(WhapiCloudClient::class)->isReady();
        if (MansourPrompt::opening($instance) === '') {
            throw new RuntimeException('missing_opening');
        }
        if (! $whapiReady && $sendUrl === '') {
            throw new RuntimeException('missing_greenapi_or_opening');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        if ($ids === []) {
            throw new RuntimeException('none_selected');
        }

        @set_time_limit(180);
        $sent = 0;
        $failed = 0;
        $results = [];
        $contacts = MansourContact::query()
            ->where('instance_id', $instance->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->limit(150)
            ->when($kind === 'followup', fn ($query) => $query->with('conversation:id,metadata,last_customer_message_at'))
            ->get();
        $replied = $kind === 'followup' ? $this->customerReplyIds($contacts->pluck('conversation_id')->all()) : [];
        $foundIds = $contacts->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        foreach ($ids as $id) {
            if (in_array($id, $foundIds, true)) {
                continue;
            }
            $failed++;
            $row = [
                'contact_id' => $id,
                'name' => '',
                'phone' => '',
                'ok' => false,
                'code' => 'request_failed',
                'human' => __('chatbot.mansour.debug_request_failed'),
                'debug' => [
                    'provider' => 'Green API',
                    'http' => 0,
                    'message_id' => null,
                    'whatsapp' => null,
                    'line' => is_array($line) ? ($line['id'] ?? null) : null,
                    'body' => 'missing_contact',
                    'attempts' => [],
                ],
            ];
            $results[] = $row;
            if ($onEach !== null) {
                $onEach($row);
            }
        }

        foreach ($contacts as $contact) {
            $line = $batchLine;
            $sendUrl = $batchUrl;
            if ($kind === 'followup') {
                $blocked = $this->followUpBlock($contact, $replied);
                if ($blocked !== null) {
                    $failed++;
                    $row = $this->sendRow($contact, false, $blocked);
                    $results[] = $row;
                    if ($onEach !== null) {
                        $onEach($row);
                    }
                    continue;
                }
                $storedLine = trim((string) data_get($contact->conversation?->metadata, 'mansour_line', ''));
                if ($storedLine !== '' && ($line === null || ($line['id'] ?? '') !== $storedLine)) {
                    try {
                        $line = $this->triggerLine($instance, $lines, $storedLine);
                        $sendUrl = $line['url'] ?? $sendUrl;
                    } catch (RuntimeException) {
                        $failed++;
                        $row = $this->sendRow($contact, false, [
                            'code' => 'line_off',
                            'human' => __('chatbot.mansour.line_off'),
                            'debug' => [
                                'provider' => 'Green API',
                                'http' => 0,
                                'message_id' => null,
                                'whatsapp' => null,
                                'line' => $storedLine,
                                'body' => 'line_off',
                                'attempts' => [],
                            ],
                        ]);
                        $results[] = $row;
                        if ($onEach !== null) {
                            $onEach($row);
                        }
                        continue;
                    }
                }
            }
            if ($line !== null) {
                $freshLine = $lines->lineById($instance, $line['id']);
                if ($freshLine === null || ! $lines->isOn($freshLine)) {
                    $contact->forceFill(['last_error' => 'line_off'])->save();
                    $failed++;
                    $row = $this->sendRow($contact, false, [
                        'code' => 'line_off',
                        'human' => __('chatbot.mansour.line_off'),
                        'debug' => [
                            'provider' => 'Green API',
                            'http' => 0,
                            'message_id' => null,
                            'whatsapp' => null,
                            'line' => $line['id'],
                            'body' => 'line_off',
                            'attempts' => [],
                        ],
                    ]);
                    $results[] = $row;
                    if ($onEach !== null) {
                        $onEach($row);
                    }
                    continue;
                }
                $sendUrl = $freshLine['url'];
            }
            $chatId = (string) ($contact->chat_id ?: ($contact->phone_normalized.'@c.us'));
            $this->paceLine(isset($line['id']) ? (string) $line['id'] : 'default');
            $opening = match (true) {
                $kind === 'followup' => AppsPrompt::followUpGreeting($contact->name),
                $instance->isAppsBot() => AppsPrompt::greetingFor($contact->name),
                $instance->isDimaBot() => DimaEngine::openingFor($contact->name),
                $instance->isInviteBot() => InvitePrompt::greetingFor($contact->name, InvitePrompt::variantOf($instance)),
                default => MansourPrompt::openingFor($instance, $contact->name),
            };
            $attempts = [];
            $retried = false;
            $whatsapp = null;
            $delivery = 'sent';
            $result = $this->greenApi->sendMessage($sendUrl, $chatId, $opening, 0);
            $attempts[] = $this->attemptSnapshot($result, $whapiReady, $sendUrl, $lines);
            $ok = ($result['status'] ?? 0) >= 200 && ($result['status'] ?? 0) < 300;
            if ($ok && $whapiReady) {
                $whapiId = $attempts[0]['message_id'] ?? null;
                $whatsapp = $this->confirmWhapiDelivery(is_string($whapiId) ? $whapiId : '');
                if ($whatsapp === 'failed') {
                    $ok = false;
                    $delivery = 'failed';
                }
            }
            if (! $ok && $sendUrl !== '' && ! $whapiReady) {
                $lines->reportFailure($instance, $sendUrl, $result);
                $instance->refresh();
            }

            $described = $this->describeSend($ok, $delivery, $attempts, $retried, is_string($whatsapp) ? $whatsapp : null);
            $delivered = $ok && $delivery !== 'failed';
            if (! $delivered && $delivery !== 'failed') {
                $contact->forceFill(['last_error' => 'send_failed'])->save();
                $failed++;
                $row = $this->sendRow($contact, false, $described);
                $results[] = $row;
                if ($onEach !== null) {
                    $onEach($row);
                }
                continue;
            }

            $lineKey = $line['id'] ?? ($sendUrl !== '' ? $lines->idFor($sendUrl) : null);
            $extra = $kind === 'followup' ? ['apps_followup_at' => now()->toIso8601String()] : [];
            $conversation = $this->rememberOpening($instance, $contact, $chatId, $opening, null, $delivery, $lineKey, $extra);
            if ($lineKey !== null && $lineKey !== '') {
                $lines->useLine($conversation, $lineKey);
            }
            $triggeredLines = is_array($contact->triggered_lines) ? $contact->triggered_lines : [];
            if ($lineKey !== null && $lineKey !== '') {
                $triggeredLines[$lineKey] = now()->toIso8601String();
            }
            $contact->forceFill([
                'chat_id' => $chatId,
                'conversation_id' => $conversation->id,
                'triggered_at' => now(),
                'followup_queued_at' => $kind === 'followup' ? null : $contact->followup_queued_at,
                'triggered_lines' => $triggeredLines !== [] ? $triggeredLines : null,
                'last_error' => $delivery === 'failed' ? 'whatsapp_rejected' : null,
            ])->save();
            if ($delivery === 'failed') {
                $failed++;
            } else {
                $sent++;
                $this->queueInviteNudge($instance, $conversation, $contact, $kind);
            }
            $row = $this->sendRow($contact, $delivery !== 'failed', $described);
            $results[] = $row;
            if ($onEach !== null) {
                $onEach($row);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'results' => $results];
    }

    /**
     * @param  array{status?: int, body?: mixed}  $result
     * @return array{provider: string, http: int, message_id: ?string, line: ?string, body: string}
     */
    private function attemptSnapshot(array $result, bool $whapi, string $sendUrl, MansourLines $lines): array
    {
        $body = $result['body'] ?? null;
        $messageId = null;
        if (is_array($body)) {
            $id = $body['idMessage'] ?? null;
            $messageId = is_string($id) && $id !== '' ? $id : null;
        }

        return [
            'provider' => $whapi ? 'whapi' : 'greenapi',
            'http' => (int) ($result['status'] ?? 0),
            'message_id' => $messageId,
            'line' => ! $whapi && $sendUrl !== '' ? $lines->idFor($sendUrl) : null,
            'body' => $this->debugBody($body),
        ];
    }

    /**
     * @param  list<array{provider: string, http: int, message_id: ?string, line: ?string, body: string}>  $attempts
     * @return array{code: string, human: string, debug: array<string, mixed>}
     */
    private function describeSend(bool $ok, string $delivery, array $attempts, bool $retried, ?string $whatsapp): array
    {
        $last = $attempts === [] ? ['http' => 0, 'body' => '', 'provider' => 'greenapi', 'message_id' => null, 'line' => null] : $attempts[array_key_last($attempts)];
        $body = (string) ($last['body'] ?? '');
        $http = (int) ($last['http'] ?? 0);

        $code = match (true) {
            $body === 'outbound_hold' => 'held',
            str_contains($body, 'missing_whapi_token') => 'no_token',
            $ok && $delivery !== 'failed' && $retried => 'retried',
            $ok && $delivery !== 'failed' && in_array($whatsapp, ['delivered', 'read'], true) => 'delivered',
            $ok && $delivery !== 'failed' => 'accepted',
            $delivery === 'failed' => 'rejected',
            $body === 'request_failed' || $http === 0 => 'request_failed',
            default => 'http',
        };

        $human = match ($code) {
            'held' => __('chatbot.mansour.debug_held'),
            'no_token' => __('chatbot.mansour.debug_no_token'),
            'retried' => __('chatbot.mansour.debug_retried'),
            'delivered' => __('chatbot.mansour.debug_delivered'),
            'accepted' => __('chatbot.mansour.debug_accepted'),
            'rejected' => __('chatbot.mansour.debug_rejected'),
            'request_failed' => __('chatbot.mansour.debug_request_failed'),
            default => __('chatbot.mansour.debug_http', [
                'status' => $http > 0 ? (string) $http : '—',
                'reason' => $this->reasonFromBody($body),
            ]),
        };

        return [
            'code' => $code,
            'human' => $human,
            'debug' => [
                'provider' => ($last['provider'] ?? '') === 'whapi' ? 'Whapi' : 'Green API',
                'http' => $http,
                'message_id' => $last['message_id'] ?? null,
                'whatsapp' => $whatsapp,
                'line' => $last['line'] ?? null,
                'body' => $body,
                'attempts' => count($attempts) > 1 ? $attempts : [],
            ],
        ];
    }

    /**
     * @param  array{code: string, human: string, debug: array<string, mixed>}  $described
     * @return array<string, mixed>
     */
    private function sendRow(MansourContact $contact, bool $ok, array $described): array
    {
        return [
            'contact_id' => (int) $contact->id,
            'name' => (string) ($contact->name ?? ''),
            'phone' => (string) $contact->phone,
            'ok' => $ok,
            'code' => $described['code'],
            'human' => $described['human'],
            'debug' => $described['debug'],
        ];
    }

    private function debugBody(mixed $body): string
    {
        if (is_string($body)) {
            return mb_substr($body, 0, 800);
        }
        if ($body === null) {
            return '';
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return mb_substr(is_string($json) ? $json : '', 0, 800);
    }

    private function reasonFromBody(string $body): string
    {
        $trim = trim($body);
        if ($trim === '') {
            return '';
        }

        $decoded = json_decode($trim, true);
        if (is_array($decoded)) {
            foreach (['message', 'error', 'description'] as $key) {
                $value = $decoded[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return mb_substr($value, 0, 180);
                }
                if (is_array($value)) {
                    $msg = $value['message'] ?? $value['error'] ?? null;
                    if (is_string($msg) && $msg !== '') {
                        return mb_substr($msg, 0, 180);
                    }
                }
            }
        }

        $flat = preg_replace('/\s+/', ' ', $trim) ?? $trim;

        return mb_substr($flat, 0, 180);
    }

    /**
     * Cold openings are the messages WhatsApp restricts. Keep at least 12 seconds
     * between sends on the same line. Other lines keep their own clock.
     */
    private function paceLine(string $lineId): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $key = 'mansour-send-gap:'.$lineId;
        $lock = null;
        try {
            $lock = Cache::lock($key.':lock', 30);
            $lock->block(25);
        } catch (\Throwable) {
            $lock = null;
        }

        try {
            $next = Cache::get($key);
            if (is_numeric($next)) {
                $wait = (float) $next - microtime(true);
                if ($wait > 0 && $wait <= 20) {
                    usleep((int) round($wait * 1000000));
                }
            }
            Cache::put($key, microtime(true) + 12, now()->addMinutes(5));
        } finally {
            $lock?->release();
        }
    }

    private function triggerLine(ChatbotInstance $instance, MansourLines $lines, ?string $lineId): ?array
    {
        $catalog = $lines->lines($instance);
        if ($catalog === []) {
            return null;
        }
        $wanted = trim((string) $lineId);
        if ($wanted === '') {
            $wanted = (string) $lines->idOfLive($instance);
        }
        $line = $lines->lineById($instance, $wanted);
        if ($line === null || ! $lines->isOn($line)) {
            throw new RuntimeException('line_off');
        }

        return $line;
    }

    /**
     * Numbers that already got the first hello and never answered.
     *
     * @return list<array{id:int,name:string,phone:string,preview:string,line:?string,nudged:bool}>
     */
    public function followUps(ChatbotInstance $instance): array
    {
        $this->assertMansour($instance);
        $contacts = $instance->mansourContacts()
            ->with('conversation:id,metadata,last_customer_message_at')
            ->where(function ($query): void {
                $query->whereNotNull('triggered_at')->orWhereNotNull('followup_queued_at');
            })
            ->orderBy('id')
            ->get(['id', 'name', 'phone', 'triggered_at', 'followup_queued_at', 'conversation_id']);
        $replied = $this->customerReplyIds($contacts->pluck('conversation_id')->all());
        $rows = [];
        foreach ($contacts as $contact) {
            if ($this->followUpBlock($contact, $replied) !== null) {
                continue;
            }
            $conversation = $contact->conversation;
            $line = trim((string) data_get($conversation?->metadata, 'mansour_line', ''));
            $rows[] = [
                'id' => (int) $contact->id,
                'name' => (string) ($contact->name ?? ''),
                'phone' => (string) $contact->phone,
                'preview' => AppsPrompt::followUpGreeting($contact->name),
                'line' => $line !== '' ? $line : null,
                'nudged' => $conversation !== null && filled(data_get($conversation->metadata, 'apps_followup_at')),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int|string|null>  $conversationIds
     * @return array<int, true>
     */
    private function customerReplyIds(array $conversationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $conversationIds))));
        if ($ids === []) {
            return [];
        }

        return array_fill_keys(
            ChatbotMessage::query()
                ->whereIn('conversation_id', $ids)
                ->where('role', 'user')
                ->distinct()
                ->pluck('conversation_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            true,
        );
    }

    /**
     * @param  array<int, true>  $replied
     * @return array{code:string,human:string,debug:array<string,mixed>}|null
     */
    private function followUpBlock(MansourContact $contact, array $replied): ?array
    {
        $conversationId = (int) ($contact->conversation_id ?? 0);
        $answered = $conversationId > 0 && isset($replied[$conversationId]);
        if (! $answered && $contact->conversation?->last_customer_message_at !== null) {
            $answered = true;
        }
        $queued = $contact->followup_queued_at !== null && $contact->triggered_at === null;
        $reason = match (true) {
            $queued => null,
            $contact->triggered_at === null => 'followup_not_sent',
            $answered => 'followup_replied',
            default => null,
        };
        if ($reason === null) {
            return null;
        }

        return [
            'code' => $reason,
            'human' => __('chatbot.mansour.'.$reason),
            'debug' => [
                'provider' => 'Green API',
                'http' => 0,
                'message_id' => null,
                'whatsapp' => null,
                'line' => null,
                'body' => $reason,
                'attempts' => [],
            ],
        ];
    }

    /**
     * One reminder, ten minutes after the invitation, when she has not replied.
     */
    public function sendInviteNudge(int $conversationId, int $contactId): bool
    {
        $conversation = ChatbotConversation::query()->find($conversationId);
        if (! $conversation instanceof ChatbotConversation || ! $conversation->allowsAutomaticReply()) {
            return false;
        }
        $instance = $conversation->instance;
        if (! $instance instanceof ChatbotInstance || ! $instance->isInviteBot() || $instance->outboundHeld()) {
            return false;
        }
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        if (filled($meta['invite_nudge_at'] ?? null) || $conversation->last_customer_message_at !== null) {
            return false;
        }
        if (ChatbotMessage::query()->where('conversation_id', $conversation->id)->where('role', 'user')->exists()) {
            return false;
        }

        $contact = MansourContact::query()->find($contactId);
        if (! $contact instanceof MansourContact || $contact->triggered_at === null) {
            return false;
        }
        if ($contact->conversation_id !== null && (int) $contact->conversation_id !== (int) $conversation->id) {
            return false;
        }
        if ($contact->triggered_at->gt(now()->subMinutes(10))) {
            return false;
        }

        $lineId = trim((string) ($meta['mansour_line'] ?? ''));
        $lines = app(MansourLines::class);
        $line = $lineId !== '' ? $lines->lineById($instance, $lineId) : null;
        if ($line === null || ! $lines->isOn($line)) {
            return false;
        }

        $text = InvitePrompt::nudgeFor($contact->name ?: $conversation->contact_name, InvitePrompt::variantOf($instance));
        $meta['invite_nudge_at'] = now()->toIso8601String();
        $conversation->forceFill(['metadata' => $meta])->save();

        $result = $this->greenApi->sendMessage((string) $line['url'], (string) $conversation->external_chat_id, $text);
        $ok = ($result['status'] ?? 0) >= 200 && ($result['status'] ?? 0) < 300;
        if (! $ok) {
            unset($meta['invite_nudge_at']);
            $conversation->forceFill(['metadata' => $meta])->save();

            return false;
        }

        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'message' => $text,
            'delivery_status' => 'sent',
        ]);
        $conversation->recordAssistantActivity();

        return true;
    }

    public function sendDueInviteNudges(): int
    {
        $count = 0;
        $bots = ChatbotInstance::query()
            ->where('integration_type', 'mansour')
            ->get()
            ->filter(fn (ChatbotInstance $bot): bool => $bot->isInviteBot());

        foreach ($bots as $bot) {
            $contacts = MansourContact::query()
                ->where('instance_id', $bot->id)
                ->whereNotNull('conversation_id')
                ->whereNotNull('triggered_at')
                ->where('triggered_at', '<=', now()->subMinutes(10))
                ->whereHas('conversation', function ($query): void {
                    $query->whereNull('last_customer_message_at')
                        ->whereNull('metadata->invite_nudge_at');
                })
                ->orderBy('id')
                ->limit(30)
                ->get();
            foreach ($contacts as $contact) {
                if ($this->sendInviteNudge((int) $contact->conversation_id, (int) $contact->id)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function queueInviteNudge(ChatbotInstance $instance, ChatbotConversation $conversation, MansourContact $contact, string $kind): void
    {
        if ($kind === 'followup' || ! $instance->isInviteBot() || app()->runningUnitTests()) {
            return;
        }

        SendInviteNudgeJob::dispatch((int) $conversation->id, (int) $contact->id)
            ->delay(now()->addMinutes(10));
    }

    private function refreshInviteCopy(ChatbotInstance $bot): ChatbotInstance
    {
        $settings = is_array($bot->integration_settings) ? $bot->integration_settings : [];
        $opening = InvitePrompt::plainWomenCopy((string) ($settings['opening_message'] ?? ''));
        if ($opening === '') {
            $opening = InvitePrompt::template();
        }
        if (! str_contains($opening, 'يرجى الإجابة')) {
            $opening = rtrim($opening)."\n".InvitePrompt::ASK;
        }
        $name = in_array($bot->name, ['بوت الدعوة', 'الدعوه', 'الدعوة', 'دعوة'], true)
            ? self::INVITE_NAME
            : $bot->name;
        if ($opening === (string) ($settings['opening_message'] ?? '') && $name === $bot->name) {
            return $bot;
        }
        $settings['opening_message'] = $opening;
        $bot->forceFill([
            'name' => $name,
            'integration_settings' => $settings,
        ])->save();

        return $bot->fresh() ?? $bot;
    }

    private function refreshYouthCopy(ChatbotInstance $bot): ChatbotInstance
    {
        $settings = is_array($bot->integration_settings) ? $bot->integration_settings : [];
        $opening = trim((string) ($settings['opening_message'] ?? ''));
        if ($opening === '' || ! str_contains($opening, 'لأن التغيير يبدأ منكم')) {
            $opening = InvitePrompt::template(InvitePrompt::YOUTH);
        }
        $name = $bot->name === self::YOUTH_INVITE_NAME ? $bot->name : self::YOUTH_INVITE_NAME;
        if ($opening === (string) ($settings['opening_message'] ?? '') && $name === $bot->name) {
            return $bot;
        }
        $settings['role'] = 'youth';
        $settings['opening_message'] = $opening;
        $bot->forceFill([
            'name' => $name,
            'integration_settings' => $settings,
        ])->save();

        return $bot->fresh() ?? $bot;
    }

    /**
     * The poster goes out only after the greeting has been answered.
     */
    public function deliverInviteInvitation(ChatbotInstance $instance, ChatbotConversation $conversation): bool
    {
        if (! $instance->isInviteBot() || InvitePrompt::delivered($conversation)) {
            return false;
        }

        $variant = InvitePrompt::variantOf($instance);
        $poster = InvitePrompt::posterPath($variant);
        $chatId = trim((string) $conversation->external_chat_id);
        if ($poster === null || $chatId === '') {
            return false;
        }

        $lines = app(MansourLines::class);
        $sendUrl = $lines->urlFor($instance, $conversation, true);
        if ($sendUrl === '' || ! $lines->canDeliver($instance, $conversation)) {
            return false;
        }

        $caption = InvitePrompt::invitationFor(
            $conversation->contact_name,
            (string) data_get($instance->integration_settings, 'opening_message'),
            $variant,
        );
        $result = $this->greenApi->sendLocalFile($sendUrl, $chatId, $poster, InvitePrompt::posterFileName($variant), $caption);
        $ok = ($result['status'] ?? 0) >= 200 && ($result['status'] ?? 0) < 300;
        if (! $ok) {
            return false;
        }

        $attachment = InvitePrompt::posterAttachment($variant) ?? [];
        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => $attachment !== [] ? 'image' : 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => $caption,
            'delivery_status' => 'sent',
            ...$attachment,
        ]);
        InvitePrompt::markDelivered($conversation);
        $conversation->recordAssistantActivity();

        return true;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function rememberOpening(ChatbotInstance $instance, MansourContact $contact, string $chatId, string $opening, ?string $continuation = null, string $delivery = 'sent', ?string $lineId = null, array $extra = []): ChatbotConversation
    {
        $query = ChatbotConversation::query()
            ->forExternalChat($instance->id, ChatbotConversation::CHANNEL_WHATSAPP, $chatId);
        if ($lineId !== null && $lineId !== '') {
            $query->where('metadata->mansour_line', $lineId);
        }
        $conversation = $query->first();

        $metadata = $extra;
        if ($lineId !== null && $lineId !== '') {
            $metadata['mansour_line'] = $lineId;
        }
        $storedMeta = $metadata !== [] ? $metadata : null;

        if ($conversation === null) {
            $conversation = ChatbotConversation::query()->create([
                'user_id' => $instance->user_id,
                'instance_id' => $instance->id,
                'title' => $contact->name ?: $contact->phone,
                'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                'external_chat_id' => $chatId,
                'contact_phone' => $contact->phone,
                'contact_name' => $contact->name,
                'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
                'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
                'metadata' => $storedMeta,
            ]);
        } else {
            $this->forgetHistory($conversation);
            $conversation->forceFill([
                'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
                'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
                'unread_count' => 0,
                'metadata' => $storedMeta,
                'last_customer_message_at' => null,
            ])->save();
        }

        foreach (array_filter([$opening, $continuation], static fn (?string $line): bool => trim((string) $line) !== '') as $line) {
            ChatbotMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'message' => $line,
                'delivery_status' => $delivery,
            ]);
        }
        $conversation->recordAssistantActivity();

        return $conversation;
    }

    private function forgetHistory(ChatbotConversation $conversation): void
    {
        $id = (int) $conversation->id;

        ChatbotMessage::query()->where('conversation_id', $id)->delete();
        ChatbotToolExecution::query()->where('conversation_id', $id)->delete();
        ChatbotConversationInstruction::query()->where('conversation_id', $id)->delete();
        ChatbotConversationContext::query()->where('conversation_id', $id)->delete();
    }

    private function create(User $user, string $name, string $prompt, string $opening, ?string $greenApiUrl): ChatbotInstance
    {
        return ChatbotInstance::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'system_prompt' => $prompt,
            'greenapi_url' => $greenApiUrl,
            'integration_type' => 'mansour',
            'integration_settings' => ['opening_message' => $opening],
            'is_active' => true,
            'stores_members' => false,
        ]);
    }

    private function refreshAppsCopy(ChatbotInstance $bot): ChatbotInstance
    {
        $settings = is_array($bot->integration_settings) ? $bot->integration_settings : [];
        $opening = trim((string) ($settings['opening_message'] ?? ''));
        $dirty = false;
        if ($opening === '' || str_contains($opening, 'أصواتكم') || str_contains($opening, 'ثلاث خطوات') || str_contains($opening, 'لائحة') || ! str_contains($opening, 'تطبيق «الموحدة»')) {
            $settings['opening_message'] = AppsPrompt::DEFAULT_OPENING;
            $dirty = true;
        }
        if (trim((string) ($settings['muwahada_url'] ?? '')) === '') {
            $settings['muwahada_url'] = AppsPrompt::MUWAHADA_URL;
            $dirty = true;
        }
        $prompt = trim((string) $bot->system_prompt);
        $promptDirty = $prompt === '' || str_contains($prompt, 'ثلاث خطوات') || str_contains($prompt, 'أصواتكم') || str_contains($prompt, 'لائحة الناخبين') || ! str_contains($prompt, 'تنزيل تطبيق «الموحدة» فقط');
        if (! $dirty && ! $promptDirty) {
            return $bot;
        }

        $bot->forceFill([
            'integration_settings' => $settings,
            'system_prompt' => $promptDirty ? AppsPrompt::defaultCampaignPrompt() : $prompt,
        ])->save();

        return $bot->fresh() ?? $bot;
    }

    private function refreshDimaCopy(ChatbotInstance $bot): ChatbotInstance
    {
        $settings = is_array($bot->integration_settings) ? $bot->integration_settings : [];
        $opening = trim((string) ($settings['opening_message'] ?? ''));
        $prompt = trim((string) $bot->system_prompt);
        $dirty = $opening === '' || str_contains($opening, 'أصواتكم') || str_contains($opening, 'قائمة');
        $promptDirty = $prompt === '' || ! str_contains($prompt, 'مركز استطلاع بالمجتمع العربي');
        if (! $dirty && ! $promptDirty) {
            return $bot;
        }

        if ($dirty) {
            $settings['opening_message'] = DimaPrompt::DEFAULT_OPENING;
            $settings['role'] = 'dima';
        }
        $bot->forceFill([
            'integration_settings' => $settings,
            'system_prompt' => $promptDirty ? DimaPrompt::instructions() : $prompt,
        ])->save();

        return $bot->fresh() ?? $bot;
    }

    private function isMansourTwo(string $name): bool
    {
        $name = str_replace(['٢', '۲', '٢'], '2', $name);
        $name = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{00A0}\x{FEFF}]/u', '', $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if (preg_match('/3|٣|۳/u', $name) === 1) {
            return false;
        }

        return preg_match('/منصور/u', $name) === 1 && preg_match('/2/u', $name) === 1
            || preg_match('/mansour/iu', $name) === 1 && preg_match('/2/u', $name) === 1;
    }

    private function nextName(User $user, string $base): string
    {
        $base = trim(preg_replace('/\s+\d+$/u', '', $base) ?? $base);
        if ($base === '') {
            $base = 'بوت الفرز';
        }
        $names = ChatbotInstance::query()
            ->where('user_id', $user->id)
            ->where('integration_type', 'mansour')
            ->pluck('name')
            ->all();
        $n = 2;
        do {
            $candidate = $base.' '.$n;
            $n++;
        } while (in_array($candidate, $names, true));

        return $candidate;
    }

    private function confirmWhapiDelivery(string $messageId): ?string
    {
        if ($messageId === '') {
            return null;
        }

        $client = app(WhapiCloudClient::class);
        $tries = app()->runningUnitTests() ? 1 : 3;
        $status = null;
        for ($i = 0; $i < $tries; $i++) {
            if (! app()->runningUnitTests() && $i > 0) {
                usleep(800000);
            }
            $status = $client->deliveryStatus($messageId);
            if (in_array($status, ['failed', 'sent', 'delivered', 'read'], true)) {
                return $status;
            }
        }

        return $status;
    }

    private function chatIdDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && strlen($digits) >= 9) {
            $digits = '972'.substr($digits, 1);
        } elseif (preg_match('/^5\d{8}$/', $digits)) {
            $digits = '972'.$digits;
        }

        return strlen($digits) >= 8 ? $digits : '';
    }

    /**
     * A previous import kept the exponent from Excel scientific notation, so
     * 5.48978573E8 was stored as 5489785738. Point that contact at the real
     * 972 number instead of inserting a second row.
     */
    private function repairLegacyContact(ChatbotInstance $instance, string $legacy, string $phone, ?string $name): bool
    {
        $stale = MansourContact::query()
            ->where('instance_id', $instance->id)
            ->where('phone_normalized', $legacy)
            ->first();
        if (! $stale instanceof MansourContact) {
            return false;
        }

        $taken = MansourContact::query()
            ->where('instance_id', $instance->id)
            ->where('phone_normalized', $phone)
            ->where('id', '!=', $stale->id)
            ->exists();
        if ($taken) {
            return true;
        }

        $oldChat = (string) ($stale->chat_id ?: ($legacy.'@c.us'));
        $cleanName = HebrewName::arabic($name);
        $stale->forceFill([
            'name' => $cleanName !== '' ? $cleanName : $stale->name,
            'phone' => $phone,
            'phone_normalized' => $phone,
            'chat_id' => $phone.'@c.us',
            'last_error' => null,
        ])->save();

        $conversation = $stale->conversation;
        if ($conversation instanceof ChatbotConversation && (string) $conversation->external_chat_id === $oldChat) {
            $conversation->forceFill([
                'external_chat_id' => $phone.'@c.us',
                'contact_phone' => $phone,
            ])->save();
        }

        return true;
    }

    private function assertMansour(ChatbotInstance $instance): void
    {
        if (! $instance->hasMansourIntegration()) {
            throw new RuntimeException('not_mansour');
        }
    }
}
