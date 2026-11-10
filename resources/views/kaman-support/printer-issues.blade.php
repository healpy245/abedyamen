@php
    $activeProject = \App\Enums\Project::KamanSupport->value;
    $status = $filters['status'] ?? 'open';
    $fault = $filters['fault'] ?? '';
    $base = array_filter([
        'restaurant' => $filters['restaurant'] ?? '',
        'fault' => $fault,
        'q' => $filters['q'] ?? '',
        'from' => $filters['from'] ?? '',
        'to' => $filters['to'] ?? '',
    ], static fn ($value) => $value !== '' && $value !== null);
    $faultLabel = static fn (string $code): string => __('kaman-support.printer_issues.faults.'.$code);
    $faultTone = static fn (string $code): string => match ($code) {
        'paper_out', 'cover_open', 'printer_error' => 'bg-amber-50 text-amber-800 border-amber-200',
        'no_printer' => 'bg-violet-50 text-violet-800 border-violet-200',
        'cut_mid_print' => 'bg-orange-50 text-orange-800 border-orange-200',
        default => 'bg-rose-50 text-rose-800 border-rose-200',
    };
    $attemptLabel = static function (?string $attempt): string {
        $key = 'kaman-support.printer_issues.attempt_'.($attempt ?? '');
        $label = __($key);

        return $label === $key ? (string) $attempt : $label;
    };
    $named = static function (string $group, ?string $value): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $key = 'kaman-support.printer_issues.'.$group.'.'.$value;
        $label = __($key);

        return $label === $key ? $value : $label;
    };
@endphp

@extends('layouts.support')

@section('title', __('kaman-support.printer_issues.title'))
@section('tag', __('kaman-support.tag'))

@section('content')
    <div class="page-container" data-printer-issues>
        <div class="mx-auto w-full max-w-6xl space-y-4">
            <section class="hero-panel hero-panel--compact">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 class="text-xl sm:text-2xl font-semibold text-[#2b1e11]">{{ __('kaman-support.printer_issues.title') }}</h1>
                        <p class="mt-1 text-sm text-[#7c6a56]">{{ __('kaman-support.printer_issues.subtitle') }}</p>
                    </div>
                    @include('kaman-support.partials.nav', ['nav' => 'printer-issues'])
                </div>
            </section>

            <section class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <div class="kaman-card kaman-card--compact">
                    <p class="text-xs text-[#7c6a56]">{{ __('kaman-support.printer_issues.open_count') }}</p>
                    <p class="text-2xl font-semibold text-rose-700" data-printer-open-count>{{ $counts['open'] }}</p>
                </div>
                <div class="kaman-card kaman-card--compact">
                    <p class="text-xs text-[#7c6a56]">{{ __('kaman-support.printer_issues.uncertain_count') }}</p>
                    <p class="text-2xl font-semibold text-orange-700">{{ $counts['uncertain'] }}</p>
                </div>
                <div class="kaman-card kaman-card--compact">
                    <p class="text-xs text-[#7c6a56]">{{ __('kaman-support.printer_issues.resolved_week') }}</p>
                    <p class="text-2xl font-semibold text-emerald-700">{{ $counts['resolvedWeek'] }}</p>
                </div>
            </section>

            <form method="get" data-clean-get class="kaman-card kaman-card--compact flex flex-wrap items-end gap-2">
                <input type="hidden" name="status" value="{{ $status }}">
                <label class="flex w-64 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.filter_restaurant') }}
                    <select name="restaurant" class="kaman-input" data-ds-select>
                        <option value="">{{ __('kaman-support.all_restaurants') }}</option>
                        @foreach($restaurants as $restaurant)
                            <option value="{{ $restaurant['value'] }}" @selected($filters['restaurant'] === $restaurant['value'])>{{ $restaurant['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex w-48 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.printer_issues.fault') }}
                    <select name="fault" class="kaman-input" data-ds-select>
                        <option value="">{{ __('kaman-support.printer_issues.all_faults') }}</option>
                        @foreach($faults as $code)
                            <option value="{{ $code }}" @selected($fault === $code)>{{ $faultLabel($code) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex w-40 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.log_from') }}
                    <input type="date" name="from" value="{{ substr((string) ($filters['from'] ?? ''), 0, 10) }}" class="kaman-input">
                </label>
                <label class="flex w-40 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.log_to') }}
                    <input type="date" name="to" value="{{ substr((string) ($filters['to'] ?? ''), 0, 10) }}" class="kaman-input">
                </label>
                <label class="flex min-w-[12rem] flex-1 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.log_search') }}
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="kaman-input" placeholder="{{ __('kaman-support.log_search') }}">
                </label>
                <button type="submit" class="kaman-button">{{ __('kaman-support.filter') }}</button>
            </form>

            <nav class="flex flex-wrap gap-2">
                @foreach(['open', 'resolved', 'all'] as $option)
                    <a href="{{ route('kaman-support.printer-issues', $base + ['status' => $option]) }}"
                       class="kaman-button-ghost kaman-button--sm {{ $status === $option ? 'ring-1 ring-[#e8b086]' : '' }}">
                        {{ __('kaman-support.printer_issues.status_'.$option) }}
                    </a>
                @endforeach
            </nav>

            <section class="kaman-card">
                @if($issues->isEmpty())
                    <p class="py-12 text-center text-sm text-[#7c6a56]" data-printer-issues-empty>{{ __('kaman-support.printer_issues.empty') }}</p>
                @endif
                <ol class="space-y-3" data-printer-issue-list>
                    @foreach($issues as $issue)
                        @php
                            $device = $issue->device;
                            $address = $issue->printerAddress();
                            $minutes = $issue->minutesToResolve();
                        @endphp
                        <li class="rounded-xl border border-[#efe2d3] p-3" data-printer-issue="{{ $issue->id }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $issue->isOpen() ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">
                                    {{ $issue->isOpen() ? __('kaman-support.printer_issues.status_open') : __('kaman-support.printer_issues.status_resolved') }}
                                </span>
                                <span class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $faultTone($issue->fault) }}" data-printer-issue-fault="{{ $issue->fault }}">
                                    {{ $faultLabel($issue->fault) }}
                                </span>
                                <span class="text-sm font-semibold text-[#2b1e11]">
                                    {{ __('kaman-support.printer_issues.kitchen') }}:
                                    {{ $issue->station ?: ($issue->fault === 'no_printer' ? __('kaman-support.printer_issues.no_kitchen_matched') : '—') }}
                                </span>
                                @if($issue->order_number)
                                    <span class="text-sm text-[#2b1e11]">· {{ __('kaman-support.printer_issues.order') }} #{{ $issue->order_number }}</span>
                                @endif
                                @if($named('types', $issue->order_type) !== '')
                                    <span class="text-sm text-[#7c6a56]">· {{ $named('types', $issue->order_type) }}</span>
                                @endif
                                @if($issue->order_table)
                                    <span class="text-sm text-[#7c6a56]">· {{ __('kaman-support.printer_issues.table') }} {{ $issue->order_table }}</span>
                                @endif
                                @if($issue->external_order_id)
                                    <span class="text-sm text-[#7c6a56]">· {{ __('kaman-support.printer_issues.external') }} {{ $issue->external_order_id }}</span>
                                @endif
                                <span class="ms-auto text-xs text-[#a78a6c]">
                                    {{ $device?->displayRestaurant() ?? $issue->restaurant }}
                                </span>
                            </div>

                            @if($issue->may_have_printed && $issue->isOpen())
                                <p class="mt-2 rounded-lg border border-orange-200 bg-orange-50 px-2 py-1 text-xs text-orange-800">
                                    {{ __('kaman-support.printer_issues.may_have_printed') }}
                                </p>
                            @endif

                            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                                <div>
                                    <dt class="text-[#a78a6c]">{{ __('kaman-support.printer_issues.printer') }}</dt>
                                    <dd class="text-[#2b1e11]">
                                        @if($issue->printer_name)<span>{{ $issue->printer_name }}</span> @endif
                                        <span class="font-mono">{{ $address ?: ($issue->printer_name ? '' : '—') }}</span>
                                        @if($named('links', $issue->printer_link) !== '' && $issue->printer_link !== 'network')
                                            <span class="text-[#a78a6c]">{{ $named('links', $issue->printer_link) }}</span>
                                        @endif
                                        @if($issue->printer_mac) <span class="text-[#a78a6c]">{{ $issue->printer_mac }}</span>@endif
                                    </dd>
                                </div>
                                <div><dt class="text-[#a78a6c]">{{ __('kaman-support.printer_issues.attempts') }}</dt><dd class="text-[#2b1e11]">{{ $issue->attempts }}</dd></div>
                                <div><dt class="text-[#a78a6c]">{{ __('kaman-support.printer_issues.first_failed') }}</dt><dd class="text-[#2b1e11]">{{ $sessions->formatDisplayTime($issue->first_failed_at) }}</dd></div>
                                <div><dt class="text-[#a78a6c]">{{ __('kaman-support.printer_issues.last_failed') }}</dt><dd class="text-[#2b1e11]">{{ $sessions->formatDisplayTime($issue->last_failed_at) }}</dd></div>
                                <div><dt class="text-[#a78a6c]">{{ __('kaman-support.printer_issues.cashier') }}</dt><dd class="text-[#2b1e11]">{{ $issue->cashier_name ?: '—' }}</dd></div>
                                <div>
                                    <dt class="text-[#a78a6c]">{{ __('kaman-support.printer_issues.device') }}</dt>
                                    <dd class="text-[#2b1e11]">
                                        @if($device)
                                            <a class="underline" href="{{ route('kaman-support.device', $device) }}">{{ $device->hostname ?: $device->device_id }}</a>
                                        @else
                                            —
                                        @endif
                                    </dd>
                                </div>
                                <div><dt class="text-[#a78a6c]">{{ __('kaman-support.printer_issues.channel') }}</dt><dd class="text-[#2b1e11]">{{ $named('channels', $issue->order_channel) ?: '—' }}</dd></div>
                                <div><dt class="text-[#a78a6c]">{{ __('kaman-support.printer_issues.where') }}</dt><dd class="text-[#2b1e11]">{{ $named('classes', $issue->fault_class) ?: '—' }}</dd></div>
                                <div><dt class="text-[#a78a6c]">{{ __('kaman-support.printer_issues.version') }}</dt><dd class="text-[#2b1e11]">{{ $issue->app_version ?: '—' }}</dd></div>
                                @if(! $issue->isOpen())
                                    <div class="col-span-2">
                                        <dt class="text-[#a78a6c]">{{ __('kaman-support.printer_issues.resolved_at') }}</dt>
                                        <dd class="text-emerald-800">
                                            {{ $sessions->formatDisplayTime($issue->resolved_at) }}
                                            · {{ $issue->resolution === 'skipped' ? __('kaman-support.printer_issues.resolution_skipped') : __('kaman-support.printer_issues.resolution_printed') }}
                                            @if($minutes !== null) · {{ __('kaman-support.printer_issues.took', ['minutes' => $minutes]) }} @endif
                                        </dd>
                                    </div>
                                @endif
                                @if($issue->dismissed_count > 0)
                                    <div class="col-span-2"><dd class="text-amber-800">{{ __('kaman-support.printer_issues.dismissed', ['count' => $issue->dismissed_count]) }}</dd></div>
                                @endif
                            </dl>

                            @if($issue->reason)
                                <p class="mt-2 text-sm text-[#2b1e11]"><span class="text-xs text-[#a78a6c]">{{ __('kaman-support.printer_issues.reason') }}:</span> {{ $issue->reason }}</p>
                            @endif

                            @if(! empty($issue->printed_on))
                                <p class="mt-2 text-sm text-emerald-800">{{ __('kaman-support.printer_issues.printed_on') }}: {{ implode(', ', $issue->printed_on) }}</p>
                            @endif
                            @if(! empty($issue->configured_printers))
                                <p class="mt-2 text-sm text-[#2b1e11]">{{ __('kaman-support.printer_issues.configured_printers') }}: {{ implode(', ', $issue->configured_printers) }}</p>
                            @endif

                            @if(! empty($issue->meals))
                                <div class="mt-2">
                                    <p class="text-xs text-[#a78a6c]">{{ __('kaman-support.printer_issues.meals') }}</p>
                                    <ul class="mt-1 flex flex-wrap gap-1">
                                        @foreach($issue->meals as $meal)
                                            <li class="rounded-md bg-[#faf3ea] px-2 py-0.5 text-xs text-[#2b1e11]">
                                                {{ ($meal['qty'] ?? 1) }}× {{ $meal['name'] ?? ($meal['line'] ?? '') }}@if(! empty($meal['note'])) <span class="text-[#a78a6c]">({{ $meal['note'] }})</span>@endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <details class="mt-2">
                                <summary class="cursor-pointer text-xs text-[#7c6a56]">{{ __('kaman-support.printer_issues.timeline') }} ({{ count($issue->attempts_log ?? []) }})</summary>
                                <ol class="mt-2 space-y-1 border-s border-[#efe2d3] ps-3 text-xs">
                                    @foreach(array_reverse($issue->attempts_log ?? []) as $step)
                                        @php
                                            $stepAt = isset($step['at']) ? \Illuminate\Support\Carbon::parse($step['at']) : null;
                                            $eventKey = 'kaman-support.printer_issues.event_'.($step['event'] ?? 'failed');
                                        @endphp
                                        <li>
                                            <span class="font-mono text-[#a78a6c]">{{ $sessions->formatDisplayTime($stepAt, 'm-d H:i:s') }}</span>
                                            <span class="font-semibold text-[#2b1e11]">{{ __($eventKey) }}</span>
                                            @if(! empty($step['attempt'])) · {{ $attemptLabel($step['attempt']) }} @endif
                                            @if(! empty($step['fault'])) · {{ $faultLabel($step['fault']) }} @endif
                                            @if(! empty($step['mayHavePrinted'])) · <span class="text-orange-700">{{ __('kaman-support.printer_issues.uncertain_count') }}</span> @endif
                                            @if(! empty($step['printer'])) · <span class="font-mono">{{ $step['printer'] }}</span> @endif
                                            @if(isset($step['elapsedMs'])) · {{ number_format($step['elapsedMs'] / 1000, 1) }}s @endif
                                            @if(! empty($step['printedOn']) && is_array($step['printedOn'])) · {{ __('kaman-support.printer_issues.printed_on') }} {{ implode(', ', $step['printedOn']) }} @endif
                                            @if(! empty($step['configuredPrinters']) && is_array($step['configuredPrinters'])) · {{ __('kaman-support.printer_issues.configured_printers') }} {{ implode(', ', $step['configuredPrinters']) }} @endif
                                            @if(! empty($step['cashier'])) · {{ $step['cashier'] }} @endif
                                            @if(! empty($step['error']))
                                                <div class="font-mono text-[11px] text-[#7c6a56]">{{ $step['error'] }}</div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                                @if($issue->error_detail)
                                    <p class="mt-2 text-xs text-[#a78a6c]">{{ __('kaman-support.printer_issues.error') }}</p>
                                    <pre class="mt-1 whitespace-pre-wrap break-all rounded-md bg-[#faf3ea] p-2 text-[11px] text-[#2b1e11]">{{ $issue->error_detail }}</pre>
                                @endif
                            </details>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>
    </div>
@endsection
