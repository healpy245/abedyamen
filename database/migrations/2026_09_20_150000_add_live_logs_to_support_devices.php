<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $missing = array_values(array_filter([
            'restaurant_name',
            'cashier_name',
            'cashier_email',
            'role',
            'platform',
            'app_version',
            'hostname',
            'locale',
            'session_id',
            'app_state',
            'logged_in',
            'opened_at',
            'closed_at',
            'log_count',
            'extra',
        ], static fn (string $column): bool => ! Schema::hasColumn('support_devices', $column)));

        if ($missing !== []) {
            Schema::table('support_devices', function (Blueprint $table) use ($missing): void {
                foreach ($missing as $column) {
                    match ($column) {
                        'restaurant_name' => $table->string('restaurant_name', 191)->nullable(),
                        'cashier_name' => $table->string('cashier_name', 191)->nullable(),
                        'cashier_email' => $table->string('cashier_email', 191)->nullable(),
                        'role' => $table->string('role', 64)->nullable(),
                        'platform' => $table->string('platform', 64)->nullable(),
                        'app_version' => $table->string('app_version', 64)->nullable(),
                        'hostname' => $table->string('hostname', 191)->nullable(),
                        'locale' => $table->string('locale', 16)->nullable(),
                        'session_id' => $table->string('session_id', 64)->nullable(),
                        'app_state' => $table->string('app_state', 32)->default('open'),
                        'logged_in' => $table->boolean('logged_in')->default(false),
                        'opened_at' => $table->timestamp('opened_at')->nullable(),
                        'closed_at' => $table->timestamp('closed_at')->nullable(),
                        'log_count' => $table->unsignedInteger('log_count')->default(0),
                        'extra' => $table->json('extra')->nullable(),
                    };
                }
            });
        }

        if (Schema::hasTable('support_device_logs')) {
            return;
        }

        Schema::create('support_device_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_device_id')->constrained('support_devices')->cascadeOnDelete();
            $table->string('client_id', 80);
            $table->string('level', 16)->default('info');
            $table->string('source', 64)->default('app');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('logged_at')->nullable();
            $table->timestamps();

            $table->unique(['support_device_id', 'client_id'], 'support_device_logs_device_client_unique');
            $table->index(['support_device_id', 'id']);
            $table->index(['support_device_id', 'level']);
            $table->index('logged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_device_logs');

        $existing = array_values(array_filter([
            'restaurant_name',
            'cashier_name',
            'cashier_email',
            'role',
            'platform',
            'app_version',
            'hostname',
            'locale',
            'session_id',
            'app_state',
            'logged_in',
            'opened_at',
            'closed_at',
            'log_count',
            'extra',
        ], static fn (string $column): bool => Schema::hasColumn('support_devices', $column)));

        if ($existing !== []) {
            Schema::table('support_devices', function (Blueprint $table) use ($existing): void {
                $table->dropColumn($existing);
            });
        }
    }
};
