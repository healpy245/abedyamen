@php
    /** @var array<string,mixed> $settings */
    $activeProject = 'ai-chatbot';
@endphp

@extends('layouts.kaman')

@section('title', __('chatbot.settings_title'))
@section('tag', __('chatbot.settings'))

@section('content')
    @include('ai-chatbot.settings-inner', ['settings' => $settings])
@endsection
