<?php

namespace Database\Seeders;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use Illuminate\Database\Seeder;

class KamanWhatsappChatbotInstanceSeeder extends Seeder
{
    public const INSTANCE_NAME = 'Kaman POS — WhatsApp';

    public const OWNER_EMAIL = 'yamen@kaman.rest';

    public function run(): void
    {
        $owner = User::query()->where('email', self::OWNER_EMAIL)->first();

        if ($owner === null) {
            $this->command?->warn('Owner user missing — run WorkspaceUserSeeder first.');

            return;
        }

        $prompt = $this->systemPrompt();

        $instance = ChatbotInstance::query()->updateOrCreate(
            [
                'user_id' => $owner->id,
                'name' => self::INSTANCE_NAME,
            ],
            [
                'system_prompt' => $prompt,
                'stores_members' => false,
                'integration_type' => 'kaman_whatsapp',
                'integration_settings' => [
                    'enabled' => true,
                    'label' => 'Kaman POS WhatsApp sales bot',
                    'channel' => 'whatsapp',
                ],
            ]
        );

        $this->removeDuplicateInstances($owner->id, $instance->id);
        $this->grantChatbotUsers($instance);

        $this->command?->info(sprintf(
            '  Shared "%s" (id %d) owned by %s',
            self::INSTANCE_NAME,
            $instance->id,
            self::OWNER_EMAIL
        ));
    }

    protected function removeDuplicateInstances(int $ownerId, int $keepId): void
    {
        ChatbotInstance::query()
            ->where('name', self::INSTANCE_NAME)
            ->where('id', '!=', $keepId)
            ->each(function (ChatbotInstance $duplicate): void {
                ChatbotInstanceUser::query()->where('instance_id', $duplicate->id)->delete();
                $duplicate->delete();
            });
    }

    protected function grantChatbotUsers(ChatbotInstance $instance): void
    {
        $authz = app(ChatbotAuthorizationService::class);

        User::query()
            ->where('email', '!=', self::OWNER_EMAIL)
            ->whereNotIn('email', WorkspaceUserSeeder::CHATBOT_MEMBER_ONLY_EMAILS)
            ->get()
            ->each(function (User $user) use ($authz, $instance): void {
                if (! $user->canAccessProject(\App\Enums\Project::AiChatbot)) {
                    return;
                }

                $authz->grantAccess($instance, $user, ChatbotInstanceUser::ROLE_MANAGER);
            });
    }

    protected function systemPrompt(): string
    {
        $path = database_path('seeders/prompts/kaman_whatsapp_system_prompt.txt');

        if (! is_file($path)) {
            throw new \RuntimeException('Missing Kaman WhatsApp system prompt at '.$path);
        }

        return trim((string) file_get_contents($path));
    }
}
