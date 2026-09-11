<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Enums\Project;
use Illuminate\View\View;

class NotificationComposer
{
    public function compose(View $view): void
    {
        $user = auth()->user();

        if ($user === null || ! $user->canAccessProject(Project::AppDevelopment)) {
            $view->with([
                'appDevNotificationCount' => 0,
                'appDevNotifications' => collect(),
            ]);

            return;
        }

        $view->with([
            'appDevNotificationCount' => $user->unreadNotifications()->count(),
            'appDevNotifications' => $user->notifications()->limit(12)->get(),
        ]);
    }
}
