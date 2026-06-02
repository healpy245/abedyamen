@php
    /** @var \Illuminate\Support\Collection|\App\Models\AiChatbot\ChatbotConversation[] $conversations */
    $activeId = $activeConversation?->id ?? null;
@endphp

<aside class="w-full md:w-64 lg:w-72 border-r border-slate-800 bg-slate-950/70 flex flex-col">
    <div class="px-3 py-3 border-b border-slate-800 flex items-center justify-between gap-2">
        <div>
            <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                AI Chatbot Studio
            </div>
            <div class="text-[11px] text-slate-500">
                Your private conversations
            </div>
        </div>
        <a href="{{ route('ai-chatbot.admin.settings.edit') }}"
           class="inline-flex items-center gap-1 rounded-full border border-slate-800 bg-slate-900/80 px-2 py-1 text-[11px] text-slate-300 hover:border-emerald-500/60 hover:text-emerald-300 transition">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400/80"></span>
            <span>Settings</span>
        </a>
    </div>

    <div class="p-3 border-b border-slate-800">
        <form id="aiChatbotNewConversationForm" action="{{ route('ai-chatbot.conversations.store') }}" method="post" class="w-full">
            @csrf
            <button type="submit"
                    class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-500 text-slate-950 text-sm font-medium px-3 py-2 hover:bg-emerald-400 transition focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 focus:ring-offset-slate-950">
                <svg class="w-4 h-4" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M10 4V16M4 10H16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span>New Chat</span>
            </button>
        </form>
    </div>

    <div class="flex-1 overflow-y-auto">
        @if($conversations->isEmpty())
            <div class="px-3 py-4 text-xs text-slate-500">
                No conversations yet. Start a new chat to begin.
            </div>
        @else
            <ul class="px-2 py-2 space-y-1 text-sm">
                @foreach($conversations as $conversation)
                    @php
                        $isActive = $conversation->id === $activeId;
                    @endphp
                    <li class="group flex items-center gap-1">
                        <a href="{{ route('ai-chatbot.conversations.show', $conversation) }}"
                           class="flex-1 min-w-0 rounded-md px-2 py-1.5 {{ $isActive ? 'bg-slate-800/80 text-slate-50' : 'text-slate-300 hover:bg-slate-900/80' }} transition">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-xs">
                                    {{ $conversation->title ?? 'Untitled chat' }}
                                </span>
                            </div>
                            <div class="mt-0.5 text-[11px] text-slate-500 flex items-center gap-1">
                                <span class="h-1 w-1 rounded-full bg-slate-500/70"></span>
                                <span class="truncate">
                                    {{ $conversation->updated_at?->diffForHumans() }}
                                </span>
                            </div>
                        </a>
                        <form method="post"
                              action="{{ route('ai-chatbot.conversations.destroy', $conversation) }}"
                              class="shrink-0">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="p-1 rounded-md text-slate-500 hover:text-rose-400 hover:bg-rose-950/40 transition"
                                    onclick="return confirm('Delete this conversation?')">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="none"
                                     xmlns="http://www.w3.org/2000/svg">
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

