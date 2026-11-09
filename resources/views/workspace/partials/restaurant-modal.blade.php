{{--
    Mounts the Overview door's restaurant modal on a page of another door, opening on that door's section.
    Elements with data-act="open-{{ $surface }}" data-id="<restaurant id>" open it.
--}}
@php
    $rmUser = auth()->user();
    $rmCatalog = app(\App\Http\Controllers\AgentsWorkspaceController::class)->catalog();
    $rmSupport = app(\App\Services\KamanSupport\RestaurantSupport::class);
    // Without the Overview door the restaurant's details stay locked, so they don't reach this page.
    if (! $rmUser?->canOpenDoor(\App\Enums\Door::Overview)) {
        $rmCatalog = [
            'companies' => array_map(
                static fn (array $company): array => array_intersect_key($company, array_flip(['id', 'name', 'subdomain', 'color', 'logo', 'cityId'])),
                $rmCatalog['companies'],
            ),
            'cities' => [],
        ];
    }
    // Someone limited to one product only sees the restaurants that use it.
    if ($rmUser?->supportProduct() !== null) {
        $rmSlugs = array_flip(array_map('mb_strtolower', app(\App\Services\KamanSupport\SupportDeviceSessionService::class)->restaurants()));
        $rmCatalog['companies'] = array_values(array_filter(
            $rmCatalog['companies'],
            static fn (array $company): bool => isset($rmSlugs[mb_strtolower(trim((string) ($company['subdomain'] ?? '')))]),
        ));
    }
    $rmBoot = [
        'page' => $surface,
        'surface' => $surface,
        'user' => $rmUser?->name,
        'locale' => app()->getLocale(),
        'images' => asset('images/agents'),
        'companies' => $rmCatalog['companies'],
        'cities' => $rmCatalog['cities'],
        'finance' => app(\App\Services\Finance\RestaurantFinance::class)->forUser($rmUser),
        'financeLinks' => \App\Services\Finance\RestaurantFinance::links($rmUser),
        'supportBoot' => $rmSupport->boot($rmUser),
        'extraRestaurants' => $rmSupport->extraRestaurants($rmUser),
        'open' => $open ?? null,
        'stats' => (object) [],
        'activity' => [],
        'links' => (object) [],
        'copy' => (object) [],
    ];
@endphp
@push('head')
    <link rel="stylesheet" href="{{ asset('css/agents-workspace.css') }}?v={{ filemtime(public_path('css/agents-workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/finance-workspace.css') }}?v={{ filemtime(public_path('css/finance-workspace.css')) }}">
@endpush
<dialog id="ag-portal" class="ag-portal"></dialog>
<div id="ag-toast" class="ag-toast" hidden></div>
@push('scripts')
    <script>window.AGENTS_BOOT = {{ Illuminate\Support\Js::from($rmBoot) }};</script>
    <script src="{{ asset('js/agents-workspace.js') }}?v={{ filemtime(public_path('js/agents-workspace.js')) }}" defer></script>
@endpush
