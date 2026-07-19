@php
    /** @var \App\Models\AiChatbot\ChatbotMember|null $editingMember */
    $member = $editingMember;
    $isEdit = $member !== null;
@endphp

<form action="{{ $isEdit ? route('ai-chatbot.instances.members.update', ['instance' => $instance, 'member' => $member]) : route('ai-chatbot.instances.members.store', $instance) }}"
      method="post"
      class="space-y-4">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="space-y-1.5">
            <label for="member_name" class="kaman-label block text-xs">{{ __('chatbot.name') }}</label>
            <input id="member_name" name="name" type="text" maxlength="120"
                   value="{{ old('name', $member?->name) }}"
                   class="kaman-input w-full">
        </div>

        <div class="space-y-1.5">
            <label for="member_customer_type" class="kaman-label block text-xs">{{ __('chatbot.customer_type') }}</label>
            <select id="member_customer_type" name="customer_type" required
                    class="kaman-input w-full">
                <option value="new" @selected(old('customer_type', $member?->customer_type ?? 'new') === 'new')>{{ __('chatbot.customer_new') }}</option>
                <option value="subscriber" @selected(old('customer_type', $member?->customer_type) === 'subscriber')>{{ __('chatbot.customer_subscriber') }}</option>
            </select>
        </div>

        <div class="space-y-1.5">
            <label for="member_national_id" class="kaman-label block text-xs">{{ __('chatbot.national_id') }}</label>
            <input id="member_national_id" name="national_id" type="text" maxlength="20"
                   value="{{ old('national_id', $member?->national_id) }}"
                   class="kaman-input w-full font-mono">
        </div>

        <div class="space-y-1.5">
            <label for="member_phone" class="kaman-label block text-xs">{{ __('chatbot.phone') }}</label>
            <input id="member_phone" name="phone" type="text" maxlength="30"
                   value="{{ old('phone', $member?->phone) }}"
                   class="kaman-input w-full">
        </div>

        <div class="space-y-1.5">
            <label for="member_payment_last4" class="kaman-label block text-xs">{{ __('chatbot.payment_last4') }}</label>
            <input id="member_payment_last4" name="payment_last4" type="text" maxlength="4" pattern="\d{4}"
                   value="{{ old('payment_last4', $member?->payment_last4) }}"
                   placeholder="{{ __('chatbot.payment_placeholder') }}"
                   class="kaman-input w-full font-mono">
        </div>

        <div class="space-y-1.5">
            <label for="member_router_type" class="kaman-label block text-xs">{{ __('chatbot.router_type') }}</label>
            <input id="member_router_type" name="router_type" type="text" maxlength="120"
                   value="{{ old('router_type', $member?->router_type) }}"
                   class="kaman-input w-full">
        </div>
    </div>

    <div class="space-y-1.5">
        <label for="member_notes" class="kaman-label block text-xs">{{ __('chatbot.notes') }}</label>
        <textarea id="member_notes" name="notes" rows="3"
                  class="kaman-input w-full">{{ old('notes', $member?->notes) }}</textarea>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="kaman-button">
            {{ $isEdit ? __('chatbot.update_member') : __('chatbot.add_member_btn') }}
        </button>
        @if($isEdit)
            <a href="{{ route('ai-chatbot.instances.members.index', $instance) }}"
               class="text-xs font-semibold text-[#a78a6c] hover:text-[#f16229] transition">
                {{ __('chatbot.cancel_edit') }}
            </a>
        @endif
    </div>
</form>
