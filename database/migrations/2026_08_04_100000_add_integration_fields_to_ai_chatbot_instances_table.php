<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbot_instances', function (Blueprint $table): void {
            $table->string('integration_type', 50)->nullable()->after('greenapi_webhook_token');
            $table->json('integration_settings')->nullable()->after('integration_type');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_instances', function (Blueprint $table): void {
            $table->dropColumn(['integration_type', 'integration_settings']);
        });
    }
};
