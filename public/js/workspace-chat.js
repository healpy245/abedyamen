/**
 * Customer workspace chat — polling + reply + Live AI Instructor.
 * Designed so endpoints can later be swapped for WebSockets without rewriting UI.
 */
(function () {
    'use strict';

    const MESSAGE_POLL_MS = 1500;
    const LIST_POLL_MS = 4000;

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

        startListPolling(root) {
            if (!root) return;
            const poll = async () => {
                if (!this.visible()) return;
                const url = new URL(root.dataset.pollUrl, window.location.origin);
                url.searchParams.set('filter', root.dataset.filter || 'all');
                url.searchParams.set('q', root.dataset.q || '');
                if (root.dataset.since) {
                    url.searchParams.set('since', root.dataset.since);
                }
                try {
                    const res = await fetch(url.toString(), {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                    });
                    if (!res.ok) return;
                    const data = await res.json();
                    // First response is a snapshot (may include rows outside the paginated DOM).
                    // Only insert missing rows on later incremental polls — never full-page reload.
                    const insertMissing = Boolean(root.dataset.since);
                    this.mergeList(root, data.conversations || [], insertMissing);
                    if (data.server_time) root.dataset.since = data.server_time;
                } catch (_) { /* ignore transient */ }
            };
            poll();
            this.listTimer = setInterval(poll, LIST_POLL_MS);
            document.addEventListener('visibilitychange', () => {
                if (this.visible()) poll();
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

            const row = document.createElement('a');
            row.href = c.url || '#';
            row.className = 'conversation-row flex gap-3 px-3 py-3 border-b border-[#eadfce]/80 hover:bg-white/70 transition';
            row.dataset.id = String(c.id);
            row.dataset.updated = c.updated_at || '';
            row.dataset.unread = String(unread);
            row.innerHTML = `
                <div class="w-11 h-11 rounded-full bg-[#f1dfc5] text-[#7c6a56] flex items-center justify-center text-sm font-bold shrink-0" aria-hidden="true">${this.escapeHtml(c.initials || '?')}</div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-sm font-semibold text-[#2b1e11] truncate">${this.escapeHtml(c.display_name || '')}</p>
                        <time class="text-[10px] text-[#a78a6c] shrink-0" datetime="${this.escapeHtml(c.last_message_at || '')}">${this.escapeHtml(this.formatListTime(c.last_message_at))}</time>
                    </div>
                    <p class="text-xs text-[#7c6a56] truncate mt-0.5">${this.escapeHtml(c.preview || '')}</p>
                    <div class="mt-1 flex flex-wrap gap-1 row-badges">${badges.join('')}</div>
                </div>`;
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

        startConversation(root) {
            if (!root) return;
            this.bindComposer(root);
            this.bindBotMode(root);
            this.bindInstructor(root);
            this.bindDrawer(root);
            this.bindInstructorCollapse(root);

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
                    (data.messages || []).forEach((m) => this.appendMessage(root, m));
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

            poll();
            this.messageTimer = setInterval(poll, MESSAGE_POLL_MS);
            document.addEventListener('visibilitychange', () => {
                if (this.visible()) poll();
            });
        },

        appendMessage(root, m) {
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
            thread.scrollTop = thread.scrollHeight;
            root.dataset.lastMessageId = String(m.id);
        },

        bindComposer(root) {
            const form = root.querySelector('#reply-form');
            if (!form || root.dataset.canReply !== '1') return;
            const input = root.querySelector('#reply-input');
            const err = root.querySelector('#reply-error');
            const btn = root.querySelector('#reply-submit');

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
