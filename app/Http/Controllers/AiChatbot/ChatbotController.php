<?php

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatbot\SendChatbotMessageRequest;
use App\Http\Requests\AiChatbot\UploadChatbotImageRequest;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Services\AiChatbot\AiChatbotInstanceService;
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\AiChatbotSettingsService;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Services\AiChatbot\ChatbotImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatbotController extends Controller
{
    public function __construct(
        protected AiChatbotService $chatbotService,
        protected AiChatbotSettingsService $settingsService,
        protected AiChatbotInstanceService $instanceService,
        protected ChatbotImageUploadService $imageUploadService,
        protected ChatbotAuthorizationService $authorizationService,
    ) {
    }

    public function index(Request $request, ChatbotInstance $instance)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);
        $this->settingsService->ensureDefaults();
        $instance->loadCount('members');

        $instances = $this->authorizationService->instancesForUser($user);

        $conversations = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->where(function ($q) use ($user): void {
                $q->where('user_id', $user->id)
                    ->orWhere('channel', ChatbotConversation::CHANNEL_WHATSAPP);
            })
            ->where('channel', '!=', ChatbotConversation::CHANNEL_TEST)
            ->latest('updated_at')
            ->get();

        $activeConversation = $conversations->first();
        $messages = $activeConversation
            ? $activeConversation->messages()->orderBy('id')->get()
            : collect();

        return view('ai-chatbot.index', [
            'instance' => $instance,
            'instances' => $instances,
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'messages' => $messages,
        ]);
    }

    public function showConversation(Request $request, ChatbotInstance $instance, ChatbotConversation $conversation)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);
        $this->authorizeConversationForInstance($conversation, $instance, $user);
        $this->settingsService->ensureDefaults();
        $instance->loadCount('members');

        $instances = $this->authorizationService->instancesForUser($user);

        $conversations = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->where(function ($q) use ($user): void {
                $q->where('user_id', $user->id)
                    ->orWhere('channel', ChatbotConversation::CHANNEL_WHATSAPP);
            })
            ->where('channel', '!=', ChatbotConversation::CHANNEL_TEST)
            ->latest('updated_at')
            ->get();

        $messages = $conversation->messages()->orderBy('id')->get();

        return view('ai-chatbot.index', [
            'instance' => $instance,
            'instances' => $instances,
            'conversations' => $conversations,
            'activeConversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    public function storeConversation(Request $request, ChatbotInstance $instance)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);

        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'title' => null,
        ]);

        $redirectUrl = route('ai-chatbot.instances.conversations.show', [
            'instance' => $instance,
            'conversation' => $conversation,
        ]);

        if ($request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'conversation' => [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                    'redirect_url' => $redirectUrl,
                ],
            ]);
        }

        return redirect()->to($redirectUrl);
    }

    public function send(SendChatbotMessageRequest $request, ChatbotInstance $instance)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);

        $validated = $request->validated();
        $message = $validated['message'];
        $conversationId = $validated['conversation_id'] ?? null;

        try {
            $result = $this->chatbotService->sendMessage($user, $instance, $message, $conversationId);
        } catch (RuntimeException $e) {
            $messageText = $e->getMessage();

            if (str_contains($messageText, 'Conversation not found')) {
                return $this->errorResponse($messageText, Response::HTTP_NOT_FOUND);
            }

            if (str_contains($messageText, 'Unable to reach the AI provider')) {
                return $this->errorResponse($messageText, Response::HTTP_SERVICE_UNAVAILABLE);
            }

            return $this->errorResponse($messageText, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            Log::error('AiChatbot send failed', [
                'user_id' => $user?->id,
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('An unexpected error occurred while sending your message.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $instance->touch();

        $conversation = $result['conversation'];
        $userMessage = $result['user_message'];
        $assistantMessage = $result['assistant_message'];

        $userMessageHtml = view('ai-chatbot.partials.message', [
            'message' => $userMessage,
            'instance' => $instance,
        ])->render();

        $assistantMessageHtml = view('ai-chatbot.partials.message', [
            'message' => $assistantMessage,
            'instance' => $instance,
        ])->render();

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
            ],
            'user_message_html' => $userMessageHtml,
            'assistant_message_html' => $assistantMessageHtml,
            'typing_delay_ms' => $this->settingsService->typingDelayMs((string) $assistantMessage->message),
        ]);
    }

    public function uploadImage(UploadChatbotImageRequest $request, ChatbotInstance $instance)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);

        $validated = $request->validated();
        $conversationId = isset($validated['conversation_id']) ? (int) $validated['conversation_id'] : null;

        try {
            $result = $this->imageUploadService->handle(
                $user,
                $instance,
                $request->file('image'),
                $conversationId,
                $validated['caption'] ?? null,
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            Log::error('AiChatbot image upload failed', [
                'user_id' => $user?->id,
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('An unexpected error occurred while uploading the image.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $instance->touch();

        $conversation = $result['conversation'];
        $userMessage = $result['user_message'];
        $assistantMessage = $result['assistant_message'];

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
            ],
            'user_message_html' => view('ai-chatbot.partials.message', [
                'message' => $userMessage,
                'instance' => $instance,
            ])->render(),
            'assistant_message_html' => view('ai-chatbot.partials.message', [
                'message' => $assistantMessage,
                'instance' => $instance,
            ])->render(),
            'typing_delay_ms' => $this->settingsService->typingDelayMs((string) $assistantMessage->message),
        ]);
    }

    public function attachment(Request $request, ChatbotInstance $instance, ChatbotMessage $message): StreamedResponse
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);

        $message->loadMissing('conversation');
        $conversation = $message->conversation;

        if ($conversation === null
            || (int) $conversation->instance_id !== (int) $instance->id
            || ! $this->authorizationService->canAccessInstance($user, $instance)
        ) {
            abort(404);
        }

        if (! $message->hasAttachment()) {
            abort(404);
        }

        $disk = (string) ($message->attachment_disk ?: 'local');
        if (! Storage::disk($disk)->exists((string) $message->attachment_path)) {
            abort(404);
        }

        return Storage::disk($disk)->response(
            (string) $message->attachment_path,
            null,
            [
                'Content-Type' => (string) ($message->attachment_mime ?: 'application/octet-stream'),
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }

    public function destroyConversation(Request $request, ChatbotInstance $instance, ChatbotConversation $conversation)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);
        $this->authorizeConversationForInstance($conversation, $instance, $user);

        $conversation->delete();

        if ($request->wantsJson() || $request->expectsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->route('ai-chatbot.instances.show', $instance);
    }

    protected function authorizeConversationForInstance(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        $user,
    ): void {
        if ((int) $conversation->instance_id !== (int) $instance->id) {
            abort(404);
        }

        if (! $this->authorizationService->canAccessInstance($user, $instance)) {
            abort(404);
        }

        // Web studio conversations remain owner-scoped; WhatsApp workspace chats are instance-scoped.
        if (
            $conversation->channel !== ChatbotConversation::CHANNEL_WHATSAPP
            && (int) $conversation->user_id !== (int) $user->id
            && ! ($user->is_admin ?? false)
        ) {
            abort(404);
        }
    }

    protected function errorResponse(string $message, int $status)
    {
        return response()->json([
            'message' => $message,
        ], $status);
    }
}
