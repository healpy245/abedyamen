<?php

namespace Database\Seeders;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Support\InternetCompanyProfile;
use Illuminate\Database\Seeder;

class SpeedcomChatbotInstanceSeeder extends Seeder
{
    public const INSTANCE_NAME = 'سالي — سبيدكوم';

    public const OWNER_EMAIL = 'yamen@kaman.rest';

    public function run(): void
    {
        $owner = User::query()->where('email', self::OWNER_EMAIL)->first();

        if ($owner === null) {
            $this->command?->warn('Owner user missing — run WorkspaceUserSeeder first.');

            return;
        }

        $compiler = app(\App\Services\AiChatbot\PromptCompiler::class);
        $sections = $compiler->speedcomDefaultSections();
        $compiled = $compiler->compile($sections);

        $instance = ChatbotInstance::query()->updateOrCreate(
            [
                'user_id' => $owner->id,
                'name' => self::INSTANCE_NAME,
            ],
            [
                'system_prompt' => $compiled,
                'prompt_sections' => $sections,
                'settings_schema_version' => \App\Services\AiChatbot\PromptCompiler::SCHEMA_VERSION,
                'stores_members' => true,
                'integration_type' => InternetCompanyProfile::SLUG_SPEEDCOM,
                'greenapi_url' => '',
                'integration_settings' => [
                    'enabled' => true,
                    'company_slug' => InternetCompanyProfile::SLUG_SPEEDCOM,
                    'label' => 'Sally — Speedcom Internet CRM',
                    'allowed_reply_phones' => [],
                ],
            ]
        );

        $this->removeDuplicateInstances((int) $instance->id);
        $this->grantChatbotUsers($instance);

        $this->command?->info(sprintf(
            '  Shared "%s" (id %d) owned by %s — set Green API URL in workspace settings',
            self::INSTANCE_NAME,
            $instance->id,
            self::OWNER_EMAIL
        ));
    }

    protected function removeDuplicateInstances(int $keepId): void
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
}
