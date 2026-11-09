<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Door;
use App\Enums\Project;
use App\Support\LockedDoor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route behind a project in the workspace catalog.
 *
 * Usage: ->middleware(['auth', 'project:form'])
 *
 * Authentication itself is left to the `auth` middleware, so this only ever
 * answers "may this signed-in user open this project?".
 */
class EnsureUserCanAccessProject
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $project): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        $resolved = Project::tryFromKey($project);

        // A typo'd key in a route definition must fail closed, not open.
        if ($resolved === null) {
            abort(500, sprintf('Unknown project "%s".', $project));
        }

        if (!$user->canAccessProject($resolved)) {
            return LockedDoor::response($request, Door::forProject($resolved));
        }

        return $next($request);
    }
}
