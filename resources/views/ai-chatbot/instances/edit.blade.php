@php
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    $activeProject = 'ai-chatbot';
@endphp

@extends('layouts.kaman')

@section('title', __('chatbot.instance_prompt') . ' — ' . $instance->name)
@section('tag', __('chatbot.tag'))

@section('content')
    <div class="page-container">
        <div class="mx-auto w-full max-w-3xl">

            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="kaman-eyebrow mb-2">{{ __('chatbot.instance_prompt') }}</p>
                    <h1 class="text-2xl font-semibold text-[#2b1e11]">{{ $instance->name }}</h1>
                    <p class="mt-1 text-sm text-[#7c6a56]">
                        {{ __('chatbot.instance_prompt_desc') }}
                    </p>
                </div>
                <a href="{{ route('ai-chatbot.instances.show', $instance) }}" class="kaman-button-ghost kaman-button--sm">
                    {{ __('chatbot.back_to_chat') }}
                </a>
            </div>

            @if(session('status'))
                <div class="mb-4 rounded-xl border border-green-200 bg-green-50/70 px-4 py-3 text-sm text-green-700">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50/70 px-4 py-3 text-sm text-red-600">
                    {{ $errors->first() }}
                </div>
            @endif

            <form action="{{ route('ai-chatbot.instances.update', $instance) }}" method="post"
                  class="kaman-card kaman-card--pad space-y-4">
                @csrf
                @method('PUT')

                <div class="space-y-2">
                    <label for="name" class="kaman-label block">{{ __('chatbot.instance_name') }}</label>
                    <input id="name" name="name" type="text" required maxlength="120"
                           value="{{ old('name', $instance->name) }}"
                           class="kaman-input w-full">
                </div>

                <div class="space-y-2">
                    <label for="system_prompt" class="kaman-label block">{{ __('chatbot.system_prompt') }}</label>
                    <textarea id="system_prompt" name="system_prompt" rows="10" required
                              class="kaman-input kaman-scroll w-full font-mono leading-relaxed">{{ old('system_prompt', $instance->system_prompt) }}</textarea>
                </div>

                <div class="pt-2">
                    <button type="submit" class="kaman-button">
                        {{ __('chatbot.save_instance') }}
                    </button>
                </div>
            </form>

            <div class="kaman-card kaman-card--pad mt-5 border-red-200/60">
                <h2 class="text-sm font-semibold text-[#2b1e11] mb-1">{{ __('chatbot.danger_zone') }}</h2>
                <p class="text-xs text-[#a78a6c] mb-4">
                    {{ __('chatbot.danger_zone_desc') }}
                </p>
                <form action="{{ route('ai-chatbot.instances.destroy', $instance) }}" method="post"
                      onsubmit="return confirm(@json(__('chatbot.delete_instance_confirm')))">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="kaman-button-danger kaman-button--sm">
                        {{ __('chatbot.delete_instance') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
