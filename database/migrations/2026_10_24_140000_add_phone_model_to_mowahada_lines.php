<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mowahada_lines', function (Blueprint $table) {
            $table->string('phone_model', 80)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('mowahada_lines', function (Blueprint $table) {
            $table->dropColumn('phone_model');
        });
    }
};
