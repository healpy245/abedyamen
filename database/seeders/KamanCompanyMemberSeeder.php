<?php

namespace Database\Seeders;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use Illuminate\Database\Seeder;

/**
 * Grants the Kaman company member access to Yamen's Kaman POS WhatsApp bot only.
 *
 * Does not create owned instances for that user and removes any leftover
 * owned bots / grants so they never see Sally Malan or other instances.
 */
class KamanCompanyMemberSeeder extends Seeder
{
    public const MEMBER_EMAIL = 'kaman@kaman.rest';

    public const OWNER_EMAIL = 'yamen@kaman.rest';

    public function run(): void
    {
        $member = User::query()->where('email', self::MEMBER_EMAIL)->first();
        $owner = User::query()->where('email', self::OWNER_EMAIL)->first();

        if ($member === null || $owner === null) {
            $this->command?->warn('Kaman company member or owner user missing — run WorkspaceUserSeeder first.');

            return;
        }

        // Member must never own chatbot instances.
        ChatbotInstance::query()->where('user_id', $member->id)->delete();

        $kaman = ChatbotInstance::query()
            ->where('user_id', $owner->id)
            ->where('name', KamanWhatsappChatbotInstanceSeeder::INSTANCE_NAME)
            ->where('integration_type', 'kaman_whatsapp')
            ->first();

        if ($kaman === null) {
            $this->command?->warn('Owner Kaman WhatsApp instance missing — run KamanWhatsappChatbotInstanceSeeder first.');

            return;
        }

        // Drop any other instance grants for this member.
        ChatbotInstanceUser::query()
            ->where('user_id', $member->id)
            ->where('instance_id', '!=', $kaman->id)
            ->delete();

        app(ChatbotAuthorizationService::class)->grantAccess(
            $kaman,
            $member,
            ChatbotInstanceUser::ROLE_MANAGER,
        );

        $this->command?->info(sprintf(
            '  %s → manager on "%s" (id %d) only',
            self::MEMBER_EMAIL,
            $kaman->name,
            $kaman->id
        ));
    }
}
