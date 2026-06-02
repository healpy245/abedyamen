<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'AI Chatbot Studio')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite('resources/css/app.css')
    @stack('head')
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 antialiased">
<div class="min-h-screen flex flex-col">
    <header class="border-b border-slate-800 bg-slate-950/80 backdrop-blur">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="h-8 w-8 rounded-xl bg-emerald-500/10 border border-emerald-400/40 flex items-center justify-center">
                    <span class="text-emerald-400 font-semibold text-sm">AI</span>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-[0.18em] text-emerald-400/80 font-semibold">
                        AI Chatbot Studio
                    </div>
                    <div class="text-xs text-slate-400">
                        Conversational workspace for experiments
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3 text-xs text-slate-400">
                <span class="hidden sm:inline">
                    {{ auth()->user()?->email }}
                </span>
                <span class="inline-flex items-center gap-1 rounded-full border border-slate-800 bg-slate-900/60 px-2 py-1">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>Connected</span>
                </span>
            </div>
        </div>
    </header>

    <main class="flex-1 flex flex-col">
        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>

