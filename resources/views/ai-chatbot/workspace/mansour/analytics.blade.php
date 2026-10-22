@php
    use App\Support\FarzUi;

    $placement = $placement ?? 'desktop';
    $groups = match (true) {
        $instance->isDimaBot() => FarzUi::dimaGroups(),
        $instance->isAppsBot() => FarzUi::appGroups(),
        $instance->isInviteBot() => FarzUi::inviteGroups(),
        default => FarzUi::groups(),
    };
    $hint = fn (string $key): string => $instance->isDimaBot() || $instance->isInviteBot()
        ? ($groups[$key]['hint'] ?? $groups[$key]['label'])
        : ($instance->isAppsBot()
            ? $groups[$key]['label']
            : __('chatbot.mansour.'.$key.'_hint'));
@endphp

@if($placement === 'desktop')
    <aside class="farz-live hidden xl:flex" id="mansour-analytics-desktop">
        <h3 class="farz-live__title"><i class="farz-live__pulse"></i> {{ __('chatbot.mansour.live') }}</h3>
        <div class="farz-live__quota" title="أرقام انبعتلها / أرقام لسا ما انبعت">
            <b>
                <span data-analytics="numbers_sent">{{ (int) ($analytics['numbers_sent'] ?? 0) }}</span>
                <span>/</span>
                <span data-analytics="numbers_available">{{ (int) ($analytics['numbers_available'] ?? 0) }}</span>
            </b>
            <small>انبعت / متاح للإرسال</small>
        </div>
        @foreach($groups as $key => $meta)
            <div class="analytics-card farz-live__card" style="--c: {{ $meta['color'] }}"
                 role="button" tabindex="0"
                 data-insight-filter="{{ $key }}"
                 data-insight-label="{{ $meta['label'] }}"
                 title="{{ $hint($key) }}"
                 aria-pressed="false">
                <span class="farz-live__ico">{{ FarzUi::svg($meta['icon']) }}</span>
                <div class="farz-live__body">
                    <div class="farz-live__top">
                        <b>{{ $meta['label'] }}</b>
                        <a href="{{ route('ai-chatbot.workspace.analytics.export', array_filter(['instance' => $instance, 'group' => $key, 'line' => is_array($line ?? null) ? $line['id'] : null])) }}"
                           class="analytics-download farz-live__dl" title="{{ __('chatbot.mansour.download') }}" aria-label="{{ __('chatbot.mansour.download') }}">{{ FarzUi::svg('download') }}</a>
                    </div>
                    <p class="farz-live__num">
                        <span data-analytics="{{ $key }}">{{ (int) ($analytics[$key] ?? 0) }}</span>
                        <small><span data-analytics="{{ $key }}_pct">{{ (int) ($analytics[$key.'_pct'] ?? 0) }}</span>%</small>
                    </p>
                    <div class="farz-live__bar"><span data-analytics-bar="{{ $key }}" style="width: {{ (int) ($analytics[$key.'_pct'] ?? 0) }}%"></span></div>
                </div>
            </div>
        @endforeach
    </aside>
@else
    <div class="farz-live-mobile xl:hidden" id="mansour-analytics-mobile">
        <div class="farz-live__quota farz-live__quota--mobile" title="أرقام انبعتلها / أرقام لسا ما انبعت">
            <b>
                <span data-analytics="numbers_sent">{{ (int) ($analytics['numbers_sent'] ?? 0) }}</span>
                <span>/</span>
                <span data-analytics="numbers_available">{{ (int) ($analytics['numbers_available'] ?? 0) }}</span>
            </b>
            <small>انبعت / متاح</small>
        </div>
        @foreach($groups as $key => $meta)
            <div class="analytics-card farz-live-mobile__card" style="--c: {{ $meta['color'] }}"
                 role="button" tabindex="0"
                 data-insight-filter="{{ $key }}"
                 data-insight-label="{{ $meta['label'] }}"
                 title="{{ $meta['label'] }}"
                 aria-pressed="false">
                <span class="farz-live__ico">{{ FarzUi::svg($meta['icon']) }}</span>
                <span class="farz-live-mobile__text">
                    <b data-analytics="{{ $key }}">{{ (int) ($analytics[$key] ?? 0) }}</b>
                    <small>{{ $meta['label'] }}</small>
                </span>
                <a href="{{ route('ai-chatbot.workspace.analytics.export', array_filter(['instance' => $instance, 'group' => $key, 'line' => is_array($line ?? null) ? $line['id'] : null])) }}"
                   class="analytics-download farz-live__dl" title="{{ __('chatbot.mansour.download') }}" aria-label="{{ __('chatbot.mansour.download') }}">{{ FarzUi::svg('download') }}</a>
                <div class="farz-live__bar"><span data-analytics-bar="{{ $key }}" style="width: {{ (int) ($analytics[$key.'_pct'] ?? 0) }}%"></span></div>
            </div>
        @endforeach
    </div>

    @once
        @push('scripts')
            <script>
                (function () {
                    const sync = () => document.querySelectorAll('[data-analytics-bar]').forEach((bar) => {
                        const pct = document.querySelector(`[data-analytics="${bar.dataset.analyticsBar}_pct"]`);
                        if (pct) bar.style.width = Math.min(100, parseInt(pct.textContent, 10) || 0) + '%';
                    });
                    new MutationObserver(sync).observe(document.body, { subtree: true, characterData: true, childList: true });
                })();
            </script>
        @endpush
    @endonce
@endif

@include('ai-chatbot.workspace.partials.download-history-modal')
