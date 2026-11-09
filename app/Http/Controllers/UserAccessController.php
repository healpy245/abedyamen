<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Door;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Yamen's users management page: every user, what they can open, and the switches to change it.
 */
class UserAccessController extends Controller
{
    public function index(): View
    {
        $users = User::query()->orderByRaw('disabled_at IS NOT NULL')->orderBy('name')->get();

        return view('workspace.access.index', [
            'rows' => $users->map(fn (User $user): array => $this->row($user))->all(),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'door' => ['required', Rule::enum(Door::class)],
            'sub_door' => ['nullable', 'string', 'max:40'],
            'on' => ['required', 'boolean'],
        ]);

        $door = Door::from($data['door']);
        $subDoor = $data['sub_door'] ?? null;
        abort_if($subDoor !== null && ! $door->hasSubDoor($subDoor), 422);

        $fixed = $subDoor === null ? $user->fixedDoorState($door) : ($user->fixedDoorState($door) ?? $user->fixedSubDoorState($door, $subDoor));
        if ($fixed !== null || $user->isTurnedOff()) {
            return response()->json(['message' => __('access.fixed_refused'), 'row' => $this->row($user)], 422);
        }

        $user->setDoorAccess($door, $subDoor, (bool) $data['on']);

        return response()->json(['message' => __('access.saved'), 'row' => $this->row($user->refresh())]);
    }

    /**
     * Turns an account on or off. Its doors, password and everything it owns stay as they are.
     */
    public function account(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);

        if ($user->managesUsers()) {
            return response()->json(['message' => __('access.fixed_refused'), 'row' => $this->row($user)], 422);
        }

        $user->forceFill(['disabled_at' => $data['on'] ? null : now()])->save();

        return response()->json(['message' => __('access.saved'), 'row' => $this->row($user->refresh())]);
    }

    /**
     * @return array{id: int, name: string, email: string, initials: string, role: string, note: ?string, url: string, accountUrl: ?string, turnedOff: bool, doors: array<string, array<string, mixed>>}
     */
    public function row(User $user): array
    {
        $off = $user->isTurnedOff();
        $doors = [];
        foreach (Door::cases() as $door) {
            $on = ! $off && $user->canOpenDoor($door);
            $doorFixed = $off || $user->fixedDoorState($door) !== null;
            $subs = [];
            foreach ($door->subDoors() as $key => $sub) {
                $subFixed = $doorFixed || $user->fixedSubDoorState($door, $key) !== null;
                $subs[$key] = [
                    'label' => $sub['label'],
                    'icon' => $sub['icon'],
                    // The switch keeps its position while the door is closed, so reopening the door restores it.
                    'on' => $user->subDoorSetting($door, $key),
                    'open' => ! $off && $user->canOpenSubDoor($door, $key),
                    'fixed' => $subFixed,
                ];
            }
            $doors[$door->value] = [
                'label' => $door->label(),
                'icon' => $door->icon(),
                'on' => $on,
                'fixed' => $doorFixed,
                'subs' => $subs,
                'openCount' => count(array_filter($subs, static fn (array $sub): bool => $sub['open'])),
            ];
        }

        return [
            'id' => $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'initials' => collect(preg_split('/\s+/', trim((string) $user->name)) ?: [])
                ->filter()
                ->take(2)
                ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                ->implode(''),
            'role' => match (true) {
                (bool) $user->is_admin => __('access.role_admin'),
                default => __('access.role_member'),
            },
            'note' => match (true) {
                $user->managesUsers() => __('access.note_manager'),
                $user->limitedSupportConsole() => __('access.note_limited'),
                $user->supportProduct() === 'mall' => __('access.note_cashier'),
                default => null,
            },
            'url' => route('workspace.users.access', $user),
            'accountUrl' => $user->managesUsers() ? null : route('workspace.users.account', $user),
            'turnedOff' => $off,
            'doors' => $doors,
        ];
    }
}
