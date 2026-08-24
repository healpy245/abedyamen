<?php

namespace Database\Seeders;

use App\Enums\Project;
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
    /**
     * Users who must not own chatbot instances — they receive instance access
     * grants only (see MalanCompanyMemberSeeder).
     *
     * @var list<string>
     */
    public const CHATBOT_MEMBER_ONLY_EMAILS = [
        'malan@kaman.rest',
    ];

    public function run(): void
    {
        $users = [
            [
                'name' => 'Yamen',
                'email' => 'yamen@kaman.rest',
                'password' => 'Yam123456@',
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
                    Project::AiChatbot->value,
                    Project::Form->value,
                ],
            ],
            [
                // Malan company staff: chatbot project only; MALAN bot via grant (not ownership).
                'name' => 'Malan Team',
                'email' => 'malan@kaman.rest',
                'password' => 'Malan123@',
                'is_admin' => false,
                'projects' => [
                    Project::AiChatbot->value,
                ],
            ],
        ];

        foreach ($users as $user) {
            $this->upsertWorkspaceUser($user, mergeProjects: false);
        }

        foreach ($this->appDevelopmentTeam() as $user) {
            $this->upsertWorkspaceUser($user, mergeProjects: true);
        }
    }

    /**
     * App Development / QA team. Grants are merged so existing project
     * access (Form, AI Chatbot, admin catalog) is never stripped.
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
                'projects' => $grant,
            ],
            [
                'name' => 'Minna',
                'email' => 'minna@kaman.rest',
                'password' => 'Minna123@',
                'is_admin' => false,
                'projects' => $grant,
            ],
            [
                'name' => 'Abed',
                'email' => 'abed@kaman.rest',
                'password' => 'Abed123@',
                'is_admin' => false,
                'projects' => $grant,
            ],
            [
                'name' => 'Amro',
                'email' => 'amro@kaman.rest',
                'password' => 'Amro123@',
                'is_admin' => false,
                'projects' => $grant,
            ],
            [
                'name' => 'Moaz',
                'email' => 'moaz@kaman.rest',
                'password' => 'Moaz123@',
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
