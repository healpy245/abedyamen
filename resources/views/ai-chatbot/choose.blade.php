@php
    $activeProject = 'ai-chatbot';
@endphp

@extends('layouts.kaman')

@section('title', __('chatbot.choose.title'))
@section('tag', __('chatbot.choose.tag'))

@section('content')
    <div class="page-container page-container--tight">
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <section class="hero-panel hero-panel--compact">
                <div class="min-w-0">
                    <h1 class="text-xl sm:text-2xl font-semibold text-[#2b1e11]">
                        {{ __('chatbot.choose.title') }}
                    </h1>
                    <p class="mt-1 text-sm text-[#7c6a56]">
                        {{ __('chatbot.choose.subtitle') }}
                    </p>
                </div>
            </section>

            <section>
                <x-workspace.section-header
                    :title="__('chatbot.choose.bots')"
                    :count="$instances->count()"
                    class="mb-3"
                />

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($instances as $bot)
                        <x-workspace.tool-card
                            :href="route('ai-chatbot.workspace.conversations', $bot)"
                            :title="$bot->name"
                            :description="$bot->workspaceDescription()"
                            :icon="$bot->workspaceIcon()"
                            :tone="$bot->workspaceTone()"
                            :status="$bot->isBotGloballyActive() ? __('chatbot.workspace.bot_on') : __('chatbot.workspace.bot_off')"
                            :detail="$bot->workspaceDescription()"
                        />
                    @endforeach
                </div>
            </section>
        </div>
    </div>
@endsection
