<span class="kaman-badge {{ $priority->cssClass() }}">
    @include('app-development.partials.icon', ['name' => $priority->icon()])
    {{ $priority->label() }}
</span>
