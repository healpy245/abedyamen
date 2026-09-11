@extends('app-development.layouts.project')

@section('title', __('app-development.team.title'))

@section('app')
    <div class="kaman-card overflow-hidden">
        <div class="border-b border-[#eadfce] px-4 py-3">
            <h1 class="text-base font-semibold text-[#2b1e11]">{{ __('app-development.team.title') }}</h1>
            <p class="mt-0.5 text-xs text-[#7c6a56]">{{ __('app-development.team.subtitle') }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="kaman-table">
                <thead>
                    <tr>
                        <th>{{ __('app-development.team.member') }}</th>
                        <th>{{ __('app-development.team.role') }}</th>
                        <th>{{ __('app-development.team.phone') }}</th>
                        <th>{{ __('app-development.team.whatsapp') }}</th>
                        <th>{{ __('app-development.team.password') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($members as $member)
                        <tr>
                            <td>
                                <div class="kaman-table__person">
                                    <strong>{{ $member->user?->name ?? '—' }}</strong>
                                    @if($member->user?->email)
                                        <small>{{ $member->user->email }}</small>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="kaman-badge bg-white text-[#7c6a56] border-[#eadfce]">
                                    {{ $member->role->label() }}
                                </span>
                            </td>
                            <td colspan="4">
                                <form method="post" action="{{ route('app-development.team.update', $member) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    @method('PUT')
                                    <input type="tel"
                                           name="phone"
                                           value="{{ old('phone', $member->phone) }}"
                                           class="kaman-input kaman-input--sm w-36"
                                           dir="ltr"
                                           placeholder="+9725…">
                                    <label class="inline-flex items-center gap-1.5 pb-1.5 text-xs text-[#2b1e11]">
                                        <input type="checkbox"
                                               name="whatsapp_notifications_enabled"
                                               value="1"
                                               class="rounded border-[#eadfce]"
                                               @checked(old('whatsapp_notifications_enabled', $member->whatsapp_notifications_enabled))>
                                        {{ __('app-development.team.enabled') }}
                                    </label>
                                    <input type="password"
                                           name="password"
                                           class="kaman-input kaman-input--sm w-36"
                                           autocomplete="new-password"
                                           placeholder="{{ __('app-development.team.new_password') }}">
                                    <input type="password"
                                           name="password_confirmation"
                                           class="kaman-input kaman-input--sm w-36"
                                           autocomplete="new-password"
                                           placeholder="{{ __('app-development.team.confirm_password') }}">
                                    <button type="submit" class="kaman-button-ghost kaman-button--sm">
                                        @include('app-development.partials.icon', ['name' => 'save'])
                                        {{ __('app.save') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
