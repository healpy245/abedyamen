@php
    $live = $device->isLive();
    $tone = $live
        ? ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-800', 'border' => 'border-emerald-200']
        : ['bg' => 'bg-slate-50', 'text' => 'text-slate-700', 'border' => 'border-slate-200'];
    $kind = $device->deviceKind();
    $failure = $failure ?? ($failures[$device->id] ?? null);
    $failedCount = (int) ($failure['count'] ?? 0);
@endphp
<a href="{{ route('kaman-support.device', $device) }}"
   data-device-id="{{ $device->id }}"
   class="kaman-card kaman-card--compact block space-y-2 transition hover:bg-[#fff8f0] hover:shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <div data-card-name class="font-semibold text-[#2b1e11]">{{ $device->displayRestaurant() }}</div>
            <div data-card-cashier class="text-xs text-[#7c6a56]">
                {{ $device->cashier_name ?: '—' }}
                @if($device->role) · {{ $device->role }} @endif
            </div>
        </div>
        <div class="flex flex-wrap gap-1">
            <span data-online class="kaman-badge {{ $tone['bg'] }} {{ $tone['text'] }} {{ $tone['border'] }}">
                {{ $live ? __('kaman-support.online') : __('kaman-support.offline') }}
            </span>
            <span class="kaman-badge bg-[#fff4ea] text-[#9a3412] border-[#f3d2b5]">
                {{ __('kaman-support.kind_'.$kind) }}
            </span>
        </div>
    </div>
    <div data-card-host class="font-mono text-xs text-[#7c6a56] break-all">{{ $device->hostname ?: $device->device_id }}</div>
    <div data-failed {{ $failedCount > 0 ? '' : 'hidden' }}
         class="rounded-md border border-rose-200 bg-rose-50 px-2 py-1.5 text-xs text-rose-800">
        <div class="flex flex-wrap items-center gap-2">
            <span data-failed-count class="font-semibold">{{ trans('kaman-support.failed_on_device', ['count' => $failedCount]) }}</span>
            <span data-failed-source>{{ $failure['source'] ?? '' }}</span>
            <span data-failed-at>{{ $failure['at'] ?? '' }}</span>
        </div>
        <div data-failed-message class="mt-0.5 line-clamp-2 break-words">{{ $failure['message'] ?? '' }}</div>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-[#7c6a56]">
        <span data-card-meta>{{ $device->platform ?: '—' }} · {{ $device->app_version ?: '—' }}</span>
        <span>
            {{ $device->log_count }} {{ __('kaman-support.log_count') }} ·
            <span data-last-clock>{{ $device->last_seen_at?->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('H:i') }}</span>
            <span data-last-seen>{{ $device->last_seen_at?->diffForHumans() }}</span>
        </span>
    </div>
</a>
