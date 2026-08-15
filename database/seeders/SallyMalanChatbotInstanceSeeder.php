<?php

namespace Database\Seeders;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use Illuminate\Database\Seeder;

class SallyMalanChatbotInstanceSeeder extends Seeder
{
    public const INSTANCE_NAME = 'سالي — ملان انترنت';

    public const OWNER_EMAIL = 'yamen@kaman.rest';

    /** @var list<string> */
    private const LEGACY_INSTANCE_NAMES = [
        'Melan Internet — SpeedCom',
        'Melan Internet — Sally',
    ];

    public function run(): void
    {
        $owner = User::query()->where('email', self::OWNER_EMAIL)->first();

        if ($owner === null) {
            $this->command?->warn('Owner user missing — run WorkspaceUserSeeder first.');

            return;
        }

        $this->renameLegacyInstances($owner);

        $prompt = $this->systemPrompt();
        $sections = $this->promptSections();
        $compiler = app(\App\Services\AiChatbot\PromptCompiler::class);
        $compiled = $compiler->compile($sections, $prompt);

        $instance = ChatbotInstance::query()->updateOrCreate(
            [
                'user_id' => $owner->id,
                'name' => self::INSTANCE_NAME,
            ],
            [
                'system_prompt' => $compiled !== '' ? $compiled : $prompt,
                'prompt_sections' => $sections,
                'settings_schema_version' => \App\Services\AiChatbot\PromptCompiler::SCHEMA_VERSION,
                'stores_members' => true,
                'integration_type' => 'malan',
                'greenapi_url' => 'https://7107.api.greenapi.com/waInstance7107621968/sendMessage/e8f81a4913314e39b52c24dbd1f0ae440e90eb90e273475d97',
                'integration_settings' => [
                    'enabled' => true,
                    'label' => 'Sally — Malan Internet CRM',
                    'allowed_reply_phones' => [
                        '0533046830',
                        '0524060606',
                    ],
                ],
            ]
        );

        $this->removeDuplicateInstances($instance->id);
        $this->grantChatbotUsers($instance);

        $this->command?->info(sprintf(
            '  Shared "%s" (id %d) owned by %s',
            self::INSTANCE_NAME,
            $instance->id,
            self::OWNER_EMAIL
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function promptSections(): array
    {
        return app(\App\Services\AiChatbot\PromptCompiler::class)->sallyMalanDefaultSections();
    }

    protected function renameLegacyInstances(User $owner): void
    {
        ChatbotInstance::query()
            ->where('user_id', $owner->id)
            ->whereIn('name', self::LEGACY_INSTANCE_NAMES)
            ->update(['name' => self::INSTANCE_NAME]);
    }

    protected function removeDuplicateInstances(int $keepId): void
    {
        ChatbotInstance::query()
            ->where(function ($q): void {
                $q->where('name', self::INSTANCE_NAME)
                    ->orWhereIn('name', self::LEGACY_INSTANCE_NAMES);
            })
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
        $path = database_path('seeders/prompts/sally_malan_system_prompt.txt');

        if (! is_file($path)) {
            throw new \RuntimeException('Missing Sally Malan system prompt at '.$path);
        }

        return trim((string) file_get_contents($path));
    }
}
