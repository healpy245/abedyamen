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

                <div class="border-t border-[#eadfce] pt-4 space-y-4">
                    <div>
                        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.greenapi_title') }}</h2>
                        <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.greenapi_desc') }}</p>
                    </div>

                    <div class="space-y-2">
                        <label for="greenapi_url" class="kaman-label block">{{ __('chatbot.greenapi_send_url') }}</label>
                        <input id="greenapi_url" name="greenapi_url" type="url" maxlength="512"
                               value="{{ old('greenapi_url', $instance->greenapi_url) }}"
                               placeholder="{{ __('chatbot.greenapi_send_url_placeholder') }}"
                               class="kaman-input w-full font-mono text-xs">
                        <p class="text-xs text-[#a78a6c]">{{ __('chatbot.greenapi_send_url_help') }}</p>
                    </div>

                    <div class="space-y-2">
                        <label for="greenapi_webhook_url" class="kaman-label block">{{ __('chatbot.greenapi_webhook_url') }}</label>
                        <div class="flex flex-wrap gap-2">
                            <input id="greenapi_webhook_url" type="text" readonly
                                   value="{{ $greenapiWebhookUrl }}"
                                   class="kaman-input min-w-0 flex-1 font-mono text-xs bg-[#faf6f0]">
                            <button type="button" class="kaman-button-ghost kaman-button--sm"
                                    onclick="navigator.clipboard.writeText(document.getElementById('greenapi_webhook_url').value)">
                                {{ __('chatbot.copy') }}
                            </button>
                        </div>
                        <p class="text-xs text-[#a78a6c]">{{ __('chatbot.greenapi_webhook_url_help') }}</p>
                    </div>

                    <div class="space-y-2">
                        <label for="allowed_reply_phones" class="kaman-label block">{{ __('chatbot.allowed_reply_phones') }}</label>
                        <textarea id="allowed_reply_phones" name="allowed_reply_phones" rows="4"
                                  placeholder="{{ __('chatbot.allowed_reply_phones_placeholder') }}"
                                  class="kaman-input kaman-scroll w-full font-mono text-xs leading-relaxed">{{ old('allowed_reply_phones', implode("\n", $instance->allowedReplyPhones())) }}</textarea>
                        <p class="text-xs text-[#a78a6c]">{{ __('chatbot.allowed_reply_phones_help') }}</p>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="kaman-button">
                        {{ __('chatbot.save_instance') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
