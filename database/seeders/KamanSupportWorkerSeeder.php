<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Project;
use App\Models\KamanSupport\SupportWorker;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Phase-1: only the existing Yamen workspace user may open the console.
 * Does not reset passwords. Grants kaman-support on users.projects.
 */
class KamanSupportWorkerSeeder extends Seeder
{
    public const WORKER_EMAIL = 'yamen@kaman.rest';

    public function run(): void
    {
        $user = User::query()->where('email', self::WORKER_EMAIL)->first();

        if ($user === null) {
            $this->command?->warn('Kaman support worker skipped: '.self::WORKER_EMAIL.' is not seeded yet.');

            return;
        }

        $projects = $user->projectKeys();
        if (! in_array(Project::KamanSupport->value, $projects, true)) {
            $projects[] = Project::KamanSupport->value;
            $user->projects = $projects;
            $user->save();
        }

        SupportWorker::query()->updateOrCreate(
            ['email' => self::WORKER_EMAIL],
            [
                'user_id' => $user->id,
                'name' => $user->name,
                'is_active' => true,
            ],
        );

        $this->command?->info('Kaman support console: /kaman-support/ (workspace login, Yamen only).');
    }
}
