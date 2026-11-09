<?php

use App\Enums\AppDevelopmentRole;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Moaz is a worker with Customer Support and Development only, as a developer. His password stays.
     * Overview and Sales Agents are open to every workspace user by default, so they are closed here.
     */
    public function up(): void
    {
        $moaz = User::query()->where('email', 'moaz@kaman.rest')->first();
        if ($moaz === null) {
            return;
        }

        $moaz->forceFill([
            'is_admin' => false,
            'projects' => ['kaman-support', 'app-development'],
            'door_access' => ['overview' => false, 'agents' => false],
        ])->save();

        AppDevelopmentMember::query()->updateOrCreate(
            ['user_id' => $moaz->id],
            ['role' => AppDevelopmentRole::Developer],
        );
    }

    public function down(): void
    {
        // Access was changed on purpose. Restoring the old grants is a manual choice.
    }
};
