<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\KamanSupport;

use App\Http\Controllers\Controller;
use App\Http\Requests\KamanSupport\StorePosTicketMediaRequest;
use App\Http\Requests\KamanSupport\StorePosTicketRequest;
use App\Http\Requests\KamanSupport\StoreSupportTicketEventRequest;
use App\Http\Requests\KamanSupport\UpdateSupportTicketRequest;
use App\Http\Resources\KamanSupport\SupportTicketResource;
use App\Models\KamanSupport\SupportTicket;
use App\Services\KamanSupport\SupportTicketService;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $tickets,
    ) {}

    public function store(StorePosTicketRequest $request): JsonResponse
    {
        $result = $this->tickets->submitFromPos($request->payload());

        return $this->ticketJson($result['ticket'], $result['created'] ? 201 : 200);
    }

    public function storeMedia(StorePosTicketMediaRequest $request, SupportTicket $ticket): JsonResponse
    {
        $audio = $request->file('audio');
        $screen = $request->file('screen');
        $audio = $audio instanceof UploadedFile ? $audio : null;
        $screen = $screen instanceof UploadedFile ? $screen : null;

        if ($audio === null && $screen === null) {
            return $this->utf8Json([
                'ok' => false,
                'message' => 'Attach a voice recording, a screen recording, or both.',
            ], 422);
        }

        $ticket = $this->tickets->attachMedia($ticket, $audio, $screen);

        return $this->ticketJson($ticket);
    }

    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->tickets->paginate(
            status: $request->query('status'),
            restaurant: $request->query('restaurant'),
            q: $request->query('q'),
            page: $page,
            limit: $limit,
        );

        return $this->utf8Json([
            'data' => $paginator->getCollection()
                ->map(static fn (SupportTicket $ticket): array => (new SupportTicketResource($ticket))->resolve($request))
                ->values()
                ->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        $ticket->load('events');

        return $this->ticketJson($ticket);
    }

    public function update(UpdateSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $updated = $this->tickets->updateTicket(
            $ticket,
            $request->validated(),
            $request->user(),
        );

        return $this->ticketJson($updated);
    }

    public function storeEvent(StoreSupportTicketEventRequest $request, SupportTicket $ticket): JsonResponse
    {
        $this->tickets->addEvent(
            $ticket,
            \App\Enums\KamanSupport\SupportTicketEventType::from($request->validated('type')),
            $request->validated('message'),
            $request->validated('data'),
            $request->user(),
        );

        return $this->ticketJson($ticket->fresh(['events']) ?? $ticket, 201);
    }

    private function ticketJson(SupportTicket $ticket, int $status = 200): JsonResponse
    {
        $ticket->loadMissing('events');

        return $this->utf8Json(
            (new SupportTicketResource($ticket, detailed: true))->resolve(request()),
            $status,
        );
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
