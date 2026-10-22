<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Close every workspace login except Yamen, Ahmad's form door, Moaz's
     * support door, Ibrahim, and the Mowahada accounts.
     */
    public function up(): void
    {
        $off = [
            'mohamed@kaman.rest',
            'malan@kaman.rest',
            'kaman@kaman.rest',
            'speedcom@kaman.rest',
            'connections@kaman.rest',
            'burhan@kaman.rest',
            'abedjaber@kaman.rest',
            'ahmadessa@kaman.rest',
            'minna@kaman.rest',
            'abed@kaman.rest',
            'amro@kaman.rest',
        ];

        $ids = DB::table('users')->whereIn('email', $off)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        DB::table('users')->whereIn('id', $ids)->update([
            'is_admin' => false,
            'projects' => json_encode([]),
        ]);

        if (Schema::hasTable('ai_chatbot_instance_user')) {
            DB::table('ai_chatbot_instance_user')->whereIn('user_id', $ids)->delete();
        }

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
        }
    }

    public function down(): void
    {
        // Access was removed on purpose. Restoring the old grants is a manual choice.
    }
};
