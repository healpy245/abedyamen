@php
    $commands = $commands ?? collect();
    $commandNames = $commandNames ?? [];
@endphp

<section class="kaman-card kaman-card--compact space-y-3">
    <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.jobs_title') }}</h2>
    <p class="text-sm text-[#7c6a56]">{{ __('kaman-support.jobs_hint') }}</p>

    <form method="post" action="{{ $action }}" class="space-y-3">
        @csrf
        <label class="flex flex-col gap-1 text-xs font-medium text-[#7c6a56]">
            {{ __('kaman-support.jobs_name') }}
            <select name="name" required class="kaman-input">
                @foreach($commandNames as $command)
                    <option value="{{ $command->value }}">{{ $command->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-[#7c6a56]">
            {{ __('kaman-support.jobs_printer') }}
            <input type="text" name="args[printer]" class="kaman-input" placeholder="{{ __('kaman-support.jobs_printer_hint') }}">
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-[#7c6a56]">
            {{ __('kaman-support.jobs_ip') }}
            <input type="text" name="args[ip]" class="kaman-input" placeholder="10.0.0.3">
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-[#7c6a56]">
            {{ __('kaman-support.jobs_order') }}
            <input type="number" name="args[order_number]" class="kaman-input" min="1">
        </label>
        <button type="submit" class="kaman-button w-full">{{ __('kaman-support.jobs_send') }}</button>
    </form>

    @if($commands->isNotEmpty())
        <ol class="space-y-2 text-sm">
            @foreach($commands as $command)
                <li class="rounded-md border border-[#eadfce] px-3 py-2">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-[#a78a6c]">
                        <span class="font-semibold text-[#c2410c]">{{ $command->name?->label() ?? $command->name }}</span>
                        <span class="font-mono">{{ $command->public_id }}</span>
                        <span>{{ $command->status?->value }}</span>
                    </div>
                    @if(is_array($command->result) && $command->result !== [])
                        <pre class="mt-1 whitespace-pre-wrap break-words text-xs text-[#3f2f1d]">{{ json_encode($command->result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</section>
