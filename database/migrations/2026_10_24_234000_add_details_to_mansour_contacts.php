<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person values for the أصواتكم message: town, ballot box role and box number.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mansour_contacts', 'details')) {
            return;
        }
        Schema::table('mansour_contacts', function (Blueprint $table): void {
            $table->json('details')->nullable()->after('audience');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('mansour_contacts', 'details')) {
            Schema::table('mansour_contacts', function (Blueprint $table): void {
                $table->dropColumn('details');
            });
        }
    }
};
