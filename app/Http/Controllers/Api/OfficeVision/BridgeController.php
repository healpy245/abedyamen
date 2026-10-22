<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\OfficeVision;

use App\Enums\Project;
use App\Http\Controllers\Controller;
use App\Models\OfficeVision\ApiToken;
use App\Models\OfficeVision\Camera;
use App\Models\OfficeVision\Device;
use App\Models\OfficeVision\OfficeEvent;
use App\Models\OfficeVision\PairCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BridgeController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['ok' => false, 'error' => 'invalid_login'], 422);
        }

        if (! $user->canAccessProject(Project::OfficeVision)) {
            return response()->json(['ok' => false, 'error' => 'no_access'], 403);
        }

        return response()->json([
            'ok' => true,
            'token' => ApiToken::issue('user', $user->id, null),
            'name' => $user->name,
        ]);
    }

    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pairing_key' => ['required_without:code', 'string'],
            'code' => ['required_without:pairing_key', 'string'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        $plain = PairCode::normalize((string) ($data['pairing_key'] ?? $data['code']));
        $code = PairCode::query()
            ->where('code_hash', hash('sha256', $plain))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($code === null) {
            return response()->json(['ok' => false, 'error' => 'invalid_code'], 422);
        }

        $code->forceFill(['used_at' => now()])->save();
        $device = Device::query()->create([
            'name' => $data['name'] ?? 'Office PC',
        ]);

        return response()->json([
            'ok' => true,
            'token' => ApiToken::issue('device', $code->user_id, $device->id),
            'device_id' => $device->id,
        ], 201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $token = $this->token($request);
        $device = $token->device;
        if ($device === null) {
            return response()->json(['ok' => false, 'error' => 'device_missing'], 422);
        }

        $data = $request->validate([
            'stream_base' => ['nullable', 'string', 'max:200'],
            'stream_user' => ['nullable', 'string', 'max:80'],
            'stream_password' => ['nullable', 'string', 'max:200'],
            'turn_url' => ['nullable', 'string', 'max:200'],
            'turn_username' => ['nullable', 'string', 'max:120'],
            'turn_credential' => ['nullable', 'string', 'max:200'],
            'cameras' => ['required', 'array', 'max:6'],
            'cameras.*.name' => ['required', 'string', 'max:80'],
            'cameras.*.path' => ['required', 'string', 'max:40'],
            'cameras.*.online' => ['required', 'boolean'],
            'cameras.*.persons' => ['nullable', 'integer', 'min:0'],
        ]);

        $device->fill([
            'last_seen_at' => now(),
            'stream_base' => $data['stream_base'] ?? null,
            'stream_user' => $data['stream_user'] ?? null,
            'stream_password' => $data['stream_password'] ?? null,
            'turn_url' => $data['turn_url'] ?? null,
            'turn_username' => $data['turn_username'] ?? null,
            'turn_credential' => $data['turn_credential'] ?? null,
        ])->save();

        $paths = [];
        foreach (array_values($data['cameras']) as $index => $camera) {
            $paths[] = $camera['path'];
            Camera::query()->updateOrCreate(
                ['device_id' => $device->id, 'path' => $camera['path']],
                [
                    'name' => $camera['name'],
                    'online' => $camera['online'],
                    'persons' => (int) ($camera['persons'] ?? 0),
                    'sort' => $index,
                ],
            );
        }
        Camera::query()->where('device_id', $device->id)->whereNotIn('path', $paths)->delete();

        return response()->json(['ok' => true]);
    }

    public function cameras(Request $request): JsonResponse
    {
        $device = $this->visibleDevice($request);
        if ($device === null) {
            return response()->json(['ok' => true, 'cameras' => [], 'ice_servers' => $this->ice(null)]);
        }

        return response()->json([
            'ok' => true,
            'stream_base' => $device->stream_base,
            'stream_user' => $device->stream_user,
            'stream_password' => $device->stream_password,
            'ice_servers' => $this->ice($device),
            'online' => $device->last_seen_at !== null && $device->last_seen_at->gt(now()->subSeconds(45)),
            'cameras' => $device->cameras->map(fn (Camera $camera) => [
                'name' => $camera->name,
                'path' => $camera->path,
                'online' => $camera->online,
                'persons' => $camera->persons,
            ])->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $token = $this->token($request);
        $query = OfficeEvent::query()->orderByDesc('occurred_at')->orderByDesc('id');
        if ($token->kind === 'device') {
            $query->where('device_id', $token->device_id);
        }
        if ($request->filled('after')) {
            $query->where('id', '>', (int) $request->input('after'));
            $query->reorder()->orderBy('id');
        }

        $events = $query->limit(100)->get()->map(fn (OfficeEvent $event) => $this->eventPayload($event));

        return response()->json(['ok' => true, 'events' => $events]);
    }

    public function store(Request $request): JsonResponse
    {
        $token = $this->token($request);
        $device = $token->device;
        if ($device === null) {
            return response()->json(['ok' => false, 'error' => 'device_missing'], 422);
        }

        $type = strtolower((string) $request->input('event_type', $request->input('direction', '')));
        if ($type === 'entry') {
            $type = 'enter';
        }
        $request->merge(['event_type' => $type]);

        $data = $request->validate([
            'event_uid' => ['required', 'uuid'],
            'camera_name' => ['required', 'string', 'max:80'],
            'event_type' => ['required', 'in:enter,exit'],
            'worker_name' => ['nullable', 'string', 'max:80'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'occurred_at' => ['required', 'date'],
            'clip' => ['nullable', 'file', 'max:20480'],
        ]);

        $worker = trim((string) ($data['worker_name'] ?? ''));
        if ($worker === '' || strcasecmp($worker, 'Unknown person') === 0) {
            $worker = null;
        }

        $event = OfficeEvent::query()->firstOrNew(['uid' => $data['event_uid']]);
        if (! $event->exists) {
            $event->fill([
                'device_id' => $device->id,
                'camera_name' => $data['camera_name'],
                'event_type' => $data['event_type'],
                'worker_name' => $worker,
                'confidence' => $data['confidence'] ?? null,
                'occurred_at' => $data['occurred_at'],
            ]);
        } elseif ($worker !== null && $event->worker_name === null) {
            $event->worker_name = $worker;
        }

        if ($request->file('clip') !== null) {
            $this->pruneClips();
            $path = $request->file('clip')->storeAs('officevision-clips', $event->uid.'.mp4', 'local');
            $event->clip_path = $path;
        }

        $event->save();

        return response()->json(['ok' => true, 'id' => $event->id, 'event' => $this->eventPayload($event)], $event->wasRecentlyCreated ? 201 : 200);
    }

    public function clip(Request $request, string $event): StreamedResponse|JsonResponse
    {
        $token = $this->token($request);
        $record = $this->findEvent($event);
        if ($token->kind === 'device' && $record->device_id !== $token->device_id) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        if ($record->clip_path === null || ! Storage::disk('local')->exists($record->clip_path)) {
            return response()->json(['ok' => false, 'error' => 'clip_unavailable'], 404);
        }

        return Storage::disk('local')->response($record->clip_path, $record->uid.'.mp4', [
            'Content-Type' => 'video/mp4',
        ]);
    }

    private function token(Request $request): ApiToken
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('officevision_token');

        return $token;
    }

    private function visibleDevice(Request $request): ?Device
    {
        $token = $this->token($request);
        if ($token->kind === 'device') {
            return $token->device;
        }

        return Device::query()->orderByDesc('last_seen_at')->orderByDesc('id')->first();
    }

    private function findEvent(string $key): OfficeEvent
    {
        $query = OfficeEvent::query()->where('uid', $key);
        if (ctype_digit($key)) {
            $query->orWhere('id', (int) $key);
        }

        return $query->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(OfficeEvent $event): array
    {
        return [
            'id' => $event->id,
            'uid' => $event->uid,
            'worker_name' => $event->worker_name,
            'direction' => $event->event_type,
            'camera_name' => $event->camera_name,
            'confidence' => $event->confidence,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'has_clip' => $event->clip_path !== null,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function ice(?Device $device): array
    {
        $servers = [
            ['urls' => 'stun:stun.l.google.com:19302'],
        ];
        if ($device !== null && filled($device->turn_url)) {
            $servers[] = [
                'urls' => (string) $device->turn_url,
                'username' => (string) $device->turn_username,
                'credential' => (string) $device->turn_credential,
            ];
        }

        return $servers;
    }

    private function pruneClips(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subHours((int) config('officevision.clip_ttl_hours', 72))->getTimestamp();
        foreach ($disk->files('officevision-clips') as $file) {
            if ($disk->lastModified($file) >= $cutoff) {
                continue;
            }
            $disk->delete($file);
            OfficeEvent::query()->where('uid', pathinfo($file, PATHINFO_FILENAME))->update(['clip_path' => null]);
        }
    }
}
