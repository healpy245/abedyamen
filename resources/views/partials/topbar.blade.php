@php
    use App\Enums\Project;

    $logoPath = $logoPath ?? asset('kaman.png');
    $logoAlt = $logoAlt ?? __('app.name');
    $showTagBadge = $showTagBadge ?? true;
    $tagText = $tagText ?? __('app.partner_portal');
    $poweredByLabel = $poweredByLabel ?? __('app.powered_by');
    $poweredByLogo = $poweredByLogo ?? asset('mfit.png');
    $poweredByAlt = $poweredByAlt ?? 'MFIT';

    $navUser = auth()->user();
@endphp

@php
    $boardWorkspace = ($activeProject ?? null) === \App\Enums\Project::AppDevelopment->value;
@endphp
<header class="kaman-topbar sticky top-0 z-40{{ $boardWorkspace ? ' kaman-topbar--board' : '' }}">
    {{-- max-w matches .page-container so the logo lines up with page content. --}}
    <div class="mx-auto flex w-full items-center justify-between gap-3 py-2 {{ $boardWorkspace ? 'kaman-topbar__inner--board px-3 sm:px-4' : 'max-w-[90rem] px-4 sm:px-6' }}">
        <div class="flex items-center gap-3 min-w-0">
            @if($navUser && ! $boardWorkspace)
                <button type="button"
                        class="kaman-sidebar-open kaman-button-ghost kaman-button--sm lg:!hidden"
                        data-kaman-sidebar-open
                        aria-label="{{ __('app.sidebar_open') }}">
                    @include('app-development.partials.icon', ['name' => 'menu'])
                </button>
            @endif
            <a href="{{ $navUser ? $navUser->afterLoginUrl() : route('login') }}" class="kaman-logo shrink-0">
                <span class="kaman-logo__glow" aria-hidden="true"></span>
                <img src="{{ $logoPath }}" alt="{{ $logoAlt }}" class="kaman-logo__img">
            </a>
            @if($showTagBadge && $tagText)
                <div class="kaman-topbar__rule hidden sm:inline-flex items-center h-7 border-s ps-3 min-w-0 rtl:border-s-0 rtl:border-e rtl:ps-0 rtl:pe-3">
                    <p class="kaman-topbar__tag text-xs font-semibold uppercase tracking-widest truncate">{{ $tagText }}</p>
                </div>
            @endif
        </div>

        <div class="flex shrink-0 items-center gap-2 sm:gap-3">
            @include('partials.theme-toggle')
            @include('partials.lang-switcher')

            @if($navUser)
                @if($navUser->canAccessProject(Project::AppDevelopment))
                    @include('app-development.partials.bell')
                @endif
                <div class="kaman-topbar__rule hidden sm:flex items-center gap-2 ps-2 sm:border-s rtl:border-s-0 rtl:border-e rtl:ps-0 rtl:pe-2">
                    <div class="h-8 w-8 rounded-full bg-[#f47a2e]/12 border border-[#f47a2e]/25 flex items-center justify-center text-[#f16229] text-xs font-bold shrink-0">
                        {{ mb_strtoupper(mb_substr(trim($navUser->name) !== '' ? $navUser->name : $navUser->email, 0, 1)) }}
                    </div>
                    <div class="leading-tight min-w-0">
                        <div class="kaman-topbar__name text-xs font-semibold truncate max-w-[9rem]">{{ $navUser->name }}</div>
                        <div class="kaman-topbar__role text-[10px] uppercase tracking-wider">
                            {{ $navUser->is_admin ? __('app.administrator') : __('app.member') }}
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                    @csrf
                    <button type="submit" class="kaman-button-ghost kaman-button--sm">
                        @include('app-development.partials.icon', ['name' => 'logout'])
                        {{ __('app.sign_out') }}
                    </button>
                </form>
            @else
                @if($poweredByLabel)
                    <p class="kaman-topbar__powered hidden sm:block text-xs uppercase tracking-[0.2em]">{{ $poweredByLabel }}</p>
                @endif
                @if($poweredByLogo)
                    <img src="{{ $poweredByLogo }}" alt="{{ $poweredByAlt }}" class="h-9 w-auto object-contain">
                @endif
            @endif
        </div>
    </div>

</header>
