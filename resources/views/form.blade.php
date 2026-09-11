<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he']) ? 'rtl' : 'ltr' }}" class="kaman-booting">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('form.title') }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700;800&family=Heebo:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>

    <!-- Shared Kaman design system (body, .kaman-card, .kaman-input, .kaman-button, .stamp-band, .page-container) -->
    <link rel="stylesheet" href="{{ asset('css/kaman.css') }}?v={{ filemtime(public_path('css/kaman.css')) }}">

    <style>
        /* Form-specific styles only. Shared tokens live in public/css/kaman.css. */
        .layout-grid {
            display: grid;
            gap: clamp(1.25rem, 2vw, 2rem);
            align-items: stretch;
            grid-template-columns: minmax(0, 1fr);
        }
        .drink-group {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        .drink-group__header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .drink-group__title {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.32em;
            color: #f47a2e;
        }
        .drink-group__description {
            font-size: 0.7rem;
            color: #a78a6c;
            margin-top: 0.35rem;
        }
        .drink-group__controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 0.85rem;
        }
        .drink-group__select {
            padding: 0.55rem 1.2rem;
            border-radius: 999px;
            border: 1px solid rgba(244, 122, 46, 0.35);
            background: rgba(244, 122, 46, 0.15);
            color: #f16229;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            transition: all 0.2s ease;
        }
        .drink-group__select:hover {
            background: rgba(244, 122, 46, 0.25);
        }
        .drink-group__select.is-active {
            background: linear-gradient(135deg, #f47a2e 0%, #f16229 100%);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 12px 28px rgba(244, 123, 46, 0.25);
        }
        .drink-group__bulk label {
            display: block;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            color: #a78a6c;
            margin-bottom: 0.45rem;
        }
        .drink-group__bulk input {
            width: 8rem;
        }
        .drink-row {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: minmax(160px, 1fr);
            gap: 0.9rem;
            overflow-x: auto;
            padding: 0.5rem 0 1.3rem;
            scroll-snap-type: x proximity;
        }
        .drink-row::-webkit-scrollbar {
            height: 8px;
        }
        .drink-row::-webkit-scrollbar-thumb {
            background: rgba(244, 122, 46, 0.45);
            border-radius: 999px;
        }
        .drink-row::-webkit-scrollbar-track {
            background: rgba(244, 201, 157, 0.35);
            border-radius: 999px;
        }
        .drink-card {
            scroll-snap-align: start;
        }
        .drink-card__header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
        }
        .drink-card__label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            text-align: center;
        }
        .drink-card__image {
            display: inline-flex;
            height: 3.5rem;
            width: 3.5rem;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: rgba(245, 159, 67, 0.16);
        }
        .drink-card__name {
            font-size: 0.85rem;
            font-weight: 600;
            color: #2b1e11;
            line-height: 1.2;
            word-break: break-word;
            max-width: 100%;
        }
        @media (max-width: 768px) {
            .drink-group__controls {
                justify-content: flex-start;
            }
            .drink-group__bulk input {
                width: 100%;
            }
            .drink-row {
                grid-auto-columns: minmax(140px, 1fr);
            }
            .drink-card__name {
                font-size: 0.95rem;
            }
        }
        .form-main-stage {
            display: flex;
            flex-direction: column;
            gap: 0.7rem;
            min-height: 0;
        }
        .form-haat {
            margin-top: 0.35rem;
            border: 1px solid #f1dfc5;
            border-radius: 14px;
            background: #fffaf3;
            padding: 0.35rem 0.7rem 0.7rem;
        }
        .form-haat > summary {
            cursor: pointer;
            list-style: none;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #9a3412;
            padding: 0.55rem 0.15rem;
        }
        .form-haat > summary::-webkit-details-marker { display: none; }
        .form-haat > summary::after {
            content: '▸';
            float: right;
            color: #c7b69d;
        }
        .form-haat[open] > summary::after { content: '▾'; }
        @media (max-width: 1179px) {
            .form-main-stage {
                order: -1;
            }
        }
        @media (min-width: 1180px) {
            .layout-grid {
                grid-template-columns: minmax(0, 1.7fr) minmax(280px, 340px);
                align-items: start;
            }
            .form-main-stage {
                grid-column: 1;
                grid-row: 1;
                position: sticky;
                top: 5.25rem;
                height: calc(100vh - 6.5rem);
            }
            .menu-agent {
                flex: 1 1 0;
                height: auto;
                min-height: 0;
                position: static;
            }
            #workflowDebugPanel {
                flex: 0 0 15.75rem;
                max-height: 15.75rem;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }
            #automationPanels {
                grid-column: 2;
                grid-row: 1;
                position: sticky;
                top: 5.25rem;
                max-height: calc(100vh - 6.5rem);
                overflow-y: auto;
            }
        }
        .menu-agent {
            display: flex;
            flex-direction: column;
            min-height: 22rem;
            overflow: hidden;
            position: relative;
            padding: 1rem 1.1rem 0.9rem;
            animation: menu-agent-in 420ms var(--kaman-ease, cubic-bezier(.22, 1, .36, 1)) both;
        }
        .menu-agent__head {
            padding-bottom: 0.55rem;
            border-bottom: 1px solid var(--kaman-line, #f1dfc5);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .menu-agent__head p { display: none; }
        .menu-agent__new {
            flex-shrink: 0;
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #a78a6c;
            background: transparent;
            border: 0;
            padding: 0.35rem 0.2rem;
            cursor: pointer;
            transition: color 180ms ease, transform 180ms ease;
        }
        .menu-agent__new:hover {
            color: #f16229;
        }
        .menu-agent__log {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            padding: 0.9rem 0;
            min-height: 8rem;
            scroll-behavior: smooth;
        }
        .menu-agent__bubble {
            max-width: 92%;
            border-radius: 1rem;
            padding: 0.7rem 0.9rem;
            font-size: 0.875rem;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
            animation: menu-agent-pop 280ms cubic-bezier(.22, 1, .36, 1) both;
        }
        .menu-agent__bubble--user {
            align-self: flex-end;
            background: #2b1e11;
            color: #fffaf3;
        }
        .menu-agent__bubble--assistant {
            align-self: flex-start;
            background: #fffaf3;
            border: 1px solid #f1dfc5;
            color: #2b1e11;
        }
        .menu-agent__thumbs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.45rem;
        }
        .menu-agent__thumbs img {
            height: 2.5rem;
            width: 2.5rem;
            object-fit: cover;
            border-radius: 0.4rem;
            border: 1px solid rgba(255,255,255,0.35);
            transition: transform 180ms ease;
        }
        .menu-agent__thumbs img:hover {
            transform: scale(1.08);
        }
        .menu-agent__tool {
            align-self: flex-start;
            font-size: 0.7rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            padding: 0.3rem 0.55rem;
            border-radius: 999px;
            background: rgba(244, 122, 46, 0.12);
            color: #9a3412;
            animation: menu-agent-pop 220ms cubic-bezier(.22, 1, .36, 1) both;
        }
        .menu-agent__tool.is-fail {
            background: #fee2e2;
            color: #991b1b;
        }
        .menu-agent__tool.is-pulse {
            animation: menu-agent-pulse 1.1s ease-in-out infinite;
        }
        .menu-agent__composer {
            border-top: 1px solid var(--kaman-line, #f1dfc5);
            padding-top: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
        }
        .menu-agent__pending {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
        }
        .menu-agent__pending-chip {
            font-size: 0.7rem;
            background: #fffaf3;
            border: 1px solid #f1dfc5;
            border-radius: 999px;
            padding: 0.15rem 0.5rem;
            color: #7c6a56;
            transition: transform 160ms ease, border-color 160ms ease;
        }
        .menu-agent__pending-chip:hover {
            transform: translateY(-1px);
            border-color: #f47a2e;
        }
        .menu-agent__row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
            align-items: flex-end;
        }
        .menu-agent__input {
            min-height: 2.75rem;
            resize: none;
            transition: border-color 180ms ease, box-shadow 180ms ease;
        }
        .menu-agent__drop {
            display: none;
            position: absolute;
            inset: 0;
            z-index: 5;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-size: 0.95rem;
            font-weight: 600;
            color: #9a3412;
            background: rgba(255, 250, 243, 0.92);
            border: 2px dashed #f47a2e;
            border-radius: inherit;
            pointer-events: none;
        }
        .menu-agent.is-drop .menu-agent__drop {
            display: flex;
            animation: menu-agent-in 180ms ease both;
        }
        @keyframes menu-agent-in {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: none; }
        }
        @keyframes menu-agent-pop {
            from { opacity: 0; transform: translateY(8px) scale(.98); }
            to { opacity: 1; transform: none; }
        }
        @keyframes menu-agent-pulse {
            0%, 100% { opacity: .55; }
            50% { opacity: 1; }
        }
        @media (prefers-reduced-motion: reduce) {
            .menu-agent,
            .menu-agent__bubble,
            .menu-agent__tool,
            .menu-agent__thumbs img,
            .menu-agent__pending-chip,
            .menu-agent__new,
            .menu-agent__input {
                animation: none !important;
                transition: none !important;
            }
            .menu-agent__log { scroll-behavior: auto; }
        }
    </style>
</head>
<body class="antialiased">
    @include('partials.boot-splash')

    @include('partials.topbar', [
        'tagText' => __('form.tag'),
        'activeProject' => 'form',
    ])

    <main class="relative min-h-screen">
        <div class="stamp-band absolute inset-x-0 -bottom-24 h-32"></div>
        <div class="page-container">
            <div id="layoutGrid" class="layout-grid">
                <div class="space-y-4" id="automationPanels">
                    <div id="unitAutomationPanel" class="kaman-card kaman-card--pad kaman-section">
                    <div class="space-y-1">
                        <h3 class="text-lg font-semibold text-[#2b1e11]">{{ __('form.restaurant_details') }}</h3>
                    </div>

                    <!-- Success Message -->
                    @if(session('success'))
                        <div class="rounded-xl border border-green-200 bg-green-50/70 px-4 py-3 text-left text-sm text-green-700 flex items-start gap-3">
                            <svg class="h-5 w-5 flex-shrink-0 text-green-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <span>{{ session('success') }}</span>
                        </div>
                    @endif

                    <!-- Warning Message -->
                    @if(session('warning'))
                        <div class="rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-left text-sm text-amber-700 flex items-start gap-3">
                            <svg class="h-5 w-5 flex-shrink-0 text-amber-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                            <span>{{ session('warning') }}</span>
                        </div>
                    @endif

                    @error('drinks_payload')
                        <div class="rounded-xl border border-red-200 bg-red-50/70 px-4 py-3 text-left text-sm text-red-600">
                            {{ $message }}
                        </div>
                    @enderror

                    <form id="restaurantForm" class="kaman-form" method="POST" action="{{ route('form.submit') }}" enctype="multipart/form-data">
                        <input type="hidden" name="drinks_payload" id="drinksPayload" value="{{ old('drinks_payload') }}">
                        @csrf

                        <input type="hidden" id="method_type" name="method_type" value="HAAT Menu Copy">

                        <div class="kaman-field">
                            <label for="subdomain" class="kaman-label">
                                {{ __('form.subdomain') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                            </label>
                            <input
                                id="subdomain"
                                name="subdomain"
                                type="text"
                                required
                                value="{{ old('subdomain') }}"
                                class="kaman-input w-full @error('subdomain') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                placeholder="{{ __('form.subdomain_placeholder') }}"
                            >
                            @error('subdomain')
                                <p class="text-xs text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Login action sits inline with its status, not stacked. --}}
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                id="unitLoginBtn"
                                class="kaman-button"
                            >
                                {{ __('app.login') }}
                            </button>
                            <p id="unitLoginStatus" class="text-sm min-h-[1.5em]"></p>
                        </div>

                        <div id="unitLoginCredentials" class="hidden kaman-card--compact rounded-2xl border border-[#f1dfc5] bg-[#fffaf3] space-y-3">
                            <p class="text-xs text-[#7c6a56]">{{ __('form.login_credentials_hint') }}</p>
                            <input type="hidden" id="environment" name="environment" value="{{ old('environment', 'rest') }}">
                            <div class="kaman-form-grid">
                                <div class="kaman-field">
                                    <label for="username" class="kaman-label">
                                        {{ __('form.username') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                                    </label>
                                    <input
                                        id="username"
                                        name="username"
                                        type="text"
                                        autocomplete="username"
                                        value="{{ old('username') }}"
                                        class="kaman-input w-full @error('username') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                        placeholder="{{ __('form.username_placeholder') }}"
                                    >
                                    @error('username')
                                        <p class="text-xs text-red-500">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div class="kaman-field">
                                    <label for="password" class="kaman-label">
                                        {{ __('form.restaurant_password') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                                    </label>
                                    <input
                                        id="password"
                                        name="password"
                                        type="password"
                                        autocomplete="current-password"
                                        class="kaman-input w-full @error('password') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                        placeholder="{{ __('form.restaurant_password_placeholder') }}"
                                    >
                                    @error('password')
                                        <p class="text-xs text-red-500">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <!-- Description (legacy methods; kept hidden) -->
                        <div id="descriptionSection" class="kaman-field hidden">
                            <label for="description" class="kaman-label">
                                {{ __('form.description') }}
                            </label>
                            <textarea
                                id="description"
                                name="description"
                                rows="9"
                                disabled
                                autocomplete="off"
                                spellcheck="false"
                                class="kaman-input w-full @error('description') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                placeholder="{{ __('form.category_store_placeholder') }}"
                            >{{ old('description') }}</textarea>
                            <p id="categoryStoreHint" class="text-xs text-[#a78a6c] whitespace-pre-line leading-relaxed">{{ __('form.category_store_hint') }}</p>
                            <p id="structuredBlocksHint" class="hidden text-xs text-[#a78a6c] whitespace-pre-line leading-relaxed">{{ __('form.structured_blocks_hint') }}</p>
                            @error('description')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <details class="form-haat" id="haatBulkImport">
                            <summary>{{ __('form.haat_bulk_title') }}</summary>
                        <div id="haatMenuSection" class="space-y-3">
                            <div class="kaman-field">
                                <label for="haat_menu_json" class="kaman-label">
                                    {{ __('form.haat_menu_json') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                                </label>
                                <input
                                    id="haat_menu_json"
                                    name="haat_menu_json"
                                    type="file"
                                    accept="application/json,.json"
                                    required
                                    class="kaman-input w-full @error('haat_menu_json') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                >
                                <p class="text-xs text-[#a78a6c]">{{ __('form.haat_menu_json_hint') }}</p>
                                @error('haat_menu_json')
                                    <p class="text-xs text-red-500">{{ $message }}</p>
                                @enderror
                            </div>
                            @if(!empty($haatMenuApiEnabled))
                                <div class="kaman-field">
                                    <label for="haat_restaurant_id" class="kaman-label">{{ __('form.haat_restaurant_id') }}</label>
                                    <input
                                        id="haat_restaurant_id"
                                        name="haat_restaurant_id"
                                        type="text"
                                        value="{{ old('haat_restaurant_id') }}"
                                        class="kaman-input w-full"
                                        placeholder="{{ __('form.haat_restaurant_id_placeholder') }}"
                                    >
                                </div>
                            @endif
                            <label class="flex items-center gap-2 text-sm text-[#2b1e11]">
                                <input type="checkbox" name="no_images" value="1" @checked(old('no_images'))>
                                {{ __('form.haat_no_images') }}
                            </label>
                        </div>

                        <!-- Category logo for Category Store With AI Image -->
                        <div id="categoryLogoSection" class="space-y-2 hidden">
                            <label for="category_logo" class="text-sm font-medium text-[#2b1e11]">
                                Restaurant logo (required for AI category images)
                                <span class="text-xs font-normal text-[#a78a6c] block">
                                    The AI will generate a matching image for each category using this logo’s colors and style.
                                </span>
                            </label>
                            <input
                                id="category_logo"
                                name="category_logo"
                                type="file"
                                accept="image/*"
                                class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('category_logo') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                            >
                            @error('category_logo')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Optional style image for AI meal photos -->
                        <div id="mealAiStyleSection" class="space-y-2 hidden">
                            <label for="meal_style_image" class="text-sm font-medium text-[#2b1e11]">
                                Reference image for AI meals (optional)
                                <span class="text-xs font-normal text-[#a78a6c] block">
                                    Upload one good photo and AI will match its colors, lighting, and style for all generated meal images.
                                </span>
                            </label>
                            <input
                                id="meal_style_image"
                                name="meal_style_image"
                                type="file"
                                accept="image/*"
                                class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('meal_style_image') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                            >
                            @error('meal_style_image')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Category Name (English, optional for store paths) -->
                        <div id="categoryNameEnSection" class="space-y-2 hidden">
                            <label for="category_name_en" class="text-sm font-medium text-[#2b1e11]">
                                {{ __('form.category_name_en') }}
                                <span class="text-xs font-normal text-[#a78a6c]">{{ __('form.category_name_en_desc') }}</span>
                            </label>
                            <input
                                id="category_name_en"
                                name="category_name_en"
                                type="text"
                                value="{{ old('category_name_en') }}"
                                class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('category_name_en') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                placeholder="{{ __('form.category_name_en_placeholder') }}"
                            >
                            @error('category_name_en')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Cold Drinks Selection -->
                        <div id="drinksSection" class="space-y-5 hidden">
                            <div>
                                <p class="text-sm font-medium text-[#2b1e11]">{{ __('form.select_drinks') }}</p>
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.select_drinks_desc') }}</p>
                            </div>

                            @if(!empty($drinksGroups))
                                @foreach($drinksGroups as $groupKey => $group)
                                    <div class="drink-group" data-drink-group="{{ $groupKey }}">
                                        <div class="drink-group__header">
                                            <div>
                                                <p class="drink-group__title">{{ $group['label'] }}</p>
                                                <p class="drink-group__description">Use select all to include every drink in this row or apply a single price to all selected items.</p>
                                            </div>
                                            <div class="drink-group__controls">
                                                <button
                                                    type="button"
                                                    class="drink-group__select"
                                                    data-select-all
                                                    data-group-label="{{ $group['label'] }}"
                                                >
                                                    {{ __('form.select_all') }}
                                                </button>
                                                <div class="drink-group__bulk">
                                                    <label for="bulk-price-{{ $groupKey }}">{{ __('form.bulk_price') }}</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        placeholder="0.00"
                                                        class="kaman-input kaman-input--sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                        id="bulk-price-{{ $groupKey }}"
                                                        data-bulk-price
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                        <div class="drink-row">
                                            @foreach($group['items'] as $drinkIndex => $drink)
                                                <div data-drink-card class="drink-card rounded-2xl border border-[#f1dfc5] bg-white/80 p-4 shadow-sm transition hover:shadow-lg">
                                                    <div class="drink-card__header">
                                                        <input
                                                            type="checkbox"
                                                            class="drink-checkbox h-5 w-5 shrink-0 accent-[#f47a2e]"
                                                            id="drink-{{ $groupKey }}-{{ $drinkIndex }}"
                                                            data-drink-name="{{ $drink['key'] }}"
                                                            data-drink-label="{{ $drink['name'] }}"
                                                        >
                                                        <label for="drink-{{ $groupKey }}-{{ $drinkIndex }}" class="drink-card__label">
                                                            <span class="drink-card__image">
                                                                <img src="{{ $drink['url'] }}" alt="{{ $drink['name'] }}" class="h-12 w-12 object-contain">
                                                            </span>
                                                            <span class="drink-card__name">{{ $drink['name'] }}</span>
                                                        </label>
                                                    </div>
                                                    <div class="mt-3">
                                                        <label for="drink-price-{{ $groupKey }}-{{ $drinkIndex }}" class="text-xs font-medium uppercase tracking-widest text-[#a78a6c]">
                                                            {{ __('form.price') }}
                                                        </label>
                                                        <div class="mt-2">
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                placeholder="0.00"
                                                                class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                                id="drink-price-{{ $groupKey }}-{{ $drinkIndex }}"
                                                                data-price-input
                                                                disabled
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                <p class="rounded-xl border border-dashed border-[#f1dfc5] bg-white/70 px-4 py-6 text-center text-sm text-[#a78a6c]">
                                    No drinks found. Add drink images to the <code class="text-[#f47a2e]">public/ColdDrinks</code> directory to enable this option.
                                </p>
                            @endif

                            <p id="drinksError" class="hidden text-sm text-red-500">Please select at least one drink and provide a valid price.</p>
                        </div>

                        <!-- Hot Drinks Selection -->
                        <div id="hotDrinksSection" class="space-y-5 hidden">
                            <div>
                                <p class="text-sm font-medium text-[#2b1e11]">{{ __('form.select_hot_drinks') }}</p>
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.select_hot_drinks_desc') }}</p>
                            </div>

                            @if(!empty($hotDrinksGroups ?? []))
                                @foreach($hotDrinksGroups as $groupKey => $group)
                                    <div class="drink-group" data-drink-group="{{ $groupKey }}" data-drink-type="hot">
                                        <div class="drink-group__header">
                                            <div>
                                                <p class="drink-group__title">{{ $group['label'] }}</p>
                                                <p class="drink-group__description">Use select all to include every drink in this row or apply a single price to all selected items.</p>
                                            </div>
                                            <div class="drink-group__controls">
                                                <button
                                                    type="button"
                                                    class="drink-group__select"
                                                    data-select-all
                                                    data-group-label="{{ $group['label'] }}"
                                                >
                                                    {{ __('form.select_all') }}
                                                </button>
                                                <div class="drink-group__bulk">
                                                    <label for="bulk-price-{{ $groupKey }}">{{ __('form.bulk_price') }}</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        placeholder="0.00"
                                                        class="kaman-input kaman-input--sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                        id="bulk-price-{{ $groupKey }}"
                                                        data-bulk-price
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                        <div class="drink-row">
                                            @foreach($group['items'] as $drinkIndex => $drink)
                                                <div data-drink-card class="drink-card rounded-2xl border border-[#f1dfc5] bg-white/80 p-4 shadow-sm transition hover:shadow-lg">
                                                    <div class="drink-card__header">
                                                        <input
                                                            type="checkbox"
                                                            class="drink-checkbox h-5 w-5 shrink-0 accent-[#f47a2e]"
                                                            id="hot-drink-{{ $groupKey }}-{{ $drinkIndex }}"
                                                            data-drink-name="{{ $drink['key'] }}"
                                                            data-drink-label="{{ $drink['name'] }}"
                                                        >
                                                        <label for="hot-drink-{{ $groupKey }}-{{ $drinkIndex }}" class="drink-card__label">
                                                            <span class="drink-card__image">
                                                                <img src="{{ $drink['url'] }}" alt="{{ $drink['name'] }}" class="h-12 w-12 object-contain">
                                                            </span>
                                                            <span class="drink-card__name">{{ $drink['name'] }}</span>
                                                        </label>
                                                    </div>
                                                    <div class="mt-3">
                                                        <label for="hot-drink-price-{{ $groupKey }}-{{ $drinkIndex }}" class="text-xs font-medium uppercase tracking-widest text-[#a78a6c]">
                                                            {{ __('form.price') }}
                                                        </label>
                                                        <div class="mt-2">
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                placeholder="0.00"
                                                                class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                                id="hot-drink-price-{{ $groupKey }}-{{ $drinkIndex }}"
                                                                data-price-input
                                                                disabled
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                <p class="rounded-xl border border-dashed border-[#f1dfc5] bg-white/70 px-4 py-6 text-center text-sm text-[#a78a6c]">
                                    No hot drinks found. Add drink images to the <code class="text-[#f47a2e]">public/HotDrinks</code> directory to enable this option.
                                </p>
                            @endif

                            <p id="hotDrinksError" class="hidden text-sm text-red-500">Please select at least one hot drink and provide a valid price.</p>
                        </div>

                        <!-- Natural Juices Selection -->
                        <div id="naturalJuicesSection" class="space-y-5 hidden">
                            <div>
                                <p class="text-sm font-medium text-[#2b1e11]">{{ __('form.select_natural_juices') }}</p>
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.select_natural_juices_desc') }}</p>
                            </div>

                            @if(!empty($naturalJuicesGroups))
                                @foreach($naturalJuicesGroups as $groupKey => $group)
                                    <div class="drink-group" data-drink-group="{{ $groupKey }}">
                                        <div class="drink-group__header">
                                            <div>
                                                <p class="drink-group__title">{{ $group['label'] }}</p>
                                                <p class="drink-group__description">Use select all to include every natural juice in this row or apply a single price to all selected items.</p>
                                            </div>
                                            <div class="drink-group__controls">
                                                <button
                                                    type="button"
                                                    class="drink-group__select"
                                                    data-select-all
                                                    data-group-label="{{ $group['label'] }}"
                                                >
                                                    {{ __('form.select_all') }}
                                                </button>
                                                <div class="drink-group__bulk">
                                                    <label for="bulk-price-{{ $groupKey }}">{{ __('form.bulk_price') }}</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        placeholder="0.00"
                                                        class="kaman-input kaman-input--sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                        id="bulk-price-{{ $groupKey }}"
                                                        data-bulk-price
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                        <div class="drink-row">
                                            @foreach($group['items'] as $juiceIndex => $juice)
                                                <div data-drink-card class="drink-card rounded-2xl border border-[#f1dfc5] bg-white/80 p-4 shadow-sm transition hover:shadow-lg">
                                                    <div class="drink-card__header">
                                                        <input
                                                            type="checkbox"
                                                            class="drink-checkbox h-5 w-5 shrink-0 accent-[#f47a2e]"
                                                            id="juice-{{ $groupKey }}-{{ $juiceIndex }}"
                                                            data-drink-name="{{ $juice['key'] }}"
                                                            data-drink-label="{{ $juice['name'] }}"
                                                            data-drink-name-ar="{{ $juice['name_ar'] ?? '' }}"
                                                        >
                                                        <label for="juice-{{ $groupKey }}-{{ $juiceIndex }}" class="drink-card__label">
                                                            <span class="drink-card__image">
                                                                <img src="{{ $juice['url'] }}" alt="{{ $juice['name'] }}" class="h-12 w-12 object-contain">
                                                            </span>
                                                            <span class="drink-card__name">{{ $juice['name'] }}</span>
                                                        </label>
                                                    </div>
                                                    <div class="mt-3">
                                                        <label for="juice-price-{{ $groupKey }}-{{ $juiceIndex }}" class="text-xs font-medium uppercase tracking-widest text-[#a78a6c]">
                                                            {{ __('form.price') }}
                                                        </label>
                                                        <div class="mt-2">
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                placeholder="0.00"
                                                                class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                                id="juice-price-{{ $groupKey }}-{{ $juiceIndex }}"
                                                                data-price-input
                                                                disabled
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                <p class="rounded-xl border border-dashed border-[#f1dfc5] bg-white/70 px-4 py-6 text-center text-sm text-[#a78a6c]">
                                    No natural juices found. Add juice images to the <code class="text-[#f47a2e]">public/NaturalJuice</code> directory to enable this option.
                                </p>
                            @endif

                            <p id="naturalJuicesError" class="hidden text-sm text-red-500">Please select at least one natural juice and provide a valid price.</p>
                        </div>

                        <!-- Sweets Selection -->
                        <div id="sweetsSection" class="space-y-5 hidden">
                            <div>
                                <p class="text-sm font-medium text-[#2b1e11]">{{ __('form.select_sweets') }}</p>
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.select_sweets_desc') }}</p>
                            </div>

                            @if(!empty($sweetsGroups ?? []))
                                @foreach($sweetsGroups as $groupKey => $group)
                                    <div class="drink-group" data-drink-group="{{ $groupKey }}">
                                        <div class="drink-group__header">
                                            <div>
                                                <p class="drink-group__title">{{ $group['label'] }}</p>
                                                <p class="drink-group__description">Use select all to include every sweet in this row or apply a single price to all selected items.</p>
                                            </div>
                                            <div class="drink-group__controls">
                                                <button
                                                    type="button"
                                                    class="drink-group__select"
                                                    data-select-all
                                                    data-group-label="{{ $group['label'] }}"
                                                >
                                                    {{ __('form.select_all') }}
                                                </button>
                                                <div class="drink-group__bulk">
                                                    <label for="bulk-price-{{ $groupKey }}">{{ __('form.bulk_price') }}</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        placeholder="0.00"
                                                        class="kaman-input kaman-input--sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                        id="bulk-price-{{ $groupKey }}"
                                                        data-bulk-price
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                        <div class="drink-row">
                                            @foreach($group['items'] as $sweetIndex => $sweet)
                                                <div data-drink-card class="drink-card rounded-2xl border border-[#f1dfc5] bg-white/80 p-4 shadow-sm transition hover:shadow-lg">
                                                    <div class="drink-card__header">
                                                        <input
                                                            type="checkbox"
                                                            class="drink-checkbox h-5 w-5 shrink-0 accent-[#f47a2e]"
                                                            id="sweet-{{ $groupKey }}-{{ $sweetIndex }}"
                                                            data-drink-name="{{ $sweet['key'] }}"
                                                            data-drink-label="{{ $sweet['name'] }}"
                                                        >
                                                        <label for="sweet-{{ $groupKey }}-{{ $sweetIndex }}" class="drink-card__label">
                                                            <span class="drink-card__image">
                                                                <img src="{{ $sweet['url'] }}" alt="{{ $sweet['name'] }}" class="h-12 w-12 object-contain">
                                                            </span>
                                                            <span class="drink-card__name">{{ $sweet['name'] }}</span>
                                                        </label>
                                                    </div>
                                                    <div class="mt-3">
                                                        <label for="sweet-price-{{ $groupKey }}-{{ $sweetIndex }}" class="text-xs font-medium uppercase tracking-widest text-[#a78a6c]">
                                                            {{ __('form.price') }}
                                                        </label>
                                                        <div class="mt-2">
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                placeholder="0.00"
                                                                class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                                id="sweet-price-{{ $groupKey }}-{{ $sweetIndex }}"
                                                                data-price-input
                                                                disabled
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                <p class="rounded-xl border border-dashed border-[#f1dfc5] bg-white/70 px-4 py-6 text-center text-sm text-[#a78a6c]">
                                    No sweets found. Add sweet images to the <code class="text-[#f47a2e]">public/Sweets</code> directory to enable this option.
                                </p>
                            @endif

                            <p id="sweetsError" class="hidden text-sm text-red-500">Please select at least one sweet and provide a valid price.</p>
                        </div>

                        <!-- Pasta Meals Selection -->
                        <div id="pastaMealsSection" class="space-y-5 hidden">
                            <div>
                                <p class="text-sm font-medium text-[#2b1e11]">{{ __('form.select_pasta_meals') }}</p>
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.select_pasta_meals_desc') }}</p>
                            </div>

                            @if(!empty($pastaMealsGroups ?? []))
                                @foreach($pastaMealsGroups as $groupKey => $group)
                                    <div class="drink-group" data-drink-group="{{ $groupKey }}">
                                        <div class="drink-group__header">
                                            <div>
                                                <p class="drink-group__title">{{ $group['label'] }}</p>
                                                <p class="drink-group__description">Use select all to include every pasta meal in this row or apply a single price to all selected items.</p>
                                            </div>
                                            <div class="drink-group__controls">
                                                <button
                                                    type="button"
                                                    class="drink-group__select"
                                                    data-select-all
                                                    data-group-label="{{ $group['label'] }}"
                                                >
                                                    {{ __('form.select_all') }}
                                                </button>
                                                <div class="drink-group__bulk">
                                                    <label for="bulk-price-{{ $groupKey }}">{{ __('form.bulk_price') }}</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        placeholder="0.00"
                                                        class="kaman-input kaman-input--sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                        id="bulk-price-{{ $groupKey }}"
                                                        data-bulk-price
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                        <div class="drink-row">
                                            @foreach($group['items'] as $pastaIndex => $pasta)
                                                <div data-drink-card class="drink-card rounded-2xl border border-[#f1dfc5] bg-white/80 p-4 shadow-sm transition hover:shadow-lg">
                                                    <div class="drink-card__header">
                                                        <input
                                                            type="checkbox"
                                                            class="drink-checkbox h-5 w-5 shrink-0 accent-[#f47a2e]"
                                                            id="pasta-{{ $groupKey }}-{{ $pastaIndex }}"
                                                            data-drink-name="{{ $pasta['key'] }}"
                                                            data-drink-label="{{ $pasta['name'] }}"
                                                        >
                                                        <label for="pasta-{{ $groupKey }}-{{ $pastaIndex }}" class="drink-card__label">
                                                            <span class="drink-card__image">
                                                                <img src="{{ $pasta['url'] }}" alt="{{ $pasta['name'] }}" class="h-12 w-12 object-contain">
                                                            </span>
                                                            <span class="drink-card__name">{{ $pasta['name'] }}</span>
                                                        </label>
                                                    </div>
                                                    <div class="mt-3">
                                                        <label for="pasta-price-{{ $groupKey }}-{{ $pastaIndex }}" class="text-xs font-medium uppercase tracking-widest text-[#a78a6c]">
                                                            {{ __('form.price') }}
                                                        </label>
                                                        <div class="mt-2">
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                placeholder="0.00"
                                                                class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                                id="pasta-price-{{ $groupKey }}-{{ $pastaIndex }}"
                                                                data-price-input
                                                                disabled
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                <p class="rounded-xl border border-dashed border-[#f1dfc5] bg-white/70 px-4 py-6 text-center text-sm text-[#a78a6c]">
                                    No pasta meals found. Add pasta images to the <code class="text-[#f47a2e]">public/pasta</code> directory to enable this option.
                                </p>
                            @endif

                            <p id="pastaMealsError" class="hidden text-sm text-red-500">Please select at least one pasta meal and provide a valid price.</p>
                        </div>

                        <!-- Sandwiches Selection -->
                        <div id="sandwichesSection" class="space-y-5 hidden">
                            <div>
                                <p class="text-sm font-medium text-[#2b1e11]">{{ __('form.select_sandwiches') }}</p>
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.select_sandwiches_desc') }}</p>
                            </div>

                            @if(!empty($sandwichGroups ?? []))
                                @foreach($sandwichGroups as $groupKey => $group)
                                    <div class="drink-group" data-drink-group="{{ $groupKey }}">
                                        <div class="drink-group__header">
                                            <div>
                                                <p class="drink-group__title">{{ $group['label'] }}</p>
                                                <p class="drink-group__description">Use select all to include every sandwich in this row or apply a single price to all selected items.</p>
                                            </div>
                                            <div class="drink-group__controls">
                                                <button
                                                    type="button"
                                                    class="drink-group__select"
                                                    data-select-all
                                                    data-group-label="{{ $group['label'] }}"
                                                >
                                                    {{ __('form.select_all') }}
                                                </button>
                                                <div class="drink-group__bulk">
                                                    <label for="bulk-price-{{ $groupKey }}">{{ __('form.bulk_price') }}</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        placeholder="0.00"
                                                        class="kaman-input kaman-input--sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                        id="bulk-price-{{ $groupKey }}"
                                                        data-bulk-price
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                        <div class="drink-row">
                                            @foreach($group['items'] as $sandwichIndex => $sandwich)
                                                <div data-drink-card class="drink-card rounded-2xl border border-[#f1dfc5] bg-white/80 p-4 shadow-sm transition hover:shadow-lg">
                                                    <div class="drink-card__header">
                                                        <input
                                                            type="checkbox"
                                                            class="drink-checkbox h-5 w-5 shrink-0 accent-[#f47a2e]"
                                                            id="sandwich-{{ $groupKey }}-{{ $sandwichIndex }}"
                                                            data-drink-name="{{ $sandwich['key'] }}"
                                                            data-drink-label="{{ $sandwich['name'] }}"
                                                        >
                                                        <label for="sandwich-{{ $groupKey }}-{{ $sandwichIndex }}" class="drink-card__label">
                                                            <span class="drink-card__image">
                                                                <img src="{{ $sandwich['url'] }}" alt="{{ $sandwich['name'] }}" class="h-12 w-12 object-contain">
                                                            </span>
                                                            <span class="drink-card__name">{{ $sandwich['name'] }}</span>
                                                        </label>
                                                    </div>
                                                    <div class="mt-3">
                                                        <label for="sandwich-price-{{ $groupKey }}-{{ $sandwichIndex }}" class="text-xs font-medium uppercase tracking-widest text-[#a78a6c]">
                                                            {{ __('form.price') }}
                                                        </label>
                                                        <div class="mt-2">
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                placeholder="0.00"
                                                                class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                                id="sandwich-price-{{ $groupKey }}-{{ $sandwichIndex }}"
                                                                data-price-input
                                                                disabled
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                <p class="rounded-xl border border-dashed border-[#f1dfc5] bg-white/70 px-4 py-6 text-center text-sm text-[#a78a6c]">
                                    No sandwiches found. Add sandwich images to the <code class="text-[#f47a2e]">public/sandwiches</code> directory to enable this option.
                                </p>
                            @endif

                            <p id="sandwichesError" class="hidden text-sm text-red-500">Please select at least one sandwich and provide a valid price.</p>
                        </div>

                        <!-- Ingredients Selection -->
                        <div id="ingredientsSection" class="space-y-5 hidden">
                            <div>
                                <p class="text-sm font-medium text-[#2b1e11]">{{ __('form.select_ingredients') }}</p>
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.select_ingredients_desc') }}</p>
                            </div>

                            @if(!empty($ingredientsGroups ?? []))
                                @foreach($ingredientsGroups as $groupKey => $group)
                                    <div class="drink-group" data-drink-group="{{ $groupKey }}">
                                        <div class="drink-group__header">
                                            <div>
                                                <p class="drink-group__title">{{ $group['label'] }}</p>
                                                <p class="drink-group__description">Use select all to include every ingredient in this row or apply a single price to all selected items.</p>
                                            </div>
                                            <div class="drink-group__controls">
                                                <button
                                                    type="button"
                                                    class="drink-group__select"
                                                    data-select-all
                                                    data-group-label="{{ $group['label'] }}"
                                                >
                                                    {{ __('form.select_all') }}
                                                </button>
                                                <div class="drink-group__bulk">
                                                    <label for="bulk-price-{{ $groupKey }}">{{ __('form.bulk_price') }}</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        placeholder="0.00"
                                                        class="kaman-input kaman-input--sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                        id="bulk-price-{{ $groupKey }}"
                                                        data-bulk-price
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                        <div class="drink-row">
                                            @foreach($group['items'] as $ingredientIndex => $ingredient)
                                                <div data-drink-card class="drink-card rounded-2xl border border-[#f1dfc5] bg-white/80 p-4 shadow-sm transition hover:shadow-lg">
                                                    <div class="drink-card__header">
                                                        <input
                                                            type="checkbox"
                                                            class="drink-checkbox h-5 w-5 shrink-0 accent-[#f47a2e]"
                                                            id="ingredient-{{ $groupKey }}-{{ $ingredientIndex }}"
                                                            data-drink-name="{{ $ingredient['key'] }}"
                                                            data-drink-label="{{ $ingredient['name'] }}"
                                                        >
                                                        <label for="ingredient-{{ $groupKey }}-{{ $ingredientIndex }}" class="drink-card__label">
                                                            <span class="drink-card__image">
                                                                <img src="{{ $ingredient['url'] }}" alt="{{ $ingredient['name'] }}" class="h-12 w-12 object-contain">
                                                            </span>
                                                            <span class="drink-card__name">{{ $ingredient['name'] }}</span>
                                                        </label>
                                                    </div>
                                                    <div class="mt-3">
                                                        <label for="ingredient-price-{{ $groupKey }}-{{ $ingredientIndex }}" class="text-xs font-medium uppercase tracking-widest text-[#a78a6c]">
                                                            {{ __('form.price') }}
                                                        </label>
                                                        <div class="mt-2">
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                placeholder="0.00"
                                                                class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                                id="ingredient-price-{{ $groupKey }}-{{ $ingredientIndex }}"
                                                                data-price-input
                                                                disabled
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                <p class="rounded-xl border border-dashed border-[#f1dfc5] bg-white/70 px-4 py-6 text-center text-sm text-[#a78a6c]">
                                    No ingredients found. Add ingredient images to the <code class="text-[#f47a2e]">public/ingredients</code> directory to enable this option.
                                </p>
                            @endif

                            <p id="ingredientsError" class="hidden text-sm text-red-500">Please select at least one ingredient and provide a valid price.</p>
                        </div>

                        <!-- Burger Selection -->
                        <div id="burgerSection" class="space-y-5 hidden">
                            <div>
                                <p class="text-sm font-medium text-[#2b1e11]">{{ __('form.select_burgers') }}</p>
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.select_burgers_desc') }}</p>
                            </div>

                            @if(!empty($burgerGroups ?? []))
                                @foreach($burgerGroups as $groupKey => $group)
                                    <div class="drink-group" data-drink-group="{{ $groupKey }}">
                                        <div class="drink-group__header">
                                            <div>
                                                <p class="drink-group__title">{{ $group['label'] }}</p>
                                                <p class="drink-group__description">Use select all to include every burger in this row or apply a single price to all selected items.</p>
                                            </div>
                                            <div class="drink-group__controls">
                                                <button
                                                    type="button"
                                                    class="drink-group__select"
                                                    data-select-all
                                                    data-group-label="{{ $group['label'] }}"
                                                >
                                                    {{ __('form.select_all') }}
                                                </button>
                                                <div class="drink-group__bulk">
                                                    <label for="bulk-price-{{ $groupKey }}">{{ __('form.bulk_price') }}</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        placeholder="0.00"
                                                        class="kaman-input kaman-input--sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                        id="bulk-price-{{ $groupKey }}"
                                                        data-bulk-price
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                        <div class="drink-row">
                                            @foreach($group['items'] as $burgerIndex => $burger)
                                                <div data-drink-card class="drink-card rounded-2xl border border-[#f1dfc5] bg-white/80 p-4 shadow-sm transition hover:shadow-lg">
                                                    <div class="drink-card__header">
                                                        <input
                                                            type="checkbox"
                                                            class="drink-checkbox h-5 w-5 shrink-0 accent-[#f47a2e]"
                                                            id="burger-{{ $groupKey }}-{{ $burgerIndex }}"
                                                            data-drink-name="{{ $burger['key'] }}"
                                                            data-drink-label="{{ $burger['name'] }}"
                                                        >
                                                        <label for="burger-{{ $groupKey }}-{{ $burgerIndex }}" class="drink-card__label">
                                                            <span class="drink-card__image">
                                                                <img src="{{ $burger['url'] }}" alt="{{ $burger['name'] }}" class="h-12 w-12 object-contain">
                                                            </span>
                                                            <span class="drink-card__name">{{ $burger['name'] }}</span>
                                                        </label>
                                                    </div>
                                                    <div class="mt-3">
                                                        <label for="burger-price-{{ $groupKey }}-{{ $burgerIndex }}" class="text-xs font-medium uppercase tracking-widest text-[#a78a6c]">
                                                            {{ __('form.price') }}
                                                        </label>
                                                        <div class="mt-2">
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                placeholder="0.00"
                                                                class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                                                id="burger-price-{{ $groupKey }}-{{ $burgerIndex }}"
                                                                data-price-input
                                                                disabled
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                <p class="rounded-xl border border-dashed border-[#f1dfc5] bg-white/70 px-4 py-6 text-center text-sm text-[#a78a6c]">
                                    No burgers found. Add burger images to the <code class="text-[#f47a2e]">public/burger</code> directory to enable this option.
                                </p>
                            @endif

                            <p id="burgerError" class="hidden text-sm text-red-500">Please select at least one burger and provide a valid price.</p>
                        </div>

                        <!-- Custom Images Meals Store Section -->
                        <div id="customImagesMealsSection" class="space-y-5 hidden">
                            <div>
                                <p class="text-sm font-medium text-[#2b1e11]">{{ __('form.upload_meal_images') }}</p>
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.upload_meal_images_desc') }}</p>
                            </div>

                            <!-- Folder Name Field -->
                            <div class="space-y-2">
                                <label for="folder_name" class="text-sm font-medium text-[#2b1e11]">
                                    {{ __('form.folder_name') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                                    <span class="text-xs font-normal text-[#a78a6c]">{{ __('form.folder_name_desc') }}</span>
                                </label>
                                <input
                                    id="folder_name"
                                    name="folder_name"
                                    type="text"
                                    value="{{ old('folder_name') }}"
                                    class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('folder_name') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                    placeholder="{{ __('form.folder_name_placeholder') }}"
                                >
                                @error('folder_name')
                                    <p class="text-sm text-red-500">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Image Upload Field -->
                            <div class="space-y-2">
                                <label for="meal_images" class="text-sm font-medium text-[#2b1e11]">
                                    {{ __('form.meal_images') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                                    <span class="text-xs font-normal text-[#a78a6c]">{{ __('form.meal_images_desc') }}</span>
                                </label>
                                <input
                                    id="meal_images"
                                    name="meal_images[]"
                                    type="file"
                                    accept="image/*"
                                    multiple
                                    class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('meal_images') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                >
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.meal_images_help') }}</p>
                                <div id="imagePreview" class="mt-3 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 hidden"></div>
                                <p id="imageCount" class="text-xs text-[#a78a6c] mt-2 hidden"></p>
                                @error('meal_images')
                                    <p class="text-sm text-red-500">{{ $message }}</p>
                                @enderror
                                @error('meal_images.*')
                                    <p class="text-sm text-red-500">{{ $message }}</p>
                                @enderror
                            </div>

                            <p id="customImagesMealsError" class="hidden text-sm text-red-500">{{ __('form.error_folder_name') }}</p>
                        </div>

                        <!-- Custom Image Named Section -->
                        <div id="customImageNamedSection" class="space-y-5 hidden">
                            <div>
                                <p class="text-sm font-medium text-[#2b1e11]">{{ __('form.upload_folder_images') }}</p>
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.upload_folder_images_desc') }}</p>
                            </div>

                            <!-- Folder Upload Field -->
                            <div class="space-y-2">
                                <label for="folder_upload" class="text-sm font-medium text-[#2b1e11]">
                                    {{ __('form.choose_folder') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                                    <span class="text-xs font-normal text-[#a78a6c]">{{ __('form.choose_folder_desc') }}</span>
                                </label>
                                <input
                                    id="folder_upload"
                                    name="folder_upload[]"
                                    type="file"
                                    accept="image/*"
                                    webkitdirectory
                                    directory
                                    multiple
                                    class="kaman-input w-full text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('folder_upload') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                >
                                <p class="text-xs text-[#a78a6c] mt-1">{{ __('form.choose_folder_help') }}</p>
                                <div id="folderUploadPreview" class="mt-3 space-y-1 hidden">
                                    <p id="folderUploadFolderName" class="text-sm font-medium text-[#2b1e11]"></p>
                                    <p id="folderUploadFileCount" class="text-xs text-[#a78a6c]"></p>
                                </div>
                                @error('folder_upload')
                                    <p class="text-sm text-red-500">{{ $message }}</p>
                                @enderror
                            </div>

                            <p id="customImageNamedError" class="hidden text-sm text-red-500">{{ __('form.error_choose_folder') }}</p>
                        </div>

                        <!-- Actions -->
                        <div class="space-y-4">
                            <button
                                type="submit"
                                class="kaman-button w-full font-semibold uppercase tracking-wide text-white transition duration-200"
                            >
                                {{ __('form.haat_submit') }}
                            </button>
                            <div class="text-center">
                                <a href="{{ url('/') }}" class="text-sm font-semibold text-[#f47a2e] hover:text-[#f16229] transition">
                                    {{ __('form.back_to_home') }}
                                </a>
                            </div>
                        </div>
                        </details>
                    </form>

                    @php
                        $formRuns = $formWorkflowRuns ?? [];
                        $formRunsRunning = [];
                        $formRunsHistory = [];
                        foreach ($formRuns as $formRunRow) {
                            if (in_array($formRunRow['status'] ?? '', ['running', 'paused'], true)) {
                                $formRunsRunning[] = $formRunRow;
                            } else {
                                $formRunsHistory[] = $formRunRow;
                            }
                        }
                        $formRunStatusLabel = static function (string $status): string {
                            return match ($status) {
                                'running' => __('form.runs_status_running'),
                                'paused' => __('form.runs_paused'),
                                'completed' => __('form.runs_completed'),
                                'failed' => __('form.runs_failed'),
                                default => $status,
                            };
                        };
                        $formRunWhen = static function (array $run): string {
                            $raw = $run['finished_at'] ?? $run['paused_at'] ?? $run['started_at'] ?? $run['created_at'] ?? '';
                            if (! is_string($raw) || $raw === '') {
                                return '';
                            }
                            try {
                                return \Illuminate\Support\Carbon::parse($raw)->timezone((string) config('app.timezone'))->format('Y-m-d H:i');
                            } catch (\Throwable) {
                                return $raw;
                            }
                        };
                    @endphp
                    <section id="formRunsPanel" class="form-runs" aria-live="polite">
                        <div class="form-runs__head">
                            <h3 class="form-runs__title">{{ __('form.runs_title') }}</h3>
                        </div>
                        <div id="formRunsEmpty" class="form-runs__empty" @if($formRuns !== []) hidden @endif>
                            {{ __('form.runs_empty') }}
                        </div>
                        <div id="formRunsRunning" class="form-runs__group" @if($formRunsRunning === []) hidden @endif>
                            <div class="form-runs__label">{{ __('form.runs_running') }}</div>
                            <div id="formRunsRunningList">
                                @foreach ($formRunsRunning as $run)
                                    <div class="form-runs__item" data-run-id="{{ $run['id'] }}">
                                        <div class="form-runs__meta">
                                            <div class="form-runs__name">{{ $run['title'] ?? (($run['method_type'] ?? 'Workflow').(! empty($run['subdomain']) ? ' · '.$run['subdomain'] : '')) }}</div>
                                            <div class="form-runs__sub"><span class="form-runs__badge is-{{ $run['status'] }}">{{ $formRunStatusLabel((string) $run['status']) }}</span>{{ $formRunWhen($run) }}@if (! empty($run['user_name']) && empty($run['is_mine'])) · {{ __('form.runs_by') }} {{ $run['user_name'] }}@endif</div>
                                        </div>
                                        <div class="form-runs__actions">
                                            @if (! empty($run['is_running']))
                                                <button type="button" class="form-runs__btn form-runs__btn--pause" data-run-pause="{{ $run['id'] }}">{{ __('form.runs_pause') }}</button>
                                            @elseif (! empty($run['can_continue']))
                                                <button type="button" class="form-runs__btn form-runs__btn--continue" data-run-continue="{{ $run['id'] }}">{{ __('form.runs_continue') }}</button>
                                            @endif
                                            @if (! empty($run['can_delete']))
                                                <button type="button" class="form-runs__btn form-runs__btn--delete" data-run-delete="{{ $run['id'] }}">{{ __('form.runs_delete') }}</button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div id="formRunsHistory" class="form-runs__group" @if($formRunsHistory === []) hidden @endif>
                            <div class="form-runs__label">{{ __('form.runs_history') }}</div>
                            <div id="formRunsHistoryList">
                                @foreach ($formRunsHistory as $run)
                                    <div class="form-runs__item" data-run-id="{{ $run['id'] }}">
                                        <div class="form-runs__meta">
                                            <div class="form-runs__name">{{ $run['title'] ?? (($run['method_type'] ?? 'Workflow').(! empty($run['subdomain']) ? ' · '.$run['subdomain'] : '')) }}</div>
                                            <div class="form-runs__sub"><span class="form-runs__badge is-{{ $run['status'] }}">{{ $formRunStatusLabel((string) $run['status']) }}</span>{{ $formRunWhen($run) }}@if (! empty($run['user_name']) && empty($run['is_mine'])) · {{ __('form.runs_by') }} {{ $run['user_name'] }}@endif</div>
                                        </div>
                                        <div class="form-runs__actions">
                                            @if (! empty($run['can_continue']))
                                                <button type="button" class="form-runs__btn form-runs__btn--continue" data-run-continue="{{ $run['id'] }}">{{ __('form.runs_continue') }}</button>
                                            @endif
                                            @if (! empty($run['can_delete']))
                                                <button type="button" class="form-runs__btn form-runs__btn--delete" data-run-delete="{{ $run['id'] }}">{{ __('form.runs_delete') }}</button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </section>
                </div>
                </div>

                <div id="formMainStage" class="form-main-stage">
                <aside id="menuAgentPanel" class="menu-agent kaman-card" aria-label="{{ __('form.agent_title') }}">
                    <div class="menu-agent__drop" id="menuAgentDrop">{{ __('form.agent_drop') }}</div>
                    <div class="menu-agent__head">
                        <div>
                            <h3 class="text-xl font-semibold text-[#2b1e11]">{{ __('form.agent_title') }}</h3>
                            <p class="text-sm text-[#a78a6c] mt-1">{{ __('form.agent_subtitle') }}</p>
                        </div>
                        <button type="button" id="menuAgentNew" class="menu-agent__new">{{ __('form.agent_new') }}</button>
                    </div>
                    <div id="menuAgentLog" class="menu-agent__log">
                        <p id="menuAgentEmpty" class="text-sm text-[#a78a6c] m-auto text-center px-4">{{ __('form.agent_empty') }}</p>
                    </div>
                    <form id="menuAgentForm" class="menu-agent__composer">
                        <p id="menuAgentLoginHint" class="text-xs text-amber-700">{{ __('form.agent_need_login') }}</p>
                        <div id="menuAgentPending" class="menu-agent__pending" hidden></div>
                        <div class="menu-agent__row">
                            <label class="kaman-button-ghost shrink-0 cursor-pointer">
                                <input id="menuAgentFiles" type="file" class="hidden" multiple accept="image/*,application/pdf,.json,.txt,.csv">
                                {{ __('form.agent_attach') }}
                            </label>
                            <textarea
                                id="menuAgentInput"
                                class="kaman-input menu-agent__input w-full"
                                rows="2"
                                placeholder="{{ __('form.agent_placeholder') }}"
                                disabled
                            ></textarea>
                            <button id="menuAgentSend" type="submit" class="kaman-button shrink-0" disabled>{{ __('form.agent_send') }}</button>
                        </div>
                    </form>
                </aside>

                    <div id="workflowDebugPanel" class="wf-debug">
                        <div class="wf-debug__head">
                            <span class="wf-debug__title">{{ __('form.debug_title') }}</span>
                            <div id="workflowRunControls" class="wf-debug__controls" hidden>
                                <button type="button" id="workflowPauseBtn" class="wf-debug__ctrl wf-debug__ctrl--pause">{{ __('form.runs_pause') }}</button>
                                <button type="button" id="workflowContinueBtn" class="wf-debug__ctrl wf-debug__ctrl--continue" hidden>{{ __('form.runs_continue') }}</button>
                            </div>
                            <span id="workflowDebugBadge" class="wf-debug__badge"></span>
                        </div>
                        <div id="workflowDebugContent" class="wf-debug__body">
                            <div id="workflowDebugPlaceholder" class="wf-debug__placeholder hidden">
                                {{ __('form.debug_placeholder') }}
                            </div>
                            <div id="workflowDebugData" class="wf-debug__data">
                                <p id="workflowDebugBanner" class="wf-debug__banner hidden"></p>
                                <ol id="workflowStepTrack" class="wf-debug__steps" hidden></ol>
                                <div id="workflowCountGrid" class="wf-debug__counts" hidden></div>
                                <div class="wf-debug__split">
                                    <div id="workflowLiveLog" class="wf-debug__log">
                                        <div id="workflowLiveLogEntries" class="wf-debug__log-body" tabindex="0"></div>
                                    </div>
                                    <div class="wf-debug__side" hidden>
                                        <div class="wf-debug__meta">
                                            <strong id="debugMethodType">—</strong>
                                            <span id="debugTimestamp"></span>
                                        </div>
                                        <h4>Summary</h4>
                                        <ul id="debugSummaryList" class="wf-debug__summary">
                                            <li>No workflow run yet.</li>
                                        </ul>
                                        <div id="workflowFailures" class="wf-debug__fails hidden">
                                            <h4>Failures</h4>
                                            <div id="workflowFailureRows"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <script>
        window.__workflowDebugFromSession = @json(session('workflow_debug'));
        window.__formWorkflowRuns = @json($formWorkflowRuns ?? []);
        window.__formRunRoutes = {
            list: @json(route('form.runs.index')),
        };
        window.__kamanRestaurantAuthenticated = false;
        function setKamanRestaurantAuthenticated(value) {
            window.__kamanRestaurantAuthenticated = !!value;
            window.dispatchEvent(new CustomEvent('kaman-auth-changed', { detail: { authenticated: !!value } }));
        }
        const FORM_AUTH_ROUTES = {
            authStatus: @json(route('form.full-ai.auth-status')),
            login: @json(route('form.full-ai.login')),
            menuAgent: @json(route('form.menu-agent.chat')),
        };

        const FORM_SESSION_KEYS = {
            subdomain: 'webtimize_form_subdomain',
            environment: 'webtimize_form_environment',
            savedCredentials: 'webtimize_form_saved_credentials',
        };

        function getSavedCredentials() {
            try {
                const raw = sessionStorage.getItem(FORM_SESSION_KEYS.savedCredentials);
                if (!raw) {
                    return null;
                }
                const parsed = JSON.parse(raw);
                if (!parsed || typeof parsed !== 'object') {
                    return null;
                }
                return parsed;
            } catch (e) {
                return null;
            }
        }

        function hasSavedCredentials() {
            const saved = getSavedCredentials();
            return !!(saved && saved.username && saved.password);
        }

        function saveCredentials({ username, password, environment }) {
            try {
                sessionStorage.setItem(FORM_SESSION_KEYS.savedCredentials, JSON.stringify({
                    username: username,
                    password: password,
                    environment: environment || 'rest',
                }));
            } catch (e) {
                /* sessionStorage may be unavailable */
            }
        }

        function syncCredentialFields(username, password, environment) {
            const usernameEl = document.getElementById('username');
            const passwordEl = document.getElementById('password');
            const environmentEl = document.getElementById('environment');
            if (usernameEl && username !== undefined && username !== null) {
                usernameEl.value = username;
            }
            if (passwordEl && password !== undefined && password !== null) {
                passwordEl.value = password;
            }
            if (environmentEl && environment !== undefined && environment !== null) {
                environmentEl.value = environment || 'rest';
            }
        }

        function resetLoginCredentialsPanel() {
            const panel = document.getElementById('unitLoginCredentials');
            if (!panel) {
                return;
            }
            panel.classList.add('hidden');
            const usernameEl = document.getElementById('username');
            const passwordEl = document.getElementById('password');
            if (usernameEl) {
                usernameEl.value = '';
            }
            if (passwordEl) {
                passwordEl.value = '';
            }
        }

        function hideLoginCredentialsPanel() {
            const panel = document.getElementById('unitLoginCredentials');
            if (panel) {
                panel.classList.add('hidden');
            }
        }

        function hideLoginCredentialsPanels() {
            resetLoginCredentialsPanel();
        }

        function showLoginCredentialsPanel(panelId) {
            const panel = document.getElementById(panelId);
            if (panel) {
                panel.classList.remove('hidden');
            }
        }

        function isLoginCredentialsPanelVisible(panelId) {
            const panel = document.getElementById(panelId);
            return !!(panel && !panel.classList.contains('hidden'));
        }

        function getLoginEnvironment(environmentEl) {
            return (environmentEl && environmentEl.value) ? environmentEl.value : 'rest';
        }

        async function checkStoredKamanAuth(subdomain, environment) {
            const response = await fetch(FORM_AUTH_ROUTES.authStatus, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({ subdomain: subdomain, environment: environment }),
            });
            const data = await response.json().catch(() => ({}));
            return {
                ok: response.ok,
                authenticated: !!(response.ok && data.authenticated),
                message: data.message || '',
            };
        }

        function ensureFormCredentialsForSubmit() {
            const saved = getSavedCredentials();
            if (saved && saved.username && saved.password) {
                syncCredentialFields(saved.username, saved.password, saved.environment || 'rest');
                return true;
            }
            if (window.__kamanRestaurantAuthenticated) {
                return true;
            }

            const usernameEl = document.getElementById('username');
            const passwordEl = document.getElementById('password');
            const username = (usernameEl && usernameEl.value.trim()) || '';
            const password = (passwordEl && passwordEl.value) || '';
            if (username && password) {
                const environmentEl = document.getElementById('environment');
                saveCredentials({
                    username: username,
                    password: password,
                    environment: (environmentEl && environmentEl.value) || 'rest',
                });
                return true;
            }

            showLoginCredentialsPanel('unitLoginCredentials');
            return false;
        }

        function persistFormCredentials() {
            try {
                const subdomainMain = document.getElementById('subdomain');
                const subdomain = (subdomainMain && subdomainMain.value.trim()) || '';
                if (subdomain) {
                    sessionStorage.setItem(FORM_SESSION_KEYS.subdomain, subdomain);
                }
                const saved = getSavedCredentials();
                if (saved && saved.environment) {
                    sessionStorage.setItem(FORM_SESSION_KEYS.environment, saved.environment);
                }
            } catch (e) {
                /* sessionStorage may be unavailable */
            }
        }

        function restoreFormCredentials() {
            try {
                const savedSubdomain = sessionStorage.getItem(FORM_SESSION_KEYS.subdomain);
                const saved = getSavedCredentials();
                const subdomainMain = document.getElementById('subdomain');
                if (savedSubdomain && subdomainMain && !subdomainMain.value.trim()) {
                    subdomainMain.value = savedSubdomain;
                }
                if (saved && saved.username && saved.password) {
                    syncCredentialFields(saved.username, saved.password, saved.environment || 'rest');
                }
            } catch (e) {
                /* sessionStorage may be unavailable */
            }
        }

        function getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                || document.querySelector('input[name="_token"]')?.value
                || '';
        }

        async function performKamanLogin(options) {
            const {
                subdomainEl,
                environmentEl,
                usernameEl,
                passwordEl,
                credentialsPanelId,
                statusEl,
                loginBtn,
                successMessage,
            } = options;

            const subdomain = (subdomainEl && subdomainEl.value) ? subdomainEl.value.trim() : '';
            const environment = getLoginEnvironment(environmentEl);
            const credentialsVisible = isLoginCredentialsPanelVisible(credentialsPanelId);

            statusEl.textContent = '';
            statusEl.className = 'text-sm min-h-[1.5em]';

            if (!subdomain) {
                statusEl.textContent = 'Enter a subdomain first.';
                statusEl.classList.add('text-amber-700');
                return;
            }

            loginBtn.disabled = true;

            try {
                if (!credentialsVisible) {
                    statusEl.textContent = 'Checking session…';
                    statusEl.classList.add('text-[#7c6a56]');

                    const authCheck = await checkStoredKamanAuth(subdomain, environment);
                    if (authCheck.authenticated) {
                        setKamanRestaurantAuthenticated(true);
                        hideLoginCredentialsPanel();
                        const saved = getSavedCredentials();
                        if (saved && saved.username && saved.password) {
                            syncCredentialFields(saved.username, saved.password, saved.environment || 'rest');
                        }
                        persistFormCredentials();
                        statusEl.textContent = authCheck.message || 'You are already signed in for this restaurant.';
                        statusEl.className = 'text-sm min-h-[1.5em] text-emerald-700';
                        return;
                    }

                    showLoginCredentialsPanel(credentialsPanelId);
                    statusEl.textContent = 'No saved session. Enter your username and password, then click Login again.';
                    statusEl.className = 'text-sm min-h-[1.5em] text-amber-700';
                    return;
                }

                const username = (usernameEl && usernameEl.value) ? usernameEl.value.trim() : '';
                const password = (passwordEl && passwordEl.value) ? passwordEl.value : '';

                if (!username || !password) {
                    statusEl.textContent = 'Enter your username and password.';
                    statusEl.classList.add('text-amber-700');
                    return;
                }

                statusEl.textContent = 'Signing in…';
                statusEl.classList.add('text-[#7c6a56]');

                const response = await fetch(FORM_AUTH_ROUTES.login, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    body: JSON.stringify({
                        subdomain: subdomain,
                        environment: environment,
                        username: username,
                        password: password,
                    }),
                });
                const data = await response.json().catch(() => ({}));
                if (response.ok && data.success) {
                    setKamanRestaurantAuthenticated(true);
                    saveCredentials({
                        username: username,
                        password: password,
                        environment: environment,
                    });
                    syncCredentialFields(username, password, environment);
                    hideLoginCredentialsPanel();
                    persistFormCredentials();
                    statusEl.textContent = data.message || successMessage;
                    statusEl.className = 'text-sm min-h-[1.5em] text-emerald-700';
                } else {
                    statusEl.textContent = data.message || data.error || 'Something went wrong. Check your credentials.';
                    statusEl.className = 'text-sm min-h-[1.5em] text-red-600';
                }
            } catch (err) {
                statusEl.textContent = 'Network error. Try again.';
                statusEl.className = 'text-sm min-h-[1.5em] text-red-600';
            } finally {
                loginBtn.disabled = false;
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            restoreFormCredentials();
            const subdomainMain = document.getElementById('subdomain');
            if (subdomainMain) {
                subdomainMain.addEventListener('input', function () {
                    hideLoginCredentialsPanels();
                    const status = document.getElementById('unitLoginStatus');
                    if (status) {
                        status.textContent = '';
                    }
                    persistFormCredentials();
                });
            }
            ['subdomain', 'environment', 'username', 'password'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('input', function () {
                        if (id === 'username' || id === 'password' || id === 'environment') {
                            const usernameEl = document.getElementById('username');
                            const passwordEl = document.getElementById('password');
                            const environmentEl = document.getElementById('environment');
                            const username = (usernameEl && usernameEl.value.trim()) || '';
                            const password = (passwordEl && passwordEl.value) || '';
                            if (username && password) {
                                saveCredentials({
                                    username: username,
                                    password: password,
                                    environment: (environmentEl && environmentEl.value) || 'rest',
                                });
                            }
                        }
                        persistFormCredentials();
                    });
                    el.addEventListener('change', persistFormCredentials);
                }
            });

            const form = document.getElementById('restaurantForm');
            const methodTypeSelect = document.getElementById('method_type');
            const unitLoginBtn = document.getElementById('unitLoginBtn');
            const unitLoginStatus = document.getElementById('unitLoginStatus');
            const descriptionSection = document.getElementById('descriptionSection');
            const drinksSection = document.getElementById('drinksSection');
            const hotDrinksSection = document.getElementById('hotDrinksSection');
            const naturalJuicesSection = document.getElementById('naturalJuicesSection');
            const sweetsSection = document.getElementById('sweetsSection');
            const pastaMealsSection = document.getElementById('pastaMealsSection');
            const sandwichesSection = document.getElementById('sandwichesSection');
            const burgerSection = document.getElementById('burgerSection');
            const ingredientsSection = document.getElementById('ingredientsSection');
            const customImagesMealsSection = document.getElementById('customImagesMealsSection');
            const customImageNamedSection = document.getElementById('customImageNamedSection');
            const folderUploadInput = document.getElementById('folder_upload');
            const customImageNamedError = document.getElementById('customImageNamedError');
            const descriptionField = document.getElementById('description');
            const haatMenuSection = document.getElementById('haatMenuSection');
            const haatMenuJsonInput = document.getElementById('haat_menu_json');
            const mealImagesInput = document.getElementById('meal_images');
            const mealAiStyleSection = document.getElementById('mealAiStyleSection');
            const categoryLogoSection = document.getElementById('categoryLogoSection');
            const folderNameInput = document.getElementById('folder_name');
            const imagePreview = document.getElementById('imagePreview');
            const imageCount = document.getElementById('imageCount');
            const customImagesMealsError = document.getElementById('customImagesMealsError');
            const drinksPayloadInput = document.getElementById('drinksPayload');
            const drinksError = document.getElementById('drinksError');
            const hotDrinksError = document.getElementById('hotDrinksError');
            const naturalJuicesError = document.getElementById('naturalJuicesError');
            const sweetsError = document.getElementById('sweetsError');
            const pastaMealsError = document.getElementById('pastaMealsError');
            const sandwichesError = document.getElementById('sandwichesError');
            const burgerError = document.getElementById('burgerError');
            const ingredientsError = document.getElementById('ingredientsError');
            const layoutGrid = document.getElementById('layoutGrid');
            const categoryNameEnSection = document.getElementById('categoryNameEnSection');
            const drinkCards = Array.from(document.querySelectorAll('[data-drink-card]'));
            const drinkGroups = Array.from(document.querySelectorAll('[data-drink-group]'));

            if (!form || !methodTypeSelect) {
                return;
            }

            // Workflow debug panel
            const WF_STEPS = [
                { id: 'login', label: 'Login' },
                { id: 'parse', label: 'Parse JSON' },
                { id: 'snapshot', label: 'Read menu' },
                { id: 'plan', label: 'Plan changes' },
                { id: 'categories', label: 'Categories' },
                { id: 'meals', label: 'Meals', aliases: ['items', 'item'] },
                { id: 'ingredient_categories', label: 'Addon groups' },
                { id: 'ingredients', label: 'Ingredients' },
                { id: 'links', label: 'Apply links', aliases: ['discover_link'] },
                { id: 'done', label: 'Done' },
            ];
            const WF_AGENT_STEPS = [
                { id: 'think', label: 'Think' },
                { id: 'read', label: 'Read files' },
                { id: 'store', label: 'Store / clean' },
                { id: 'http', label: 'Kaman HTTP' },
                { id: 'done', label: 'Done' },
            ];
            const wfStepState = {};

            function normalizeWfStep(step) {
                const raw = String(step || '');
                const found = WF_STEPS.concat(WF_AGENT_STEPS).find((s) => s.id === raw || (s.aliases || []).includes(raw));
                return found ? found.id : raw;
            }

            function resetWfSteps(methodType) {
                Object.keys(wfStepState).forEach((k) => delete wfStepState[k]);
                const track = document.getElementById('workflowStepTrack');
                if (!track) return;
                const steps = methodType === 'Menu Agent'
                    ? WF_AGENT_STEPS
                    : (methodType === 'HAAT Menu Copy' ? WF_STEPS : null);
                if (!steps) {
                    track.hidden = true;
                    track.innerHTML = '';
                    return;
                }
                track.hidden = false;
                track.innerHTML = steps.map((s) => (
                    '<li class="wf-debug__step is-pending" data-step="' + s.id + '">' +
                        '<span class="wf-debug__step-dot"></span>' +
                        '<span class="wf-debug__step-label">' + escapeHtml(s.label) + '</span>' +
                    '</li>'
                )).join('');
            }

            function markWfStep(step, status) {
                const id = normalizeWfStep(step);
                if (!id) return;
                const prev = wfStepState[id];
                if (status === 'run' && (prev === 'ok' || prev === 'fail' || prev === 'reuse')) {
                    return;
                }
                if (prev === 'fail' && (status === 'ok' || status === 'reuse' || status === 'run')) {
                    return;
                }
                wfStepState[id] = status;
                const li = document.querySelector('#workflowStepTrack [data-step="' + id + '"]');
                if (!li) return;
                li.classList.remove('is-pending', 'is-run', 'is-ok', 'is-fail');
                li.classList.add('is-' + (status === 'reuse' ? 'ok' : status));
            }

            let liveLogPinned = true;
            let activeRun = null;
            let inspectingRunId = null;
            let inspectEventCount = 0;
            let inspectPollTimer = null;
            const FORM_RUN_I18N = {
                pause: @json(__('form.runs_pause')),
                continue: @json(__('form.runs_continue')),
                delete: @json(__('form.runs_delete')),
                deleteConfirm: @json(__('form.runs_delete_confirm')),
                by: @json(__('form.runs_by')),
                watch: @json(__('form.runs_watch')),
                inspect: @json(__('form.runs_inspect')),
                running: @json(__('form.runs_status_running')),
                paused: @json(__('form.runs_paused')),
                completed: @json(__('form.runs_completed')),
                failed: @json(__('form.runs_failed')),
            };

            function csrfToken() {
                return document.querySelector('input[name="_token"]')?.value || '';
            }

            function runStatusLabel(status) {
                if (status === 'running') return FORM_RUN_I18N.running;
                if (status === 'paused') return FORM_RUN_I18N.paused;
                if (status === 'completed') return FORM_RUN_I18N.completed;
                if (status === 'failed') return FORM_RUN_I18N.failed;
                return status || '';
            }

            function formatRunWhen(run) {
                const raw = run.finished_at || run.paused_at || run.started_at || run.created_at || '';
                if (!raw) return '';
                const date = new Date(raw);
                if (Number.isNaN(date.getTime())) return '';
                return date.toLocaleString();
            }

            function setActiveRun(run) {
                activeRun = run || null;
                const controls = document.getElementById('workflowRunControls');
                const pauseBtn = document.getElementById('workflowPauseBtn');
                const continueBtn = document.getElementById('workflowContinueBtn');
                if (!controls) {
                    return;
                }
                if (!activeRun) {
                    controls.hidden = true;
                    return;
                }
                controls.hidden = false;
                const pausing = !!activeRun.pause_requested && !!activeRun.is_running;
                if (pauseBtn) {
                    pauseBtn.hidden = !activeRun.is_running;
                    pauseBtn.disabled = pausing;
                    pauseBtn.textContent = pausing ? 'Pausing…' : FORM_RUN_I18N.pause;
                }
                if (continueBtn) {
                    continueBtn.hidden = !activeRun.can_continue;
                    continueBtn.disabled = !activeRun.can_continue;
                }
            }

            function renderFormRuns(runs) {
                const list = Array.isArray(runs) ? runs : [];
                window.__formWorkflowRuns = list;
                const emptyEl = document.getElementById('formRunsEmpty');
                const runningWrap = document.getElementById('formRunsRunning');
                const historyWrap = document.getElementById('formRunsHistory');
                const runningList = document.getElementById('formRunsRunningList');
                const historyList = document.getElementById('formRunsHistoryList');
                const running = list.filter((run) => run.status === 'running' || run.status === 'paused');
                const history = list.filter((run) => run.status !== 'running' && run.status !== 'paused');
                if (emptyEl) {
                    emptyEl.hidden = list.length > 0;
                }
                if (runningWrap && runningList) {
                    runningWrap.hidden = running.length === 0;
                    runningList.innerHTML = running.map(runItemHtml).join('');
                }
                if (historyWrap && historyList) {
                    historyWrap.hidden = history.length === 0;
                    historyList.innerHTML = history.map(runItemHtml).join('');
                }
            }

            function friendlyMessage(message) {
                const text = String(message || '');
                const lower = text.toLowerCase();
                if (lower.includes('rate limit') || lower.includes('tokens per min') || lower.includes('tpm') || lower.includes('retries exhausted')) {
                    return 'OpenAI is busy right now. Wait a moment and try again.';
                }
                if (lower.includes('openai error:')) {
                    return 'The menu agent hit a temporary OpenAI error. Try again.';
                }
                return text;
            }

            function runItemHtml(run) {
                const title = escapeHtml(run.title || ((run.method_type || 'Workflow') + (run.subdomain ? ' · ' + run.subdomain : '')));
                const when = escapeHtml(formatRunWhen(run));
                const owner = (!run.is_mine && run.user_name)
                    ? ' · ' + escapeHtml(FORM_RUN_I18N.by) + ' ' + escapeHtml(run.user_name)
                    : '';
                const selected = inspectingRunId && Number(inspectingRunId) === Number(run.id);
                let actions = '';
                if (run.is_running) {
                    actions += '<button type="button" class="form-runs__btn form-runs__btn--pause" data-run-pause="' + Number(run.id) + '">' + escapeHtml(FORM_RUN_I18N.pause) + '</button>';
                } else if (run.can_continue) {
                    actions += '<button type="button" class="form-runs__btn form-runs__btn--continue" data-run-continue="' + Number(run.id) + '">' + escapeHtml(FORM_RUN_I18N.continue) + '</button>';
                }
                if (run.can_delete) {
                    actions += '<button type="button" class="form-runs__btn form-runs__btn--delete" data-run-delete="' + Number(run.id) + '">' + escapeHtml(FORM_RUN_I18N.delete) + '</button>';
                }
                return '<div class="form-runs__item' + (selected ? ' is-active' : '') + '" data-run-id="' + Number(run.id) + '">' +
                    '<div class="form-runs__meta">' +
                        '<div class="form-runs__name">' + title + '</div>' +
                        '<div class="form-runs__sub"><span class="form-runs__badge is-' + escapeHtml(run.status) + '">' + escapeHtml(runStatusLabel(run.status)) + '</span>' + when + owner + '</div>' +
                    '</div>' +
                    (actions ? '<div class="form-runs__actions">' + actions + '</div>' : '') +
                '</div>';
            }

            async function refreshFormRuns() {
                try {
                    const res = await fetch(window.__formRunRoutes.list, {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    });
                    const data = await res.json().catch(() => ({}));
                    if (Array.isArray(data.runs)) {
                        renderFormRuns(data.runs);
                        if (activeRun) {
                            const latest = data.runs.find((row) => Number(row.id) === Number(activeRun.id));
                            if (latest) {
                                setActiveRun(latest);
                            }
                        }
                    }
                } catch (_) {}
            }

            function stopInspectPoll() {
                if (inspectPollTimer) {
                    clearInterval(inspectPollTimer);
                    inspectPollTimer = null;
                }
            }

            function applyMenuAgentConversation(conversation) {
                window.__pendingMenuAgentConversation = conversation || null;
                if (conversation && window.__formMenuAgent && typeof window.__formMenuAgent.restoreConversation === 'function') {
                    window.__formMenuAgent.restoreConversation(conversation);
                }
            }

            function openDebuggerPanel() {
                document.getElementById('workflowDebugPlaceholder')?.classList.add('hidden');
                document.getElementById('workflowDebugData')?.classList.remove('hidden');
                document.getElementById('workflowLiveLog')?.classList.remove('hidden');
                const content = document.getElementById('workflowDebugContent');
                const chevron = document.getElementById('workflowDebugChevron');
                if (content) {
                    content.classList.remove('hidden');
                }
                if (chevron) {
                    chevron.style.transform = 'rotate(180deg)';
                }
            }

            async function inspectRunById(id, options = {}) {
                const reset = options.reset !== false;
                const follow = options.follow !== false;
                const numericId = Number(id);
                if (!numericId) {
                    return;
                }
                inspectingRunId = numericId;
                if (reset) {
                    stopInspectPoll();
                    inspectEventCount = 0;
                    openDebuggerPanel();
                    const known = (window.__formWorkflowRuns || []).find((row) => Number(row.id) === numericId);
                    if (known) {
                        resetWfSteps(known.method_type);
                        setActiveRun(known);
                        renderFormRuns(window.__formWorkflowRuns || []);
                    }
                    const logEl = document.getElementById('workflowLiveLogEntries');
                    if (logEl) {
                        logEl.innerHTML = '';
                        liveLogPinned = true;
                        bindLiveLogScroll();
                    }
                }
                try {
                    const known = (window.__formWorkflowRuns || []).find((row) => Number(row.id) === numericId);
                    const url = known?.show_url || (String(window.__formRunRoutes.list || '').replace(/\/?$/, '/') + numericId);
                    const res = await fetch(url, {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data.run) {
                        appendLiveStep(data.error || 'Could not load this submission.', 'done', 'fail');
                        return;
                    }
                    upsertRun(data.run);
                    setActiveRun(data.run);
                    const events = Array.isArray(data.events) ? data.events : [];
                    if (reset) {
                        resetWfSteps(data.run.method_type);
                        inspectEventCount = 0;
                        const logEl = document.getElementById('workflowLiveLogEntries');
                        if (logEl) {
                            logEl.innerHTML = '';
                        }
                    }
                    for (let i = inspectEventCount; i < events.length; i++) {
                        const ev = events[i] || {};
                        appendLiveStep(ev.message || '', ev.step || '', ev.data?.status || ev.status || 'run', ev.timestamp || null);
                    }
                    inspectEventCount = events.length;
                    renderWorkflowDebug({
                        method_type: data.run.method_type,
                        payload: data.payload || {},
                        result: data.result || {},
                        timestamp: data.run.started_at || '',
                        run: data.run
                    }, false);
                    if (reset && data.run.has_conversation && data.conversation) {
                        applyMenuAgentConversation(data.conversation);
                    }
                    if (data.run.status === 'paused') {
                        showDebugBanner(data.run.error || 'Paused. Click Continue to resume.', true);
                        const banner = document.getElementById('workflowDebugBanner');
                        if (banner) {
                            banner.classList.remove('is-ok', 'is-fail');
                            banner.classList.add('is-run');
                        }
                    } else if (data.run.status === 'failed') {
                        showDebugBanner(data.run.error || data.result?.error || 'Workflow finished with errors.', false);
                    } else if (events.length === 0) {
                        if (data.run.is_running) {
                            showDebugBanner('Watching this submission. Activity appears as the workflow writes new steps.', true);
                        } else {
                            showDebugBanner(data.run.error || 'No activity log was stored for this submission.', data.run.status === 'completed');
                        }
                    } else if (data.run.is_running) {
                        showDebugBanner('Watching live…', true);
                        const banner = document.getElementById('workflowDebugBanner');
                        if (banner) {
                            banner.classList.remove('is-ok', 'is-fail');
                            banner.classList.add('is-run');
                        }
                    }
                    if (follow && data.run.is_running && !inspectPollTimer) {
                        inspectPollTimer = setInterval(() => {
                            inspectRunById(numericId, { reset: false, follow: false });
                        }, 2000);
                    }
                    if (!data.run.is_running) {
                        stopInspectPoll();
                    }
                } catch (err) {
                    appendLiveStep('Could not load submission: ' + (err.message || 'Request failed'), 'done', 'fail');
                }
            }

            function upsertRun(run) {
                if (!run || !run.id) {
                    return;
                }
                const list = Array.isArray(window.__formWorkflowRuns) ? window.__formWorkflowRuns.slice() : [];
                const idx = list.findIndex((row) => Number(row.id) === Number(run.id));
                if (idx >= 0) {
                    list[idx] = run;
                } else {
                    list.unshift(run);
                }
                renderFormRuns(list);
                if (activeRun && Number(activeRun.id) === Number(run.id)) {
                    setActiveRun(run);
                }
            }

            let sseSawDone = false;
            let sseWatchdog = null;
            let sseLastEventAt = Date.now();

            function touchSseWatchdog() {
                sseLastEventAt = Date.now();
            }

            function startSseWatchdog() {
                sseSawDone = false;
                touchSseWatchdog();
                if (sseWatchdog) {
                    clearInterval(sseWatchdog);
                }
                sseWatchdog = setInterval(() => {
                    if (sseSawDone) {
                        return;
                    }
                    const silentFor = Date.now() - sseLastEventAt;
                    if (silentFor >= 20000) {
                        appendLiveStep('Still waiting on Kaman. No log for ' + Math.round(silentFor / 1000) + 's — use Pause if this stays stuck.', '', 'run');
                        sseLastEventAt = Date.now();
                    }
                }, 5000);
            }

            function stopSseWatchdog() {
                if (sseWatchdog) {
                    clearInterval(sseWatchdog);
                    sseWatchdog = null;
                }
            }

            function handleWorkflowEvent(ev) {
                touchSseWatchdog();
                if (ev.event === 'run' && ev.run) {
                    inspectingRunId = Number(ev.run.id);
                    setActiveRun(ev.run);
                    upsertRun(ev.run);
                    return;
                }
                if (ev.event === 'step') {
                    appendLiveStep(ev.message, ev.step, ev.data?.status || 'run', ev.timestamp || null);
                    return;
                }
                if (ev.event !== 'done') {
                    return;
                }
                sseSawDone = true;
                stopSseWatchdog();
                if (ev.run) {
                    upsertRun(ev.run);
                    setActiveRun(ev.run);
                }
                if (ev.paused) {
                    appendLiveStep(ev.result?.message || 'Workflow paused. You can continue it from the list.', 'done', 'run');
                    showDebugBanner(ev.result?.message || 'Paused. Continue when you want.', true);
                    const banner = document.getElementById('workflowDebugBanner');
                    if (banner) {
                        banner.classList.remove('is-ok', 'is-fail');
                        banner.classList.add('is-run');
                    }
                } else {
                    appendLiveStep(ev.success ? 'Workflow finished.' : 'Workflow finished with errors.', 'done', ev.success ? 'ok' : 'fail');
                }
                renderWorkflowDebug({
                    method_type: ev.method_type,
                    payload: ev.payload || {},
                    result: ev.result || {},
                    timestamp: ev.timestamp || '',
                    run: ev.run || activeRun
                }, false);
                refreshFormRuns();
            }

            async function readSseStream(res) {
                startSseWatchdog();
                try {
                    const reader = res.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        buffer += decoder.decode(value, { stream: true });
                        const lines = buffer.split('\n\n');
                        buffer = lines.pop() || '';
                        for (const chunk of lines) {
                            const match = chunk.match(/^data:\s*(.+)$/m);
                            if (match) {
                                try {
                                    handleWorkflowEvent(JSON.parse(match[1]));
                                } catch (_) {}
                            }
                        }
                    }
                    if (buffer) {
                        const match = buffer.match(/^data:\s*(.+)$/m);
                        if (match) {
                            try {
                                handleWorkflowEvent(JSON.parse(match[1]));
                            } catch (_) {}
                        }
                    }
                    if (!sseSawDone) {
                        const id = inspectingRunId || activeRun?.id;
                        appendLiveStep('Live stream closed. Watching saved logs — the copy can keep going in the background.', '', 'run');
                        showDebugBanner('Watching this submission…', true);
                        const banner = document.getElementById('workflowDebugBanner');
                        if (banner) {
                            banner.classList.remove('is-ok', 'is-fail');
                            banner.classList.add('is-run');
                        }
                        if (id) {
                            inspectRunById(id, { reset: false, follow: true });
                        }
                        refreshFormRuns();
                    }
                } finally {
                    stopSseWatchdog();
                }
            }

            async function deleteRunById(id) {
                const run = (window.__formWorkflowRuns || []).find((row) => Number(row.id) === Number(id));
                if (!run || !run.delete_url || !run.can_delete) {
                    return;
                }
                if (!window.confirm(FORM_RUN_I18N.deleteConfirm)) {
                    return;
                }
                try {
                    const res = await fetch(run.delete_url, {
                        method: 'DELETE',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken(),
                        }
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        appendLiveStep('Could not delete: ' + (data.message || data.error || 'Request failed'), '', 'fail');
                        return;
                    }
                    if (Array.isArray(data.runs)) {
                        renderFormRuns(data.runs);
                    } else {
                        await refreshFormRuns();
                    }
                    if (Number(inspectingRunId) === Number(id)) {
                        stopInspectPoll();
                        inspectingRunId = null;
                        setActiveRun(null);
                        applyMenuAgentConversation(null);
                    }
                    if (activeRun && Number(activeRun.id) === Number(id)) {
                        setActiveRun(null);
                    }
                } catch (err) {
                    appendLiveStep('Could not delete: ' + (err.message || 'Request failed'), '', 'fail');
                }
            }

            async function pauseRunById(id) {
                const run = (window.__formWorkflowRuns || []).find((row) => Number(row.id) === Number(id)) || (activeRun && Number(activeRun.id) === Number(id) ? activeRun : null);
                if (!run || !run.pause_url) {
                    return;
                }
                appendLiveStep('Pause requested...', '', 'run');
                try {
                    const res = await fetch(run.pause_url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken(),
                        }
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        appendLiveStep('Could not pause: ' + (data.error || 'Request failed'), '', 'fail');
                        return;
                    }
                    appendLiveStep(data.message || 'Pause requested.', '', 'run');
                    if (data.run) {
                        upsertRun(data.run);
                        setActiveRun(data.run);
                    }
                    if (data.run && !data.run.can_continue) {
                        await new Promise((resolve) => setTimeout(resolve, 8000));
                        const forceRes = await fetch(run.pause_url + (run.pause_url.includes('?') ? '&' : '?') + 'force=1', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken(),
                            }
                        });
                        const forceData = await forceRes.json().catch(() => ({}));
                        if (forceData.run) {
                            appendLiveStep(forceData.message || 'Paused.', '', 'run');
                            upsertRun(forceData.run);
                            setActiveRun(forceData.run);
                        }
                    }
                    inspectRunById(id, { reset: false, follow: true });
                } catch (err) {
                    appendLiveStep('Could not pause: ' + (err.message || 'Request failed'), '', 'fail');
                }
            }

            function applyRunSubdomainToForm(run) {
                const subdomainEl = document.getElementById('subdomain');
                const environmentEl = document.getElementById('environment');
                if (subdomainEl && run.subdomain && !subdomainEl.value.trim()) {
                    subdomainEl.value = run.subdomain;
                } else if (subdomainEl && run.subdomain) {
                    subdomainEl.value = run.subdomain;
                }
                if (environmentEl && run.environment) {
                    environmentEl.value = run.environment === 'dev' ? 'dev' : 'rest';
                }
                persistFormCredentials();
            }

            async function loginForRun(run) {
                applyRunSubdomainToForm(run);
                const saved = getSavedCredentials() || {};
                const usernameEl = document.getElementById('username');
                const passwordEl = document.getElementById('password');
                const environmentEl = document.getElementById('environment');
                const username = (usernameEl && usernameEl.value.trim()) || saved.username || '';
                const password = (passwordEl && passwordEl.value) || saved.password || '';
                const environment = (environmentEl && environmentEl.value) || saved.environment || run.environment || 'rest';
                const subdomain = run.subdomain || (document.getElementById('subdomain') && document.getElementById('subdomain').value.trim()) || '';
                if (!subdomain) {
                    return { ok: false, message: 'Enter the restaurant subdomain, then Continue.' };
                }
                const authCheck = await checkStoredKamanAuth(subdomain, environment);
                if (authCheck.authenticated) {
                    setKamanRestaurantAuthenticated(true);
                    return { ok: true };
                }
                if (!username || !password) {
                    showLoginCredentialsPanel('unitLoginCredentials');
                    const status = document.getElementById('unitLoginStatus');
                    if (status) {
                        status.textContent = 'Sign in to this restaurant, then click Continue.';
                        status.className = 'text-sm min-h-[1.5em] text-amber-700';
                    }
                    return { ok: false, message: 'Sign in to the restaurant, then Continue.' };
                }
                if (usernameEl) usernameEl.value = username;
                if (passwordEl) passwordEl.value = password;
                const response = await fetch(FORM_AUTH_ROUTES.login, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({
                        subdomain: subdomain,
                        environment: environment,
                        username: username,
                        password: password,
                    }),
                });
                const data = await response.json().catch(() => ({}));
                if (response.ok && data.success) {
                    setKamanRestaurantAuthenticated(true);
                    saveCredentials({ username: username, password: password, environment: environment });
                    return { ok: true };
                }
                return { ok: false, message: data.error || data.message || 'Restaurant login failed.' };
            }

            function continueAuthBody(run) {
                const saved = getSavedCredentials() || {};
                const usernameEl = document.getElementById('username');
                const passwordEl = document.getElementById('password');
                return {
                    subdomain: run.subdomain || '',
                    environment: run.environment || 'rest',
                    username: (usernameEl && usernameEl.value.trim()) || saved.username || '',
                    password: (passwordEl && passwordEl.value) || saved.password || '',
                };
            }

            async function continueRunById(id, options = {}) {
                const retried = !!options.retried;
                const run = (window.__formWorkflowRuns || []).find((row) => Number(row.id) === Number(id)) || (activeRun && Number(activeRun.id) === Number(id) ? activeRun : null);
                if (!run || !run.continue_url) {
                    return;
                }
                stopInspectPoll();
                inspectingRunId = Number(run.id);
                prepareLiveDebug(run.method_type || '');
                appendLiveStep(retried ? 'Retrying with restaurant session…' : 'Continuing workflow...', '', 'run');
                try {
                    const res = await fetch(run.continue_url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'text/event-stream, application/json',
                            'Content-Type': 'application/json',
                            'X-Live-Debug': '1',
                            'X-CSRF-TOKEN': csrfToken(),
                        },
                        body: JSON.stringify(continueAuthBody(run)),
                    });
                    const ct = res.headers.get('Content-Type') || '';
                    if (ct.includes('text/event-stream')) {
                        await readSseStream(res);
                        return;
                    }
                    const data = await res.json().catch(() => ({}));
                    if (data.detached && data.run) {
                        upsertRun(data.run);
                        setActiveRun(data.run);
                        inspectRunById(data.run.id);
                        return;
                    }
                    if (data.needs_login && !retried) {
                        appendLiveStep('Restaurant session missing. Signing in…', '', 'run');
                        const login = await loginForRun(run);
                        if (login.ok) {
                            return continueRunById(id, { retried: true });
                        }
                        const paused = data.run || { ...run, is_running: false, can_continue: true, status: 'paused' };
                        upsertRun(paused);
                        setActiveRun(paused);
                        appendLiveStep(login.message || data.error || 'Sign in, then Continue.', 'done', 'fail');
                        showDebugBanner(login.message || data.error, false);
                        return;
                    }
                    const fallback = data.run || { ...run, is_running: false, can_continue: true, status: 'paused' };
                    upsertRun(fallback);
                    setActiveRun(fallback);
                    const msg = data.error || data.message || 'Could not continue.';
                    appendLiveStep(msg, 'done', 'fail');
                    showDebugBanner(msg, false);
                    refreshFormRuns();
                } catch (err) {
                    const paused = { ...run, is_running: false, can_continue: true, status: 'paused' };
                    upsertRun(paused);
                    setActiveRun(paused);
                    appendLiveStep('Error: ' + (err.message || 'Request failed'), 'done', 'fail');
                    showDebugBanner(err.message || 'An error occurred.', false);
                }
            }

            function prepareLiveDebug(methodType) {
                document.getElementById('workflowDebugPlaceholder')?.classList.add('hidden');
                document.getElementById('workflowDebugData')?.classList.remove('hidden');
                document.getElementById('workflowLiveLog')?.classList.remove('hidden');
                document.getElementById('workflowDebugContent')?.classList.remove('hidden');
                const logEl = document.getElementById('workflowLiveLogEntries');
                if (logEl) {
                    logEl.innerHTML = '';
                    liveLogPinned = true;
                    bindLiveLogScroll();
                    scrollLiveLogToLatest(true);
                }
                resetWfSteps(methodType);
                showDebugBanner('Running…', true);
                const banner = document.getElementById('workflowDebugBanner');
                if (banner) {
                    banner.classList.remove('is-ok', 'is-fail');
                    banner.classList.add('is-run');
                    banner.textContent = 'In progress. Watch the steps and activity log.';
                    banner.classList.remove('hidden');
                    banner.hidden = false;
                }
                renderWorkflowDebug({ method_type: methodType, payload: {}, result: {}, timestamp: '' }, false);
            }

            function bindLiveLogScroll() {
                const logEl = document.getElementById('workflowLiveLogEntries');
                if (!logEl || logEl.dataset.scrollBound === '1') {
                    return logEl;
                }
                logEl.dataset.scrollBound = '1';
                logEl.addEventListener('scroll', () => {
                    const gap = logEl.scrollHeight - logEl.scrollTop - logEl.clientHeight;
                    liveLogPinned = gap < 64;
                }, { passive: true });
                return logEl;
            }

            function scrollLiveLogToLatest(force = false) {
                const logEl = document.getElementById('workflowLiveLogEntries');
                if (!logEl || (!force && !liveLogPinned)) {
                    return;
                }
                requestAnimationFrame(() => {
                    logEl.scrollTop = logEl.scrollHeight;
                });
            }

            function appendLiveStep(message, step = '', status = '', at = null) {
                const logEl = bindLiveLogScroll();
                const liveLog = document.getElementById('workflowLiveLog');
                if (!logEl || !liveLog) return;
                liveLog.classList.remove('hidden');
                const stamp = at ? new Date(at) : new Date();
                const timeSource = Number.isNaN(stamp.getTime()) ? new Date() : stamp;
                const time = timeSource.toLocaleTimeString('en-GB', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
                const tone = status === 'fail' ? 'is-fail' : (status === 'ok' || status === 'reuse' ? 'is-ok' : (status === 'run' ? 'is-run' : ''));
                const line = document.createElement('div');
                line.className = 'wf-debug__log-line ' + tone;
                line.innerHTML = '<span class="wf-debug__log-time">[' + time + ']</span>' +
                    (step ? '<span class="wf-debug__log-step">' + escapeHtml(normalizeWfStep(step)) + '</span>' : '') +
                    '<span class="wf-debug__log-msg">' + escapeHtml(friendlyMessage(message)) + '</span>';
                logEl.appendChild(line);
                scrollLiveLogToLatest();
                if (step) {
                    markWfStep(step, status === 'fail' ? 'fail' : (status === 'ok' || status === 'reuse' ? 'ok' : 'run'));
                }
            }

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function showDebugBanner(message, success) {
                const el = document.getElementById('workflowDebugBanner');
                if (!el) return;
                el.hidden = false;
                el.classList.remove('hidden', 'is-ok', 'is-fail', 'is-run');
                el.classList.add(success ? 'is-ok' : 'is-fail');
                el.textContent = friendlyMessage(message || '');
            }

            function collectFailures(report) {
                const rows = [];
                const pushGroup = (stage, list) => {
                    (list || []).forEach((item) => {
                        rows.push({
                            stage,
                            name: item.name || item.title || item.haat_id || '—',
                            error: item.error || 'Unknown error',
                        });
                    });
                };
                pushGroup('categories', report.categories?.failed);
                pushGroup('meals', report.meals?.failed);
                pushGroup('addon groups', report.ingredient_categories?.failed);
                pushGroup('ingredients', report.ingredients?.failed);
                const linkGroups = new Map();
                (report.links || []).forEach((link) => {
                    if ((link.failed || 0) > 0 || link.error) {
                        const error = String(link.error || ('Failed ' + (link.failed || 0) + ' contents'));
                        const current = linkGroups.get(error) || { names: [], failed: 0 };
                        current.names.push(link.name || link.haat_id || '—');
                        current.failed += Number(link.failed || 1);
                        linkGroups.set(error, current);
                    }
                });
                linkGroups.forEach((group, error) => {
                    rows.push({
                        stage: 'links',
                        name: group.names.length > 1 ? (group.names.length + ' meals') : group.names[0],
                        error: error,
                    });
                });
                return rows;
            }

            function renderCounts(pipeline) {
                const grid = document.getElementById('workflowCountGrid');
                if (!grid) return;
                if (!pipeline || typeof pipeline !== 'object') {
                    grid.hidden = true;
                    grid.innerHTML = '';
                    return;
                }
                const labels = {
                    categories: 'Categories',
                    meals: 'Meals',
                    ingredient_categories: 'Addon groups',
                    ingredients: 'Ingredients',
                    links: 'Links',
                };
                const cards = Object.keys(labels).map((key) => {
                    const bucket = pipeline[key] || {};
                    const created = Number(bucket.created || 0);
                    const updated = Number(bucket.updated || 0);
                    const reused = Number(bucket.reused || 0);
                    const failed = Number(bucket.failed || 0);
                    const tone = failed > 0 ? 'is-fail' : ((created + updated + reused) > 0 ? 'is-ok' : '');
                    return '<div class="wf-debug__count ' + tone + '">' +
                        '<strong>' + labels[key] + '</strong>' +
                        '<span>' + created + ' created</span>' +
                        '<span>' + updated + ' updated</span>' +
                        '<span>' + reused + ' unchanged</span>' +
                        '<span>' + failed + ' failed</span>' +
                    '</div>';
                }).join('');
                grid.innerHTML = cards;
                grid.hidden = false;
            }

            function renderFailures(rows) {
                const wrap = document.getElementById('workflowFailures');
                const list = document.getElementById('workflowFailureRows');
                if (!wrap || !list) return;
                if (!rows.length) {
                    wrap.classList.add('hidden');
                    list.innerHTML = '';
                    return;
                }
                wrap.classList.remove('hidden');
                list.innerHTML = rows.slice(0, 80).map((row) => (
                    '<div class="wf-debug__fail">' +
                        '<span class="wf-debug__fail-stage">' + escapeHtml(row.stage) + '</span>' +
                        '<span class="wf-debug__fail-name">' + escapeHtml(String(row.name)) + '</span>' +
                        '<span class="wf-debug__fail-error">' + escapeHtml(String(row.error)) + '</span>' +
                    '</div>'
                )).join('');
            }

            function renderWorkflowDebug(data, clearLiveLog = true) {
                const placeholder = document.getElementById('workflowDebugPlaceholder');
                const dataEl = document.getElementById('workflowDebugData');
                const panel = document.getElementById('workflowDebugPanel');
                const badge = document.getElementById('workflowDebugBadge');
                const liveLogEntries = document.getElementById('workflowLiveLogEntries');

                if (!panel) return;

                placeholder?.classList.add('hidden');
                dataEl?.classList.remove('hidden');
                if (clearLiveLog && liveLogEntries) {
                    liveLogEntries.innerHTML = '';
                    liveLogPinned = true;
                }

                const methodEl = document.getElementById('debugMethodType');
                const summaryEl = document.getElementById('debugSummaryList');
                const timestampEl = document.getElementById('debugTimestamp');

                if (data) {
                    const result = data.result ?? {};
                    const report = result.data || result.body || {};
                    let status = data.run?.status || activeRun?.status || '';
                    if (result.paused === true) {
                        status = 'paused';
                    } else if (result.success === false) {
                        status = 'failed';
                    } else if (result.success === true) {
                        status = 'completed';
                    }
                    const isPaused = status === 'paused';
                    const hasOutcome = !isPaused && status !== 'running' && (result.success === true || result.success === false || status === 'completed' || status === 'failed');
                    const success = status === 'completed' || (hasOutcome && !!result.success && status !== 'failed');
                    const reason = friendlyMessage(data.run?.error || result.error || result.message || data.error || (isPaused ? 'Paused. Click Continue to resume.' : (hasOutcome ? 'No details provided.' : 'Running…')));
                    const createdCount = Array.isArray(result.created) ? result.created.length : (Array.isArray(result.body?.created) ? result.body.created.length : 0);
                    const failedCount = Array.isArray(result.failed) ? result.failed.length : (Array.isArray(result.body?.failed) ? result.body.failed.length : 0);
                    const failures = collectFailures(report);

                    if (methodEl) methodEl.textContent = data.method_type ?? '-';
                    if (timestampEl) timestampEl.textContent = data.timestamp ?? '-';
                    renderCounts(report.pipeline);
                    renderFailures(failures);

                    if (summaryEl) {
                        const messages = [];
                        if (isPaused) {
                            messages.push('Workflow paused. Click Continue to resume.');
                            messages.push(reason);
                        } else if (status === 'running' || !hasOutcome) {
                            messages.push('Workflow is running.');
                        } else {
                            messages.push(success ? 'Workflow completed successfully.' : 'Workflow failed.');
                            messages.push((success ? 'Result: ' : 'Why: ') + reason);
                        }
                        if (createdCount > 0 || failedCount > 0) {
                            messages.push('Created: ' + createdCount + ', Failed: ' + failedCount + '.');
                        }
                        if (failures[0]) {
                            messages.push('First API error: ' + failures[0].name + ' — ' + failures[0].error);
                        }
                        summaryEl.innerHTML = messages.map((msg) => '<li>' + escapeHtml(String(msg)) + '</li>').join('');
                    }

                    if (badge) {
                        badge.textContent = isPaused ? 'Paused' : (status === 'running' || !hasOutcome ? 'Running' : (success ? 'Success' : 'Error'));
                        badge.className = 'wf-debug__badge ' + (isPaused || status === 'running' || !hasOutcome ? 'is-run' : (success ? 'is-ok' : 'is-fail'));
                    }

                    if (hasOutcome && data.method_type === 'HAAT Menu Copy' && report.pipeline) {
                        markWfStep('snapshot', 'ok');
                        markWfStep('plan', 'ok');
                        ['categories', 'meals', 'ingredient_categories', 'ingredients', 'links'].forEach((key) => {
                            const failed = Number(report.pipeline[key]?.failed || 0);
                            const ok = Number(report.pipeline[key]?.created || 0)
                                + Number(report.pipeline[key]?.updated || 0)
                                + Number(report.pipeline[key]?.reused || 0);
                            if (failed > 0) markWfStep(key, 'fail');
                            else if (ok > 0) markWfStep(key, 'ok');
                        });
                        markWfStep('done', success ? 'ok' : 'fail');
                    }

                    if (hasOutcome) {
                        showDebugBanner(reason, success);
                    }
                }

                panel.classList.remove('hidden');
                panel.querySelector('#workflowDebugContent')?.classList.remove('hidden');
            }

            if (unitLoginBtn && unitLoginStatus) {
                unitLoginBtn.addEventListener('click', async () => {
                    await performKamanLogin({
                        subdomainEl: document.getElementById('subdomain'),
                        environmentEl: document.getElementById('environment'),
                        usernameEl: document.getElementById('username'),
                        passwordEl: document.getElementById('password'),
                        credentialsPanelId: 'unitLoginCredentials',
                        statusEl: unitLoginStatus,
                        loginBtn: unitLoginBtn,
                        successMessage: 'Token stored successfully. You can run the AI workflows.',
                    });
                });
            }

            async function submitFormWithLiveDebug(form, formData = null) {
                const fd = formData || new FormData(form);
                fd.append('_token', csrfToken());
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = '{{ __('form.submitting') }}';
                }

                stopInspectPoll();
                inspectingRunId = null;
                prepareLiveDebug(fd.get('method_type'));
                appendLiveStep('Starting workflow...', '', 'run');

                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/event-stream, application/json', 'X-Live-Debug': '1' }
                    });

                    const ct = res.headers.get('Content-Type') || '';
                    if (ct.includes('text/event-stream')) {
                        await readSseStream(res);
                    } else {
                        const data = await res.json().catch(() => ({}));
                        if (data.detached && data.run) {
                            upsertRun(data.run);
                            setActiveRun(data.run);
                            inspectRunById(data.run.id);
                        } else if (!res.ok) {
                            const msg = data.message || (data.errors ? Object.values(data.errors).flat().join(' ') : null) || data.error || 'An error occurred.';
                            appendLiveStep('Error: ' + msg, 'done', 'fail');
                            showDebugBanner(msg, false);
                        } else {
                            if (data.workflow_debug) renderWorkflowDebug(data.workflow_debug);
                            if (data.error) showDebugBanner(data.error, false);
                            refreshFormRuns();
                        }
                    }
                } catch (err) {
                    appendLiveStep('Error: ' + (err.message || 'Request failed'), 'done', 'fail');
                    showDebugBanner(err.message || 'An error occurred.', false);
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = '{{ __('form.submit_application') }}';
                    }
                    refreshFormRuns();
                }
            }

            const debugContent = document.getElementById('workflowDebugContent');

            document.getElementById('workflowPauseBtn')?.addEventListener('click', () => {
                if (activeRun?.id) {
                    pauseRunById(activeRun.id);
                }
            });
            document.getElementById('workflowContinueBtn')?.addEventListener('click', () => {
                if (activeRun?.id) {
                    continueRunById(activeRun.id);
                }
            });
            document.getElementById('formRunsPanel')?.addEventListener('click', (event) => {
                const pauseBtn = event.target.closest('[data-run-pause]');
                if (pauseBtn) {
                    pauseRunById(pauseBtn.getAttribute('data-run-pause'));
                    return;
                }
                const continueBtn = event.target.closest('[data-run-continue]');
                if (continueBtn) {
                    continueRunById(continueBtn.getAttribute('data-run-continue'));
                    return;
                }
                const deleteBtn = event.target.closest('[data-run-delete]');
                if (deleteBtn) {
                    event.preventDefault();
                    event.stopPropagation();
                    deleteRunById(deleteBtn.getAttribute('data-run-delete'));
                    return;
                }
                const inspectBtn = event.target.closest('[data-run-inspect]');
                if (inspectBtn) {
                    inspectRunById(inspectBtn.getAttribute('data-run-inspect'));
                    return;
                }
                const item = event.target.closest('[data-run-id]');
                if (item) {
                    inspectRunById(item.getAttribute('data-run-id'));
                }
            });
            renderFormRuns(window.__formWorkflowRuns || []);
            const watchOnLoad = (window.__formWorkflowRuns || []).find((run) => run.status === 'running' || run.status === 'paused');
            if (watchOnLoad) {
                inspectRunById(watchOnLoad.id);
            }
            setInterval(refreshFormRuns, 4000);

            if (window.__workflowDebugFromSession) {
                resetWfSteps(window.__workflowDebugFromSession.method_type);
                renderWorkflowDebug(window.__workflowDebugFromSession);
                debugContent?.classList.remove('hidden');
            }

            function setDrinksMode(mode) {
                const isCold = mode === 'cold';
                const isHot = mode === 'hot';
                const isNatural = mode === 'natural';
                const isSweets = mode === 'sweets';
                const isPasta = mode === 'pasta';
                const isSandwiches = mode === 'sandwiches';
                const isBurger = mode === 'burger';
                const isIngredients = mode === 'ingredients';
                const isCustomImagesMeals = mode === 'custom-images-meals';
                const isCustomImageNamed = mode === 'custom-image-named';

                if (isCold || isHot || isNatural || isSweets || isPasta || isSandwiches || isBurger || isIngredients || isCustomImagesMeals || isCustomImageNamed) {
                    // For Custom Images Meals Store and Custom Image Named, keep description visible (like Meal Store)
                    if (!isCustomImagesMeals && !isCustomImageNamed) {
                        descriptionSection.classList.add('hidden');
                    } else {
                        descriptionSection.classList.remove('hidden');
                        if (descriptionField) {
                            descriptionField.setAttribute('name', 'description');
                            descriptionField.removeAttribute('disabled');
                        }
                    }
                    if (drinksSection) drinksSection.classList.toggle('hidden', !isCold);
                    if (hotDrinksSection) hotDrinksSection.classList.toggle('hidden', !isHot);
                    if (naturalJuicesSection) naturalJuicesSection.classList.toggle('hidden', !isNatural);
                    if (sweetsSection) sweetsSection.classList.toggle('hidden', !isSweets);
                    if (pastaMealsSection) pastaMealsSection.classList.toggle('hidden', !isPasta);
                    if (sandwichesSection) sandwichesSection.classList.toggle('hidden', !isSandwiches);
                    if (burgerSection) burgerSection.classList.toggle('hidden', !isBurger);
                    if (ingredientsSection) ingredientsSection.classList.toggle('hidden', !isIngredients);
                    if (customImagesMealsSection) customImagesMealsSection.classList.toggle('hidden', !isCustomImagesMeals);
                    if (customImageNamedSection) customImageNamedSection.classList.toggle('hidden', !isCustomImageNamed);
                    
                    // Add/remove required attribute for custom images fields
                    if (isCustomImagesMeals) {
                        if (folderNameInput) folderNameInput.setAttribute('required', 'required');
                        if (mealImagesInput) mealImagesInput.setAttribute('required', 'required');
                        if (folderUploadInput) folderUploadInput.removeAttribute('required');
                    } else if (isCustomImageNamed) {
                        if (folderNameInput) folderNameInput.removeAttribute('required');
                        if (mealImagesInput) mealImagesInput.removeAttribute('required');
                        if (folderUploadInput) folderUploadInput.setAttribute('required', 'required');
                    } else {
                        if (folderNameInput) folderNameInput.removeAttribute('required');
                        if (mealImagesInput) mealImagesInput.removeAttribute('required');
                        if (folderUploadInput) folderUploadInput.removeAttribute('required');
                    }
                    
                } else {
                    descriptionSection.classList.remove('hidden');
                    // Ensure description field has name attribute and is not disabled
                    if (descriptionField) {
                        descriptionField.setAttribute('name', 'description');
                        descriptionField.removeAttribute('disabled');
                    }
                    if (drinksSection) drinksSection.classList.add('hidden');
                    if (hotDrinksSection) hotDrinksSection.classList.add('hidden');
                    if (naturalJuicesSection) naturalJuicesSection.classList.add('hidden');
                    if (sweetsSection) sweetsSection.classList.add('hidden');
                    if (pastaMealsSection) pastaMealsSection.classList.add('hidden');
                    if (sandwichesSection) sandwichesSection.classList.add('hidden');
                    if (burgerSection) burgerSection.classList.add('hidden');
                    if (ingredientsSection) ingredientsSection.classList.add('hidden');
                    if (customImagesMealsSection) customImagesMealsSection.classList.add('hidden');
                    
                    // Remove required attribute when section is hidden
                    if (folderNameInput) folderNameInput.removeAttribute('required');
                    if (mealImagesInput) mealImagesInput.removeAttribute('required');
                    drinksPayloadInput.value = '';
                    drinksError.classList.add('hidden');
                    hotDrinksError.classList.add('hidden');
                    if (naturalJuicesError) naturalJuicesError.classList.add('hidden');
                    if (sweetsError) sweetsError.classList.add('hidden');
                    if (pastaMealsError) pastaMealsError.classList.add('hidden');
                    if (sandwichesError) sandwichesError.classList.add('hidden');
                    if (burgerError) burgerError.classList.add('hidden');
                    if (ingredientsError) ingredientsError.classList.add('hidden');
                }
            }

            function updateFormMode() {
                const mode = methodTypeSelect ? methodTypeSelect.value : '';
                const categoryStoreHint = document.getElementById('categoryStoreHint');
                const structuredBlocksHint = document.getElementById('structuredBlocksHint');
                const categoryListModes = ['Category Store', 'Category Ingredients Store'];
                const structuredModes = [
                    'Meal Store',
                    'Category and Meal Store',
                    'Ingredients Store',
                    'Category and Ingredients Store',
                ];
                const imageStoreModes = ['Drinks Store'];
                const drinksModeMap = {
                    'Drinks Store': 'cold',
                };
                const isHaatCopy = mode === 'HAAT Menu Copy';

                setDrinksMode(drinksModeMap[mode] || null);

                if (haatMenuSection) {
                    haatMenuSection.classList.toggle('hidden', !isHaatCopy);
                }
                if (haatMenuJsonInput) {
                    if (isHaatCopy) {
                        haatMenuJsonInput.setAttribute('name', 'haat_menu_json');
                        haatMenuJsonInput.removeAttribute('disabled');
                    } else {
                        haatMenuJsonInput.removeAttribute('required');
                    }
                }

                if (descriptionSection) {
                    descriptionSection.classList.toggle('hidden', isHaatCopy);
                }
                if (descriptionField) {
                    if (isHaatCopy) {
                        descriptionField.removeAttribute('required');
                        descriptionField.removeAttribute('name');
                        descriptionField.setAttribute('disabled', 'disabled');
                    } else {
                        descriptionField.setAttribute('name', 'description');
                        descriptionField.removeAttribute('disabled');
                        if (categoryListModes.includes(mode) || structuredModes.includes(mode) || mode === 'Category Store With AI Image' || mode === 'Meal Store With AI Images') {
                            descriptionField.setAttribute('required', 'required');
                        } else if (mode === 'Drinks Store') {
                            descriptionField.removeAttribute('required');
                        }
                    }
                }

                if (categoryStoreHint) {
                    categoryStoreHint.classList.toggle('hidden', !categoryListModes.includes(mode));
                }
                if (structuredBlocksHint) {
                    structuredBlocksHint.classList.toggle('hidden', !structuredModes.includes(mode));
                }

                if (categoryLogoSection) {
                    categoryLogoSection.classList.toggle('hidden', mode !== 'Category Store With AI Image');
                }
                if (mealAiStyleSection) {
                    mealAiStyleSection.classList.toggle('hidden', mode !== 'Meal Store With AI Images');
                }
                if (categoryNameEnSection) {
                    categoryNameEnSection.classList.toggle('hidden', !imageStoreModes.includes(mode));
                }

                resetWfSteps(mode);
            }

            function refreshGroupState(group) {
                if (!group) {
                    return;
                }

                const selectAllBtn = group.querySelector('[data-select-all]');
                const bulkPriceInput = group.querySelector('[data-bulk-price]');
                const cards = Array.from(group.querySelectorAll('[data-drink-card]'));
                const checkedCount = cards.filter((card) => card.querySelector('.drink-checkbox').checked).length;

                if (selectAllBtn) {
                    const allSelected = cards.length > 0 && checkedCount === cards.length;
                    const groupLabel = selectAllBtn.dataset.groupLabel || '';
                    const selectAllText = '{{ __('form.select_all') }}';
                    const clearAllText = '{{ __('form.clear_all') }}';
                    selectAllBtn.textContent = allSelected
                        ? clearAllText
                        : `${selectAllText}${groupLabel ? ' ' + groupLabel : ''}`;
                    selectAllBtn.classList.toggle('is-active', allSelected);
                    selectAllBtn.setAttribute('aria-pressed', allSelected ? 'true' : 'false');
                }

                if (bulkPriceInput) {
                    bulkPriceInput.classList.toggle('opacity-60', checkedCount === 0);
                }
            }

            function applyBulkPriceValue(group, value) {
                if (!group) {
                    return;
                }

                const trimmed = value.trim();

                if (trimmed === '') {
                    return;
                }

                const numericValue = Number(trimmed);

                if (Number.isNaN(numericValue) || numericValue < 0) {
                    return;
                }

                const formatted = numericValue.toFixed(2);
                const cards = Array.from(group.querySelectorAll('[data-drink-card]'));
                const selectedCards = cards.filter((card) => card.querySelector('.drink-checkbox').checked);

                if (selectedCards.length === 0) {
                    return;
                }

                selectedCards.forEach((card) => {
                    const priceInput = card.querySelector('[data-price-input]');
                    if (priceInput) {
                        priceInput.value = formatted;
                        priceInput.disabled = false;
                    }
                });
            }

            function updateCardState(card, checked) {
                const priceInput = card.querySelector('[data-price-input]');
                card.classList.remove('border-red-400');
                if (checked) {
                    card.classList.add('border-[#f47a2e]', 'bg-[#fff3e6]', 'shadow-lg');
                    priceInput.disabled = false;
                } else {
                    card.classList.remove('border-[#f47a2e]', 'bg-[#fff3e6]', 'shadow-lg');
                    priceInput.disabled = true;
                    priceInput.value = '';
                }

                const group = card.closest('[data-drink-group]');
                if (group) {
                    refreshGroupState(group);
                }
            }

            drinkCards.forEach((card) => {
                const checkbox = card.querySelector('.drink-checkbox');
                const priceInput = card.querySelector('[data-price-input]');

                checkbox.addEventListener('change', () => {
                    updateCardState(card, checkbox.checked);
                    if (checkbox.checked) {
                        priceInput.focus();
                    }
                });
            });

            drinkGroups.forEach((group) => {
                const selectAllBtn = group.querySelector('[data-select-all]');
                const bulkPriceInput = group.querySelector('[data-bulk-price]');
                const cards = Array.from(group.querySelectorAll('[data-drink-card]'));

                if (selectAllBtn) {
                    selectAllBtn.addEventListener('click', () => {
                        const allSelected = cards.every((card) => card.querySelector('.drink-checkbox').checked);
                        const targetState = !allSelected;

                        cards.forEach((card) => {
                            const checkbox = card.querySelector('.drink-checkbox');
                            checkbox.checked = targetState;
                            updateCardState(card, targetState);
                        });

                        if (targetState && bulkPriceInput && bulkPriceInput.value.trim() !== '') {
                            applyBulkPriceValue(group, bulkPriceInput.value);
                        }

                        if (targetState && bulkPriceInput) {
                            bulkPriceInput.focus();
                        }
                    });
                }

                if (bulkPriceInput) {
                    const applyBulkHandler = () => applyBulkPriceValue(group, bulkPriceInput.value);
                    bulkPriceInput.addEventListener('change', applyBulkHandler);
                    bulkPriceInput.addEventListener('blur', applyBulkHandler);
                }

                refreshGroupState(group);
            });

            // Image preview functionality for Custom Images Meals Store
            if (mealImagesInput) {
                mealImagesInput.addEventListener('change', function(e) {
                    const files = Array.from(e.target.files);
                    const maxFiles = 50;
                    
                    // Validate file count
                    if (files.length > maxFiles) {
                        alert(`Please select no more than ${maxFiles} images.`);
                        e.target.value = '';
                        imagePreview.classList.add('hidden');
                        imageCount.classList.add('hidden');
                        return;
                    }
                    
                    // Show preview
                    if (files.length > 0) {
                        imagePreview.classList.remove('hidden');
                        imageCount.classList.remove('hidden');
                        imagePreview.innerHTML = '';
                        
                        files.forEach((file, index) => {
                            if (file.type.startsWith('image/')) {
                                const reader = new FileReader();
                                reader.onload = function(e) {
                                    const div = document.createElement('div');
                                    div.className = 'relative border border-[#f1dfc5] rounded-lg overflow-hidden bg-white/80';
                                    div.innerHTML = `
                                        <img src="${e.target.result}" alt="Preview ${index + 1}" class="w-full h-24 object-cover">
                                        <div class="absolute top-1 right-1 bg-black/50 text-white text-xs px-1 rounded">${index + 1}</div>
                                    `;
                                    imagePreview.appendChild(div);
                                };
                                reader.readAsDataURL(file);
                            }
                        });
                        
                        imageCount.textContent = `${files.length} image(s) selected${files.length >= maxFiles ? ' (maximum reached)' : ` (up to ${maxFiles} allowed)`}`;
                    } else {
                        imagePreview.classList.add('hidden');
                        imageCount.classList.add('hidden');
                    }
                });
            }

            // Folder upload preview for Custom Image Named
            const folderUploadPreview = document.getElementById('folderUploadPreview');
            const folderUploadFolderName = document.getElementById('folderUploadFolderName');
            const folderUploadFileCount = document.getElementById('folderUploadFileCount');
            if (folderUploadInput) {
                folderUploadInput.addEventListener('change', function(e) {
                    const files = Array.from(e.target.files || []);
                    const imageFiles = files.filter(f => f.type.startsWith('image/'));
                    if (imageFiles.length > 0) {
                        const firstPath = imageFiles[0].webkitRelativePath || imageFiles[0].name;
                        const folderName = firstPath.split('/')[0];
                        folderUploadPreview.classList.remove('hidden');
                        if (folderUploadFolderName) folderUploadFolderName.textContent = folderName;
                        if (folderUploadFileCount) folderUploadFileCount.textContent = imageFiles.length + ' ' + '{{ __('form.images_selected') }}';
                        if (customImageNamedError) {
                            customImageNamedError.classList.add('hidden');
                            folderUploadInput.classList.remove('border-red-400');
                        }
                    } else {
                        folderUploadPreview.classList.add('hidden');
                        if (folderUploadFolderName) folderUploadFolderName.textContent = '';
                        if (folderUploadFileCount) folderUploadFileCount.textContent = '';
                    }
                });
            }

            form.addEventListener('submit', (event) => {
                const mode = methodTypeSelect.value;
                const subdomainInput = document.getElementById('subdomain');
                
                // Validate always-required fields
                let hasBasicError = false;
                
                if (!subdomainInput || !subdomainInput.value.trim()) {
                    hasBasicError = true;
                    if (subdomainInput) {
                        subdomainInput.classList.add('border-red-400');
                    }
                } else if (subdomainInput) {
                    subdomainInput.classList.remove('border-red-400');
                }

                if (!ensureFormCredentialsForSubmit()) {
                    hasBasicError = true;
                }
                
                if (!mode) {
                    hasBasicError = true;
                    if (methodTypeSelect) {
                        methodTypeSelect.classList.add('border-red-400');
                    }
                } else if (methodTypeSelect) {
                    methodTypeSelect.classList.remove('border-red-400');
                }
                
                if (hasBasicError) {
                    event.preventDefault();
                    alert(hasSavedCredentials()
                        ? 'Please fill in all required fields: Method Type and Subdomain.'
                        : 'Please sign in first: enter subdomain, click Login, then provide your username and password.');
                    return;
                }

                const categoryListModes = ['Category Store', 'Category Ingredients Store'];
                const structuredModes = [
                    'Meal Store',
                    'Category and Meal Store',
                    'Ingredients Store',
                    'Category and Ingredients Store',
                ];
                const textDescriptionModes = [
                    ...categoryListModes,
                    ...structuredModes,
                    'Category Store With AI Image',
                    'Meal Store With AI Images',
                ];

                if (mode === 'HAAT Menu Copy') {
                    const haatIdInput = document.getElementById('haat_restaurant_id');
                    const hasFile = haatMenuJsonInput && haatMenuJsonInput.files && haatMenuJsonInput.files.length > 0;
                    const hasRestaurantId = haatIdInput && haatIdInput.value.trim();
                    if (!hasFile && !hasRestaurantId) {
                        event.preventDefault();
                        if (haatMenuJsonInput) {
                            haatMenuJsonInput.classList.add('border-red-400');
                        }
                        alert(@json(__('form.haat_menu_json_required')));
                        return;
                    }
                    if (haatMenuJsonInput) {
                        haatMenuJsonInput.classList.remove('border-red-400');
                    }
                }

                if (textDescriptionModes.includes(mode)) {
                    if (!descriptionField || !descriptionField.value.trim()) {
                        event.preventDefault();
                        if (descriptionField) {
                            descriptionField.classList.add('border-red-400');
                        }
                        alert(categoryListModes.includes(mode)
                            ? 'Please enter at least one category name in the description.'
                            : 'Please enter a description for this method type.');
                        return;
                    }
                    if (descriptionField) {
                        descriptionField.classList.remove('border-red-400');
                    }
                }

                event.preventDefault();

                if (mode === 'Drinks Store') {
                    const activeSection = drinksSection;
                    const errorElement = drinksError;

                    if (!activeSection) {
                        return;
                    }

                    const activeCards = Array.from(activeSection.querySelectorAll('[data-drink-card]'));
                    const selections = [];
                    let hasError = false;

                    activeCards.forEach((card) => {
                        const checkbox = card.querySelector('.drink-checkbox');
                        const priceInput = card.querySelector('[data-price-input]');
                        const name = checkbox.dataset.drinkName;
                        const label = checkbox.dataset.drinkLabel || name;

                        if (checkbox.checked) {
                            const priceValue = priceInput.value.trim();
                            if (priceValue === '' || isNaN(priceValue) || Number(priceValue) < 0) {
                                hasError = true;
                                card.classList.add('border-red-400');
                            } else {
                                card.classList.remove('border-red-400');
                                selections.push({
                                    key: name,
                                    name: name ? name.toLowerCase() : name,
                                    label,
                                    price: Number(priceValue).toFixed(2),
                                });
                            }
                        } else {
                            card.classList.remove('border-red-400');
                        }
                    });

                    if (selections.length === 0) {
                        hasError = true;
                    }

                    if (hasError) {
                        errorElement.classList.remove('hidden');
                        return;
                    }

                    errorElement.classList.add('hidden');
                    drinksPayloadInput.value = JSON.stringify(selections);
                    const descriptionLines = selections.map((item) => `${item.name} : ${item.price}`);
                    descriptionField.value = `{\n${descriptionLines.join('\n')}\n}`;
                    submitFormWithLiveDebug(form);
                    return;
                }

                if (descriptionField) {
                    descriptionField.setAttribute('name', 'description');
                    descriptionField.removeAttribute('disabled');
                }
                submitFormWithLiveDebug(form);
            });

            if (methodTypeSelect) {
                methodTypeSelect.addEventListener('change', updateFormMode);
            }
            // Initialize form mode on page load
            updateFormMode();

            window.__formLiveDebug = {
                handleWorkflowEvent,
                prepareLiveDebug,
                appendLiveStep,
                upsertRun,
                setActiveRun,
                openDebuggerPanel,
            };

            if (drinksPayloadInput && drinksPayloadInput.value) {
                try {
                    const previousSelection = JSON.parse(drinksPayloadInput.value);
                    previousSelection.forEach((item) => {
                        const matchingCard = drinkCards.find((card) => {
                            const checkbox = card.querySelector('.drink-checkbox');
                            const datasetName = checkbox.dataset.drinkName || '';
                            return datasetName === item.name
                                || datasetName === item.name?.toLowerCase()
                                || datasetName === item.label?.toLowerCase();
                        });

                        if (matchingCard) {
                            const checkbox = matchingCard.querySelector('.drink-checkbox');
                            const priceInput = matchingCard.querySelector('[data-price-input]');
                            checkbox.checked = true;
                            priceInput.value = item.price;
                            updateCardState(matchingCard, true);
                        }
                    });
                } catch (error) {
                    console.warn('Unable to restore previous drink selections', error);
                }
            }
        });
    </script>
    <script>
        (function () {
            const logEl = document.getElementById('menuAgentLog');
            const emptyEl = document.getElementById('menuAgentEmpty');
            const formEl = document.getElementById('menuAgentForm');
            const inputEl = document.getElementById('menuAgentInput');
            const sendBtn = document.getElementById('menuAgentSend');
            const filesEl = document.getElementById('menuAgentFiles');
            const pendingEl = document.getElementById('menuAgentPending');
            const loginHint = document.getElementById('menuAgentLoginHint');
            const panelEl = document.getElementById('menuAgentPanel');
            const newChatBtn = document.getElementById('menuAgentNew');
            if (!formEl || !inputEl || !logEl) {
                return;
            }

            const CONV_KEY = 'webtimize_form_menu_agent_conversation';
            const i18n = {
                thinking: @json(__('form.agent_thinking')),
                error: @json(__('form.agent_error')),
                empty: @json(__('form.agent_empty')),
            };

            const history = [];
            let pendingFiles = [];
            let sending = false;

            function conversationId() {
                let id = sessionStorage.getItem(CONV_KEY);
                if (!id) {
                    id = (crypto.randomUUID && crypto.randomUUID()) || String(Date.now());
                    sessionStorage.setItem(CONV_KEY, id);
                }
                return id;
            }

            function friendlyAgentText(text) {
                const value = String(text || '');
                const lower = value.toLowerCase();
                if (lower.includes('rate limit') || lower.includes('tokens per min') || lower.includes('tpm') || lower.includes('openai error')) {
                    return i18n.error;
                }
                return value;
            }

            function resetConversation() {
                sessionStorage.removeItem(CONV_KEY);
                history.length = 0;
                pendingFiles = [];
                renderPending();
                logEl.querySelectorAll('.menu-agent__bubble, .menu-agent__tool').forEach((el) => el.remove());
                if (emptyEl) {
                    emptyEl.hidden = false;
                    emptyEl.textContent = i18n.empty;
                }
            }

            function restoreConversation(conversation) {
                if (!conversation) {
                    return;
                }
                if (conversation.id) {
                    sessionStorage.setItem(CONV_KEY, conversation.id);
                }
                history.length = 0;
                pendingFiles = [];
                renderPending();
                logEl.querySelectorAll('.menu-agent__bubble, .menu-agent__tool').forEach((el) => el.remove());
                const messages = Array.isArray(conversation.messages) ? conversation.messages : [];
                if (!messages.length) {
                    if (emptyEl) {
                        emptyEl.hidden = false;
                        emptyEl.textContent = i18n.empty;
                    }
                    return;
                }
                if (emptyEl) {
                    emptyEl.hidden = true;
                }
                messages.forEach((row) => {
                    const role = row.role === 'assistant' ? 'assistant' : 'user';
                    const content = String(row.content || '');
                    history.push({ role: role, content: content });
                    appendBubble(role, content, row.files || []);
                });
                logEl.scrollTop = logEl.scrollHeight;
            }

            function csrfToken() {
                const meta = document.querySelector('meta[name="csrf-token"]');
                return meta ? meta.getAttribute('content') : '';
            }

            function setComposerEnabled(on) {
                inputEl.disabled = !on || sending;
                sendBtn.disabled = !on || sending;
                filesEl.disabled = !on || sending;
                loginHint.hidden = on;
            }

            function syncAuth() {
                setComposerEnabled(!!window.__kamanRestaurantAuthenticated);
            }

            function hideEmpty() {
                if (emptyEl) {
                    emptyEl.hidden = true;
                }
            }

            function appendBubble(role, text, files) {
                hideEmpty();
                const wrap = document.createElement('div');
                wrap.className = 'menu-agent__bubble menu-agent__bubble--' + role;
                wrap.textContent = text || '';
                if (files && files.length) {
                    const thumbs = document.createElement('div');
                    thumbs.className = 'menu-agent__thumbs';
                    files.forEach((file) => {
                        const name = file && file.name ? file.name : 'file';
                        const type = (file && (file.type || file.mime)) || '';
                        const canPreview = typeof File !== 'undefined' && file instanceof File && type.startsWith('image/');
                        if (!canPreview) {
                            const chip = document.createElement('span');
                            chip.className = 'menu-agent__pending-chip';
                            chip.textContent = name;
                            thumbs.appendChild(chip);
                            return;
                        }
                        const img = document.createElement('img');
                        img.alt = name;
                        img.src = URL.createObjectURL(file);
                        thumbs.appendChild(img);
                    });
                    wrap.appendChild(thumbs);
                }
                logEl.appendChild(wrap);
                logEl.scrollTop = logEl.scrollHeight;
                return wrap;
            }

            function appendTool(summary, ok, pulsing) {
                hideEmpty();
                const el = document.createElement('div');
                el.className = 'menu-agent__tool' + (ok === false ? ' is-fail' : '') + (pulsing ? ' is-pulse' : '');
                el.textContent = summary;
                logEl.appendChild(el);
                logEl.scrollTop = logEl.scrollHeight;
                return el;
            }

            function renderPending() {
                pendingEl.innerHTML = '';
                if (!pendingFiles.length) {
                    pendingEl.hidden = true;
                    return;
                }
                pendingEl.hidden = false;
                pendingFiles.forEach((file, index) => {
                    const chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'menu-agent__pending-chip';
                    chip.textContent = file.name + ' ×';
                    chip.addEventListener('click', () => {
                        pendingFiles.splice(index, 1);
                        renderPending();
                    });
                    pendingEl.appendChild(chip);
                });
            }

            function formatToolStart(ev) {
                if (ev.tool === 'kaman_request_many') {
                    const count = ev.arguments && ev.arguments.count ? ev.arguments.count : '?';
                    return 'HTTP × ' + count;
                }
                const method = (ev.arguments && ev.arguments.method) || 'HTTP';
                const path = (ev.arguments && ev.arguments.path) || '';
                return method + ' ' + path;
            }

            function pipeAgentDebug(ev) {
                const dbg = window.__formLiveDebug;
                if (!dbg) {
                    return;
                }
                if (ev.event === 'run' && ev.run) {
                    dbg.openDebuggerPanel();
                    dbg.prepareLiveDebug('Menu Agent');
                    dbg.handleWorkflowEvent(ev);
                    return;
                }
                if (ev.event === 'step' || ev.event === 'done') {
                    dbg.handleWorkflowEvent(ev);
                }
            }

            async function readSse(res, onEvent) {
                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    const parts = buffer.split('\n\n');
                    buffer = parts.pop() || '';
                    for (const chunk of parts) {
                        const match = chunk.match(/^data:\s*(.+)$/m);
                        if (!match) continue;
                        try {
                            onEvent(JSON.parse(match[1]));
                        } catch (_) {}
                    }
                }
                if (buffer) {
                    const match = buffer.match(/^data:\s*(.+)$/m);
                    if (match) {
                        try {
                            onEvent(JSON.parse(match[1]));
                        } catch (_) {}
                    }
                }
            }

            function addPendingFiles(list) {
                pendingFiles = pendingFiles.concat(Array.from(list || [])).slice(0, 20);
                renderPending();
            }

            filesEl.addEventListener('change', () => {
                addPendingFiles(filesEl.files || []);
                filesEl.value = '';
            });

            if (panelEl) {
                ['dragenter', 'dragover'].forEach((type) => {
                    panelEl.addEventListener(type, (event) => {
                        event.preventDefault();
                        panelEl.classList.add('is-drop');
                    });
                });
                ['dragleave', 'drop'].forEach((type) => {
                    panelEl.addEventListener(type, (event) => {
                        event.preventDefault();
                        if (type === 'dragleave' && event.target !== panelEl) {
                            return;
                        }
                        panelEl.classList.remove('is-drop');
                    });
                });
                panelEl.addEventListener('drop', (event) => {
                    const files = event.dataTransfer && event.dataTransfer.files;
                    if (files && files.length) {
                        addPendingFiles(files);
                    }
                });
            }

            if (newChatBtn) {
                newChatBtn.addEventListener('click', () => {
                    if (sending) {
                        return;
                    }
                    resetConversation();
                });
            }

            inputEl.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    formEl.requestSubmit();
                }
            });

            formEl.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (sending || !window.__kamanRestaurantAuthenticated) {
                    return;
                }
                const text = inputEl.value.trim();
                const files = pendingFiles.slice();
                if (!text && files.length === 0) {
                    return;
                }

                sending = true;
                setComposerEnabled(true);
                sendBtn.disabled = true;
                inputEl.disabled = true;

                appendBubble('user', text, files);
                history.push({ role: 'user', content: text });
                inputEl.value = '';
                pendingFiles = [];
                renderPending();

                const statusEl = appendTool(i18n.thinking, true, true);
                const body = new FormData();
                body.append('conversation_id', conversationId());
                history.forEach((row, index) => {
                    body.append('messages[' + index + '][role]', row.role);
                    body.append('messages[' + index + '][content]', row.content);
                });
                files.forEach((file) => body.append('attachments[]', file, file.name));

                let assistantText = '';
                try {
                    const res = await fetch(FORM_AUTH_ROUTES.menuAgent, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'text/event-stream, application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken(),
                        },
                        body,
                    });

                    const ct = (res.headers.get('content-type') || '').toLowerCase();
                    if (res.status === 401) {
                        const data = await res.json().catch(() => ({}));
                        statusEl.remove();
                        assistantText = friendlyAgentText(data.error || data.message || i18n.error);
                        appendBubble('assistant', assistantText);
                    } else if (ct.includes('text/event-stream') && res.body) {
                        let sawMessage = false;
                        await readSse(res, (ev) => {
                            pipeAgentDebug(ev);
                            if (ev.event === 'status' && ev.message) {
                                statusEl.textContent = ev.message;
                                statusEl.classList.add('is-pulse');
                                return;
                            }
                            if (ev.event === 'tool_start') {
                                statusEl.textContent = formatToolStart(ev);
                                statusEl.classList.add('is-pulse');
                                return;
                            }
                            if (ev.event === 'tool_result') {
                                statusEl.classList.remove('is-pulse');
                                appendTool(ev.summary || formatToolStart(ev), ev.ok !== false);
                                return;
                            }
                            if (ev.event === 'message' && ev.reply) {
                                sawMessage = true;
                                assistantText = ev.reply;
                                appendBubble('assistant', ev.reply);
                                return;
                            }
                            if (ev.event === 'error') {
                                assistantText = friendlyAgentText(ev.message || i18n.error);
                                return;
                            }
                            if (ev.event === 'done' && !sawMessage && ev.reply) {
                                assistantText = ev.reply;
                                appendBubble('assistant', ev.reply);
                            }
                        });
                        statusEl.remove();
                        if (!assistantText) {
                            assistantText = i18n.error;
                            appendBubble('assistant', assistantText);
                        }
                    } else {
                        const data = await res.json().catch(() => ({}));
                        statusEl.remove();
                        assistantText = friendlyAgentText(data.error || data.message || i18n.error);
                        appendBubble('assistant', assistantText);
                    }
                } catch (err) {
                    statusEl.remove();
                    assistantText = i18n.error;
                    appendBubble('assistant', assistantText);
                }

                if (assistantText) {
                    history.push({ role: 'assistant', content: assistantText });
                }
                sending = false;
                syncAuth();
                inputEl.focus();
            });

            window.addEventListener('kaman-auth-changed', syncAuth);
            syncAuth();
            window.__formMenuAgent = {
                restoreConversation: restoreConversation,
                resetConversation: resetConversation,
            };
            if (window.__pendingMenuAgentConversation) {
                restoreConversation(window.__pendingMenuAgentConversation);
            }
        })();
    </script>
</body>
</html>

