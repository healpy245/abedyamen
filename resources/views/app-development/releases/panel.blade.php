<div data-modal-title="v{{ $release->version_name }}" data-modal-variant="view" class="space-y-3">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-base font-semibold text-[#2b1e11]">v{{ $release->version_name }}</h3>
                @if($release->is_latest)
                    <span class="kaman-badge border-emerald-200 bg-emerald-50 text-emerald-800">
                        @include('app-development.partials.icon', ['name' => 'check-circle'])
                        {{ __('app-development.releases.latest') }}
                    </span>
                @endif
            </div>
            @if($release->version_code)
                <p class="text-xs text-[#a78a6c]">{{ __('app-development.releases.build') }} {{ $release->version_code }}</p>
            @endif
            @if($release->title)
                <p class="mt-1 text-sm text-[#2b1e11]">{{ $release->title }}</p>
            @endif
        </div>
            <a href="{{ route('app-development.releases.download', $release) }}" class="kaman-button kaman-button--sm shrink-0">
                @include('app-development.partials.icon', ['name' => 'download'])
                {{ __('app-development.releases.download') }}
            </a>
    </div>

    <div class="grid grid-cols-2 gap-2 text-sm">
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
        <div class="col-span-2">
            <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.releases.checksum') }}</p>
            <p class="break-all font-mono text-[11px] text-[#7c6a56]">{{ $release->checksum_sha256 }}</p>
        </div>
    </div>

    @if($release->release_notes)
        <div>
            <h4 class="mb-1 text-sm font-semibold text-[#2b1e11]">{{ __('app-development.releases.notes') }}</h4>
            <div class="max-h-24 overflow-y-auto whitespace-pre-wrap rounded-lg border border-[#f1dfc5] bg-white px-3 py-2 text-sm text-[#2b1e11] kaman-scroll">{{ $release->release_notes }}</div>
        </div>
    @endif

    <div class="space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h4 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.releases.included') }}</h4>
            <p class="text-xs text-[#a78a6c]">{{ __('app-development.releases.progress', ['completed' => $ticketStatusCounts['completed'], 'qa' => $ticketStatusCounts['qa']]) }}</p>
        </div>
        <div class="max-h-28 space-y-1.5 overflow-y-auto kaman-scroll">
            @forelse($release->tickets as $ticket)
                @include('app-development.partials.ticket-row', ['ticket' => $ticket, 'meta' => $ticket->status->label()])
            @empty
                <p class="text-sm text-[#7c6a56]">{{ __('app-development.dashboard.no_recent') }}</p>
            @endforelse
        </div>
    </div>

    <div class="space-y-1">
        <h4 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.releases.downloaded_by') }}</h4>
        <div class="max-h-20 overflow-y-auto kaman-scroll">
            @forelse($downloaders as $download)
                <p class="text-sm text-[#2b1e11]">{{ $download->user?->name }} <span class="text-xs text-[#a78a6c]">{{ $download->downloaded_at?->format('d/m/Y H:i') }}</span></p>
            @empty
                <p class="text-sm text-[#7c6a56]">{{ __('app-development.releases.no_downloads') }}</p>
            @endforelse
        </div>
    </div>
</div>
