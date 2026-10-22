{{-- Triggered chats on one WhatsApp line, and how close it is to a restriction. --}}
<span class="farz-line-meta" @if(($stat['id'] ?? '') !== '') data-line-stat="{{ $stat['id'] }}" @endif>
    <b data-triggered>{{ (int) ($stat['triggered'] ?? 0) }}</b>
    <span>محادثة</span>
    <span data-today>اليوم {{ (int) ($stat['today'] ?? 0) }}/{{ (int) ($stat['cap'] ?? \App\Services\AiChatbot\MansourLines::DAY_CAP) }}</span>
    <i class="farz-risk is-{{ $stat['level'] ?? 'low' }}" data-risk title="{{ $stat['level_label'] ?? 'منخفض' }}">{{ (int) ($stat['percent'] ?? 0) }}%</i>
</span>
