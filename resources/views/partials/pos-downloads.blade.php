{{-- Stable public files: downloads/kaman-pos.apk and kaman-pos-setup.exe.
     The hidden zip link lets installs before 1.3.59 still see the new version. --}}
@php
    $posVersion = (string) config('kaman-workspace.pos_version');
    $posQuery = '?v='.$posVersion.'.'.config('kaman-workspace.pos_build');
    $winVersion = (string) config('kaman-workspace.pos_windows_version');
    $winQuery = '?v='.$winVersion.'.'.config('kaman-workspace.pos_windows_build');
@endphp
<section class="kaman-card kaman-card--pad" aria-label="{{ __('app.pos_downloads') }}">
    <p class="text-center text-xs font-semibold uppercase tracking-widest text-[#a78a6c]">
        {{ __('app.pos_downloads') }}
    </p>
    <div class="mt-3 grid grid-cols-1 gap-2">
        <a href="{{ asset('downloads/kaman-pos.apk') }}{{ $posQuery }}"
           class="kaman-button w-full"
           download="KamanPOS-{{ $posVersion }}.apk">
            @include('app-development.partials.icon', ['name' => 'apk'])
            <span>{{ __('app.pos_apk') }}</span>
            <span class="text-[11px] font-medium opacity-80">{{ __('app.pos_apk_meta') }}</span>
        </a>
        <a href="{{ asset('downloads/kaman-pos-setup.exe') }}{{ $winQuery }}"
           class="kaman-button-ghost w-full"
           download="KamanPOS-Setup.exe">
            @include('app-development.partials.icon', ['name' => 'download'])
            <span>{{ __('app.pos_windows') }}</span>
            <span class="text-[11px] font-medium opacity-80">{{ __('app.pos_windows_meta') }}</span>
        </a>
        <a href="{{ asset('downloads/kaman-pos-windows.zip') }}?v=1.3.61.84" hidden></a>
        <a href="{{ asset('downloads/kaman-pos-previous.apk') }}?v=1.3.93.117" hidden></a>
        <a href="{{ asset('downloads/kaman-pos-previous-setup.exe') }}?v=1.3.93.117" hidden></a>
    </div>
</section>
