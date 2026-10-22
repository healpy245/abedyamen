<?php

namespace Database\Seeders;

use App\Enums\Project;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A look-only login for the Mowahada bots. It can open the pages and the
 * simulated test chat. It cannot send, change settings, or run the live bots.
 */
class MowahadaViewerSeeder extends Seeder
{
    public const EMAIL = 'mowahada.view@workspace.com';

    public const PASSWORD = 'View123@';

    public function run(): void
    {
        $viewer = User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'مشاهدة الموحدة',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'is_admin' => false,
                'projects' => [Project::AiChatbot->value],
            ],
        );

        ChatbotInstance::query()->where('user_id', $viewer->id)->delete();

        $owner = User::query()->where('email', User::MOWAHADA_EMAIL)->first();
        $bots = $owner === null
            ? collect()
            : ChatbotInstance::query()
                ->where('user_id', $owner->id)
                ->where('integration_type', 'mansour')
                ->get();

        ChatbotInstanceUser::query()
            ->where('user_id', $viewer->id)
            ->when($bots->isNotEmpty(), fn ($query) => $query->whereNotIn('instance_id', $bots->pluck('id')))
            ->delete();

        $authz = app(ChatbotAuthorizationService::class);
        foreach ($bots as $bot) {
            $authz->grantAccess($bot, $viewer, ChatbotInstanceUser::ROLE_VIEWER, [
                'run_tests' => true,
            ]);
        }
    }
}
