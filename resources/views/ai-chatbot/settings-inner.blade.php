@php
    /** @var array<string,mixed> $settings */
@endphp

<div class="flex-1 flex flex-col max-w-4xl mx-auto w-full px-4 py-6 text-slate-50">
    <div class="mb-6 flex items-center justify-between gap-3">
        <div>
            <div class="text-xs uppercase tracking-[0.16em] text-emerald-400 font-semibold mb-1">
                AI Chatbot Studio
            </div>
            <h1 class="text-lg font-semibold">
                Settings
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Control the default behavior and safety of the assistant.
            </p>
        </div>
        <a href="{{ route('ai-chatbot.index') }}"
           class="inline-flex items-center gap-1 rounded-full border border-slate-700 bg-slate-900/70 px-3 py-1.5 text-xs text-slate-200 hover:border-emerald-500 hover:text-emerald-300 transition">
            Back to chat
        </a>
    </div>

    @if(session('status'))
        <div class="mb-4 rounded-lg border border-emerald-600/60 bg-emerald-900/40 px-3 py-2 text-xs text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <form action="{{ route('ai-chatbot.admin.settings.update') }}" method="post" class="space-y-5">
        @csrf

        <input type="hidden" name="action" id="aiChatbotSettingsAction" value="save">

        <div class="space-y-1.5">
            <label for="system_prompt" class="block text-xs font-medium text-slate-200">
                System Prompt
            </label>
            <p class="text-[11px] text-slate-500 mb-1">
                Defines the assistant’s identity and tone. This is always sent before user messages.
            </p>
            <textarea
                id="system_prompt"
                name="system_prompt"
                rows="5"
                class="w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 py-2 text-xs text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
            >{{ old('system_prompt', $settings['system_prompt'] ?? '') }}</textarea>
            @error('system_prompt')
            <p class="text-[11px] text-rose-400 mt-0.5">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="space-y-1.5">
                <label for="chatbot_model" class="block text-xs font-medium text-slate-200">
                    Model
                </label>
                <input
                    id="chatbot_model"
                    name="chatbot_model"
                    type="text"
                    value="{{ old('chatbot_model', $settings['chatbot_model'] ?? 'gpt-4o-mini') }}"
                    class="w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 py-2 text-xs text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                >
                @error('chatbot_model')
                <p class="text-[11px] text-rose-400 mt-0.5">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-1.5">
                <label for="temperature" class="block text-xs font-medium text-slate-200">
                    Temperature
                </label>
                <input
                    id="temperature"
                    name="temperature"
                    type="number"
                    step="0.1"
                    min="0"
                    max="2"
                    value="{{ old('temperature', $settings['temperature'] ?? 0.7) }}"
                    class="w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 py-2 text-xs text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                >
                @error('temperature')
                <p class="text-[11px] text-rose-400 mt-0.5">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-1.5">
                <label for="max_tokens" class="block text-xs font-medium text-slate-200">
                    Max Tokens
                </label>
                <input
                    id="max_tokens"
                    name="max_tokens"
                    type="number"
                    min="1"
                    max="8000"
                    value="{{ old('max_tokens', $settings['max_tokens'] ?? 2000) }}"
                    class="w-full rounded-lg border border-slate-700 bg-slate-950/70 px-3 py-2 text-xs text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                >
                @error('max_tokens')
                <p class="text-[11px] text-rose-400 mt-0.5">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 pt-3 border-t border-slate-800 mt-2">
            <p class="text-[11px] text-slate-500">
                Changes apply to new requests immediately. Existing conversations keep their history.
            </p>
            <div class="flex items-center gap-2">
                <button type="button"
                        onclick="if(confirm('Reset all AI Chatbot settings to defaults?')) { document.getElementById('aiChatbotSettingsAction').value = 'reset'; this.form.submit(); }"
                        class="inline-flex items-center justify-center rounded-lg border border-slate-700 bg-slate-950/70 px-3 py-1.5 text-xs text-slate-200 hover:border-rose-500 hover:text-rose-300 transition">
                    Reset to defaults
                </button>
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-emerald-500 px-4 py-1.5 text-xs font-medium text-slate-950 hover:bg-emerald-400 transition">
                    Save changes
                </button>
            </div>
        </div>
    </form>
</div>

