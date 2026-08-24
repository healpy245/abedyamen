@extends('app-development.layouts.project')

@section('title', 'v'.$release->version_name)

@section('app')
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-[#2b1e11]">v{{ $release->version_name }}</h1>
                @if($release->is_latest)
                    <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800">{{ __('app-development.releases.latest') }}</span>
                @endif
            </div>
            @if($release->version_code)
                <p class="text-sm text-[#a78a6c]">{{ __('app-development.releases.build') }} {{ $release->version_code }}</p>
            @endif
            @if($release->title)
                <p class="mt-1 text-sm text-[#2b1e11]">{{ $release->title }}</p>
            @endif
        </div>
        <a href="{{ route('app-development.releases.download', $release) }}" class="kaman-button kaman-button--sm">{{ __('app-development.releases.download') }}</a>
    </div>

    <section class="kaman-card kaman-card--compact grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div>
            <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.releases.uploaded_by', ['name' => '']) }}</p>
            <p class="font-medium text-[#2b1e11]">{{ $release->uploader?->name }}</p>
        </div>
        <div>
            <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.tickets.created_at') }}</p>
            <p class="font-medium text-[#2b1e11]">{{ $release->created_at?->format('d/m/Y H:i') }}</p>
        </div>
        <div>
            <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.releases.size') }}</p>
            <p class="font-medium text-[#2b1e11]">{{ $release->humanSize() }}</p>
        </div>
        <div class="col-span-2 sm:col-span-4">
            <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.releases.checksum') }}</p>
            <p class="break-all font-mono text-xs text-[#7c6a56]">{{ $release->checksum_sha256 }}</p>
        </div>
    </section>

    @if($release->release_notes)
        <section class="kaman-card kaman-card--pad">
            <h2 class="mb-2 text-sm font-semibold text-[#2b1e11]">{{ __('app-development.releases.notes') }}</h2>
            <div class="whitespace-pre-wrap text-sm text-[#2b1e11]">{{ $release->release_notes }}</div>
        </section>
    @endif

    <section class="kaman-card kaman-card--compact space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.releases.included') }}</h2>
            <p class="text-xs text-[#a78a6c]">{{ __('app-development.releases.progress', ['completed' => $ticketStatusCounts['completed'], 'qa' => $ticketStatusCounts['qa']]) }}</p>
        </div>
        @forelse($release->tickets as $ticket)
            @include('app-development.partials.ticket-row', ['ticket' => $ticket, 'meta' => $ticket->status->label()])
        @empty
            <p class="text-sm text-[#7c6a56]">{{ __('app-development.dashboard.no_recent') }}</p>
        @endforelse
    </section>

    <section class="kaman-card kaman-card--compact space-y-2">
        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.releases.downloaded_by') }}</h2>
        @forelse($downloaders as $download)
            <p class="text-sm text-[#2b1e11]">{{ $download->user?->name }} <span class="text-xs text-[#a78a6c]">{{ $download->downloaded_at?->format('d/m/Y H:i') }}</span></p>
        @empty
            <p class="text-sm text-[#7c6a56]">{{ __('app-development.releases.no_downloads') }}</p>
        @endforelse
    </section>
@endsection
