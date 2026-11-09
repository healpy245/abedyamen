<?php

declare(strict_types=1);

namespace App\Services\KamanSupport;

use App\Enums\KamanSupport\SupportTicketEventType;
use App\Enums\KamanSupport\SupportTicketStatus;
use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportTicket;
use App\Models\KamanSupport\SupportTicketEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupportTicketService
{
    /**
     * @var list<string>
     */
    private const KNOWN_FIELDS = [
        'clientTicketId',
        'createdAt',
        'summary',
        'details',
        'restaurant',
        'restaurantName',
        'cashierName',
        'deviceId',
        'locale',
        'conversation',
        'diagnosis',
        'printers',
    ];

    public function __construct(
        private readonly SupportTicketIdGenerator $ids,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ticket: SupportTicket, created: bool}
     */
    public function submitFromPos(array $payload): array
    {
        $restaurant = $this->stringValue($payload['restaurant'] ?? '');
        $clientId = $this->ids->normalize(isset($payload['clientTicketId']) ? (string) $payload['clientTicketId'] : null);

        if ($clientId !== null) {
            $existing = SupportTicket::query()
                ->where('restaurant', $restaurant)
                ->where('public_id', $clientId)
                ->first();

            if ($existing !== null) {
                $this->touchDevice($existing);
                $this->logSubmit($existing, idempotent: true);

                return ['ticket' => $existing->load('events'), 'created' => false];
            }
        }

        $publicId = $clientId ?? $this->ids->generate();

        try {
            $ticket = DB::transaction(function () use ($payload, $restaurant, $publicId): SupportTicket {
                $ticket = SupportTicket::query()->create([
                    'public_id' => $publicId,
                    'restaurant' => $restaurant,
                    'restaurant_name' => $this->nullableString($payload['restaurantName'] ?? null),
                    'cashier_name' => $this->nullableString($payload['cashierName'] ?? null),
                    'device_id' => $this->nullableString($payload['deviceId'] ?? null),
                    'locale' => $this->nullableString($payload['locale'] ?? null, 16),
                    'summary' => mb_substr(trim((string) ($payload['summary'] ?? '')), 0, 2000),
                    'details' => $this->nullableText($payload['details'] ?? null),
                    'conversation' => $this->jsonValue($payload['conversation'] ?? null),
                    'diagnosis' => $this->jsonValue($payload['diagnosis'] ?? null),
                    'printers' => $this->jsonValue($payload['printers'] ?? null),
                    'extra' => $this->extraFields($payload),
                    'status' => SupportTicketStatus::Open,
                    'client_created_at' => $this->parseClientTime($payload['createdAt'] ?? null),
                ]);

                $this->recordEvent(
                    $ticket,
                    SupportTicketEventType::Created,
                    $ticket->summary,
                    ['source' => 'pos'],
                    'pos',
                    $ticket->cashier_name,
                );

                if (is_array($ticket->diagnosis) && $ticket->diagnosis !== []) {
                    $cause = $this->stringValue($ticket->diagnosis['cause'] ?? '');
                    $this->recordEvent(
                        $ticket,
                        SupportTicketEventType::Diagnosis,
                        $cause !== '' ? $cause : null,
                        $ticket->diagnosis,
                        'pos',
                        $ticket->cashier_name,
                    );
                }

                $this->touchDevice($ticket);

                return $ticket;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            if (! $this->isDuplicatePublicId($e)) {
                throw $e;
            }

            $existing = SupportTicket::query()
                ->where('restaurant', $restaurant)
                ->where('public_id', $publicId)
                ->firstOrFail();

            $this->touchDevice($existing);
            $this->logSubmit($existing, idempotent: true);

            return ['ticket' => $existing->load('events'), 'created' => false];
        }

        $this->logSubmit($ticket, idempotent: false);

        return ['ticket' => $ticket->load('events'), 'created' => true];
    }

    /**
     * @return LengthAwarePaginator<int, SupportTicket>
     */
    public function paginate(?string $status, ?string $restaurant, ?string $q, int $page, int $limit): LengthAwarePaginator
    {
        $limit = max(1, min(100, $limit));
        $page = max(1, $page);

        $query = SupportTicket::query()->visibleTo(auth()->user())->orderByDesc('id');

        if ($status !== null && $status !== '') {
            $resolved = SupportTicketStatus::tryFrom($status);
            if ($resolved !== null) {
                $query->where('status', $resolved);
            }
        }

        if ($restaurant !== null && $restaurant !== '') {
            $query->where('restaurant', $restaurant);
        }

        $query->search($q);

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * @param  array{status?: string, assignee?: string, note?: string}  $changes
     */
    public function updateTicket(SupportTicket $ticket, array $changes, User $actor): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $changes, $actor): SupportTicket {
            $ticket->refresh();

            if (isset($changes['status']) && $changes['status'] !== '') {
                $status = SupportTicketStatus::from($changes['status']);
                if ($ticket->status !== $status) {
                    $from = $ticket->status?->value;
                    $ticket->status = $status;
                    $this->recordEvent(
                        $ticket,
                        SupportTicketEventType::StatusChanged,
                        sprintf('%s → %s', $from ?? 'unknown', $status->value),
                        ['from' => $from, 'to' => $status->value],
                        'worker',
                        $actor->name,
                    );
                }
            }

            if (array_key_exists('assignee', $changes)) {
                $assignee = $this->nullableString($changes['assignee'] ?? null);
                if ($ticket->assignee !== $assignee) {
                    $from = $ticket->assignee;
                    $ticket->assignee = $assignee;
                    $this->recordEvent(
                        $ticket,
                        SupportTicketEventType::AssigneeChanged,
                        $assignee !== null ? $assignee : ($from ?? ''),
                        ['from' => $from, 'to' => $assignee],
                        'worker',
                        $actor->name,
                    );
                }
            }

            $ticket->save();

            $note = $this->nullableText($changes['note'] ?? null);
            if ($note !== null) {
                $this->addEvent(
                    $ticket,
                    SupportTicketEventType::Note,
                    $note,
                    ['internal' => true],
                    $actor,
                );
            }

            return $ticket->fresh(['events']) ?? $ticket;
        });
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function addEvent(
        SupportTicket $ticket,
        SupportTicketEventType $type,
        ?string $message,
        ?array $data,
        User $actor,
    ): SupportTicketEvent {
        return $this->recordEvent($ticket, $type, $message, $data, 'worker', $actor->name);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function addNamedEvent(
        SupportTicket $ticket,
        SupportTicketEventType $type,
        ?string $message,
        ?array $data,
        string $actorType,
        ?string $actorName,
    ): SupportTicketEvent {
        return $this->recordEvent($ticket, $type, $message, $data, $actorType, $actorName);
    }

    public function attachMedia(SupportTicket $ticket, ?UploadedFile $audio, ?UploadedFile $screen): SupportTicket
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', $ticket->public_id) ?: 'ticket';
        $dir = 'kaman-support/'.$id;
        $extra = is_array($ticket->extra) ? $ticket->extra : [];

        if ($audio !== null) {
            $ext = strtolower($audio->getClientOriginalExtension() ?: 'wav');
            if (! in_array($ext, ['wav', 'm4a', 'aac', 'mp3', 'ogg'], true)) {
                $ext = 'wav';
            }
            $stored = $audio->storeAs($dir, 'voice.'.$ext, 'local');
            if (! is_string($stored) || $stored === '') {
                throw new \RuntimeException('Could not store the voice recording.');
            }
            $extra['audioPath'] = $stored;
        }

        if ($screen !== null) {
            $stored = $screen->storeAs($dir, 'screen.kscr', 'local');
            if (! is_string($stored) || $stored === '') {
                throw new \RuntimeException('Could not store the screen recording.');
            }
            $extra['screenPath'] = $stored;
        }

        $ticket->extra = $extra;
        $ticket->save();
        $ticket->refresh();

        $this->addNamedEvent(
            $ticket,
            SupportTicketEventType::Note,
            'recordings attached',
            [
                'voice' => $ticket->hasVoice(),
                'screen' => $ticket->hasScreen(),
            ],
            'pos',
            $ticket->cashier_name,
        );

        return $ticket;
    }

    public function touchDevice(SupportTicket $ticket): void
    {
        $deviceId = trim((string) $ticket->device_id);
        $restaurant = $ticket->restaurant;

        if ($deviceId === '' || $restaurant === '') {
            return;
        }

        SupportDevice::query()->updateOrCreate(
            [
                'restaurant' => $restaurant,
                'device_id' => $deviceId,
            ],
            [
                'last_ticket_id' => $ticket->public_id,
                'last_seen_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function recordEvent(
        SupportTicket $ticket,
        SupportTicketEventType $type,
        ?string $message,
        ?array $data,
        string $actorType,
        ?string $actorName,
    ): SupportTicketEvent {
        return $ticket->events()->create([
            'type' => $type,
            'message' => $message,
            'data' => $data,
            'actor_type' => $actorType,
            'actor_name' => $actorName,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extraFields(array $payload): ?array
    {
        $extra = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, self::KNOWN_FIELDS, true)) {
                continue;
            }

            $extra[$key] = $value;
        }

        return $extra === [] ? null : $extra;
    }

    private function logSubmit(SupportTicket $ticket, bool $idempotent): void
    {
        Log::info('kaman-support.ticket.submitted', [
            'id' => $ticket->public_id,
            'restaurant' => $ticket->restaurant,
            'summary' => Str::limit($ticket->summary, 80, ''),
            'idempotent' => $idempotent,
        ]);
    }

    private function stringValue(mixed $value): string
    {
        return mb_substr(trim((string) $value), 0, 120);
    }

    private function nullableString(mixed $value, int $max = 191): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @return array<mixed>|null
     */
    private function jsonValue(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function parseClientTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isDuplicatePublicId(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'support_tickets_restaurant_public_id_unique')
            || str_contains($message, 'UNIQUE constraint failed: support_tickets.restaurant')
            || (int) $e->errorInfo[1] === 1062;
    }
}
