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
    $navProjects = $navUser ? $navUser->accessibleProjects() : [];
    $activeProject = $activeProject ?? null;
@endphp

<header class="kaman-topbar sticky top-0 z-30">
    {{-- max-w matches .page-container so the logo lines up with page content. --}}
    <div class="mx-auto w-full max-w-[90rem] px-4 sm:px-6 py-2 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3 min-w-0">
            <a href="{{ $navUser ? route('home') : url('/') }}" class="kaman-logo shrink-0">
                <span class="kaman-logo__glow" aria-hidden="true"></span>
                <img src="{{ $logoPath }}" alt="{{ $logoAlt }}" class="kaman-logo__img">
            </a>
            @if($showTagBadge && $tagText)
                <div class="kaman-topbar__rule hidden sm:inline-flex items-center h-7 border-s ps-3 min-w-0 rtl:border-s-0 rtl:border-e rtl:ps-0 rtl:pe-3">
                    <p class="kaman-topbar__tag text-xs font-semibold uppercase tracking-widest truncate">{{ $tagText }}</p>
                </div>
            @endif
        </div>

        <div class="flex items-center gap-2 sm:gap-3">
            @include('partials.theme-toggle')
            @include('partials.lang-switcher')

            @if($navUser)
                @if($navUser->canAccessProject(Project::AppDevelopment))
                    @include('app-development.partials.bell')
                @endif
                @if(count($navProjects) > 1)
                    <nav class="hidden lg:flex items-center gap-1" aria-label="{{ __('app.projects') }}">
                        @foreach($navProjects as $project)
                            <a href="{{ $project->url() }}"
                               class="kaman-nav-link"
                               @if($activeProject === $project->value) aria-current="page" @endif>
                                {{ $project->label() }}
                            </a>
                        @endforeach
                    </nav>
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

    @if($navUser && count($navProjects) > 1)
        <div class="kaman-topbar__rule lg:hidden border-t px-4 py-2 overflow-x-auto kaman-scroll">
            <nav class="flex items-center gap-1 w-max" aria-label="{{ __('app.projects') }}">
                @foreach($navProjects as $project)
                    <a href="{{ $project->url() }}"
                       class="kaman-nav-link"
                       @if($activeProject === $project->value) aria-current="page" @endif>
                        {{ $project->label() }}
                    </a>
                @endforeach
            </nav>
        </div>
    @endif
</header>
