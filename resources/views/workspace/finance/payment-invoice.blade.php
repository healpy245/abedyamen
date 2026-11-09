@php
    use App\Support\FinanceMoney;
    /** @var \App\Models\Finance\FinancePayment $payment */
    $client = $payment->client;
    $period = $payment->period->translatedFormat('F Y');
    // The month's itemised features when they add up to what was charged; otherwise one line for the month.
    $lines = collect($payment->items ?? [])->filter(fn ($row) => is_array($row) && filled($row['name'] ?? null));
    if ($lines->isEmpty() || abs($lines->sum(fn ($row) => (float) ($row['amount'] ?? 0)) - (float) $payment->amount) > 0.009) {
        $lines = collect([['name' => __('finance.payment_invoice.line', ['period' => $period]), 'amount' => (float) $payment->amount]]);
    }
    $locale = app()->getLocale();
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ in_array($locale, ['ar', 'he'], true) ? 'rtl' : 'ltr' }}">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('finance.payment_invoice.title') }} {{ $payment->displayNumber() }} — {{ $client?->name }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/finance-workspace.css') }}?v={{ filemtime(public_path('css/finance-workspace.css')) }}">
</head>
<body class="fin-doc-page">
    <div class="fin-doc-tools">
        <a class="fin-btn fin-btn--ghost" href="{{ route('workspace.finance.clients', ['open' => $payment->finance_client_id, 'tab' => 'payments']) }}">{{ __('finance.invoice.back') }}</a>
        <button type="button" class="fin-btn fin-btn--primary" onclick="window.print()">{{ __('finance.payment_invoice.print') }}</button>
    </div>
    <article class="fin-paper">
        <header class="fin-paper__head">
            <div>
                <img src="{{ asset('kaman.png') }}" alt="" class="fin-paper__brand">
                <strong>MFITGROUP</strong>
            </div>
            <div class="fin-paper__type">
                <h1>{{ __('finance.payment_invoice.title') }}</h1>
                <span dir="ltr">{{ __('finance.invoice.number') }} {{ $payment->displayNumber() }}</span>
                <span>{{ ($payment->paid_on ?? $payment->created_at)->format('d/m/Y') }}</span>
            </div>
        </header>
        <section class="fin-paper__to">
            <span>{{ __('finance.invoice.billed_to') }}</span>
            <strong>{{ $client?->name }}</strong>
            @if($client?->business_number)
                <small lang="he">ח.פ. / ע.מ. <span dir="ltr">{{ $client->business_number }}</span></small>
            @endif
            @if($client?->contact_name)
                <small>{{ $client->contact_name }}</small>
            @endif
            @if($client?->phone)
                <small dir="ltr">{{ $client->phone }}</small>
            @endif
        </section>
        <dl class="fin-paper__facts">
            <div><dt>{{ __('finance.payment_invoice.period') }}</dt><dd>{{ $period }}</dd></div>
            <div><dt>{{ __('finance.payment_invoice.status') }}</dt><dd>{{ $payment->status->label() }}</dd></div>
            <div><dt>{{ __('finance.payment_invoice.method') }}</dt><dd>{{ $payment->method?->label() ?? '—' }}</dd></div>
            <div><dt>{{ __('finance.payment_invoice.paid_on') }}</dt><dd>{{ $payment->paid_on?->format('d/m/Y') ?? '—' }}</dd></div>
            @if($payment->reference)
                <div><dt>{{ __('finance.payment_invoice.reference') }}</dt><dd dir="auto">{{ $payment->reference }}</dd></div>
            @endif
        </dl>
        <table class="fin-paper__lines">
            <thead>
                <tr>
                    <th>#</th>
                    <th></th>
                    <th>{{ __('finance.invoice.line') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $line)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $line['name'] }}</td>
                        <td>{{ FinanceMoney::format((float) ($line['amount'] ?? 0)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <section class="fin-paper__totals">
            <div class="is-total"><span>{{ __('finance.modal.total') }}</span><b>{{ FinanceMoney::format($payment->amount) }}</b></div>
        </section>
        @if($payment->note)
            <p class="fin-paper__notes">{{ $payment->note }}</p>
        @endif
    </article>
</body>
</html>
