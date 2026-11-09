@extends('app-development.layouts.project')

@section('title', __('app-development.team.title'))

@section('app')
    <div class="ds-page">
        <header class="ds-head">
            <div>
                <h1 class="ds-h1">{{ __('app-development.team.title') }}</h1>
                <p class="ds-sub">{{ __('app-development.team.subtitle') }}</p>
            </div>
        </header>

        <div class="ds-table-wrap">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th>{{ __('app-development.team.member') }}</th>
                        <th>{{ __('app-development.team.role') }}</th>
                        <th>{{ __('app-development.team.phone') }} · {{ __('app-development.team.whatsapp') }} · {{ __('app-development.team.password') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($members as $member)
                        <tr>
                            <td>
                                <span class="ds-identity">
                                    <x-ds.avatar :name="$member->user?->name ?? '?'" />
                                    <span class="ds-identity__text">
                                        <strong>{{ $member->user?->name ?? '—' }}</strong>
                                        @if($member->user?->email)
                                            <small><bdi dir="ltr">{{ $member->user->email }}</bdi></small>
                                        @endif
                                    </span>
                                </span>
                            </td>
                            <td><x-ds.badge soft :dot="false">{{ $member->role->label() }}</x-ds.badge></td>
                            <td>
                                <form method="post" action="{{ route('app-development.team.update', $member) }}" class="dv-team-form">
                                    @csrf
                                    @method('PUT')
                                    <input type="tel" name="phone" value="{{ old('phone', $member->phone) }}" class="ds-input" dir="ltr" placeholder="+9725…" aria-label="{{ __('app-development.team.phone') }}">
                                    <label class="ds-switch">
                                        <input type="checkbox" name="whatsapp_notifications_enabled" value="1" @checked(old('whatsapp_notifications_enabled', $member->whatsapp_notifications_enabled))>
                                        <span class="ds-switch__track" aria-hidden="true"></span>
                                        <span>{{ __('app-development.team.whatsapp') }}</span>
                                    </label>
                                    <input type="password" name="password" class="ds-input" autocomplete="new-password" placeholder="{{ __('app-development.team.new_password') }}" aria-label="{{ __('app-development.team.new_password') }}">
                                    <input type="password" name="password_confirmation" class="ds-input" autocomplete="new-password" placeholder="{{ __('app-development.team.confirm_password') }}" aria-label="{{ __('app-development.team.confirm_password') }}">
                                    <button type="submit" class="ds-btn ds-btn--secondary ds-btn--sm">{{ __('app.save') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="ds-empty-cell">
                                <x-ds.empty :title="__('app-development.team.title')" icon="users" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
