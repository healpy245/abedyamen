<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_workflow_runs', function (Blueprint $table) {
            $table->json('events')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('form_workflow_runs', function (Blueprint $table) {
            $table->dropColumn('events');
        });
    }
};
