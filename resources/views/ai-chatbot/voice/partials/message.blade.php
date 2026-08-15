@php
    /** @var \App\Models\Voice\VoiceCallMessage $message */
    $text = (string) $message->content;
    $role = $message->role instanceof \App\Enums\Voice\VoiceCallMessageRole
        ? $message->role->value
        : (string) $message->role;
    $isCaller = $role === 'caller';
    $hasArabic = (bool) preg_match('/\p{Arabic}/u', $text);
    $dir = $hasArabic ? 'rtl' : 'ltr';
    $align = $hasArabic ? 'text-right' : 'text-left';
@endphp

<div class="flex items-start gap-2 mb-3 {{ $isCaller ? 'justify-end' : '' }}">
    @unless($isCaller)
        <div class="mt-0.5 h-7 w-7 shrink-0 rounded-full bg-[#f47a2e]/12 border border-[#f47a2e]/30 flex items-center justify-center text-[11px] font-bold text-[#f16229]">
            {{ __('chatbot.ai_label') }}
        </div>
    @endunless

    <div dir="{{ $dir }}"
         class="max-w-[80%] px-4 py-2.5 rounded-2xl text-sm leading-relaxed whitespace-pre-wrap {{ $align }}
             {{ $isCaller
                 ? 'bg-gradient-to-br from-[#f59f43] to-[#f47a2e] text-white rounded-br-sm shadow-md'
                 : ($role === 'system'
                     ? 'bg-[#fff7ed] border border-dashed border-[#f1dfc5] text-[#a78a6c] italic'
                     : 'bg-white border border-[#f1dfc5] rounded-bl-sm text-[#2b1e11]') }}">
        {{ $text }}
    </div>

    @if($isCaller)
        <div class="mt-0.5 h-7 w-7 shrink-0 rounded-full bg-[#2b1e11]/8 border border-[#2b1e11]/15 flex items-center justify-center text-[11px] font-bold text-[#2b1e11]">
            {{ mb_strtoupper(mb_substr(auth()->user()?->name ?? 'C', 0, 1)) }}
        </div>
    @endif
</div>
