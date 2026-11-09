<?php

namespace Database\Seeders;

use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The workspace access matrix.
 *
 * Idempotent: re-running resets each user's password and project grants back
 * to what is declared here, so this file stays the source of truth for who
 * can open what.
 */
class WorkspaceUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Yamen',
                'email' => 'yamen@kaman.rest',
                'password' => 'yam1234@',
                // Admin: full catalog access, now and for any project added later.
                'is_admin' => true,
                'projects' => Project::keys(),
            ],
            [
                'name' => 'Ahmad',
                'email' => 'ahmad@kaman.rest',
                'password' => 'Ahmad123',
                'is_admin' => false,
                'projects' => [
                    Project::Form->value,
                ],
            ],
            [
                'name' => 'Mohamed',
                'email' => 'mohamed@kaman.rest',
                'password' => 'mohamed123@',
                'is_admin' => false,
                'projects' => [
                    Project::Form->value,
                ],
            ],
            [
                // POS live logs only, and only devices running app version 1.0.x.
                'name' => 'Moaz',
                'email' => 'moaz@kaman.rest',
                'password' => 'Moaz123@',
                'is_admin' => false,
                'projects' => [
                    Project::KamanSupport->value,
                ],
            ],
            [
                // Live app connections only. Every version. No logs, tickets, or commands.
                'name' => 'Connections',
                'email' => 'connections@kaman.rest',
                'password' => 'Connections123@',
                'is_admin' => false,
                'projects' => [
                    Project::KamanSupport->value,
                ],
            ],
            [
                'name' => 'Burhan',
                'email' => 'burhan@kaman.rest',
                'password' => 'Burhan123@',
                // Admin for the existing catalog, but not POS customer support.
                'is_admin' => true,
                'projects' => array_values(array_filter(
                    Project::keys(),
                    static fn (string $key): bool => $key !== Project::KamanSupport->value,
                )),
            ],
        ];

        foreach ($users as $user) {
            $this->upsertWorkspaceUser($user, mergeProjects: false);
        }

        foreach ($this->appDevelopmentTeam() as $user) {
            $this->upsertWorkspaceUser($user, mergeProjects: true);
        }

        $moaz = User::query()->where('email', 'moaz@kaman.rest')->first();
        if ($moaz !== null) {
            AppDevelopmentMember::query()->where('user_id', $moaz->id)->delete();
        }
    }

    /**
     * App Development / QA team. Grants are merged so existing project
     * access (Form, admin catalog) is never stripped.
     *
     * @return list<array{name: string, email: string, password: string, is_admin: bool, projects: list<string>}>
     */
    private function appDevelopmentTeam(): array
    {
        $grant = [Project::AppDevelopment->value];

        return [
            [
                'name' => 'Abed Jaber',
                'email' => 'abedjaber@kaman.rest',
                'password' => 'AbedJaber123@',
                'is_admin' => false,
                'projects' => $grant,
            ],
            [
                'name' => 'Ahmad Essa',
                'email' => 'ahmadessa@kaman.rest',
                'password' => 'AhmadEssa123@',
                'is_admin' => false,
                'projects' => [
                    Project::AppDevelopment->value,
                    Project::Form->value,
                ],
            ],
            [
                'name' => 'Minna',
                'email' => 'minna@kaman.rest',
                'password' => 'Minna123@',
                'is_admin' => false,
                'projects' => $grant,
            ],
            [
                'name' => 'Abed Salhab',
                'email' => 'abed@kaman.rest',
                'password' => 'Abed123@',
                'is_admin' => false,
                'projects' => [
                    Project::AppDevelopment->value,
                    Project::Form->value,
                ],
            ],
            [
                'name' => 'Amro',
                'email' => 'amro@kaman.rest',
                'password' => 'Amro123@',
                'is_admin' => false,
                'projects' => $grant,
            ],
        ];
    }

    /**
     * @param  array{name: string, email: string, password: string, is_admin: bool, projects: list<string>}  $user
     */
    private function upsertWorkspaceUser(array $user, bool $mergeProjects): void
    {
        $existing = User::query()->where('email', $user['email'])->first();

        $payload = [
            'name' => $user['name'],
            'email_verified_at' => now(),
        ];

        if ($existing === null) {
            $payload['password'] = Hash::make($user['password']);
            $payload['is_admin'] = $user['is_admin'];
            $payload['projects'] = $user['projects'];
        } elseif ($mergeProjects) {
            $payload['projects'] = array_values(array_unique(array_merge(
                $existing->projectKeys(),
                $user['projects'],
            )));
            if ($existing->is_admin) {
                $payload['is_admin'] = true;
            }
        } else {
            $payload['password'] = Hash::make($user['password']);
            $payload['is_admin'] = $user['is_admin'];
            $payload['projects'] = $user['projects'];
        }

        User::query()->updateOrCreate(
            ['email' => $user['email']],
            $payload,
        );

        $saved = User::query()->where('email', $user['email'])->first();

        $this->command?->info(sprintf(
            '  %s — %s',
            $user['email'],
            ($saved?->is_admin) ? 'admin (all projects)' : implode(', ', $saved?->projectKeys() ?? $user['projects'])
        ));
    }
}
