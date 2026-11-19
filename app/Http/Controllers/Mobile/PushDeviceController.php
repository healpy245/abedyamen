<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Enums\Door;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkspacePushDevice;
use App\Services\Push\FcmSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The phone app registers its push token here with what it wants to hear
 * about, and drops it on sign-out. One token belongs to one signed-in user.
 */
class PushDeviceController extends Controller
{
    public function store(Request $request, FcmSender $fcm): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'platform' => ['nullable', 'string', 'in:android,ios'],
            'appVersion' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'in:en,ar,he'],
            'notifyTickets' => ['nullable', 'boolean'],
            'notifyFailures' => ['nullable', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $hash = WorkspacePushDevice::hashToken($data['token']);
        $phone = WorkspacePushDevice::query()->firstOrNew(['token_hash' => $hash]);
        $phone->fill([
            'user_id' => $user->id,
            'token' => $data['token'],
            'platform' => $data['platform'] ?? 'android',
            'app_version' => $data['appVersion'] ?? null,
            'locale' => $data['locale'] ?? 'en',
            'notify_tickets' => $data['notifyTickets'] ?? $phone->notify_tickets ?? true,
            'notify_failures' => $data['notifyFailures'] ?? $phone->notify_failures ?? true,
            'last_seen_at' => now(),
        ])->save();

        return response()->json($this->state($user, $phone, $fcm));
    }

    public function destroy(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');
        if ($token !== '') {
            WorkspacePushDevice::query()
                ->where('token_hash', WorkspacePushDevice::hashToken($token))
                ->where('user_id', $request->user()?->id)
                ->delete();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(User $user, WorkspacePushDevice $phone, FcmSender $fcm): array
    {
        return [
            'ok' => true,
            'serverReady' => $fcm->enabled(),
            'notifyTickets' => $phone->notify_tickets,
            'notifyFailures' => $phone->notify_failures,
            // What this account may hear about at all.
            'canTickets' => $user->canOpenSubDoor(Door::Support, 'tickets'),
            'canFailures' => $user->canOpenSubDoor(Door::Support, 'logs'),
        ];
    }
}
