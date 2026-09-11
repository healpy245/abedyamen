@php
    $activeProject = 'ai-chatbot';
    $id = $sections['identity'] ?? [];
    $biz = $sections['business'] ?? [];
    $beh = $sections['conversation_behavior'] ?? [];
    $res = $sections['restrictions'] ?? [];
    $wf = $sections['malan_workflows'] ?? [];
    $adv = $sections['advanced'] ?? [];
@endphp
@extends('layouts.kaman')

@section('title', __('chatbot.workspace.settings_title').' — '.$instance->name)
@section('tag', __('chatbot.workspace.tag'))

@section('content')
<div class="w-full max-w-4xl mx-auto px-3 sm:px-4 pb-10">
    @include('ai-chatbot.workspace.partials.nav')

    @if(session('status'))
        <div class="mb-4 rounded-xl border border-green-200 bg-green-50/70 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50/70 px-4 py-3 text-sm text-red-600">{{ $errors->first() }}</div>
    @endif

    {{-- Global bot power — green active / red inactive --}}
    <div class="mb-4">
        @include('ai-chatbot.workspace.partials.bot-power-toggle', [
            'instance' => $instance,
            'canToggleBot' => $canManageSettings || ($canControlBot ?? false),
            'compact' => false,
        ])
    </div>

    {{-- Integration status (no secrets) --}}
    <div class="kaman-card kaman-card--pad mb-4">
        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.integration_status') }}</h2>
        <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
            <div><dt class="text-[#a78a6c] text-xs">{{ __('chatbot.workspace.integration_type') }}</dt><dd>{{ $integrationStatus['type'] ?: '—' }}</dd></div>
            <div>
                <dt class="text-[#a78a6c] text-xs">{{ __('chatbot.workspace.greenapi') }}</dt>
                <dd class="font-semibold {{ $integrationStatus['greenapi_configured'] ? 'text-emerald-700' : 'text-red-600' }}">
                    {{ $integrationStatus['greenapi_configured'] ? __('chatbot.workspace.configured') : __('chatbot.workspace.not_configured') }}
                </dd>
            </div>
            <div>
                <dt class="text-[#a78a6c] text-xs">{{ __('chatbot.workspace.webhook') }}</dt>
                <dd class="font-semibold {{ $integrationStatus['webhook_configured'] ? 'text-emerald-700' : 'text-red-600' }}">
                    {{ $integrationStatus['webhook_configured'] ? __('chatbot.workspace.configured') : __('chatbot.workspace.not_configured') }}
                </dd>
            </div>
            <div>
                <dt class="text-[#a78a6c] text-xs">{{ __('chatbot.workspace.global_bot') }}</dt>
                <dd class="font-semibold {{ $integrationStatus['is_active'] ? 'text-emerald-700' : 'text-red-600' }}">
                    {{ $integrationStatus['is_active'] ? __('chatbot.workspace.bot_on') : __('chatbot.workspace.bot_off') }}
                </dd>
            </div>
        </dl>
    </div>

    @if($instance->hasKamanWhatsappIntegration())
        <div class="kaman-card kaman-card--pad mb-4 space-y-3">
            <div>
                <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.pos_demo_title') }}</h2>
                <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.pos_demo_help') }}</p>
            </div>
            @if($posDemoReady ?? false)
                <p class="text-sm text-emerald-700">
                    {{ __('chatbot.workspace.pos_demo_current', ['name' => $posDemo['original_name'] ?? ($posDemo['file_name'] ?? 'video')]) }}
                </p>
            @else
                <p class="text-sm text-[#7c6a56]">{{ __('chatbot.workspace.pos_demo_empty') }}</p>
            @endif
            @if($canManageSettings)
                <form method="post"
                      action="{{ route('ai-chatbot.workspace.settings.pos-demo', $instance) }}"
                      enctype="multipart/form-data"
                      class="flex flex-wrap items-end gap-2">
                    @csrf
                    <div class="min-w-0 flex-1">
                        <label class="kaman-label block" for="pos_demo_video">{{ __('chatbot.workspace.pos_demo_file') }}</label>
                        <input id="pos_demo_video"
                               name="video"
                               type="file"
                               accept="video/mp4,video/quicktime,video/webm,video/3gpp,.mp4,.mov,.webm,.3gp,.m4v"
                               required
                               class="kaman-input w-full text-xs">
                    </div>
                    <button type="submit" class="kaman-button kaman-button--sm">{{ __('chatbot.workspace.pos_demo_upload') }}</button>
                </form>
                @if($posDemoReady ?? false)
                    <form method="post"
                          action="{{ route('ai-chatbot.workspace.settings.pos-demo.destroy', $instance) }}"
                          onsubmit="return confirm(@json(__('chatbot.workspace.pos_demo_delete_confirm')))">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="kaman-button-ghost kaman-button--sm text-red-700">{{ __('chatbot.workspace.pos_demo_delete') }}</button>
                    </form>
                @endif
            @endif
        </div>
    @endif

    <form method="post" action="{{ route('ai-chatbot.workspace.settings.update', $instance) }}" class="space-y-4">
        @csrf
        @method('PUT')

        <div class="kaman-card kaman-card--pad space-y-4">
            <div>
                <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.greenapi_title') }}</h2>
                <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.greenapi_desc') }}</p>
            </div>
            <div>
                <label class="kaman-label block" for="greenapi_url">{{ __('chatbot.greenapi_send_url') }}</label>
                <input id="greenapi_url"
                       name="greenapi_url"
                       type="url"
                       maxlength="2000"
                       value="{{ old('greenapi_url', $instance->greenapi_url) }}"
                       placeholder="{{ __('chatbot.greenapi_send_url_placeholder') }}"
                       class="kaman-input w-full font-mono text-xs"
                       @disabled(! ($canManageIntegration || $canManageSettings))>
                <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.greenapi_send_url_help') }}</p>
            </div>
            <div>
                <label class="kaman-label block" for="greenapi_webhook_url">{{ __('chatbot.greenapi_webhook_url') }}</label>
                <div class="flex flex-wrap gap-2">
                    <input id="greenapi_webhook_url"
                           type="text"
                           readonly
                           value="{{ $greenapiWebhookUrl }}"
                           class="kaman-input min-w-0 flex-1 font-mono text-xs bg-[#faf6f0]">
                    <button type="button"
                            class="kaman-button-ghost kaman-button--sm shrink-0"
                            onclick="navigator.clipboard.writeText(document.getElementById('greenapi_webhook_url').value)">
                        {{ __('chatbot.copy') }}
                    </button>
                </div>
                <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.greenapi_webhook_url_help') }}</p>
            </div>
        </div>

        <div class="kaman-card kaman-card--pad space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.global_controls') }}</h2>
            </div>
            <div>
                <label class="kaman-label block" for="name">{{ __('chatbot.instance_name') }}</label>
                <input id="name" name="name" type="text" value="{{ old('name', $instance->name) }}" class="kaman-input w-full" @disabled(! $canManageSettings) maxlength="120">
            </div>
            <div>
                <label class="kaman-label block" for="disabled_message">{{ __('chatbot.workspace.disabled_message') }}</label>
                <textarea id="disabled_message" name="disabled_message" rows="2" class="kaman-input w-full" @disabled(! $canManageSettings)
                          placeholder="{{ __('chatbot.workspace.disabled_message_hint') }}">{{ old('disabled_message', $instance->disabled_message) }}</textarea>
            </div>
            <div>
                <p class="kaman-label mb-2">{{ __('chatbot.workspace.ignored_reply_phones') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="kaman-label block text-xs font-medium text-[#6b5340]" for="ignored_reply_phones">{{ __('chatbot.workspace.ignored_reply_phones_manual') }}</label>
                        <textarea id="ignored_reply_phones"
                                  name="ignored_reply_phones"
                                  rows="5"
                                  class="kaman-input kaman-scroll w-full font-mono text-xs leading-relaxed"
                                  @disabled(! $canManageSettings)
                                  placeholder="{{ __('chatbot.workspace.ignored_reply_phones_placeholder') }}">{{ old('ignored_reply_phones', implode("\n", $instance->manualIgnoredReplyPhones())) }}</textarea>
                        <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.ignored_reply_phones_manual_help') }}</p>
                    </div>
                    <div>
                        <label class="kaman-label block text-xs font-medium text-[#6b5340]" for="ignored_reply_phones_api">{{ __('chatbot.workspace.ignored_reply_phones_api') }}</label>
                        <textarea id="ignored_reply_phones_api"
                                  rows="5"
                                  class="kaman-input kaman-scroll w-full font-mono text-xs leading-relaxed bg-[#faf6f1] text-[#6b5340]"
                                  readonly
                                  tabindex="-1"
                                  placeholder="{{ __('chatbot.workspace.ignored_reply_phones_api_empty') }}">{{ implode("\n", $instance->apiIgnoredReplyPhones()) }}</textarea>
                        <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.ignored_reply_phones_api_help') }}</p>
                    </div>
                </div>
                <p class="mt-2 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.ignored_reply_phones_help') }}</p>
            </div>
        </div>

        @php
            $sectionDefs = [
                'identity' => [
                    'title' => __('chatbot.workspace.section_identity'),
                    'fields' => [
                        'bot_name' => ['label' => __('chatbot.workspace.field_bot_name'), 'type' => 'text'],
                        'company_name' => ['label' => __('chatbot.workspace.field_company'), 'type' => 'text'],
                        'role' => ['label' => __('chatbot.workspace.field_role'), 'type' => 'textarea'],
                        'languages' => ['label' => __('chatbot.workspace.field_languages'), 'type' => 'text', 'hint' => __('chatbot.workspace.languages_hint')],
                        'dialect' => ['label' => __('chatbot.workspace.field_dialect'), 'type' => 'text'],
                        'tone' => ['label' => __('chatbot.workspace.field_tone'), 'type' => 'text'],
                    ],
                    'data' => $id,
                ],
                'business' => [
                    'title' => __('chatbot.workspace.section_business'),
                    'fields' => [
                        'description' => ['label' => __('chatbot.workspace.field_description'), 'type' => 'textarea'],
                        'services' => ['label' => __('chatbot.workspace.field_services'), 'type' => 'textarea'],
                        'locations' => ['label' => __('chatbot.workspace.field_locations'), 'type' => 'textarea'],
                        'working_hours' => ['label' => __('chatbot.workspace.field_hours'), 'type' => 'textarea'],
                        'contact_information' => ['label' => __('chatbot.workspace.field_contact'), 'type' => 'textarea'],
                        'frequently_requested_information' => ['label' => __('chatbot.workspace.field_faq'), 'type' => 'textarea'],
                    ],
                    'data' => $biz,
                ],
                'conversation_behavior' => [
                    'title' => __('chatbot.workspace.section_behavior'),
                    'fields' => [
                        'greeting' => ['label' => __('chatbot.workspace.field_greeting'), 'type' => 'textarea'],
                        'answer_length' => ['label' => __('chatbot.workspace.field_answer_length'), 'type' => 'text'],
                        'formality' => ['label' => __('chatbot.workspace.field_formality'), 'type' => 'text'],
                        'emoji_usage' => ['label' => __('chatbot.workspace.field_emoji'), 'type' => 'text'],
                        'follow_up_rules' => ['label' => __('chatbot.workspace.field_follow_up'), 'type' => 'textarea'],
                        'angry_customer_handling' => ['label' => __('chatbot.workspace.field_angry'), 'type' => 'textarea'],
                        'human_handoff_rules' => ['label' => __('chatbot.workspace.field_handoff'), 'type' => 'textarea'],
                        'task_confirmation_rules' => ['label' => __('chatbot.workspace.field_task_confirmation'), 'type' => 'textarea'],
                    ],
                    'data' => $beh,
                ],
                'restrictions' => [
                    'title' => __('chatbot.workspace.section_restrictions'),
                    'fields' => [
                        'forbidden_topics' => ['label' => __('chatbot.workspace.field_forbidden_topics'), 'type' => 'textarea'],
                        'forbidden_claims' => ['label' => __('chatbot.workspace.field_forbidden_claims'), 'type' => 'textarea'],
                        'sensitive_information_rules' => ['label' => __('chatbot.workspace.field_sensitive'), 'type' => 'textarea'],
                        'payment_rules' => ['label' => __('chatbot.workspace.field_payment_rules'), 'type' => 'textarea'],
                        'verification_requirements' => ['label' => __('chatbot.workspace.field_verification'), 'type' => 'textarea'],
                        'emergency_escalation' => ['label' => __('chatbot.workspace.field_emergency'), 'type' => 'textarea'],
                    ],
                    'data' => $res,
                ],
                'malan_workflows' => [
                    'title' => __('chatbot.workspace.section_malan'),
                    'fields' => [
                        'customer_identification' => ['label' => __('chatbot.workspace.field_customer_id'), 'type' => 'textarea'],
                        'outage_support' => ['label' => __('chatbot.workspace.field_outage'), 'type' => 'textarea'],
                        'technical_support' => ['label' => __('chatbot.workspace.field_tech'), 'type' => 'textarea'],
                        'payment' => ['label' => __('chatbot.workspace.field_payment'), 'type' => 'textarea'],
                        'bank_transfer_proof' => ['label' => __('chatbot.workspace.field_bank_proof'), 'type' => 'textarea'],
                        'service_reactivation' => ['label' => __('chatbot.workspace.field_reactivation'), 'type' => 'textarea'],
                        'human_escalation' => ['label' => __('chatbot.workspace.field_escalation'), 'type' => 'textarea'],
                    ],
                    'data' => $wf,
                ],
            ];
        @endphp

        @foreach($sectionDefs as $sectionKey => $section)
            @if($sectionKey === 'malan_workflows' && ! $instance->hasMalanIntegration())
                @continue
            @endif
            <details class="kaman-card overflow-hidden group" {{ $loop->first ? 'open' : '' }}>
                <summary class="cursor-pointer list-none px-5 py-4 flex items-center justify-between gap-2 bg-white hover:bg-[#fffaf3]">
                    <span class="text-sm font-semibold text-[#2b1e11]">{{ $section['title'] }}</span>
                    <span class="text-[#a78a6c] text-xs group-open:rotate-180 transition">▼</span>
                </summary>
                <div class="px-5 pb-5 space-y-3 border-t border-[#eadfce] pt-4">
                    @foreach($section['fields'] as $fieldKey => $field)
                        @php
                            $value = old("prompt_sections.$sectionKey.$fieldKey", $section['data'][$fieldKey] ?? '');
                            if (is_array($value)) { $value = implode(', ', $value); }
                        @endphp
                        <div>
                            <label class="kaman-label block" for="ps-{{ $sectionKey }}-{{ $fieldKey }}">{{ $field['label'] }}</label>
                            @if(($field['type'] ?? '') === 'textarea')
                                <textarea id="ps-{{ $sectionKey }}-{{ $fieldKey }}" name="prompt_sections[{{ $sectionKey }}][{{ $fieldKey }}]" rows="3"
                                          class="kaman-input w-full" @disabled(! $canManageSettings)>{{ $value }}</textarea>
                            @else
                                <input id="ps-{{ $sectionKey }}-{{ $fieldKey }}" name="prompt_sections[{{ $sectionKey }}][{{ $fieldKey }}]" type="text"
                                       value="{{ $value }}" class="kaman-input w-full" @disabled(! $canManageSettings)>
                            @endif
                            @if(!empty($field['hint']))
                                <p class="text-[11px] text-[#a78a6c] mt-1">{{ $field['hint'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </details>
        @endforeach

        <details class="kaman-card overflow-hidden">
            <summary class="cursor-pointer list-none px-5 py-4 text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.section_advanced') }}</summary>
            <div class="px-5 pb-5 border-t border-[#eadfce] pt-4">
                <label class="kaman-label block" for="custom_instructions">{{ __('chatbot.workspace.field_custom') }}</label>
                <textarea id="custom_instructions" name="prompt_sections[advanced][custom_instructions]" rows="5"
                          class="kaman-input w-full" @disabled(! $canManageSettings)>{{ old('prompt_sections.advanced.custom_instructions', $adv['custom_instructions'] ?? '') }}</textarea>
            </div>
        </details>

        @if($canManageSettings)
            <details class="kaman-card overflow-hidden">
                <summary class="cursor-pointer list-none px-5 py-4 text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.compiled_preview') }}</summary>
                <div class="px-5 pb-5 border-t border-[#eadfce] pt-4">
                    <pre class="kaman-scroll max-h-64 overflow-auto rounded-xl bg-[#2b1e11] text-[#f7efe3] text-xs p-4 whitespace-pre-wrap">{{ $compiledPreview }}</pre>
                </div>
            </details>

            <div class="flex justify-end">
                <button type="submit" class="kaman-button">{{ __('chatbot.workspace.save_settings') }}</button>
            </div>
        @endif
    </form>

    @if($canManageSettings)
        <div class="kaman-card kaman-card--pad mt-4 border border-red-200/80 bg-red-50/30 space-y-3">
            <h2 class="text-sm font-semibold text-red-800">{{ __('chatbot.workspace.clear_conversations_title') }}</h2>
            <p class="text-xs text-[#7c6a56]">{{ __('chatbot.workspace.clear_conversations_help') }}</p>
            <form method="post"
                  action="{{ route('ai-chatbot.workspace.settings.clear-conversations', $instance) }}"
                  onsubmit="return confirm(@json(__('chatbot.workspace.clear_conversations_confirm')))">
                @csrf
                <button type="submit" class="kaman-button !bg-red-600 hover:!bg-red-700 !border-red-700">
                    {{ __('chatbot.workspace.clear_conversations_button') }}
                </button>
            </form>
        </div>
    @endif
</div>
@endsection
