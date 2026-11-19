<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('sales.contract.title') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/finance-workspace.css') }}?v={{ filemtime(public_path('css/finance-workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/contract.css') }}?v={{ filemtime(public_path('css/contract.css')) }}">
</head>
<body class="fin-doc-page ct-page">
    <article class="fin-paper ct-paper ct-closed">
        <img src="{{ asset('kaman.png') }}" alt="KAMAN" class="ct-logo">
        <h1>{{ __('sales.contract.closed_title') }}</h1>
        <p>{{ __('sales.contract.closed_body') }}</p>
    </article>
</body>
</html>
