@php
    $target = url()->previous();
    if (! is_string($target) || $target === '' || $target === url()->current()) {
        $target = route('login');
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.sign_in') }}</title>
    <meta http-equiv="refresh" content="0;url={{ $target }}">
    <script>location.replace(@json($target));</script>
</head>
<body></body>
</html>
