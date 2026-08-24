<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AppDevelopmentRole;
use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Assigns App Development QA / developer roles.
 *
 * Project access itself is granted in WorkspaceUserSeeder (merged into
 * users.projects). This seeder only upserts role rows and never strips
 * existing workspace permissions.
 */
class AppDevelopmentMemberSeeder extends Seeder
{
    /**
     * @var array<string, AppDevelopmentRole>
     */
    public const TEAM = [
        'abedjaber@kaman.rest' => AppDevelopmentRole::Qa,
        'yamen@kaman.rest' => AppDevelopmentRole::Qa,
        'ahmadessa@kaman.rest' => AppDevelopmentRole::Qa,
        'minna@kaman.rest' => AppDevelopmentRole::Developer,
        'abed@kaman.rest' => AppDevelopmentRole::Developer,
        'amro@kaman.rest' => AppDevelopmentRole::Developer,
        'moaz@kaman.rest' => AppDevelopmentRole::Developer,
    ];

    public function run(): void
    {
        foreach (self::TEAM as $email => $role) {
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
                ['role' => $role],
            );

            $this->command?->info(sprintf('  %s — %s', $email, $role->value));
        }
    }
}
