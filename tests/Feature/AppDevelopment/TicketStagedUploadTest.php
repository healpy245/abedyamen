<?php

declare(strict_types=1);

namespace Tests\Feature\AppDevelopment;

use App\Enums\AppDevelopmentRole;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Enums\AppDevelopmentTicketType;
use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentStagedUpload;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;
use App\Services\AppDevelopment\TicketStagingUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketStagedUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_chunked_upload_then_create_ticket_with_staged_video(): void
    {
        $qa = $this->makeQa();
        $bytes = random_bytes(1500);
        $file = UploadedFile::fake()->createWithContent('clip.mp4', $bytes);

        $init = $this->actingAs($qa)->postJson(route('app-development.uploads.init'), [
            'name' => 'clip.mp4',
            'size' => strlen($bytes),
            'mime' => 'video/mp4',
            'kind' => 'attachment',
        ])->assertOk()->json();

        $uuid = $init['uuid'];
        $chunkSize = (int) $init['chunk_size'];
        $this->assertGreaterThan(0, $chunkSize);

        $offset = 0;
        $index = 0;
        while ($offset < strlen($bytes)) {
            $slice = substr($bytes, $offset, $chunkSize);
            $chunk = UploadedFile::fake()->createWithContent('chunk.bin', $slice);

            $this->actingAs($qa)->post(route('app-development.uploads.chunk', $uuid), [
                'index' => $index,
                'chunk' => $chunk,
            ], ['Accept' => 'application/json'])->assertOk();

            $offset += $chunkSize;
            $index++;
        }

        $staged = AppDevelopmentStagedUpload::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame('ready', $staged->status);

        $this->actingAs($qa)->post(route('app-development.tickets.store'), [
            'title' => 'Video ticket',
            'priority' => AppDevelopmentTicketPriority::High->value,
            'type' => AppDevelopmentTicketType::Bug->value,
            'app_types' => ['cashier'],
            'description' => 'Has a video',
            'staged_attachments' => [$uuid],
        ])->assertRedirect();

        $ticket = AppDevelopmentTicket::query()->firstOrFail();
        $this->assertSame(AppDevelopmentTicketStatus::Open, $ticket->status);
        $this->assertCount(1, $ticket->attachments);
        $this->assertSame('clip.mp4', $ticket->attachments->first()->original_name);

        $staged->refresh();
        $this->assertSame('claimed', $staged->status);
    }

    public function test_create_form_includes_upload_progress_assets(): void
    {
        $qa = $this->makeQa();

        $this->actingAs($qa)
            ->get(route('app-development.tickets.create'))
            ->assertOk()
            ->assertSee('data-app-dev-upload-progress', false)
            ->assertSee('window.__appDevUpload', false)
            ->assertSee('data-app-dev-upload-percent', false);
    }

    private function makeQa(): User
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'projects' => [Project::AppDevelopment->value],
        ]);

        AppDevelopmentMember::query()->create([
            'user_id' => $user->id,
            'role' => AppDevelopmentRole::Qa,
            'whatsapp_notifications_enabled' => true,
        ]);

        return $user->fresh(['appDevelopmentMembership']) ?? $user;
    }
}
