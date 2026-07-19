<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('form.title') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700;800&family=Heebo:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Shared Kaman design system (body, .kaman-card, .kaman-input, .kaman-button, .stamp-band, .page-container) -->
    <link rel="stylesheet" href="{{ asset('css/kaman.css') }}">

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
    </style>
</head>
<body class="antialiased">

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
                    <div class="space-y-1 text-center">
                        <h3 class="text-xl font-semibold text-[#2b1e11]">{{ __('form.restaurant_details') }}</h3>
                        <p class="text-sm text-[#a78a6c]">{{ __('form.restaurant_details_desc') }}</p>
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

                        {{-- Subdomain + method type: both short, so they share a row on desktop. --}}
                        <div class="kaman-form-grid">
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

                            <div class="kaman-field">
                                <label for="method_type" class="kaman-label">
                                    {{ __('form.method_type') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                                </label>
                                <select
                                    id="method_type"
                                    name="method_type"
                                    required
                                    class="kaman-input w-full @error('method_type') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                >
                                    <option value="" disabled @selected(!old('method_type'))>{{ __('form.select_method_type') }}</option>
                                    @foreach($methodTypes as $methodType)
                                        <option value="{{ $methodType }}" @selected(old('method_type', 'Category Store') === $methodType)>{{ $methodType }}</option>
                                    @endforeach
                                </select>
                                @error('method_type')
                                    <p class="text-xs text-red-500">{{ $message }}</p>
                                @enderror
                            </div>
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

                        <!-- Description -->
                        <div id="descriptionSection" class="kaman-field">
                            <label for="description" class="kaman-label">
                                {{ __('form.description') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                            </label>
                            <textarea
                                id="description"
                                name="description"
                                rows="9"
                                required
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

                        <!-- Name translation (all methods) -->
                        <div id="translateNamesSection" class="rounded-xl border border-[#f1dfc5] bg-white/60 px-4 py-4 space-y-2">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="hidden" name="translate_names" value="0">
                                <input
                                    type="checkbox"
                                    id="translate_names"
                                    name="translate_names"
                                    value="1"
                                    class="mt-1 h-4 w-4 rounded border-[#e4c9a8] text-[#f16229] focus:ring-[#f16229]"
                                    {{ old('translate_names', '1') == '1' ? 'checked' : '' }}
                                >
                                <span class="text-sm text-[#2b1e11]">
                                    <span class="font-medium block">{{ __('form.translate_names_label') }}</span>
                                    <span class="text-xs text-[#a78a6c] font-normal block mt-1">{{ __('form.translate_names_help') }}</span>
                                </span>
                            </label>
                        </div>

                        <!-- Actions -->
                        <div class="space-y-4">
                            <button
                                type="submit"
                                class="kaman-button w-full font-semibold uppercase tracking-wide text-white transition duration-200"
                            >
                                {{ __('form.submit_application') }}
                            </button>
                            <div class="text-center">
                                <a href="{{ url('/') }}" class="text-sm font-semibold text-[#f47a2e] hover:text-[#f16229] transition">
                                    {{ __('form.back_to_home') }}
                                </a>
                            </div>
                        </div>
                    </form>

                    <!-- Workflow Debug Panel -->
                    <div id="workflowDebugPanel" class="mt-8 rounded-2xl border-2 border-slate-300 bg-slate-50 dark:bg-slate-900 dark:border-slate-600 overflow-hidden">
                        <button type="button" id="workflowDebugToggle" class="w-full px-4 py-3 flex items-center justify-between text-left font-semibold text-slate-700 dark:text-slate-200 bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 transition">
                            <span>🔍 Workflow Debugger</span>
                            <span id="workflowDebugBadge" class="text-xs px-2 py-0.5 rounded-full bg-slate-300 text-slate-600"></span>
                            <svg id="workflowDebugChevron" class="w-5 h-5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div id="workflowDebugContent" class="p-4 text-sm font-mono {{ session('workflow_debug') ? '' : 'hidden' }}">
                            <div id="workflowDebugPlaceholder" class="text-slate-500 italic">
                                Submit the form to see workflow debug output.
                            </div>
                            <div id="workflowDebugData" class="hidden">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-[55vh] overflow-hidden">
                                    <div id="workflowLiveLog" class="hidden flex flex-col min-w-0">
                                        <h4 class="font-bold text-slate-800 dark:text-slate-100 mb-2 shrink-0">Live Log</h4>
                                        <div id="workflowLiveLogEntries" class="p-3 bg-slate-900 text-emerald-300 rounded text-xs font-mono overflow-y-auto flex-1 min-h-[120px] space-y-1"></div>
                                    </div>
                                    <div class="flex flex-col min-w-0">
                                        <div class="flex items-center justify-between gap-2 mb-2 shrink-0">
                                            <h4 class="font-bold text-slate-800 dark:text-slate-100">Method Type</h4>
                                            <span id="debugTimestamp" class="text-slate-500 text-xs"></span>
                                        </div>
                                        <div id="debugMethodType" class="p-2 bg-white dark:bg-slate-800 rounded text-xs shrink-0 text-slate-700 dark:text-slate-200"></div>
                                        <h4 class="font-bold text-slate-800 dark:text-slate-100 mb-2 mt-3 shrink-0">Execution Summary</h4>
                                        <ul id="debugSummaryList" class="p-3 bg-white dark:bg-slate-800 rounded text-xs overflow-auto flex-1 min-h-[120px] space-y-2 text-slate-700 dark:text-slate-200 list-disc pl-5">
                                            <li>No workflow run yet.</li>
                                        </ul>
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
        const FORM_AUTH_ROUTES = {
            authStatus: @json(route('form.full-ai.auth-status')),
            login: @json(route('form.full-ai.login')),
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
                        hideLoginCredentialsPanels();
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
                    saveCredentials({
                        username: username,
                        password: password,
                        environment: environment,
                    });
                    syncCredentialFields(username, password, environment);
                    hideLoginCredentialsPanels();
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
            function appendLiveStep(message, step = '') {
                const logEl = document.getElementById('workflowLiveLogEntries');
                const liveLog = document.getElementById('workflowLiveLog');
                if (!logEl || !liveLog) return;
                liveLog.classList.remove('hidden');
                const time = new Date().toLocaleTimeString('en-GB', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
                const line = document.createElement('div');
                line.className = 'flex gap-2';
                line.innerHTML = '<span class="text-slate-500 shrink-0">[' + time + ']</span><span>' + (step ? '<span class="text-amber-400">' + step + '</span> ' : '') + escapeHtml(message) + '</span>';
                logEl.appendChild(line);
                logEl.scrollTop = logEl.scrollHeight;
            }

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
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
                if (clearLiveLog && liveLogEntries) liveLogEntries.innerHTML = '';

                const methodEl = document.getElementById('debugMethodType');
                const summaryEl = document.getElementById('debugSummaryList');
                const timestampEl = document.getElementById('debugTimestamp');

                if (data) {
                    const result = data.result ?? {};
                    const success = !!result.success;
                    const reason = result.message || result.error || data.error || 'No details provided.';
                    const createdCount = Array.isArray(result.created) ? result.created.length : (Array.isArray(result.body?.created) ? result.body.created.length : 0);
                    const failedCount = Array.isArray(result.failed) ? result.failed.length : (Array.isArray(result.body?.failed) ? result.body.failed.length : 0);

                    if (methodEl) methodEl.textContent = data.method_type ?? '-';
                    if (timestampEl) timestampEl.textContent = data.timestamp ?? '-';

                    if (summaryEl) {
                        const messages = [];
                        messages.push(success ? 'Workflow completed successfully.' : 'Workflow failed.');
                        messages.push((success ? 'Success reason: ' : 'Failure reason: ') + reason);
                        if (createdCount > 0 || failedCount > 0) {
                            messages.push('Created: ' + createdCount + ', Failed: ' + failedCount + '.');
                        }
                        if (!success && failedCount > 0) {
                            const firstFailure = result.failed?.[0]?.error || result.body?.failed?.[0]?.error;
                            if (firstFailure) {
                                messages.push('First failure detail: ' + firstFailure);
                            }
                        }
                        summaryEl.innerHTML = messages.map((msg) => '<li>' + escapeHtml(String(msg)) + '</li>').join('');
                    }

                    if (badge) {
                        badge.textContent = success ? 'Success' : 'Error';
                        badge.className = 'text-xs px-2 py-0.5 rounded-full ' + (success ? 'bg-emerald-200 text-emerald-800' : 'bg-red-200 text-red-800');
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
                fd.append('_token', document.querySelector('input[name="_token"]').value);
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = '{{ __('form.submitting') }}';
                }

                document.getElementById('workflowDebugPlaceholder')?.classList.add('hidden');
                document.getElementById('workflowDebugData')?.classList.remove('hidden');
                document.getElementById('workflowLiveLog')?.classList.remove('hidden');
                const logEl = document.getElementById('workflowLiveLogEntries');
                if (logEl) logEl.innerHTML = '';
                appendLiveStep('Starting workflow...');
                renderWorkflowDebug({ method_type: fd.get('method_type'), payload: {}, result: {}, timestamp: '' }, false);

                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/event-stream, application/json', 'X-Live-Debug': '1' }
                    });

                    const ct = res.headers.get('Content-Type') || '';
                    if (ct.includes('text/event-stream')) {
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
                                        const ev = JSON.parse(match[1]);
                                        if (ev.event === 'step') {
                                            appendLiveStep(ev.message, ev.step);
                                        } else if (ev.event === 'done') {
                                            appendLiveStep('Workflow completed.', 'done');
                                            renderWorkflowDebug({
                                                method_type: ev.method_type,
                                                payload: ev.payload || {},
                                                result: ev.result || {},
                                                timestamp: ev.timestamp || ''
                                            }, false);
                                            if (!ev.success && (ev.message || ev.error || ev.result?.error || ev.result?.message)) {
                                                alert(ev.message || ev.error || ev.result?.message || ev.result?.error || 'Workflow failed.');
                                            }
                                        }
                                    } catch (_) {}
                                }
                            }
                        }
                        if (buffer) {
                            const match = buffer.match(/^data:\s*(.+)$/m);
                            if (match) {
                                try {
                                    const ev = JSON.parse(match[1]);
                                    if (ev.event === 'done') {
                                        renderWorkflowDebug({
                                            method_type: ev.method_type,
                                            payload: ev.payload || {},
                                            result: ev.result || {},
                                            timestamp: ev.timestamp || ''
                                        });
                                    }
                                } catch (_) {}
                            }
                        }
                    } else {
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            const msg = data.message || (data.errors ? Object.values(data.errors).flat().join(' ') : null) || data.error || 'An error occurred.';
                            appendLiveStep('Error: ' + msg, 'error');
                            alert(msg);
                        } else {
                            if (data.workflow_debug) renderWorkflowDebug(data.workflow_debug);
                            if (data.error) alert(data.error);
                        }
                    }
                } catch (err) {
                    appendLiveStep('Error: ' + (err.message || 'Request failed'), 'error');
                    alert(err.message || 'An error occurred.');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = '{{ __('form.submit_application') }}';
                    }
                }
            }

            const debugToggle = document.getElementById('workflowDebugToggle');
            const debugContent = document.getElementById('workflowDebugContent');
            const debugChevron = document.getElementById('workflowDebugChevron');

            if (debugToggle && debugContent) {
                debugToggle.addEventListener('click', () => {
                    const isHidden = debugContent.classList.contains('hidden');
                    debugContent.classList.toggle('hidden', !isHidden);
                    if (debugChevron) debugChevron.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
                });
            }

            if (window.__workflowDebugFromSession) {
                renderWorkflowDebug(window.__workflowDebugFromSession);
                debugContent?.classList.remove('hidden');
                if (debugChevron) debugChevron.style.transform = 'rotate(180deg)';
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

                setDrinksMode(drinksModeMap[mode] || null);

                if (descriptionSection) {
                    descriptionSection.classList.remove('hidden');
                }
                if (descriptionField) {
                    descriptionField.setAttribute('name', 'description');
                    descriptionField.removeAttribute('disabled');
                    if (categoryListModes.includes(mode) || structuredModes.includes(mode) || mode === 'Category Store With AI Image' || mode === 'Meal Store With AI Images') {
                        descriptionField.setAttribute('required', 'required');
                    } else if (mode === 'Drinks Store') {
                        descriptionField.removeAttribute('required');
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

            function mealNameFromImageFile(file) {
                const path = file.webkitRelativePath || file.name || '';
                const base = path.split('/').pop() || path;
                const dot = base.lastIndexOf('.');
                return (dot > 0 ? base.slice(0, dot) : base).trim();
            }

            function resolveImageCategoryForFile(file, explicitCategory) {
                if (explicitCategory && String(explicitCategory).trim()) {
                    return String(explicitCategory).trim();
                }
                const path = (file.webkitRelativePath || file.name || '').replace(/\\/g, '/');
                const parts = path.split('/').filter(Boolean);
                if (parts.length >= 3) {
                    return parts[parts.length - 2];
                }
                if (parts.length === 2) {
                    return parts[0];
                }
                return 'Meals';
            }

            function buildStructuredMealDescriptionFromFiles(files, explicitCategory) {
                const imageFiles = Array.from(files).filter(f => f.type && f.type.startsWith('image/'));
                if (!imageFiles.length) {
                    return '';
                }

                const sorted = imageFiles.slice().sort((a, b) => {
                    const pa = (a.webkitRelativePath || a.name || '').toLowerCase();
                    const pb = (b.webkitRelativePath || b.name || '').toLowerCase();
                    return pa.localeCompare(pb);
                });

                const groups = new Map();
                sorted.forEach((file) => {
                    const mealName = mealNameFromImageFile(file);
                    if (!mealName) {
                        return;
                    }
                    const category = resolveImageCategoryForFile(file, explicitCategory);
                    if (!groups.has(category)) {
                        groups.set(category, []);
                    }
                    groups.get(category).push(mealName);
                });

                const blocks = [];
                Array.from(groups.keys()).sort((a, b) => a.localeCompare(b)).forEach((category) => {
                    const lines = groups.get(category).map(name => name + ' : ');
                    blocks.push(category + ' : {\n' + lines.join('\n') + '\n}');
                });

                return blocks.join('\n\n');
            }

            function autofillMealDescriptionFromImages(files, explicitCategory) {
                if (!descriptionField) {
                    return;
                }
                const text = buildStructuredMealDescriptionFromFiles(files, explicitCategory);
                if (!text) {
                    return;
                }
                descriptionField.value = text;
                descriptionField.setAttribute('name', 'description');
                descriptionField.removeAttribute('disabled');
            }

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
                        const categoryLabel = folderNameInput ? folderNameInput.value : '';
                        autofillMealDescriptionFromImages(files, categoryLabel);
                    } else {
                        imagePreview.classList.add('hidden');
                        imageCount.classList.add('hidden');
                    }
                });
            }

            if (folderNameInput && mealImagesInput) {
                folderNameInput.addEventListener('input', function() {
                    if (methodTypeSelect.value !== 'Custom Images Meals Store') {
                        return;
                    }
                    const files = mealImagesInput.files;
                    if (files && files.length > 0) {
                        autofillMealDescriptionFromImages(Array.from(files), folderNameInput.value);
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
                        autofillMealDescriptionFromImages(imageFiles, '');
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
                persistFormCredentials();
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

            methodTypeSelect.addEventListener('change', updateFormMode);
            // Initialize form mode on page load
            updateFormMode();

            if (drinksPayloadInput.value) {
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
</body>
</html>

