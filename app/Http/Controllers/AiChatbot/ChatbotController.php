<?php

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatbot\SendChatbotMessageRequest;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Services\AiChatbot\AiChatbotInstanceService;
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\AiChatbotSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ChatbotController extends Controller
{
    public function __construct(
        protected AiChatbotService $chatbotService,
        protected AiChatbotSettingsService $settingsService,
        protected AiChatbotInstanceService $instanceService,
    ) {
    }

    public function index(Request $request, ChatbotInstance $instance)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);
        $this->settingsService->ensureDefaults();
        $instance->loadCount('members');

        $instances = ChatbotInstance::query()
            ->where('user_id', $user->id)
            ->latest('updated_at')
            ->get();

        $conversations = ChatbotConversation::query()
            ->where('user_id', $user->id)
            ->where('instance_id', $instance->id)
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

        $instances = ChatbotInstance::query()
            ->where('user_id', $user->id)
            ->latest('updated_at')
            ->get();

        $conversations = ChatbotConversation::query()
            ->where('user_id', $user->id)
            ->where('instance_id', $instance->id)
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
        ])->render();

        $assistantMessageHtml = view('ai-chatbot.partials.message', [
            'message' => $assistantMessage,
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
        if ($conversation->user_id !== $user->id && !($user->is_admin ?? false)) {
            abort(404);
        }

        if ($conversation->instance_id !== $instance->id) {
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
