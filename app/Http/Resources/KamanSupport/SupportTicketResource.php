<?php

declare(strict_types=1);

namespace App\Http\Resources\KamanSupport;

use App\Models\KamanSupport\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        $resource,
        private readonly bool $detailed = false,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportTicket $ticket */
        $ticket = $this->resource;

        $data = [
            'id' => $ticket->public_id,
            'clientTicketId' => $ticket->public_id,
            'status' => $ticket->status?->value,
            'summary' => $ticket->summary,
            'details' => $ticket->details,
            'restaurant' => $ticket->restaurant,
            'restaurantName' => $ticket->restaurant_name,
            'cashierName' => $ticket->cashier_name,
            'deviceId' => $ticket->device_id,
            'locale' => $ticket->locale,
            'assignee' => $ticket->assignee,
            'createdAt' => optional($ticket->client_created_at ?? $ticket->created_at)?->toIso8601String(),
            'receivedAt' => $ticket->created_at?->toIso8601String(),
            'updatedAt' => $ticket->updated_at?->toIso8601String(),
            'conversation' => $ticket->conversation ?? [],
            'diagnosis' => $ticket->diagnosis ?? new \stdClass,
            'printers' => $ticket->printers ?? [],
        ];

        if (is_array($ticket->extra) && $ticket->extra !== []) {
            $data['extra'] = $ticket->extra;
        }

        if ($this->detailed) {
            $data['events'] = $ticket->events->map(static fn ($event): array => [
                'id' => $event->id,
                'type' => $event->type?->value,
                'message' => $event->message,
                'data' => $event->data ?? new \stdClass,
                'actorType' => $event->actor_type,
                'actorName' => $event->actor_name,
                'createdAt' => $event->created_at?->toIso8601String(),
            ])->all();
        }

        return $data;
    }
}
