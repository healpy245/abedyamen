@php
    /** @var \Illuminate\Support\Collection|\App\Models\AiChatbot\ChatbotConversation[] $conversations */
@endphp

@include('ai-chatbot.chat', [
    'conversations' => $conversations,
    'activeConversation' => $activeConversation,
    'messages' => $messages,
])

