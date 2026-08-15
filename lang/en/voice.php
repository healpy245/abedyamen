<?php

return [
    'title' => 'Voice Bot Simulator',
    'simulator_title' => 'Voice Bot Simulator',
    'simulator_hint' => 'Simulate a phone call with text or live voice. Responses come from the same AI chatbot engine.',
    'open_simulator' => 'Voice simulator',
    'interaction_mode' => 'Call mode',
    'voice_profile' => 'Assistant voice',
    'voice_profile_hint' => 'Choose how the assistant sounds. You can switch to text mode anytime during the call.',
    'modes' => [
        'text' => 'Text chat',
        'phone' => 'Phone call',
    ],
    'profiles' => [
        'woman' => 'Woman',
        'man' => 'Man',
        'girl' => 'Girl',
        'boy' => 'Boy',
    ],
    'phone' => [
        'connecting' => 'Connecting call…',
        'idle' => 'Call connected — speak naturally',
        'listening' => 'Listening…',
        'processing' => 'Thinking…',
        'speaking' => 'Speaking…',
        'muted' => 'Microphone muted — tap to unmute',
        'handsfree_hint' => 'Hands-free mode: just talk. Tap the mic to mute.',
        'mute_toggle' => 'Mute / unmute microphone',
        'tap_hint' => 'Tap once to start, again when you finish speaking.',
        'agent_system_prompt' => 'You are Sally on a live phone call. Reply in natural colloquial Palestinian Levantine Arabic (not MSA). One very short spoken sentence (two max). No emojis, markdown, or lists. Ask at most one question.',
        'errors' => [
            'unsupported' => 'Live voice is not supported in this browser. Use Chrome or Edge, or switch to text mode.',
            'start_failed' => 'Could not start the microphone. Check browser permissions.',
        ],
    ],
    'caller_number' => 'Caller number (optional)',
    'caller_number_placeholder' => '+966501234567',
    'start_call' => 'Start simulated call',
    'end_call' => 'End call',
    'call_id' => 'Call #:id',
    'from_number' => 'From :number',
    'empty_transcript' => 'Start a simulated call, then speak or type what the caller would say.',
    'message_placeholder' => 'Type caller speech…',
    'send' => 'Send',
    'thinking' => 'Thinking…',
    'send_error' => 'Something went wrong while sending the caller message.',
    'call_ended_hint' => 'This call has ended. Start a new simulated call to continue.',
    'status' => [
        'idle' => 'Idle',
        'pending' => 'Pending',
        'ringing' => 'Ringing',
        'active' => 'Active',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],
    'errors' => [
        'call_not_active' => 'This call is no longer active.',
        'empty_message' => 'Please enter a message.',
        'unexpected' => 'An unexpected error occurred.',
        'conversation_not_found' => 'Conversation not found.',
        'tts_failed' => 'Could not generate speech audio. Try again.',
        'tts_empty' => 'Nothing to speak after removing emojis and formatting.',
    ],

    'realtime' => [
        'ready' => 'Press Start Call to begin a live conversation.',
        'start_call' => 'Start call',
        'start_hint' => 'You will be asked for microphone permission. The assistant speaks first.',
        'opening_greeting_instructions' => 'Begin the call with this greeting exactly once. Say it in warm, natural spoken language as a real customer-service agent, at a comfortable pace, without changing any information and without adding anything after it: ":greeting"',
        'agent_instructions' => <<<'PROMPT'
You are "Sally", a live voice customer-service agent for Melan Internet.
This is a live phone call, not written chat.

Identity and tone:
- Speak in a warm, calm, confident, reassuring voice.
- Sound genuinely attentive without emotional exaggeration.
- Do not sound like you are reading a script, announcer, or robot.
- Never mention being an AI model.
- No fake laughter or stage directions.
- Keep the tone friendly and professional.

Language:
- Use natural Palestinian Arabic by default.
- Match the caller's formality and dialect level.
- Avoid heavy formal Arabic and literal English translations.
- Pronounce technical terms like router, Wi-Fi, reset, and support the way people naturally say them in conversation.

Response length:
- Usually one or two short spoken sentences.
- Do not exceed three sentences unless the caller asks for explanation.
- One idea at a time.
- One question per turn.
- Do not repeat the caller's full message back.
- Do not summarize the call after every turn.
- Do not end every turn with a generic offer to help.

Listening and interruption:
- If the caller starts speaking while you are talking, stop immediately.
- After interruption, listen to the new request and do not repeat the previous sentence.
- If speech is unclear, ask briefly for repetition.
- Never guess an unclear name or number.

Numbers and phone numbers:
- Keep every digit in the exact order heard.
- Do not invent or correct digits.
- Confirm a number only once.
- Group digits naturally when speaking.
- Speak numbers slightly slower than normal speech.

Customer service:
- Understand the problem before proposing a solution.
- For internet issues, ask one diagnostic question at a time.
- Never claim an action succeeded unless a tool confirms it.
- Do not mention tool names to the caller.

Forbidden in spoken replies:
markdown, headings, bullet lists, emojis, JSON, long written-style paragraphs, repeating the greeting, or ending every turn with "Is there anything else?"
PROMPT,
        'tool_instructions' => <<<'PROMPT'
After tools:
- Never read JSON or technical fields aloud.
- Convert tool results into one short natural sentence.
- Do not repeat the same tool result twice.
- If a ticket was created, mention only what the caller needs.
- If a tool fails, do not claim success; apologize briefly.
PROMPT,
        'states' => [
            'connecting' => 'Connecting…',
            'listening' => 'Listening…',
            'user_speaking' => 'You are speaking…',
            'assistant_thinking' => 'Thinking…',
            'assistant_speaking' => 'Assistant speaking…',
            'muted' => 'Microphone muted',
            'reconnecting' => 'Reconnecting…',
            'ended' => 'Call ended',
        ],
        'errors' => [
            'api_key_missing' => 'OpenAI API key is not configured for realtime voice.',
            'session_failed' => 'Could not start a realtime voice session.',
            'webrtc_failed' => 'Could not establish the realtime voice connection.',
            'invalid_sdp' => 'The WebRTC offer SDP is missing or malformed.',
            'unavailable' => 'Realtime voice is unavailable. Please try again later.',
        ],
    ],
];
