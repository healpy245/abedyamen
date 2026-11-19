<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Local copies of the live accounts set up on 2026-11-15, with the same doors, so each kind of user
 * can be tried on a developer machine. Never run on the live server: it refuses in production.
 *
 *     php artisan db:seed --class=LocalPreviewUsersSeeder
 *
 * Every account here signs in with the local preview password below (local only, not a live password).
 */
class LocalPreviewUsersSeeder extends Seeder
{
    public const PASSWORD = 'kYR-MK6TT-lu';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('LocalPreviewUsersSeeder is for local machines only.');
        }

        $worker = ['is_admin' => false, 'projects' => [], 'door_access' => ['overview' => false]];

        $accounts = [
            ['nael@kaman.rest', 'Nael', $worker],
            ['osama@kaman.rest', 'Osama', $worker],
            ['suzi@kaman.rest', 'Suzi', ['is_admin' => false, 'projects' => [], 'door_access' => ['overview' => false, 'agents' => false, 'finance' => true]]],
            ['ahmadessa@kaman.rest', 'Ahmad Essa', ['is_admin' => false, 'projects' => [Project::AppDevelopment->value, Project::Form->value], 'door_access' => [
                'overview' => false, 'agents' => false, 'form' => false,
                'support' => true, 'development' => true, 'development.reports' => true,
                'finance' => true, 'finance.dashboard' => false, 'finance.clients' => false, 'finance.deals' => false,
            ]]],
            ['fregmac@kaman.rest', 'Fregmac', ['is_admin' => true, 'projects' => Project::keys(), 'door_access' => null]],
            ['abedjaber@kaman.rest', 'Abed Jaber', ['is_admin' => false, 'projects' => [Project::AppDevelopment->value], 'door_access' => ['agents.manage' => true, 'support' => true]]],
        ];

        foreach ($accounts as [$email, $name, $attributes]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill($attributes + [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'disabled_at' => null,
            ])->save();
        }
    }
}
