@php
    /** @var \Illuminate\Support\Collection|\App\Models\AiChatbot\ChatbotConversation[] $conversations */
@endphp

@include('ai-chatbot.chat', [
    'instance' => $instance,
    'instances' => $instances,
    'conversations' => $conversations,
    'activeConversation' => $activeConversation,
    'messages' => $messages,
])

