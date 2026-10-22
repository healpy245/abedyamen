{{-- Conversations list sidebar (RTL: sits on the inline-start / right side) --}}
@php
    $activeId = $activeId ?? null;
    $filter = $filter ?? 'all';
    $search = $search ?? '';
    $showInsight = (bool) ($showInsight ?? false);
@endphp
<aside class="border-e border-[#eadfce] flex flex-col min-h-0 min-w-0 overflow-hidden bg-[#fffaf3]/60 {{ $listPaneClass ?? '' }}"
       id="workspace-list-pane"
       data-poll-url="{{ route('ai-chatbot.workspace.conversations.poll', $instance) }}"
       data-filter="{{ $filter }}"
       data-q="{{ $search }}"
       @if(is_array($line ?? null)) data-line="{{ $line['id'] }}" data-line-label="{{ $line['label'] }}" @endif
       data-list-labels="{{ e(json_encode([
           'mode_human' => __('chatbot.workspace.mode_human'),
           'mode_paused' => __('chatbot.workspace.mode_paused'),
           'needs_attention' => __('chatbot.workspace.needs_attention'),
           'mark_user_created' => __('chatbot.workspace.mark_user_created'),
           'mark_user_created_confirm' => __('chatbot.workspace.mark_user_created_confirm'),
       ], JSON_UNESCAPED_UNICODE)) }}"
       @if($showInsight)
           data-insight="{{ $insight ?? 'all' }}"
           data-insight-conversation-ids="{{ e(json_encode($insightConversationIds ?? [], JSON_UNESCAPED_UNICODE)) }}"
           data-empty-label="{{ __('chatbot.workspace.empty_conversations') }}"
           data-filter-empty-label="{{ __('chatbot.workspace.analytics.filter_empty') }}"
       @endif
>
    <div class="p-3 border-b border-[#eadfce] space-y-2">
        @if(is_array($line ?? null))
            <div class="farz-linebar">
                @if(count($lines ?? []) > 1)
                    <details class="farz-switch">
                        <summary title="تبديل الرقم">
                            <span class="farz-switch__dot {{ ! empty($line['alert']) ? 'is-dead' : (! empty($line['active']) ? 'is-on' : '') }}" @if(($line['id'] ?? '') !== 'all') data-line-dot="{{ $line['id'] }}" @endif></span>
                            <b>{{ $line['label'] }}</b>
                            @if(($line['phone'] ?? '') !== '' && ($line['name'] ?? '') !== '')
                                <small dir="ltr">{{ $line['phone'] }}</small>
                            @endif
                            <span class="farz-switch__caret">{{ \App\Support\FarzUi::svg('arrow') }}</span>
                            @if(($line['id'] ?? '') !== 'all')
                                @include('ai-chatbot.workspace.partials.line-pressure', ['stat' => $line])
                            @endif
                        </summary>
                        <div class="farz-switch__menu">
                            <a href="{{ route('ai-chatbot.workspace.conversations', array_filter(['instance' => $instance, 'line' => 'all', 'filter' => $filter !== 'all' ? $filter : null, 'q' => $search !== '' ? $search : null])) }}"
                               @class(['is-active' => ($line['id'] ?? '') === 'all'])>
                                <span class="farz-switch__dot is-on"></span>
                                <span>كل الأرقام</span>
                            </a>
                            @foreach($lines as $option)
                                <a href="{{ route('ai-chatbot.workspace.conversations', array_filter(['instance' => $instance, 'line' => $option['id'], 'filter' => $filter !== 'all' ? $filter : null, 'q' => $search !== '' ? $search : null])) }}"
                                   @class(['is-active' => $option['id'] === $line['id']])>
                                    <span class="farz-switch__dot {{ ! empty($option['alert']) ? 'is-dead' : (! empty($option['active']) ? 'is-on' : '') }}" data-line-dot="{{ $option['id'] }}"></span>
                                    <span class="farz-switch__body">
                                        <span class="farz-switch__title">
                                            <span>{{ $option['label'] }}</span>
                                            @if($option['phone'] !== '' && $option['name'] !== '')
                                                <small dir="ltr">{{ $option['phone'] }}</small>
                                            @endif
                                        </span>
                                        @include('ai-chatbot.workspace.partials.line-pressure', ['stat' => $option])
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </details>
                @elseif(($line['id'] ?? '') !== 'all')
                    <div class="farz-line-current">
                        <span class="farz-switch__dot {{ ! empty($line['alert']) ? 'is-dead' : (! empty($line['active']) ? 'is-on' : '') }}" data-line-dot="{{ $line['id'] }}"></span>
                        <b>{{ $line['label'] }}</b>
                        @include('ai-chatbot.workspace.partials.line-pressure', ['stat' => $line])
                    </div>
                @endif
                @if(($canControlBot ?? false) && ($line['id'] ?? '') !== 'all')
                    <form method="post" action="{{ route('ai-chatbot.workspace.mansour.line-active', ['instance' => $instance, 'line' => $line['id']]) }}" class="farz-linebar__power">
                        @csrf
                        <input type="hidden" name="active" value="{{ ! empty($line['active']) ? '0' : '1' }}">
                        <button type="submit" class="farz-switch__power {{ ! empty($line['active']) ? 'is-on' : '' }}" title="{{ ! empty($line['active']) ? 'إيقاف هذا الرقم' : 'تشغيل هذا الرقم' }}">
                            {{ ! empty($line['active']) ? 'إيقاف' : 'تشغيل' }}
                        </button>
                    </form>
                @endif
            </div>
        @endif
        @if($instance->hasMansourIntegration())
            @php
                $paceLines = collect($lines ?? [])
                    ->filter(static fn ($row): bool => is_array($row) && ($row['id'] ?? '') !== '' && ($row['id'] ?? '') !== 'all')
                    ->sortByDesc(static fn ($row): int => (int) ($row['percent'] ?? 0))
                    ->values();
            @endphp
            <div id="farz-pace" class="farz-pace" data-pace-title="حد اليوم" data-pace-note="نسبة اليوم. بتنزل بعد نص الليل." @if($paceLines->isEmpty()) hidden @endif>
                @if($paceLines->isNotEmpty())
                    <strong>حد اليوم · {{ \App\Services\AiChatbot\MansourLines::DAY_CAP }}</strong>
                    <em>نسبة اليوم. بتنزل بعد نص الليل.</em>
                    @foreach($paceLines as $pace)
                        <p data-line-pace="{{ $pace['id'] }}">
                            <b dir="ltr">{{ ($pace['phone'] ?? '') !== '' ? $pace['phone'] : ($pace['label'] ?? '') }}</b>
                            <span data-pace-rate>{{ (int) ($pace['today'] ?? 0) }}/{{ (int) ($pace['cap'] ?? \App\Services\AiChatbot\MansourLines::DAY_CAP) }}</span>
                            <i class="farz-risk is-{{ $pace['level'] ?? 'low' }}" data-pace-risk title="{{ $pace['level_label'] ?? 'منخفض' }}">{{ (int) ($pace['percent'] ?? 0) }}%</i>
                        </p>
                    @endforeach
                @endif
            </div>
        @endif
        <form method="get" action="{{ route('ai-chatbot.workspace.conversations', $instance) }}" class="flex min-w-0 gap-2">
            <label class="sr-only" for="workspace-search">{{ __('chatbot.workspace.search') }}</label>
            <input id="workspace-search" name="q" value="{{ $search }}" type="search"
                   placeholder="{{ __('chatbot.workspace.search_placeholder') }}"
                   class="kaman-input !h-9 min-w-0 flex-1 text-sm">
            <input type="hidden" name="filter" value="{{ $filter }}">
            @if(is_array($line ?? null))
                <input type="hidden" name="line" value="{{ $line['id'] }}">
            @endif
            <button type="submit" class="kaman-button kaman-button--sm">{{ __('chatbot.workspace.search') }}</button>
        </form>
        <div class="flex flex-wrap gap-1" role="tablist" aria-label="{{ __('chatbot.workspace.filters') }}">
            @foreach([
                'all' => __('chatbot.workspace.filter_all'),
                'unread' => __('chatbot.workspace.filter_unread'),
                'needs_attention' => __('chatbot.workspace.filter_needs_attention'),
                'human_takeover' => __('chatbot.workspace.filter_human'),
                'bot_active' => __('chatbot.workspace.filter_bot_active'),
            ] as $key => $label)
                <a href="{{ route('ai-chatbot.workspace.conversations', array_filter(['instance' => $instance, 'filter' => $key, 'q' => $search !== '' ? $search : null, 'line' => is_array($line ?? null) ? $line['id'] : null])) }}"
                   class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition
                   {{ $filter === $key ? 'border-[#f47a2e]/40 bg-[#f47a2e]/12 text-[#f16229]' : 'border-[#eadfce] text-[#7c6a56] hover:border-[#f47a2e]/30' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
        @if($showInsight)
            <div class="flex items-center justify-between gap-2">
                <button type="button" id="insight-filter-clear"
                        class="hidden text-[10px] font-semibold text-[#f47a2e] hover:underline">
                    {{ __('chatbot.workspace.analytics.clear') }}
                </button>
            </div>
            <p id="insight-filter-label" class="hidden text-[10px] text-[#a78a6c]"></p>
        @endif
    </div>

    <div id="conversation-list" class="flex-1 overflow-y-auto kaman-scroll" aria-live="polite">
        @php
            $waitingAccountIds = ($instance->isAppsBot() && is_array(data_get($analytics ?? null, 'conversation_ids.waiting_account')))
                ? array_map('intval', data_get($analytics, 'conversation_ids.waiting_account'))
                : [];
        @endphp
        @forelse($conversations as $c)
            @include('ai-chatbot.workspace.partials.conversation-row', [
                'c' => $c,
                'activeId' => $activeId,
                'waitingAccount' => in_array((int) $c->id, $waitingAccountIds, true),
            ])
        @empty
            <div class="p-6 text-center text-sm text-[#a78a6c]" id="list-empty">
                {{ __('chatbot.workspace.empty_conversations') }}
            </div>
        @endforelse
    </div>

    @if($conversations->hasPages())
        <div class="p-2 border-t border-[#eadfce]" id="conversation-list-pager">{{ $conversations->links() }}</div>
    @endif
</aside>
