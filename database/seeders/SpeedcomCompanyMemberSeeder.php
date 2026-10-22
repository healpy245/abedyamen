<?php

namespace Database\Seeders;

use App\Enums\Project;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Support\InternetCompanyProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Grants Speedcom logins access to Yamen's Mamdouh bot only.
 *
 * Does not create owned instances for those users and removes any leftover
 * owned bots / grants so they never see Kaman, Malan, or other instances.
 */
class SpeedcomCompanyMemberSeeder extends Seeder
{
    public const MEMBER_EMAIL = 'speedcom@kaman.rest';

    public const MEMBER_PASSWORD = 'Speedcom123@';

    public const IBRAHIM_EMAIL = 'ibrahim@kaman-workspace.com';

    public const IBRAHIM_PASSWORD = 'Ibrahim123@';

    public const OWNER_EMAIL = 'yamen@kaman.rest';

    public function run(): void
    {
        $owner = User::query()->where('email', self::OWNER_EMAIL)->first();
        $speedcom = null;

        if ($owner !== null) {
            $speedcom = ChatbotInstance::query()
                ->where('user_id', $owner->id)
                ->where('name', SpeedcomChatbotInstanceSeeder::INSTANCE_NAME)
                ->where('integration_type', InternetCompanyProfile::SLUG_SPEEDCOM)
                ->first();
        }

        if ($owner === null || $speedcom === null) {
            $this->command?->warn('Speedcom company member or owner user missing — run WorkspaceUserSeeder and SpeedcomChatbotInstanceSeeder first.');
        }

        $this->grantMember(self::MEMBER_EMAIL, 'Speedcom', self::MEMBER_PASSWORD, $speedcom);
        $this->grantMember(self::IBRAHIM_EMAIL, 'Ibrahim', self::IBRAHIM_PASSWORD, $speedcom);
    }

    private function grantMember(string $email, string $name, string $password, ?ChatbotInstance $speedcom): void
    {
        $member = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'is_admin' => false,
                'projects' => [Project::AiChatbot->value],
            ],
        );

        ChatbotInstance::query()->where('user_id', $member->id)->delete();

        if ($speedcom === null) {
            return;
        }

        ChatbotInstanceUser::query()
            ->where('user_id', $member->id)
            ->where('instance_id', '!=', $speedcom->id)
            ->delete();

        app(ChatbotAuthorizationService::class)->grantAccess(
            $speedcom,
            $member,
            ChatbotInstanceUser::ROLE_MANAGER,
        );

        $this->command?->info(sprintf(
            '  %s → manager on "%s" (id %d) only',
            $email,
            $speedcom->name,
            $speedcom->id
        ));
    }
}
