@php
    $activeProject = 'ai-chatbot';
@endphp

@extends('layouts.kaman')

@section('title', __('chatbot.title'))
@section('tag', __('chatbot.tag'))

@section('content')
    <div class="page-container">
        <div class="mx-auto w-full max-w-xl kaman-card kaman-card--pad text-center">
            <p class="kaman-eyebrow mb-2">{{ __('chatbot.sidebar_title') }}</p>
            <h1 class="text-2xl font-semibold text-[#2b1e11]">{{ __('chatbot.no_seeded_instances_title') }}</h1>
            <p class="mt-3 text-sm text-[#7c6a56]">
                {{ __('chatbot.no_seeded_instances_body') }}
            </p>
            <pre class="mt-5 rounded-xl bg-[#fff6ea] px-4 py-3 text-left text-xs text-[#2b1e11] overflow-x-auto" dir="ltr">php artisan db:seed --class=SallyMalanChatbotInstanceSeeder
php artisan db:seed --class=KamanWhatsappChatbotInstanceSeeder</pre>
        </div>
    </div>
@endsection
