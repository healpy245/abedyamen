<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\KamanSupport;

use App\Http\Controllers\Controller;
use App\Http\Requests\KamanSupport\StoreDeviceLogsRequest;
use App\Http\Requests\KamanSupport\StoreDeviceSessionRequest;
use App\Services\KamanSupport\SupportDeviceCommandService;
use App\Services\KamanSupport\SupportDeviceSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceSessionController extends Controller
{
    public function __construct(
        private readonly SupportDeviceSessionService $sessions,
        private readonly SupportDeviceCommandService $commands,
    ) {}

    public function info(): JsonResponse
    {
        return $this->utf8Json([
            'ok' => true,
            'service' => 'kaman-support-live',
            'heartbeatSeconds' => 15,
            'liveWindowSeconds' => $this->sessions->liveWindowSeconds(),
            'path' => '/api/kaman-support/v1/devices/session',
            'logsPath' => '/api/kaman-support/v1/devices/logs',
            'commands' => config('kaman-support.allowlisted_commands'),
        ]);
    }

    public function store(StoreDeviceSessionRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $device = $this->sessions->heartbeat($payload);
        $this->commands->complete($device, $this->commandResults($payload));
        $watched = $this->sessions->isWatched($device);
        $pending = $device->app_state === 'closed'
            ? []
            : $this->commands->claimPending($device, $watched ? 2 : 1);

        return $this->utf8Json([
            'ok' => true,
            'id' => $device->id,
            'deviceId' => $device->device_id,
            'restaurant' => $device->restaurant,
            'appState' => $device->app_state,
            'online' => $device->isLive(),
            'watch' => $watched,
            'pollMs' => $this->sessions->watchPollMs($device),
            'logCount' => $device->log_count,
            'lastSeenAt' => $device->last_seen_at?->toIso8601String(),
            'commands' => $pending,
        ]);
    }

    public function storeLogs(StoreDeviceLogsRequest $request): JsonResponse
    {
        $device = $this->sessions->heartbeat([
            ...$request->payload(),
            'appState' => 'open',
        ]);

        return $this->utf8Json([
            'ok' => true,
            'id' => $device->id,
            'deviceId' => $device->device_id,
            'accepted' => $device->log_count,
            'logCount' => $device->log_count,
            'lastSeenAt' => $device->last_seen_at?->toIso8601String(),
        ]);
    }

    public function close(Request $request): JsonResponse
    {
        $payload = $request->all();
        $payload['appState'] = 'closed';
        if (empty($payload['deviceId']) && empty($payload['device_id'])) {
            return $this->utf8Json([
                'ok' => false,
                'error' => 'validation',
                'message' => 'deviceId is required.',
            ], 422);
        }

        $device = $this->sessions->heartbeat($payload);

        return $this->utf8Json([
            'ok' => true,
            'id' => $device->id,
            'deviceId' => $device->device_id,
            'appState' => $device->app_state,
            'online' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function commandResults(array $payload): array
    {
        $rows = $payload['commandResults'] ?? $payload['command_results'] ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function utf8Json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json(
            $payload,
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
