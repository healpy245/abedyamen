<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEEP = ['yamen@kaman.rest', 'moaz@kaman.rest', 'ibrahim@kaman-workspace.com'];

    /**
     * Only Yamen, Moaz and Ibrahim keep signing in. Nothing else about the other accounts changes, so
     * their bots keep running and Yamen can turn any of them back on from the users page.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'disabled_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('disabled_at')->nullable()->after('door_access');
            });
        }

        // Never lock the only manager out.
        if (! DB::table('users')->where('email', self::KEEP[0])->exists()) {
            return;
        }

        $ids = DB::table('users')->whereNotIn('email', self::KEEP)->pluck('id');
        DB::table('users')->whereIn('id', $ids)->update(['disabled_at' => now()]);

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
