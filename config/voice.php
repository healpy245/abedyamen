<?php

return [

    /*
  |--------------------------------------------------------------------------
  | Voice Provider
  |--------------------------------------------------------------------------
  |
  | Supported: fake, telnyx
  |
  */

    'provider' => env('VOICE_PROVIDER', 'fake'),

    'default_chatbot_instance_id' => env('VOICE_DEFAULT_CHATBOT_INSTANCE_ID'),

    /*
  |--------------------------------------------------------------------------
  | Browser simulator voice profiles (Web Speech API pitch/rate hints)
  |--------------------------------------------------------------------------
  */
    'profiles' => [
        'woman' => ['pitch' => 1.1, 'rate' => 1.08, 'prefer' => ['female', 'woman', 'zira', 'hoda', 'samantha', 'zariyah', 'salma']],
        'man' => ['pitch' => 0.85, 'rate' => 0.98, 'prefer' => ['male', 'man', 'david', 'guy', 'daniel', 'hamed', 'shakir']],
        'girl' => ['pitch' => 1.28, 'rate' => 1.12, 'prefer' => ['female', 'girl', 'child', 'salma', 'zariyah']],
        'boy' => ['pitch' => 0.95, 'rate' => 1.05, 'prefer' => ['male', 'boy', 'child', 'shakir', 'hamed']],
    ],

    'phone' => [
        'silence_ms' => (int) env('VOICE_PHONE_SILENCE_MS', 500),
        'max_tokens' => (int) env('VOICE_PHONE_MAX_TOKENS', 70),
        'temperature' => (float) env('VOICE_PHONE_TEMPERATURE', 0.35),
        // Skip a second OpenAI call after Malan lookup when a short spoken reply can be composed.
        'fast_tool_replies' => filter_var(env('VOICE_PHONE_FAST_TOOL_REPLIES', true), FILTER_VALIDATE_BOOLEAN),
        // Keep only recent turns in voice mode to shrink prompt latency.
        'history_limit' => (int) env('VOICE_PHONE_HISTORY_LIMIT', 6),
        // Optional faster model for phone turns only (falls back to chatbot_model).
        'model' => env('VOICE_PHONE_MODEL', 'gpt-4o-mini'),
        // Voice-call SSE streaming (OpenAI stream → phrase TTS → browser queue).
        'streaming_enabled' => filter_var(env('VOICE_STREAMING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'latency' => [
        'log' => filter_var(env('VOICE_LATENCY_LOG', true), FILTER_VALIDATE_BOOLEAN),
        'overlay' => filter_var(env('VOICE_LATENCY_OVERLAY', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
  |--------------------------------------------------------------------------
  | OpenAI Realtime (WebRTC speech-to-speech)
  |--------------------------------------------------------------------------
  */
    'realtime' => [
        'enabled' => filter_var(env('VOICE_REALTIME_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'model' => env('OPENAI_REALTIME_MODEL', 'gpt-realtime'),
        'voice' => env('OPENAI_REALTIME_VOICE', 'marin'),
        'default_voice' => env('OPENAI_REALTIME_DEFAULT_VOICE', 'marin'),
        'allowed_voices' => [
            'alloy',
            'ash',
            'ballad',
            'coral',
            'echo',
            'sage',
            'shimmer',
            'verse',
            'marin',
            'cedar',
        ],
        'transcription_model' => env('OPENAI_REALTIME_TRANSCRIPTION_MODEL', 'whisper-1'),
        'timeout' => (int) env('VOICE_REALTIME_TIMEOUT', 30),
        'vad' => [
            'type' => env('VOICE_REALTIME_VAD_TYPE', 'server_vad'),
            'threshold' => (float) env('VOICE_REALTIME_VAD_THRESHOLD', 0.5),
            'prefix_padding_ms' => (int) env('VOICE_REALTIME_VAD_PREFIX_MS', 400),
            'silence_duration_ms' => (int) env('VOICE_REALTIME_VAD_SILENCE_MS', 750),
            'eagerness' => env('VOICE_REALTIME_VAD_EAGERNESS', 'auto'),
            'create_response' => filter_var(env('VOICE_REALTIME_VAD_CREATE_RESPONSE', true), FILTER_VALIDATE_BOOLEAN),
            'interrupt_response' => filter_var(env('VOICE_REALTIME_VAD_INTERRUPT_RESPONSE', true), FILTER_VALIDATE_BOOLEAN),
        ],
        'opening_greeting' => [
            'ar' => 'مرحبا، معك سالي مندوبة الذكاء الاصطناعي لشركة ملان إنترنت، كيف بقدر أساعدك؟',
            'en' => 'Hello, this is Sally, your AI assistant. How can I help you today?',
            'he' => 'שלום, כאן סאלי מלווה ה-AI. איך אוכל לעזור?',
        ],
        'transport' => env('VOICE_REALTIME_TRANSPORT', 'guzzle'),
        'diagnostics' => [
            'enabled' => filter_var(env('VOICE_REALTIME_DIAGNOSE', false), FILTER_VALIDATE_BOOLEAN),
            'ab_curl' => filter_var(env('VOICE_REALTIME_AB_CURL', false), FILTER_VALIDATE_BOOLEAN),
        ],
    ],

    /*
  |--------------------------------------------------------------------------
  | Text-to-speech (server-side neural voices for Arabic / Hebrew / English)
  |--------------------------------------------------------------------------
  */
        'tts' => [
            'provider' => env('VOICE_TTS_PROVIDER', 'auto'),
            'fallback_language' => 'en',
            'timeout' => (int) env('VOICE_TTS_TIMEOUT', 30),
            // Vocalize Arabic (tashkeel) before TTS. Off by default — adds latency and fights dialect speech.
            'arabic_diacritize' => filter_var(env('VOICE_TTS_ARABIC_DIACRITIZE', false), FILTER_VALIDATE_BOOLEAN),
            'diacritize_model' => env('VOICE_TTS_DIACRITIZE_MODEL', 'gpt-4o-mini'),
            'diacritize_timeout' => (int) env('VOICE_TTS_DIACRITIZE_TIMEOUT', 8),

        'edge' => [
            'voices' => [
                'ar' => [
                    // Jordanian female — closest free Levantine neural voice to Palestinian.
                    'woman' => 'ar-JO-SanaNeural',
                    'man' => 'ar-JO-TaimNeural',
                    'girl' => 'ar-JO-SanaNeural',
                    'boy' => 'ar-JO-TaimNeural',
                ],
                'en' => [
                    'woman' => 'en-US-JennyNeural',
                    'man' => 'en-US-GuyNeural',
                    'girl' => 'en-US-AriaNeural',
                    'boy' => 'en-US-DavisNeural',
                ],
                'he' => [
                    'woman' => 'he-IL-HilaNeural',
                    'man' => 'he-IL-AvriNeural',
                    'girl' => 'he-IL-HilaNeural',
                    'boy' => 'he-IL-AvriNeural',
                ],
            ],
        ],

        'openai' => [
            'model' => env('VOICE_TTS_OPENAI_MODEL', 'gpt-4o-mini-tts'),
            'speed' => (float) env('VOICE_TTS_OPENAI_SPEED', 0.95),
            'instructions' => [
                'ar' => 'You are Sally, a young Palestinian woman working customer care for an internet ISP. '
                    .'Speak ONLY in natural colloquial Palestinian Levantine Arabic (لهجة فلسطينية عامية شامية), '
                    .'NOT Modern Standard Arabic, NOT Egyptian, NOT Gulf. '
                    .'Clear native phone-call pronunciation, warm and confident, moderate pace, no mumbling, no robotic cadence. '
                    .'Sound like a real local agent from Palestine.',
                'en' => 'Speak in warm, natural, professional customer-service English. Sound friendly, clear, and human — not robotic.',
                'he' => 'דבר בעברית ברורה, חמה ומקצועית כנציג שירות לקוחות. קצב דיבור טבעי ולא רובוטי.',
            ],
            'voices' => [
                'ar' => [
                    'woman' => env('VOICE_TTS_OPENAI_VOICE_AR_WOMAN', 'coral'),
                    'man' => 'onyx',
                    'girl' => 'shimmer',
                    'boy' => 'echo',
                ],
                'en' => [
                    'woman' => 'coral',
                    'man' => 'onyx',
                    'girl' => 'shimmer',
                    'boy' => 'echo',
                ],
                'he' => [
                    'woman' => 'coral',
                    'man' => 'onyx',
                    'girl' => 'shimmer',
                    'boy' => 'echo',
                ],
            ],
        ],

        'telnyx' => [
            // Reuses TELNYX_API_KEY from voice.providers.telnyx unless overridden.
            'api_key' => env('TELNYX_TTS_API_KEY', env('TELNYX_API_KEY')),
            'base_url' => env('TELNYX_TTS_BASE_URL', 'https://api.telnyx.com/v2'),
            // Bayan currently returns WAV for binary_output regardless of format request.
            'response_format' => env('TELNYX_TTS_FORMAT', 'wav'),
            // Bayan only supports 16000 Hz.
            'sample_rate' => (int) env('TELNYX_TTS_SAMPLE_RATE', 16000),
            // Dialect hint; leave empty to use the speaker's native dialect (Yara).
            'language_ar' => env('TELNYX_TTS_LANGUAGE_AR', ''),
            'voices' => [
                'ar' => [
                    'woman' => env('TELNYX_TTS_VOICE_AR_WOMAN', 'Telnyx.Bayan.Yara'),
                    'man' => env('TELNYX_TTS_VOICE_AR_MAN', 'Telnyx.Bayan.Ahmed'),
                    'girl' => env('TELNYX_TTS_VOICE_AR_GIRL', env('TELNYX_TTS_VOICE_AR_WOMAN', 'Telnyx.Bayan.Yara')),
                    'boy' => env('TELNYX_TTS_VOICE_AR_BOY', env('TELNYX_TTS_VOICE_AR_MAN', 'Telnyx.Bayan.Ahmed')),
                ],
                'en' => [
                    'woman' => env('TELNYX_TTS_VOICE_EN_WOMAN', env('TELNYX_TTS_VOICE_AR_WOMAN', 'Telnyx.Bayan.Yara')),
                    'man' => env('TELNYX_TTS_VOICE_EN_MAN', env('TELNYX_TTS_VOICE_AR_MAN', 'Telnyx.Bayan.Ahmed')),
                    'girl' => env('TELNYX_TTS_VOICE_EN_GIRL', env('TELNYX_TTS_VOICE_AR_WOMAN', 'Telnyx.Bayan.Yara')),
                    'boy' => env('TELNYX_TTS_VOICE_EN_BOY', env('TELNYX_TTS_VOICE_AR_MAN', 'Telnyx.Bayan.Ahmed')),
                ],
                'he' => [
                    'woman' => env('TELNYX_TTS_VOICE_HE_WOMAN', env('TELNYX_TTS_VOICE_AR_WOMAN', 'Telnyx.Bayan.Yara')),
                    'man' => env('TELNYX_TTS_VOICE_HE_MAN', env('TELNYX_TTS_VOICE_AR_MAN', 'Telnyx.Bayan.Ahmed')),
                    'girl' => env('TELNYX_TTS_VOICE_HE_GIRL', env('TELNYX_TTS_VOICE_AR_WOMAN', 'Telnyx.Bayan.Yara')),
                    'boy' => env('TELNYX_TTS_VOICE_HE_BOY', env('TELNYX_TTS_VOICE_AR_MAN', 'Telnyx.Bayan.Ahmed')),
                ],
            ],
        ],

        'elevenlabs' => [
            'api_key' => env('ELEVENLABS_API_KEY'),
            'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
            // Multilingual v2 = best Arabic quality available for API.
            'model' => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),
            // Clearer, less mumbled Arabic: higher stability + slower pace + less latency optimization.
            'stability' => (float) env('ELEVENLABS_STABILITY', 0.68),
            'similarity_boost' => (float) env('ELEVENLABS_SIMILARITY', 0.85),
            'style' => (float) env('ELEVENLABS_STYLE', 0.0),
            'speed' => (float) env('ELEVENLABS_SPEED', 0.92),
            'use_speaker_boost' => filter_var(env('ELEVENLABS_SPEAKER_BOOST', true), FILTER_VALIDATE_BOOLEAN),
            // 0 = best quality; higher values sound more compressed/mumbled.
            'optimize_streaming_latency' => (int) env('ELEVENLABS_OPTIMIZE_LATENCY', 0),
            'voices' => [
                // Lily (premade) tends to be clearer for Arabic than Sarah on free API.
                // Rafoush (library) needs a paid ElevenLabs plan + voice id.
                'ar' => [
                    'woman' => env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku'),
                    'man' => env('ELEVENLABS_VOICE_AR_MAN', env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku')),
                    'girl' => env('ELEVENLABS_VOICE_AR_GIRL', env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku')),
                    'boy' => env('ELEVENLABS_VOICE_AR_BOY', env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku')),
                ],
                'en' => [
                    'woman' => env('ELEVENLABS_VOICE_EN_WOMAN', env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku')),
                    'man' => env('ELEVENLABS_VOICE_EN_MAN', env('ELEVENLABS_VOICE_AR_MAN', env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku'))),
                    'girl' => env('ELEVENLABS_VOICE_EN_GIRL', env('ELEVENLABS_VOICE_AR_GIRL', env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku'))),
                    'boy' => env('ELEVENLABS_VOICE_EN_BOY', env('ELEVENLABS_VOICE_AR_BOY', env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku'))),
                ],
                'he' => [
                    'woman' => env('ELEVENLABS_VOICE_HE_WOMAN', env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku')),
                    'man' => env('ELEVENLABS_VOICE_HE_MAN', env('ELEVENLABS_VOICE_AR_MAN', env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku'))),
                    'girl' => env('ELEVENLABS_VOICE_HE_GIRL', env('ELEVENLABS_VOICE_AR_GIRL', env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku'))),
                    'boy' => env('ELEVENLABS_VOICE_HE_BOY', env('ELEVENLABS_VOICE_AR_BOY', env('ELEVENLABS_VOICE_AR_WOMAN', 'pFZP5JQG7iQjIQuC4Bku'))),
                ],
            ],
        ],
    ],

    'providers' => [

        'fake' => [
            'simulated_answer_delay_ms' => 0,
        ],

        'telnyx' => [
            'api_key' => env('TELNYX_API_KEY'),
            'public_key' => env('TELNYX_PUBLIC_KEY'),
            'connection_id' => env('TELNYX_CONNECTION_ID'),
            'phone_number' => env('TELNYX_PHONE_NUMBER'),
            'webhook_verify' => env('TELNYX_WEBHOOK_VERIFY', true),
            'webhook_verify_bypass' => env('TELNYX_WEBHOOK_VERIFY_BYPASS', false),
            'api_base_url' => 'https://api.telnyx.com/v2',
        ],

    ],

];
