@php
    /** @var \App\Models\AiChatbot\ChatbotMessage $message */
    /** @var \App\Models\AiChatbot\ChatbotInstance|null $instance */
    $text = (string) $message->message;
    $isUser = $message->role === 'user';
    $hasArabic = (bool) preg_match('/\p{Arabic}/u', $text);
    $dir = $hasArabic ? 'rtl' : 'ltr';
    $align = $hasArabic ? 'text-right' : 'text-left';
    $attachmentUrl = null;
    if ($message->hasAttachment() && isset($instance)) {
        $attachmentUrl = route('ai-chatbot.instances.messages.attachment', [
            'instance' => $instance,
            'message' => $message,
        ]);
    }
@endphp

<div class="flex items-start gap-2 mb-3 {{ $isUser ? 'justify-end' : '' }}">
    @unless($isUser)
        <div class="mt-0.5 h-7 w-7 shrink-0 rounded-full bg-[#f47a2e]/12 border border-[#f47a2e]/30 flex items-center justify-center text-[11px] font-bold text-[#f16229]">
            {{ __('chatbot.ai_label') }}
        </div>
    @endunless

    <div dir="{{ $dir }}"
         class="max-w-[80%] px-4 py-2.5 rounded-2xl text-sm leading-relaxed {{ $align }}
             {{ $isUser
                 ? 'bg-gradient-to-br from-[#f59f43] to-[#f47a2e] text-white rounded-br-sm shadow-md'
                 : 'bg-white border border-[#f1dfc5] rounded-bl-sm text-[#2b1e11]' }}">
        @if($attachmentUrl && $message->isImageAttachment())
            <a href="{{ $attachmentUrl }}" target="_blank" rel="noopener noreferrer" class="mb-2 block overflow-hidden rounded-xl {{ $isUser ? 'ring-1 ring-white/30' : 'ring-1 ring-[#f1dfc5]' }}">
                <img src="{{ $attachmentUrl }}"
                     alt="{{ __('chatbot.attachment_image_alt') }}"
                     class="max-h-56 w-full object-cover"
                     loading="lazy">
            </a>
        @elseif($attachmentUrl && $message->isAudioAttachment())
            <div class="mb-2 w-full min-w-[16rem] max-w-md rounded-xl px-2.5 py-2 {{ $isUser ? 'bg-white/15' : 'bg-[#fff6ea]' }}">
                <audio controls preload="metadata" controlslist="nodownload"
                       class="wa-audio-player block w-full"
                       src="{{ $attachmentUrl }}">
                    <a href="{{ $attachmentUrl }}" target="_blank" rel="noopener noreferrer">{{ __('chatbot.attachment_file') }}</a>
                </audio>
            </div>
        @elseif($attachmentUrl)
            <a href="{{ $attachmentUrl }}"
               target="_blank"
               rel="noopener noreferrer"
               class="mb-2 inline-flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium {{ $isUser ? 'bg-white/15 text-white' : 'bg-[#fff6ea] text-[#f16229]' }}">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M6 3H11L15 7V17C15 17.5523 14.5523 18 14 18H6C5.44772 18 5 17.5523 5 17V4C5 3.44772 5.44772 3 6 3Z" stroke="currentColor" stroke-width="1.4"/>
                    <path d="M11 3V7H15" stroke="currentColor" stroke-width="1.4"/>
                </svg>
                {{ __('chatbot.attachment_file') }}
            </a>
        @endif

        @if(trim($text) !== '')
            <div class="whitespace-pre-wrap">{{ $text }}</div>
        @endif
    </div>

    @if($isUser)
        <div class="mt-0.5 h-7 w-7 shrink-0 rounded-full bg-[#2b1e11]/8 border border-[#2b1e11]/15 flex items-center justify-center text-[11px] font-bold text-[#2b1e11]">
            {{ mb_strtoupper(mb_substr(auth()->user()?->name ?? 'U', 0, 1)) }}
        </div>
    @endif
</div>
