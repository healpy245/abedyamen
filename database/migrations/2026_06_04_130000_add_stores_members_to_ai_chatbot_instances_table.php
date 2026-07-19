<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_chatbot_instances', function (Blueprint $table) {
            $table->boolean('stores_members')->default(false)->after('system_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_instances', function (Blueprint $table) {
            $table->dropColumn('stores_members');
        });
    }
};
