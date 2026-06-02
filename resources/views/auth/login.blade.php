<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - AI Chatbot Studio</title>
    @vite('resources/css/app.css')
    <style>
        .auth-shell {
            width: 100%;
            max-width: 30rem;
            margin-inline: auto;
        }

        .auth-input {
            background-color: rgb(15 23 42 / 0.9) !important;
            color: rgb(241 245 249) !important;
            border-color: rgb(51 65 85) !important;
        }

        .auth-input::placeholder {
            color: rgb(148 163 184) !important;
        }

        .auth-input:-webkit-autofill,
        .auth-input:-webkit-autofill:hover,
        .auth-input:-webkit-autofill:focus {
            -webkit-text-fill-color: rgb(241 245 249) !important;
            -webkit-box-shadow: 0 0 0px 1000px rgb(15 23 42 / 0.9) inset !important;
            transition: background-color 9999s ease-in-out 0s;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 antialiased flex items-center justify-center">
<div class="auth-shell px-4">
    <div class="mb-6 text-center">
        <div class="inline-flex items-center justify-center h-10 w-10 rounded-2xl bg-emerald-500/10 border border-emerald-400/60 mb-3">
            <span class="text-emerald-400 font-semibold text-sm">AI</span>
        </div>
        <h1 class="text-lg font-semibold">Sign in</h1>
        <p class="text-xs text-slate-400 mt-1">
            Use one of the seeded users, e.g. <span class="font-mono">admin@example.com</span>.
        </p>
    </div>

    <form method="POST" action="{{ url('/login') }}" class="space-y-4">
        @csrf

        <div class="space-y-1">
            <label for="email" class="block text-xs font-medium text-slate-200">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                   class="auth-input w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
            @error('email')
            <p class="text-[11px] text-rose-400 mt-0.5">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-1">
            <label for="password" class="block text-xs font-medium text-slate-200">Password</label>
            <input id="password" name="password" type="password" required
                   class="auth-input w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
        </div>

        <div class="flex items-center justify-between text-xs text-slate-400">
            <label class="inline-flex items-center gap-1">
                <input type="checkbox" name="remember"
                       class="h-3.5 w-3.5 rounded border-slate-700 bg-slate-900 text-emerald-500 focus:ring-emerald-500">
                <span>Remember me</span>
            </label>
        </div>

        <button type="submit"
                class="w-full inline-flex items-center justify-center rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 transition">
            Continue
        </button>
    </form>
</div>
</body>
</html>

