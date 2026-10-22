<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('ai_chatbot_instances')->cascadeOnDelete();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('group', 64);
            $table->string('line_key', 64)->default('');
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('row_count')->default(0);
            $table->json('phones');
            $table->json('payload');
            $table->timestamps();

            $table->index(['instance_id', 'campaign_id', 'group', 'line_key'], 'analytics_downloads_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_downloads');
    }
};
