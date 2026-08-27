@php
    /** @var list<\App\Enums\AppDevelopmentAppType>|\Illuminate\Support\Collection $types */
    $types = collect($types ?? []);
    $compact = $compact ?? false;
    $limit = $compact ? 2 : 99;
    $shown = $types->take($limit);
    $extra = max(0, $types->count() - $shown->count());
@endphp
<span class="kaman-app-types {{ $compact ? 'kaman-app-types--compact' : '' }}">
    @forelse($shown as $type)
        <span class="kaman-app-type-badge kaman-app-type-badge--{{ $type->value }}" title="{{ $type->label() }}">
            @include('app-development.partials.icon', ['name' => $type->icon()])
            @unless($compact && $types->count() > 1)
                <span>{{ $type->label() }}</span>
            @else
                <span class="kaman-app-type-badge__short">{{ $type->label() }}</span>
            @endunless
        </span>
    @empty
        <span class="text-[#a78a6c]">—</span>
    @endforelse
    @if($extra > 0)
        <span class="kaman-app-type-badge kaman-app-type-badge--more">+{{ $extra }}</span>
    @endif
</span>
