@php
    /** @var array<string,mixed> $settings */
@endphp

@extends('ai-chatbot.layout')

@section('title', 'AI Chatbot Settings')

@section('content')
    @include('ai-chatbot.settings-inner', ['settings' => $settings])
@endsection

