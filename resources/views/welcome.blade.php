@extends('layouts.kaman')

<<<<<<< HEAD
@section('title', __('workspace.title'))
@section('tag', __('workspace.tag'))
=======
        <title>Laravel</title>
>>>>>>> parent of cd712ea (First)

@section('content')
    @php
        $user = auth()->user();
        $projects = $user->accessibleProjects();
        $isAdmin = (bool) $user->is_admin;
        $firstName = trim(explode(' ', trim($user->name))[0] ?? $user->name);
    @endphp

    <div class="page-container page-container--tight">
        <div class="mx-auto w-full max-w-6xl space-y-5">

            <section class="hero-panel hero-panel--compact">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <h1 class="text-xl sm:text-2xl font-semibold text-[#2b1e11]">
                            {{ __('workspace.hello', ['name' => $firstName]) }}
                        </h1>
                        <p class="mt-1 text-sm text-[#7c6a56]">
                            {{ __('workspace.subtitle') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <x-workspace.stat-pill
                            :label="__('workspace.stat_tools', ['count' => count($projects)])"
                            tone="orange"
                        />
                        <x-workspace.stat-pill
                            :label="$isAdmin ? __('workspace.admin') : __('app.member')"
                            :tone="$isAdmin ? 'amber' : 'slate'"
                        />
                    </div>
                </div>
            </section>

            @if(count($projects) > 0)
                <section>
                    <x-workspace.section-header
                        :title="__('workspace.your_tools')"
                        :count="count($projects)"
                        class="mb-3"
                    />

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($projects as $project)
                            <x-workspace.tool-card
                                :href="$project->url()"
                                :title="$project->label()"
                                :description="$project->description()"
                                :icon="$project->icon()"
                                :tone="$project->tone()"
                                :detail="$project->detail()"
                            />
                        @endforeach
                    </div>
                </section>
            @else
                <section class="kaman-card kaman-card--compact text-center">
                    <span class="mx-auto mb-3 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-[#f47a2e]/10 border border-[#f47a2e]/20">
                        <x-workspace.icon name="lock-closed" class="h-5 w-5 text-[#f47a2e]" />
                    </span>
                    <h2 class="text-base font-semibold text-[#2b1e11] mb-1">{{ __('workspace.no_tools_title') }}</h2>
                    <p class="mx-auto max-w-sm text-sm text-[#7c6a56]">
                        {{ __('workspace.no_tools_body') }}
                    </p>
                </section>
            @endif

            <p class="pt-1 text-center text-xs text-[#a78a6c]">
                {{ __('workspace.signed_in_as', ['email' => $user->email]) }}
            </p>
        </div>
    </div>
@endsection
