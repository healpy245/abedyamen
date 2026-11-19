@php
    /**
     * The leads report in the Kaman paper design. Opens with the PDF download started; it can also be printed.
     *
     * @var \Illuminate\Support\Collection<int, \App\Models\Sales\AgentLead> $leads
     */
    $lang = app()->getLocale();
    $rtl = $lang !== 'en';
    $period = $from !== null || $to !== null
        ? ($from?->format('d/m/Y') ?? '…').' – '.($to?->format('d/m/Y') ?? '…')
        : __('sales.report.all_time');
    $fileName = __('sales.report.file_name').'-'.now()->format('Y-m-d');
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('sales.report.title') }} — {{ $period }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;600;800&family=Almarai:wght@400;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/finance-workspace.css') }}?v={{ filemtime(public_path('css/finance-workspace.css')) }}">
    <style>
        body.fin-doc-page { font-family: {{ $lang === 'ar' ? "'Almarai'" : "'Heebo'" }}, sans-serif; }
        .lr-paper { max-width: 980px; }
        .lr-logos { display: flex; align-items: center; gap: 10px; }
        .lr-logos img { height: 40px; width: auto; border-radius: 10px; }
        .lr-meta { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 18px 0 4px; color: #7c6a56; font-size: 13px; }
        .lr-meta b { color: #231a12; }
        .lr-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 18px 0; }
        .lr-card { padding: 12px 14px; border: 1px solid #f1dcc4; border-radius: 12px; background: #fffaf4; break-inside: avoid; }
        .lr-card strong { display: flex; justify-content: space-between; font-size: 14px; }
        .lr-card strong b { color: #f58220; font-size: 20px; }
        .lr-card ul { margin: 6px 0 0; padding: 0; list-style: none; font-size: 12px; color: #7c6a56; }
        .lr-card li { display: flex; justify-content: space-between; }
        .lr-section { margin: 22px 0 8px; font-size: 15px; }
        .lr-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .lr-table th { padding: 8px 6px; border-bottom: 2px solid #f58220; color: #7c6a56; font-size: 11.5px; text-align: start; }
        .lr-table td { padding: 8px 6px; border-bottom: 1px solid #f3eadf; vertical-align: top; }
        .lr-table tr { break-inside: avoid; }
        .lr-chip { display: inline-block; padding: 1px 8px; border-radius: 999px; background: #fff1e2; color: #a5520b; font-size: 11.5px; white-space: nowrap; }
        .lr-empty { padding: 26px; text-align: center; color: #7c6a56; }
        body.is-pdf { background: #fff; padding: 0; }
        body.is-pdf .fin-paper { margin: 0 auto; border: 0; box-shadow: none; }
        @media (max-width: 640px) { .fin-paper { padding: 20px 14px; } .lr-table { font-size: 11.5px; } }
    </style>
</head>
<body class="fin-doc-page {{ $pdf ? 'is-pdf' : '' }}">
    @unless($pdf)
        <div class="fin-doc-tools">
            <button type="button" class="fin-btn fin-btn--ghost" onclick="window.print()">{{ __('sales.doc.print') }}</button>
            <button type="button" class="fin-btn fin-btn--primary" data-lr-download>{{ __('sales.report.download') }}</button>
        </div>
    @endunless
    <article class="fin-paper lr-paper">
        <header class="fin-paper__head">
            <div class="lr-logos">
                <img src="{{ asset('kaman.png') }}" alt="KAMAN">
                <img src="{{ asset('images/contract/mfit-group.png') }}" alt="MFIT Group">
            </div>
            <div class="fin-paper__type">
                <h1>{{ __('sales.report.title') }}</h1>
                <span>{{ $period }}</span>
                <span>{{ __('sales.report.made', ['date' => now()->format('d/m/Y H:i'), 'name' => $user->name]) }}</span>
            </div>
        </header>

        <div class="lr-meta">
            <span>{{ __('sales.report.total') }}: <b>{{ $leads->count() }}</b></span>
            @foreach($filters as $label => $value)
                <span>{{ $label }}: <b>{{ $value }}</b></span>
            @endforeach
        </div>

        <div class="lr-cards">
            @foreach($stages as $stage)
                <div class="lr-card">
                    <strong><span>{{ $stage['label'] }}</span><b>{{ $stage['count'] }}</b></strong>
                    @if($stage['subs'] !== [])
                        <ul>
                            @foreach($stage['subs'] as $sub)
                                <li><span>{{ $sub['label'] }}</span><span>{{ $sub['count'] }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>

        @if($agents->isNotEmpty())
            <h2 class="lr-section">{{ __('sales.report.by_agent') }}</h2>
            <div class="lr-cards">
                @foreach($agents as $agent)
                    <div class="lr-card"><strong><span>{{ $agent['name'] }}</span><b>{{ $agent['count'] }}</b></strong></div>
                @endforeach
            </div>
        @endif

        <h2 class="lr-section">{{ __('sales.report.leads') }}</h2>
        @if($leads->isEmpty())
            <p class="lr-empty">{{ __('sales.report.empty') }}</p>
        @else
            <table class="lr-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ __('sales.report.col_name') }}</th>
                        <th>{{ __('sales.report.col_contact') }}</th>
                        <th>{{ __('sales.report.col_city') }}</th>
                        <th>{{ __('sales.report.col_agent') }}</th>
                        <th>{{ __('sales.report.col_status') }}</th>
                        <th>{{ __('sales.report.col_added') }}</th>
                        <th>{{ __('sales.report.col_follow_up') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($leads as $lead)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td><strong>{{ $lead->name }}</strong></td>
                            <td>{{ $lead->contact }}@if($lead->phone)<br><bdi dir="ltr">{{ $lead->phone }}</bdi>@endif</td>
                            <td>{{ $cities[(string) $lead->city_id] ?? '' }}</td>
                            <td>{{ $lead->agent?->name ?? '—' }}</td>
                            <td><span class="lr-chip">{{ $lead->stage->group() === $lead->stage->value ? $lead->stage->label() : $lead->stage->groupLabel().' › '.$lead->stage->label() }}</span></td>
                            <td>{{ $lead->created_at?->format('d/m/Y') }}</td>
                            <td>{{ $lead->next_follow_up_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </article>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        (function () {
            var save = function () {
                var button = document.querySelector('[data-lr-download]');
                if (button) button.disabled = true;
                document.body.classList.add('is-pdf');
                return (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () {
                    return html2pdf().set({
                        margin: [8, 0, 8, 0],
                        filename: @json($fileName.'.pdf'),
                        image: { type: 'jpeg', quality: 0.96 },
                        html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
                        pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.lr-card'] }
                    }).from(document.querySelector('.fin-paper')).save();
                }).then(function () {
                    document.body.classList.remove('is-pdf');
                    if (button) button.disabled = false;
                });
            };
            var button = document.querySelector('[data-lr-download]');
            if (button) button.addEventListener('click', save);
            // Opened from the leads page: the PDF starts downloading straight away.
            if (@json(request()->boolean('download'))) save();
        })();
    </script>
</body>
</html>
