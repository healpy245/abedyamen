@php
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    /** @var \Illuminate\Support\Collection|\App\Models\AiChatbot\ChatbotInstance[] $instances */
    /** @var \Illuminate\Support\Collection|\App\Models\AiChatbot\ChatbotConversation[] $conversations */
    $activeId = $activeConversation?->id ?? null;
    $activeInstanceId = $instance->id;
@endphp

<aside class="w-full md:w-64 lg:w-72 shrink-0 border-b md:border-b-0 md:border-e border-[#f1dfc5] bg-white/70 flex flex-col">
    <div class="px-4 py-3 border-b border-[#f1dfc5] flex items-center justify-between gap-2">
        <div class="min-w-0">
            <div class="text-[0.65rem] font-semibold uppercase tracking-[0.18em] text-[#f47a2e]">
                {{ __('chatbot.sidebar_title') }}
            </div>
            <div class="text-[11px] text-[#a78a6c]">
                {{ __('chatbot.sidebar_subtitle') }}
            </div>
        </div>
        @if(auth()->user()?->is_admin)
            <a href="{{ route('ai-chatbot.admin.settings.edit') }}" class="kaman-chip kaman-chip--accent shrink-0">
                {{ __('chatbot.global') }}
            </a>
        @endif
    </div>

    <div class="p-3 border-b border-[#f1dfc5] space-y-3">
        <div class="text-[0.6rem] uppercase tracking-[0.18em] text-[#a78a6c] font-semibold px-1">
            {{ __('chatbot.instances') }}
        </div>

        <ul class="space-y-1 max-h-36 overflow-y-auto kaman-scroll">
            @forelse($instances as $botInstance)
                @php $isActiveInstance = $botInstance->id === $activeInstanceId; @endphp
                <li class="flex items-center gap-1">
                    <a href="{{ route('ai-chatbot.instances.show', $botInstance) }}"
                       class="flex-1 min-w-0 block rounded-lg px-2.5 py-1.5 text-xs truncate transition
                           {{ $isActiveInstance
                               ? 'bg-[#f47a2e]/12 text-[#f16229] border border-[#f47a2e]/30 font-semibold'
                               : 'text-[#7c6a56] hover:bg-[#f47a2e]/8 border border-transparent' }}">
                        {{ $botInstance->name }}
                    </a>
                    <a href="{{ route('ai-chatbot.instances.edit', $botInstance) }}"
                       class="shrink-0 p-1.5 rounded-lg border transition
                           {{ request()->routeIs('ai-chatbot.instances.edit') && $botInstance->id === $activeInstanceId
                               ? 'border-[#f47a2e]/30 bg-[#f47a2e]/12 text-[#f16229]'
                               : 'border-transparent text-[#a78a6c] hover:text-[#f16229] hover:bg-[#f47a2e]/8 hover:border-[#f47a2e]/20' }}"
                       title="{{ __('chatbot.instance_settings_for', ['name' => $botInstance->name]) }}"
                       aria-label="{{ __('chatbot.instance_settings_for', ['name' => $botInstance->name]) }}">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M10 12.5C11.3807 12.5 12.5 11.3807 12.5 10C12.5 8.61929 11.3807 7.5 10 7.5C8.61929 7.5 7.5 8.61929 7.5 10C7.5 11.3807 8.61929 12.5 10 12.5Z" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M15.8333 10C15.8333 9.58333 15.9167 9.16667 16.0833 8.79167L17.0833 6.79167C17.25 6.41667 17.1667 5.95833 16.875 5.66667L14.3333 3.125C14.0417 2.83333 13.5833 2.75 13.2083 2.91667L11.2083 3.91667C10.8333 4.08333 10.4167 4.16667 10 4.16667C9.58333 4.16667 9.16667 4.08333 8.79167 3.91667L6.79167 2.91667C6.41667 2.75 5.95833 2.83333 5.66667 3.125L3.125 5.66667C2.83333 5.95833 2.75 6.41667 2.91667 6.79167L3.91667 8.79167C4.08333 9.16667 4.16667 9.58333 4.16667 10C4.16667 10.4167 4.08333 10.8333 3.91667 11.2083L2.91667 13.2083C2.75 13.5833 2.83333 14.0417 3.125 14.3333L5.66667 16.875C5.95833 17.1667 6.41667 17.25 6.79167 17.0833L8.79167 16.0833C9.16667 15.9167 9.58333 15.8333 10 15.8333C10.4167 15.8333 10.8333 15.9167 11.2083 16.0833L13.2083 17.0833C13.5833 17.25 14.0417 17.1667 14.3333 16.875L16.875 14.3333C17.1667 14.0417 17.25 13.5833 17.0833 13.2083L16.0833 11.2083C15.9167 10.8333 15.8333 10.4167 15.8333 10Z" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                    </a>
                </li>
            @empty
                <li class="px-1 text-[11px] text-[#a78a6c]">
                    {{ __('chatbot.no_seeded_instances') }}
                </li>
            @endforelse
        </ul>

        <a href="{{ route('ai-chatbot.instances.edit', $instance) }}"
           class="flex items-center justify-center gap-2 w-full rounded-lg px-2 py-2 text-xs font-semibold transition border
               {{ request()->routeIs('ai-chatbot.instances.edit')
                   ? 'border-[#f47a2e]/30 bg-[#f47a2e]/12 text-[#f16229]'
                   : 'border-[#f1dfc5] bg-white/60 text-[#7c6a56] hover:border-[#f47a2e]/40 hover:text-[#f16229]' }}">
            <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M10 12.5C11.3807 12.5 12.5 11.3807 12.5 10C12.5 8.61929 11.3807 7.5 10 7.5C8.61929 7.5 7.5 8.61929 7.5 10C7.5 11.3807 8.61929 12.5 10 12.5Z" stroke="currentColor" stroke-width="1.5"/>
                <path d="M15.8333 10C15.8333 9.58333 15.9167 9.16667 16.0833 8.79167L17.0833 6.79167C17.25 6.41667 17.1667 5.95833 16.875 5.66667L14.3333 3.125C14.0417 2.83333 13.5833 2.75 13.2083 2.91667L11.2083 3.91667C10.8333 4.08333 10.4167 4.16667 10 4.16667C9.58333 4.16667 9.16667 4.08333 8.79167 3.91667L6.79167 2.91667C6.41667 2.75 5.95833 2.83333 5.66667 3.125L3.125 5.66667C2.83333 5.95833 2.75 6.41667 2.91667 6.79167L3.91667 8.79167C4.08333 9.16667 4.16667 9.58333 4.16667 10C4.16667 10.4167 4.08333 10.8333 3.91667 11.2083L2.91667 13.2083C2.75 13.5833 2.83333 14.0417 3.125 14.3333L5.66667 16.875C5.95833 17.1667 6.41667 17.25 6.79167 17.0833L8.79167 16.0833C9.16667 15.9167 9.58333 15.8333 10 15.8333C10.4167 15.8333 10.8333 15.9167 11.2083 16.0833L13.2083 17.0833C13.5833 17.25 14.0417 17.1667 14.3333 16.875L16.875 14.3333C17.1667 14.0417 17.25 13.5833 17.0833 13.2083L16.0833 11.2083C15.9167 10.8333 15.8333 10.4167 15.8333 10Z" stroke="currentColor" stroke-width="1.5"/>
            </svg>
            <span>{{ __('chatbot.instance_settings') }}</span>
        </a>

        @if($instance->storesMembers())
            <a href="{{ route('ai-chatbot.instances.members.index', $instance) }}"
               class="block text-center rounded-lg px-2 py-1.5 text-xs transition border
                   {{ ($membersPage ?? false)
                       ? 'border-[#f47a2e]/30 bg-[#f47a2e]/12 text-[#f16229] font-semibold'
                       : 'border-[#f1dfc5] bg-white/60 text-[#7c6a56] hover:border-[#f47a2e]/40 hover:text-[#f16229]' }}">
                {{ __('chatbot.members_count', ['count' => $instance->members_count ?? 0]) }}
            </a>
        @endif
    </div>

    <div class="p-3 border-b border-[#f1dfc5]">
        <form id="aiChatbotNewConversationForm"
              action="{{ route('ai-chatbot.instances.conversations.store', $instance) }}"
              method="post"
              class="w-full">
            @csrf
            <button type="submit" class="kaman-button w-full">
                <svg class="w-4 h-4" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M10 4V16M4 10H16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>{{ __('chatbot.new_chat') }}</span>
            </button>
        </form>
    </div>

    <div class="flex-1 overflow-y-auto kaman-scroll max-h-72 md:max-h-none">
        @if($conversations->isEmpty())
            <div class="px-4 py-4 text-xs text-[#a78a6c]">
                {{ __('chatbot.no_conversations') }}
            </div>
        @else
            <ul class="px-2 py-2 space-y-1">
                @foreach($conversations as $conversation)
                    @php $isActive = $conversation->id === $activeId; @endphp
                    <li class="group flex items-center gap-1">
                        <a href="{{ route('ai-chatbot.instances.conversations.show', ['instance' => $instance, 'conversation' => $conversation]) }}"
                           class="flex-1 min-w-0 rounded-lg px-2.5 py-1.5 transition border
                               {{ $isActive
                                   ? 'bg-[#f47a2e]/10 border-[#f47a2e]/25'
                                   : 'border-transparent hover:bg-[#f47a2e]/6' }}">
                            <span class="truncate text-xs block {{ $isActive ? 'text-[#2b1e11] font-semibold' : 'text-[#7c6a56]' }}">
                                {{ $conversation->title ?? __('chatbot.untitled_chat') }}
                            </span>
                            <span class="mt-0.5 text-[11px] text-[#a78a6c] block">
                                {{ $conversation->updated_at?->diffForHumans() }}
                            </span>
                        </a>
                        <form method="post"
                              action="{{ route('ai-chatbot.instances.conversations.destroy', ['instance' => $instance, 'conversation' => $conversation]) }}"
                              class="shrink-0">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="p-1.5 rounded-lg text-[#c7b69d] hover:text-red-500 hover:bg-red-50 transition"
                                    aria-label="{{ __('chatbot.delete_conversation') }}"
                                    onclick="return confirm(@json(__('chatbot.delete_conversation_confirm')))">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <path d="M5 6L6 15H14L15 6M8 6V5C8 4.44772 8.44772 4 9 4H11C11.5523 4 12 4.44772 12 5V6M4 6H16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</aside>
