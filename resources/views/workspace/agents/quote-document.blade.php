@php
    use App\Support\FinanceMoney;
    /** @var \App\Models\Sales\SalesQuote $quote */
    $rtl = $lang !== 'en';
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $quote->title() }} — {{ $quote->customerName() }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;600;800&family=Almarai:wght@400;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/finance-workspace.css') }}?v={{ filemtime(public_path('css/finance-workspace.css')) }}">
    <style>
        body.fin-doc-page { font-family: {{ $lang === 'ar' ? "'Almarai'" : "'Heebo'" }}, sans-serif; }
        .sq-doc-section { margin: 22px 0 6px; font-size: 15px; }
        body.is-pdf { background: #fff; padding: 0; }
        body.is-pdf .fin-paper { margin: 0 auto; box-shadow: none; }
    </style>
</head>
<body class="fin-doc-page {{ $pdf ? 'is-pdf' : '' }}">
    @unless($pdf)
        <div class="fin-doc-tools">
            <a class="fin-btn fin-btn--ghost" href="{{ route('workspace.agents.sales', ['open' => $quote->id]) }}">{{ __('sales.doc.back') }}</a>
            <button type="button" class="fin-btn fin-btn--primary" onclick="window.print()">{{ __('sales.doc.print') }}</button>
        </div>
    @endunless
    <article class="fin-paper">
        <header class="fin-paper__head">
            <div>
                <img src="{{ asset('kaman.png') }}" alt="" class="fin-paper__brand">
                <strong>{{ __('sales.doc.brand') }}</strong>
            </div>
            <div class="fin-paper__type">
                <h1>{{ __($quote->isSold() ? 'sales.doc.sale' : 'sales.doc.quote') }}</h1>
                <span dir="ltr">#{{ $quote->number }}</span>
                <span>{{ ($quote->sold_at ?? $quote->created_at)?->format('d/m/Y') }}</span>
            </div>
        </header>
        <section class="fin-paper__to">
            <span>{{ __('sales.doc.for') }}</span>
            <strong>{{ $quote->customerName() }}</strong>
            @if($quote->contact_name)<small>{{ $quote->contact_name }}</small>@endif
        </section>

        @foreach(['monthly', 'one_time'] as $billing)
            @php $lines = $quote->items->where('billing', $billing); @endphp
            @continue($lines->isEmpty())
            <h2 class="sq-doc-section">{{ __('sales.doc.'.$billing) }}</h2>
            <table class="fin-paper__lines">
                <thead>
                    <tr>
                        <th>#</th>
                        <th></th>
                        <th>{{ __('sales.doc.qty') }}</th>
                        <th>{{ __('sales.doc.unit') }}</th>
                        <th>{{ __('sales.doc.line') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lines as $line)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $line->displayName($lang) }}</td>
                            <td>{{ $line->quantityLabel() }}</td>
                            <td>{{ FinanceMoney::format($line->unit_price) }}</td>
                            <td>{{ FinanceMoney::format($line->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach

        <section class="fin-paper__totals">
            @if((float) $quote->monthly_total > 0)
                <div><span>{{ __('sales.monthly_total') }}</span><b>{{ FinanceMoney::format($quote->monthly_total) }}</b></div>
            @endif
            @if((float) $quote->one_time_total > 0)
                <div class="is-total"><span>{{ __('sales.one_time_total') }}</span><b>{{ FinanceMoney::format($quote->one_time_total) }}</b></div>
            @endif
        </section>
        <p class="fin-paper__notes">{{ __('sales.catalog.no_vat') }}@if($quote->notes)<br>{{ $quote->notes }}@endif</p>
        @if($quote->agent)
            <footer class="fin-paper__foot">{{ __('sales.doc.agent') }} {{ $quote->agent->name }}</footer>
        @endif
    </article>
    @if($pdf)
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <script>
            // Loaded by the quote panel in a hidden frame: render the paper to a PDF, then either download it
            // or (?pdf=blob, for WhatsApp) hand the file back to the panel.
            (function () {
                var asBlob = @json(request('pdf') === 'blob');
                var tell = function (ok, blob) { try { parent.postMessage({ type: 'sq-pdf', ok: ok, blob: blob || null }, location.origin); } catch (e) {} };
                (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () {
                    var worker = html2pdf().set({
                        margin: [8, 0, 8, 0],
                        filename: @json($filename.'-'.$lang.'.pdf'),
                        image: { type: 'jpeg', quality: 0.96 },
                        html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                        pagebreak: { mode: ['css', 'legacy'] }
                    }).from(document.querySelector('.fin-paper'));
                    return asBlob ? worker.outputPdf('blob') : worker.save();
                }).then(function (blob) { tell(true, asBlob ? blob : null); }, function () { tell(false); });
            })();
        </script>
    @endif
</body>
</html>
