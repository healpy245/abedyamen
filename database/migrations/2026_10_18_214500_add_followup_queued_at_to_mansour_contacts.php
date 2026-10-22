<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mansour_contacts', function (Blueprint $table) {
            $table->timestamp('followup_queued_at')->nullable()->after('triggered_at');
        });
    }

    public function down(): void
    {
        Schema::table('mansour_contacts', function (Blueprint $table) {
            $table->dropColumn('followup_queued_at');
        });
    }
};
