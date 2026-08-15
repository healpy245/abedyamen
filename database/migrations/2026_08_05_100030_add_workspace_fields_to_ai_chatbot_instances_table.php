<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbot_instances', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('integration_settings');
            $table->text('disabled_message')->nullable()->after('is_active');
            $table->json('prompt_sections')->nullable()->after('disabled_message');
            $table->unsignedInteger('settings_schema_version')->default(1)->after('prompt_sections');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_instances', function (Blueprint $table) {
            $table->dropColumn([
                'is_active',
                'disabled_message',
                'prompt_sections',
                'settings_schema_version',
            ]);
        });
    }
};
