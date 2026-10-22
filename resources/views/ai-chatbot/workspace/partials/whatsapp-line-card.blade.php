@php
    $title = ($line['name'] ?? '') !== '' ? $line['name'] : (($line['id'] ?? '') !== '' ? '#'.$line['id'] : ($fallback ?? ''));
    $phone = (string) ($line['phone'] ?? '');
    if (str_starts_with($phone, '972') && strlen($phone) >= 11) {
        $phone = '0'.substr($phone, 3);
    }
@endphp
@php $tag = ($bare ?? false) ? 'div' : 'article'; @endphp
<{{ $tag }} @class([
    'flex flex-row items-center gap-3',
    'kaman-card min-w-[16rem] flex-1 px-4 py-3' => ! ($bare ?? false),
])>
    @if(($line['avatar'] ?? '') !== '')
        <img src="{{ $line['avatar'] }}" alt="" class="h-14 w-14 shrink-0 rounded-full object-cover bg-[#f1dfc5]">
    @else
        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-[#f1dfc5] text-lg font-bold text-[#7c6a56]">
            {{ mb_substr($title !== '' ? $title : '?', 0, 1) }}
        </span>
    @endif
    <div class="min-w-0">
        <p class="truncate text-sm font-semibold text-[#2b1e11]">{{ $title }}</p>
        @if($phone !== '')
            <p class="truncate text-xs text-[#a78a6c]" dir="ltr">{{ $phone }}</p>
        @endif
        @if(($line['id'] ?? '') !== '')
            <p class="truncate text-[11px] text-[#a78a6c]" dir="ltr">{{ $line['id'] }}</p>
        @endif
    </div>
</{{ $tag }}>
