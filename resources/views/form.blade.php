<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('form.title') }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(180deg, #fef8f0 0%, #fff 45%, #fef2dd 100%);
            color: #2b1e11;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .page-container {
            width: 100%;
            max-width: none;
            padding: clamp(1.5rem, 4vw, 3.5rem) clamp(1.5rem, 5vw, 4.5rem) clamp(3rem, 6vw, 5rem);
            margin: 0 auto;
        }
        .kaman-card {
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(48, 31, 13, 0.12);
            border: 1px solid rgba(238, 206, 165, 0.6);
            background: #ffffff;
            width: 100%;
        }
        .kaman-input {
            border-radius: 14px;
            border: 1px solid #f1dfc5;
        }
        .kaman-input:focus {
            border-color: #f59f43;
            box-shadow: 0 0 0 4px rgba(246, 146, 50, 0.15);
        }
        .kaman-button {
            background: linear-gradient(135deg, #f59f43 0%, #f47a2e 100%);
            border-radius: 999px;
            box-shadow: 0 15px 35px rgba(244, 123, 46, 0.35);
        }
        .kaman-button:hover {
            background: linear-gradient(135deg, #f47a2e 0%, #f16229 100%);
        }
        .stamp-band {
            background: linear-gradient(90deg, rgba(244, 201, 157, 0.55) 0%, rgba(255, 255, 255, 0.35) 53%, rgba(244, 201, 157, 0.55) 100%);
        }
        .layout-grid {
            display: grid;
            gap: clamp(2rem, 3vw, 4rem);
            align-items: stretch;
            grid-template-columns: minmax(0, 1fr);
        }
        .hero-panel {
            border-radius: 32px;
            padding: clamp(1.5rem, 4vw, 3rem);
            border: 1px solid rgba(244, 122, 46, 0.25);
            background: radial-gradient(circle at top right, rgba(244, 122, 46, 0.15), rgba(255, 255, 255, 0.9));
            box-shadow: 0 30px 90px rgba(27, 13, 4, 0.18);
            min-height: 540px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .hero-metrics {
            display: grid;
            gap: 1rem;
        }
        @media (min-width: 640px) {
            .hero-metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        .metric-card {
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(244, 122, 46, 0.2);
            padding: 1rem 1.2rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4);
        }
        .drink-group {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        .automation-toggle-card {
            cursor: pointer;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }
        .automation-toggle-card.is-active {
            border-color: #f47a2e;
            box-shadow: 0 25px 55px rgba(244, 122, 46, 0.22);
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

    <main class="relative min-h-screen">
        <div class="stamp-band absolute inset-x-0 -bottom-24 h-32"></div>
        <div class="page-container">
            <div id="layoutGrid" class="layout-grid">
                <div class="space-y-6" id="automationPanels">
                    <div id="automationToggle" class="grid gap-4 md:grid-cols-2">
                        <button
                            type="button"
                            data-automation-path="unit"
                            class="automation-toggle-card kaman-card relative flex flex-col items-start gap-2 border-2 border-transparent p-6 text-left transition hover:-translate-y-0.5 hover:shadow-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f47a2e]"
                        >
                            <span class="text-xs font-semibold uppercase tracking-[0.35em] text-[#f47a2e]">Unit AI Automation</span>
                            <span class="text-2xl font-semibold text-[#2b1e11]">Curate by sections</span>
                            <p class="text-sm text-[#7c6a56]">Use the existing workflow wizard to submit categories, meals, ingredients or image-based batches.</p>
                            <span class="mt-4 inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.35em] text-[#f47a2e]">
                                <span class="h-1.5 w-1.5 rounded-full bg-[#f47a2e]"></span>
                                Currently Active
                            </span>
                        </button>
                        <button
                            type="button"
                            data-automation-path="full"
                            class="automation-toggle-card kaman-card relative flex flex-col items-start gap-2 border-2 border-transparent p-6 text-left transition hover:-translate-y-0.5 hover:shadow-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#2b1e11]"
                        >
                            <span class="text-xs font-semibold uppercase tracking-[0.35em] text-[#2b1e11]">Full AI Automation</span>
                            <span class="text-2xl font-semibold text-[#2b1e11]">Hands-free agent</span>
                            <p class="text-sm text-[#7c6a56]">Upload menus, PDFs, or photos and let the agent orchestrate categories, meals, and ingredients with human approval.</p>
                            <span class="mt-4 inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.35em] text-[#2b1e11]">
                                <span class="h-1.5 w-1.5 rounded-full bg-[#2b1e11]"></span>
                                Preview
                            </span>
                        </button>
                    </div>

                    <div id="unitAutomationPanel" class="kaman-card p-10 space-y-8">
                    <div class="space-y-2 text-center">
                        <h3 class="text-2xl font-semibold text-[#2b1e11]">{{ __('form.restaurant_details') }}</h3>
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

                    <form id="restaurantForm" class="space-y-6" method="POST" action="{{ route('form.submit') }}" enctype="multipart/form-data">
                        <input type="hidden" name="drinks_payload" id="drinksPayload" value="{{ old('drinks_payload') }}">
                        @csrf

                        <!-- Restaurant Name -->
                        <div class="space-y-2">
                            <label for="restaurant_name" class="text-sm font-medium text-[#2b1e11]">
                                {{ __('form.restaurant_name') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                            </label>
                            <input
                                id="restaurant_name"
                                name="restaurant_name"
                                type="text"
                                required
                                value="{{ old('restaurant_name') }}"
                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('restaurant_name') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                placeholder="{{ __('form.restaurant_name_placeholder') }}"
                            >
                            @error('restaurant_name')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Restaurant Password -->
                        <div class="space-y-2">
                            <label for="password" class="text-sm font-medium text-[#2b1e11]">
                                {{ __('form.restaurant_password') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                            </label>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                required
                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('password') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                placeholder="{{ __('form.restaurant_password_placeholder') }}"
                            >
                            @error('password')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-wrap items-end gap-4">
                            <button
                                type="button"
                                id="unitLoginBtn"
                                class="kaman-button px-6 py-3 text-sm font-semibold text-white"
                            >
                                Login
                            </button>
                            <p id="unitLoginStatus" class="text-sm min-h-[1.5em]"></p>
                        </div>

                        <!-- Method Type -->
                        <div class="space-y-2">
                            <label for="method_type" class="text-sm font-medium text-[#2b1e11]">
                                {{ __('form.method_type') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                            </label>
                            <select
                                id="method_type"
                                name="method_type"
                                required
                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('method_type') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                            >
                                <option value="">{{ __('form.select_method_type') }}</option>
                                <optgroup label="{{ __('form.store_without_images') }}">
                                    <option value="Meal Store" {{ old('method_type') == 'Meal Store' ? 'selected' : '' }}>Meal Store</option>
                                    <option value="Meal Store With AI Images" {{ old('method_type') == 'Meal Store With AI Images' ? 'selected' : '' }}>Meal Store With AI Images</option>
                                    <option value="Category Store" {{ old('method_type') == 'Category Store' ? 'selected' : '' }}>Category Store</option>
                                    <option value="Category Store With AI Image" {{ old('method_type') == 'Category Store With AI Image' ? 'selected' : '' }}>Category Store With AI Image</option>
                                    <option value="Category Ingredients Store" {{ old('method_type') == 'Category Ingredients Store' ? 'selected' : '' }}>Category Ingredients Store</option>
                                    <option value="Ingredients Store" {{ old('method_type') == 'Ingredients Store' ? 'selected' : '' }}>Ingredients Store</option>
                                </optgroup>
                                <optgroup label="{{ __('form.store_with_images') }}">
                                    <option value="Drinks Store" {{ old('method_type') == 'Drinks Store' ? 'selected' : '' }}>Drinks Store</option>
                                    <option value="Natural Juices Store" {{ old('method_type') == 'Natural Juices Store' ? 'selected' : '' }}>Natural Juices Store</option>
                                    <option value="Ingredients Images Store" {{ old('method_type') == 'Ingredients Images Store' ? 'selected' : '' }}>Ingredients Images Store</option>
                                    <option value="Custom Images Meals Store" {{ old('method_type') == 'Custom Images Meals Store' ? 'selected' : '' }}>Custom Images Meals Store</option>
                                    <option value="Custom Image Named" {{ old('method_type') == 'Custom Image Named' ? 'selected' : '' }}>Custom Image Named</option>
                                </optgroup>
                            </select>
                            @error('method_type')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Description -->
                        <div id="descriptionSection" class="space-y-2">
                            <label for="description" class="text-sm font-medium text-[#2b1e11]">
                                {{ __('form.description') }}
                            </label>
                            <textarea
                                id="description"
                                name="description"
                                rows="4"
                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none resize-none @error('description') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
                                placeholder="{{ __('form.description_placeholder') }}"
                            >{{ old('description') }}</textarea>
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
                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('category_logo') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
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
                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('meal_style_image') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
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
                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('category_name_en') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
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
                                                        class="kaman-input px-4 py-2 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                        class="kaman-input px-4 py-2 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                        class="kaman-input px-4 py-2 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                        class="kaman-input px-4 py-2 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                        class="kaman-input px-4 py-2 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                        class="kaman-input px-4 py-2 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                        class="kaman-input px-4 py-2 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                        class="kaman-input px-4 py-2 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
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
                                    class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('folder_name') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
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
                                    class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('meal_images') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
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
                                    class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none @error('folder_upload') border-red-400 focus:border-red-400 focus:ring-red-300 @enderror"
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
                                class="kaman-button w-full px-6 py-3 text-sm font-semibold uppercase tracking-wide text-white transition duration-200"
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
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 max-h-[55vh] overflow-hidden">
                                    <div id="workflowLiveLog" class="hidden flex flex-col min-w-0">
                                        <h4 class="font-bold text-slate-800 dark:text-slate-100 mb-2 shrink-0">Live Log</h4>
                                        <div id="workflowLiveLogEntries" class="p-3 bg-slate-900 text-emerald-300 rounded text-xs font-mono overflow-y-auto flex-1 min-h-[120px] space-y-1"></div>
                                    </div>
                                    <div class="flex flex-col min-w-0">
                                        <div class="flex items-center justify-between gap-2 mb-2 shrink-0">
                                            <h4 class="font-bold text-slate-800 dark:text-slate-100">Method Type</h4>
                                            <span id="debugTimestamp" class="text-slate-500 text-xs"></span>
                                        </div>
                                        <pre id="debugMethodType" class="p-2 bg-white dark:bg-slate-800 rounded text-xs overflow-x-auto shrink-0"></pre>
                                        <h4 class="font-bold text-slate-800 dark:text-slate-100 mb-2 mt-3 shrink-0">Payload (sent to workflow)</h4>
                                        <pre id="debugPayload" class="p-2 bg-white dark:bg-slate-800 rounded text-xs overflow-auto flex-1 min-h-[100px]"></pre>
                                    </div>
                                    <div class="flex flex-col min-w-0">
                                        <h4 class="font-bold text-slate-800 dark:text-slate-100 mb-2 shrink-0">Result</h4>
                                        <pre id="debugResult" class="p-2 bg-white dark:bg-slate-800 rounded text-xs overflow-auto flex-1 min-h-[120px]"></pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="fullAutomationPanel" class="kaman-card p-10 space-y-8 hidden">
                    <div class="space-y-2 text-center">
                        <h3 class="text-2xl font-semibold text-[#2b1e11]">Full AI Automation</h3>
                        <p class="text-sm text-[#a78a6c]">Let the autonomous agent read your entire menu (PDF, images, text) and execute the Unit Automations sequentially with your approval before each API request.</p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl border border-[#f1dfc5] bg-white/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.32em] text-[#f47a2e] mb-2">Agent Mission</p>
                            <ul class="text-sm text-[#2b1e11] space-y-1 list-disc list-inside">
                                <li>Generate categories, meals, and ingredients automatically.</li>
                                <li>Preview every HTTP call before it hits Kaman.</li>
                                <li>Keep a live diagram of the structure being stored.</li>
                            </ul>
                        </div>
                        <div class="rounded-2xl border border-[#f1dfc5] bg-white/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.32em] text-[#2b1e11] mb-2">Human-in-the-loop</p>
                            <ul class="text-sm text-[#2b1e11] space-y-1 list-disc list-inside">
                                <li>Approve or skip each request with one click.</li>
                                <li>See summarized payloads before sending.</li>
                                <li>Trace the agent’s reasoning in real time.</li>
                            </ul>
                        </div>
                    </div>

                    <form id="fullAiForm" class="space-y-6" method="POST" action="{{ route('form.full-ai.start') }}" enctype="multipart/form-data">
                        @csrf

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <label for="full_ai_restaurant_name" class="text-sm font-medium text-[#2b1e11]">
                                    {{ __('form.restaurant_name') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                                </label>
                                <input
                                    id="full_ai_restaurant_name"
                                    name="restaurant_name"
                                    type="text"
                                    class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                    placeholder="{{ __('form.restaurant_name_placeholder') }}"
                                >
                            </div>
                            <div class="space-y-2">
                                <label for="full_ai_password" class="text-sm font-medium text-[#2b1e11]">
                                    {{ __('form.restaurant_password') }} <span class="text-[#f16229]">{{ __('form.required') }}</span>
                                </label>
                                <input
                                    id="full_ai_password"
                                    name="password"
                                    type="password"
                                    class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                                    placeholder="{{ __('form.restaurant_password_placeholder') }}"
                                >
                            </div>
                        </div>

                        <div class="flex flex-wrap items-end gap-4">
                            <button
                                type="button"
                                id="fullAiLoginBtn"
                                class="kaman-button px-6 py-3 text-sm font-semibold text-white"
                            >
                                Login
                            </button>
                            <p id="fullAiLoginStatus" class="text-sm min-h-[1.5em]"></p>
                        </div>

                        <div class="space-y-2">
                            <label for="full_ai_description" class="text-sm font-medium text-[#2b1e11]">
                                Menu Input (text)
                                <span class="text-xs font-normal text-[#a78a6c] block">Optional but helps accuracy. Paste any raw menu format.</span>
                            </label>
                            <textarea
                                id="full_ai_description"
                                name="description"
                                rows="5"
                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none resize-none"
                                placeholder="Breakfast : @{{ Pancake Stack : 42, Avocado Toast : 38 @}}"
                            ></textarea>
                        </div>

                        <div class="space-y-2">
                            <label for="full_ai_files" class="text-sm font-medium text-[#2b1e11]">
                                Upload menu files (PDF / Images)
                                <span class="text-xs font-normal text-[#a78a6c] block">Provide one or more files if you don't have text handy.</span>
                            </label>
                            <input
                                id="full_ai_files"
                                name="attachments[]"
                                type="file"
                                multiple
                                accept=".pdf,.jpg,.jpeg,.png,.gif,image/*"
                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                            >
                        </div>

                        <div class="space-y-2">
                            <label for="full_ai_logo" class="text-sm font-medium text-[#2b1e11]">
                                Restaurant logo (optional)
                                <span class="text-xs font-normal text-[#a78a6c] block">Used to generate category images with the same colors and style before storing categories.</span>
                            </label>
                            <input
                                id="full_ai_logo"
                                name="logo"
                                type="file"
                                accept="image/*"
                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none"
                            >
                        </div>

                        <div class="space-y-2">
                            <label for="full_ai_agent_instructions" class="text-sm font-medium text-[#2b1e11]">
                                Description (optional)
                                <span class="text-xs font-normal text-[#a78a6c] block">Human description or instructions to help the AI analyze your menu the way you want (e.g. language, format, what to focus on). Used as extra context when analyzing uploaded files.</span>
                            </label>
                            <textarea
                                id="full_ai_agent_instructions"
                                name="agent_instructions"
                                rows="3"
                                class="kaman-input w-full px-4 py-3 text-sm text-[#2b1e11] placeholder-[#c7b69d] focus:outline-none resize-none"
                                placeholder="e.g. Menu is in Arabic; group drinks separately; include prices in SAR."
                            ></textarea>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <label class="text-sm font-medium text-[#2b1e11]">
                                    Agent goals
                                </label>
                                <div class="rounded-2xl border border-dashed border-[#f1dfc5] bg-white/40 px-4 py-3 text-xs text-[#7c6a56] leading-relaxed">
                                    1. Extract categories<br>
                                    2. Generate meals with translations<br>
                                    3. Build category ingredients<br>
                                    4. Populate master ingredient list
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="text-sm font-medium text-[#2b1e11]">Launch agent</label>
                                <button
                                    type="submit"
                                    class="kaman-button w-full px-6 py-3 text-sm font-semibold uppercase tracking-wide text-white transition duration-200 disabled:opacity-60"
                                >
                                    ⚡ Start Full AI Automation
                                </button>
                                <p id="fullAiFormStatus" class="text-xs text-[#a78a6c]"></p>
                            </div>
                        </div>
                    </form>

                    <div id="fullAiDebugger" class="space-y-5 mt-5">
                        <div class="rounded-2xl border border-[#f1dfc5] bg-white shadow-sm">
                            <div class="flex items-center justify-between px-4 py-3 border-b border-[#f1dfc5] bg-[#fef8f0]/50">
                                <div>
                                    <p class="text-base font-semibold text-[#2b1e11]">What’s happening</p>
                                    <p class="text-sm text-[#7c6a56]">Step-by-step log so you always know what the agent did.</p>
                                </div>
                                <button type="button" id="fullAiClearLog" class="text-sm font-semibold text-[#f47a2e] hover:underline">Clear log</button>
                            </div>
                            <div id="fullAiLogEntries" class="max-h-56 overflow-y-auto p-4 text-sm text-[#2b1e11] space-y-2 bg-white">
                                <p data-full-ai-log-placeholder class="text-[#a78a6c] italic">Click “Start Full AI Automation” and events will appear here.</p>
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="rounded-2xl border border-[#f1dfc5] bg-white shadow-sm p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <div>
                                        <p class="text-base font-semibold text-[#2b1e11]">Your turn</p>
                                        <p class="text-sm text-[#7c6a56]">Approve or skip each action before it’s sent.</p>
                                    </div>
                                    <span id="fullAiSessionBadge" class="text-sm px-3 py-1 rounded-full bg-[#f1dfc5] text-[#7c6a56] font-medium">Nothing running</span>
                                </div>
                                <div id="fullAiApprovalPanel" class="rounded-xl border-2 border-dashed border-[#f1dfc5] bg-[#fef8f0]/50 p-4 text-sm text-[#7c6a56] min-h-[140px] flex items-center justify-center">
                                    <p class="text-center">Start the automation above. When the agent is ready to send data, you’ll see it here and can choose <strong>Send to Kaman</strong> or <strong>Skip</strong>.</p>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-[#f1dfc5] bg-white shadow-sm p-4">
                                <p class="text-base font-semibold text-[#2b1e11] mb-1">Your menu so far</p>
                                <p class="text-sm text-[#7c6a56] mb-3">Categories, meals, and ingredients we’re building from your input.</p>
                                <div id="fullAiDiagram" class="min-h-[180px] rounded-xl border-2 border-dashed border-[#f1dfc5] bg-[#fef8f0]/50 p-4 text-sm text-[#2b1e11]">
                                    <p class="text-[#a78a6c] text-center py-6">After you approve steps, the menu structure will show here.</p>
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
        const FULL_AI_ROUTES = {
            login: @json(route('form.full-ai.login')),
            chat: @json(route('form.full-ai.chat')),
            executeStep: @json(route('form.full-ai.execute-step')),
            start: @json(route('form.full-ai.start')),
            approveBase: @json(url('/form/full-ai')),
        };

        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('restaurantForm');
            const methodTypeSelect = document.getElementById('method_type');
            const automationToggleButtons = document.querySelectorAll('[data-automation-path]');
            const unitAutomationPanelEl = document.getElementById('unitAutomationPanel');
            const fullAutomationPanelEl = document.getElementById('fullAutomationPanel');
            const fullAiForm = document.getElementById('fullAiForm');
            const fullAiFormStatus = document.getElementById('fullAiFormStatus');
            const fullAiLogEntries = document.getElementById('fullAiLogEntries');
            const fullAiApprovalPanel = document.getElementById('fullAiApprovalPanel');
            const fullAiDiagramEl = document.getElementById('fullAiDiagram');
            const fullAiSessionBadge = document.getElementById('fullAiSessionBadge');
            const fullAiClearLog = document.getElementById('fullAiClearLog');
            const fullAiLoginBtn = document.getElementById('fullAiLoginBtn');
            const fullAiLoginStatus = document.getElementById('fullAiLoginStatus');
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

            let activeAutomationPath = 'unit';

            function setAutomationPath(path) {
                activeAutomationPath = path === 'full' ? 'full' : 'unit';
                automationToggleButtons.forEach((btn) => {
                    const isActive = (btn.dataset.automationPath || 'unit') === activeAutomationPath;
                    btn.classList.toggle('is-active', isActive);
                });
                if (unitAutomationPanelEl) {
                    unitAutomationPanelEl.classList.toggle('hidden', activeAutomationPath !== 'unit');
                }
                if (fullAutomationPanelEl) {
                    fullAutomationPanelEl.classList.toggle('hidden', activeAutomationPath !== 'full');
                }
            }

            automationToggleButtons.forEach((btn) => {
                btn.addEventListener('click', () => setAutomationPath(btn.dataset.automationPath || 'unit'));
            });
            setAutomationPath('unit');

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

                const safeJson = (obj) => {
                    try {
                        return JSON.stringify(obj, null, 2);
                    } catch {
                        return String(obj);
                    }
                };

                const methodEl = document.getElementById('debugMethodType');
                const payloadEl = document.getElementById('debugPayload');
                const resultEl = document.getElementById('debugResult');
                const timestampEl = document.getElementById('debugTimestamp');

                if (data) {
                    if (methodEl) methodEl.textContent = data.method_type ?? '-';
                    if (payloadEl) payloadEl.textContent = safeJson(data.payload ?? {});
                    if (resultEl) resultEl.textContent = safeJson(data.result ?? {});
                    if (timestampEl) timestampEl.textContent = data.timestamp ?? '-';

                    const success = data.result?.success ?? false;
                    if (badge) {
                        badge.textContent = success ? 'Success' : 'Error';
                        badge.className = 'text-xs px-2 py-0.5 rounded-full ' + (success ? 'bg-emerald-200 text-emerald-800' : 'bg-red-200 text-red-800');
                    }
                }

                panel.classList.remove('hidden');
                panel.querySelector('#workflowDebugContent')?.classList.remove('hidden');
            }

            const fullAiState = {
                sessionId: null,
                diagram: {
                    categories: [],
                    meals: [],
                    category_ingredients: [],
                    ingredients: [],
                },
            };

            const emptyDiagram = () => ({
                categories: [],
                meals: [],
                category_ingredients: [],
                ingredients: [],
            });

            function appendFullAiLog(message, step = '') {
                if (!fullAiLogEntries) {
                    return;
                }
                const placeholder = fullAiLogEntries.querySelector('[data-full-ai-log-placeholder]');
                if (placeholder) placeholder.remove();
                const time = new Date().toLocaleTimeString('en-GB', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
                const row = document.createElement('div');
                row.className = 'flex gap-2 text-[#2b1e11]';
                row.innerHTML = `<span class="text-[#a78a6c] shrink-0">[${time}]</span><span>${step ? `<span class="font-semibold text-[#f47a2e]">${escapeHtml(step)}</span> ` : ''}${escapeHtml(message)}</span>`;
                fullAiLogEntries.appendChild(row);
                fullAiLogEntries.scrollTop = fullAiLogEntries.scrollHeight;
            }

            function renderFullAiDiagram(diagram) {
                if (!fullAiDiagramEl) {
                    return;
                }
                const data = diagram || fullAiState.diagram || emptyDiagram();
                const mealsByCategory = {};
                (data.meals || []).forEach((meal) => {
                    const key = meal.category || 'Unassigned';
                    if (!mealsByCategory[key]) mealsByCategory[key] = [];
                    mealsByCategory[key].push(meal);
                });

                const categoryList = (data.categories || [])
                    .map((cat) => {
                        const img = (cat.image_url) ? `<img src="/${escapeHtml(cat.image_url)}" alt="" class="inline-block w-10 h-10 rounded object-cover mr-2 align-middle" onerror="this.style.display='none'">` : '';
                        return `<li class="pl-1 flex items-center gap-2 py-0.5">${img}<span>${escapeHtml(cat.label)}</span></li>`;
                    })
                    .join('') || '<li class="text-[#a78a6c]">No categories yet.</li>';

                const mealSections = Object.keys(mealsByCategory).map((cat) => {
                    const items = mealsByCategory[cat].map((meal) => `<li>${escapeHtml(meal.label)} <span class="text-[#a78a6c]">(${escapeHtml(meal.price)})</span></li>`).join('');
                    return `<div><p class="font-semibold text-[#2b1e11]">${escapeHtml(cat)}</p><ul class="list-disc list-inside pl-4 text-[#7c6a56]">${items}</ul></div>`;
                }).join('') || '<p class="text-[#a78a6c]">No meals yet.</p>';

                const ingredientsList = (data.ingredients || [])
                    .map((ingredient) => `<span class="rounded-full bg-[#fef8f0] px-2 py-1 text-xs text-[#2b1e11] border border-[#f1dfc5]">${escapeHtml(ingredient.name)}</span>`)
                    .join('');

                fullAiDiagramEl.innerHTML = `
                    <div class="space-y-4 text-sm">
                        <div>
                            <p class="font-semibold text-[#2b1e11] mb-1">Categories (${(data.categories || []).length})</p>
                            <ul class="list-disc list-inside text-[#7c6a56]">${categoryList}</ul>
                        </div>
                        <div>
                            <p class="font-semibold text-[#2b1e11] mb-1">Meals</p>
                            <div class="space-y-2">${mealSections}</div>
                        </div>
                        <div>
                            <p class="font-semibold text-[#2b1e11] mb-1">Ingredients (${(data.ingredients || []).length})</p>
                            <div class="flex flex-wrap gap-2">${ingredientsList || '<span class="text-[#a78a6c]">No ingredients yet.</span>'}</div>
                        </div>
                    </div>
                `;
            }

            function renderFullAiApproval(step) {
                if (!fullAiApprovalPanel) {
                    return;
                }
                if (!step) {
                    fullAiApprovalPanel.innerHTML = '<p class="text-[#7c6a56] text-sm text-center">Nothing to approve right now. Run the automation or continue approving steps.</p>';
                    fullAiApprovalPanel.classList.add('flex', 'items-center', 'justify-center');
                    if (fullAiSessionBadge) {
                        fullAiSessionBadge.textContent = 'Nothing running';
                        fullAiSessionBadge.className = 'text-sm px-3 py-1 rounded-full bg-[#f1dfc5] text-[#7c6a56] font-medium';
                    }
                    return;
                }
                fullAiApprovalPanel.classList.remove('flex', 'items-center', 'justify-center');

                const bodyPreview = JSON.stringify(step.http?.body ?? {}, null, 2);
                fullAiApprovalPanel.innerHTML = `
                    <div class="space-y-3">
                        <div>
                            <p class="text-sm font-semibold text-[#f47a2e]">${step.title || 'Next action'}</p>
                            <p class="text-sm text-[#2b1e11]">${step.description || ''}</p>
                        </div>
                        <div class="rounded-xl border border-[#f1dfc5] bg-white px-4 py-3 text-sm text-[#7c6a56] space-y-1">
                            <p><span class="font-semibold text-[#2b1e11]">Action:</span> ${step.http?.method ?? 'POST'} → ${(step.http?.url ?? 'N/A').replace(/^\//, '')}</p>
                        </div>
                        <p class="text-xs font-semibold text-[#2b1e11]">Data to send:</p>
                        <pre class="max-h-40 overflow-auto rounded-xl border border-[#f1dfc5] bg-[#2b1e11] text-xs text-emerald-200 p-3">${escapeHtml(bodyPreview)}</pre>
                        <div class="flex flex-wrap gap-3">
                            <button
                                type="button"
                                data-full-ai-approve="1"
                                class="kaman-button px-5 py-2.5 text-sm font-semibold text-white"
                            >
                                Send to Kaman
                            </button>
                            <button
                                type="button"
                                data-full-ai-skip="1"
                                class="rounded-xl border-2 border-[#f1dfc5] px-5 py-2.5 text-sm font-semibold text-[#7c6a56] hover:bg-[#fef8f0]"
                            >
                                Skip
                            </button>
                        </div>
                    </div>
                `;

                if (fullAiSessionBadge) {
                    fullAiSessionBadge.textContent = 'Your turn';
                    fullAiSessionBadge.className = 'text-sm px-3 py-1 rounded-full bg-amber-100 text-amber-800 font-medium';
                }

                fullAiApprovalPanel.querySelector('[data-full-ai-approve]')?.addEventListener('click', () => approveFullAiStep(step.id ?? null, true));
                fullAiApprovalPanel.querySelector('[data-full-ai-skip]')?.addEventListener('click', () => approveFullAiStep(step.id ?? null, false));
            }

            function getCsrfToken() {
                return document.querySelector('meta[name=\"csrf-token\"]')?.getAttribute('content') || document.querySelector('input[name=\"_token\"]')?.value || '';
            }

            async function executeFullAiHttpStep(stepHttp) {
                if (!stepHttp || !stepHttp.url) {
                    appendFullAiLog('No HTTP metadata found for this step.', 'http');
                    return;
                }
                const method = (stepHttp.method || 'POST').toUpperCase();
                const path = stepHttp.url;
                const body = stepHttp.body ?? {};
                const fullUrl = path.startsWith('http') ? path : '(Kaman) ' + path;

                try {
                    appendFullAiLog(`Sending ${method} ${fullUrl}`, 'http');
                    const response = await fetch(FULL_AI_ROUTES.executeStep, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                        body: JSON.stringify({ method, path, body }),
                    });
                    const data = await response.json();

                    if (!response.ok) {
                        appendFullAiLog(
                            (data.error || 'Request failed') + (data.status ? ' (HTTP ' + data.status + ')' : ''),
                            'http'
                        );
                        return;
                    }

                    const status = data.status ?? 0;
                    const ok = data.success === true;
                    appendFullAiLog(
                        `HTTP ${status} ${ok ? 'OK' : 'ERROR'} for ${method} ${fullUrl}`,
                        'http'
                    );
                    const payload = data.body;
                    const payloadStr = typeof payload === 'string' ? payload : JSON.stringify(payload);
                    appendFullAiLog('Response: ' + payloadStr, 'http');
                } catch (error) {
                    appendFullAiLog(`HTTP request failed: ${error.message}`, 'error');
                }
            }

            async function approveFullAiStep(stepId, approved = true) {
                if (!fullAiState.sessionId) {
                    appendFullAiLog('No active session to approve.', 'warning');
                    return;
                }
                try {
                    appendFullAiLog(approved ? 'Approving pending request...' : 'Skipping request...', stepId || 'step');
                    const response = await fetch(`${FULL_AI_ROUTES.approveBase}/${fullAiState.sessionId}/approve`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                        body: JSON.stringify({ step_id: stepId, approved }),
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Approval failed');
                    }
                    fullAiState.diagram = data.diagram || fullAiState.diagram;
                    renderFullAiDiagram(fullAiState.diagram);
                    renderFullAiApproval(data.next_step);
                    appendFullAiLog(approved ? 'Applied step successfully.' : 'Skipped step.', data.applied_step?.id ?? '');
                    if (approved && data.applied_step?.http) {
                        await executeFullAiHttpStep(data.applied_step.http);
                    }
                    if (data.finished) {
                        if (fullAiSessionBadge) {
                            fullAiSessionBadge.textContent = 'All done';
                            fullAiSessionBadge.className = 'text-sm px-3 py-1 rounded-full bg-emerald-100 text-emerald-800 font-medium';
                        }
                    }
                } catch (error) {
                    appendFullAiLog(`Approval failed: ${error.message}`, 'error');
                    alert(error.message || 'Approval failed.');
                }
            }

            if (fullAiClearLog && fullAiLogEntries) {
                fullAiClearLog.addEventListener('click', () => {
                    fullAiLogEntries.innerHTML = '<p data-full-ai-log-placeholder class="text-[#a78a6c] italic">Log cleared.</p>';
                });
            }

            if (unitLoginBtn && unitLoginStatus) {
                unitLoginBtn.addEventListener('click', async () => {
                    const nameEl = document.getElementById('restaurant_name');
                    const passEl = document.getElementById('password');
                    const name = (nameEl && nameEl.value) ? nameEl.value.trim() : '';
                    const password = (passEl && passEl.value) ? passEl.value : '';
                    unitLoginStatus.textContent = '';
                    unitLoginStatus.className = 'text-sm min-h-[1.5em]';
                    if (!name || !password) {
                        unitLoginStatus.textContent = 'Enter restaurant name and password first.';
                        unitLoginStatus.classList.add('text-amber-700');
                        return;
                    }
                    unitLoginBtn.disabled = true;
                    unitLoginStatus.textContent = 'Logging in…';
                    unitLoginStatus.classList.add('text-[#7c6a56]');
                    try {
                        const response = await fetch(FULL_AI_ROUTES.login, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': getCsrfToken(),
                            },
                            body: JSON.stringify({ restaurant_name: name, password: password }),
                        });
                        const data = await response.json();
                        if (response.ok && data.success) {
                            unitLoginStatus.textContent = data.message || 'Token stored successfully. You can run the AI workflows.';
                            unitLoginStatus.className = 'text-sm min-h-[1.5em] text-emerald-700';
                        } else {
                            unitLoginStatus.textContent = data.error || 'Something went wrong. Check your credentials.';
                            unitLoginStatus.className = 'text-sm min-h-[1.5em] text-red-600';
                        }
                    } catch (err) {
                        unitLoginStatus.textContent = 'Network error. Try again.';
                        unitLoginStatus.className = 'text-sm min-h-[1.5em] text-red-600';
                    } finally {
                        unitLoginBtn.disabled = false;
                    }
                });
            }

            if (fullAiLoginBtn && fullAiLoginStatus) {
                fullAiLoginBtn.addEventListener('click', async () => {
                    const nameEl = document.getElementById('full_ai_restaurant_name');
                    const passEl = document.getElementById('full_ai_password');
                    const name = (nameEl && nameEl.value) ? nameEl.value.trim() : '';
                    const password = (passEl && passEl.value) ? passEl.value : '';
                    fullAiLoginStatus.textContent = '';
                    fullAiLoginStatus.className = 'text-sm min-h-[1.5em]';
                    if (!name || !password) {
                        fullAiLoginStatus.textContent = 'Enter restaurant name and password first.';
                        fullAiLoginStatus.classList.add('text-amber-700');
                        return;
                    }
                    fullAiLoginBtn.disabled = true;
                    fullAiLoginStatus.textContent = 'Logging in…';
                    fullAiLoginStatus.classList.add('text-[#7c6a56]');
                    try {
                        const response = await fetch(FULL_AI_ROUTES.login, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': getCsrfToken(),
                            },
                            body: JSON.stringify({ restaurant_name: name, password: password }),
                        });
                        const data = await response.json();
                        if (response.ok && data.success) {
                            fullAiLoginStatus.textContent = data.message || 'Token stored successfully. You can start Full AI automation.';
                            fullAiLoginStatus.className = 'text-sm min-h-[1.5em] text-emerald-700';
                        } else {
                            fullAiLoginStatus.textContent = data.error || 'Something went wrong. Check your credentials.';
                            fullAiLoginStatus.className = 'text-sm min-h-[1.5em] text-red-600';
                        }
                    } catch (err) {
                        fullAiLoginStatus.textContent = 'Network error. Try again.';
                        fullAiLoginStatus.className = 'text-sm min-h-[1.5em] text-red-600';
                    } finally {
                        fullAiLoginBtn.disabled = false;
                    }
                });
            }

            if (fullAiForm) {
                fullAiForm.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    setAutomationPath('full');
                    fullAiState.sessionId = null;
                    fullAiState.diagram = emptyDiagram();
                    renderFullAiDiagram(fullAiState.diagram);
                    renderFullAiApproval(null);
                    appendFullAiLog('Launching Full AI automation...', 'agent');
                    const submitBtn = fullAiForm.querySelector('button[type=\"submit\"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Launching...';
                    }
                    if (fullAiFormStatus) {
                        fullAiFormStatus.textContent = 'Preparing agent payload...';
                    }
                    const fullAiFilesInput = document.getElementById('full_ai_files');
                    const fullAiDescription = document.getElementById('full_ai_description');
                    const hasText = fullAiDescription && fullAiDescription.value.trim().length > 0;
                    const hasFiles = fullAiFilesInput && fullAiFilesInput.files && fullAiFilesInput.files.length > 0;
                    if (!hasText && !hasFiles) {
                        appendFullAiLog('Provide either menu text or at least one file.', 'error');
                        if (fullAiFormStatus) fullAiFormStatus.textContent = 'Upload a PDF/image or paste text.';
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = '⚡ Start Full AI Automation';
                        }
                        return;
                    }
                    try {
                        const fd = new FormData(fullAiForm);
                        const response = await fetch(FULL_AI_ROUTES.start, {
                            method: 'POST',
                            body: fd,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        const data = await response.json();
                        if (!response.ok || !data.success) {
                            throw new Error(data.error || 'Unable to start the agent.');
                        }
                        fullAiState.sessionId = data.session_id;
                        fullAiState.diagram = data.diagram || emptyDiagram();
                        renderFullAiDiagram(fullAiState.diagram);
                        renderFullAiApproval(data.next_step);
                        appendFullAiLog(`Session ${data.session_id.substring(0, 8)} created.`, 'session');
                        if (fullAiSessionBadge) {
                            fullAiSessionBadge.textContent = data.next_step ? 'Your turn' : 'All done';
                            fullAiSessionBadge.className = 'text-sm px-3 py-1 rounded-full font-medium ' + (data.next_step ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800');
                        }
                        if (fullAiFormStatus) {
                            fullAiFormStatus.textContent = data.next_step ? 'Awaiting your approval…' : 'Agent finished instantly.';
                        }
                    } catch (error) {
                        appendFullAiLog(`Start failed: ${error.message}`, 'error');
                        if (fullAiFormStatus) fullAiFormStatus.textContent = error.message || 'Start failed.';
                        alert(error.message || 'Failed to start agent.');
                    } finally {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = '⚡ Start Full AI Automation';
                        }
                    }
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
                                            if (!ev.success && (ev.error || ev.result?.error)) {
                                                alert(ev.error || ev.result?.error || 'Workflow failed.');
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
                const value = methodTypeSelect.value;
                const imageBasedMethods = ['Drinks Store', 'Natural Juices Store', 'Ingredients Images Store'];
                const isImageBased = imageBasedMethods.includes(value);
                
                // Show/hide category_name_en field based on image-based methods (but not for Custom Images Meals Store or Custom Image Named)
                if (categoryNameEnSection) {
                    if (isImageBased && value !== 'Custom Images Meals Store' && value !== 'Custom Image Named') {
                        categoryNameEnSection.classList.remove('hidden');
                    } else {
                        categoryNameEnSection.classList.add('hidden');
                    }
                }
                
                if (mealAiStyleSection) {
                    if (value === 'Meal Store With AI Images') {
                        mealAiStyleSection.classList.remove('hidden');
                    } else {
                        mealAiStyleSection.classList.add('hidden');
                    }
                }

                if (categoryLogoSection) {
                    if (value === 'Category Store With AI Image') {
                        categoryLogoSection.classList.remove('hidden');
                    } else {
                        categoryLogoSection.classList.add('hidden');
                    }
                }
                
                if (value === 'Drinks Store') {
                    setDrinksMode('cold');
                } else if (value === 'Natural Juices Store') {
                    setDrinksMode('natural');
                } else if (value === 'Ingredients Images Store') {
                    setDrinksMode('ingredients');
                } else if (value === 'Custom Images Meals Store') {
                    setDrinksMode('custom-images-meals');
                } else if (value === 'Custom Image Named') {
                    setDrinksMode('custom-image-named');
                } else {
                    setDrinksMode(null);
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
                const restaurantNameInput = document.getElementById('restaurant_name');
                const passwordInput = document.getElementById('password');
                
                // Validate always-required fields
                let hasBasicError = false;
                
                if (!restaurantNameInput || !restaurantNameInput.value.trim()) {
                    hasBasicError = true;
                    if (restaurantNameInput) {
                        restaurantNameInput.classList.add('border-red-400');
                    }
                } else if (restaurantNameInput) {
                    restaurantNameInput.classList.remove('border-red-400');
                }
                
                if (!passwordInput || !passwordInput.value.trim()) {
                    hasBasicError = true;
                    if (passwordInput) {
                        passwordInput.classList.add('border-red-400');
                    }
                } else if (passwordInput) {
                    passwordInput.classList.remove('border-red-400');
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
                    alert('Please fill in all required fields: Method Type, Restaurant Name, and Password.');
                    return;
                }
                
                // Validate Image Renamer fields if Image Renamer is selected
                if (mode === 'Image Renamer') {
                    const customNameInput = document.getElementById('custom_name');
                    const imagesFileNamesInput = document.getElementById('images_file_names');
                    const folderInput = document.getElementById('folder');
                    
                    let hasError = false;
                    
                    if (!customNameInput || !customNameInput.value.trim()) {
                        hasError = true;
                        if (customNameInput) {
                            customNameInput.classList.add('border-red-400');
                        }
                    } else if (customNameInput) {
                        customNameInput.classList.remove('border-red-400');
                    }
                    
                    if (!imagesFileNamesInput || !imagesFileNamesInput.value.trim()) {
                        hasError = true;
                        if (imagesFileNamesInput) {
                            imagesFileNamesInput.classList.add('border-red-400');
                        }
                    } else if (imagesFileNamesInput) {
                        imagesFileNamesInput.classList.remove('border-red-400');
                    }
                    
                    if (!folderInput || !folderInput.files || folderInput.files.length === 0) {
                        hasError = true;
                        if (folderInput) {
                            folderInput.classList.add('border-red-400');
                        }
                    } else if (folderInput) {
                        folderInput.classList.remove('border-red-400');
                    }
                    
                    if (hasError) {
                        event.preventDefault();
                        alert('Please fill in all required Image Renamer fields: Custom Name, Images File Names, and Upload Images.');
                        return;
                    }
                    
                    // If validation passes, allow form to submit
                    return;
                }
                
                // Handle Custom Image Named - AJAX submit with folder files
                if (mode === 'Custom Image Named') {
                    const folderUpload = document.getElementById('folder_upload');
                    if (!folderUpload || !folderUpload.files || folderUpload.files.length === 0) {
                        event.preventDefault();
                        if (customImageNamedError) {
                            customImageNamedError.textContent = '{{ __('form.error_choose_folder') }}';
                            customImageNamedError.classList.remove('hidden');
                        }
                        if (folderUpload) folderUpload.classList.add('border-red-400');
                        return;
                    }
                    const files = Array.from(folderUpload.files).filter(f => f.type.startsWith('image/'));
                    if (files.length === 0) {
                        event.preventDefault();
                        if (customImageNamedError) {
                            customImageNamedError.textContent = '{{ __('form.error_choose_folder') }}';
                            customImageNamedError.classList.remove('hidden');
                        }
                        if (folderUpload) folderUpload.classList.add('border-red-400');
                        return;
                    }
                    event.preventDefault();
                    const formData = new FormData(form);
                    formData.delete('folder_upload[]');
                    formData.delete('_token');
                    files.forEach((file) => {
                        const path = file.webkitRelativePath || file.name;
                        formData.append('folder_paths[]', path);
                        formData.append('folder_files[]', file);
                    });
                    submitFormWithLiveDebug(form, formData);
                    return;
                }

                // Handle Custom Images Meals Store validation
                if (mode === 'Custom Images Meals Store') {
                    const folderNameInput = document.getElementById('folder_name');
                    const mealImagesFileInput = document.getElementById('meal_images');
                    
                    let hasError = false;
                    
                    if (!folderNameInput || !folderNameInput.value.trim()) {
                        hasError = true;
                        if (folderNameInput) {
                            folderNameInput.classList.add('border-red-400');
                        }
                    } else if (folderNameInput) {
                        folderNameInput.classList.remove('border-red-400');
                    }
                    
                    if (!mealImagesFileInput || !mealImagesFileInput.files || mealImagesFileInput.files.length === 0) {
                        hasError = true;
                        if (mealImagesFileInput) {
                            mealImagesFileInput.classList.add('border-red-400');
                        }
                        if (customImagesMealsError) {
                            customImagesMealsError.textContent = 'Please upload at least one image.';
                            customImagesMealsError.classList.remove('hidden');
                        }
                    } else {
                        const fileCount = mealImagesFileInput.files.length;
                        if (fileCount > 50) {
                            hasError = true;
                            if (mealImagesFileInput) {
                                mealImagesFileInput.classList.add('border-red-400');
                            }
                            if (customImagesMealsError) {
                                customImagesMealsError.textContent = 'Please upload no more than 50 images.';
                                customImagesMealsError.classList.remove('hidden');
                            }
                        } else {
                            if (mealImagesFileInput) {
                                mealImagesFileInput.classList.remove('border-red-400');
                            }
                            if (customImagesMealsError) {
                                customImagesMealsError.classList.add('hidden');
                            }
                        }
                    }
                    
                    if (hasError) {
                        event.preventDefault();
                        return;
                    }
                    
                    // Ensure description field is properly configured before submission
                    if (descriptionField) {
                        descriptionField.setAttribute('name', 'description');
                        descriptionField.removeAttribute('disabled');
                    }
                    event.preventDefault();
                    submitFormWithLiveDebug(form);
                    return;
                }
                
                // Skip validation for other non-image-based methods (Meal Store, etc.) - submit with live debug
                if (mode === 'Meal Store' || mode === 'Category Store' || mode === 'Category Ingredients Store' || mode === 'Ingredients Store') {
                    // Ensure description field is properly configured before submission
                    if (descriptionField) {
                        descriptionField.setAttribute('name', 'description');
                        descriptionField.removeAttribute('disabled');
                    }
                    event.preventDefault();
                    submitFormWithLiveDebug(form);
                    return;
                }
                
                // Handle image-based methods (excluding Custom Images Meals Store as it's handled separately)
                if (mode !== 'Drinks Store' && mode !== 'Hot Drinks Store' && mode !== 'Natural Juices Store' && mode !== 'Sweets Store' && mode !== 'Pasta Meals Store' && mode !== 'Burger Store' && mode !== 'Sandwiches Store' && mode !== 'Ingredients Images Store') {
                    return;
                }

                let activeSection, errorElement;
                if (mode === 'Drinks Store') {
                    activeSection = drinksSection;
                    errorElement = drinksError;
                } else if (mode === 'Hot Drinks Store') {
                    activeSection = hotDrinksSection;
                    errorElement = hotDrinksError;
                } else if (mode === 'Natural Juices Store') {
                    activeSection = naturalJuicesSection;
                    errorElement = naturalJuicesError;
                } else if (mode === 'Sweets Store') {
                    activeSection = sweetsSection;
                    errorElement = sweetsError;
                } else if (mode === 'Pasta Meals Store') {
                    activeSection = pastaMealsSection;
                    errorElement = pastaMealsError;
                } else if (mode === 'Burger Store') {
                    activeSection = burgerSection;
                    errorElement = burgerError;
                } else if (mode === 'Sandwiches Store') {
                    activeSection = sandwichesSection;
                    errorElement = sandwichesError;
                } else if (mode === 'Ingredients Images Store') {
                    activeSection = ingredientsSection;
                    errorElement = ingredientsError;
                }

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
                    const nameAr = checkbox.dataset.drinkNameAr || '';

                    if (checkbox.checked) {
                        const priceValue = priceInput.value.trim();
                        if (priceValue === '' || isNaN(priceValue) || Number(priceValue) < 0) {
                            hasError = true;
                            card.classList.add('border-red-400');
                        } else {
                            card.classList.remove('border-red-400');
                            const selection = {
                                key: name,
                                name: name ? name.toLowerCase() : name,
                                label,
                                price: Number(priceValue).toFixed(2),
                            };
                            // Include name_ar for Natural Juices Store
                            if (mode === 'Natural Juices Store' && nameAr) {
                                selection.name_ar = nameAr;
                            }
                            selections.push(selection);
                        }
                    } else {
                        card.classList.remove('border-red-400');
                    }
                });

                if (selections.length === 0) {
                    hasError = true;
                }

                if (hasError) {
                    event.preventDefault();
                    errorElement.classList.remove('hidden');
                    return;
                }

                errorElement.classList.add('hidden');
                drinksPayloadInput.value = JSON.stringify(selections);
                const descriptionLines = selections.map((item) => `${item.name} : ${item.price}`);
                descriptionField.value = `{\n${descriptionLines.join('\n')}\n}`;
                event.preventDefault();
                submitFormWithLiveDebug(form);
                return;
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

