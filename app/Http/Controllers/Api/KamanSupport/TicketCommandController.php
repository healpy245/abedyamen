<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\KamanSupport;

use App\Enums\KamanSupport\SupportCommandName;
use App\Http\Controllers\Controller;
use App\Http\Requests\KamanSupport\StoreSupportTicketCommandRequest;
use App\Models\KamanSupport\SupportTicket;
use App\Services\KamanSupport\SupportDeviceCommandService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class TicketCommandController extends Controller
{
    public function __construct(
        private readonly SupportDeviceCommandService $commands,
    ) {}

    public function store(StoreSupportTicketCommandRequest $request, SupportTicket $ticket): JsonResponse
    {
        $device = $this->commands->resolveTicketDevice($ticket);
        if ($device === null) {
            return $this->utf8Json([
                'ok' => false,
                'error' => 'device_offline',
                'message' => 'No live POS device is linked to this ticket yet.',
                'ticketId' => $ticket->public_id,
                'allowlisted' => SupportCommandName::values(),
            ], 409);
        }

        try {
            $command = $this->commands->queue(
                $device,
                SupportCommandName::from($request->validated('name')),
                $request->validated('args') ?? [],
                $ticket,
                $request->user(),
            );
        } catch (RuntimeException $error) {
            return $this->utf8Json([
                'ok' => false,
                'error' => $error->getMessage(),
                'message' => 'This device already has queued printer jobs. Wait for the next heartbeat.',
                'ticketId' => $ticket->public_id,
            ], 409);
        }

        return $this->utf8Json([
            'ok' => true,
            'id' => $command->public_id,
            'ticketId' => $ticket->public_id,
            'deviceId' => $device->device_id,
            'name' => $command->name instanceof SupportCommandName ? $command->name->value : (string) $command->name,
            'status' => $command->status?->value,
            'expiresAt' => $command->expires_at?->toIso8601String(),
        ], 202);
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
