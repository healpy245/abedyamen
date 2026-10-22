<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $taken = DB::table('users')->where('email', 'mowahada@workspace.com')->exists();
        if ($taken) {
            return;
        }

        DB::table('users')
            ->where('email', 'chatbot@kaman.rest')
            ->update(['email' => 'mowahada@workspace.com']);
    }

    public function down(): void
    {
        $taken = DB::table('users')->where('email', 'chatbot@kaman.rest')->exists();
        if ($taken) {
            return;
        }

        DB::table('users')
            ->where('email', 'mowahada@workspace.com')
            ->update(['email' => 'chatbot@kaman.rest']);
    }
};
