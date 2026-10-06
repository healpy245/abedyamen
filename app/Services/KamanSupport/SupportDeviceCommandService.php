<?php

declare(strict_types=1);

namespace App\Services\KamanSupport;

use App\Enums\KamanSupport\SupportCommandName;
use App\Enums\KamanSupport\SupportCommandStatus;
use App\Enums\KamanSupport\SupportTicketEventType;
use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportDeviceCommand;
use App\Models\KamanSupport\SupportDeviceLog;
use App\Models\KamanSupport\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class SupportDeviceCommandService
{
    public function __construct(
        private readonly SupportTicketService $tickets,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function queue(
        SupportDevice $device,
        SupportCommandName $name,
        array $args = [],
        ?SupportTicket $ticket = null,
        ?User $actor = null,
    ): SupportDeviceCommand {
        $this->expireStale($device);

        $open = $device->commands()
            ->whereIn('status', [
                SupportCommandStatus::Queued->value,
                SupportCommandStatus::Sent->value,
            ])
            ->count();

        if ($open >= 5) {
            throw new RuntimeException('device_busy');
        }

        $command = SupportDeviceCommand::query()->create([
            'support_device_id' => $device->id,
            'support_ticket_id' => $ticket?->id,
            'public_id' => 'CMD-'.strtoupper(Str::lower(Str::random(8))),
            'name' => $name,
            'args' => $this->cleanArgs($args),
            'status' => SupportCommandStatus::Queued,
            'actor_name' => $actor?->name,
            'expires_at' => now()->addSeconds($this->ttlSeconds()),
        ]);

        if ($ticket !== null) {
            $this->tickets->addNamedEvent(
                $ticket,
                SupportTicketEventType::CommandResult,
                $name->label().' queued',
                [
                    'id' => $command->public_id,
                    'name' => $name->value,
                    'args' => $command->args,
                    'status' => SupportCommandStatus::Queued->value,
                    'deviceId' => $device->device_id,
                ],
                $actor === null ? 'workspace' : 'worker',
                $actor?->name ?? 'workspace',
            );
        }

        return $command;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function claimPending(SupportDevice $device, int $limit = 1): array
    {
        $this->expireStale($device);

        $inFlight = $device->commands()
            ->where('status', SupportCommandStatus::Sent->value)
            ->exists();
        if ($inFlight) {
            return [];
        }

        $queued = $device->commands()
            ->with('ticket')
            ->where('status', SupportCommandStatus::Queued->value)
            ->orderBy('id')
            ->limit(max(1, min(2, $limit)))
            ->get();

        $out = [];
        foreach ($queued as $command) {
            $command->forceFill([
                'status' => SupportCommandStatus::Sent,
                'sent_at' => now(),
            ])->save();
            $out[] = $command->toPosPayload();
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function complete(SupportDevice $device, array $rows): int
    {
        $accepted = 0;
        foreach (array_slice($rows, 0, 8) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = trim((string) ($row['id'] ?? $row['commandId'] ?? $row['command_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $command = $device->commands()->where('public_id', $id)->first();
            if ($command === null || ! ($command->status?->isOpen() ?? false)) {
                continue;
            }

            $ok = $row['ok'] ?? $row['success'] ?? true;
            $ok = filter_var($ok, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $ok = $ok ?? true;
            $result = is_array($row['result'] ?? null) ? $row['result'] : ['raw' => $row['result'] ?? null];

            $command->forceFill([
                'status' => $ok ? SupportCommandStatus::Done : SupportCommandStatus::Failed,
                'result' => $result,
                'completed_at' => now(),
            ])->save();
            $accepted++;

            $this->recordResultLog($device, $command, $ok, $result);

            $ticket = $command->ticket;
            if ($ticket instanceof SupportTicket) {
                $name = $command->name instanceof SupportCommandName ? $command->name->value : (string) $command->name;
                $this->tickets->addNamedEvent(
                    $ticket,
                    SupportTicketEventType::CommandResult,
                    $name.': '.($ok ? 'ok' : 'failed'),
                    [
                        'id' => $command->public_id,
                        'name' => $name,
                        'ok' => $ok,
                        'result' => $result,
                    ],
                    'pos',
                    'pos',
                );
            }
        }

        return $accepted;
    }

    /**
     * @return Collection<int, SupportDeviceCommand>
     */
    public function recent(SupportDevice $device, int $limit = 8): Collection
    {
        return $device->commands()
            ->orderByDesc('id')
            ->limit(max(1, min(20, $limit)))
            ->get();
    }

    public function resolveTicketDevice(SupportTicket $ticket): ?SupportDevice
    {
        $restaurant = trim((string) $ticket->restaurant);
        $deviceId = trim((string) $ticket->device_id);

        if ($restaurant !== '' && $deviceId !== '') {
            $device = SupportDevice::query()
                ->where('restaurant', $restaurant)
                ->where('device_id', $deviceId)
                ->first();
            if ($device !== null) {
                return $device;
            }
        }

        if ($restaurant === '') {
            return null;
        }

        return SupportDevice::query()
            ->where('restaurant', $restaurant)
            ->where('app_state', '!=', 'closed')
            ->orderByDesc('last_seen_at')
            ->first();
    }

    public function ttlSeconds(): int
    {
        return max(60, (int) config('kaman-support.command_ttl_seconds', 120));
    }

    private function expireStale(SupportDevice $device): void
    {
        $device->commands()
            ->whereIn('status', [
                SupportCommandStatus::Queued->value,
                SupportCommandStatus::Sent->value,
            ])
            ->where('expires_at', '<=', now())
            ->update([
                'status' => SupportCommandStatus::Expired->value,
                'completed_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    private function cleanArgs(array $args): ?array
    {
        $clean = [];
        foreach ($args as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }
            if ($value === null) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordResultLog(SupportDevice $device, SupportDeviceCommand $command, bool $ok, array $result): void
    {
        $name = $command->name instanceof SupportCommandName ? $command->name->value : (string) $command->name;
        $summary = trim((string) ($result['summary'] ?? $result['message'] ?? ''));
        $message = $name.' '.($ok ? 'ok' : 'failed');
        if ($summary !== '') {
            $message .= ': '.$summary;
        }

        SupportDeviceLog::query()->create([
            'support_device_id' => $device->id,
            'client_id' => $command->public_id,
            'level' => $ok ? 'info' : 'warn',
            'source' => 'job',
            'message' => mb_substr($message, 0, 4000),
            'context' => [
                'id' => $command->public_id,
                'name' => $name,
                'ok' => $ok,
                'result' => $result,
            ],
            'logged_at' => now(),
        ]);

        $device->forceFill([
            'log_count' => SupportDeviceLog::query()->where('support_device_id', $device->id)->count(),
        ])->save();
    }
}
