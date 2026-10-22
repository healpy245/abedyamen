<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\OfficeVision;

use App\Http\Controllers\Controller;
use App\Models\OfficeVision\Device;
use App\Models\OfficeVision\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfficeVisionController extends Controller
{
    public function pair(Request $request): JsonResponse
    {
        $key = (string) config('officevision.pairing_key');
        if ($key === '') {
            return response()->json(['message' => 'OfficeVision pairing key is not configured.'], 503);
        }

        $data = $request->validate([
            'pairing_key' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', 'in:device,viewer'],
        ]);

        if (! hash_equals($key, $data['pairing_key'])) {
            return response()->json(['message' => 'The pairing key is not valid.'], 403);
        }

        $token = Str::random(48);
        $device = Device::query()->create([
            'name' => $data['name'],
            'role' => $data['role'],
            'token_hash' => hash('sha256', $token),
        ]);

        return response()->json([
            'token' => $token,
            'device_id' => $device->id,
            'role' => $device->role,
        ], 201);
    }

    public function storeEvent(Request $request): JsonResponse
    {
        $device = $this->device($request);
        if ($device->role !== 'device') {
            return response()->json(['message' => 'Only the office PC can store events.'], 403);
        }

        $data = $request->validate([
            'event_uid' => ['required', 'string', 'max:64'],
            'camera_name' => ['required', 'string', 'max:120'],
            'event_type' => ['required', 'in:enter,exit'],
            'worker_name' => ['nullable', 'string', 'max:120'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'occurred_at' => ['required', 'date'],
            'clip' => ['nullable', 'file', 'max:51200'],
        ]);

        $existing = Event::query()
            ->where('device_id', $device->id)
            ->where('event_uid', $data['event_uid'])
            ->first();

        if ($existing !== null) {
            return response()->json($this->eventPayload($existing));
        }

        $worker = trim((string) ($data['worker_name'] ?? ''));
        if ($worker === '' || strcasecmp($worker, 'Unknown person') === 0) {
            $worker = null;
        }

        $event = new Event([
            'device_id' => $device->id,
            'event_uid' => $data['event_uid'],
            'camera_name' => $data['camera_name'],
            'event_type' => $data['event_type'],
            'worker_name' => $worker,
            'confidence' => $data['confidence'] ?? null,
            'occurred_at' => $data['occurred_at'],
        ]);

        if ($request->file('clip') !== null) {
            $this->pruneOldClips();
            $event->clip_path = $request->file('clip')->store('officevision/clips', 'local');
        }

        $event->save();

        return response()->json($this->eventPayload($event), 201);
    }

    public function events(Request $request): JsonResponse
    {
        $events = Event::query()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Event $event) => $this->eventPayload($event));

        return response()->json(['events' => $events]);
    }

    public function clip(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $event = Event::query()->findOrFail($id);
        if ($event->clip_path === null || ! Storage::disk('local')->exists($event->clip_path)) {
            return response()->json(['message' => 'Clip is not available.'], 404);
        }

        return Storage::disk('local')->response($event->clip_path, 'clip.mp4', [
            'Content-Type' => 'video/mp4',
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $device = $this->device($request);
        if ($device->role !== 'device') {
            return response()->json(['message' => 'Only the office PC can send a heartbeat.'], 403);
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
            'stream_base' => $data['stream_base'] ?? null,
            'stream_user' => $data['stream_user'] ?? null,
            'stream_password' => $data['stream_password'] ?? null,
            'turn_url' => $data['turn_url'] ?? null,
            'turn_username' => $data['turn_username'] ?? null,
            'turn_credential' => $data['turn_credential'] ?? null,
            'cameras' => $data['cameras'],
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['ok' => true]);
    }

    public function cameras(): JsonResponse
    {
        $office = $this->officeDevice();

        return response()->json([
            'cameras' => $office?->cameras ?? [],
        ]);
    }

    public function streams(): JsonResponse
    {
        $office = $this->officeDevice();
        $ice = [
            ['urls' => 'stun:stun.l.google.com:19302'],
        ];
        if ($office !== null && filled($office->turn_url)) {
            $ice[] = [
                'urls' => $office->turn_url,
                'username' => (string) $office->turn_username,
                'credential' => (string) $office->turn_credential,
            ];
        }

        return response()->json([
            'stream_base' => $office?->stream_base,
            'stream_user' => $office?->stream_user,
            'stream_password' => $office?->stream_password,
            'ice_servers' => $ice,
            'online' => $office?->last_seen_at !== null && $office->last_seen_at->gt(now()->subSeconds(45)),
        ]);
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->attributes->get('officevision_device');

        return $device;
    }

    private function officeDevice(): ?Device
    {
        return Device::query()
            ->where('role', 'device')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(Event $event): array
    {
        return [
            'id' => $event->id,
            'event_uid' => $event->event_uid,
            'camera_name' => $event->camera_name,
            'event_type' => $event->event_type,
            'worker_name' => $event->worker_name,
            'confidence' => $event->confidence,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'has_clip' => $event->clip_path !== null,
        ];
    }

    private function pruneOldClips(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subHours((int) config('officevision.clip_ttl_hours', 48))->getTimestamp();
        foreach ($disk->allFiles('officevision/clips') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }
}
