<?php

namespace Database\Seeders;

use App\Enums\Project;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Services\AiChatbot\MansourBotService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Look-only login for the two Mowahada invitation bots.
 */
class InvitationViewerSeeder extends Seeder
{
    public const EMAIL = 'invitation@workspace.com';

    public const PASSWORD = 'Invite123@';

    public function run(): void
    {
        $viewer = User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'invitation',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'is_admin' => false,
                'projects' => [Project::AiChatbot->value],
            ],
        );

        ChatbotInstance::query()->where('user_id', $viewer->id)->delete();

        $owner = User::query()->where('email', User::MOWAHADA_EMAIL)->first();
        $bots = collect();
        if ($owner instanceof User) {
            $service = app(MansourBotService::class);
            $service->ensureInviteBot($owner);
            $service->ensureYouthInviteBot($owner);
            $bots = ChatbotInstance::query()
                ->where('user_id', $owner->id)
                ->where('integration_type', 'mansour')
                ->get()
                ->filter(fn (ChatbotInstance $bot): bool => $bot->isInviteBot())
                ->values();
        }

        ChatbotInstanceUser::query()
            ->where('user_id', $viewer->id)
            ->when($bots->isNotEmpty(), fn ($query) => $query->whereNotIn('instance_id', $bots->pluck('id')))
            ->delete();

        $authz = app(ChatbotAuthorizationService::class);
        foreach ($bots as $bot) {
            $authz->grantAccess($bot, $viewer, ChatbotInstanceUser::ROLE_VIEWER);
        }
    }
}
