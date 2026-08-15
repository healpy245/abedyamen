@php
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    $settingsUrl = route('ai-chatbot.instances.edit', $instance);
    $onSettingsPage = request()->routeIs('ai-chatbot.instances.edit');
@endphp

<div class="flex flex-wrap items-center gap-2 mt-2">
    @if($instance->hasMalanIntegration())
        <a href="{{ route('ai-chatbot.workspace.conversations', $instance) }}"
           class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition border border-[#f1dfc5] bg-white text-[#7c6a56] hover:border-[#f47a2e]/40 hover:text-[#f16229]">
            {{ __('chatbot.workspace.nav_conversations') }}
        </a>
    @endif
    <a href="{{ $settingsUrl }}"
       class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition border
           {{ $onSettingsPage
               ? 'border-[#f47a2e]/30 bg-[#f47a2e]/12 text-[#f16229]'
               : 'border-[#f1dfc5] bg-white text-[#7c6a56] hover:border-[#f47a2e]/40 hover:text-[#f16229]' }}">
        <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M10 12.5C11.3807 12.5 12.5 11.3807 12.5 10C12.5 8.61929 11.3807 7.5 10 7.5C8.61929 7.5 7.5 8.61929 7.5 10C7.5 11.3807 8.61929 12.5 10 12.5Z" stroke="currentColor" stroke-width="1.5"/>
            <path d="M15.8333 10C15.8333 9.58333 15.9167 9.16667 16.0833 8.79167L17.0833 6.79167C17.25 6.41667 17.1667 5.95833 16.875 5.66667L14.3333 3.125C14.0417 2.83333 13.5833 2.75 13.2083 2.91667L11.2083 3.91667C10.8333 4.08333 10.4167 4.16667 10 4.16667C9.58333 4.16667 9.16667 4.08333 8.79167 3.91667L6.79167 2.91667C6.41667 2.75 5.95833 2.83333 5.66667 3.125L3.125 5.66667C2.83333 5.95833 2.75 6.41667 2.91667 6.79167L3.91667 8.79167C4.08333 9.16667 4.16667 9.58333 4.16667 10C4.16667 10.4167 4.08333 10.8333 3.91667 11.2083L2.91667 13.2083C2.75 13.5833 2.83333 14.0417 3.125 14.3333L5.66667 16.875C5.95833 17.1667 6.41667 17.25 6.79167 17.0833L8.79167 16.0833C9.16667 15.9167 9.58333 15.8333 10 15.8333C10.4167 15.8333 10.8333 15.9167 11.2083 16.0833L13.2083 17.0833C13.5833 17.25 14.0417 17.1667 14.3333 16.875L16.875 14.3333C17.1667 14.0417 17.25 13.5833 17.0833 13.2083L16.0833 11.2083C15.9167 10.8333 15.8333 10.4167 15.8333 10Z" stroke="currentColor" stroke-width="1.5"/>
        </svg>
        <span>{{ __('chatbot.instance_settings') }}</span>
    </a>
</div>
