<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Development tasks: Paused is replaced by QA testing (and Pushed to git is added before Completed).
 * A paused task had been started and stopped, so it goes back to In progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('app_development_tasks')->where('status', 'paused')->update(['status' => 'in_progress']);
    }

    public function down(): void
    {
        DB::table('app_development_tasks')->whereIn('status', ['qa_testing', 'pushed_to_git'])->update(['status' => 'in_progress']);
    }
};
