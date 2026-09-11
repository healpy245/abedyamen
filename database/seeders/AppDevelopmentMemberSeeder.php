<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AppDevelopmentRole;
use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\User;
use App\Services\AppDevelopment\AppDevelopmentWhatsAppNotifyService;
use Illuminate\Database\Seeder;

/**
 * Assigns App Development QA / developer roles + WhatsApp notify prefs.
 *
 * Project access itself is granted in WorkspaceUserSeeder (merged into
 * users.projects). This seeder only upserts role rows and never strips
 * existing workspace permissions.
 */
class AppDevelopmentMemberSeeder extends Seeder
{
    /**
     * @var array<string, array{role: AppDevelopmentRole, phone: ?string, notify: bool}>
     */
    public const TEAM = [
        'abedjaber@kaman.rest' => [
            'role' => AppDevelopmentRole::Qa,
            'phone' => '+972584680004',
            'notify' => false,
        ],
        'yamen@kaman.rest' => [
            'role' => AppDevelopmentRole::Admin,
            'phone' => null,
            'notify' => true,
        ],
        'burhan@kaman.rest' => [
            'role' => AppDevelopmentRole::Admin,
            'phone' => null,
            'notify' => false,
        ],
        'ahmadessa@kaman.rest' => [
            'role' => AppDevelopmentRole::Qa,
            'phone' => '+972549133538',
            'notify' => true,
        ],
        'minna@kaman.rest' => [
            'role' => AppDevelopmentRole::Developer,
            'phone' => '+972547239847',
            'notify' => true,
        ],
        'abed@kaman.rest' => [
            'role' => AppDevelopmentRole::Developer,
            'phone' => '+972524209156',
            'notify' => true,
        ],
        'amro@kaman.rest' => [
            'role' => AppDevelopmentRole::Developer,
            'phone' => '0546452973',
            'notify' => true,
        ],
        'moaz@kaman.rest' => [
            'role' => AppDevelopmentRole::Developer,
            'phone' => '+972523211175',
            'notify' => true,
        ],
    ];

    public function run(): void
    {
        foreach (self::TEAM as $email => $config) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->command?->warn("App Development member missing: {$email} — run WorkspaceUserSeeder first.");

                continue;
            }

            $projects = $user->projectKeys();
            if (! in_array(Project::AppDevelopment->value, $projects, true) && ! $user->is_admin) {
                $projects[] = Project::AppDevelopment->value;
                $user->forceFill(['projects' => array_values(array_unique($projects))])->save();
            }

            AppDevelopmentMember::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => $config['role'],
                    'phone' => $config['phone'],
                    'whatsapp_notifications_enabled' => $config['notify'],
                ],
            );

            $this->command?->info(sprintf(
                '  %s — %s%s',
                $email,
                $config['role']->value,
                $config['notify'] ? '' : ' (WhatsApp off)',
            ));
        }

        app(AppDevelopmentWhatsAppNotifyService::class)->syncWorkerPhonesOntoKamanBotIgnoreList();
    }
}
