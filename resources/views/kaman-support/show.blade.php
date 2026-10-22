@php
    $nav = 'tickets';
    $activeProject = \App\Enums\Project::KamanSupport->value;
    $tone = $ticket->status?->tone() ?? ['bg' => 'bg-slate-50', 'text' => 'text-slate-700', 'border' => 'border-slate-200'];
    $diagnosis = is_array($ticket->diagnosis) ? $ticket->diagnosis : [];
    $printers = is_array($ticket->printers) ? $ticket->printers : [];
    $conversation = is_array($ticket->conversation) ? $ticket->conversation : [];
@endphp

@extends('layouts.support')

@section('title', $ticket->public_id.' · '.__('kaman-support.title'))
@section('tag', __('kaman-support.tag'))

@section('content')
    <div class="sup-sr">
        @include('kaman-support.partials.nav', ['nav' => 'tickets'])
    </div>
    <div class="sup-ticket-page">
        <div class="sup-ticket-head">
            <div>
                <div class="sup-ticket-id">
                    <h1 dir="ltr">{{ $ticket->public_id }}</h1>
                    <span class="sup-badge">{{ $ticket->status?->label() }}</span>
                    <button type="button" class="sup-ghost" data-copy-ticket="{{ $ticket->public_id }}">{{ __('kaman-support.copy_id') }}</button>
                </div>
                <p>{{ $ticket->summary }}</p>
                <p class="sup-meta">
                    {{ $ticket->restaurant_name ?: $ticket->restaurant }}
                    @if($ticket->cashier_name) · {{ $ticket->cashier_name }} @endif
                    · {{ $ticket->created_at?->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('Y-m-d H:i') }}
                </p>
            </div>
            <div class="tk-actions">
                @if($ticket->status !== \App\Enums\KamanSupport\SupportTicketStatus::Closed)
                    <form method="post" action="{{ route('kaman-support.escalate', $ticket) }}">
                        @csrf
                        <button class="tk-view" type="submit">{{ __('kaman-support.escalate') }}</button>
                    </form>
                    <button class="tk-view" type="button" data-close-open="{{ $ticket->public_id }}">{{ __('kaman-support.close_ticket') }}</button>
                @endif
                <a class="tk-view" href="{{ route('kaman-support.inbox') }}">{{ __('kaman-support.back') }}</a>
            </div>
        </div>

        @if(session('status'))
            <p class="sup-toast" role="status">{{ session('status') }}</p>
        @endif

        <div class="sup-ticket-layout">
            <div>
                <div class="sup-seg" role="tablist">
                    <button type="button" class="is-active" data-ticket-tab="overview">{{ __('kaman-support.ws_overview') }}</button>
                    <button type="button" data-ticket-tab="diagnostics">{{ __('kaman-support.ws_diagnostics') }}</button>
                    <button type="button" data-ticket-tab="activity">{{ __('kaman-support.ws_activity') }}</button>
                </div>

                <section data-ticket-panel="overview" class="sup-pane">
                    <h2>{{ __('kaman-support.details') }}</h2>
                    <pre class="sup-pre">{{ $ticket->details ?: '—' }}</pre>
                    <h2>{{ __('kaman-support.device') }}</h2>
                    <p class="sup-meta" dir="ltr">{{ $ticket->device_id ?: '—' }}</p>
                    @if($diagnosis !== [])
                        <h2>{{ __('kaman-support.diagnosis') }}</h2>
                        <ul class="sup-list">
                            @foreach($diagnosis as $key => $value)
                                @if(is_scalar($value) || $value === null || is_bool($value))
                                    <li>{{ is_string($key) ? $key.': ' : '' }}{{ is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? '—') }}</li>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section data-ticket-panel="diagnostics" class="sup-pane" hidden>
                    <h2>{{ __('kaman-support.diagnosis') }}</h2>
                    @if($diagnosis === [])
                        <p class="sup-meta">{{ __('kaman-support.no_diagnosis') }}</p>
                    @else
                        <details>
                            <summary>{{ __('kaman-support.diagnosis') }}</summary>
                            <dl class="sup-list">
                                @foreach($diagnosis as $key => $value)
                                    <div>
                                        <dt>{{ is_string($key) ? $key : '' }}</dt>
                                        <dd>
                                            @if(is_bool($value))
                                                {{ $value ? 'true' : 'false' }}
                                            @elseif(is_scalar($value) || $value === null)
                                                {{ $value ?? '—' }}
                                            @else
                                                <pre class="sup-pre" dir="ltr">{{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </details>
                    @endif
                    <h2>{{ __('kaman-support.printers') }}</h2>
                    @if($printers === [])
                        <p class="sup-meta">{{ __('kaman-support.no_printers') }}</p>
                    @else
                        <div class="sup-logs">
                            @foreach($printers as $printer)
                                @php $row = is_array($printer) ? $printer : []; @endphp
                                <div class="sup-row sup-row--static">
                                    <span>{{ $row['name'] ?? '—' }}</span>
                                    <span class="sup-muted">{{ $row['role'] ?? '' }}</span>
                                    <span class="sup-muted">{{ $row['station'] ?? '' }}</span>
                                    <span dir="ltr">{{ $row['ip'] ?? '' }}</span>
                                    <strong>{{ $row['issue'] ?? '' }}</strong>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <h2>{{ __('kaman-support.live_session') }}</h2>
                    <p class="sup-meta">{{ __('kaman-support.live_coming') }}</p>
                    @if($device)
                        <a class="sup-ghost" href="{{ route('kaman-support.logs', ['restaurants' => [$ticket->restaurant]]) }}">{{ __('kaman-support.view_device_logs') }}</a>
                        <p class="sup-meta">
                            {{ __('kaman-support.last_seen') }}:
                            @if($device->last_seen_at)
                                {{ $device->last_seen_at->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('Y-m-d H:i') }}
                                ({{ $device->isRecentlySeen() ? __('kaman-support.online') : __('kaman-support.offline') }})
                            @else
                                {{ __('kaman-support.never_seen') }}
                            @endif
                        </p>
                    @endif
                </section>

                <section data-ticket-panel="activity" class="sup-pane" hidden>
                    <h2>{{ __('kaman-support.conversation') }}</h2>
                    @if($conversation === [])
                        <p class="sup-meta">{{ __('kaman-support.no_conversation') }}</p>
                    @else
                        <ul class="sup-list">
                            @foreach($conversation as $line)
                                <li>{{ is_scalar($line) ? $line : json_encode($line, JSON_UNESCAPED_UNICODE) }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <h2>{{ __('kaman-support.events') }}</h2>
                    @if($ticket->events->isEmpty())
                        <p class="sup-meta">{{ __('kaman-support.no_events') }}</p>
                    @else
                        <ol class="sup-list">
                            @foreach($ticket->events as $event)
                                <li>
                                    <strong>{{ $event->type?->label() }}</strong>
                                    <span class="sup-meta">{{ $event->actor_name }} · {{ $event->created_at?->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('Y-m-d H:i') }}</span>
                                    @if($event->message)<p>{{ $event->message }}</p>@endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </div>

            <aside class="sup-pane sup-ticket-side">
                <form method="post" action="{{ route('kaman-support.update', $ticket) }}" class="sup-list">
                    @csrf
                    @method('PATCH')
                    <label class="sup-field">
                        {{ __('kaman-support.filter_status') }}
                        <select name="status">
                            @foreach($statuses as $status)
                                <option value="{{ $status->value }}" @selected($ticket->status === $status)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="sup-field">
                        {{ __('kaman-support.assignee') }}
                        <input type="text" name="assignee" value="{{ old('assignee', $ticket->assignee) }}">
                    </label>
                    <label class="sup-field">
                        {{ __('kaman-support.note') }}
                        <textarea name="note" rows="3" placeholder="{{ __('kaman-support.note_placeholder') }}">{{ old('note') }}</textarea>
                    </label>
                    <button type="submit" class="sup-primary">{{ __('kaman-support.save') }}</button>
                </form>
                <form method="post" action="{{ route('kaman-support.events.store', $ticket) }}" class="sup-list">
                    @csrf
                    <input type="hidden" name="type" value="note">
                    <textarea name="message" rows="3" required placeholder="{{ __('kaman-support.note_placeholder') }}">{{ old('message') }}</textarea>
                    <button type="submit" class="sup-ghost">{{ __('kaman-support.add_note') }}</button>
                </form>
                @if($device)
                    @include('kaman-support.partials.command-form', [
                        'action' => route('kaman-support.tickets.commands', $ticket),
                        'commands' => $commands,
                        'commandNames' => $commandNames,
                    ])
                @endif
            </aside>
        </div>
    </div>
    <dialog class="tk-assure" data-close-dialog>
        <form method="post" action="{{ route('kaman-support.close', $ticket) }}">
            @csrf
            <div class="tk-assure__head">
                <div class="tk-assure__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 12.5l3.2 3.2L18 8"/><circle cx="12" cy="12" r="9"/></svg></div>
                <div>
                    <h2>{{ __('kaman-support.close_title') }}</h2>
                    <p>{{ $ticket->summary }}</p>
                </div>
                <button class="tk-assure__x" type="button" data-close-cancel aria-label="{{ __('app-development.cancel') }}">×</button>
            </div>
            <label>
                {{ __('kaman-support.close_note') }}
                <textarea name="note" rows="4"></textarea>
            </label>
            <div class="tk-assure__foot">
                <button class="tk-view" type="button" data-close-cancel>{{ __('app-development.cancel') }}</button>
                <button class="tk-new" type="submit">{{ __('kaman-support.close_ticket') }}</button>
            </div>
        </form>
    </dialog>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-ticket-tab]').forEach((button) => {
            button.addEventListener('click', () => {
                const tab = button.getAttribute('data-ticket-tab');
                document.querySelectorAll('[data-ticket-tab]').forEach((item) => item.classList.toggle('is-active', item === button));
                document.querySelectorAll('[data-ticket-panel]').forEach((panel) => {
                    panel.hidden = panel.getAttribute('data-ticket-panel') !== tab;
                });
            });
        });
        const closeDialog = document.querySelector('[data-close-dialog]');
        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-close-open]') && closeDialog?.showModal) closeDialog.showModal();
            if (event.target.closest('[data-close-cancel]') && closeDialog) (window.kamanCloseDialog || function (d) { d.close(); })(closeDialog);
        });
        closeDialog?.addEventListener('click', (event) => {
            if (event.target === closeDialog) (window.kamanCloseDialog || function (d) { d.close(); })(closeDialog);
        });
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
