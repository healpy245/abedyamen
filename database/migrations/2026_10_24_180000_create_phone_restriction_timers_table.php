<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_restriction_timers', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            $table->string('phone_model', 80)->nullable();
            $table->timestamp('ends_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_restriction_timers');
    }
};
