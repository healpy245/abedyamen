<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Code</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #101318; color: #e8edf2; font-family: sans-serif; }
        form { width: min(320px, calc(100% - 32px)); }
        input, button { width: 100%; box-sizing: border-box; margin-top: 12px; padding: 12px; border-radius: 8px; border: 0; font-size: 16px; }
        input { background: #1a1f27; color: inherit; }
        button { background: #3ddcff; color: #101318; font-weight: 600; }
    </style>
</head>
<body>
    <form method="post" action="{{ route('officevision.unlock') }}">
        @csrf
        <input name="code" type="password" inputmode="numeric" autocomplete="off" autofocus>
        <button type="submit">Continue</button>
    </form>
</body>
</html>
