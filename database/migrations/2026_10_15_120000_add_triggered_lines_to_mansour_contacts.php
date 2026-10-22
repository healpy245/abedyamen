<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mansour_contacts', function (Blueprint $table) {
            $table->json('triggered_lines')->nullable()->after('triggered_at');
        });
    }

    public function down(): void
    {
        Schema::table('mansour_contacts', function (Blueprint $table) {
            $table->dropColumn('triggered_lines');
        });
    }
};
