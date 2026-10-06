@php
    $activeProject = \App\Enums\Project::KamanSupport->value;
    $tone = $ticket->status?->tone() ?? ['bg' => 'bg-slate-50', 'text' => 'text-slate-700', 'border' => 'border-slate-200'];
    $diagnosis = is_array($ticket->diagnosis) ? $ticket->diagnosis : [];
    $printers = is_array($ticket->printers) ? $ticket->printers : [];
    $conversation = is_array($ticket->conversation) ? $ticket->conversation : [];
@endphp

@extends('layouts.kaman')

@section('title', $ticket->public_id.' · '.__('kaman-support.title'))
@section('tag', __('kaman-support.tag'))

@section('content')
    <div class="page-container page-container--tight">
        <div class="mx-auto w-full max-w-6xl space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <a href="{{ route('kaman-support.index') }}" class="kaman-button-ghost kaman-button--sm">{{ __('kaman-support.back') }}</a>
                <div class="flex flex-wrap items-center gap-2">
                    @include('kaman-support.partials.nav', ['nav' => 'tickets'])
                    <button type="button" class="kaman-button-ghost kaman-button--sm" data-copy-ticket="{{ $ticket->public_id }}">{{ __('kaman-support.copy_id') }}</button>
                </div>
            </div>

            @if(session('status'))
                <p class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>
            @endif

            <section class="hero-panel hero-panel--compact space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="font-mono text-xl font-semibold text-[#2b1e11]">{{ $ticket->public_id }}</h1>
                    <span class="kaman-badge {{ $tone['bg'] }} {{ $tone['text'] }} {{ $tone['border'] }}">{{ $ticket->status?->label() }}</span>
                </div>
                <p class="text-base text-[#2b1e11]">{{ $ticket->summary }}</p>
                <p class="text-sm text-[#7c6a56]">
                    {{ $ticket->restaurant_name ?: $ticket->restaurant }}
                    @if($ticket->cashier_name) · {{ $ticket->cashier_name }} @endif
                    @if($ticket->locale) · {{ __('kaman-support.locale') }}: {{ $ticket->locale }} @endif
                    · {{ $ticket->created_at?->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('Y-m-d H:i') }}
                </p>
            </section>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="space-y-4 lg:col-span-2">
                    <section class="kaman-card kaman-card--compact space-y-2">
                        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.details') }}</h2>
                        <pre class="whitespace-pre-wrap break-words text-sm text-[#3f2f1d]">{{ $ticket->details ?: '—' }}</pre>
                    </section>

                    <section class="kaman-card kaman-card--compact space-y-2">
                        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.conversation') }}</h2>
                        @if($conversation === [])
                            <p class="text-sm text-[#7c6a56]">{{ __('kaman-support.no_conversation') }}</p>
                        @else
                            <ul class="space-y-1.5 text-sm">
                                @foreach($conversation as $line)
                                    <li class="rounded-md bg-[#fff8f0] px-2 py-1.5 text-[#3f2f1d]">{{ is_scalar($line) ? $line : json_encode($line, JSON_UNESCAPED_UNICODE) }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </section>

                    <section class="kaman-card kaman-card--compact space-y-2">
                        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.diagnosis') }}</h2>
                        @if($diagnosis === [])
                            <p class="text-sm text-[#7c6a56]">{{ __('kaman-support.no_diagnosis') }}</p>
                        @else
                            <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2 text-sm">
                                @foreach($diagnosis as $key => $value)
                                    <div class="rounded-md border border-[#eadfce] px-2 py-1.5">
                                        <dt class="text-xs uppercase tracking-wide text-[#a78a6c]">{{ is_string($key) ? $key : '' }}</dt>
                                        <dd class="break-words text-[#2b1e11]">
                                            @if(is_bool($value))
                                                {{ $value ? 'true' : 'false' }}
                                            @elseif(is_scalar($value) || $value === null)
                                                {{ $value ?? '—' }}
                                            @else
                                                <pre class="whitespace-pre-wrap text-xs">{{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </section>

                    <section class="kaman-card kaman-card--compact space-y-2">
                        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.printers') }}</h2>
                        @if($printers === [])
                            <p class="text-sm text-[#7c6a56]">{{ __('kaman-support.no_printers') }}</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="text-start text-xs uppercase text-[#a78a6c]">
                                            <th class="px-2 py-1">{{ __('kaman-support.printer.name') }}</th>
                                            <th class="px-2 py-1">{{ __('kaman-support.printer.role') }}</th>
                                            <th class="px-2 py-1">{{ __('kaman-support.printer.station') }}</th>
                                            <th class="px-2 py-1">{{ __('kaman-support.printer.ip') }}</th>
                                            <th class="px-2 py-1">{{ __('kaman-support.printer.issue') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($printers as $printer)
                                            @php $row = is_array($printer) ? $printer : []; @endphp
                                            <tr class="border-t border-[#f3ebe0]">
                                                <td class="px-2 py-1">{{ $row['name'] ?? '—' }}</td>
                                                <td class="px-2 py-1">{{ $row['role'] ?? '—' }}</td>
                                                <td class="px-2 py-1">{{ $row['station'] ?? '—' }}</td>
                                                <td class="px-2 py-1 font-mono text-xs">{{ $row['ip'] ?? '—' }}</td>
                                                <td class="px-2 py-1">{{ $row['issue'] ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </section>

                    <section class="kaman-card kaman-card--compact space-y-3">
                        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.events') }}</h2>
                        @if($ticket->events->isEmpty())
                            <p class="text-sm text-[#7c6a56]">{{ __('kaman-support.no_events') }}</p>
                        @else
                            <ol class="space-y-2">
                                @foreach($ticket->events as $event)
                                    <li class="rounded-md border border-[#eadfce] px-3 py-2 text-sm">
                                        <div class="flex flex-wrap items-center gap-2 text-xs text-[#a78a6c]">
                                            <span class="font-semibold text-[#c2410c]">{{ $event->type?->label() }}</span>
                                            <span>{{ $event->actor_name }}</span>
                                            <span>{{ $event->created_at?->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('Y-m-d H:i') }}</span>
                                        </div>
                                        @if($event->message)
                                            <p class="mt-1 whitespace-pre-wrap text-[#2b1e11]">{{ $event->message }}</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </section>
                </div>

                <div class="space-y-4">
                    <section class="kaman-card kaman-card--compact space-y-3">
                        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.update') }}</h2>
                        <form method="post" action="{{ route('kaman-support.update', $ticket) }}" class="space-y-3">
                            @csrf
                            @method('PATCH')
                            <label class="flex flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                                {{ __('kaman-support.filter_status') }}
                                <select name="status" class="kaman-input">
                                    @foreach($statuses as $status)
                                        <option value="{{ $status->value }}" @selected($ticket->status === $status)>{{ $status->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="flex flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                                {{ __('kaman-support.assignee') }}
                                <input type="text" name="assignee" value="{{ old('assignee', $ticket->assignee) }}" class="kaman-input">
                            </label>
                            <label class="flex flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                                {{ __('kaman-support.note') }}
                                <textarea name="note" rows="3" class="kaman-input" placeholder="{{ __('kaman-support.note_placeholder') }}">{{ old('note') }}</textarea>
                            </label>
                            <button type="submit" class="kaman-button w-full">{{ __('kaman-support.save') }}</button>
                        </form>
                    </section>

                    <section class="kaman-card kaman-card--compact space-y-3">
                        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.add_note') }}</h2>
                        <form method="post" action="{{ route('kaman-support.events.store', $ticket) }}" class="space-y-3">
                            @csrf
                            <input type="hidden" name="type" value="note">
                            <textarea name="message" rows="4" required class="kaman-input" placeholder="{{ __('kaman-support.note_placeholder') }}">{{ old('message') }}</textarea>
                            <button type="submit" class="kaman-button-ghost w-full">{{ __('kaman-support.add_note') }}</button>
                        </form>
                    </section>

                    @if($device)
                        @include('kaman-support.partials.command-form', [
                            'action' => route('kaman-support.tickets.commands', $ticket),
                            'commands' => $commands,
                            'commandNames' => $commandNames,
                        ])
                    @endif

                    <section class="kaman-card kaman-card--compact space-y-2">
                        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.live_session') }}</h2>
                        <p class="text-sm text-[#7c6a56]">{{ __('kaman-support.live_coming') }}</p>
                        @if($device)
                            <a href="{{ route('kaman-support.device', $device) }}" class="kaman-button-ghost kaman-button--sm inline-flex">{{ __('kaman-support.view_device_logs') }}</a>
                        @endif
                        <dl class="space-y-1 text-sm">
                            <div>
                                <dt class="text-xs text-[#a78a6c]">{{ __('kaman-support.live_device') }}</dt>
                                <dd>{{ $ticket->device_id ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-[#a78a6c]">{{ __('kaman-support.last_seen') }}</dt>
                                <dd>
                                    @if($device?->last_seen_at)
                                        {{ $device->last_seen_at->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('Y-m-d H:i') }}
                                        ({{ $device->isRecentlySeen() ? __('kaman-support.online') : __('kaman-support.offline') }})
                                    @else
                                        {{ __('kaman-support.never_seen') }}
                                    @endif
                                </dd>
                            </div>
                            @if($device?->last_ticket_id)
                                <div>
                                    <dt class="text-xs text-[#a78a6c]">{{ __('kaman-support.live_last_ticket') }}</dt>
                                    <dd class="font-mono text-xs">{{ $device->last_ticket_id }}</dd>
                                </div>
                            @endif
                        </dl>
                    </section>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-copy-ticket]').forEach((button) => {
            button.addEventListener('click', async () => {
                const id = button.getAttribute('data-copy-ticket') || '';
                try {
                    await navigator.clipboard.writeText(id);
                    const previous = button.textContent;
                    button.textContent = @json(__('kaman-support.copied'));
                    setTimeout(() => { button.textContent = previous; }, 1500);
                } catch (e) {}
            });
        });
    </script>
@endpush
