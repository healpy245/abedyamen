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
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make($user['password']),
                    'is_admin' => $user['is_admin'],
                    'projects' => $user['projects'],
                    'email_verified_at' => now(),
                ]
            );

            $this->command?->info(sprintf(
                '  %s — %s',
                $user['email'],
                $user['is_admin'] ? 'admin (all projects)' : implode(', ', $user['projects'])
            ));
        }
    }
}
