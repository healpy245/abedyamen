<?php

declare(strict_types=1);

namespace App\Services\AppDevelopment;

use App\Enums\AppDevelopmentTicketActivityType;
use App\Models\AppDevelopment\AppDevelopmentRelease;
use App\Models\AppDevelopment\AppDevelopmentReleaseDownload;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReleaseService
{
    public function __construct(
        private readonly TicketWorkflowService $workflow,
        private readonly AppDevelopmentNotificationService $notifications,
    ) {}

    /**
     * @param  list<int>  $ticketIds
     */
    public function upload(
        User $user,
        UploadedFile $file,
        string $versionName,
        ?int $versionCode,
        ?string $title,
        ?string $releaseNotes,
        array $ticketIds,
    ): AppDevelopmentRelease {
        $checksum = hash_file('sha256', $file->getRealPath()) ?: '';
        $original = $file->getClientOriginalName();
        $safeName = $this->safeFilename($original);

        return DB::transaction(function () use ($user, $file, $versionName, $versionCode, $title, $releaseNotes, $ticketIds, $checksum, $original, $safeName): AppDevelopmentRelease {
            AppDevelopmentRelease::query()->where('is_latest', true)->update(['is_latest' => false]);

            $release = AppDevelopmentRelease::query()->create([
                'version_name' => $versionName,
                'version_code' => $versionCode,
                'title' => $title,
                'release_notes' => $releaseNotes,
                'disk' => 'local',
                'file_path' => 'pending',
                'original_filename' => $original,
                'file_size' => $file->getSize() ?: 0,
                'checksum_sha256' => $checksum,
                'uploaded_by' => $user->id,
                'is_latest' => true,
            ]);

            $path = $file->storeAs(
                'app-development/releases/'.$release->id,
                $safeName,
                'local',
            );

            if ($path === false || ! Storage::disk('local')->exists($path)) {
                throw new \RuntimeException(__('app-development.errors.release_store_failed'));
            }

            $release->forceFill([
                'file_path' => $path,
                'file_size' => Storage::disk('local')->size($path),
            ])->save();

            $ticketIds = array_values(array_unique(array_filter($ticketIds)));

            if ($ticketIds !== []) {
                $tickets = AppDevelopmentTicket::query()->whereIn('id', $ticketIds)->get();
                $release->tickets()->sync($tickets->pluck('id')->all());

                foreach ($tickets as $ticket) {
                    $this->workflow->record(
                        $ticket,
                        $user,
                        AppDevelopmentTicketActivityType::ApkLinked,
                        $ticket->status,
                        $ticket->status,
                        [
                            'release_id' => $release->id,
                            'version_name' => $release->version_name,
                        ],
                    );
                }
            } else {
                $this->notifications->notifyRelease($release, $user);
            }

            return $release->refresh();
        });
    }

    public function download(AppDevelopmentRelease $release, User $user): StreamedResponse
    {
        $disk = Storage::disk($release->disk ?: 'local');

        if (! $disk->exists($release->file_path)) {
            abort(404);
        }

        AppDevelopmentReleaseDownload::query()->create([
            'release_id' => $release->id,
            'user_id' => $user->id,
            'downloaded_at' => now(),
        ]);

        return $disk->download(
            $release->file_path,
            $release->original_filename,
            [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    private function safeFilename(string $original): string
    {
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $basename = pathinfo($original, PATHINFO_FILENAME);
        $slug = Str::slug($basename) ?: 'release';

        return $slug.'-'.Str::lower(Str::random(8)).'.'.($extension !== '' ? $extension : 'apk');
    }
}
