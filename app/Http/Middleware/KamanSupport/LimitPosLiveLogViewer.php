<?php

declare(strict_types=1);

namespace App\Http\Middleware\KamanSupport;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A limited support viewer may open the live board and a single device.
 * Tickets, failed-request tools, printer commands, and the support API stay closed.
 */
class LimitPosLiveLogViewer
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Someone limited to one product cannot open another product's ticket or device by its address.
        if ($user instanceof User && $user->supportProduct() !== null) {
            foreach (['ticket', 'device'] as $name) {
                $record = $request->route($name);
                abort_if(is_object($record) && ! $record->isVisibleTo($user), 404);
            }
        }

        if (! $user instanceof User || ! $user->limitedSupportConsole()) {
            return $next($request);
        }

        $allowed = $request->routeIs(
            'kaman-support.index',
            'kaman-support.board.feed',
            'kaman-support.logos',
            'kaman-support.live',
            'kaman-support.liveview',
            'kaman-support.live.feed',
            'kaman-support.device',
            'kaman-support.device.feed',
        );

        abort_unless($allowed, 403);

        return $next($request);
    }
}
