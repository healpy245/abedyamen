@if(session('success'))
    <div class="app-dev-flash app-dev-flash--success rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">
        {{ session('success') }}
    </div>
@endif

@if(session('info'))
    <div class="app-dev-flash app-dev-flash--info rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900" role="status">
        {{ session('info') }}
    </div>
@endif

@if(session('error'))
    <div class="app-dev-flash app-dev-flash--error rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800" role="alert">
        {{ session('error') }}
    </div>
@endif

@if($errors->any())
    <div class="app-dev-flash app-dev-flash--error rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800" role="alert">
        <ul class="list-disc ps-5 space-y-0.5">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
