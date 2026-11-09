@php
    $activeProject = \App\Enums\Project::KamanSupport->value;
@endphp

@extends('layouts.kaman')

@section('title', __('kaman-support.title'))
@section('tag', __('kaman-support.tag'))

@section('content')
    <div class="page-container page-container--tight">
        <div class="mx-auto w-full max-w-6xl space-y-4">
            <section class="hero-panel hero-panel--compact">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 class="text-xl sm:text-2xl font-semibold text-[#2b1e11]">{{ __('kaman-support.title') }}</h1>
                        <p class="mt-1 text-sm text-[#7c6a56]">{{ __('kaman-support.subtitle') }}</p>
                    </div>
                    @include('kaman-support.partials.nav', ['nav' => 'tickets'])
                </div>
            </section>

            <form method="get" class="kaman-card kaman-card--compact flex flex-wrap items-end gap-2">
                <label class="flex min-w-[10rem] flex-1 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.search_placeholder') }}
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="kaman-input" placeholder="{{ __('kaman-support.search_placeholder') }}">
                </label>
                <label class="flex w-44 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.filter_status') }}
                    <select name="status" class="kaman-input">
                        <option value="">{{ __('kaman-support.all_statuses') }}</option>
                        @foreach($statuses as $status)
                            <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex w-44 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.filter_restaurant') }}
                    <select name="restaurant" class="kaman-input">
                        <option value="">{{ __('kaman-support.all_restaurants') }}</option>
                        @foreach($restaurants as $restaurant)
                            <option value="{{ $restaurant }}" @selected($filters['restaurant'] === $restaurant)>{{ $restaurant }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="kaman-button">{{ __('kaman-support.filter') }}</button>
            </form>

            <section class="kaman-card kaman-card--compact overflow-x-auto">
                @if($tickets->isEmpty())
                    <p class="py-8 text-center text-sm text-[#7c6a56]">{{ __('kaman-support.empty') }}</p>
                @else
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-[#eadfce] text-start text-xs uppercase tracking-wide text-[#a78a6c]">
                                <th class="px-3 py-2 font-semibold">{{ __('kaman-support.ticket') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('kaman-support.filter_status') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('kaman-support.restaurant') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('kaman-support.summary') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('kaman-support.received') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('kaman-support.last_seen') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tickets as $ticket)
                                @php
                                    $deviceKey = $ticket->restaurant.'|'.$ticket->device_id;
                                    $device = $devices[$deviceKey] ?? null;
                                    $tone = $ticket->status?->tone() ?? ['bg' => 'bg-slate-50', 'text' => 'text-slate-700', 'border' => 'border-slate-200'];
                                @endphp
                                <tr class="border-b border-[#f3ebe0] last:border-0 hover:bg-[#fff8f0]">
                                    <td class="px-3 py-2 whitespace-nowrap font-mono text-xs">
                                        <a href="{{ route('kaman-support.inbox', ['ticket' => $ticket->public_id]) }}" class="font-semibold text-[#c2410c] hover:underline">{{ $ticket->public_id }}</a>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="kaman-badge {{ $tone['bg'] }} {{ $tone['text'] }} {{ $tone['border'] }}">{{ $ticket->status?->label() }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-[#2b1e11]">{{ $ticket->restaurant_name ?: $ticket->restaurant }}</div>
                                        @if($ticket->cashier_name)
                                            <div class="text-xs text-[#7c6a56]">{{ $ticket->cashier_name }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 max-w-md">
                                        <a href="{{ route('kaman-support.inbox', ['ticket' => $ticket->public_id]) }}" class="line-clamp-2 text-[#2b1e11] hover:underline">{{ $ticket->summary }}</a>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-[#7c6a56]">{{ $ticket->created_at?->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('Y-m-d H:i') }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs">
                                        @if($device?->last_seen_at)
                                            <span class="{{ $device->isRecentlySeen() ? 'text-emerald-700' : 'text-[#7c6a56]' }}">
                                                {{ $device->isRecentlySeen() ? __('kaman-support.online') : $device->last_seen_at->diffForHumans() }}
                                            </span>
                                        @else
                                            <span class="text-[#a78a6c]">{{ __('kaman-support.never_seen') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="mt-3">{{ $tickets->withQueryString()->links() }}</div>
                @endif
            </section>
        </div>
    </div>
@endsection
