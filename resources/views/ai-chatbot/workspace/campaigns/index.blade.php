@php
    $activeProject = 'ai-chatbot';
@endphp
@extends('layouts.kaman')

@section('title', __('chatbot.workspace.campaigns.title').' — '.$instance->name)
@section('tag', __('chatbot.workspace.tag'))

@section('content')
<div class="w-full max-w-[1400px] mx-auto px-3 sm:px-4 pb-10">
    @include('ai-chatbot.workspace.partials.nav')

    @if(session('status'))
        <div class="mb-4 rounded-xl border border-green-200 bg-green-50/70 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50/70 px-4 py-3 text-sm text-red-600">{{ $errors->first() }}</div>
    @endif

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.campaigns.title') }}</h2>
            <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.leads_only_note') }}</p>
        </div>
    </div>

    @if($canManageSettings ?? false)
        <details class="kaman-card kaman-card--pad mb-5 group" @if($errors->any()) open @endif>
            <summary class="cursor-pointer list-none flex items-center justify-between gap-2">
                <span class="text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.campaigns.create') }}</span>
                <span class="text-[#f16229] text-lg leading-none group-open:rotate-45 transition">+</span>
            </summary>
            <form method="post" action="{{ route('ai-chatbot.workspace.campaigns.store', $instance) }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                @csrf
                <div>
                    <label class="kaman-label block" for="name">{{ __('chatbot.workspace.campaigns.name') }}</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="160" class="kaman-input w-full">
                </div>
                <div>
                    <label class="kaman-label block" for="greenapi_url">{{ __('chatbot.workspace.campaigns.greenapi_url') }}</label>
                    <input id="greenapi_url" name="greenapi_url" type="url" value="{{ old('greenapi_url') }}" required class="kaman-input w-full font-mono text-xs" placeholder="{{ __('chatbot.greenapi_send_url_placeholder') }}">
                    <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.greenapi_url_help') }}</p>
                    <p class="mt-1 text-xs text-amber-700">{{ __('chatbot.workspace.campaigns.separate_greenapi_note') }}</p>
                </div>
                <div>
                    <label class="kaman-label block" for="opening_message">{{ __('chatbot.workspace.campaigns.opening_message') }}</label>
                    <textarea id="opening_message" name="opening_message" rows="4" class="kaman-input w-full">{{ old('opening_message', $defaultOpeningMessage) }}</textarea>
                    <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.opening_message_help') }}</p>
                </div>
                <div>
                    <label class="kaman-label block" for="excel">{{ __('chatbot.workspace.campaigns.excel') }}</label>
                    <input id="excel" name="excel" type="file" accept=".xlsx,.csv,.txt" required class="kaman-input w-full">
                    <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.excel_help') }}</p>
                </div>
                <button type="submit" class="kaman-button">{{ __('chatbot.workspace.campaigns.create') }}</button>
            </form>
        </details>
    @endif

    @if($campaigns->isEmpty())
        <div class="kaman-card kaman-card--pad text-center py-12">
            <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-[#f1dfc5] text-[#f16229]">
                <x-workspace.icon name="megaphone" class="h-7 w-7" />
            </div>
            <p class="text-sm font-medium text-[#2b1e11]">{{ __('chatbot.workspace.campaigns.empty') }}</p>
            <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.empty_hint') }}</p>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($campaigns as $campaign)
                @php
                    $statusKey = 'chatbot.workspace.campaigns.status_'.$campaign->status;
                    $statusLabel = __($statusKey);
                    if ($statusLabel === $statusKey) {
                        $statusLabel = $campaign->status;
                    }
                @endphp
                <article class="kaman-card overflow-hidden flex flex-col">
                    <a href="{{ route('ai-chatbot.workspace.campaigns.show', [$instance, $campaign]) }}" class="block p-4 hover:bg-[#f7efe3]/50 transition">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="text-base font-semibold text-[#2b1e11] truncate">{{ $campaign->name }}</h3>
                            <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide rounded-full px-2 py-0.5 border
                                {{ $campaign->status === 'running' ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : ($campaign->status === 'stopped' ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-[#eadfce] bg-[#f7efe3] text-[#7c6a56]') }}">
                                {{ $statusLabel }}
                            </span>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                            <div class="rounded-xl bg-[#f7efe3]/80 px-3 py-2">
                                <dt class="text-[10px] uppercase tracking-wide text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.stat_leads') }}</dt>
                                <dd class="text-lg font-semibold text-[#2b1e11]">{{ $campaign->leads_stored_count }}</dd>
                            </div>
                            <div class="rounded-xl bg-[#f7efe3]/80 px-3 py-2">
                                <dt class="text-[10px] uppercase tracking-wide text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.stat_messages') }}</dt>
                                <dd class="text-lg font-semibold text-[#2b1e11]">{{ $campaign->messages_triggered_count }}</dd>
                            </div>
                            <div class="rounded-xl bg-[#f7efe3]/80 px-3 py-2">
                                <dt class="text-[10px] uppercase tracking-wide text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.stat_responded') }}</dt>
                                <dd class="text-lg font-semibold text-[#2b1e11]">{{ $campaign->responded_count }}</dd>
                            </div>
                            <div class="rounded-xl bg-[#f7efe3]/80 px-3 py-2">
                                <dt class="text-[10px] uppercase tracking-wide text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.stat_contacts') }}</dt>
                                <dd class="text-lg font-semibold text-[#2b1e11]">{{ $campaign->contacts_count }}</dd>
                            </div>
                        </dl>
                    </a>
                    <div class="px-4 pb-4 pt-0 flex flex-wrap items-center gap-2">
                        @include('ai-chatbot.workspace.campaigns.partials.bot-power-toggle', [
                            'instance' => $instance,
                            'campaign' => $campaign,
                            'canToggleBot' => $canControlBot ?? false,
                            'compact' => true,
                        ])
                        @if($canControlBot ?? false)
                            @if($campaign->canStop())
                                <form method="post" action="{{ route('ai-chatbot.workspace.campaigns.stop', [$instance, $campaign]) }}">
                                    @csrf
                                    <button type="submit" class="kaman-button-ghost !min-h-0 !py-1.5 !px-3 text-xs">{{ __('chatbot.workspace.campaigns.stop') }}</button>
                                </form>
                            @elseif($campaign->canStart())
                                <button type="button"
                                        class="kaman-button !min-h-0 !py-1.5 !px-3 text-xs"
                                        data-campaign-trigger-open
                                        data-contacts-url="{{ route('ai-chatbot.workspace.campaigns.contacts', [$instance, $campaign]) }}"
                                        data-store-contact-url="{{ route('ai-chatbot.workspace.campaigns.contacts.store', [$instance, $campaign]) }}"
                                        data-start-url="{{ route('ai-chatbot.workspace.campaigns.start', [$instance, $campaign]) }}">
                                    {{ __('chatbot.workspace.campaigns.start') }}
                                </button>
                            @endif
                        @endif
                        <a href="{{ route('ai-chatbot.workspace.campaigns.test.page', [$instance, $campaign]) }}" class="kaman-button-ghost !min-h-0 !py-1.5 !px-3 text-xs inline-flex items-center">
                            {{ __('chatbot.workspace.campaigns.test') }}
                        </a>
                        @if($canManageSettings ?? false)
                            <a href="{{ route('ai-chatbot.workspace.campaigns.settings', [$instance, $campaign]) }}" class="kaman-button-ghost !min-h-0 !py-1.5 !px-3 text-xs inline-flex items-center">
                                {{ __('chatbot.workspace.campaigns.settings') }}
                            </a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>

@once('campaign-trigger-modal')
    @include('ai-chatbot.workspace.campaigns.partials.trigger-modal')
@endonce
@pushOnce('scripts', 'campaign-trigger-js')
    <script src="{{ asset('js/campaign-trigger.js') }}?v={{ @filemtime(public_path('js/campaign-trigger.js')) ?: time() }}" defer></script>
@endPushOnce
@endsection
