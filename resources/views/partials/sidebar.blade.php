@php
    $navUser = auth()->user();
    $navProjects = $navUser ? $navUser->accessibleProjects() : [];
    $activeProject = $activeProject ?? null;
    $canDownloadKamanApp = $navUser
        && strcasecmp((string) $navUser->email, (string) config('kaman-app.allowed_email')) === 0;
@endphp

<div class="kaman-sidebar-backdrop" data-kaman-sidebar-backdrop hidden></div>

<aside class="kaman-sidebar" data-kaman-sidebar aria-label="{{ __('app.projects') }}">
    @if(count($navProjects) > 0)
        <div class="kaman-sidebar__section">
            <p class="kaman-sidebar__heading">{{ __('app.projects') }}</p>
            <nav class="kaman-sidebar__nav" aria-label="{{ __('app.projects') }}">
                @foreach($navProjects as $project)
                    <a href="{{ $project->url() }}"
                       class="kaman-sidebar__link"
                       @if($activeProject === $project->value) aria-current="page" @endif>
                        {{ $project->label() }}
                    </a>
                @endforeach
            </nav>
        </div>
    @endif

    @unless($navUser?->supportConnectionsOnly() || $navUser?->isChatbotOnly())
    <div class="kaman-sidebar__section">
        <p class="kaman-sidebar__heading">{{ __('app.quick_downloads') }}</p>
        <div class="kaman-sidebar__downloads">
            <a href="{{ asset('downloads/kaman-pos.apk') }}?v=1.3.84.108"
               class="kaman-sidebar__download"
               download="KamanPOS-1.3.84.apk">
                @include('app-development.partials.icon', ['name' => 'apk'])
                <span>{{ __('app.download_pos_android') }}</span>
            </a>
            <a href="{{ asset('downloads/kaman-pos-setup.exe') }}?v=1.3.84.108"
               class="kaman-sidebar__download"
               download="KamanPOS-Setup.exe">
                @include('app-development.partials.icon', ['name' => 'download'])
                <span>{{ __('app.download_pos_windows') }}</span>
            </a>
            <a href="{{ route('downloads.kaman-support') }}?v=1.0.11"
               class="kaman-sidebar__download"
               download="KamanSupport-1.0.11.apk">
                @include('app-development.partials.icon', ['name' => 'apk'])
                <span>{{ __('app.download_support_android') }}</span>
            </a>
            @if($canDownloadKamanApp)
                <a href="{{ route('downloads.kaman-app') }}?v=3.0.19"
                   class="kaman-sidebar__download"
                   download="{{ config('kaman-app.download_name') }}">
                    @include('app-development.partials.icon', ['name' => 'apk'])
                    <span>{{ __('app.download_kaman_app') }}</span>
                </a>
            @endif
        </div>
    </div>
    @endunless
</aside>

<script>
    (function () {
        const root = document.body;
        const sidebar = document.querySelector('[data-kaman-sidebar]');
        const backdrop = document.querySelector('[data-kaman-sidebar-backdrop]');
        if (!sidebar || root.dataset.kamanSidebarBound === '1') return;
        root.dataset.kamanSidebarBound = '1';

        const open = () => {
            root.classList.add('kaman-sidebar-open');
            if (backdrop) backdrop.hidden = false;
        };
        const close = () => {
            root.classList.remove('kaman-sidebar-open');
            if (backdrop) backdrop.hidden = true;
        };

        document.querySelectorAll('[data-kaman-sidebar-open]').forEach((button) => {
            button.addEventListener('click', open);
        });
        backdrop?.addEventListener('click', close);
        sidebar.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => {
                if (window.matchMedia('(max-width: 1023px)').matches) close();
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') close();
        });
    })();
</script>
