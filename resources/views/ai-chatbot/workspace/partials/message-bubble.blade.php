@php
    $isCustomer = $message->role === 'user';
    $dir = preg_match('/[\x{0600}-\x{06FF}\x{0590}-\x{05FF}]/u', (string) $message->message) ? 'rtl' : 'ltr';
    $attachmentUrl = $message->hasAttachment()
        ? route('ai-chatbot.instances.messages.attachment', ['instance' => $instance, 'message' => $message])
        : null;
@endphp
<div class="flex {{ $isCustomer ? 'justify-start' : 'justify-end' }} message-bubble" data-id="{{ $message->id }}">
    <div class="max-w-[85%] sm:max-w-[70%] rounded-2xl px-3.5 py-2.5 shadow-sm
                {{ $isCustomer ? 'bg-white text-[#2b1e11] rounded-ss-md' : 'bg-[#f47a2e] text-white rounded-se-md' }}">
        @if($message->staffSourceLabel() && ! $isCustomer)
            <span class="inline-block mb-1 text-[10px] font-semibold uppercase tracking-wide {{ $isCustomer ? 'text-[#a78a6c]' : 'text-white/80' }}">
                {{ $message->staffSourceLabel() }}
            </span>
        @endif

        @if($attachmentUrl && $message->isImageAttachment())
            <a href="{{ $attachmentUrl }}" target="_blank" rel="noopener" class="block mb-2">
                <img src="{{ $attachmentUrl }}" alt="" class="max-h-56 rounded-lg object-cover">
            </a>
        @elseif($attachmentUrl && $message->isAudioAttachment())
            <div class="mb-2 w-full min-w-[16rem] sm:min-w-[18rem] max-w-md rounded-xl px-2.5 py-2 {{ $isCustomer ? 'bg-[#f7efe3]' : 'bg-white/15' }}">
                <audio controls preload="metadata" controlslist="nodownload"
                       class="wa-audio-player block w-full"
                       src="{{ $attachmentUrl }}">
                    <a href="{{ $attachmentUrl }}" target="_blank" rel="noopener">{{ __('chatbot.workspace.audio_attachment') }}</a>
                </audio>
            </div>
        @elseif($attachmentUrl && $message->isPdfAttachment())
            <a href="{{ $attachmentUrl }}" target="_blank" rel="noopener"
               class="mb-2 flex items-center gap-2 rounded-lg px-3 py-2 text-sm {{ $isCustomer ? 'bg-[#f7efe3]' : 'bg-white/15' }}">
                <span aria-hidden="true">📄</span>
                <span>{{ __('chatbot.workspace.pdf_attachment') }}</span>
            </a>
        @elseif($attachmentUrl)
            <a href="{{ $attachmentUrl }}" target="_blank" rel="noopener"
               class="mb-2 inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm {{ $isCustomer ? 'bg-[#f7efe3]' : 'bg-white/15' }}">
                {{ __('chatbot.attachment_file') }}
            </a>
        @endif

        @if(trim((string) $message->message) !== '')
            <div class="text-sm leading-relaxed whitespace-pre-wrap break-words" dir="{{ $dir }}">{{ $message->message }}</div>
        @endif

        <div class="mt-1 text-[10px] {{ $isCustomer ? 'text-[#a78a6c]' : 'text-white/70' }} text-end" dir="ltr">
            {{ optional($message->created_at)->timezone(config('app.timezone'))->format('H:i') }}
        </div>
    </div>
</div>
