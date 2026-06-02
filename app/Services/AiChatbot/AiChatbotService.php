<?php

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AiChatbotService
{
    public function __construct(
        protected AiChatbotSettingsService $settingsService,
    ) {
    }

    /**
     * @return array{conversation:ChatbotConversation,user_message:ChatbotMessage,assistant_message:ChatbotMessage}
     */
    public function sendMessage(User $user, string $message, ?int $conversationId = null): array
    {
        $conversation = $this->findOrCreateConversation($user, $conversationId);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'message' => $message,
        ]);

        if ($conversation->title === null) {
            $trimmed = trim(preg_replace('/\s+/', ' ', $message));
            $conversation->title = mb_substr($trimmed, 0, 60) ?: 'New chat';
            $conversation->save();
        }

        $settings = $this->settingsService->all();

        $history = $conversation->messages()
            ->orderBy('id')
            ->get()
            ->map(function (ChatbotMessage $msg) {
                return [
                    'role' => $msg->role,
                    'content' => (string) $msg->message,
                ];
            })
            ->all();

        $systemPrompt = (string) ($settings['system_prompt'] ?? '');
        if ($systemPrompt !== '') {
            array_unshift($history, [
                'role' => 'system',
                'content' => $systemPrompt,
            ]);
        }

        $apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        if (!$apiKey) {
            throw new RuntimeException('Missing OpenAI API key. Set services.openai.api_key or OPENAI_API_KEY.');
        }

        $model = (string) ($settings['chatbot_model'] ?? 'gpt-4o-mini');
        $temperature = (float) ($settings['temperature'] ?? 0.7);
        $maxTokens = (int) ($settings['max_tokens'] ?? 2000);

        $http = Http::timeout(30)
            ->withToken($apiKey)
            ->acceptJson();

        $verifySsl = config('services.openai.verify_ssl', true);
        if (!$verifySsl) {
            $http = $http->withoutVerifying();
        }

        try {
            $response = $http->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'messages' => $history,
            ]);
        } catch (Throwable $e) {
            $errorText = (string) $e->getMessage();

            Log::warning('AiChatbot OpenAI request failed', [
                'error' => $errorText,
            ]);

            if (str_contains($errorText, 'SSL certificate problem')) {
                throw new RuntimeException(
                    'Unable to reach the AI provider due to local SSL certificate verification (cURL error 60). For local development only, set OPENAI_VERIFY_SSL=false in .env, then run php artisan optimize:clear.'
                );
            }

            throw new RuntimeException('Unable to reach the AI provider. Please try again later.');
        }

        if (!$response->successful()) {
            $body = $response->json();
            $errorMessage = is_array($body)
                ? ($body['error']['message'] ?? $body['message'] ?? $response->body())
                : $response->body();

            $errorMessage = is_string($errorMessage) ? $errorMessage : json_encode($errorMessage);

            Log::warning('AiChatbot OpenAI error response', [
                'status' => $response->status(),
                'message' => $errorMessage,
            ]);

            throw new RuntimeException($errorMessage ?: 'The AI provider returned an error.');
        }

        $data = $response->json();

        $assistantText = '';
        if (is_array($data)) {
            $assistantText = $data['choices'][0]['message']['content'] ?? '';
        }

        $assistantText = is_string($assistantText) ? trim($assistantText) : '';

        if ($assistantText === '') {
            throw new RuntimeException('The AI provider did not return a usable response.');
        }

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'message' => $assistantText,
        ]);

        return [
            'conversation' => $conversation->fresh(),
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
        ];
    }

    protected function findOrCreateConversation(User $user, ?int $conversationId = null): ChatbotConversation
    {
        if ($conversationId === null) {
            return ChatbotConversation::create([
                'user_id' => $user->id,
                'title' => null,
            ]);
        }

        $conversation = ChatbotConversation::where('id', $conversationId)
            ->where('user_id', $user->id)
            ->first();

        if (!$conversation) {
            throw new RuntimeException('Conversation not found.');
        }

        return $conversation;
    }
}

