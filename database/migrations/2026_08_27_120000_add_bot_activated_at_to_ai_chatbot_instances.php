<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbot_instances', function (Blueprint $table) {
            $table->timestamp('bot_activated_at')->nullable()->after('is_active');
        });

        DB::table('ai_chatbot_instances')
            ->where('is_active', 1)
            ->whereNull('bot_activated_at')
            ->update(['bot_activated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_instances', function (Blueprint $table) {
            $table->dropColumn('bot_activated_at');
        });
    }
};
