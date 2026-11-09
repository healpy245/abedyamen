@php
    use App\Enums\Finance\InvoiceType;
    use App\Support\FinanceMoney;
    /** @var \App\Models\Finance\FinanceInvoice $invoice */
    $sign = $invoice->type === InvoiceType::Credit ? -1 : 1;
@endphp
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->type->label() }} {{ $invoice->displayNumber() }} — {{ $invoice->client?->name }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/finance-workspace.css') }}?v={{ filemtime(public_path('css/finance-workspace.css')) }}">
</head>
<body class="fin-doc-page">
    <div class="fin-doc-tools">
        <a class="fin-btn fin-btn--ghost" href="{{ route('workspace.finance.clients', ['open' => $invoice->finance_client_id, 'tab' => 'invoices']) }}">{{ __('finance.invoice.back') }}</a>
        <button type="button" class="fin-btn fin-btn--primary" onclick="window.print()">{{ __('finance.invoice.print') }}</button>
    </div>
    <article class="fin-paper">
        <header class="fin-paper__head">
            <div>
                <img src="{{ asset('kaman.png') }}" alt="" class="fin-paper__brand">
                <strong>MFITGROUP</strong>
            </div>
            <div class="fin-paper__type">
                <h1>{{ $invoice->type->label() }}</h1>
                <span dir="ltr">{{ __('finance.invoice.number') }} {{ $invoice->displayNumber() }}</span>
                <span>{{ $invoice->issued_on->format('d/m/Y') }}</span>
            </div>
        </header>
        <section class="fin-paper__to">
            <span>{{ __('finance.invoice.billed_to') }}</span>
            <strong>{{ $invoice->client?->name }}</strong>
            @if($invoice->client?->phone)
                <small dir="ltr">{{ $invoice->client->phone }}</small>
            @endif
        </section>
        <table class="fin-paper__lines">
            <thead>
                <tr>
                    <th>#</th>
                    <th></th>
                    <th>{{ __('finance.invoice.qty') }}</th>
                    <th>{{ __('finance.invoice.unit') }}</th>
                    <th>{{ __('finance.invoice.line') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $line)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $line->description }}</td>
                        <td>{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}</td>
                        <td>{{ FinanceMoney::format($line->unit_price) }}</td>
                        <td>{{ FinanceMoney::format($sign * (float) $line->line_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <section class="fin-paper__totals">
            @if($invoice->type->hasVat())
                <div><span>{{ __('finance.modal.subtotal') }}</span><b>{{ FinanceMoney::format($sign * (float) $invoice->subtotal) }}</b></div>
                <div><span>{{ __('finance.modal.vat', ['rate' => rtrim(rtrim(number_format((float) $invoice->vat_rate, 2), '0'), '.')]) }}</span><b>{{ FinanceMoney::format($sign * (float) $invoice->vat) }}</b></div>
            @endif
            <div class="is-total"><span>{{ __('finance.modal.total') }}</span><b>{{ FinanceMoney::format($sign * (float) $invoice->total) }}</b></div>
        </section>
        @if($invoice->notes)
            <p class="fin-paper__notes">{{ $invoice->notes }}</p>
        @endif
        @if($invoice->creator)
            <footer class="fin-paper__foot">{{ __('finance.invoice.issued_by') }} {{ $invoice->creator->name }}</footer>
        @endif
    </article>
</body>
</html>
