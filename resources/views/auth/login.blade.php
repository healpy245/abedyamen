@extends('layouts.kaman')

@section('title', __('auth.title'))
@section('tag', __('app.partner_portal'))

@section('content')
    <div class="flex-1 flex items-center justify-center px-4 py-12 sm:py-20">
        <div class="w-full max-w-md">

            <div class="mb-8 text-center">
                <span class="mx-auto mb-4 inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-[#f47a2e]/12 border border-[#f47a2e]/25">
                    <svg class="h-6 w-6 text-[#f16229]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/>
                    </svg>
                </span>
                <h1 class="text-2xl font-semibold text-[#2b1e11]">{{ __('auth.sign_in') }}</h1>
                <p class="mt-2 text-sm text-[#a78a6c]">
                    {{ __('auth.subtitle') }}
                </p>
            </div>

            <div class="kaman-card kaman-card--pad">
                <form method="POST" action="{{ url('/login') }}" class="space-y-5">
                    @csrf

                    <div class="space-y-2">
                        <label for="email" class="kaman-label block">{{ __('auth.email') }}</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                               autocomplete="username"
                               class="kaman-input w-full @error('email') border-red-400 @enderror"
                               placeholder="{{ __('auth.email_placeholder') }}">
                        @error('email')
                        <p class="text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="password" class="kaman-label block">{{ __('auth.password') }}</label>
                        <input id="password" name="password" type="password" required
                               autocomplete="current-password"
                               class="kaman-input w-full"
                               placeholder="••••••••">
                    </div>

                    <label class="inline-flex items-center gap-2 text-xs text-[#7c6a56] cursor-pointer">
                        <input type="checkbox" name="remember"
                               class="h-4 w-4 rounded border-[#f1dfc5] text-[#f47a2e] focus:ring-[#f59f43]">
                        <span>{{ __('auth.remember_me') }}</span>
                    </label>

                    <button type="submit" class="kaman-button w-full">
                        {{ __('auth.continue') }}
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-xs text-[#a78a6c]">
                {{ __('auth.footer') }}
            </p>
        </div>
    </div>
@endsection
