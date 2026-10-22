@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he'], true);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.chatbots_title') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 28px 16px;
            color: #0d1f14;
            font-family: {{ $isRtl ? '"Almarai", system-ui, sans-serif' : '"IBM Plex Sans", system-ui, sans-serif' }};
            background:
                radial-gradient(900px 420px at 100% -10%, rgba(47, 196, 99, .18), transparent 60%),
                radial-gradient(700px 380px at -10% 110%, rgba(15, 90, 44, .12), transparent 55%),
                #f3faf5;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(15, 90, 44, .05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(15, 90, 44, .05) 1px, transparent 1px);
            background-size: 28px 28px;
            mask-image: radial-gradient(circle at 50% 40%, #000 20%, transparent 78%);
        }
        .panel {
            position: relative;
            width: min(440px, 100%);
            overflow: hidden;
            border: 1px solid #e2ece5;
            border-radius: 28px;
            background: #fff;
            box-shadow: 0 30px 70px -28px rgba(4, 34, 15, .45);
        }
        .panel__head {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 26px 28px 22px;
            color: #fff;
            background:
                radial-gradient(220px 120px at 90% 0%, rgba(47, 196, 99, .45), transparent 70%),
                linear-gradient(165deg, #0a4321, #04220f);
        }
        .panel__logo {
            width: 52px;
            height: 52px;
            flex: none;
            border-radius: 16px;
            object-fit: cover;
            background: #fff;
            box-shadow: 0 8px 20px -10px rgba(0, 0, 0, .5);
        }
        .panel__head h1 {
            margin: 0;
            font-size: 26px;
            font-weight: 800;
            letter-spacing: 0;
        }
        .panel__head p {
            margin: 4px 0 0;
            color: rgba(255, 255, 255, .72);
            font-size: 14px;
            font-weight: 400;
            line-height: 1.45;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 16px;
            padding: 26px 28px 8px;
        }
        label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
            font-weight: 700;
        }
        input[type="email"],
        input[type="password"] {
            width: 100%;
            height: 50px;
            border: 1px solid #e2ece5;
            border-radius: 14px;
            background: #f7fbf8;
            padding: 0 14px;
            color: #0d1f14;
            font: inherit;
            font-size: 15px;
            outline: none;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        input:focus {
            border-color: #2fc463;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(47, 196, 99, .16);
        }
        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            color: #4b5d52;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .remember input { width: 16px; height: 16px; accent-color: #1fa24c; }
        button {
            height: 52px;
            border: 0;
            border-radius: 14px;
            background: linear-gradient(180deg, #2fc463, #1fa24c);
            color: #fff;
            font: inherit;
            font-size: 16px;
            font-weight: 800;
            box-shadow: 0 14px 28px -12px rgba(31, 162, 76, .9);
            cursor: pointer;
        }
        button:hover { filter: brightness(1.05); }
        .error { margin-top: 6px; color: #be123c; font-size: 13px; font-weight: 700; }
        .foot {
            margin: 0;
            padding: 8px 28px 24px;
            text-align: center;
            font-size: 13px;
        }
        .foot a {
            color: #15803d;
            font-weight: 800;
            text-decoration: none;
        }
        .foot a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <main class="panel">
        <header class="panel__head">
            <img class="panel__logo" src="{{ asset('kaman.png') }}" alt="" width="52" height="52">
            <div>
                <h1>{{ __('auth.chatbots_sign_in') }}</h1>
                <p>{{ __('auth.chatbots_subtitle') }}</p>
            </div>
        </header>

        <form method="POST" action="{{ route('chatbots.login') }}">
            @csrf
            <div>
                <label for="email">{{ __('auth.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="name@workspace.com">
                @error('email')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>
            <div>
                <label for="password">{{ __('auth.password') }}</label>
                <input id="password" name="password" type="password" required autocomplete="current-password">
                @error('password')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>
            <label class="remember">
                <input type="checkbox" name="remember">
                <span>{{ __('auth.remember_me') }}</span>
            </label>
            <button type="submit">{{ __('auth.continue') }}</button>
        </form>

        <p class="foot">
            <a href="{{ route('login') }}">{{ __('auth.kaman_login_link') }}</a>
        </p>
    </main>
</body>
</html>
