<?php

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;

class AiChatbotInstanceService
{
    public function __construct(
        protected AiChatbotSettingsService $settingsService,
    ) {
    }

    public function defaultPrompt(): string
    {
        return (string) ($this->settingsService->defaults()['system_prompt'] ?? 'You are a helpful AI assistant.');
    }

    public function ensureDefaultForUser(User $user): ChatbotInstance
    {
        $existing = ChatbotInstance::query()
            ->where('user_id', $user->id)
            ->oldest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->createForUser($user, 'General Assistant', $this->defaultPrompt());
    }

    public function createForUser(User $user, string $name, ?string $systemPrompt = null): ChatbotInstance
    {
        return ChatbotInstance::query()->create([
            'user_id' => $user->id,
            'name' => trim($name) !== '' ? trim($name) : 'New Instance',
            'system_prompt' => $systemPrompt ?? $this->defaultPrompt(),
        ]);
    }

    public function authorizeForUser(ChatbotInstance $instance, User $user): void
    {
        if ($instance->user_id !== $user->id && !($user->is_admin ?? false)) {
            abort(404);
        }
    }

    /**
     * @return array{
     *     instance: ChatbotInstance,
     *     instances: \Illuminate\Support\Collection<int, ChatbotInstance>,
     *     conversations: \Illuminate\Support\Collection<int, ChatbotConversation>,
     *     activeConversation: ChatbotConversation|null
     * }
     */
    public function layoutData(User $user, ChatbotInstance $instance, ?ChatbotConversation $activeConversation = null): array
    {
        $this->authorizeForUser($instance, $user);

        $instance->loadCount('members');

        return [
            'instance' => $instance,
            'instances' => ChatbotInstance::query()
                ->where('user_id', $user->id)
                ->latest('updated_at')
                ->get(),
            'conversations' => ChatbotConversation::query()
                ->where('user_id', $user->id)
                ->where('instance_id', $instance->id)
                ->latest('updated_at')
                ->get(),
            'activeConversation' => $activeConversation,
        ];
    }
}
