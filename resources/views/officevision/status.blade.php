<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Status</title>
    <style>
        body { margin: 0; min-height: 100vh; background: #101318; color: #e8edf2; font-family: sans-serif; }
        main { max-width: 32rem; margin: 0 auto; padding: 48px 20px; }
        p { line-height: 1.6; }
        code { color: #3ddcff; }
    </style>
</head>
<body>
    <main>
        <p>API: <code>/api/officevision</code></p>
        <p>Pairing key: {{ config('officevision.pairing_key') ? 'configured' : 'not set' }}</p>
    </main>
</body>
</html>
