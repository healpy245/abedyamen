<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_restaurant_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subdomain', 64);
            $table->string('environment', 16);
            $table->string('base_url');
            $table->text('token');
            $table->string('username')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'base_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_restaurant_tokens');
    }
};
