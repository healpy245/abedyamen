/**
 * Customer workspace chat — polling + reply + Live AI Instructor.
 * Designed so endpoints can later be swapped for WebSockets without rewriting UI.
 */
(function () {
    'use strict';

    const MESSAGE_POLL_MS = 1500;
    const LIST_POLL_MS = 4000;
    const ANALYTICS_POLL_MS = 5000;

    const WorkspaceChat = {
        listTimer: null,
        messageTimer: null,

        toast(elRoot, text) {
            const toast = elRoot.querySelector('#toast') || document.getElementById('toast');
            if (!toast) return;
            toast.textContent = text;
            toast.classList.remove('hidden');
            clearTimeout(toast._t);
            toast._t = setTimeout(() => toast.classList.add('hidden'), 2800);
        },

        csrf(el) {
            return el.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        visible() {
            return document.visibilityState !== 'hidden';
        },

        modeLabel(root, mode) {
            try {
                const labels = JSON.parse(root.dataset.modeLabels || '{}');
                return labels[mode] || String(mode || '').replace('_', ' ');
            } catch (_) {
                return String(mode || '').replace('_', ' ');
            }
        },

        paintBotMode(root, mode) {
            const badge = root.querySelector('#bot-mode-badge');
            if (badge && mode) {
                badge.dataset.mode = mode;
                badge.textContent = this.modeLabel(root, mode);
                badge.classList.remove(
                    'bg-emerald-50', 'text-emerald-700', 'border-emerald-200',
                    'bg-amber-50', 'text-amber-700', 'border-amber-200',
                    'bg-sky-50', 'text-sky-700', 'border-sky-200'
                );
                if (mode === 'active') {
                    badge.classList.add('bg-emerald-50', 'text-emerald-700', 'border-emerald-200');
                } else if (mode === 'paused') {
                    badge.classList.add('bg-amber-50', 'text-amber-700', 'border-amber-200');
                } else {
                    badge.classList.add('bg-sky-50', 'text-sky-700', 'border-sky-200');
                }
            }

            const toggle = root.querySelector('#chat-bot-toggle');
            if (toggle) {
                const isOn = mode === 'active';
                toggle.setAttribute('aria-pressed', isOn ? 'true' : 'false');
                toggle.textContent = isOn
                    ? (toggle.dataset.onLabel || 'Chat bot: ON')
                    : (toggle.dataset.offLabel || 'Chat bot: OFF');
            }
        },

        bindMarkUserCreated() {
            if (this.markUserBound) return;
            this.markUserBound = true;
            document.addEventListener('click', (event) => {
                const button = event.target.closest('.mark-user-created');
                if (!button || button.disabled) return;
                event.preventDefault();
                event.stopPropagation();
                const root = document.getElementById('workspace-list-pane');
                const labels = root ? this.listLabels(root) : {};
                const confirmText = labels.mark_user_created_confirm || '';
                if (confirmText && !window.confirm(confirmText)) return;
                const csrf = document.querySelector('[data-csrf]')?.dataset.csrf || '';
                button.disabled = true;
                fetch(button.dataset.url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                }).then(async (res) => {
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || data.ok === false) {
                        button.disabled = false;
                        window.alert(data.error || data.delivery?.error || 'تعذر إرسال الرسالة.');
                        return;
                    }
                    const id = button.dataset.id;
                    document.querySelectorAll(`.mark-user-created[data-id="${id}"]`).forEach((el) => el.remove());
                }).catch(() => {
                    button.disabled = false;
                    window.alert('تعذر إرسال الرسالة.');
                });
            });
        },

        markUserButton(root, c) {
            if (!c.waiting_account || !c.mark_user_url) return '';
            const labels = this.listLabels(root);
            return `<button type="button" class="mark-user-created shrink-0 self-center me-2 rounded-full border border-[#f47a2e]/40 bg-[#f47a2e]/10 px-2 py-1 text-[10px] font-semibold text-[#f16229] hover:bg-[#f47a2e]/20" data-url="${this.escapeHtml(c.mark_user_url)}" data-id="${this.escapeHtml(String(c.id))}">${this.escapeHtml(labels.mark_user_created || 'تم إنشاء المستخدم')}</button>`;
        },

        startListPolling(root) {
            this.bindMarkUserCreated();
            this.bindConversationOpen(root);
            if (!root) return;
            let pollSeq = 0;
            let abortCtrl = null;

            const poll = async () => {
                if (!this.visible()) return;
                const requestedInsight = root.dataset.insight || 'all';
                const filtering = requestedInsight !== 'all';
                const seq = ++pollSeq;

                if (abortCtrl) {
                    try { abortCtrl.abort(); } catch (_) {}
                }
                abortCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;

                const url = new URL(root.dataset.pollUrl, window.location.origin);
                url.searchParams.set('filter', root.dataset.filter || 'all');
                url.searchParams.set('q', root.dataset.q || '');
                if (root.dataset.line) {
                    url.searchParams.set('line', root.dataset.line);
                }
                if (filtering) {
                    url.searchParams.set('insight', requestedInsight);
                } else if (root.dataset.since) {
                    url.searchParams.set('since', root.dataset.since);
                }
                try {
                    const res = await fetch(url.toString(), {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                        signal: abortCtrl?.signal,
                    });
                    if (!res.ok) return;
                    const data = await res.json();

                    // Drop stale responses (e.g. unfiltered poll finishing after a card tap).
                    if (seq !== pollSeq) return;
                    this.paintLineHealth(data.lines);
                    const currentInsight = root.dataset.insight || 'all';
                    if (currentInsight !== requestedInsight) return;

                    if (filtering || !root.dataset.since) {
                        this.replaceList(root, data.conversations || []);
                        if (!filtering && data.server_time) {
                            root.dataset.since = data.server_time;
                        }
                    } else {
                        this.mergeList(root, data.conversations || [], true);
                        if (data.server_time) root.dataset.since = data.server_time;
                    }
                    this.paintLiveDot(true);
                } catch (err) {
                    if (err && err.name === 'AbortError') return;
                    this.paintLiveDot(false);
                }
            };
            this.pollListNow = poll;
            poll();
            this.listTimer = setInterval(poll, LIST_POLL_MS);
            document.addEventListener('visibilitychange', () => {
                if (this.visible()) poll();
            });
        },

        replaceList(root, conversations) {
            const list = root.querySelector('#conversation-list');
            if (!list) return;
            const activeId = document.querySelector('#workspace-chat-live')?.dataset?.conversationId
                || document.querySelector('#workspace-chat')?.dataset?.conversationId
                || new URLSearchParams(window.location.search).get('conversation')
                || '';
            list.innerHTML = '';
            if (!conversations.length) {
                const empty = document.createElement('p');
                empty.id = 'list-empty';
                empty.className = 'p-4 text-xs text-[#a78a6c]';
                const filtering = (root.dataset.insight || 'all') !== 'all';
                empty.textContent = (filtering ? root.dataset.filterEmptyLabel : '')
                    || root.dataset.emptyLabel
                    || 'No chats in this group.';
                list.appendChild(empty);
                return;
            }
            conversations.forEach((c) => {
                const row = this.buildListRow(root, c);
                if (activeId && String(c.id) === String(activeId)) {
                    row.classList.add('bg-white', 'border-s-4', 'border-s-[#f47a2e]');
                }
                list.appendChild(row);
            });
        },

        setInsightFilter(listRoot, insight, label) {
            if (!listRoot) return;
            const next = insight && insight !== 'all' ? String(insight) : 'all';
            listRoot.dataset.insight = next;
            delete listRoot.dataset.since;

            const clearBtn = document.getElementById('insight-filter-clear');
            const labelEl = document.getElementById('insight-filter-label');
            if (clearBtn) clearBtn.classList.toggle('hidden', next === 'all');
            if (labelEl) {
                if (next === 'all') {
                    labelEl.classList.add('hidden');
                    labelEl.textContent = '';
                } else {
                    labelEl.classList.remove('hidden');
                    labelEl.textContent = label || next;
                }
            }

            document.querySelectorAll('.analytics-card[data-insight-filter]').forEach((card) => {
                const on = next !== 'all' && card.dataset.insightFilter === next;
                card.setAttribute('aria-pressed', on ? 'true' : 'false');
                card.classList.toggle('ring-2', on);
                card.classList.toggle('ring-[#f47a2e]', on);
                card.classList.toggle('shadow-sm', on);
            });

            const pageUrl = new URL(window.location.href);
            if (next === 'all') pageUrl.searchParams.delete('insight');
            else pageUrl.searchParams.set('insight', next);
            if (pageUrl.href !== window.location.href) {
                history.replaceState(history.state, '', pageUrl);
            }

            const pager = listRoot.querySelector('#conversation-list-pager');
            if (pager) pager.classList.toggle('hidden', next !== 'all');

            // Instant UI filter from cached conversation ids (before API returns).
            this.applyInsightFilterLocally(listRoot, next);

            if (typeof this.pollListNow === 'function') {
                this.pollListNow();
            }
        },

        applyInsightFilterLocally(listRoot, insight) {
            const list = listRoot?.querySelector('#conversation-list');
            if (!list) return;

            if (!insight || insight === 'all') {
                list.querySelectorAll('.conversation-row').forEach((row) => {
                    row.classList.remove('hidden');
                    row.style.display = '';
                });
                list.querySelector('#list-empty')?.remove();
                return;
            }

            let ids = [];
            try {
                const map = JSON.parse(listRoot.dataset.insightConversationIds || '{}');
                ids = Array.isArray(map[insight]) ? map[insight] : [];
            } catch (_) {
                ids = [];
            }
            const allow = new Set(ids.map((id) => String(id)));
            let visible = 0;
            list.querySelectorAll('.conversation-row').forEach((row) => {
                const show = allow.has(String(row.dataset.id || ''));
                row.classList.toggle('hidden', !show);
                row.style.display = show ? '' : 'none';
                if (show) visible += 1;
            });

            let empty = list.querySelector('#list-empty');
            if (visible === 0) {
                if (!empty) {
                    empty = document.createElement('p');
                    empty.id = 'list-empty';
                    empty.className = 'p-4 text-xs text-[#a78a6c]';
                    list.appendChild(empty);
                }
                empty.textContent = listRoot.dataset.filterEmptyLabel || 'No chats in this group.';
                empty.classList.remove('hidden');
            } else if (empty) {
                empty.remove();
            }
        },

        bindInsightFilters(pageRoot, listRoot) {
            if (!listRoot || listRoot.dataset.insightBound === '1') return;
            listRoot.dataset.insightBound = '1';

            const apply = (insight, label) => {
                const current = listRoot.dataset.insight || 'all';
                if (insight && insight === current) {
                    this.setInsightFilter(listRoot, 'all', '');
                    return;
                }
                this.setInsightFilter(listRoot, insight || 'all', label || '');
            };

            const onActivate = (e) => {
                const card = e.target.closest?.('.analytics-card[data-insight-filter]');
                if (!card) return;
                if (e.target.closest('.analytics-download')) return;
                e.preventDefault();
                e.stopPropagation();
                apply(card.dataset.insightFilter, card.dataset.insightLabel || '');
            };

            // Delegation so desktop + mobile cards always work after re-renders.
            document.addEventListener('click', onActivate);
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                const card = e.target.closest?.('.analytics-card[data-insight-filter]');
                if (!card) return;
                if (e.target.closest('.analytics-download')) return;
                e.preventDefault();
                apply(card.dataset.insightFilter, card.dataset.insightLabel || '');
            });

            document.getElementById('insight-filter-clear')?.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.setInsightFilter(listRoot, 'all', '');
            });

            const initial = listRoot.dataset.insight || 'all';
            if (initial !== 'all') {
                const card = pageRoot.querySelector(`.analytics-card[data-insight-filter="${initial}"]`);
                this.setInsightFilter(listRoot, initial, card?.dataset.insightLabel || initial);
            }
        },
        startAnalyticsPolling(root) {
            if (!root?.dataset?.analyticsUrl) return;
            const listRoot = document.getElementById('workspace-list-pane');
            const poll = async () => {
                if (!this.visible()) return;
                try {
                    const res = await fetch(root.dataset.analyticsUrl, {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                    });
                    if (!res.ok) return;
                    const data = await res.json();
                    const analytics = data.analytics || {};
                    Object.keys(analytics).forEach((key) => {
                        const value = analytics[key];
                        if (value === undefined || value === null || typeof value === 'object') return;
                        document.querySelectorAll(`[data-analytics="${key}"]`).forEach((el) => {
                            el.textContent = String(value);
                        });
                    });
                    if (listRoot && analytics.conversation_ids) {
                        listRoot.dataset.insightConversationIds = JSON.stringify(analytics.conversation_ids);
                        const insight = listRoot.dataset.insight || 'all';
                        if (insight !== 'all') {
                            this.applyInsightFilterLocally(listRoot, insight);
                        }
                    }
                    this.paintLiveDot(true);
                } catch (_) {
                    this.paintLiveDot(false);
                }
            };
            poll();
            this.analyticsTimer = setInterval(poll, ANALYTICS_POLL_MS);
            document.addEventListener('visibilitychange', () => {
                if (this.visible()) poll();
            });
        },

        paintLineHealth(lines) {
            if (!Array.isArray(lines)) return;
            const alerts = [];
            lines.forEach((line) => {
                const id = String(line.id ?? '');
                document.querySelectorAll(`[data-line-stat="${id}"]`).forEach((el) => {
                    const count = el.querySelector('[data-triggered]');
                    const risk = el.querySelector('[data-risk]');
                    if (count) count.textContent = String(line.triggered ?? 0);
                    const today = el.querySelector('[data-today]');
                    if (today) today.textContent = 'اليوم ' + String(line.today ?? 0) + '/' + String(line.cap || 24);
                    if (risk) {
                        risk.textContent = String(line.percent ?? 0) + '%';
                        risk.title = line.level_label || '';
                        risk.classList.remove('is-low', 'is-medium', 'is-high', 'is-critical', 'is-dead');
                        risk.classList.add('is-' + (line.level || 'low'));
                    }
                });
                document.querySelectorAll(`[data-line-dot="${id}"]`).forEach((dot) => {
                    const dead = !!line.alert;
                    dot.classList.toggle('is-dead', dead);
                    dot.classList.toggle('is-on', !dead && !!line.active);
                });
                if (line.alert) alerts.push(line);
            });
            const box = document.getElementById('farz-dead');
            if (box) {
                if (!alerts.length) {
                    box.hidden = true;
                    box.innerHTML = '';
                } else {
                    box.hidden = false;
                    box.innerHTML = '<strong>تنبيه</strong>' + alerts.map((line) => {
                        const who = line.phone || line.label || ('#' + line.id);
                        return `<p><b dir="ltr">${this.escapeHtml(who)}</b><span>${this.escapeHtml(line.alert)}</span></p>`;
                    }).join('');
                }
            }
            this.paintPace(lines);
        },

        paintPace(lines) {
            const pace = document.getElementById('farz-pace');
            if (!pace || !Array.isArray(lines)) return;
            if (!lines.length) {
                pace.hidden = true;
                pace.innerHTML = '';
                return;
            }
            const cap = lines[0].cap || 24;
            const ranked = lines.slice().sort((a, b) => (b.percent || 0) - (a.percent || 0));
            const title = pace.dataset.paceTitle || 'حد اليوم';
            const note = pace.dataset.paceNote || '';
            pace.hidden = false;
            pace.innerHTML = `<strong>${this.escapeHtml(title)} · ${this.escapeHtml(String(cap))}</strong>`
                + (note ? `<em>${this.escapeHtml(note)}</em>` : '')
                + ranked.map((line) => {
                    const who = line.phone || line.label || ('#' + line.id);
                    const level = line.level || 'low';
                    return `<p data-line-pace="${this.escapeHtml(String(line.id))}"><b dir="ltr">${this.escapeHtml(who)}</b><span data-pace-rate>${this.escapeHtml(String(line.today ?? 0))}/${this.escapeHtml(String(line.cap || cap))}</span><i class="farz-risk is-${this.escapeHtml(level)}" data-pace-risk title="${this.escapeHtml(line.level_label || '')}">${this.escapeHtml(String(line.percent ?? 0))}%</i></p>`;
                }).join('');
        },

        paintLiveDot(ok) {
            document.querySelectorAll('#live-sync-dot span[aria-hidden="true"], .live-sync-dot span[aria-hidden="true"]').forEach((dot) => {
                dot.classList.toggle('bg-emerald-500', !!ok);
                dot.classList.toggle('bg-amber-500', !ok);
            });
        },

        escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        formatListTime(iso) {
            if (!iso) return '';
            try {
                return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } catch (_) {
                return '';
            }
        },

        listLabels(root) {
            try {
                return JSON.parse(root.dataset.listLabels || '{}');
            } catch (_) {
                return {};
            }
        },

        buildListRow(root, c) {
            const labels = this.listLabels(root);
            const badges = [];
            const unread = Number(c.unread_count || 0);
            if (unread > 0) {
                badges.push(`<span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-[#f47a2e] text-white text-[10px] font-bold">${unread}</span>`);
            }
            if (c.bot_mode === 'human_takeover') {
                badges.push(`<span class="text-[10px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-1.5 py-0.5">${this.escapeHtml(labels.mode_human || 'Human')}</span>`);
            } else if (c.bot_mode === 'paused') {
                badges.push(`<span class="text-[10px] font-semibold text-slate-600 bg-slate-50 border border-slate-200 rounded-full px-1.5 py-0.5">${this.escapeHtml(labels.mode_paused || 'Paused')}</span>`);
            }
            if (c.attention_status === 'needs_attention') {
                badges.push(`<span class="text-[10px] font-semibold text-rose-700 bg-rose-50 border border-rose-200 rounded-full px-1.5 py-0.5">${this.escapeHtml(labels.needs_attention || 'Needs attention')}</span>`);
            }

            const row = document.createElement('div');
            row.className = 'conversation-row flex items-stretch border-b border-[#eadfce]/80 hover:bg-white/70 transition';
            row.dataset.id = String(c.id);
            row.dataset.updated = c.updated_at || '';
            row.dataset.unread = String(unread);
            const phone = c.contact_phone ? `<p class="text-[11px] text-[#a78a6c] truncate mt-0.5" dir="ltr">${this.escapeHtml(c.contact_phone)}</p>` : '';
            row.innerHTML = `
                <a href="${this.escapeHtml(c.url || '#')}" class="flex gap-3 flex-1 min-w-0 px-3 py-3">
                    <div class="w-11 h-11 rounded-full bg-[#f1dfc5] text-[#7c6a56] flex items-center justify-center text-sm font-bold shrink-0" aria-hidden="true">${this.escapeHtml(c.initials || '?')}</div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-semibold text-[#2b1e11] truncate">${this.escapeHtml(c.display_name || '')}</p>
                            <time class="text-[10px] text-[#a78a6c] shrink-0" datetime="${this.escapeHtml(c.last_message_at || '')}">${this.escapeHtml(this.formatListTime(c.last_message_at))}</time>
                        </div>
                        ${phone}
                        <p class="text-xs text-[#7c6a56] truncate mt-0.5">${this.escapeHtml(c.preview || '')}</p>
                        <div class="mt-1 flex flex-wrap gap-1 row-badges">${badges.join('')}</div>
                    </div>
                </a>
                ${this.markUserButton(root, c)}`;
            return row;
        },

        paintListRow(root, row, c) {
            const preview = row.querySelector('p.text-xs');
            if (preview && typeof c.preview === 'string') preview.textContent = c.preview;

            const name = row.querySelector('p.text-sm');
            if (name && c.display_name) name.textContent = c.display_name;

            const time = row.querySelector('time');
            if (time && c.last_message_at) {
                time.setAttribute('datetime', c.last_message_at);
                time.textContent = this.formatListTime(c.last_message_at);
            }

            row.dataset.updated = c.updated_at || row.dataset.updated;
            if (typeof c.unread_count === 'number') {
                row.dataset.unread = String(c.unread_count);
            }

            let badges = row.querySelector('.row-badges');
            if (!badges) {
                badges = document.createElement('div');
                badges.className = 'mt-1 flex flex-wrap gap-1 row-badges';
                row.querySelector('.min-w-0')?.appendChild(badges);
            }

            const labels = this.listLabels(root);
            const parts = [];
            const unread = Number(c.unread_count || 0);
            if (unread > 0) {
                parts.push(`<span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-[#f47a2e] text-white text-[10px] font-bold">${unread}</span>`);
            }
            if (c.bot_mode === 'human_takeover') {
                parts.push(`<span class="text-[10px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-1.5 py-0.5">${this.escapeHtml(labels.mode_human || 'Human')}</span>`);
            } else if (c.bot_mode === 'paused') {
                parts.push(`<span class="text-[10px] font-semibold text-slate-600 bg-slate-50 border border-slate-200 rounded-full px-1.5 py-0.5">${this.escapeHtml(labels.mode_paused || 'Paused')}</span>`);
            }
            if (c.attention_status === 'needs_attention') {
                parts.push(`<span class="text-[10px] font-semibold text-rose-700 bg-rose-50 border border-rose-200 rounded-full px-1.5 py-0.5">${this.escapeHtml(labels.needs_attention || 'Needs attention')}</span>`);
            }
            badges.innerHTML = parts.join('');

            const existingMark = row.querySelector(':scope > .mark-user-created');
            const markHtml = this.markUserButton(root, c);
            if (markHtml && !existingMark) {
                row.insertAdjacentHTML('beforeend', markHtml);
            } else if (!markHtml && existingMark) {
                existingMark.remove();
            }
        },

        mergeList(root, conversations, insertMissing = false) {
            const list = root.querySelector('#conversation-list');
            if (!list || !conversations.length) return;

            // Newest first from the API — prepend in reverse so order stays correct.
            const ordered = conversations.slice().reverse();
            ordered.forEach((c) => {
                let row = list.querySelector(`.conversation-row[data-id="${c.id}"]`);
                if (!row) {
                    if (!insertMissing) return;
                    row = this.buildListRow(root, c);
                    list.prepend(row);
                    list.querySelector('#list-empty')?.remove();
                    return;
                }
                this.paintListRow(root, row, c);
                list.prepend(row);
            });
        },

        bindConversationOpen(root) {
            if (!root || root.dataset.openBound === '1') return;
            root.dataset.openBound = '1';
            root.addEventListener('click', (event) => {
                const link = event.target.closest?.('a[href]');
                if (!link || !root.contains(link) || !link.closest('.conversation-row')) return;
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
                if (window.matchMedia('(max-width: 767px)').matches) {
                    const insight = root.dataset.insight || 'all';
                    if (insight === 'all') return;
                    event.preventDefault();
                    const url = new URL(link.href, window.location.origin);
                    url.searchParams.set('insight', insight);
                    window.location.assign(url.toString());
                    return;
                }
                event.preventDefault();
                this.openConversation(link.href, root);
            });
            if (!this.boundConversationPop) {
                this.boundConversationPop = true;
                window.addEventListener('popstate', () => {
                    if (/\/conversations\/\d+/.test(window.location.pathname)) {
                        this.openConversation(window.location.href, document.getElementById('workspace-list-pane'), false);
                        return;
                    }
                    this.closeConversation();
                });
            }
        },

        async openConversation(href, listRoot, push = true) {
            const list = listRoot || document.getElementById('workspace-list-pane');
            const fetchUrl = new URL(href, window.location.origin);
            const insight = list?.dataset.insight || fetchUrl.searchParams.get('insight') || 'all';
            if (insight !== 'all') fetchUrl.searchParams.set('insight', insight);
            else fetchUrl.searchParams.delete('insight');
            const shown = new URL(fetchUrl.href);
            fetchUrl.searchParams.set('pane', '1');
            try {
                const res = await fetch(fetchUrl.toString(), {
                    headers: { Accept: 'application/json', 'X-Chat-Pane': '1' },
                    cache: 'no-store',
                });
                if (!res.ok) throw new Error('pane');
                const data = await res.json();
                if (!data.html) throw new Error('pane');
                this.mountConversation(data.html);
                if (list && data.id) this.markOpenRow(list, data.id);
                if (push) history.pushState({ pane: true }, '', shown);
            } catch (_) {
                window.location.assign(shown.toString());
            }
        },

        mountConversation(html) {
            const wrap = document.createElement('div');
            wrap.innerHTML = html.trim();
            const pane = wrap.querySelector('#workspace-chat-live');
            const current = document.getElementById('workspace-chat-live') || document.getElementById('workspace-chat-slot');
            if (!pane || !current) return;
            if (current.id === 'workspace-chat-slot' && !this.emptySlotHtml) {
                this.emptySlotHtml = current.outerHTML;
            }
            current.replaceWith(pane);
            const host = document.getElementById('workspace-chat');
            if (host) {
                Object.assign(host.dataset, pane.dataset);
                this.bindComposer(host);
                this.bindBotMode(host);
                this.bindInstructor(host);
                this.scrollThreadToBottom(host, true);
                if (host.dataset.readUrl) {
                    fetch(host.dataset.readUrl, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf(host) },
                    }).catch(() => {});
                }
                return;
            }
            this.startConversation(pane);
        },

        closeConversation() {
            const current = document.getElementById('workspace-chat-live');
            if (!current || !this.emptySlotHtml) return;
            if (this.messageTimer) {
                clearInterval(this.messageTimer);
                this.messageTimer = null;
            }
            current.outerHTML = this.emptySlotHtml;
            document.querySelectorAll('#conversation-list .conversation-row').forEach((row) => {
                row.classList.remove('bg-white', 'border-s-4', 'border-s-[#f47a2e]');
            });
        },

        markOpenRow(listRoot, id) {
            listRoot.querySelectorAll('.conversation-row').forEach((row) => {
                const on = String(row.dataset.id || '') === String(id);
                row.classList.toggle('bg-white', on);
                row.classList.toggle('border-s-4', on);
                row.classList.toggle('border-s-[#f47a2e]', on);
            });
        },

        startConversation(root) {
            if (!root) return;
            if (this.messageTimer) {
                clearInterval(this.messageTimer);
                this.messageTimer = null;
            }
            this.bindComposer(root);
            this.bindBotMode(root);
            this.bindInstructor(root);
            this.bindDrawer(root);
            this.bindInstructorCollapse(root);
            this.bindThreadScrollPassthrough(root);
            this.scrollThreadToBottom(root, true);

            fetch(root.dataset.readUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': this.csrf(root),
                },
            }).catch(() => {});

            const poll = async () => {
                if (!this.visible()) return;
                const after = root.dataset.lastMessageId || '0';
                const url = new URL(root.dataset.messagesUrl, window.location.origin);
                url.searchParams.set('after_id', after);
                try {
                    const res = await fetch(url.toString(), {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                    });
                    if (!res.ok) return;
                    const data = await res.json();
                    const hadNew = (data.messages || []).length > 0;
                    const nearBottom = this.isThreadNearBottom(root);
                    (data.messages || []).forEach((m) => this.appendMessage(root, m, { autoScroll: false }));
                    if (hadNew && nearBottom) {
                        this.scrollThreadToBottom(root);
                    }
                    if (data.conversation?.bot_mode) {
                        this.paintBotMode(root, data.conversation.bot_mode);
                    }
                    const syncDot = root.querySelector('#live-sync-dot span');
                    if (syncDot) {
                        syncDot.classList.toggle('bg-emerald-500', true);
                        syncDot.classList.toggle('bg-amber-500', false);
                    }
                } catch (_) {
                    const syncDot = root.querySelector('#live-sync-dot span');
                    if (syncDot) {
                        syncDot.classList.toggle('bg-emerald-500', false);
                        syncDot.classList.toggle('bg-amber-500', true);
                    }
                }
            };

            this.pollConversation = () => poll();
            poll();
            this.messageTimer = setInterval(poll, MESSAGE_POLL_MS);
            if (!this.boundConversationVisibility) {
                this.boundConversationVisibility = true;
                document.addEventListener('visibilitychange', () => {
                    if (this.visible()) this.pollConversation?.();
                });
            }
        },

        isThreadNearBottom(root, threshold = 120) {
            const thread = root?.querySelector('#message-thread');
            if (!thread) return true;
            return (thread.scrollHeight - thread.scrollTop - thread.clientHeight) <= threshold;
        },

        scrollThreadToBottom(root, force = false) {
            const thread = root?.querySelector('#message-thread');
            if (!thread) return;
            if (!force && !this.isThreadNearBottom(root)) return;

            const go = () => {
                // Only scroll the thread itself — never scrollIntoView (that traps/jumps the page).
                thread.scrollTop = thread.scrollHeight;
                const anchor = thread.querySelector('#thread-bottom-anchor');
                if (anchor) {
                    thread.scrollTop = Math.max(thread.scrollHeight, anchor.offsetTop + 40);
                }
            };
            go();
            requestAnimationFrame(() => {
                go();
                requestAnimationFrame(go);
            });
            setTimeout(go, 50);
            setTimeout(go, 200);
            setTimeout(go, 500);
        },

        bindThreadScrollPassthrough(root) {
            const thread = root?.querySelector('#message-thread');
            if (!thread || thread.dataset.scrollPassthroughBound === '1') return;
            thread.dataset.scrollPassthroughBound = '1';

            thread.addEventListener('wheel', (e) => {
                const atTop = thread.scrollTop <= 0;
                const atBottom = thread.scrollTop + thread.clientHeight >= thread.scrollHeight - 1;
                // At the edge: let the page/outer scroll take over instead of getting stuck.
                if ((atTop && e.deltaY < 0) || (atBottom && e.deltaY > 0)) {
                    // Do not stopPropagation — allow the event to reach the document.
                    return;
                }
            }, { passive: true });
        },

        appendMessage(root, m, options = {}) {
            const autoScroll = options.autoScroll !== false;
            const thread = root.querySelector('#message-thread');
            if (!thread || thread.querySelector(`.message-bubble[data-id="${m.id}"]`)) return;

            const empty = thread.querySelector('p.text-center');
            if (empty) empty.remove();

            const isCustomer = m.role === 'user';
            const dir = /[\u0600-\u06FF\u0590-\u05FF]/.test(m.message || '') ? 'rtl' : 'ltr';
            const wrap = document.createElement('div');
            wrap.className = `flex ${isCustomer ? 'justify-start' : 'justify-end'} message-bubble`;
            wrap.dataset.id = String(m.id);

            let media = '';
            if (m.attachment_url && m.is_image) {
                media = `<a href="${m.attachment_url}" target="_blank" rel="noopener" class="block mb-2"><img src="${m.attachment_url}" alt="" class="max-h-56 rounded-lg object-cover"></a>`;
            } else if (m.attachment_url && m.is_video) {
                media = `<div class="mb-2 overflow-hidden rounded-lg"><video controls playsinline preload="metadata" class="max-h-56 w-full bg-black" src="${m.attachment_url}"></video></div>`;
            } else if (m.attachment_url && m.is_audio) {
                media = `<div class="mb-2 w-full min-w-[16rem] sm:min-w-[18rem] max-w-md rounded-xl px-2.5 py-2 ${isCustomer ? 'bg-[#f7efe3]' : 'bg-white/15'}"><audio controls preload="metadata" controlslist="nodownload" class="wa-audio-player block w-full" src="${m.attachment_url}"></audio></div>`;
            } else if (m.attachment_url && m.is_pdf) {
                media = `<a href="${m.attachment_url}" target="_blank" rel="noopener" class="mb-2 flex items-center gap-2 rounded-lg px-3 py-2 text-sm ${isCustomer ? 'bg-[#f7efe3]' : 'bg-white/15'}">📄 PDF</a>`;
            } else if (m.attachment_url) {
                media = `<a href="${m.attachment_url}" target="_blank" rel="noopener" class="mb-2 inline-block text-sm underline">File</a>`;
            }

            const source = (!isCustomer && m.source_label)
                ? `<span class="inline-block mb-1 text-[10px] font-semibold uppercase tracking-wide ${isCustomer ? 'text-[#a78a6c]' : 'text-white/80'}">${m.source_label}</span>`
                : '';

            wrap.innerHTML = `
                <div class="max-w-[85%] sm:max-w-[70%] rounded-2xl px-3.5 py-2.5 shadow-sm ${isCustomer ? 'bg-white text-[#2b1e11] rounded-ss-md' : 'bg-[#f47a2e] text-white rounded-se-md'}">
                    ${source}${media}
                    <div class="text-sm leading-relaxed whitespace-pre-wrap break-words" dir="${dir}"></div>
                    <div class="mt-1 text-[10px] ${isCustomer ? 'text-[#a78a6c]' : 'text-white/70'} text-end" dir="ltr"></div>
                </div>`;
            wrap.querySelector('.whitespace-pre-wrap').textContent = m.message || '';
            const time = m.created_at ? new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
            wrap.querySelector('.text-end').textContent = time;
            thread.appendChild(wrap);
            root.dataset.lastMessageId = String(m.id);
            if (autoScroll) {
                this.scrollThreadToBottom(root, true);
            }
        },

        bindComposer(root) {
            const form = root.querySelector('#reply-form');
            if (!form || root.dataset.canReply !== '1') return;
            const input = root.querySelector('#reply-input');
            const err = root.querySelector('#reply-error');
            const btn = root.querySelector('#reply-submit');

            const autosize = () => {
                if (!input) return;
                input.style.height = '2.75rem';
                const next = Math.min(Math.max(input.scrollHeight, 44), 104);
                input.style.height = next + 'px';
            };
            input?.addEventListener('input', autosize);
            autosize();

            input?.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    form.requestSubmit();
                }
            });

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const message = (input.value || '').trim();
                if (!message) return;
                btn.disabled = true;
                err.classList.add('hidden');
                try {
                    const res = await fetch(root.dataset.replyUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrf(root),
                        },
                        body: JSON.stringify({ message }),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (data.message) this.appendMessage(root, data.message);
                    if (data.conversation?.bot_mode) this.paintBotMode(root, data.conversation.bot_mode);
                    if (!res.ok || data.ok === false) {
                        const msg = data.delivery?.error || data.message || data.error || 'Failed to send to WhatsApp';
                        throw new Error(typeof msg === 'string' ? msg : 'Failed to send to WhatsApp');
                    }
                    input.value = '';
                    autosize();
                    this.scrollThreadToBottom(root, true);
                    this.toast(root, data.delivery?.channel === 'whatsapp' ? 'Sent to WhatsApp' : 'Sent');
                } catch (ex) {
                    err.textContent = ex.message || 'Error';
                    err.classList.remove('hidden');
                } finally {
                    btn.disabled = false;
                    input.focus();
                }
            });
        },

        async setBotMode(root, mode) {
            const res = await fetch(root.dataset.botModeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': this.csrf(root),
                },
                body: JSON.stringify({ bot_mode: mode }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error('Failed');
            const next = data.conversation?.bot_mode || mode;
            this.paintBotMode(root, next);
            return next;
        },

        bindBotMode(root) {
            if (root.dataset.canControl !== '1') return;

            const toggle = root.querySelector('#chat-bot-toggle');
            toggle?.addEventListener('click', async () => {
                const current = root.querySelector('#bot-mode-badge')?.dataset.mode || 'active';
                const next = current === 'active'
                    ? (toggle.dataset.modeOff || 'paused')
                    : (toggle.dataset.modeOn || 'active');
                try {
                    await this.setBotMode(root, next);
                    this.toast(root, 'Bot mode updated');
                } catch (_) {
                    this.toast(root, 'Could not update bot mode');
                }
            });

            root.querySelectorAll('.bot-mode-btn').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const mode = btn.dataset.mode;
                    try {
                        await this.setBotMode(root, mode);
                        this.toast(root, 'Bot mode updated');
                    } catch (_) {
                        this.toast(root, 'Could not update bot mode');
                    }
                });
            });
        },

        bindInstructor(root) {
            const scope = root.querySelector('#instruction-scope');
            const replyCount = root.querySelector('#reply-count-field');
            const untilTime = root.querySelector('#until-time-field');
            scope?.addEventListener('change', () => {
                replyCount?.classList.toggle('hidden', scope.value !== 'reply_count');
                untilTime?.classList.toggle('hidden', scope.value !== 'until_time');
            });

            root.querySelectorAll('.instruction-template').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const ta = root.querySelector('#instruction-text');
                    if (ta) ta.value = btn.dataset.instruction || '';
                });
            });

            root.querySelectorAll('.instruction-form').forEach((form) => {
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    if (root.dataset.canInstruct !== '1') return;
                    const fd = new FormData(form);
                    const payload = Object.fromEntries(fd.entries());
                    payload.priority = Number(payload.priority || 100);
                    if (payload.remaining_uses) payload.remaining_uses = Number(payload.remaining_uses);
                    try {
                        const res = await fetch(form.dataset.storeUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': this.csrf(root),
                            },
                            body: JSON.stringify(payload),
                        });
                        if (!res.ok) throw new Error('Failed');
                        this.toast(root, 'Instruction saved');
                        window.location.reload();
                    } catch (_) {
                        this.toast(root, 'Could not save instruction');
                    }
                });
            });

            root.querySelectorAll('.toggle-instruction').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const active = btn.dataset.active === '1';
                    await fetch(btn.dataset.url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrf(root),
                        },
                        body: JSON.stringify({ is_active: !active }),
                    });
                    window.location.reload();
                });
            });

            root.querySelectorAll('.delete-instruction').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    if (!confirm('Delete this instruction?')) return;
                    await fetch(btn.dataset.url, {
                        method: 'DELETE',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrf(root),
                        },
                    });
                    window.location.reload();
                });
            });
        },

        bindDrawer(root) {
            const drawer = root.querySelector('#instructor-drawer');
            root.querySelector('#btn-instructor-mobile')?.addEventListener('click', () => {
                drawer?.classList.remove('hidden');
            });
            drawer?.querySelectorAll('[data-close-drawer]').forEach((el) => {
                el.addEventListener('click', () => drawer.classList.add('hidden'));
            });
        },

        bindInstructorCollapse(root) {
            const STORAGE_KEY = 'workspace-instructor-collapsed';
            const collapseBtn = root.querySelector('#btn-instructor-collapse');
            const expandBtn = root.querySelector('#btn-instructor-expand');

            const setCollapsed = (collapsed) => {
                try {
                    localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
                } catch (_) { /* ignore */ }

                root.dataset.instructorCollapsed = collapsed ? '1' : '0';
                collapseBtn?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                expandBtn?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            };

            let initial = false;
            try {
                initial = localStorage.getItem(STORAGE_KEY) === '1';
            } catch (_) { /* ignore */ }
            setCollapsed(initial);

            collapseBtn?.addEventListener('click', () => setCollapsed(true));
            expandBtn?.addEventListener('click', () => setCollapsed(false));
        },
    };

    window.WorkspaceChat = WorkspaceChat;
})();
