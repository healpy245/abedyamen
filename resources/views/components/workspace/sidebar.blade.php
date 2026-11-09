@props(['door' => 'overview'])
@php
    $sideUser = auth()->user();
    $items = [];
    foreach ([\App\Enums\Door::Overview, \App\Enums\Door::Support, \App\Enums\Door::Development, \App\Enums\Door::Agents, \App\Enums\Door::Finance] as $sideDoor) {
        $items[] = [
            'id' => $sideDoor->value,
            'href' => $sideUser->doorEntryUrl($sideDoor),
            'label' => $sideDoor->label(),
            'icon' => $sideDoor->icon(),
            'locked' => ! $sideUser->canOpenDoor($sideDoor),
        ];
    }
    if ($sideUser->managesUsers()) {
        $items[] = ['id' => 'users', 'href' => route('workspace.users'), 'label' => __('access.nav_users'), 'icon' => 'users', 'locked' => false];
    }

    $posVersion = (string) config('kaman-workspace.pos_version');
    $posQuery = '?v='.$posVersion.'.'.config('kaman-workspace.pos_build');
    $winVersion = (string) config('kaman-workspace.pos_windows_version');
    $winQuery = '?v='.$winVersion.'.'.config('kaman-workspace.pos_windows_build');
    $downloads = [
        ['href' => asset('downloads/kaman-pos.apk').$posQuery, 'name' => 'KamanPOS-'.$posVersion.'.apk', 'label' => __('app.download_pos_android'), 'icon' => 'phone'],
        ['href' => asset('downloads/kaman-pos-setup.exe').$winQuery, 'name' => 'KamanPOS-Setup.exe', 'label' => __('app.download_pos_windows'), 'icon' => 'monitor'],
    ];
    // Left out until the APK has been published, so the link never leads to a 404.
    if (is_file((string) config('kaman-workspace.apk_path'))) {
        array_unshift($downloads, ['href' => route('downloads.kaman-workspace').'?v='.config('kaman-workspace.version_name'), 'name' => (string) config('kaman-workspace.download_name'), 'label' => __('app.download_workspace_app'), 'icon' => 'box']);
    }
@endphp
<aside class="ws-side" aria-label="{{ __('app.name') }}">
    <a class="ws-brand" href="{{ route('workspace.overview') }}">
        <span class="ws-brandmark">
            <img src="{{ asset('kaman.png') }}" alt="">
        </span>
        <span class="ws-brand-text">
            <strong>{{ __('app.name') }}</strong>
            <small>Workspace</small>
        </span>
    </a>
    <div>
        <p class="ws-navlabel">{{ __('app.hub_nav') }}</p>
        <nav class="ws-nav" aria-label="{{ __('app.workspace') }}">
            @foreach($items as $item)
                <a href="{{ $item['href'] }}" class="ws-navitem {{ $door === $item['id'] ? 'is-active' : '' }} {{ $item['locked'] ? 'is-locked' : '' }}" @if($door === $item['id']) aria-current="page" @endif>
                    @include('components.workspace.icons', ['name' => $item['icon']])
                    <span>{{ $item['label'] }}</span>
                    @if($item['locked'])<x-workspace.lock-mark />@endif
                </a>
            @endforeach
        </nav>
    </div>
    <div class="ws-downloads">
        <p class="ws-navlabel">{{ __('app.downloads') }}</p>
        <nav class="ws-nav" aria-label="{{ __('app.downloads') }}">
            @foreach($downloads as $download)
                <a href="{{ $download['href'] }}" class="ws-navitem" download="{{ $download['name'] }}" title="{{ $download['label'] }}">
                    @include('components.workspace.icons', ['name' => $download['icon']])
                    <span>{{ $download['label'] }}</span>
                    <i class="ws-downloads__mark" aria-hidden="true">@include('components.workspace.icons', ['name' => 'download'])</i>
                </a>
            @endforeach
        </nav>
    </div>
    <div class="ws-sidefoot">
        <strong>{{ __('app.hub_status_title') }}</strong>
        <p>{{ __('app.hub_status_body') }}</p>
        <span class="ws-ok"><i></i>{{ __('app.hub_status_ok') }}</span>
    </div>
</aside>
