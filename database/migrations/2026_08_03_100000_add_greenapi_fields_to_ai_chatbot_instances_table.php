<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbot_instances', function (Blueprint $table) {
            $table->string('greenapi_url', 512)->nullable()->after('stores_members');
            $table->string('greenapi_webhook_token', 64)->nullable()->unique()->after('greenapi_url');
        });

        DB::table('ai_chatbot_instances')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('ai_chatbot_instances')
                        ->where('id', $row->id)
                        ->whereNull('greenapi_webhook_token')
                        ->update(['greenapi_webhook_token' => Str::random(48)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_instances', function (Blueprint $table) {
            $table->dropColumn(['greenapi_url', 'greenapi_webhook_token']);
        });
    }
};
