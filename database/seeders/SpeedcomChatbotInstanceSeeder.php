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
    public const INSTANCE_NAME = 'ممدوح — سبيدكوم';

    public const LEGACY_INSTANCE_NAME = 'سالي — سبيدكوم';

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

        $instance = $this->findExisting((int) $owner->id);
        $settings = is_array($instance?->integration_settings) ? $instance->integration_settings : [];
        $settings['enabled'] = true;
        $settings['company_slug'] = InternetCompanyProfile::SLUG_SPEEDCOM;
        $settings['label'] = 'Mamdouh — Speedcom Internet CRM';
        $settings['allowed_reply_phones'] = $settings['allowed_reply_phones'] ?? [];

        $payload = [
            'user_id' => $owner->id,
            'name' => self::INSTANCE_NAME,
            'system_prompt' => $compiled,
            'prompt_sections' => $sections,
            'settings_schema_version' => \App\Services\AiChatbot\PromptCompiler::SCHEMA_VERSION,
            'stores_members' => true,
            'integration_type' => InternetCompanyProfile::SLUG_SPEEDCOM,
            'integration_settings' => $settings,
        ];

        if ($instance === null) {
            $payload['greenapi_url'] = '';
            $instance = ChatbotInstance::query()->create($payload);
        } else {
            $instance->forceFill($payload)->save();
        }

        $this->removeDuplicateInstances((int) $instance->id);
        $this->grantChatbotUsers($instance);

        $this->command?->info(sprintf(
            '  Shared "%s" (id %d) owned by %s — set Green API URL in workspace settings',
            self::INSTANCE_NAME,
            $instance->id,
            self::OWNER_EMAIL
        ));
    }

    protected function findExisting(int $ownerId): ?ChatbotInstance
    {
        return ChatbotInstance::query()
            ->where('user_id', $ownerId)
            ->where(function ($query): void {
                $query->where('integration_type', InternetCompanyProfile::SLUG_SPEEDCOM)
                    ->orWhereIn('name', [self::INSTANCE_NAME, self::LEGACY_INSTANCE_NAME]);
            })
            ->get()
            ->sortByDesc(function (ChatbotInstance $instance): int {
                $score = 0;
                if ($instance->integration_type === InternetCompanyProfile::SLUG_SPEEDCOM) {
                    $score += 2;
                }
                if (trim((string) $instance->greenapi_url) !== '') {
                    $score += 4;
                }

                return $score;
            })
            ->first();
    }

    protected function removeDuplicateInstances(int $keepId): void
    {
        ChatbotInstance::query()
            ->where('id', '!=', $keepId)
            ->where(function ($query): void {
                $query->where('integration_type', InternetCompanyProfile::SLUG_SPEEDCOM)
                    ->orWhereIn('name', [self::INSTANCE_NAME, self::LEGACY_INSTANCE_NAME]);
            })
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
