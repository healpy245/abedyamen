{{-- Trigger contact picker modal (shared by campaign subnav + index cards) --}}
<div id="campaign-trigger-modal"
     class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-6"
     role="dialog"
     aria-modal="true"
     aria-labelledby="campaign-trigger-title"
     hidden
     data-label-selected="{{ __('chatbot.workspace.campaigns.trigger_selected_count') }}"
     data-label-loading="{{ __('chatbot.workspace.campaigns.trigger_loading') }}"
     data-label-empty="{{ __('chatbot.workspace.campaigns.trigger_empty') }}"
     data-label-need-one="{{ __('chatbot.workspace.campaigns.trigger_need_one') }}"
     data-label-unnamed="{{ __('chatbot.workspace.campaigns.trigger_unnamed') }}"
     data-label-custom-invalid="{{ __('chatbot.workspace.campaigns.trigger_custom_invalid') }}"
     data-label-custom-exists="{{ __('chatbot.workspace.campaigns.trigger_custom_exists') }}">
    <div class="absolute inset-0 bg-black/40" data-trigger-close></div>
    <div class="relative z-10 flex max-h-[min(88vh,720px)] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-[#eadfce] bg-[#fffaf3] shadow-xl">
        <header class="flex shrink-0 items-start justify-between gap-3 border-b border-[#eadfce] px-4 py-3">
            <div class="min-w-0">
                <h3 id="campaign-trigger-title" class="text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.campaigns.trigger_modal_title') }}</h3>
                <p class="mt-0.5 text-[11px] text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.trigger_modal_hint') }}</p>
            </div>
            <button type="button" class="kaman-button-ghost kaman-button--sm shrink-0" data-trigger-close>{{ __('chatbot.workspace.close') }}</button>
        </header>

        <div class="shrink-0 space-y-2 border-b border-[#eadfce]/80 px-4 py-3">
            <label class="sr-only" for="campaign-trigger-search">{{ __('chatbot.workspace.campaigns.trigger_search') }}</label>
            <input id="campaign-trigger-search"
                   type="search"
                   autocomplete="off"
                   placeholder="{{ __('chatbot.workspace.campaigns.trigger_search_placeholder') }}"
                   class="kaman-input w-full text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 text-[11px]">
                <div class="flex items-center gap-2">
                    <button type="button" id="campaign-trigger-select-all" class="font-semibold text-[#f16229] hover:underline">{{ __('chatbot.workspace.campaigns.trigger_select_all') }}</button>
                    <span class="text-[#d4c4b0]">·</span>
                    <button type="button" id="campaign-trigger-clear-all" class="font-semibold text-[#7c6a56] hover:underline">{{ __('chatbot.workspace.campaigns.trigger_clear_all') }}</button>
                </div>
                <p id="campaign-trigger-count" class="text-[#a78a6c]"></p>
            </div>
        </div>

        <div id="campaign-trigger-list" class="min-h-0 flex-1 overflow-y-auto kaman-scroll px-2 py-2"></div>

        <div class="shrink-0 space-y-2 border-t border-[#eadfce] bg-[#fff7ee] px-4 py-3">
            <p class="text-[11px] font-semibold text-[#7c6a56]">{{ __('chatbot.workspace.campaigns.trigger_add_custom') }}</p>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1">
                    <label class="sr-only" for="campaign-trigger-custom-phone">{{ __('chatbot.workspace.campaigns.trigger_custom_phone') }}</label>
                    <input id="campaign-trigger-custom-phone"
                           type="tel"
                           inputmode="tel"
                           autocomplete="tel"
                           dir="ltr"
                           placeholder="{{ __('chatbot.workspace.campaigns.trigger_custom_phone_placeholder') }}"
                           class="kaman-input w-full text-sm">
                </div>
                <div class="min-w-0 sm:w-36">
                    <label class="sr-only" for="campaign-trigger-custom-name">{{ __('chatbot.workspace.campaigns.trigger_custom_name') }}</label>
                    <input id="campaign-trigger-custom-name"
                           type="text"
                           autocomplete="name"
                           placeholder="{{ __('chatbot.workspace.campaigns.trigger_custom_name_placeholder') }}"
                           class="kaman-input w-full text-sm">
                </div>
                <button type="button" id="campaign-trigger-custom-add" class="kaman-button-ghost kaman-button--sm shrink-0">
                    {{ __('chatbot.workspace.campaigns.trigger_custom_add') }}
                </button>
            </div>
            <p id="campaign-trigger-custom-msg" class="hidden text-[11px] text-[#7c6a56]" role="status"></p>
        </div>

        <form id="campaign-trigger-form" method="post" class="shrink-0 border-t border-[#eadfce] bg-white px-4 py-3">
            @csrf
            <div id="campaign-trigger-ids"></div>
            <p id="campaign-trigger-error" class="mb-2 hidden text-xs text-red-600" role="alert"></p>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <button type="button" class="kaman-button-ghost kaman-button--sm" data-trigger-close>{{ __('chatbot.workspace.campaigns.trigger_cancel') }}</button>
                <button type="submit" id="campaign-trigger-submit" class="kaman-button kaman-button--sm">{{ __('chatbot.workspace.campaigns.trigger_confirm') }}</button>
            </div>
        </form>
    </div>
</div>
