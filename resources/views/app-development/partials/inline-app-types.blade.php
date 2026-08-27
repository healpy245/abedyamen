@php
    /** @var \App\Models\AppDevelopment\AppDevelopmentTicket $ticket */
    $editable = $editable ?? false;
    $types = $ticket->relationLoaded('appTypeRows') ? $ticket->appTypes() : $ticket->appTypeRows()->get()->map->app_type->all();
    $selected = collect($types)->map(fn ($t) => $t->value)->all();
    $url = route('app-development.tickets.app-types', $ticket);
@endphp

<div class="kaman-inline-wrap">
    @if(! $editable)
        @include('app-development.partials.app-type-badges', ['types' => $types, 'compact' => true])
    @else
        <div class="kaman-inline-field kaman-inline-field--multi"
             data-kaman-inline
             data-kaman-inline-multi
             data-field="app_types"
             data-url="{{ $url }}"
             data-value="{{ implode(',', $selected) }}">
            <button type="button"
                    class="kaman-inline-field__trigger"
                    aria-haspopup="listbox"
                    aria-expanded="false"
                    aria-label="{{ __('app-development.tickets.change_app_types') }}">
                <span class="kaman-inline-field__badge" data-app-types-badge>
                    @include('app-development.partials.app-type-badges', ['types' => $types, 'compact' => true])
                </span>
                <span class="kaman-inline-field__caret" aria-hidden="true">
                    @include('app-development.partials.icon', ['name' => 'chevron-down'])
                </span>
            </button>
            <div class="kaman-inline-field__menu kaman-inline-field__menu--multi" role="listbox" aria-multiselectable="true" hidden>
                @foreach(\App\Enums\AppDevelopmentAppType::cases() as $option)
                    <label class="kaman-inline-field__check {{ in_array($option->value, $selected, true) ? 'is-selected' : '' }}">
                        <input type="checkbox"
                               value="{{ $option->value }}"
                               @checked(in_array($option->value, $selected, true))>
                        <span class="kaman-app-type-badge kaman-app-type-badge--{{ $option->value }}">
                            @include('app-development.partials.icon', ['name' => $option->icon()])
                            <span>{{ $option->label() }}</span>
                        </span>
                    </label>
                @endforeach
                <button type="button" class="kaman-button kaman-button--sm mt-1 w-full" data-app-types-apply>
                    {{ __('app-development.save') }}
                </button>
            </div>
        </div>
    @endif
</div>

@once('kaman-inline-field-script')
    <script src="{{ asset('js/app-development-inline.js') }}?v={{ @filemtime(public_path('js/app-development-inline.js')) ?: time() }}" defer></script>
@endonce
