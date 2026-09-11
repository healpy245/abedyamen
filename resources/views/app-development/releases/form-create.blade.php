<div data-modal-title="{{ __('app-development.releases.upload') }}" data-modal-variant="form" class="space-y-3">
    <form method="post" action="{{ route('app-development.releases.store') }}" enctype="multipart/form-data" class="space-y-3">
        @csrf

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.releases.version') }} *</label>
                <input type="text" name="version_name" value="{{ old('version_name') }}" required class="kaman-input w-full" placeholder="2.7.14">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.releases.version_code') }}</label>
                <input type="number" name="version_code" value="{{ old('version_code') }}" min="1" class="kaman-input w-full" placeholder="2714">
            </div>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.releases.release_title') }}</label>
            <input type="text" name="title" value="{{ old('title') }}" class="kaman-input w-full">
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.releases.notes') }}</label>
            <textarea name="release_notes" rows="3" class="kaman-input w-full">{{ old('release_notes') }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.releases.apk') }} *</label>
            <input type="file" name="apk" required accept=".apk,application/vnd.android.package-archive" class="kaman-input w-full">
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.releases.related') }}</label>
            <div class="max-h-28 overflow-y-auto rounded-xl border border-[#f1dfc5] p-2 space-y-1 kaman-scroll">
                @forelse($tickets as $ticket)
                    <label class="flex items-center gap-2 rounded-lg px-2 py-1 text-sm hover:bg-white">
                        <input type="checkbox" name="ticket_ids[]" value="{{ $ticket->id }}" @checked(in_array($ticket->id, old('ticket_ids', []), false))>
                        <span class="font-semibold text-[#f16229]">{{ $ticket->ticket_number }}</span>
                        <span class="truncate text-[#2b1e11]">{{ $ticket->title }}</span>
                    </label>
                @empty
                    <p class="px-2 py-2 text-sm text-[#7c6a56]">{{ __('app-development.dashboard.no_recent') }}</p>
                @endforelse
            </div>
        </div>

        <div class="flex justify-end gap-2 pt-1">
            <a href="{{ route('app-development.releases.index') }}" class="kaman-button-ghost" data-app-dev-modal-close>
                @include('app-development.partials.icon', ['name' => 'x'])
                {{ __('app-development.cancel') }}
            </a>
            <button type="submit" class="kaman-button">
                @include('app-development.partials.icon', ['name' => 'upload'])
                {{ __('app-development.releases.upload') }}
            </button>
        </div>
    </form>
</div>
