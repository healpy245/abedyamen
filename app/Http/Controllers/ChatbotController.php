<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendChatbotMessageRequest;
use App\Http\Requests\StoreChatbotConversationRequest;
use App\Models\ChatbotConversation;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ChatbotController extends Controller
{
    public function __construct(
        private readonly ChatbotService $chatbotService
    ) {
    }

    public function index(Request $request): View
    {
        $this->chatbotService->ensureDefaultSettings();

        $conversations = ChatbotConversation::query()
            ->where('user_id', $request->user()->id)
            ->with(['messages' => fn ($query) => $query->orderBy('id')])
            ->latest()
            ->get();

        return view('chatbot.index', [
            'conversations' => $conversations,
            'activeConversation' => $conversations->first(),
        ]);
    }

    public function storeConversation(StoreChatbotConversationRequest $request): JsonResponse
    {
        $conversation = $this->chatbotService->createConversation($request->user()->id);

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
        ], 201);
    }

    public function showConversation(ChatbotConversation $conversation, Request $request): JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->load(['messages' => fn ($query) => $query->orderBy('id')]);

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
        ]);
    }

    public function sendMessage(SendChatbotMessageRequest $request): JsonResponse
    {
        $conversation = ChatbotConversation::query()->findOrFail($request->integer('conversation_id'));
        $this->authorize('view', $conversation);

        try {
            $result = $this->chatbotService->generateAssistantReply($conversation, $request->string('message')->toString());
        } catch (\Throwable $e) {
            Log::error('Chatbot message generation failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Assistant is temporarily unavailable. Please try again shortly.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => $result['assistant_message'],
            'conversation' => $result['conversation'],
        ]);
    }

    public function destroyConversation(ChatbotConversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);
        $conversation->delete();

        return response()->json([
            'success' => true,
        ]);
    }
}
