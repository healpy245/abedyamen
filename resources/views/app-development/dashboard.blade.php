@extends('app-development.layouts.project')

@section('title', __('app-development.dashboard.title'))

@section('app')
    @php
        $user = auth()->user();
    @endphp

    <section class="hero-panel hero-panel--compact">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-xl sm:text-2xl font-semibold text-[#2b1e11]">{{ __('app-development.dashboard.title') }}</h1>
                <p class="mt-1 text-sm text-[#7c6a56]">{{ __('app-development.dashboard.subtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($user->isQa() && ($statusCounts['qa'] ?? 0) > 0)
                    <a href="{{ route('app-development.qa.index') }}" class="rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-800">
                        {{ __('app-development.dashboard.waiting_banner', ['count' => $statusCounts['qa']]) }}
                    </a>
                @endif
                @if($user->isDeveloper() && $returnedCount > 0)
                    <a href="{{ route('app-development.tickets.index', ['tab' => 'returned']) }}" class="rounded-full border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-800">
                        {{ __('app-development.dashboard.returned_banner', ['count' => $returnedCount]) }}
                    </a>
                @endif
            </div>
        </div>
    </section>

    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach([
            ['open', 'dashboard.open', 'app-development.tickets.index', ['tab' => 'open']],
            ['working', 'dashboard.working', 'app-development.tickets.index', ['tab' => 'working']],
            ['qa', 'dashboard.waiting_qa', $user->isQa() ? 'app-development.qa.index' : 'app-development.tickets.index', $user->isQa() ? [] : ['tab' => 'qa']],
            ['completed', 'dashboard.completed', 'app-development.tickets.index', ['tab' => 'completed']],
        ] as [$key, $label, $route, $params])
            <a href="{{ route($route, $params) }}" class="kaman-card kaman-card--compact kaman-card--link">
                <p class="text-xs font-semibold uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.'.$label) }}</p>
                <p class="mt-1 text-2xl font-semibold text-[#2b1e11]">{{ $statusCounts[$key] ?? 0 }}</p>
            </a>
        @endforeach
    </section>

    @if($user->isDeveloper())
        <section class="grid grid-cols-1 gap-3 lg:grid-cols-3">
            <div class="kaman-card kaman-card--compact space-y-2">
                <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.dashboard.my_working') }}</h2>
                @forelse($myTickets as $ticket)
                    @include('app-development.partials.ticket-row', ['ticket' => $ticket, 'meta' => $ticket->creator?->name])
                @empty
                    <p class="text-sm text-[#7c6a56]">{{ __('app-development.dashboard.no_recent') }}</p>
                @endforelse
            </div>
            <div class="kaman-card kaman-card--compact space-y-2">
                <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.dashboard.available') }}</h2>
                @forelse($availableTickets as $ticket)
                    @include('app-development.partials.ticket-row', ['ticket' => $ticket, 'meta' => $ticket->creator?->name])
                @empty
                    <p class="text-sm text-[#7c6a56]">{{ __('app-development.tickets.empty_open') }}</p>
                @endforelse
            </div>
            <div class="kaman-card kaman-card--compact space-y-2">
                <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.dashboard.returned') }}</h2>
                @forelse($returnedTickets as $ticket)
                    @include('app-development.partials.ticket-row', ['ticket' => $ticket, 'meta' => $ticket->latestQaRejection?->note()])
                @empty
                    <p class="text-sm text-[#7c6a56]">{{ __('app-development.dashboard.no_recent') }}</p>
                @endforelse
            </div>
        </section>
    @endif

    @if($user->isQa())
        <section class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <div class="kaman-card kaman-card--compact space-y-2">
                <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.nav.qa_queue') }}</h2>
                @forelse($waitingForQa as $ticket)
                    @include('app-development.partials.ticket-row', ['ticket' => $ticket, 'meta' => $ticket->assignedDeveloper?->name])
                @empty
                    <p class="text-sm text-[#7c6a56]">{{ __('app-development.tickets.empty_qa') }}</p>
                @endforelse
            </div>
            <div class="kaman-card kaman-card--compact space-y-2">
                <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.dashboard.recently_completed') }}</h2>
                @forelse($recentlyCompleted as $ticket)
                    @include('app-development.partials.ticket-row', ['ticket' => $ticket, 'meta' => $ticket->completedBy?->name])
                @empty
                    <p class="text-sm text-[#7c6a56]">{{ __('app-development.dashboard.no_recent') }}</p>
                @endforelse
            </div>
        </section>
    @endif

    <section class="kaman-card kaman-card--compact space-y-2">
        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.dashboard.recently_updated') }}</h2>
        @forelse($recent as $ticket)
            @include('app-development.partials.ticket-row', ['ticket' => $ticket, 'meta' => $ticket->assignedDeveloper?->name ?? __('app-development.tickets.unassigned')])
        @empty
            <p class="text-sm text-[#7c6a56]">{{ __('app-development.dashboard.no_recent') }}</p>
        @endforelse
    </section>
@endsection
