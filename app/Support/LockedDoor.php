<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Door;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a user gets from a door or sub door they cannot open. The page is built only from the door
 * catalog, so it holds nothing from the door behind it.
 */
class LockedDoor
{
    public static function response(Request $request, Door $door, ?string $subDoor = null): Response
    {
        $name = $subDoor !== null && $door->hasSubDoor($subDoor)
            ? $door->subDoors()[$subDoor]['label']
            : $door->label();

        if ($request->expectsJson()) {
            return response()->json(['message' => __('access.locked_message', ['name' => $name])], 403);
        }

        $way = $request->user()?->firstOpenDoor();

        return response()->view('workspace.access.locked', [
            'door' => $door,
            'subDoor' => $subDoor,
            'name' => $name,
            'wayOut' => $way !== null ? $request->user()->doorEntryUrl($way) : null,
        ], 403);
    }
}
