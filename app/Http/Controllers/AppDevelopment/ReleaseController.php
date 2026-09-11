<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Enums\AppDevelopmentTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\StoreReleaseRequest;
use App\Models\AppDevelopment\AppDevelopmentRelease;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Services\AppDevelopment\ReleaseService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReleaseController extends Controller
{
    use RendersAppDevelopmentModal;

    public function __construct(
        private readonly ReleaseService $releases,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', AppDevelopmentRelease::class);

        $releases = AppDevelopmentRelease::query()
            ->with(['uploader:id,name'])
            ->withCount('tickets')
            ->latest()
            ->paginate(15);

        return view('app-development.releases.index', [
            'releases' => $releases,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('uploadRelease', AppDevelopmentRelease::class);

        $tickets = AppDevelopmentTicket::query()
            ->whereIn('status', [
                AppDevelopmentTicketStatus::Working,
                AppDevelopmentTicketStatus::Qa,
                AppDevelopmentTicketStatus::Open,
            ])
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get(['id', 'ticket_number', 'title', 'status']);

        return $this->appDevelopmentModal(
            $request,
            'app-development.releases.form-create',
            ['tickets' => $tickets],
            __('app-development.releases.upload'),
            'form',
            route('app-development.releases.index'),
        );
    }

    public function store(StoreReleaseRequest $request): RedirectResponse
    {
        $release = $this->releases->upload(
            $request->user(),
            $request->file('apk'),
            $request->validated('version_name'),
            $request->filled('version_code') ? (int) $request->validated('version_code') : null,
            $request->validated('title'),
            $request->validated('release_notes'),
            array_map('intval', $request->validated('ticket_ids', []) ?? []),
        );

        return redirect()
            ->route('app-development.releases.show', $release)
            ->with('success', __('app-development.flash.release_uploaded'));
    }

    public function show(Request $request, AppDevelopmentRelease $release): View
    {
        $this->authorize('view', $release);

        $release->load([
            'uploader:id,name',
            'tickets.assignedDeveloper:id,name',
            'downloads.user:id,name',
        ]);

        $ticketStatusCounts = [
            'completed' => $release->tickets->where('status', AppDevelopmentTicketStatus::Completed)->count(),
            'qa' => $release->tickets->where('status', AppDevelopmentTicketStatus::Qa)->count(),
            'working' => $release->tickets->where('status', AppDevelopmentTicketStatus::Working)->count(),
            'open' => $release->tickets->where('status', AppDevelopmentTicketStatus::Open)->count(),
        ];

        $downloaders = $release->downloads
            ->unique('user_id')
            ->values();

        return $this->appDevelopmentModal(
            $request,
            'app-development.releases.panel',
            [
                'release' => $release,
                'ticketStatusCounts' => $ticketStatusCounts,
                'downloaders' => $downloaders,
            ],
            'v'.$release->version_name,
            'view',
            route('app-development.releases.index'),
        );
    }

    public function download(AppDevelopmentRelease $release): StreamedResponse
    {
        $this->authorize('downloadRelease', $release);

        return $this->releases->download($release, request()->user());
    }
}
