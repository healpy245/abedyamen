<?php

use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Moaz has Customer Support only. User::supportProduct() limits what he sees there to Kaman Cashier.
     * His password stays.
     */
    public function up(): void
    {
        $moaz = User::query()->where('email', 'moaz@kaman.rest')->first();
        if ($moaz === null) {
            return;
        }

        $moaz->forceFill([
            'is_admin' => false,
            'projects' => ['kaman-support'],
            'door_access' => ['overview' => false, 'agents' => false],
        ])->save();

        AppDevelopmentMember::query()->where('user_id', $moaz->id)->delete();
    }

    public function down(): void
    {
        // Access was changed on purpose. Restoring the old grants is a manual choice.
    }
};
