<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AppDevelopment\AppDevelopmentRelease;
use App\Models\User;
use Illuminate\Notifications\Notification;

class AppDevelopmentReleaseUploaded extends Notification
{
    public function __construct(
        public AppDevelopmentRelease $release,
        public User $actor,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'apk_uploaded',
            'title' => $this->release->title ?: ('v'.$this->release->version_name),
            'actor' => $this->actor->name,
            'excerpt' => $this->release->release_notes ? mb_substr((string) $this->release->release_notes, 0, 140) : null,
            'url' => route('app-development.releases.show', $this->release),
            'version_name' => $this->release->version_name,
        ];
    }
}
