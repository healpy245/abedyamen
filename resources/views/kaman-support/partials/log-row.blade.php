@php
    $tone = $log->tone();
    $showDevice = $showDevice ?? false;
    $device = $log->device ?? null;
    $failedApi = $log->level === 'error' && ($log->source === 'api' || str_starts_with((string) $log->message, 'API '));
@endphp
<li class="rounded-md border {{ $tone['border'] }} {{ $tone['bg'] }} px-3 py-2 text-sm {{ $failedApi ? 'ring-1 ring-rose-300' : '' }}" @if($failedApi) data-failed-api @endif>
    <div class="flex flex-wrap items-center gap-2 text-xs {{ $tone['text'] }}">
        <span class="font-semibold uppercase">{{ $log->level }}</span>
        <span>{{ $log->source }}</span>
        @if($failedApi)
            <span class="rounded bg-rose-100 px-1.5 py-0.5 font-semibold uppercase">API</span>
        @endif
        <span>{{ $log->logged_at?->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('Y-m-d H:i:s') }}</span>
        @if($showDevice && $device)
            <a href="{{ route('kaman-support.device', $device) }}" class="font-medium text-[#c2410c] hover:underline">
                {{ $device->displayRestaurant() }}
                · {{ __('kaman-support.kind_'.$device->deviceKind()) }}
                @if($device->cashier_name) · {{ $device->cashier_name }} @endif
            </a>
        @endif
    </div>
    <pre class="mt-1 whitespace-pre-wrap break-words text-[#2b1e11]">{{ $log->message }}</pre>
    @if(is_array($log->context) && $log->context !== [])
        <pre class="mt-1 whitespace-pre-wrap break-words text-xs text-[#7c6a56]">{{ json_encode($log->context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
    @endif
</li>
