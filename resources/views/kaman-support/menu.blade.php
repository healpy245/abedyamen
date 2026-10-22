@php $nav = 'menu'; @endphp
@extends('layouts.support')

@section('title', __('kaman-support.ws_menu_title'))

@section('content')
    <div class="sup-head">
        <h1>{{ __('kaman-support.ws_menu_title') }}</h1>
    </div>

    <div class="sup-menu-grid" id="sup-menu">
        <section class="sup-pane sup-menu-side">
            <label class="sup-field">
                <input type="search" data-menu-search placeholder="{{ __('kaman-support.ws_search') }}" aria-label="{{ __('kaman-support.ws_search') }}">
            </label>
            <p class="sup-menu-hint" data-menu-hint>{{ __('kaman-support.ws_menu_type') }}</p>
            <div class="sup-menu-list" data-menu-list hidden></div>
            <form class="sup-list" data-menu-login>
                <p class="sup-meta" data-menu-state></p>
                <input name="username" autocomplete="username" placeholder="{{ __('kaman-support.ws_menu_user') }}" aria-label="{{ __('kaman-support.ws_menu_user') }}">
                <input name="password" type="password" autocomplete="current-password" placeholder="{{ __('kaman-support.ws_menu_password') }}" aria-label="{{ __('kaman-support.ws_menu_password') }}">
                <button class="sup-primary" type="submit">{{ __('kaman-support.ws_menu_sign_in') }}</button>
            </form>
        </section>

        <section class="sup-pane sup-agent" data-menu-agent>
            <div class="sup-agent__log" data-menu-log>
                <p class="sup-empty" data-menu-empty>{{ __('kaman-support.ws_menu_wait') }}</p>
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

    <h2 class="sup-menu-jobs">{{ __('kaman-support.ws_menu_jobs') }}</h2>
    <div class="sup-list">
        @forelse($runs as $run)
            <article class="sup-ticket">
                <strong>{{ $run->subdomain ?: $run->method_type }}</strong>
                <span>{{ $run->status }} · {{ $run->method_type }}</span>
                <span class="sup-meta">{{ $run->started_at?->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('Y-m-d H:i') }}</span>
            </article>
        @empty
            <div class="sup-empty">{{ __('kaman-support.ws_menu_empty') }}</div>
        @endforelse
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var boot = {
                auth: @json($authUrl),
                login: @json($loginUrl),
                chat: @json($chatUrl),
                host: @json($signedHost),
                thinking: @json(__('kaman-support.ws_menu_thinking')),
                need: @json(__('kaman-support.ws_menu_need_login')),
                signed: @json(__('kaman-support.ws_menu_signed')),
                type: @json(__('kaman-support.ws_menu_type')),
                none: @json(__('kaman-support.ws_menu_none')),
                logos: @json(route('kaman-support.logos')),
            };
            var choices = @json(array_values($choices));
            var restaurant = '';
            var ready = false;
            var history = [];
            var files = [];
            var sending = false;
            var list = document.querySelector('[data-menu-list]');
            var state = document.querySelector('[data-menu-state]');
            var input = document.querySelector('[data-menu-input]');
            var send = document.querySelector('[data-menu-chat] button[type="submit"]');
            var log = document.querySelector('[data-menu-log]');
            var empty = document.querySelector('[data-menu-empty]');
            var pending = document.querySelector('[data-menu-pending]');
            var token = document.querySelector('meta[name="csrf-token"]');
            token = token ? token.getAttribute('content') : '';

            function setReady(on) {
                ready = on;
                input.disabled = !on || sending;
                send.disabled = !on || sending;
                state.textContent = on ? boot.signed : boot.need;
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

            list.addEventListener('click', function (event) {
                var button = event.target.closest('[data-restaurant]');
                if (!button) return;
                restaurant = button.getAttribute('data-restaurant');
                list.querySelectorAll('button').forEach(function (item) { item.classList.toggle('is-active', item === button); });
                setReady(false);
                fetch(boot.auth, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ subdomain: restaurant, environment: 'rest' })
                }).then(function (res) { return res.json(); }).then(function (data) {
                    setReady(!!(data && data.authenticated));
                }).catch(function () { setReady(false); });
            });

            var hint = document.querySelector('[data-menu-hint]');
            var searchBox = document.querySelector('[data-menu-search]');

            function letterOf(label) {
                var text = (label || '').trim();
                return text ? text.charAt(0) : '';
            }

            function paintLogos(slugs) {
                if (!slugs.length) return;
                var query = slugs.map(function (slug) { return 'restaurants[]=' + encodeURIComponent(slug); }).join('&');
                fetch(boot.logos + '?' + query, { headers: { 'Accept': 'application/json' } })
                    .then(function (res) { return res.json(); })
                    .then(function (payload) {
                        var logos = (payload && payload.logos) || {};
                        Object.keys(logos).forEach(function (slug) {
                            if (!logos[slug]) return;
                            list.querySelectorAll('[data-logo="' + slug + '"]').forEach(function (mark) {
                                if (mark.querySelector('img')) return;
                                var img = document.createElement('img');
                                img.alt = '';
                                img.src = logos[slug];
                                mark.appendChild(img);
                            });
                        });
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
                    if (hint) {
                        hint.hidden = false;
                        hint.textContent = query === '' ? boot.type : boot.none;
                    }
                    return;
                }
                if (hint) hint.hidden = true;
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
                    if (choice.value === restaurant) button.classList.add('is-active');
                    list.appendChild(button);
                    requestAnimationFrame(function () { button.classList.remove('is-in'); });
                });
                paintLogos(matches.map(function (choice) { return choice.value; }));
            }

            searchBox.addEventListener('input', function (event) {
                renderChoices(event.target.value);
            });

            document.querySelector('[data-menu-login]').addEventListener('submit', function (event) {
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

            document.querySelector('[data-menu-file]').addEventListener('change', function (event) {
                files = Array.prototype.slice.call(event.target.files || []);
                pending.hidden = files.length === 0;
                pending.textContent = files.map(function (file) { return file.name; }).join(' · ');
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
                });
            });

            if (boot.host) {
                var match = boot.host.split('.')[0];
                searchBox.value = match;
                renderChoices(match);
                var button = list.querySelector('[data-restaurant="' + match + '"]');
                if (button) button.click();
            }
        })();
    </script>
@endpush
