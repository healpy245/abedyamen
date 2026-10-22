@props([
    'href',
    'index',
    'name',
    'description',
    'status' => null,
])

<a {{ $attributes->merge(['class' => 'portal', 'href' => $href]) }} data-hub-portal data-name="{{ $name }}">
    <span class="portal-top">
        <span class="portal-index">{{ $index }}</span>
        @if($status)
            <span class="portal-status">{{ $status }}</span>
        @endif
    </span>
    <span class="portal-preview">{{ $slot }}</span>
    <span class="portal-foot">
        <span class="portal-copy">
            <span class="portal-mark">{{ $mark }}</span>
            <span>
                <strong>{{ $name }}</strong>
                <em>{{ $description }}</em>
            </span>
        </span>
        <svg class="portal-go" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path d="M4 10h12M12 6l4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </span>
</a>
