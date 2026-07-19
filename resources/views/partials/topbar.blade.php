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

<header class="sticky top-0 z-30 bg-white/90 backdrop-blur border-b border-[#f1dfc5]/70">
    {{-- max-w matches .page-container so the logo lines up with page content. --}}
    <div class="mx-auto w-full max-w-[90rem] px-4 sm:px-6 py-2 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3 min-w-0">
            <a href="{{ $navUser ? route('home') : url('/') }}" class="shrink-0">
                <img src="{{ $logoPath }}" alt="{{ $logoAlt }}" class="h-9 w-auto object-contain">
            </a>
            @if($showTagBadge && $tagText)
                <div class="hidden sm:inline-flex items-center h-7 border-s border-[#edd9be]/60 ps-3 min-w-0 rtl:border-s-0 rtl:border-e rtl:ps-0 rtl:pe-3">
                    <p class="text-xs text-[#a78a6c] font-semibold uppercase tracking-widest truncate">{{ $tagText }}</p>
                </div>
            @endif
        </div>

        <div class="flex items-center gap-2 sm:gap-3">
            @include('partials.lang-switcher')

            @if($navUser)
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

                <div class="hidden sm:flex items-center gap-2 ps-2 sm:border-s border-[#edd9be]/60 rtl:border-s-0 rtl:border-e rtl:ps-0 rtl:pe-2">
                    <div class="h-8 w-8 rounded-full bg-[#f47a2e]/12 border border-[#f47a2e]/25 flex items-center justify-center text-[#f16229] text-xs font-bold shrink-0">
                        {{ mb_strtoupper(mb_substr(trim($navUser->name) !== '' ? $navUser->name : $navUser->email, 0, 1)) }}
                    </div>
                    <div class="leading-tight min-w-0">
                        <div class="text-xs font-semibold text-[#2b1e11] truncate max-w-[9rem]">{{ $navUser->name }}</div>
                        <div class="text-[10px] text-[#a78a6c] uppercase tracking-wider">
                            {{ $navUser->is_admin ? __('app.administrator') : __('app.member') }}
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                    @csrf
                    <button type="submit" class="kaman-button-ghost kaman-button--sm">
                        {{ __('app.sign_out') }}
                    </button>
                </form>
            @else
                @if($poweredByLabel)
                    <p class="hidden sm:block text-xs text-[#a78a6c] uppercase tracking-[0.2em]">{{ $poweredByLabel }}</p>
                @endif
                @if($poweredByLogo)
                    <img src="{{ $poweredByLogo }}" alt="{{ $poweredByAlt }}" class="h-9 w-auto object-contain">
                @endif
            @endif
        </div>
    </div>

    @if($navUser && count($navProjects) > 1)
        <div class="lg:hidden border-t border-[#f1dfc5]/60 px-4 py-2 overflow-x-auto kaman-scroll">
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
