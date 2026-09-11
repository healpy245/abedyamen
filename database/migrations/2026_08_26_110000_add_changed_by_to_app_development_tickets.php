<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_development_tickets', function (Blueprint $table): void {
            $table->foreignId('priority_changed_by')
                ->nullable()
                ->after('priority')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('status_changed_by')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::table('app_development_tickets')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('app_development_tickets')
                    ->where('id', $row->id)
                    ->update([
                        'priority_changed_by' => $row->created_by,
                        'status_changed_by' => $row->created_by,
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_development_tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('priority_changed_by');
            $table->dropConstrainedForeignId('status_changed_by');
        });
    }
};
