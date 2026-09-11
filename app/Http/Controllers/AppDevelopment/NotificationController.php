<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function open(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        abort_unless(
            (int) $notification->notifiable_id === (int) $request->user()->id
            && $notification->notifiable_type === $request->user()::class,
            404,
        );

        if ($notification->unread()) {
            $notification->markAsRead();
        }

        $url = $notification->data['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return redirect()->route('app-development.index');
        }

        return redirect()->to($url);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
