{{-- Served only to signed-in users. The file itself is outside public/. --}}
@php
    $supportApkPath = (string) config('kaman-support.apk_path');
    $supportApkKb = is_file($supportApkPath) ? (int) round(filesize($supportApkPath) / 1024) : null;
@endphp
<section class="kaman-card kaman-card--pad" aria-label="{{ __('app.support_downloads') }}">
    <p class="text-center text-xs font-semibold uppercase tracking-widest text-[#a78a6c]">
        {{ __('app.support_downloads') }}
    </p>
    <div class="mt-3">
        <a href="{{ route('downloads.kaman-support') }}?v=1.0.10"
           class="kaman-button w-full"
           download="KamanSupport-1.0.10.apk">
            @include('app-development.partials.icon', ['name' => 'apk'])
            <span>{{ __('app.support_apk') }}</span>
            <span class="text-[11px] font-medium opacity-80">{{ __('app.support_apk_meta') }}@if($supportApkKb !== null) · {{ $supportApkKb }} KB @endif</span>
        </a>
    </div>
</section>
