<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbot_messages', function (Blueprint $table): void {
            $table->string('attachment_disk', 40)->nullable()->after('message');
            $table->string('attachment_path')->nullable()->after('attachment_disk');
            $table->string('attachment_mime', 100)->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_messages', function (Blueprint $table): void {
            $table->dropColumn(['attachment_disk', 'attachment_path', 'attachment_mime']);
        });
    }
};
