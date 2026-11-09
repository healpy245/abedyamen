<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\UserAccessController;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UsersController extends Controller
{
    public function index(UserAccessController $access): JsonResponse
    {
        return response()->json([
            'users' => User::query()->orderBy('name')->get()
                ->map(fn (User $user): array => $access->row($user))
                ->values()
                ->all(),
        ]);
    }
}
