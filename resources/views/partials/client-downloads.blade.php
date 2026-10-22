{{-- Session download for yamen@kaman.rest only. The file stays outside public/. --}}
<section class="kaman-card kaman-card--pad" aria-label="{{ __('app.client_downloads') }}">
    <p class="text-center text-xs font-semibold uppercase tracking-widest text-[#a78a6c]">
        {{ __('app.client_downloads') }}
    </p>
    <div class="mt-3">
        <a href="{{ route('downloads.kaman-app') }}?v=3.0.20"
           class="kaman-button w-full"
           download="{{ config('kaman-app.download_name') }}">
            @include('app-development.partials.icon', ['name' => 'apk'])
            <span>{{ __('app.client_apk') }}</span>
            <span class="text-[11px] font-medium opacity-80">{{ __('app.client_apk_meta') }}</span>
        </a>
    </div>
</section>
