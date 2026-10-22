@extends('app-development.layouts.project')

@section('title', __('app-development.tasks.title'))

@section('app')
    @php
        $user = auth()->user();
        $viewMode = $viewMode ?? 'board';
        $columns = $columns ?? [];
        $scope = $scope ?? 'mine';
        $search = $search ?? '';
        $filters = $filters ?? [];
        $preserve = request()->except('page', 'view');
        $boardUrl = route('app-development.tasks.index', array_merge($preserve, ['view' => 'board']));
        $listUrl = route('app-development.tasks.index', array_merge($preserve, ['view' => 'list']));
        $assigneeName = collect($developers)->firstWhere('id', (int) ($filters['assignee'] ?? 0))?->name;
        $chips = [];
        if ($assigneeName) {
            $chips[] = [$assigneeName, request()->except('page', 'assignee')];
        }
        if (! empty($filters['priority'])) {
            $priority = \App\Enums\AppDevelopmentTicketPriority::tryFrom((string) $filters['priority']);
            if ($priority) {
                $chips[] = [$priority->label(), request()->except('page', 'priority')];
            }
        }
        if (! empty($filters['status'])) {
            $status = \App\Enums\AppDevelopmentTaskStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $chips[] = [$status->label(), request()->except('page', 'status')];
            }
        }
        foreach ($filters['app_type'] ?? [] as $appValue) {
            $appType = \App\Enums\AppDevelopmentAppType::tryFrom((string) $appValue);
            if ($appType) {
                $chips[] = [$appType->label(), request()->except('page', 'app_type')];
            }
        }
        if (! empty($filters['ticket'])) {
            $chips[] = [(string) $filters['ticket'], request()->except('page', 'ticket')];
        }
        if (! empty($filters['due'])) {
            $chips[] = [(string) $filters['due'], request()->except('page', 'due')];
        }
        if ($search !== '') {
            $chips[] = [$search, request()->except('page', 'q')];
        }
        $sort = $sort ?? 'updated';
        $dir = $dir ?? 'desc';
        $sortLink = function (string $key) use ($sort, $dir) {
            $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';

            return route('app-development.tasks.index', array_merge(request()->except('page'), [
                'view' => 'list',
                'sort' => $key,
                'dir' => $next,
            ]));
        };
    @endphp

    <div class="kb-page"
         data-kb-ui
         data-moved="{{ __('app-development.board.moved') }}"
         data-failed="{{ __('app-development.board.move_failed') }}"
         data-loading="{{ __('app-development.board.loading') }}">
        <header class="kb-head">
            <div class="kb-tabs" role="tablist">
                <a href="{{ $listUrl }}" role="tab" @if($viewMode === 'list') aria-selected="true" @endif>{{ __('app-development.board.list') }}</a>
                <a href="{{ $boardUrl }}" role="tab" @if($viewMode === 'board') aria-selected="true" @endif>{{ __('app-development.board.kanban') }}</a>
                @if($user->isAppDevelopmentAdmin())
                    <a href="{{ route('app-development.tasks.index', array_merge(request()->except('page', 'scope'), ['scope' => 'all'])) }}" @if($scope === 'all') aria-selected="true" @endif>{{ __('app-development.tasks.scope_all') }}</a>
                    <a href="{{ route('app-development.tasks.index', array_merge(request()->except('page', 'scope'), ['scope' => 'mine'])) }}" @if($scope === 'mine') aria-selected="true" @endif>{{ __('app-development.tasks.scope_mine') }}</a>
                @endif
            </div>
            <div class="kb-head__tools">
                <form class="kb-search" method="get" action="{{ route('app-development.tasks.index') }}" role="search">
                    <input type="hidden" name="view" value="{{ $viewMode }}">
                    <input type="hidden" name="scope" value="{{ $scope }}">
                    @foreach(request()->except('page', 'view', 'scope', 'q') as $key => $value)
                        @if(is_array($value))
                            @foreach($value as $item)
                                <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                            @endforeach
                        @elseif($value !== null && $value !== '')
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <input type="search" name="q" value="{{ $search }}" data-kb-search placeholder="{{ __('app-development.board.search') }}" autocomplete="off">
                </form>
                @can('create', \App\Models\AppDevelopment\AppDevelopmentTask::class)
                    <a href="{{ route('app-development.tasks.create') }}" class="kb-new" data-app-dev-modal>
                        @include('app-development.partials.icon', ['name' => 'plus'])
                        {{ __('app-development.nav.new_task') }}
                    </a>
                @endcan
                @if($user->isAppDevelopmentAdmin())
                    <button type="button" class="kb-icon-btn kaman-filter-chip-bell" data-notify-open="open" title="{{ __('app-development.whatsapp.notify_open') }}" aria-label="{{ __('app-development.whatsapp.notify_open') }}">
                        @include('app-development.partials.icon', ['name' => 'bell'])
                    </button>
                    <button type="button" class="kb-icon-btn kaman-filter-chip-bell" data-notify-open="qa" title="{{ __('app-development.whatsapp.notify_qa') }}" aria-label="{{ __('app-development.whatsapp.notify_qa') }}">
                        @include('app-development.partials.icon', ['name' => 'bell'])
                    </button>
                @endif
            </div>
        </header>

        <form class="kb-filters" method="get" action="{{ route('app-development.tasks.index') }}">
            <input type="hidden" name="view" value="{{ $viewMode }}">
            <input type="hidden" name="scope" value="{{ $scope }}">
            @if($search !== '')
                <input type="hidden" name="q" value="{{ $search }}">
            @endif
            <select name="assignee" aria-label="{{ __('app-development.tasks.assignee') }}">
                <option value="">{{ __('app-development.board.any_assignee') }}</option>
                @foreach($developers as $developer)
                    <option value="{{ $developer->id }}" @selected((string) ($filters['assignee'] ?? '') === (string) $developer->id)>{{ $developer->name }}</option>
                @endforeach
            </select>
            <select name="priority" aria-label="{{ __('app-development.tickets.priority') }}">
                <option value="">{{ __('app-development.board.any_priority') }}</option>
                @foreach(\App\Enums\AppDevelopmentTicketPriority::cases() as $priority)
                    <option value="{{ $priority->value }}" @selected(($filters['priority'] ?? '') === $priority->value)>{{ $priority->label() }}</option>
                @endforeach
            </select>
            <select name="status" aria-label="{{ __('app-development.tasks.status') }}">
                <option value="">{{ __('app-development.board.any_status') }}</option>
                @foreach(\App\Enums\AppDevelopmentTaskStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
            <select name="app_type[]" aria-label="{{ __('app-development.tickets.app_types') }}">
                <option value="">{{ __('app-development.board.any_app') }}</option>
                @foreach(\App\Enums\AppDevelopmentAppType::cases() as $appType)
                    <option value="{{ $appType->value }}" @selected(in_array($appType->value, $filters['app_type'] ?? [], true))>{{ $appType->label() }}</option>
                @endforeach
            </select>
            <input class="kb-text" type="text" name="ticket" value="{{ $filters['ticket'] ?? '' }}" placeholder="{{ __('app-development.tasks.ticket') }}" aria-label="{{ __('app-development.tasks.ticket') }}" dir="ltr">
            <input class="kb-text" type="date" name="due" value="{{ $filters['due'] ?? '' }}" aria-label="{{ __('app-development.tasks.due_at') }}">
            <button type="submit" class="kb-apply" aria-label="{{ __('app-development.board.filters') }}">
                @include('app-development.partials.icon', ['name' => 'check'])
            </button>
            @if($chips !== [])
                <a class="kb-reset" href="{{ route('app-development.tasks.index', ['view' => $viewMode, 'scope' => $scope]) }}" aria-label="{{ __('app-development.board.clear') }}">
                    @include('app-development.partials.icon', ['name' => 'x'])
                </a>
            @endif
        </form>

        @if($chips !== [])
            <div class="kb-chips">
                @foreach($chips as [$label, $query])
                    <a class="kb-chip" href="{{ route('app-development.tasks.index', array_merge($query, ['view' => $viewMode, 'scope' => $scope])) }}">
                        {{ $label }}
                        <span aria-hidden="true">×</span>
                    </a>
                @endforeach
            </div>
        @endif

        @if($viewMode === 'board')
            <div class="kb-jumps" data-kb-jumps>
                @foreach($columns as $column)
                    <button type="button" data-kb-jump="{{ $column['status']->value }}">
                        {{ $column['status']->label() }}
                        <b>{{ $column['tasks']->count() }}</b>
                    </button>
                @endforeach
            </div>
            <div class="kb-board" data-kb-board data-task-board>
                @foreach($columns as $column)
                    @php
                        $accent = match ($column['status']) {
                            \App\Enums\AppDevelopmentTaskStatus::Todo => '#4385fa',
                            \App\Enums\AppDevelopmentTaskStatus::InProgress => '#ff823f',
                            \App\Enums\AppDevelopmentTaskStatus::Paused => '#e7ac30',
                            \App\Enums\AppDevelopmentTaskStatus::Completed => '#20aa69',
                        };
                        $loaded = $column['tasks']->count();
                    @endphp
                    <section class="kb-column" data-kb-column data-status="{{ $column['status']->value }}" style="--kb-accent: {{ $accent }}">
                        <header class="kb-column__head">
                            <h2>{{ $column['status']->label() }}</h2>
                            <b data-kb-count>{{ $loaded }}</b>
                        </header>
                        <div class="kb-column__list" data-kb-list>
                            @foreach($column['tasks'] as $task)
                                @include('app-development.tasks.partials.card', ['task' => $task])
                            @endforeach
                            <p class="kb-empty" data-kb-empty @if($loaded > 0) hidden @endif></p>
                        </div>
                    </section>
                @endforeach
            </div>
        @else
            <div class="tk-table-wrap">
                <table class="tk-table tk-table--tasks">
                    <thead>
                        <tr>
                            <th><a href="{{ $sortLink('title') }}">{{ __('app-development.tasks.title_col') }}</a></th>
                            <th><a href="{{ $sortLink('status') }}">{{ __('app-development.tasks.status') }}</a></th>
                            <th><a href="{{ $sortLink('priority') }}">{{ __('app-development.tickets.priority') }}</a></th>
                            <th><a href="{{ $sortLink('assignee') }}">{{ __('app-development.tasks.assignee') }}</a></th>
                            <th>{{ __('app-development.board.app') }}</th>
                            <th><a href="{{ $sortLink('due') }}">{{ __('app-development.tasks.due_at') }}</a></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tasks as $task)
                            @php $app = ($task->ticket?->appTypes() ?? [])[0] ?? null; @endphp
                            <tr data-kb-open data-show-url="{{ route('app-development.tasks.show', $task) }}">
                                <td>
                                    <strong>{{ $task->title }}</strong>
                                    @if($task->ticket)
                                        <small><bdi dir="ltr">{{ $task->ticket->ticket_number }}</bdi></small>
                                    @endif
                                    @if(filled($task->restaurant_name))
                                        <small>{{ $task->restaurant_name }}</small>
                                    @endif
                                </td>
                                <td>{{ $task->status->label() }}</td>
                                <td>{{ $task->priority->label() }}</td>
                                <td>{{ $task->assignee?->name }}</td>
                                <td>{{ $app?->label() }}</td>
                                <td>@if($task->due_at)<bdi dir="ltr">{{ $task->due_at->format('Y-m-d') }}</bdi>@endif</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="tk-empty">{{ __('app-development.tasks.empty_column') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($tasks && $tasks->hasPages())
                <div class="kb-pages">{{ $tasks->links() }}</div>
            @endif
        @endif

        <dialog class="kb-drawer" data-kb-drawer aria-labelledby="kb-drawer-title">
            <header class="kb-drawer__bar">
                <p id="kb-drawer-title" data-kb-drawer-title>{{ __('app-development.board.loading') }}</p>
                <button type="button" class="kb-icon-btn" data-kb-drawer-close aria-label="{{ __('app-development.board.close') }}">
                    @include('app-development.partials.icon', ['name' => 'x'])
                </button>
            </header>
            <div class="kb-drawer__body" data-kb-drawer-body></div>
        </dialog>
        <div class="kb-toasts" data-kb-toasts aria-live="polite"></div>
    </div>

    @if($user->isAppDevelopmentAdmin())
        @include('app-development.partials.notify-picker', [
            'audience' => 'open',
            'action' => route('app-development.notify.open'),
            'title' => __('app-development.whatsapp.notify_open'),
            'hint' => __('app-development.whatsapp.pick_developers'),
            'members' => $notifyDevelopers ?? collect(),
        ])
        @include('app-development.partials.notify-picker', [
            'audience' => 'qa',
            'action' => route('app-development.notify.qa'),
            'title' => __('app-development.whatsapp.notify_qa'),
            'hint' => __('app-development.whatsapp.pick_testers'),
            'members' => $notifyTesters ?? collect(),
        ])
    @endif
@endsection

@push('scripts')
    @if(($viewMode ?? 'board') === 'board')
        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js" defer></script>
    @endif
    <script src="{{ asset('js/app-development-board.js') }}?v={{ @filemtime(public_path('js/app-development-board.js')) ?: time() }}" defer></script>
    <script src="{{ asset('js/app-development-filters.js') }}?v={{ @filemtime(public_path('js/app-development-filters.js')) ?: time() }}" defer></script>
@endpush
