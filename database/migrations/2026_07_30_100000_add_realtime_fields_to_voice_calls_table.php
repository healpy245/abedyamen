<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->string('realtime_model')->nullable()->after('provider');
            $table->string('realtime_voice')->nullable()->after('realtime_model');
            $table->timestamp('greeting_played_at')->nullable()->after('answered_at');
            $table->unsignedInteger('interruption_count')->default(0)->after('duration_seconds');
            $table->text('conversation_summary')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->dropColumn([
                'realtime_model',
                'realtime_voice',
                'greeting_played_at',
                'interruption_count',
                'conversation_summary',
            ]);
        });
    }
};
