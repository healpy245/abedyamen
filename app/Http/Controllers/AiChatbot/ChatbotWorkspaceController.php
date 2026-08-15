<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatbot\StoreConversationInstructionRequest;
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
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\ChatbotAuditService;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\ChatbotInstructionService;
use App\Services\AiChatbot\ChatbotTestService;
use App\Services\AiChatbot\PromptCompiler;
use App\Services\Malan\MalanConversationContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
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

        $filter = (string) $request->query('filter', 'all');
        $search = (string) $request->query('q', '');
        $conversations = $this->paginateWorkspaceConversations($instance, $filter, $search);

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
            'canReply' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_REPLY),
            'canControlBot' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT),
            'canManageInstructions' => $this->authz->can($user, $instance, ChatbotAuthorizationService::ABILITY_MANAGE_INSTRUCTIONS),
            'role' => $this->authz->resolveRole($user, $instance),
            'showInstanceSwitcher' => $this->authz->instancesForUser($user)->count() > 1,
            'instances' => $this->authz->instancesForUser($user),
        ]);
    }

    public function showConversation(Request $request, ChatbotInstance $instance, ChatbotConversation $conversation): View|RedirectResponse
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_VIEW);
        $this->assertConversation($instance, $conversation);

        $conversation->markRead();

        $filter = (string) $request->query('filter', 'all');
        $search = (string) $request->query('q', '');
        $conversations = $this->paginateWorkspaceConversations($instance, $filter, $search);

        $messages = $conversation->messages()->orderBy('id')->get();
        $instructions = $this->instructionService->listForConversation($conversation);
        $context = $instance->hasMalanIntegration()
            ? $this->contextService->getActive($conversation)
            : null;
        $contextSummary = $this->contextService->toPromptSummary($context);

        return view('ai-chatbot.workspace.conversation', [
            'instance' => $instance,
            'conversation' => $conversation,
            'conversations' => $conversations,
            'filter' => $filter,
            'search' => $search,
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
        ]);
    }

    public function pollConversations(Request $request, ChatbotInstance $instance): JsonResponse
    {
        $this->authz->authorize($request->user(), $instance, ChatbotAuthorizationService::ABILITY_VIEW);

        $since = $request->query('since');
        $filter = (string) $request->query('filter', 'all');
        $search = (string) $request->query('q', '');

        $query = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->searchContact($search !== '' ? $search : null)
            ->filterWorkspace($filter === 'all' ? null : $filter)
            ->with('latestMessage')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(50);

        if ($since) {
            $query->where(function ($q) use ($since): void {
                $q->where('updated_at', '>', $since)
                    ->orWhere('last_message_at', '>', $since);
            });
        }

        $rows = $query->get()->map(fn (ChatbotConversation $c) => $this->conversationPayload($c));

        return response()->json([
            'conversations' => $rows,
            'server_time' => now()->toIso8601String(),
            'unread_total' => ChatbotConversation::query()
                ->where('instance_id', $instance->id)
                ->customerFacing()
                ->sum('unread_count'),
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
            ],
            'showInstanceSwitcher' => $this->authz->instancesForUser($user)->count() > 1,
            'instances' => $this->authz->instancesForUser($user),
        ]);
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
        $sections = $this->promptCompiler->normalize($validated['prompt_sections'] ?? []);

        $instance->forceFill([
            'disabled_message' => $validated['disabled_message'] ?? $instance->disabled_message,
            'name' => $validated['name'] ?? $instance->name,
        ]);
        $instance->save();

        $this->promptCompiler->applyToInstance($instance, $sections);

        $this->auditService->log($instance, 'instance.settings_updated', $user, null, [
            'sections' => $this->promptCompiler->activeSectionNames($sections),
        ]);

        return redirect()
            ->route('ai-chatbot.workspace.settings', $instance)
            ->with('status', __('chatbot.workspace.settings_saved'));
    }

    public function updateBotActive(Request $request, ChatbotInstance $instance): RedirectResponse
    {
        $user = $request->user();
        $this->authz->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_CONTROL_BOT);

        $active = $request->boolean('is_active');
        $previous = $instance->isBotGloballyActive();

        $instance->forceFill(['is_active' => $active])->save();

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
    private function paginateWorkspaceConversations(ChatbotInstance $instance, string $filter, string $search)
    {
        return ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->searchContact($search !== '' ? $search : null)
            ->filterWorkspace($filter === 'all' ? null : $filter)
            ->with('latestMessage')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();
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
    private function conversationPayload(ChatbotConversation $conversation): array
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
            'url' => route('ai-chatbot.workspace.conversations.show', [
                'instance' => $conversation->instance_id,
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
}
