@php
    /** @var \App\Models\AiChatbot\ChatbotMessage $message */
    $text = (string) $message->message;
    $isUser = $message->role === 'user';
    $hasArabic = (bool) preg_match('/\p{Arabic}/u', $text);
    $dir = $hasArabic ? 'rtl' : 'ltr';
    $align = $hasArabic ? 'text-right' : 'text-left';
@endphp

<div class="flex items-start gap-2 mb-3 {{ $isUser ? 'justify-end' : '' }}">
    @unless($isUser)
        <div class="mt-0.5 h-7 w-7 rounded-full bg-emerald-500/20 border border-emerald-400/60 flex items-center justify-center text-[11px] font-semibold text-emerald-300">
            AI
        </div>
    @endunless

    <div dir="{{ $dir }}"
         class="max-w-[80%] px-3 py-2.5 rounded-2xl text-sm leading-relaxed whitespace-pre-wrap {{ $align }}
             {{ $isUser ? 'bg-emerald-500 text-slate-950 rounded-br-sm shadow-md' : 'bg-slate-900/80 border border-slate-700/80 rounded-bl-sm text-slate-50' }}">
        {{ $text }}
    </div>

    @if($isUser)
        <div class="mt-0.5 h-7 w-7 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center text-[11px] font-semibold text-slate-200">
            {{ mb_strtoupper(mb_substr(auth()->user()?->name ?? 'U', 0, 1)) }}
        </div>
    @endif
</div>

