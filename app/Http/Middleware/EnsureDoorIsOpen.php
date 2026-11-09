<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Door;
use App\Support\LockedDoor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every web request. A route that belongs to a door the user cannot open answers with the
 * locked page (or a JSON 403) and never reaches its controller, so no data is loaded for it.
 */
class EnsureDoorIsOpen
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $target = $user !== null && $request->route() !== null ? Door::forRequest($request) : null;

        if ($target === null) {
            return $next($request);
        }

        [$door, $subDoor] = $target;

        if ($user->canOpen($door, $subDoor)) {
            return $next($request);
        }

        // Chatbot accounts keep being sent back to their own side by these doors' controllers.
        if ($user->isChatbotOnly() && in_array($door, [Door::Overview, Door::Agents], true)) {
            return $next($request);
        }

        // Finance's deal page shows the proof files an agent attached to a quote.
        if ($request->routeIs('workspace.agents.sales.file') && $user->canOpenSubDoor(Door::Finance, 'deals')) {
            return $next($request);
        }

        return LockedDoor::response($request, $door, $subDoor);
    }
}
