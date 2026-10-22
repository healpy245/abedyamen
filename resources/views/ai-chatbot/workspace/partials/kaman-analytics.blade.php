@php
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    $placement = $placement ?? 'desktop';
    $analyticsCards = [
        [
            'key' => 'leads',
            'label' => __('chatbot.workspace.analytics.leads'),
            'hint' => __('chatbot.workspace.analytics.leads_hint'),
            'icon' => 'check-circle',
            'tone' => 'bg-emerald-50 border-emerald-200/80 text-emerald-800',
            'iconTone' => 'bg-emerald-100 text-emerald-700',
            'count' => (int) ($analytics['leads'] ?? 0),
            'export' => 'leads',
            'filter' => 'leads',
        ],
        [
            'key' => 'no_response',
            'label' => __('chatbot.workspace.analytics.no_response'),
            'hint' => __('chatbot.workspace.analytics.no_response_hint'),
            'icon' => 'clock',
            'tone' => 'bg-amber-50 border-amber-200/80 text-amber-900',
            'iconTone' => 'bg-amber-100 text-amber-700',
            'count' => (int) ($analytics['no_response'] ?? 0),
            'export' => 'no_response',
            'filter' => 'no_response',
        ],
        [
            'key' => 'rejected',
            'label' => __('chatbot.workspace.analytics.rejected'),
            'hint' => __('chatbot.workspace.analytics.rejected_hint'),
            'icon' => 'x-circle',
            'tone' => 'bg-rose-50 border-rose-200/80 text-rose-900',
            'iconTone' => 'bg-rose-100 text-rose-700',
            'count' => (int) ($analytics['rejected'] ?? 0),
            'export' => 'rejected',
            'filter' => 'rejected',
        ],
        [
            'key' => 'engaged',
            'label' => __('chatbot.workspace.analytics.engaged'),
            'hint' => __('chatbot.workspace.analytics.engaged_hint'),
            'icon' => 'chat-bubble-left-right',
            'tone' => 'bg-sky-50 border-sky-200/80 text-sky-900',
            'iconTone' => 'bg-sky-100 text-sky-700',
            'count' => (int) ($analytics['engaged'] ?? 0),
            'export' => 'responded',
            'filter' => 'responded',
        ],
        [
            'key' => 'messages',
            'label' => __('chatbot.workspace.analytics.messages'),
            'hint' => __('chatbot.workspace.analytics.messages_hint'),
            'icon' => 'megaphone',
            'tone' => 'bg-orange-50 border-orange-200/80 text-orange-900',
            'iconTone' => 'bg-orange-100 text-orange-700',
            'count' => (int) ($analytics['messages'] ?? 0),
            'export' => 'messages',
            'filter' => 'messages',
        ],
        [
            'key' => 'contacts',
            'label' => __('chatbot.workspace.analytics.contacts'),
            'hint' => __('chatbot.workspace.analytics.contacts_hint'),
            'icon' => 'users',
            'tone' => 'bg-stone-50 border-stone-200/80 text-stone-800',
            'iconTone' => 'bg-stone-100 text-stone-600',
            'count' => (int) ($analytics['contacts'] ?? 0),
            'export' => 'contacts',
            'filter' => 'contacts',
        ],
    ];
@endphp

@if($placement === 'desktop')
    <aside class="hidden xl:flex flex-col min-h-0 h-full overflow-y-auto border-s border-[#eadfce]/80 bg-white/50 p-4 gap-2.5" id="kaman-analytics-desktop">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-[#a78a6c]">{{ __('chatbot.workspace.analytics.live') }}</h3>
        <p class="text-[10px] leading-snug text-[#b6987a]">{{ __('chatbot.workspace.analytics.hint') }}</p>
        <p class="text-[10px] leading-snug text-[#b6987a]">{{ __('chatbot.workspace.analytics.tap') }}</p>
        <p class="live-sync-dot inline-flex items-center gap-1 text-[10px] text-[#a78a6c]">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" aria-hidden="true"></span>
            {{ __('chatbot.workspace.live') }}
        </p>
        @foreach ($analyticsCards as $card)
            <div class="analytics-card rounded-xl border px-3 py-2.5 {{ $card['tone'] }} cursor-pointer transition ring-offset-1 hover:brightness-[0.98]"
                 role="button"
                 tabindex="0"
                 data-insight-filter="{{ $card['filter'] }}"
                 data-insight-label="{{ $card['label'] }}"
                 aria-pressed="false"
                 title="{{ __('chatbot.workspace.analytics.tap') }}">
                <div class="flex items-start gap-2.5">
                    <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $card['iconTone'] }}">
                        <x-workspace.icon :name="$card['icon']" class="h-4 w-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-semibold uppercase tracking-wide opacity-80">{{ $card['label'] }}</p>
                        <p class="text-2xl font-semibold leading-tight" data-analytics="{{ $card['key'] }}">{{ $card['count'] }}</p>
                        <p class="mt-0.5 text-[10px] leading-snug opacity-70">{{ $card['hint'] }}</p>
                    </div>
                </div>
                <a
                    href="{{ route('ai-chatbot.workspace.analytics.export', array_filter(['instance' => $instance, 'group' => $card['export'], 'line' => is_array($line ?? null) ? $line['id'] : null])) }}"
                    class="analytics-download mt-2 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-white/70 px-2 py-1.5 text-[11px] font-medium text-current ring-1 ring-black/5 hover:bg-white transition"
                    title="{{ __('chatbot.workspace.analytics.download_title') }}"
                >
                    <x-workspace.icon name="arrow-down-tray" class="h-3.5 w-3.5" />
                    {{ __('chatbot.workspace.analytics.download') }}
                </a>
            </div>
        @endforeach
    </aside>
@else
    <div class="xl:hidden mt-3 grid grid-cols-2 gap-2" id="kaman-analytics-mobile">
        @foreach ($analyticsCards as $card)
            <div class="analytics-card rounded-xl border px-3 py-2 {{ $card['tone'] }} cursor-pointer"
                 role="button"
                 tabindex="0"
                 data-insight-filter="{{ $card['filter'] }}"
                 data-insight-label="{{ $card['label'] }}"
                 aria-pressed="false"
                 title="{{ __('chatbot.workspace.analytics.tap') }}">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md {{ $card['iconTone'] }}">
                        <x-workspace.icon :name="$card['icon']" class="h-3.5 w-3.5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-wide opacity-80 truncate">{{ $card['label'] }}</p>
                        <p class="text-lg font-semibold leading-tight" data-analytics="{{ $card['key'] }}">{{ $card['count'] }}</p>
                    </div>
                </div>
                <a
                    href="{{ route('ai-chatbot.workspace.analytics.export', array_filter(['instance' => $instance, 'group' => $card['export'], 'line' => is_array($line ?? null) ? $line['id'] : null])) }}"
                    class="analytics-download mt-2 inline-flex w-full items-center justify-center gap-1 rounded-lg bg-white/70 px-2 py-1 text-[10px] font-medium ring-1 ring-black/5"
                >
                    <x-workspace.icon name="arrow-down-tray" class="h-3 w-3" />
                    {{ __('chatbot.workspace.analytics.download') }}
                </a>
            </div>
        @endforeach
    </div>
@endif

@include('ai-chatbot.workspace.partials.download-history-modal')
