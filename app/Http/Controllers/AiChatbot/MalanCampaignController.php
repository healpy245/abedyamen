<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatbot\UpdateBotModeRequest;
use App\Http\Requests\AiChatbot\WorkspaceReplyRequest;
use App\Http\Requests\AiChatbot\WorkspaceTestRequest;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\Malan\MalanCampaign;
use App\Models\Malan\MalanCampaignContact;
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\ChatbotTestService;
use App\Services\Malan\Campaigns\CampaignInsightService;
use App\Services\Malan\Campaigns\MalanCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MalanCampaignController extends Controller
{
    public function __construct(
        protected ChatbotAuthorizationService $authz,
        protected MalanCampaignService $campaignService,
        protected ChatbotTestService $testService,
        protected CampaignInsightService $insightService,
        protected AiChatbotService $chatbotService,
        protected ChatbotGreenApiService $greenApiService,
    ) {}

    public function index(Request $request, ChatbotInstance $instance): View|RedirectResponse
    {
        $this->authorizeMalanWorkspace($request, $instance);

        $campaigns = MalanCampaign::query()
            ->where('chatbot_instance_id', $instance->id)
            ->orderByDesc('id')
            ->get();

        return view('ai-chatbot.workspace.campaigns.index', $this->workspaceViewData($request, $instance) + [
            'campaigns' => $campaigns,
            'defaultOpeningMessage' => $this->campaignService->defaultOpeningMessage($instance),
            'hideGlobalBotToggle' => true,
        ]);
    }

    public function store(Request $request, ChatbotInstance $instance): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeMalanWorkspace($request, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'greenapi_url' => ['required', 'string', 'max:2000'],
            'opening_message' => ['nullable', 'string', 'max:4000'],
            'system_prompt' => ['nullable', 'string', 'max:20000'],
            'excel' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ]);

        try {
            $campaign = $this->campaignService->create($instance, $user, [
                'name' => $validated['name'],
                'greenapi_url' => $validated['greenapi_url'],
                'opening_message' => $validated['opening_message'] ?? null,
                'system_prompt' => $validated['system_prompt'] ?? null,
                'excel' => $request->file('excel'),
            ]);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['excel' => $e->getMessage()]);
        }

        return redirect()
            ->route('ai-chatbot.workspace.campaigns.show', [$instance, $campaign])
            ->with('status', __('chatbot.workspace.campaigns.created'));
    }

    public function show(Request $request, ChatbotInstance $instance, MalanCampaign $campaign): View|RedirectResponse
    {
        $this->authorizeMalanWorkspace($request, $instance);
        $this->assertCampaign($instance, $campaign);

        $this->campaignService->refreshAnalytics($campaign);
        $campaign->refresh();

        $conversations = ChatbotConversation::query()
            ->forCampaign((int) $campaign->id)
            ->where('channel', '!=', ChatbotConversation::CHANNEL_TEST)
            ->with('latestMessage')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        $activeId = $request->query('conversation');
        $active = null;
        $messages = collect();
        if ($activeId) {
            $active = ChatbotConversation::query()
                ->where('instance_id', $instance->id)
                ->where('campaign_id', $campaign->id)
                ->where('id', $activeId)
                ->first();
            if ($active !== null) {
                $active->markRead();
                $messages = $active->messages()->orderBy('id')->get();
            }
        }

        return view('ai-chatbot.workspace.campaigns.show', $this->workspaceViewData($request, $instance) + [
            'campaign' => $campaign,
            'conversations' => $conversations,
            'activeConversation' => $active,
            'messages' => $messages,
            'webhookUrl' => $this->campaignService->webhookUrl($campaign),
            'analytics' => $this->insightService->summarize($campaign),
            'hideGlobalBotToggle' => true,
        ]);
    }

    public function pollConversations(Request $request, ChatbotInstance $instance, MalanCampaign $campaign): JsonResponse
    {
        $this->authorizeMalanWorkspace($request, $instance);
        $this->assertCampaign($instance, $campaign);

        $since = $request->query('since');
        $insight = strtolower(trim((string) $request->query('insight', 'all')));
        $filtering = $insight !== '' && $insight !== 'all' && $this->insightService->isValidGroup($insight);

        $query = ChatbotConversation::query()
            ->forCampaign((int) $campaign->id)
            ->where('channel', '!=', ChatbotConversation::CHANNEL_TEST)
            ->with('latestMessage')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(200);

        if ($filtering) {
            $ids = $this->insightService->conversationIdsForGroup($campaign, $insight);
            if ($ids === []) {
                return response()->json([
                    'conversations' => [],
                    'insight' => $insight,
                    'server_time' => now()->toIso8601String(),
                ]);
            }
            $query->whereIn('id', $ids);
        } elseif ($since) {
            $query->where(function ($q) use ($since): void {
                $q->where('updated_at', '>', $since)
                    ->orWhere('last_message_at', '>', $since);
            });
        }

        $rows = $query->get()->map(fn (ChatbotConversation $c) => $this->conversationPayload($instance, $campaign, $c));

        return response()->json([
            'conversations' => $rows,
            'insight' => $filtering ? $insight : 'all',
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function reply(
        WorkspaceReplyRequest $request,
        ChatbotInstance $instance,
        MalanCampaign $campaign,
        ChatbotConversation $conversation,
    ): JsonResponse {
        $user = $request->user();
        $this->authorizeMalanWorkspace($request, $instance, ChatbotAuthorizationService::ABILITY_REPLY);
        $this->assertCampaign($instance, $campaign);
        $this->assertCampaignConversation($campaign, $conversation);

        $message = trim((string) $request->validated('message'));
        $result = $this->chatbotService->appendHumanReply($user, $instance, $conversation, $message);
        $assistant = $result['assistant_message'];

        $delivery = [
            'channel' => $conversation->channel,
            'delivered' => true,
            'status' => 'local',
            'error' => null,
        ];

        if ($conversation->isWhatsApp()) {
            if ($conversation->bot_mode === ChatbotConversation::BOT_MODE_ACTIVE) {
                $conversation->forceFill([
                    'bot_mode' => ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER,
                    'assigned_user_id' => $user->id,
                    'attention_status' => ChatbotConversation::ATTENTION_NEEDS,
                ])->save();
            }

            $sendUrl = trim((string) ($campaign->greenapi_url ?: $instance->greenapi_url));
            $chatId = $this->campaignWhatsAppChatId($conversation, $campaign);

            try {
                if ($sendUrl === '' || $chatId === '') {
                    throw new RuntimeException($sendUrl === '' ? 'missing_greenapi_url' : 'missing_chat_id');
                }
                $send = $this->greenApiService->sendMessage($sendUrl, $chatId, $message);
                $ok = ($send['status'] ?? 0) >= 200 && ($send['status'] ?? 0) < 300;
                $assistant->forceFill([
                    'delivery_status' => $ok ? 'sent' : 'failed',
                    'metadata' => array_filter([
                        'campaign_id' => $campaign->id,
                        'campaign_staff_reply' => true,
                        'whatsapp_send_status' => $send['status'] ?? null,
                        'whatsapp_send_body' => is_array($send['body'] ?? null)
                            ? ($send['body']['idMessage'] ?? $send['body'])
                            : ($send['body'] ?? null),
                    ]),
                ])->save();

                $delivery = [
                    'channel' => 'whatsapp',
                    'delivered' => $ok,
                    'status' => $ok ? 'sent' : 'failed',
                    'error' => $ok ? null : 'WhatsApp send failed.',
                ];
            } catch (Throwable $e) {
                $assistant->forceFill(['delivery_status' => 'failed'])->save();
                $delivery = [
                    'channel' => 'whatsapp',
                    'delivered' => false,
                    'status' => 'failed',
                    'error' => 'ما قدرت أرسل الرسالة على واتساب. تأكد من إعدادات Green API.',
                ];
                report($e);
            }
        }

        return response()->json([
            'ok' => $delivery['delivered'],
            'message' => $this->messagePayload($assistant->fresh() ?? $assistant, $instance),
            'conversation' => $this->conversationPayload($instance, $campaign, $conversation->fresh() ?? $conversation),
            'delivery' => $delivery,
        ], $delivery['delivered'] ? 200 : 502);
    }

    public function pollMessages(
        Request $request,
        ChatbotInstance $instance,
        MalanCampaign $campaign,
        ChatbotConversation $conversation,
    ): JsonResponse {
        $this->authorizeMalanWorkspace($request, $instance);
        $this->assertCampaign($instance, $campaign);
        $this->assertCampaignConversation($campaign, $conversation);

        $afterId = (int) $request->query('after_id', 0);

        $messages = $conversation->messages()
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->get();

        if ($afterId === 0 || $messages->isNotEmpty()) {
            $conversation->markRead();
        }

        return response()->json([
            'messages' => $messages->map(fn (ChatbotMessage $m) => $this->messagePayload($m, $instance)),
            'conversation' => $this->conversationPayload($instance, $campaign, $conversation->fresh() ?? $conversation),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function markRead(
        Request $request,
        ChatbotInstance $instance,
        MalanCampaign $campaign,
        ChatbotConversation $conversation,
    ): JsonResponse {
        $this->authorizeMalanWorkspace($request, $instance);
        $this->assertCampaign($instance, $campaign);
        $this->assertCampaignConversation($campaign, $conversation);
        $conversation->markRead();

        return response()->json(['ok' => true, 'unread_count' => 0]);
    }

    public function updateBotMode(
        UpdateBotModeRequest $request,
        ChatbotInstance $instance,
        MalanCampaign $campaign,
        ChatbotConversation $conversation,
    ): JsonResponse {
        $user = $request->user();
        $this->authorizeMalanWorkspace($request, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        $this->assertCampaign($instance, $campaign);
        $this->assertCampaignConversation($campaign, $conversation);

        $mode = (string) $request->validated('bot_mode');
        $updates = ['bot_mode' => $mode];
        if ($mode === ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER) {
            $updates['attention_status'] = ChatbotConversation::ATTENTION_NEEDS;
            $updates['assigned_user_id'] = $user->id;
        }

        $conversation->forceFill($updates)->save();

        return response()->json([
            'ok' => true,
            'conversation' => $this->conversationPayload($instance, $campaign, $conversation->fresh() ?? $conversation),
        ]);
    }

    public function pollAnalytics(Request $request, ChatbotInstance $instance, MalanCampaign $campaign): JsonResponse
    {
        $this->authorizeMalanWorkspace($request, $instance);
        $this->assertCampaign($instance, $campaign);

        $this->campaignService->refreshAnalytics($campaign);
        $campaign->refresh();

        return response()->json([
            'ok' => true,
            'analytics' => $this->insightService->summarize($campaign),
            'campaign_bot_active' => $campaign->isBotActive(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function exportAnalytics(
        Request $request,
        ChatbotInstance $instance,
        MalanCampaign $campaign,
        string $group,
    ): StreamedResponse {
        $this->authorizeMalanWorkspace($request, $instance);
        $this->assertCampaign($instance, $campaign);

        return $this->insightService->exportExcel($campaign, $group);
    }

    public function settings(Request $request, ChatbotInstance $instance, MalanCampaign $campaign): View
    {
        $this->authorizeMalanWorkspace($request, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);
        $this->assertCampaign($instance, $campaign);

        return view('ai-chatbot.workspace.campaigns.settings', $this->workspaceViewData($request, $instance) + [
            'campaign' => $campaign,
            'webhookUrl' => $this->campaignService->webhookUrl($campaign),
            'canEdit' => ! $campaign->isRunning(),
            'hideGlobalBotToggle' => true,
        ]);
    }

    public function updateSettings(Request $request, ChatbotInstance $instance, MalanCampaign $campaign): RedirectResponse
    {
        $this->authorizeMalanWorkspace($request, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);
        $this->assertCampaign($instance, $campaign);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'greenapi_url' => ['required', 'string', 'max:2000'],
            'opening_message' => ['required', 'string', 'max:4000'],
            'system_prompt' => ['required', 'string', 'max:20000'],
            'excel' => ['nullable', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ]);

        try {
            $this->campaignService->updateSettings($campaign, [
                'name' => $validated['name'],
                'greenapi_url' => $validated['greenapi_url'],
                'opening_message' => $validated['opening_message'],
                'system_prompt' => $validated['system_prompt'],
                'excel' => $request->file('excel'),
            ]);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['excel' => $e->getMessage()]);
        }

        return redirect()
            ->route('ai-chatbot.workspace.campaigns.settings', [$instance, $campaign])
            ->with('status', __('chatbot.workspace.campaigns.settings_saved'));
    }

    public function updateBotActive(Request $request, ChatbotInstance $instance, MalanCampaign $campaign): RedirectResponse
    {
        $this->authorizeMalanWorkspace($request, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        $this->assertCampaign($instance, $campaign);

        $active = $request->boolean('is_active');
        $this->campaignService->setBotActive($campaign, $active);

        return back()->with('status', $active
            ? __('chatbot.workspace.campaigns.bot_activated')
            : __('chatbot.workspace.campaigns.bot_deactivated'));
    }

    public function testPage(Request $request, ChatbotInstance $instance, MalanCampaign $campaign): View
    {
        $this->authorizeMalanWorkspace($request, $instance, ChatbotAuthorizationService::ABILITY_RUN_TESTS);
        $this->assertCampaign($instance, $campaign);

        return view('ai-chatbot.workspace.campaigns.test', $this->workspaceViewData($request, $instance) + [
            'campaign' => $campaign,
            'canRunTests' => true,
            'hideGlobalBotToggle' => true,
        ]);
    }

    public function test(WorkspaceTestRequest $request, ChatbotInstance $instance, MalanCampaign $campaign): JsonResponse
    {
        $this->authorizeMalanWorkspace($request, $instance, ChatbotAuthorizationService::ABILITY_RUN_TESTS);
        $this->assertCampaign($instance, $campaign);

        try {
            $result = $this->testService->run($request->user(), $instance, $request->validated(), $campaign);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'simulation' => true, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'simulation' => true, 'error' => 'Test failed unexpectedly.'], 500);
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function start(Request $request, ChatbotInstance $instance, MalanCampaign $campaign): RedirectResponse
    {
        $this->authorizeMalanWorkspace($request, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        $this->assertCampaign($instance, $campaign);

        $validated = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer', 'distinct'],
        ]);

        try {
            $result = $this->campaignService->start($campaign, $validated['contact_ids']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['campaign' => $e->getMessage()]);
        }

        $sent = (int) ($result['sent'] ?? 0);
        $resent = (bool) ($result['resent'] ?? false);

        if ($sent === 0) {
            return back()->withErrors([
                'campaign' => __('chatbot.workspace.campaigns.nothing_to_send'),
            ]);
        }

        return back()->with('status', $resent
            ? __('chatbot.workspace.campaigns.resent', ['count' => $sent])
            : __('chatbot.workspace.campaigns.started_count', ['count' => $sent]));
    }

    public function contactsJson(Request $request, ChatbotInstance $instance, MalanCampaign $campaign): JsonResponse
    {
        $this->authorizeMalanWorkspace($request, $instance);
        $this->assertCampaign($instance, $campaign);

        $contacts = $campaign->contacts()
            ->orderBy('id')
            ->get(['id', 'name', 'phone', 'phone_normalized', 'city', 'status', 'message_sent_at']);

        return response()->json([
            'contacts' => $contacts->map(static fn (MalanCampaignContact $c) => [
                'id' => (int) $c->id,
                'name' => trim((string) ($c->name ?? '')),
                'phone' => (string) ($c->phone_normalized ?: $c->phone),
                'city' => trim((string) ($c->city ?? '')),
                'status' => (string) $c->status,
                'sent' => $c->message_sent_at !== null,
            ])->values(),
        ]);
    }

    public function storeContact(Request $request, ChatbotInstance $instance, MalanCampaign $campaign): JsonResponse
    {
        $this->authorizeMalanWorkspace($request, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        $this->assertCampaign($instance, $campaign);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $result = $this->campaignService->addCustomContact(
                $campaign,
                (string) $validated['phone'],
                isset($validated['name']) ? (string) $validated['name'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'message' => __('chatbot.workspace.campaigns.trigger_custom_invalid'),
            ], 422);
        }

        /** @var MalanCampaignContact $contact */
        $contact = $result['contact'];

        return response()->json([
            'ok' => true,
            'created' => (bool) $result['created'],
            'contact' => [
                'id' => (int) $contact->id,
                'name' => trim((string) ($contact->name ?? '')),
                'phone' => (string) ($contact->phone_normalized ?: $contact->phone),
                'city' => trim((string) ($contact->city ?? '')),
                'status' => (string) $contact->status,
                'sent' => $contact->message_sent_at !== null,
            ],
        ], $result['created'] ? 201 : 200);
    }

    public function stop(Request $request, ChatbotInstance $instance, MalanCampaign $campaign): RedirectResponse
    {
        $this->authorizeMalanWorkspace($request, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        $this->assertCampaign($instance, $campaign);

        $this->campaignService->stop($campaign);

        return back()->with('status', __('chatbot.workspace.campaigns.stopped'));
    }

    private function authorizeMalanWorkspace(
        Request $request,
        ChatbotInstance $instance,
        string $ability = ChatbotAuthorizationService::ABILITY_VIEW,
    ): void {
        $this->authz->authorize($request->user(), $instance, $ability);

        if (! $instance->hasMalanIntegration()) {
            abort(404);
        }
    }

    private function assertCampaign(ChatbotInstance $instance, MalanCampaign $campaign): void
    {
        if ((int) $campaign->chatbot_instance_id !== (int) $instance->id) {
            abort(404);
        }
    }

    private function assertCampaignConversation(MalanCampaign $campaign, ChatbotConversation $conversation): void
    {
        if ((int) $conversation->campaign_id !== (int) $campaign->id) {
            abort(404);
        }
    }

    private function campaignWhatsAppChatId(ChatbotConversation $conversation, MalanCampaign $campaign): string
    {
        $external = trim((string) ($conversation->external_chat_id ?? ''));
        $prefix = 'campaign:'.$campaign->id.':';
        if ($external !== '' && str_starts_with($external, $prefix)) {
            return substr($external, strlen($prefix));
        }
        if ($external !== '' && str_contains($external, '@')) {
            return $external;
        }

        $phone = is_string($conversation->contact_phone)
            ? preg_replace('/\D+/', '', $conversation->contact_phone)
            : '';
        if (! is_string($phone) || $phone === '') {
            return '';
        }
        if (str_starts_with($phone, '0') && strlen($phone) === 10) {
            $phone = '972'.substr($phone, 1);
        }

        return $phone.'@c.us';
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationPayload(
        ChatbotInstance $instance,
        MalanCampaign $campaign,
        ChatbotConversation $conversation,
    ): array {
        $latest = $conversation->relationLoaded('latestMessage')
            ? $conversation->latestMessage
            : $conversation->messages()->latest('id')->first();

        return [
            'id' => $conversation->id,
            'display_name' => $conversation->displayName(),
            'initials' => $conversation->initials(),
            'contact_phone' => $conversation->contact_phone,
            'contact_name' => $conversation->contact_name,
            'channel' => $conversation->channel,
            'bot_mode' => $conversation->bot_mode,
            'attention_status' => $conversation->attention_status,
            'unread_count' => (int) $conversation->unread_count,
            'last_message_at' => optional($conversation->last_message_at)?->toIso8601String(),
            'updated_at' => optional($conversation->updated_at)?->toIso8601String(),
            'preview' => $latest ? \Illuminate\Support\Str::limit((string) $latest->message, 80) : '',
            'url' => route('ai-chatbot.workspace.campaigns.show', [
                'instance' => $instance->id,
                'campaign' => $campaign->id,
                'conversation' => $conversation->id,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(ChatbotMessage $message, ChatbotInstance $instance): array
    {
        $attachmentUrl = null;
        if ($message->hasAttachment()) {
            $attachmentUrl = route('ai-chatbot.instances.messages.attachment', [
                'instance' => $instance->id,
                'message' => $message->id,
            ]);
        }

        return [
            'id' => $message->id,
            'role' => $message->role,
            'message' => $message->message,
            'reply_source' => $message->reply_source,
            'source_label' => $message->staffSourceLabel(),
            'message_type' => $message->message_type,
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'has_attachment' => $message->hasAttachment(),
            'is_image' => $message->isImageAttachment(),
            'is_pdf' => $message->isPdfAttachment(),
            'is_audio' => $message->isAudioAttachment(),
            'attachment_url' => $attachmentUrl,
            'sent_by_user_id' => $message->sent_by_user_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceViewData(Request $request, ChatbotInstance $instance): array
    {
        $user = $request->user();

        return [
            'instance' => $instance,
            'canReply' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_REPLY),
            'canControlBot' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT),
            'canManageSettings' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS),
            'role' => $this->authz->resolveRole($user, $instance),
            'showInstanceSwitcher' => $this->authz->instancesForUser($user)->count() > 1,
            'instances' => $this->authz->instancesForUser($user),
        ];
    }
}
