@php
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    $activeProject = 'ai-chatbot';
@endphp

@extends('layouts.kaman')

@section('title', $instance->name . ' — ' . __('chatbot.internet_members'))
@section('tag', __('chatbot.tag'))

@section('content')
    <div class="flex-1 flex flex-col px-4 py-6 sm:px-6 sm:py-8">
        <div class="kaman-card mx-auto flex w-full max-w-6xl flex-1 flex-col overflow-hidden md:flex-row">
            @include('ai-chatbot.partials.sidebar', [
                'instance' => $instance,
                'instances' => $instances,
                'conversations' => $conversations,
                'activeConversation' => $activeConversation,
                'membersPage' => true,
            ])

            <section class="flex flex-1 flex-col min-h-0 min-w-0">
                <div class="border-b border-[#f1dfc5] px-5 py-3.5">
                    <p class="text-[0.65rem] uppercase tracking-[0.18em] text-[#f47a2e] font-semibold mb-0.5">
                        {{ $instance->name }}
                    </p>
                    <h1 class="text-base font-semibold text-[#2b1e11]">{{ __('chatbot.internet_members') }}</h1>
                    <p class="mt-0.5 text-xs text-[#a78a6c]">
                        {{ __('chatbot.members_desc') }}
                    </p>
                </div>

                <div class="kaman-scroll flex-1 overflow-y-auto px-5 py-5 space-y-6">
                    @if(session('status'))
                        <div class="rounded-xl border border-green-200 bg-green-50/70 px-4 py-3 text-sm text-green-700">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="rounded-xl border border-red-200 bg-red-50/70 px-4 py-3 text-sm text-red-600">
                            <ul class="list-disc list-inside space-y-0.5">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="kaman-well p-5">
                        <h2 class="text-[0.65rem] font-semibold uppercase tracking-[0.18em] text-[#a78a6c] mb-4">
                            {{ $editingMember ? __('chatbot.edit_member') : __('chatbot.add_member') }}
                        </h2>
                        @include('ai-chatbot.members.partials.form', [
                            'instance' => $instance,
                            'editingMember' => $editingMember,
                        ])
                    </div>

                    <div>
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-[0.65rem] font-semibold uppercase tracking-[0.18em] text-[#a78a6c]">
                                {{ __('chatbot.saved_members', ['count' => $members->count()]) }}
                            </h2>
                            <a href="{{ route('ai-chatbot.instances.show', $instance) }}"
                               class="text-xs font-semibold text-[#f47a2e] hover:underline">
                                {{ __('chatbot.back_to_chat') }}
                            </a>
                        </div>

                        @if($members->isEmpty())
                            <p class="kaman-well px-4 py-6 text-center text-sm text-[#a78a6c]">
                                {{ __('chatbot.no_members') }}
                            </p>
                        @else
                            <div class="kaman-scroll overflow-x-auto rounded-2xl border border-[#f1dfc5]">
                                <table class="min-w-full text-start text-xs">
                                    <thead class="bg-[#fffaf3] text-[#a78a6c] uppercase tracking-wider">
                                        <tr>
                                            <th class="px-3 py-2.5 font-semibold">{{ __('chatbot.name') }}</th>
                                            <th class="px-3 py-2.5 font-semibold">{{ __('chatbot.type') }}</th>
                                            <th class="px-3 py-2.5 font-semibold">{{ __('chatbot.national_id') }}</th>
                                            <th class="px-3 py-2.5 font-semibold">{{ __('chatbot.phone') }}</th>
                                            <th class="px-3 py-2.5 font-semibold">{{ __('chatbot.payment') }}</th>
                                            <th class="px-3 py-2.5 font-semibold">{{ __('chatbot.router') }}</th>
                                            <th class="px-3 py-2.5 font-semibold">{{ __('chatbot.added') }}</th>
                                            <th class="px-3 py-2.5 font-semibold"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[#f1dfc5] bg-white">
                                        @foreach($members as $row)
                                            <tr class="hover:bg-[#f47a2e]/5 transition">
                                                <td class="px-3 py-2.5 font-medium text-[#2b1e11]">{{ $row->name ?: __('chatbot.empty_cell') }}</td>
                                                <td class="px-3 py-2.5">
                                                    <span class="kaman-chip {{ $row->customer_type === 'subscriber' ? 'kaman-chip--success' : 'kaman-chip--muted' }} capitalize">
                                                        {{ $row->customer_type === 'subscriber' ? __('chatbot.customer_subscriber') : __('chatbot.customer_new') }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2.5 font-mono text-[#7c6a56]">{{ $row->national_id ?: __('chatbot.empty_cell') }}</td>
                                                <td class="px-3 py-2.5 text-[#7c6a56]">{{ $row->phone ?: __('chatbot.empty_cell') }}</td>
                                                <td class="px-3 py-2.5 font-mono text-[#7c6a56]">{{ $row->payment_last4 ? '****' . $row->payment_last4 : __('chatbot.empty_cell') }}</td>
                                                <td class="px-3 py-2.5 text-[#7c6a56]">{{ $row->router_type ?: __('chatbot.empty_cell') }}</td>
                                                <td class="px-3 py-2.5 whitespace-nowrap text-[#a78a6c]">{{ $row->created_at?->format('Y-m-d') }}</td>
                                                <td class="px-3 py-2.5 whitespace-nowrap">
                                                    <div class="flex items-center gap-3">
                                                        <a href="{{ route('ai-chatbot.instances.members.index', ['instance' => $instance, 'edit' => $row->id]) }}"
                                                           class="font-semibold text-[#f47a2e] hover:underline">{{ __('app.edit') }}</a>
                                                        <form method="post"
                                                              action="{{ route('ai-chatbot.instances.members.destroy', ['instance' => $instance, 'member' => $row]) }}"
                                                              onsubmit="return confirm(@json(__('chatbot.remove_member_confirm')))">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="font-semibold text-red-500 hover:underline">{{ __('app.delete') }}</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        </div>
    </div>
@endsection
