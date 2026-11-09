<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Only what Yamen changed from each user's standard access. Empty means the user keeps
     * exactly the access they had before this column existed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('door_access')->nullable()->after('projects');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('door_access');
        });
    }
};
