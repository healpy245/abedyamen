<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('support_devices', 'product')) {
            return;
        }

        Schema::table('support_devices', function (Blueprint $table): void {
            $table->string('product', 16)->nullable()->after('app_version');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('support_devices', 'product')) {
            return;
        }

        Schema::table('support_devices', function (Blueprint $table): void {
            $table->dropColumn('product');
        });
    }
};
