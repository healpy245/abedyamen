<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_development_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->timestamps();
        });

        Schema::create('app_development_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 32)->unique();
            $table->string('title');
            $table->text('description');
            $table->string('type', 32);
            $table->string('priority', 32)->default('normal');
            $table->string('status', 32)->default('open');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_for_qa_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('qa_rejection_count')->default(0);
            $table->timestamps();

            $table->index('status');
            $table->index('assigned_to');
            $table->index('created_by');
            $table->index('priority');
            $table->index('type');
            $table->index('created_at');
        });

        Schema::create('app_development_ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('app_development_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('app_development_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('app_development_tickets')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name');
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index('ticket_id');
        });

        Schema::create('app_development_ticket_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('app_development_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'created_at']);
            $table->index('event_type');
        });

        Schema::create('app_development_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version_name');
            $table->unsignedInteger('version_code')->nullable();
            $table->string('title')->nullable();
            $table->text('release_notes')->nullable();
            $table->string('disk', 32)->default('local');
            $table->string('file_path');
            $table->string('original_filename');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('checksum_sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->boolean('is_latest')->default(false);
            $table->timestamps();

            $table->index('is_latest');
            $table->index('created_at');
        });

        Schema::create('app_development_release_ticket', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained('app_development_releases')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('app_development_tickets')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['release_id', 'ticket_id']);
        });

        Schema::create('app_development_release_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained('app_development_releases')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('downloaded_at')->useCurrent();

            $table->index(['release_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_development_release_downloads');
        Schema::dropIfExists('app_development_release_ticket');
        Schema::dropIfExists('app_development_releases');
        Schema::dropIfExists('app_development_ticket_activities');
        Schema::dropIfExists('app_development_ticket_attachments');
        Schema::dropIfExists('app_development_ticket_comments');
        Schema::dropIfExists('app_development_tickets');
        Schema::dropIfExists('app_development_members');
    }
};
