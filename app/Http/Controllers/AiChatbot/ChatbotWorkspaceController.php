<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatbot\StoreConversationInstructionRequest;
use App\Http\Requests\AiChatbot\StoreKamanPosDemoRequest;
use App\Http\Requests\AiChatbot\UpdateBotModeRequest;
use App\Http\Requests\AiChatbot\UpdateConversationInstructionRequest;
use App\Http\Requests\AiChatbot\UpdateWorkspaceSettingsRequest;
use App\Http\Requests\AiChatbot\WorkspaceReplyRequest;
use App\Http\Requests\AiChatbot\WorkspaceTestImageRequest;
use App\Http\Requests\AiChatbot\WorkspaceTestRequest;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotConversationInstruction;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Services\AiChatbot\AnalyticsDownloadService;
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\AppsPrompt;
use App\Services\AiChatbot\ChatbotAuditService;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\ChatbotInstructionService;
use App\Services\AiChatbot\ChatbotTestService;
use App\Services\AiChatbot\KamanBotInsightService;
use App\Services\AiChatbot\AppsInsightService;
use App\Services\AiChatbot\DimaInsightService;
use App\Services\AiChatbot\InviteInsightService;
use App\Services\AiChatbot\MansourInsightService;
use App\Services\AiChatbot\MansourLines;
use App\Services\AiChatbot\KamanPosDemoVideoService;
use App\Services\AiChatbot\PromptCompiler;
use App\Services\AiChatbot\WhapiCloudClient;
use App\Services\Malan\MalanConversationContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use RuntimeException;
use Throwable;

class ChatbotWorkspaceController extends Controller
{
    public function __construct(
        protected ChatbotAuthorizationService $authz,
        protected AiChatbotService $chatbotService,
        protected ChatbotGreenApiService $greenApiService,
        protected ChatbotInstructionService $instructionService,
        protected PromptCompiler $promptCompiler,
        protected ChatbotTestService $testService,
        protected ChatbotAuditService $auditService,
        protected MalanConversationContextService $contextService,
        protected KamanBotInsightService $kamanInsights,
        protected MansourInsightService $mansourInsights,
        protected AppsInsightService $appsInsights,
        protected DimaInsightService $dimaInsights,
        protected InviteInsightService $inviteInsights,
        protected AnalyticsDownloadService $downloads,
    ) {}

    public function index(Request $request, ChatbotInstance $instance): RedirectResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);

        return redirect()->route('ai-chatbot.workspace.conversations', $instance);
    }

    public function conversations(Request $request, ChatbotInstance $instance): View
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        $this->queueAppsGreenCatchUp($instance);

        $filter = (string) $request->query('filter', 'all');
        $search = (string) $request->query('q', '');
        $chatLine = $this->chatLine($request, $instance);
        $conversations = $this->paginateWorkspaceConversations($instance, $filter, $search, $chatLine);

        $activeId = $request->query('conversation');
        $active = null;
        if ($activeId) {
            $active = ChatbotConversation::query()
                ->where('instance_id', $instance->id)
                ->where('id', $activeId)
                ->customerFacing()
                ->first();
        }

        return view('ai-chatbot.workspace.conversations', [
            'instance' => $instance,
            'conversations' => $conversations,
            'activeConversation' => $active,
            'filter' => $filter,
            'search' => $search,
            'lines' => $chatLine['lines'],
            'line' => $chatLine['line'],
            'insight' => (string) $request->query('insight', 'all'),
            'analytics' => $this->inboxAnalytics($instance, $chatLine),
            'canReply' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_REPLY),
            'canControlBot' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT),
            'canManageInstructions' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_INSTRUCTIONS),
            'role' => $this->authz->resolveRole($user, $instance),
            'showInstanceSwitcher' => $this->authz->instancesForUser($user)->count() > 1,
            'instances' => $this->authz->instancesForUser($user),
        ]);
    }

    public function showConversation(Request $request, ChatbotInstance $instance, ChatbotConversation $conversation): View|RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        $this->assertConversation($instance, $conversation);

        $conversation->markRead();

        if ($request->boolean('pane')) {
            $context = $instance->hasMalanIntegration()
                ? $this->contextService->getActive($conversation)
                : null;

            return response()->json([
                'id' => $conversation->id,
                'html' => view('ai-chatbot.workspace.partials.conversation-pane', [
                    'instance' => $instance,
                    'conversation' => $conversation,
                    'messages' => $conversation->messages()->orderBy('id')->get(),
                    'analytics' => null,
                    'contextSummary' => $this->contextService->toPromptSummary($context, $instance),
                    'canReply' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_REPLY),
                    'canControlBot' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT),
                    'canManageInstructions' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_INSTRUCTIONS),
                ])->render(),
            ]);
        }

        $filter = (string) $request->query('filter', 'all');
        $search = (string) $request->query('q', '');
        $chatLine = $this->chatLine($request, $instance, $conversation);
        $conversations = $this->paginateWorkspaceConversations($instance, $filter, $search, $chatLine);

        $messages = $conversation->messages()->orderBy('id')->get();
        $instructions = $this->instructionService->listForConversation($conversation);
        $context = $instance->hasMalanIntegration()
            ? $this->contextService->getActive($conversation)
            : null;
        $contextSummary = $this->contextService->toPromptSummary($context, $instance);

        $data = [
            'instance' => $instance,
            'conversation' => $conversation,
            'conversations' => $conversations,
            'filter' => $filter,
            'search' => $search,
            'lines' => $chatLine['lines'],
            'line' => $chatLine['line'],
            'insight' => (string) $request->query('insight', 'all'),
            'analytics' => $this->inboxAnalytics($instance, $chatLine),
            'messages' => $messages,
            'instructions' => $instructions,
            'instructionTemplates' => $this->instructionService->templates(),
            'contextSummary' => $contextSummary,
            'canReply' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_REPLY),
            'canControlBot' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT),
            'canManageInstructions' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_INSTRUCTIONS),
            'role' => $this->authz->resolveRole($user, $instance),
            'showInstanceSwitcher' => $this->authz->instancesForUser($user)->count() > 1,
            'instances' => $this->authz->instancesForUser($user),
        ];

        if ($request->boolean('pane')) {
            return response()->json([
                'id' => $conversation->id,
                'html' => view('ai-chatbot.workspace.partials.conversation-pane', $data)->render(),
            ]);
        }

        return view('ai-chatbot.workspace.conversation', $data);
    }

    public function pollConversations(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        $this->queueAppsGreenCatchUp($instance);

        $since = $request->query('since');
        $filter = (string) $request->query('filter', 'all');
        $search = (string) $request->query('q', '');
        $chatLine = $this->chatLine($request, $instance);
        $lineHealth = $this->lineHealth($instance, $chatLine['lines']);
        $insight = strtolower(trim((string) $request->query('insight', 'all')));
        $insightService = $this->insightService($instance);
        $filtering = ($instance->hasKamanWhatsappIntegration() || $instance->hasMansourIntegration())
            && $insight !== ''
            && $insight !== 'all'
            && $insightService->isValidGroup($insight);

        $query = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->searchContact($search !== '' ? $search : null)
            ->filterWorkspace($filter === 'all' ? null : $filter)
            ->with('latestMessage')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
        $this->applyChatLine($query, $chatLine);

        if ($filtering) {
            $ids = array_values(array_map(
                'intval',
                (array) data_get($this->inboxAnalytics($instance, $chatLine), 'conversation_ids.'.$insight, []),
            ));
            if ($ids === []) {
                return response()->json([
                    'conversations' => [],
                    'insight' => $insight,
                    'lines' => $lineHealth,
                    'server_time' => now()->toIso8601String(),
                ]);
            }
            $query->whereIn('id', $ids)->limit(200);
        } else {
            $query->limit(50);
            if ($since) {
                $query->where(function ($q) use ($since): void {
                    $q->where('updated_at', '>', $since)
                        ->orWhere('last_message_at', '>', $since);
                });
            }
        }

        $waitingIds = [];
        if ($instance->isAppsBot()) {
            $waitingIds = array_flip(array_map(
                'intval',
                (array) data_get($this->inboxAnalytics($instance, $chatLine), 'conversation_ids.'.AppsPrompt::GROUP_WAITING, []),
            ));
        }
        $rows = $query->get()->map(function (ChatbotConversation $c) use ($instance, $waitingIds, $chatLine): array {
            $viewLine = ($chatLine['line']['id'] ?? null) === 'all' ? 'all' : $chatLine['lineId'];
            $payload = $this->conversationPayload($c, $viewLine);
            $waiting = isset($waitingIds[(int) $c->id]);
            $payload['waiting_account'] = $waiting;
            $payload['mark_user_url'] = $waiting
                ? route('ai-chatbot.workspace.conversations.user-created', [$instance, $c])
                : null;

            return $payload;
        });

        return response()->json([
            'conversations' => $rows,
            'insight' => $filtering ? $insight : 'all',
            'lines' => $lineHealth,
            'server_time' => now()->toIso8601String(),
            'unread_total' => $this->applyChatLine(
                ChatbotConversation::query()
                    ->where('instance_id', $instance->id)
                    ->customerFacing(),
                $chatLine,
            )->sum('unread_count'),
        ]);
    }

    public function pollMessages(Request $request, ChatbotInstance $instance, ChatbotConversation $conversation): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        $this->assertConversation($instance, $conversation);

        $afterId = (int) $request->query('after_id', 0);

        $messages = $conversation->messages()
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->get();

        if ($afterId === 0) {
            $conversation->markRead();
        } elseif ($messages->isNotEmpty()) {
            $conversation->markRead();
        }

        return response()->json([
            'messages' => $messages->map(fn (ChatbotMessage $m) => $this->messagePayload($m, $instance)),
            'conversation' => $this->conversationPayload($conversation->fresh()),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function pollAnalytics(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        if ($instance->hasMansourIntegration()) {
            $chatLine = $this->chatLine($request, $instance);

            return response()->json([
                'ok' => true,
                'analytics' => $this->withSendQuota(
                    $this->insightService($instance)->summarize(
                        $instance,
                        $chatLine['lineId'],
                        $chatLine['includeUntagged'],
                    ),
                    $instance,
                ),
                'server_time' => now()->toIso8601String(),
            ]);
        }
        $this->assertKamanBot($instance);

        return response()->json([
            'ok' => true,
            'analytics' => $this->kamanInsights->summarize($instance),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function downloadHistory(Request $request, ChatbotInstance $instance, string $group): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);

        return response()->json($this->downloads->history(
            $instance,
            $group,
            $this->downloads->lineKey($request),
            null,
            fn (): array => $this->analyticsBundle($request, $instance, $group),
        ));
    }

    public function exportAnalytics(Request $request, ChatbotInstance $instance, string $group): StreamedResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        if ((int) $request->query('download', 0) > 0 || $request->boolean('save') || $request->boolean('fresh')) {
            return $this->downloads->deliver(
                $request,
                $instance,
                $group,
                null,
                fn (): array => $this->analyticsBundle($request, $instance, $group),
            );
        }
        if ($instance->hasMansourIntegration()) {
            $chatLine = $this->chatLine($request, $instance);

            return $this->insightService($instance)->exportCsv(
                $instance,
                $group,
                $chatLine['lineId'],
                $chatLine['includeUntagged'],
            );
        }
        $this->assertKamanBot($instance);

        return $this->kamanInsights->exportCsv($instance, $group);
    }

    public function markRead(Request $request, ChatbotInstance $instance, ChatbotConversation $conversation): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        $this->assertConversation($instance, $conversation);
        $conversation->markRead();

        return response()->json(['ok' => true, 'unread_count' => 0]);
    }

    public function reply(WorkspaceReplyRequest $request, ChatbotInstance $instance, ChatbotConversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_REPLY);
        $this->assertConversation($instance, $conversation);

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
            // Staff is talking to the customer — pause auto-replies until bot is turned back on.
            if ($conversation->bot_mode === ChatbotConversation::BOT_MODE_ACTIVE) {
                $conversation->forceFill([
                    'bot_mode' => ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER,
                    'assigned_user_id' => $user->id,
                    'attention_status' => ChatbotConversation::ATTENTION_NEEDS,
                ])->save();
            }

            try {
                $send = $this->greenApiService->sendStaffReply($instance, $conversation, $message);
                $ok = ($send['status'] ?? 0) >= 200 && ($send['status'] ?? 0) < 300;
                $assistant->forceFill([
                    'delivery_status' => $ok ? 'sent' : 'failed',
                    'metadata' => array_filter([
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
                    'error' => $ok ? null : $this->whatsAppSendErrorMessage($send),
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

        $this->auditService->log($instance, 'conversation.human_reply', $user, $conversation, [
            'message_id' => $assistant->id,
            'delivery' => $delivery,
        ]);

        return response()->json([
            'ok' => $delivery['delivered'],
            'message' => $this->messagePayload($assistant->fresh(), $instance),
            'conversation' => $this->conversationPayload($conversation->fresh()),
            'delivery' => $delivery,
        ], $delivery['delivered'] ? 200 : 502);
    }

    public function markUserCreated(Request $request, ChatbotInstance $instance, ChatbotConversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_REPLY);
        $this->assertConversation($instance, $conversation);
        if (! $instance->isAppsBot()) {
            abort(404);
        }

        $turns = $conversation->messages()
            ->orderBy('id')
            ->get(['role', 'message'])
            ->map(fn (ChatbotMessage $message): array => [
                'role' => (string) $message->role,
                'message' => (string) $message->message,
            ])
            ->all();
        if (! in_array(AppsPrompt::GROUP_WAITING, AppsPrompt::result($turns)['groups'], true)) {
            return response()->json([
                'ok' => false,
                'error' => 'المحادثة مش مصنّفة إنها بتنتظر مستخدم.',
            ], 422);
        }

        $text = AppsPrompt::accountCreatedFor($conversation->contact_name);
        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'sent_by_user_id' => $user->id,
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => $text,
            'delivery_status' => $conversation->isWhatsApp() ? 'pending' : 'local',
        ]);
        $conversation->forceFill([
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
        ])->save();
        AppsPrompt::recordAccountVerified($conversation);
        $conversation->recordAssistantActivity();

        $delivery = [
            'channel' => $conversation->channel,
            'delivered' => true,
            'status' => 'local',
            'error' => null,
        ];
        if ($conversation->isWhatsApp()) {
            try {
                $send = $this->greenApiService->sendStaffReply($instance, $conversation, $text);
                $ok = ($send['status'] ?? 0) >= 200 && ($send['status'] ?? 0) < 300;
                $assistant->forceFill([
                    'delivery_status' => $ok ? 'sent' : 'failed',
                ])->save();
                $delivery = [
                    'channel' => 'whatsapp',
                    'delivered' => $ok,
                    'status' => $ok ? 'sent' : 'failed',
                    'error' => $ok ? null : $this->whatsAppSendErrorMessage($send),
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

        $this->auditService->log($instance, 'conversation.user_created', $user, $conversation, [
            'message_id' => $assistant->id,
            'delivery' => $delivery,
        ]);

        return response()->json([
            'ok' => $delivery['delivered'],
            'message' => $this->messagePayload($assistant->fresh(), $instance),
            'conversation' => $this->conversationPayload($conversation->fresh()),
            'delivery' => $delivery,
        ], $delivery['delivered'] ? 200 : 502);
    }

    /**
     * @param  array{status?:int,body?:mixed}  $send
     */
    private function whatsAppSendErrorMessage(array $send): string
    {
        $status = (int) ($send['status'] ?? 0);
        if ($status === 0) {
            return 'محادثة واتساب غير جاهزة للإرسال (رابط Green API أو chat id ناقص).';
        }

        return 'فشل إرسال واتساب (HTTP '.$status.'). الرسالة محفوظة بالمحادثة بس ما وصلت للزبون.';
    }

    public function updateBotMode(UpdateBotModeRequest $request, ChatbotInstance $instance, ChatbotConversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        $this->assertConversation($instance, $conversation);

        $mode = (string) $request->validated('bot_mode');
        $previous = $conversation->bot_mode;

        $updates = ['bot_mode' => $mode];
        if ($mode === ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER) {
            $updates['attention_status'] = ChatbotConversation::ATTENTION_NEEDS;
            $updates['assigned_user_id'] = $user->id;
        }

        $conversation->forceFill($updates)->save();

        $this->auditService->log($instance, 'conversation.bot_mode_changed', $user, $conversation, [
            'from' => $previous,
            'to' => $mode,
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $this->conversationPayload($conversation->fresh()),
        ]);
    }

    public function settings(Request $request, ChatbotInstance $instance): View
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_VIEW);

        if ($instance->hasMansourIntegration()) {
            return view('ai-chatbot.workspace.mansour.settings', [
                'instance' => $instance,
                'canManageSettings' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS),
                'canControlBot' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT),
                'showInstanceSwitcher' => $this->authz->instancesForUser($user)->count() > 1,
            ]);
        }

        $sections = $this->promptCompiler->normalize($instance->prompt_sections);
        if ($instance->hasMalanIntegration() && $this->promptCompiler->activeSectionNames($sections) === []) {
            $sections = $this->promptCompiler->sallyMalanDefaultSections();
        }
        $canManage = $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);

        return view('ai-chatbot.workspace.settings', [
            'instance' => $instance,
            'sections' => $sections,
            'compiledPreview' => (string) ($instance->system_prompt ?: $this->promptCompiler->compile($sections)),
            'canManageSettings' => $canManage,
            'canManageIntegration' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_INTEGRATION),
            'canControlBot' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT),
            'role' => $this->authz->resolveRole($user, $instance),
            'integrationStatus' => [
                'type' => $instance->integration_type,
                'greenapi_configured' => filled($instance->greenapi_url),
                'webhook_configured' => filled($instance->greenapi_webhook_token),
                'is_active' => $instance->isBotGloballyActive(),
                'whatsapp_provider' => app(WhapiCloudClient::class)->provider(),
                'whapi_ready' => app(WhapiCloudClient::class)->isReady(),
            ],
            'greenapiWebhookUrl' => $this->greenApiService->webhookUrl($instance),
            'showInstanceSwitcher' => $this->authz->instancesForUser($user)->count() > 1,
            'instances' => $this->authz->instancesForUser($user),
            'posDemo' => app(KamanPosDemoVideoService::class)->settings($instance),
            'posDemoReady' => app(KamanPosDemoVideoService::class)->isReady($instance),
            'whatsappLines' => $user->isChatbotOnly()
                ? $this->greenApiService->connectedProfiles($instance)
                : [],
        ]);
    }

    public function storePosDemo(
        StoreKamanPosDemoRequest $request,
        ChatbotInstance $instance,
        KamanPosDemoVideoService $demo,
    ): RedirectResponse {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);

        if (! $instance->hasKamanWhatsappIntegration()) {
            abort(404);
        }

        $demo->store($instance, $request->file('video'));

        $this->auditService->log($instance, 'instance.pos_demo_uploaded', $request->user(), null, [
            'original_name' => $request->file('video')?->getClientOriginalName(),
        ]);

        return redirect()
            ->route('ai-chatbot.workspace.settings', $instance)
            ->with('status', __('chatbot.workspace.pos_demo_saved'));
    }

    public function destroyPosDemo(
        Request $request,
        ChatbotInstance $instance,
        KamanPosDemoVideoService $demo,
    ): RedirectResponse {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);

        if (! $instance->hasKamanWhatsappIntegration()) {
            abort(404);
        }

        $demo->destroy($instance);

        $this->auditService->log($instance, 'instance.pos_demo_deleted', $request->user());

        return redirect()
            ->route('ai-chatbot.workspace.settings', $instance)
            ->with('status', __('chatbot.workspace.pos_demo_deleted'));
    }

    public function testPage(Request $request, ChatbotInstance $instance): View
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_RUN_TESTS);

        return view('ai-chatbot.workspace.test', [
            'instance' => $instance,
            'canManageSettings' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS),
            'canControlBot' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT),
            'canRunTests' => true,
            'showInstanceSwitcher' => $this->authz->instancesForUser($user)->count() > 1,
            'instances' => $this->authz->instancesForUser($user),
        ]);
    }

    public function updateSettings(UpdateWorkspaceSettingsRequest $request, ChatbotInstance $instance): RedirectResponse
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);

        $validated = $request->validated();
        $whapi = app(WhapiCloudClient::class);
        $whapiError = $whapi->consumeSettingsRequest($validated);
        if ($whapiError !== null) {
            return back()
                ->withInput()
                ->withErrors([str_contains($whapiError, 'webhook') ? 'whapi_webhook_url' : 'whapi_token' => __($whapiError)]);
        }

        $webhookError = $this->greenApiService->assignWebhookToken($instance, $validated['greenapi_webhook_url'] ?? null);
        if ($webhookError !== null) {
            return back()
                ->withInput()
                ->withErrors(['greenapi_webhook_url' => __($webhookError)]);
        }

        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        if (array_key_exists('ignored_reply_phones', $validated)) {
            $settings['ignored_reply_phones'] = ChatbotInstance::parsePhoneList(
                (string) $validated['ignored_reply_phones']
            );
        }

        $instance->forceFill([
            'disabled_message' => $validated['disabled_message'] ?? $instance->disabled_message,
            'name' => $validated['name'] ?? $instance->name,
            'greenapi_url' => array_key_exists('greenapi_url', $validated)
                ? (trim((string) ($validated['greenapi_url'] ?? '')) !== ''
                    ? trim((string) $validated['greenapi_url'])
                    : null)
                : $instance->greenapi_url,
            'integration_settings' => $settings,
        ]);
        $instance->save();

        // Ensure webhook token exists once Green API is wired from this page.
        if (filled($instance->greenapi_url)) {
            $this->greenApiService->ensureWebhookToken($instance);
            $this->greenApiService->ensureOutgoingPhoneWebhook($instance);
        }

        $sectionNames = [];
        if (array_key_exists('prompt_sections', $validated)) {
            $sections = $this->promptCompiler->normalize($validated['prompt_sections']);
            $this->promptCompiler->applyToInstance($instance, $sections);
            $sectionNames = $this->promptCompiler->activeSectionNames($sections);
        } elseif (array_key_exists('system_prompt', $validated)) {
            $prompt = trim((string) $validated['system_prompt']);
            if ($prompt !== '') {
                $instance->forceFill(['system_prompt' => $prompt])->save();
            }
        }

        $this->auditService->log($instance, 'instance.settings_updated', $user, null, [
            'sections' => $sectionNames,
            'ignored_reply_phones_count' => count($settings['ignored_reply_phones'] ?? []),
            'greenapi_configured' => filled($instance->greenapi_url),
        ]);

        $status = __('chatbot.workspace.settings_saved');
        if ($whapi->webhookWarning) {
            $status .= ' '.__('chatbot.whapi_webhook_failed');
        }

        return redirect()
            ->route('ai-chatbot.workspace.settings', $instance)
            ->with('status', $status);
    }

    public function updateBotActive(Request $request, ChatbotInstance $instance): RedirectResponse
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);

        $active = $request->boolean('is_active');
        $previous = $instance->isBotGloballyActive();

        $instance->forceFill([
            'is_active' => $active,
            'bot_activated_at' => $active ? now() : $instance->bot_activated_at,
        ])->save();

        if ($active) {
            $instanceId = (int) $instance->id;
            dispatch(function () use ($instanceId): void {
                try {
                    $bot = ChatbotInstance::query()->find($instanceId);
                    if ($bot === null) {
                        return;
                    }

                    $greenApi = app(ChatbotGreenApiService::class);
                    $greenApi->ensureOutgoingPhoneWebhook($bot);
                    $greenApi->drainIncomingNotifications($bot, 40);
                } catch (Throwable $e) {
                    report($e);
                }
            })->afterResponse();
        }

        $this->auditService->log($instance, 'instance.bot_active_changed', $user, null, [
            'from' => $previous,
            'to' => $active,
        ]);

        $message = $active
            ? __('chatbot.workspace.bot_activated')
            : __('chatbot.workspace.bot_deactivated');

        return redirect()
            ->back()
            ->with('status', $message);
    }

    public function clearConversations(Request $request, ChatbotInstance $instance): RedirectResponse
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS);

        $result = $instance->clearAllConversations();

        $this->auditService->log($instance, 'instance.conversations_cleared', $user, null, $result);

        return redirect()
            ->route('ai-chatbot.workspace.conversations', $instance)
            ->with('status', __('chatbot.workspace.conversations_cleared', [
                'count' => $result['conversations'],
            ]));
    }

    public function test(WorkspaceTestRequest $request, ChatbotInstance $instance): JsonResponse
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_RUN_TESTS);

        try {
            $result = $this->testService->run($user, $instance, $request->validated());
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'simulation' => true, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'simulation' => true, 'error' => 'Test failed unexpectedly.'], 500);
        }

        return response()->json($result);
    }

    public function testImage(WorkspaceTestImageRequest $request, ChatbotInstance $instance): JsonResponse
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_RUN_TESTS);

        try {
            $result = $this->testService->uploadImage(
                $user,
                $instance,
                $request->file('image'),
                $request->validated(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'simulation' => true, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'simulation' => true, 'error' => 'Image upload failed unexpectedly.'], 500);
        }

        $status = ($result['ok'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }

    public function listInstructions(Request $request, ChatbotInstance $instance, ChatbotConversation $conversation): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        $this->assertConversation($instance, $conversation);

        return response()->json([
            'instructions' => $this->instructionService->listForConversation($conversation),
            'templates' => $this->instructionService->templates(),
        ]);
    }

    public function storeInstruction(
        StoreConversationInstructionRequest $request,
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
    ): JsonResponse {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_INSTRUCTIONS);
        $this->assertConversation($instance, $conversation);

        $instruction = $this->instructionService->create($instance, $conversation, $user, $request->validated());

        return response()->json(['ok' => true, 'instruction' => $instruction], 201);
    }

    public function updateInstruction(
        UpdateConversationInstructionRequest $request,
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        ChatbotConversationInstruction $instruction,
    ): JsonResponse {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_INSTRUCTIONS);
        $this->assertConversation($instance, $conversation);
        $this->assertInstruction($conversation, $instruction);

        $updated = $this->instructionService->update($instance, $instruction, $user, $request->validated());

        return response()->json(['ok' => true, 'instruction' => $updated]);
    }

    public function toggleInstruction(
        Request $request,
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        ChatbotConversationInstruction $instruction,
    ): JsonResponse {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_INSTRUCTIONS);
        $this->assertConversation($instance, $conversation);
        $this->assertInstruction($conversation, $instruction);

        $active = (bool) $request->boolean('is_active', ! $instruction->is_active);
        $updated = $this->instructionService->setActive($instance, $instruction, $user, $active);

        return response()->json(['ok' => true, 'instruction' => $updated]);
    }

    public function destroyInstruction(
        Request $request,
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        ChatbotConversationInstruction $instruction,
    ): JsonResponse {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_INSTRUCTIONS);
        $this->assertConversation($instance, $conversation);
        $this->assertInstruction($conversation, $instruction);

        $this->instructionService->delete($instance, $instruction, $user);

        return response()->json(['ok' => true]);
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, ChatbotConversation>
     */
    /**
     * @param  array{lines: list<array<string, string>>, line: ?array<string, string>, lineId: ?string, includeUntagged: bool}  $chatLine
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, ChatbotConversation>
     */
    private function paginateWorkspaceConversations(ChatbotInstance $instance, string $filter, string $search, array $chatLine)
    {
        $query = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->searchContact($search !== '' ? $search : null)
            ->filterWorkspace($filter === 'all' ? null : $filter)
            ->with('latestMessage')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
        $this->applyChatLine($query, $chatLine);

        return $query->paginate(30)->withQueryString();
    }

    /**
     * @return array{format:string,filename:string,ascii:string,sheet:string,widths:list<int>,phone_index:int,table:list<list<string>>}
     */
    private function analyticsBundle(Request $request, ChatbotInstance $instance, string $group): array
    {
        if ($instance->hasMansourIntegration()) {
            $chatLine = $this->chatLine($request, $instance);

            return $this->insightService($instance)->exportBundle(
                $instance,
                $group,
                $chatLine['lineId'],
                $chatLine['includeUntagged'],
            );
        }
        $this->assertKamanBot($instance);

        return $this->kamanInsights->exportBundle($instance, $group);
    }

    private function insightService(ChatbotInstance $instance): MansourInsightService|AppsInsightService|DimaInsightService|InviteInsightService|KamanBotInsightService
    {
        if ($instance->isInviteBot()) {
            return $this->inviteInsights;
        }

        if ($instance->isDimaBot()) {
            return $this->dimaInsights;
        }

        if ($instance->isAppsBot()) {
            return $this->appsInsights;
        }

        return $instance->hasMansourIntegration() ? $this->mansourInsights : $this->kamanInsights;
    }

    /**
     * @param  array{lineId: ?string, includeUntagged: bool}  $chatLine
     * @return array<string, mixed>|null
     */
    private function inboxAnalytics(ChatbotInstance $instance, array $chatLine): ?array
    {
        $build = function () use ($instance, $chatLine): ?array {
            if ($instance->hasMansourIntegration()) {
                return $this->withSendQuota(
                    $this->insightService($instance)->summarize(
                        $instance,
                        $chatLine['lineId'],
                        $chatLine['includeUntagged'],
                    ),
                    $instance,
                );
            }

            return $this->kamanAnalytics($instance);
        };

        if (app()->runningUnitTests()) {
            return $build();
        }

        $key = 'inbox-analytics:'.$instance->id.':'.($chatLine['lineId'] ?? 'all').':'.(($chatLine['includeUntagged'] ?? false) ? '1' : '0');

        return Cache::remember($key, now()->addSeconds(20), $build);
    }

    private function kamanAnalytics(ChatbotInstance $instance): ?array
    {
        if (! $instance->hasKamanWhatsappIntegration()) {
            return null;
        }

        return $this->kamanInsights->summarize($instance);
    }

    public function previewGreenSync(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $this->assertMowahadaOperator($request, $instance);
        $chats = $this->greenApiService->missingIncoming($instance);
        $token = Str::random(40);
        Cache::put($this->greenSyncKey($instance, $token), $chats, now()->addMinutes(20));

        return response()->json([
            'ok' => true,
            'token' => $token,
            'chats' => array_map(static function (array $chat): array {
                unset($chat['items']);

                return $chat;
            }, $chats),
        ]);
    }

    public function applyGreenSync(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $this->assertMowahadaOperator($request, $instance);
        $token = trim((string) $request->input('token', ''));
        $stored = $token === '' ? null : Cache::get($this->greenSyncKey($instance, $token));
        if (! is_array($stored)) {
            return response()->json(['ok' => false, 'error' => 'انتهت المعاينة. اضغط مزامنة مرة ثانية.'], 422);
        }

        $choices = $request->input('choices', []);
        if (! is_array($choices)) {
            $choices = [];
        }
        $byKey = [];
        foreach ($stored as $chat) {
            if (is_array($chat) && isset($chat['key'])) {
                $byKey[(string) $chat['key']] = $chat;
            }
        }

        $continued = 0;
        $skipped = 0;
        foreach ($choices as $key => $action) {
            $action = (string) $action;
            if (! in_array($action, ['continue', 'skip'], true) || ! isset($byKey[(string) $key])) {
                continue;
            }
            $result = $this->greenApiService->applyMissingChat($instance, $byKey[(string) $key], $action);
            if ($action === 'continue') {
                $continued += (int) ($result['stored'] ?? 0);
            } else {
                $skipped += (int) ($result['stored'] ?? 0);
            }
        }

        Cache::forget($this->greenSyncKey($instance, $token));

        return response()->json([
            'ok' => true,
            'continued' => $continued,
            'skipped' => $skipped,
        ]);
    }

    private function assertMowahadaOperator(Request $request, ChatbotInstance $instance): void
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
        if (! $instance->user?->isMowahada() || ! $instance->hasMansourIntegration()) {
            abort(404);
        }
    }

    private function greenSyncKey(ChatbotInstance $instance, string $token): string
    {
        return 'green-sync:'.$instance->id.':'.$token;
    }

    /**
     * @param  array<string, mixed>|null  $analytics
     * @return array<string, mixed>|null
     */
    private function withSendQuota(?array $analytics, ChatbotInstance $instance): ?array
    {
        if (! is_array($analytics) || ! $instance->hasMansourIntegration()) {
            return $analytics;
        }

        $analytics['numbers_sent'] = $instance->mansourContacts()->whereNotNull('triggered_at')->count();
        $analytics['numbers_available'] = $instance->mansourContacts()->whereNull('triggered_at')->count();

        return $analytics;
    }

    /**
     * Pull the apps bot's Green API queue into the CRM without blocking the page.
     */
    private function queueAppsGreenCatchUp(ChatbotInstance $instance): void
    {
        if (app()->runningUnitTests() || ! $instance->isAppsBot() || $instance->outboundHeld()) {
            return;
        }

        $key = 'apps-green-drain:'.$instance->id;
        if (! Cache::add($key, 1, 90)) {
            return;
        }

        $instanceId = (int) $instance->id;
        dispatch(function () use ($instanceId, $key): void {
            try {
                $bot = ChatbotInstance::query()->find($instanceId);
                if ($bot instanceof ChatbotInstance) {
                    $green = app(ChatbotGreenApiService::class);
                    $green->drainIncomingNotifications($bot, 24);
                    if (Cache::add('apps-green-import:'.$instanceId, 1, 600)) {
                        $green->importRecentIncoming($bot, 240);
                    }
                }
            } catch (Throwable $e) {
                report($e);
            } finally {
                Cache::forget($key);
            }
        })->afterResponse();
    }

    /**
     * Triggered-chat counts and logout/restriction alerts for each WhatsApp line.
     *
     * @param  list<array<string, mixed>>|null  $choices
     * @return list<array{id: string, phone: string, label: string, active: bool, triggered: int, today: int, level: string, level_label: string, alert: ?string}>
     */
    private function lineHealth(ChatbotInstance $instance, ?array $choices = null): array
    {
        if (! $instance->hasMansourIntegration()) {
            return [];
        }

        $lines = app(MansourLines::class);
        $choices ??= $lines->choices($instance);

        return array_values(array_map(static function (array $line): array {
            return [
                'id' => (string) $line['id'],
                'phone' => (string) ($line['phone'] ?? ''),
                'label' => (string) ($line['label'] ?? ''),
                'active' => (bool) ($line['active'] ?? false),
                'triggered' => (int) ($line['triggered'] ?? 0),
                'today' => (int) ($line['today'] ?? 0),
                'cap' => (int) ($line['cap'] ?? \App\Services\AiChatbot\MansourLines::DAY_CAP),
                'percent' => (int) ($line['percent'] ?? 0),
                'level' => (string) ($line['level'] ?? 'low'),
                'level_label' => (string) ($line['level_label'] ?? ''),
                'alert' => $line['alert'] ?? null,
            ];
        }, $choices));
    }

    /**
     * The WhatsApp number open on this chats page. Empty when the bot has no lines.
     *
     * @return array{lines: list<array<string, string>>, line: ?array<string, string>, lineId: ?string, includeUntagged: bool}
     */
    private function chatLine(Request $request, ChatbotInstance $instance, ?ChatbotConversation $conversation = null): array
    {
        $empty = ['lines' => [], 'line' => null, 'lineId' => null, 'includeUntagged' => false];
        if (! $instance->hasMansourIntegration()) {
            return $empty;
        }

        $lines = app(MansourLines::class);
        $choices = $lines->choices($instance);
        if ($choices === []) {
            return $empty;
        }
        $requested = $request->query('line');
        if (! is_string($requested) || trim($requested) === '') {
            $requested = 'all';
        }
        if (trim($requested) === 'all') {
            return [
                'lines' => $choices,
                'line' => [
                    'id' => 'all',
                    'status' => 'all',
                    'name' => '',
                    'phone' => '',
                    'label' => 'كل الأرقام',
                    'avatar' => '',
                    'active' => true,
                ],
                'lineId' => null,
                'includeUntagged' => false,
            ];
        }
        $line = $lines->selected($instance, $requested);
        if ($line === null) {
            return $empty;
        }

        return [
            'lines' => $choices,
            'line' => $line,
            'lineId' => $line['id'],
            'includeUntagged' => $line['id'] === (string) $lines->idOfLive($instance),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ChatbotConversation>  $query
     * @param  array{lineId: ?string, includeUntagged: bool}  $chatLine
     * @return \Illuminate\Database\Eloquent\Builder<ChatbotConversation>
     */
    private function applyChatLine($query, array $chatLine)
    {
        $lineId = $chatLine['lineId'] ?? null;
        if (! is_string($lineId) || $lineId === '') {
            return $query;
        }

        return $query->onLine($lineId, (bool) ($chatLine['includeUntagged'] ?? false));
    }

    private function assertKamanBot(ChatbotInstance $instance): void
    {
        if (! $instance->hasKamanWhatsappIntegration()) {
            abort(404);
        }
    }

    private function assertConversation(ChatbotInstance $instance, ChatbotConversation $conversation): void
    {
        if ((int) $conversation->instance_id !== (int) $instance->id) {
            abort(404);
        }

        if ($conversation->isTest()) {
            abort(404);
        }
    }

    private function assertInstruction(ChatbotConversation $conversation, ChatbotConversationInstruction $instruction): void
    {
        if ((int) $instruction->conversation_id !== (int) $conversation->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationPayload(ChatbotConversation $conversation, ?string $lineId = null): array
    {
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
            'url' => route('ai-chatbot.workspace.conversations.show', array_filter([
                'instance' => $conversation->instance_id,
                'conversation' => $conversation->id,
                'line' => $lineId,
            ])),
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
            'is_video' => $message->isVideoAttachment(),
            'attachment_url' => $attachmentUrl,
            'sent_by_user_id' => $message->sent_by_user_id,
        ];
    }
}
