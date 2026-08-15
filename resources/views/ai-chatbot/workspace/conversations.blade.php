@php
    $activeProject = 'ai-chatbot';
@endphp
@extends('layouts.kaman')

@section('title', __('chatbot.workspace.conversations_title').' — '.$instance->name)
@section('tag', __('chatbot.workspace.tag'))

@section('content')
<div class="flex flex-col flex-1 min-h-0 w-full max-w-[1400px] mx-auto px-3 sm:px-4 pb-3"
     id="workspace-list"
     data-csrf="{{ csrf_token() }}">

    @include('ai-chatbot.workspace.partials.nav')

    <div class="kaman-card overflow-hidden flex flex-col flex-1 min-h-[70vh] md:min-h-[75vh]">
        <div class="grid grid-cols-1 md:grid-cols-[320px_1fr] flex-1 min-h-0">
            @include('ai-chatbot.workspace.partials.conversation-list-pane', [
                'activeId' => null,
                'listPaneClass' => '',
            ])

            {{-- Empty detail on list page --}}
            <section class="hidden md:flex flex-col items-center justify-center p-8 text-center bg-[#f7efe3]/40">
                <div class="w-16 h-16 rounded-full bg-[#f1dfc5]/80 flex items-center justify-center mb-4 text-2xl text-[#a78a6c]" aria-hidden="true">💬</div>
                <p class="text-sm font-medium text-[#2b1e11]">{{ __('chatbot.workspace.select_conversation') }}</p>
                <p class="mt-1 text-xs text-[#a78a6c] max-w-xs">{{ __('chatbot.workspace.select_conversation_hint') }}</p>
            </section>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/workspace-chat.js') }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (window.WorkspaceChat) {
        window.WorkspaceChat.startListPolling(document.getElementById('workspace-list-pane'));
    }
});
</script>
@endpush
