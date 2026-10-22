{{-- Stable public files: downloads/kaman-pos.apk and kaman-pos-setup.exe.
     The hidden zip link lets installs before 1.3.59 still see the new version. --}}
<section class="kaman-card kaman-card--pad" aria-label="{{ __('app.pos_downloads') }}">
    <p class="text-center text-xs font-semibold uppercase tracking-widest text-[#a78a6c]">
        {{ __('app.pos_downloads') }}
    </p>
    <div class="mt-3 grid grid-cols-1 gap-2">
        <a href="{{ asset('downloads/kaman-pos.apk') }}?v=1.3.68.91"
           class="kaman-button w-full"
           download="KamanPOS-1.3.68.apk">
            @include('app-development.partials.icon', ['name' => 'apk'])
            <span>{{ __('app.pos_apk') }}</span>
            <span class="text-[11px] font-medium opacity-80">{{ __('app.pos_apk_meta') }}</span>
        </a>
        <a href="{{ asset('downloads/kaman-pos-setup.exe') }}?v=1.3.68.91"
           class="kaman-button-ghost w-full"
           download="KamanPOS-Setup.exe">
            @include('app-development.partials.icon', ['name' => 'download'])
            <span>{{ __('app.pos_windows') }}</span>
            <span class="text-[11px] font-medium opacity-80">{{ __('app.pos_windows_meta') }}</span>
        </a>
        <a href="{{ asset('downloads/kaman-pos-windows.zip') }}?v=1.3.61.84" hidden></a>
        <a href="{{ asset('downloads/kaman-pos-previous.apk') }}?v=1.3.67.90" hidden></a>
        <a href="{{ asset('downloads/kaman-pos-previous-setup.exe') }}?v=1.3.65.88" hidden></a>
    </div>
</section>
