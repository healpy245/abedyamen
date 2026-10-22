@php
    /** @var \App\Models\AppDevelopment\AppDevelopmentTask $task */
    $ticket = $task->ticket;
    $apps = $ticket?->appTypes() ?? [];
    $app = $apps[0] ?? null;
    $person = $task->assignee;
    $initial = $person && trim((string) $person->name) !== ''
        ? mb_strtoupper(mb_substr(trim($person->name), 0, 1))
        : '';
    $canDrag = auth()->user()?->can('update', $task) ?? false;
    $flag = in_array($task->priority->value, ['high', 'critical'], true);
@endphp

<article class="tc"
         data-kb-card
         data-task-id="{{ $task->id }}"
         data-status="{{ $task->status->value }}"
         data-show-url="{{ route('app-development.tasks.show', $task) }}"
         @if($canDrag)
             data-kb-draggable
             data-status-url="{{ route('app-development.tasks.status', $task) }}"
         @endif
         tabindex="0">
    <span class="tc-top">
        @if($ticket)
            <bdi dir="ltr" class="tc-tag">{{ $ticket->ticket_number }}</bdi>
        @endif
        @if($flag)
            <span class="tc-pin tc-pin--{{ $task->priority->value }}" title="{{ $task->priority->label() }}">
                @include('app-development.partials.icon', ['name' => $task->priority->icon()])
            </span>
        @endif
    </span>
    <span class="tc-title">{{ $task->title }}</span>
    @if(filled($task->restaurant_name))
        <span class="tc-project">{{ $task->restaurant_name }}</span>
    @endif
    @if($app)
        <span class="tc-project">
            @include('app-development.partials.icon', ['name' => 'layout'])
            <span>{{ $app->label() }}</span>
        </span>
    @endif
    <span class="tc-foot">
        @if($initial !== '')
            <span class="tc-avatar" title="{{ $person->name }}">{{ $initial }}</span>
        @else
            <span class="tc-avatar is-empty" aria-hidden="true"></span>
        @endif
        <span class="tc-status">{{ $task->status->label() }}</span>
    </span>
</article>
