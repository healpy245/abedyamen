<?php

namespace Database\Seeders;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use Illuminate\Database\Seeder;

/**
 * Grants the Malan company member access to Yamen's Sally MALAN bot only.
 *
 * Does not create owned instances for that user and removes any leftover
 * owned bots / grants so they never see Kaman WhatsApp or other instances.
 */
class MalanCompanyMemberSeeder extends Seeder
{
    public const MEMBER_EMAIL = 'malan@kaman.rest';

    public const OWNER_EMAIL = 'yamen@kaman.rest';

    public function run(): void
    {
        $member = User::query()->where('email', self::MEMBER_EMAIL)->first();
        $owner = User::query()->where('email', self::OWNER_EMAIL)->first();

        if ($member === null || $owner === null) {
            $this->command?->warn('Malan company member or owner user missing — run WorkspaceUserSeeder first.');

            return;
        }

        // Member must never own chatbot instances.
        ChatbotInstance::query()->where('user_id', $member->id)->delete();

        $malan = ChatbotInstance::query()
            ->where('user_id', $owner->id)
            ->where('name', SallyMalanChatbotInstanceSeeder::INSTANCE_NAME)
            ->where('integration_type', 'malan')
            ->first();

        if ($malan === null) {
            $this->command?->warn('Owner MALAN instance missing — run SallyMalanChatbotInstanceSeeder first.');

            return;
        }

        // Drop any other instance grants for this member.
        ChatbotInstanceUser::query()
            ->where('user_id', $member->id)
            ->where('instance_id', '!=', $malan->id)
            ->delete();

        app(ChatbotAuthorizationService::class)->grantAccess(
            $malan,
            $member,
            ChatbotInstanceUser::ROLE_MANAGER,
        );

        $this->command?->info(sprintf(
            '  %s → manager on "%s" (id %d) only',
            self::MEMBER_EMAIL,
            $malan->name,
            $malan->id
        ));
    }
}
