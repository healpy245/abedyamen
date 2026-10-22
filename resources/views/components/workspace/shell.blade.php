@props(['door' => 'overview', 'contentClass' => ''])
<div class="ws-shell">
    <x-workspace.sidebar :door="$door" />
    <div class="ws-main">
        <x-workspace.topbar :door="$door">
            <x-slot:tools>{{ $tools ?? '' }}</x-slot:tools>
        </x-workspace.topbar>
        {{ $nav ?? '' }}
        <div class="ws-content {{ $contentClass }}">
            {{ $slot }}
        </div>
    </div>
</div>
<script src="{{ asset('js/workspace-shell.js') }}?v={{ filemtime(public_path('js/workspace-shell.js')) }}"></script>
