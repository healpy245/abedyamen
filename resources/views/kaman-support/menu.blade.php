@php $nav = 'menu'; @endphp
@extends('layouts.support')

@section('title', __('kaman-support.ws_menu_title'))

@section('content')
    <div class="sup-head">
        <h1>{{ __('kaman-support.ws_menu_title') }}</h1>
    </div>

    <div class="sup-menu-grid" id="sup-menu">
        <aside class="sup-pane sup-menu-side">
            {{-- Restaurant: search until one is chosen, then a selected card with a Change button. --}}
            <div class="sup-menu-pick" data-menu-pick>
                <label class="sup-field">
                    <input type="search" data-menu-search placeholder="{{ __('kaman-support.ws_search') }}" aria-label="{{ __('kaman-support.ws_search') }}">
                </label>
                <p class="sup-menu-hint" data-menu-hint>{{ __('kaman-support.ws_menu_type') }}</p>
                <div class="sup-menu-list" data-menu-list hidden></div>
            </div>

            <div class="sup-menu-chosen" data-menu-chosen hidden>
                <span class="sup-menu-chosen__label">{{ __('kaman-support.ws_menu_selected') }}</span>
                <div class="sup-menu-chosen__card">
                    <span class="sup-menu-logo" data-menu-chosen-logo><b></b></span>
                    <span class="sup-menu-chosen__name">
                        <strong data-menu-chosen-name></strong>
                        <small data-menu-state></small>
                    </span>
                    <button type="button" class="sup-ghost sup-menu-chosen__change" data-menu-change>{{ __('kaman-support.ws_menu_change') }}</button>
                </div>
                <form class="sup-list sup-menu-login" data-menu-login hidden>
                    <input name="username" autocomplete="username" placeholder="{{ __('kaman-support.ws_menu_user') }}" aria-label="{{ __('kaman-support.ws_menu_user') }}">
                    <input name="password" type="password" autocomplete="current-password" placeholder="{{ __('kaman-support.ws_menu_password') }}" aria-label="{{ __('kaman-support.ws_menu_password') }}">
                    <button class="sup-primary" type="submit">{{ __('kaman-support.ws_menu_sign_in') }}</button>
                </form>
            </div>

            <div class="sup-menu-history">
                <div class="sup-menu-history__head">
                    <span>{{ __('kaman-support.ws_menu_chats') }}</span>
                    <button type="button" class="sup-ghost sup-menu-new" data-menu-new>+ {{ __('kaman-support.ws_menu_new_chat') }}</button>
                </div>
                <div class="sup-menu-chats" data-menu-chats></div>
                <p class="sup-menu-hint" data-menu-no-chats hidden>{{ __('kaman-support.ws_menu_no_chats') }}</p>
            </div>

            <details class="sup-menu-jobs">
                <summary>{{ __('kaman-support.ws_menu_jobs') }} <span class="sup-meta">{{ $runs->count() }}</span></summary>
                <div class="sup-menu-jobs__list">
                    @forelse($runs as $run)
                        <div class="sup-menu-job">
                            <strong>{{ $run->subdomain ?: $run->method_type }}</strong>
                            <span class="sup-meta">{{ $run->status }} · {{ $run->method_type }} · {{ $run->started_at?->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('d M H:i') }}</span>
                        </div>
                    @empty
                        <p class="sup-menu-hint">{{ __('kaman-support.ws_menu_empty') }}</p>
                    @endforelse
                </div>
            </details>
        </aside>

        <section class="sup-pane sup-agent" data-menu-agent>
            <div class="sup-agent__log" data-menu-log>
                <p class="sup-empty" data-menu-empty>{{ __('kaman-support.ws_menu_pick_first') }}</p>
            </div>
            <form class="sup-agent__composer" data-menu-chat>
                <div class="sup-agent__pending" data-menu-pending hidden></div>
                <div class="sup-agent__row">
                    <label class="sup-ghost">
                        <input type="file" data-menu-file accept="image/*,application/pdf,.json,.txt,.csv" multiple hidden>
                        {{ __('kaman-support.ws_menu_attach') }}
                    </label>
                    <textarea data-menu-input rows="2" placeholder="{{ __('kaman-support.ws_menu_placeholder') }}" disabled></textarea>
                    <button class="sup-primary" type="submit" disabled>{{ __('kaman-support.ws_menu_send') }}</button>
                </div>
            </form>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var boot = {
                auth: @json($authUrl),
                login: @json($loginUrl),
                chat: @json($chatUrl),
                chats: @json($chatsUrl),
                chatUrl: @json($chatUrlTemplate),
                host: @json($signedHost),
                thinking: @json(__('kaman-support.ws_menu_thinking')),
                need: @json(__('kaman-support.ws_menu_need_login')),
                signed: @json(__('kaman-support.ws_menu_signed')),
                type: @json(__('kaman-support.ws_menu_type')),
                none: @json(__('kaman-support.ws_menu_none')),
                wait: @json(__('kaman-support.ws_menu_wait')),
                pick: @json(__('kaman-support.ws_menu_pick_first')),
                remove: @json(__('kaman-support.ws_menu_delete_chat')),
                logos: @json(route('kaman-support.logos')),
            };
            var choices = @json(array_values($choices));
            var chats = @json($chats);
            var restaurant = '';
            var ready = false;
            var history = [];
            var chatId = null;
            var files = [];
            var sending = false;
            var logoCache = {};
            var list = document.querySelector('[data-menu-list]');
            var pick = document.querySelector('[data-menu-pick]');
            var chosen = document.querySelector('[data-menu-chosen]');
            var loginForm = document.querySelector('[data-menu-login]');
            var state = document.querySelector('[data-menu-state]');
            var input = document.querySelector('[data-menu-input]');
            var send = document.querySelector('[data-menu-chat] button[type="submit"]');
            var log = document.querySelector('[data-menu-log]');
            var empty = document.querySelector('[data-menu-empty]');
            var pending = document.querySelector('[data-menu-pending]');
            var chatList = document.querySelector('[data-menu-chats]');
            var noChats = document.querySelector('[data-menu-no-chats]');
            var hint = document.querySelector('[data-menu-hint]');
            var searchBox = document.querySelector('[data-menu-search]');
            var token = document.querySelector('meta[name="csrf-token"]');
            token = token ? token.getAttribute('content') : '';

            function chatUrl(id) { return boot.chatUrl.replace('__ID__', encodeURIComponent(id)); }

            function labelOf(slug) {
                var hit = choices.find(function (choice) { return choice.value === slug; });
                return hit ? (hit.label || hit.value) : slug;
            }

            function letterOf(label) {
                var text = (label || '').trim();
                return text ? text.charAt(0) : '';
            }

            function setReady(on) {
                ready = on;
                input.disabled = !on || sending;
                send.disabled = !on || sending;
                state.textContent = on ? boot.signed : boot.need;
                state.classList.toggle('is-ok', on);
                loginForm.hidden = on || !restaurant;
            }

            function bubble(role, text) {
                if (empty) empty.hidden = true;
                var el = document.createElement('div');
                el.className = 'sup-bubble is-' + role;
                el.textContent = text;
                log.appendChild(el);
                log.scrollTop = log.scrollHeight;
                return el;
            }

            function thinking() {
                var node = bubble('assistant', '');
                node.classList.add('is-thinking');
                node.innerHTML = '<span class="sup-ai" aria-hidden="true"><i></i><i></i><i></i></span><span class="sup-ai__label"></span>';
                node.querySelector('.sup-ai__label').textContent = boot.thinking || '';
                return node;
            }

            function settle(node, text) {
                if (!node || !text || text === boot.thinking) return;
                node.classList.remove('is-thinking');
                node.classList.add('is-reply');
                node.textContent = text;
                log.scrollTop = log.scrollHeight;
            }

            function resetLog(text) {
                log.querySelectorAll('.sup-bubble').forEach(function (node) { node.remove(); });
                empty.hidden = false;
                empty.textContent = text;
            }

            function paintLogos(slugs, scope) {
                slugs = slugs.filter(function (slug) { return !(slug in logoCache); });
                var apply = function () {
                    scope.querySelectorAll('[data-logo]').forEach(function (mark) {
                        var src = logoCache[mark.getAttribute('data-logo')];
                        if (!src || mark.querySelector('img')) return;
                        var img = document.createElement('img');
                        img.alt = '';
                        img.src = src;
                        mark.appendChild(img);
                    });
                };
                if (!slugs.length) { apply(); return; }
                var query = slugs.map(function (slug) { return 'restaurants[]=' + encodeURIComponent(slug); }).join('&');
                fetch(boot.logos + '?' + query, { headers: { 'Accept': 'application/json' } })
                    .then(function (res) { return res.json(); })
                    .then(function (payload) {
                        var logos = (payload && payload.logos) || {};
                        slugs.forEach(function (slug) { logoCache[slug] = logos[slug] || ''; });
                        apply();
                    }).catch(function () {});
            }

            function renderChoices(q) {
                var query = (q || '').trim().toLowerCase();
                var matches = query === '' ? [] : choices.filter(function (choice) {
                    return (choice.label + ' ' + choice.value).toLowerCase().indexOf(query) !== -1;
                }).slice(0, 8);
                list.innerHTML = '';
                if (!matches.length) {
                    list.hidden = true;
                    hint.hidden = false;
                    hint.textContent = query === '' ? boot.type : boot.none;
                    return;
                }
                hint.hidden = true;
                list.hidden = false;
                matches.forEach(function (choice, index) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.setAttribute('data-restaurant', choice.value);
                    button.className = 'is-in';
                    button.style.animationDelay = (index * 28) + 'ms';
                    var mark = document.createElement('span');
                    mark.className = 'sup-menu-logo';
                    mark.setAttribute('data-logo', choice.value);
                    var glyph = document.createElement('b');
                    glyph.textContent = letterOf(choice.label || choice.value);
                    mark.appendChild(glyph);
                    var name = document.createElement('span');
                    name.textContent = choice.label || choice.value;
                    button.appendChild(mark);
                    button.appendChild(name);
                    list.appendChild(button);
                    requestAnimationFrame(function () { button.classList.remove('is-in'); });
                });
                paintLogos(matches.map(function (choice) { return choice.value; }), list);
            }

            // Picking a restaurant closes the search and shows it as the selected card.
            function choose(slug, keepChat) {
                restaurant = slug;
                var label = labelOf(slug);
                pick.hidden = true;
                chosen.hidden = false;
                document.querySelector('[data-menu-chosen-name]').textContent = label;
                var mark = document.querySelector('[data-menu-chosen-logo]');
                mark.setAttribute('data-logo', slug);
                mark.querySelectorAll('img').forEach(function (img) { img.remove(); });
                mark.querySelector('b').textContent = letterOf(label);
                paintLogos([slug], chosen);
                if (!keepChat) startNewChat();
                setReady(false);
                return fetch(boot.auth, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ subdomain: slug, environment: 'rest' })
                }).then(function (res) { return res.json(); }).then(function (data) {
                    setReady(!!(data && data.authenticated));
                }).catch(function () { setReady(false); });
            }

            list.addEventListener('click', function (event) {
                var button = event.target.closest('[data-restaurant]');
                if (button) choose(button.getAttribute('data-restaurant'), false);
            });

            document.querySelector('[data-menu-change]').addEventListener('click', function () {
                chosen.hidden = true;
                pick.hidden = false;
                searchBox.value = '';
                renderChoices('');
                searchBox.focus();
            });

            searchBox.addEventListener('input', function (event) {
                renderChoices(event.target.value);
            });

            loginForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!restaurant) return;
                var body = new FormData(event.target);
                body.append('subdomain', restaurant);
                body.append('environment', 'rest');
                fetch(boot.login, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: body
                }).then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
                    .then(function (result) {
                        setReady(!!(result.ok && result.data && result.data.success !== false && result.data.authenticated !== false));
                        if (!ready && result.data && (result.data.error || result.data.message)) state.textContent = result.data.error || result.data.message;
                    }).catch(function () { setReady(false); });
            });

            // --- Chat history sidebar ---
            function renderChats() {
                chatList.innerHTML = '';
                noChats.hidden = chats.length > 0;
                chats.forEach(function (chat) {
                    var row = document.createElement('div');
                    row.className = 'sup-menu-chat' + (chat.id === chatId ? ' is-active' : '');
                    var open = document.createElement('button');
                    open.type = 'button';
                    open.className = 'sup-menu-chat__open';
                    open.setAttribute('data-chat', chat.id);
                    var title = document.createElement('strong');
                    title.textContent = chat.title;
                    var meta = document.createElement('small');
                    meta.textContent = labelOf(chat.subdomain) + ' · ' + chat.updated;
                    open.appendChild(title);
                    open.appendChild(meta);
                    var del = document.createElement('button');
                    del.type = 'button';
                    del.className = 'sup-menu-chat__delete';
                    del.setAttribute('data-chat-delete', chat.id);
                    del.setAttribute('aria-label', boot.remove);
                    del.title = boot.remove;
                    del.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
                    row.appendChild(open);
                    row.appendChild(del);
                    chatList.appendChild(row);
                });
            }

            function startNewChat() {
                chatId = null;
                history = [];
                resetLog(restaurant ? boot.wait : boot.pick);
                renderChats();
                if (restaurant && ready) input.focus();
            }

            function saveChat() {
                if (!restaurant || !history.length) return;
                var target = chatId ? chatUrl(chatId) : boot.chats;
                fetch(target, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ subdomain: restaurant, messages: history })
                }).then(function (res) { return res.ok ? res.json() : null; }).then(function (saved) {
                    if (!saved) return;
                    chatId = saved.id;
                    chats = [saved].concat(chats.filter(function (chat) { return chat.id !== saved.id; }));
                    renderChats();
                }).catch(function () {});
            }

            chatList.addEventListener('click', function (event) {
                var del = event.target.closest('[data-chat-delete]');
                if (del) {
                    var id = Number(del.getAttribute('data-chat-delete'));
                    fetch(chatUrl(id), {
                        method: 'DELETE',
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token }
                    }).then(function (res) {
                        if (!res.ok) return;
                        chats = chats.filter(function (chat) { return chat.id !== id; });
                        if (chatId === id) startNewChat(); else renderChats();
                    }).catch(function () {});
                    return;
                }
                var open = event.target.closest('[data-chat]');
                if (!open || sending) return;
                fetch(chatUrl(open.getAttribute('data-chat')), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (res) { return res.ok ? res.json() : null; })
                    .then(function (chat) {
                        if (!chat) return;
                        chatId = chat.id;
                        history = (chat.messages || []).slice();
                        resetLog(boot.wait);
                        history.forEach(function (row) { bubble(row.role, row.content); });
                        renderChats();
                        // Reopening a chat reconnects to the restaurant it was about.
                        if (chat.subdomain !== restaurant) choose(chat.subdomain, true);
                    }).catch(function () {});
            });

            document.querySelector('[data-menu-new]').addEventListener('click', startNewChat);

            document.querySelector('[data-menu-file]').addEventListener('change', function (event) {
                files = Array.prototype.slice.call(event.target.files || []);
                pending.hidden = files.length === 0;
                pending.textContent = files.map(function (file) { return file.name; }).join(' · ');
            });

            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    event.preventDefault();
                    document.querySelector('[data-menu-chat]').requestSubmit();
                }
            });

            document.querySelector('[data-menu-chat]').addEventListener('submit', function (event) {
                event.preventDefault();
                var text = input.value.trim();
                if (sending || !ready || (!text && !files.length)) return;
                sending = true;
                setReady(true);
                var agent = document.querySelector('.sup-agent');
                if (agent) agent.classList.add('is-busy');
                bubble('user', text || files.map(function (file) { return file.name; }).join(', '));
                history.push({ role: 'user', content: text });
                input.value = '';
                var status = thinking();
                var body = new FormData();
                history.forEach(function (row, index) {
                    body.append('messages[' + index + '][role]', row.role);
                    body.append('messages[' + index + '][content]', row.content);
                });
                files.forEach(function (file) {
                    body.append('attachments[]', file, file.name);
                    body.append('attachment_roles[]', 'menu');
                });
                files = [];
                pending.hidden = true;
                fetch(boot.chat, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'text/event-stream, application/json', 'X-CSRF-TOKEN': token },
                    body: body
                }).then(function (res) {
                    var type = (res.headers.get('content-type') || '').toLowerCase();
                    if (!type.includes('text/event-stream') || !res.body) {
                        return res.json().then(function (data) {
                            settle(status, (data && (data.error || data.message)) || boot.need);
                        });
                    }
                    var reader = res.body.getReader();
                    var decoder = new TextDecoder();
                    var buffer = '';
                    function pump() {
                        return reader.read().then(function (chunk) {
                            if (chunk.done) return;
                            buffer += decoder.decode(chunk.value, { stream: true });
                            var parts = buffer.split('\n\n');
                            buffer = parts.pop() || '';
                            parts.forEach(function (part) {
                                var match = part.match(/^data:\s*(.+)$/m);
                                if (!match) return;
                                try {
                                    var ev = JSON.parse(match[1]);
                                    if (ev.event === 'message' && ev.reply) settle(status, ev.reply);
                                    else if (ev.message) settle(status, ev.message);
                                    else if (ev.summary) settle(status, ev.summary);
                                } catch (e) {}
                            });
                            return pump();
                        });
                    }
                    return pump();
                }).catch(function () {
                    settle(status, boot.need);
                }).then(function () {
                    if (status.textContent && status.textContent !== boot.thinking && !status.classList.contains('is-thinking')) {
                        history.push({ role: 'assistant', content: status.textContent });
                    }
                    sending = false;
                    if (agent) agent.classList.remove('is-busy');
                    setReady(true);
                    saveChat();
                });
            });

            renderChats();
            paintLogos([], chatList);

            if (boot.host) {
                var signed = boot.host.split('.')[0];
                if (choices.some(function (choice) { return choice.value === signed; })) choose(signed, false);
            }
        })();
    </script>
@endpush
