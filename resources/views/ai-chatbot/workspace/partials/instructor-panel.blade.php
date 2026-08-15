@php $collapsible = $collapsible ?? false; @endphp
<div class="instructor-panel-inner flex flex-col h-full min-h-0 p-3">
    <div class="flex items-start justify-between gap-2 mb-1">
        <h3 class="text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.instructor') }}</h3>
        @if($collapsible)
            <button type="button"
                    id="btn-instructor-collapse"
                    class="kaman-button-ghost !px-2 !py-1 shrink-0"
                    aria-controls="instructor-panel"
                    aria-expanded="true"
                    title="{{ __('chatbot.workspace.instructor_collapse') }}">
                <span class="sr-only">{{ __('chatbot.workspace.instructor_collapse') }}</span>
                <svg class="w-4 h-4 text-[#7c6a56]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd"/>
                </svg>
            </button>
        @endif
    </div>
    <p class="text-[11px] text-[#a78a6c] mb-3">{{ __('chatbot.workspace.instructor_hint') }}</p>

    @if($canManageInstructions)
        <form class="instruction-form space-y-2 mb-4" data-store-url="{{ route('ai-chatbot.workspace.conversations.instructions.store', [$instance, $conversation]) }}">
            <label class="kaman-label block text-xs" for="instruction-text">{{ __('chatbot.workspace.instruction_label') }}</label>
            <textarea id="instruction-text" name="instruction" rows="4" required maxlength="5000"
                      class="kaman-input w-full text-sm" placeholder="{{ __('chatbot.workspace.instruction_placeholder') }}"></textarea>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="kaman-label block text-xs" for="instruction-scope">{{ __('chatbot.workspace.scope') }}</label>
                    <select id="instruction-scope" name="scope" class="kaman-input w-full !h-9 text-xs">
                        <option value="next_reply">{{ __('chatbot.workspace.scope_next_reply') }}</option>
                        <option value="persistent">{{ __('chatbot.workspace.scope_persistent') }}</option>
                        <option value="reply_count">{{ __('chatbot.workspace.scope_reply_count') }}</option>
                        <option value="until_time">{{ __('chatbot.workspace.scope_until_time') }}</option>
                    </select>
                </div>
                <div>
                    <label class="kaman-label block text-xs" for="instruction-priority">{{ __('chatbot.workspace.priority') }}</label>
                    <input id="instruction-priority" name="priority" type="number" min="1" max="1000" value="100" class="kaman-input w-full !h-9 text-xs">
                </div>
            </div>

            <div id="reply-count-field" class="hidden">
                <label class="kaman-label block text-xs" for="remaining-uses">{{ __('chatbot.workspace.remaining_uses') }}</label>
                <input id="remaining-uses" name="remaining_uses" type="number" min="1" max="100" value="3" class="kaman-input w-full !h-9 text-xs">
            </div>
            <div id="until-time-field" class="hidden">
                <label class="kaman-label block text-xs" for="expires-at">{{ __('chatbot.workspace.expires_at') }}</label>
                <input id="expires-at" name="expires_at" type="datetime-local" class="kaman-input w-full !h-9 text-xs">
            </div>

            <button type="submit" class="kaman-button w-full kaman-button--sm">{{ __('chatbot.workspace.save_instruction') }}</button>
        </form>

        <div class="mb-3">
            <p class="text-[11px] font-semibold text-[#7c6a56] mb-1.5">{{ __('chatbot.workspace.templates') }}</p>
            <div class="flex flex-wrap gap-1">
                @foreach($instructionTemplates as $tpl)
                    <button type="button" class="instruction-template text-[10px] rounded-full border border-[#eadfce] px-2 py-1 text-[#7c6a56] hover:border-[#f47a2e]/40 hover:text-[#f16229]"
                            data-instruction="{{ $tpl['instruction'] }}">{{ $tpl['label'] }}</button>
                @endforeach
            </div>
        </div>
    @else
        <p class="text-xs text-[#a78a6c] mb-3">{{ __('chatbot.workspace.instructor_readonly') }}</p>
    @endif

    <div class="flex-1 overflow-y-auto kaman-scroll space-y-2" id="instruction-list">
        @forelse($instructions as $item)
            <div class="rounded-xl border border-[#eadfce] bg-white p-2.5 text-xs" data-instruction-id="{{ $item->id }}">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-[#2b1e11] whitespace-pre-wrap">{{ $item->instruction }}</p>
                    <span class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-semibold {{ $item->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                        {{ $item->is_active ? __('chatbot.workspace.active') : __('chatbot.workspace.inactive') }}
                    </span>
                </div>
                <p class="mt-1 text-[10px] text-[#a78a6c]">
                    {{ $item->scope }} · {{ $item->creator?->name }} · {{ $item->created_at?->diffForHumans() }}
                </p>
                @if($canManageInstructions)
                    <div class="mt-2 flex gap-1">
                        <button type="button" class="toggle-instruction kaman-button-ghost !text-[10px] !px-2 !py-1"
                                data-url="{{ route('ai-chatbot.workspace.conversations.instructions.toggle', [$instance, $conversation, $item]) }}"
                                data-active="{{ $item->is_active ? '1' : '0' }}">
                            {{ $item->is_active ? __('chatbot.workspace.deactivate') : __('chatbot.workspace.activate') }}
                        </button>
                        <button type="button" class="delete-instruction kaman-button-ghost !text-[10px] !px-2 !py-1 text-red-600"
                                data-url="{{ route('ai-chatbot.workspace.conversations.instructions.destroy', [$instance, $conversation, $item]) }}">
                            {{ __('chatbot.workspace.delete') }}
                        </button>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-xs text-[#a78a6c] text-center py-4">{{ __('chatbot.workspace.no_instructions') }}</p>
        @endforelse
    </div>
</div>
