<?php

use App\Services\AiChatbot\MowahadaLinePool;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mowahada_lines', function (Blueprint $table) {
            $table->id();
            $table->string('wa_instance', 64)->unique();
            $table->text('url');
            $table->string('phone', 32)->nullable();
            $table->string('name', 120)->nullable();
            $table->string('status', 32)->default('ready');
            $table->string('reason', 64)->nullable();
            $table->string('changed_at', 40)->nullable();
            $table->timestamps();
        });

        app(MowahadaLinePool::class)->importFromBots();
    }

    public function down(): void
    {
        Schema::dropIfExists('mowahada_lines');
    }
};
