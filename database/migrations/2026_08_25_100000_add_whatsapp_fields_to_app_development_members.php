<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_development_members', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('role');
            $table->boolean('whatsapp_notifications_enabled')->default(true)->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('app_development_members', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'whatsapp_notifications_enabled']);
        });
    }
};
