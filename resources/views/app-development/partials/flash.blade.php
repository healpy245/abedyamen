@if(session('success'))
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">
        {{ session('success') }}
    </div>
@endif

@if(session('error'))
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800" role="alert">
        {{ session('error') }}
    </div>
@endif

@if($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800" role="alert">
        <ul class="list-disc ps-5 space-y-0.5">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
