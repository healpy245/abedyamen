<?php

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;

class AiChatbotInstanceService
{
    public function __construct(
        protected AiChatbotSettingsService $settingsService,
        protected ChatbotAuthorizationService $authorizationService,
    ) {}

    public function defaultPrompt(): string
    {
        return (string) ($this->settingsService->defaults()['system_prompt'] ?? 'You are a helpful AI assistant.');
    }

    /**
     * Seeded bots only — never auto-create a blank instance from the UI.
     */
    public function firstForUser(User $user): ?ChatbotInstance
    {
        return $this->authorizationService->firstAccessibleForUser($user);
    }

    /**
     * @deprecated Use firstForUser(); kept for older call sites during transition.
     */
    public function ensureDefaultForUser(User $user): ChatbotInstance
    {
        $existing = $this->firstForUser($user);

        if ($existing) {
            return $existing;
        }

        throw new \RuntimeException(
            'No chatbot instances are configured for this account. Run the Sally Malan and Kaman WhatsApp seeders.'
        );
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
        $this->authorizationService->authorize($user, $instance, ChatbotAuthorizationService::ABILITY_VIEW);
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

        $instances = $this->authorizationService->instancesForUser($user);

        return [
            'instance' => $instance,
            'instances' => $instances,
            'conversations' => ChatbotConversation::query()
                ->where('instance_id', $instance->id)
                ->where(function ($q) use ($user, $instance): void {
                    // Owner/admin: all non-test; members: all customer-facing for the instance
                    if (($user->is_admin ?? false) || (int) $instance->user_id === (int) $user->id) {
                        $q->customerFacing();
                    } else {
                        $q->customerFacing();
                    }
                })
                ->latest('updated_at')
                ->get(),
            'activeConversation' => $activeConversation,
        ];
    }
}
