<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kaman Inventory Import — Request Log</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(180deg, #fef8f0 0%, #fff 50%, #fef2dd 100%);
            color: #2b1e11;
            min-height: 100vh;
        }
        .kaman-card {
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(48, 31, 13, 0.1);
            border: 1px solid #f1dfc5;
            background: #fff;
        }
        .kaman-button {
            background: linear-gradient(135deg, #f59f43, #f47a2e);
            color: #fff;
            border-radius: 999px;
            font-weight: 600;
        }
        .kaman-button:hover { filter: brightness(1.05); }
        .kaman-button:disabled { opacity: 0.5; cursor: not-allowed; }
        .log-scroll {
            max-height: calc(100vh - 280px);
            min-height: 420px;
            overflow-y: auto;
        }
        .step-pending { border-left: 4px solid #d4c4b0; }
        .step-success { border-left: 4px solid #10b981; }
        .step-failed { border-left: 4px solid #ef4444; }
        .step-skipped { border-left: 4px solid #94a3b8; }
        .step-running { border-left: 4px solid #f59e0b; animation: pulse 1s infinite; }
        @keyframes pulse { 50% { opacity: 0.7; } }
        pre.json-block {
            font-size: 11px;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
        }
    </style>
</head>
<body>
@include('partials.topbar', ['tagText' => 'Inventory Import'])

<main class="max-w-7xl mx-auto px-4 py-8 space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-[#2b1e11]">Inventory import — HTTP request log</h1>
            <p class="text-sm text-[#7c6a56] mt-1">Build a plan from your CSV / Excel files, review every request, then send one-by-one or run all.</p>
        </div>
        <a href="{{ route('form.index') }}" class="text-sm text-[#f47a2e] font-semibold hover:underline">← Back to form</a>
    </div>

    <div class="grid lg:grid-cols-12 gap-6">
        <aside class="lg:col-span-4 space-y-4">
            <section class="kaman-card p-6 space-y-4">
                <h2 class="text-sm font-bold uppercase tracking-wide text-[#f47a2e]">Connection</h2>
                <div>
                    <label class="text-xs font-semibold text-[#7c6a56]">Restaurant subdomain</label>
                    <input id="subdomain" type="text" value="{{ $defaultSubdomain }}" class="mt-1 w-full rounded-xl border border-[#f1dfc5] px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="text-xs font-semibold text-[#7c6a56]">Environment</label>
                    <select id="environment" class="mt-1 w-full rounded-xl border border-[#f1dfc5] px-3 py-2 text-sm">
                        <option value="dev" @selected($defaultEnvironment === 'dev')>Testing (.kaman.dev)</option>
                        <option value="rest">Production (.kaman.rest)</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-[#7c6a56]">Email</label>
                    <input id="email" type="email" placeholder="thex@kaman.rest" class="mt-1 w-full rounded-xl border border-[#f1dfc5] px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="text-xs font-semibold text-[#7c6a56]">Password</label>
                    <input id="password" type="password" class="mt-1 w-full rounded-xl border border-[#f1dfc5] px-3 py-2 text-sm">
                </div>
                <button type="button" id="btnLogin" class="kaman-button w-full py-2.5 text-sm">Login to Kaman</button>
                <p id="loginStatus" class="text-xs text-[#7c6a56]"></p>
            </section>

            <section class="kaman-card p-6 space-y-4">
                <h2 class="text-sm font-bold uppercase tracking-wide text-[#f47a2e]">Plan options</h2>
                <div>
                    <label class="text-xs font-semibold text-[#7c6a56]">CSV rows limit (max per plan)</label>
                    <input id="limit" type="number" value="50" min="1" max="5000" class="mt-1 w-full rounded-xl border border-[#f1dfc5] px-3 py-2 text-sm">
                </div>
                <label class="flex items-center gap-2 text-sm"><input id="skipStock" type="checkbox"> Skip receive-stock</label>
                <label class="flex items-center gap-2 text-sm"><input id="skipLinks" type="checkbox"> Skip recipe links</label>
                <label class="flex items-center gap-2 text-sm"><input id="noIngredients" type="checkbox"> Skip ingredients sheet</label>
                <label class="flex items-center gap-2 text-sm"><input id="noRecipeLinks" type="checkbox"> Skip food-cost links</label>
                <div>
                    <label class="text-xs font-semibold text-[#7c6a56]">Suppliers map (JSON, optional)</label>
                    <textarea id="suppliersMap" rows="4" placeholder='{"שופרסל אמיגה": "uuid-here"}' class="mt-1 w-full rounded-xl border border-[#f1dfc5] px-3 py-2 text-xs font-mono"></textarea>
                </div>
                <button type="button" id="btnBuild" class="kaman-button w-full py-2.5 text-sm" disabled>Build request log</button>
            </section>

            <section class="kaman-card p-6 space-y-3">
                <h2 class="text-sm font-bold uppercase tracking-wide text-[#f47a2e]">Execute</h2>
                <button type="button" id="btnSendAll" class="kaman-button w-full py-2.5 text-sm" disabled>Send all pending</button>
                <button type="button" id="btnSendNext" class="w-full py-2.5 text-sm rounded-full border-2 border-[#f1dfc5] text-[#7c6a56] font-semibold hover:bg-[#fef8f0]" disabled>Send next only</button>
                <button type="button" id="btnStop" class="w-full py-2 text-sm text-red-600 font-semibold hidden">Stop</button>
                <button type="button" id="btnClear" class="w-full py-2 text-xs text-[#7c6a56] underline">Clear log</button>
            </section>
        </aside>

        <div class="lg:col-span-8 space-y-4">
            <div class="kaman-card p-4 flex flex-wrap gap-4 items-center justify-between">
                <div id="summary" class="text-sm text-[#7c6a56]">No plan yet. Login, then build the request log.</div>
                <div class="flex flex-wrap gap-2 items-center">
                    <label class="text-xs text-[#7c6a56]">Filter</label>
                    <select id="phaseFilter" class="rounded-lg border border-[#f1dfc5] px-2 py-1 text-xs">
                        <option value="">All phases</option>
                    </select>
                    <select id="statusFilter" class="rounded-lg border border-[#f1dfc5] px-2 py-1 text-xs">
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="success">Success</option>
                        <option value="failed">Failed</option>
                        <option value="skipped">Skipped</option>
                    </select>
                </div>
            </div>

            <div id="activityLog" class="text-xs text-[#7c6a56] font-mono bg-[#2b1e11] text-emerald-200 rounded-xl px-4 py-3 max-h-28 overflow-y-auto hidden"></div>

            <div id="stepsPanel" class="kaman-card log-scroll p-2 space-y-2">
                <p class="text-center text-sm text-[#7c6a56] py-16">Request log will appear here.</p>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    const ROUTES = {
        login: @json(route('kaman.inventory.login')),
        plan: @json(route('kaman.inventory.plan')),
        execute: @json(route('kaman.inventory.execute-step')),
        clear: @json(route('kaman.inventory.clear')),
    };

    const state = {
        loggedIn: false,
        steps: [],
        running: false,
        stopRequested: false,
    };

    const el = (id) => document.getElementById(id);

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    function appendActivity(message, type = 'info') {
        const log = el('activityLog');
        log.classList.remove('hidden');
        const line = document.createElement('div');
        const ts = new Date().toLocaleTimeString();
        line.textContent = `[${ts}] ${message}`;
        if (type === 'error') line.className = 'text-red-300';
        if (type === 'ok') line.className = 'text-emerald-300';
        log.appendChild(line);
        log.scrollTop = log.scrollHeight;
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function statusClass(status) {
        return {
            pending: 'step-pending',
            success: 'step-success',
            failed: 'step-failed',
            skipped: 'step-skipped',
            running: 'step-running',
        }[status] || 'step-pending';
    }

    function updateSummary(summary) {
        if (!summary) {
            el('summary').textContent = 'No plan loaded.';
            return;
        }
        const parts = Object.entries(summary.phases || {}).map(([k, v]) => `${k}: ${v}`).join(' · ');
        el('summary').innerHTML = `<strong>${summary.total_steps ?? 0}</strong> requests · CSV rows: ${summary.csv_rows_in_plan ?? 0}/${summary.csv_rows_total ?? 0} · ${parts}`;
    }

    function populatePhaseFilter() {
        const select = el('phaseFilter');
        const phases = [...new Set(state.steps.map((s) => s.phase))].sort();
        select.innerHTML = '<option value="">All phases</option>';
        phases.forEach((p) => {
            const opt = document.createElement('option');
            opt.value = p;
            opt.textContent = p;
            select.appendChild(opt);
        });
    }

    function filteredSteps() {
        const phase = el('phaseFilter').value;
        const status = el('statusFilter').value;
        return state.steps.map((step, index) => ({ step, index })).filter(({ step }) => {
            if (phase && step.phase !== phase) return false;
            if (status && (step.status || 'pending') !== status) return false;
            return true;
        });
    }

    function renderSteps() {
        const panel = el('stepsPanel');
        const items = filteredSteps();
        if (!state.steps.length) {
            panel.innerHTML = '<p class="text-center text-sm text-[#7c6a56] py-16">Request log will appear here.</p>';
            return;
        }
        if (!items.length) {
            panel.innerHTML = '<p class="text-center text-sm text-[#7c6a56] py-16">No steps match filter.</p>';
            return;
        }

        panel.innerHTML = items.map(({ step, index }) => {
            const st = step.status || 'pending';
            const http = step.http || {};
            const bodyStr = JSON.stringify(http.body ?? {}, null, 2);
            const respStr = step.response
                ? JSON.stringify(step.response, null, 2)
                : (step.result_message || '');
            const note = step.note ? `<p class="text-xs text-amber-700 mt-1">${escapeHtml(step.note)}</p>` : '';
            const resultMsg = step.result_message && st !== 'pending'
                ? `<p class="text-xs mt-1 ${st === 'failed' ? 'text-red-600' : 'text-[#7c6a56]'}">${escapeHtml(step.result_message)}</p>`
                : '';

            return `
            <article class="rounded-xl border border-[#f1dfc5] bg-[#fef8f0]/50 p-4 ${statusClass(st)}" data-step-index="${index}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <span class="text-[10px] uppercase tracking-wider font-bold text-[#f47a2e]">${escapeHtml(step.phase || '')}</span>
                        <span class="ml-2 text-[10px] px-2 py-0.5 rounded-full bg-white border border-[#f1dfc5]">${escapeHtml(st)}</span>
                        ${step.http_status ? `<span class="ml-1 text-[10px] text-[#7c6a56]">HTTP ${step.http_status}</span>` : ''}
                        <h3 class="text-sm font-semibold text-[#2b1e11] mt-1">${escapeHtml(step.title || step.id || '')}</h3>
                        ${note}${resultMsg}
                    </div>
                    <button type="button" data-send-one="${index}" class="text-xs kaman-button px-3 py-1.5 ${st !== 'pending' ? 'opacity-40' : ''}" ${st !== 'pending' ? 'disabled' : ''}>Send</button>
                </div>
                <p class="mt-2 text-xs font-mono text-[#7c6a56]"><strong class="text-[#2b1e11]">${escapeHtml(http.method || 'POST')}</strong> ${escapeHtml(http.path || '')}</p>
                <details class="mt-2">
                    <summary class="text-xs font-semibold text-[#f47a2e] cursor-pointer">Request body</summary>
                    <pre class="json-block mt-2 rounded-lg bg-[#2b1e11] text-emerald-200 p-3 max-h-48 overflow-auto">${escapeHtml(bodyStr)}</pre>
                </details>
                ${respStr ? `<details class="mt-2" open><summary class="text-xs font-semibold text-[#7c6a56] cursor-pointer">Response</summary><pre class="json-block mt-2 rounded-lg bg-slate-800 text-slate-200 p-3 max-h-40 overflow-auto">${escapeHtml(respStr)}</pre></details>` : ''}
            </article>`;
        }).join('');

        panel.querySelectorAll('[data-send-one]').forEach((btn) => {
            btn.addEventListener('click', () => executeOne(parseInt(btn.dataset.sendOne, 10)));
        });
    }

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        });
        const data = await res.json().catch(() => ({}));
        return { ok: res.ok, status: res.status, data };
    }

    el('btnLogin').addEventListener('click', async () => {
        const subdomain = el('subdomain').value.trim();
        const password = el('password').value;
        const email = el('email').value.trim() || `${subdomain}@kaman.rest`;
        el('loginStatus').textContent = 'Logging in…';
        const { ok, data } = await postJson(ROUTES.login, {
            subdomain,
            environment: el('environment').value,
            email,
            password,
        });
        if (!ok || !data.success) {
            state.loggedIn = false;
            el('loginStatus').textContent = data.message || 'Login failed';
            appendActivity(el('loginStatus').textContent, 'error');
            return;
        }
        state.loggedIn = true;
        el('loginStatus').textContent = data.message || 'Logged in';
        el('btnBuild').disabled = false;
        appendActivity(data.message, 'ok');
    });

    el('btnBuild').addEventListener('click', async () => {
        if (!state.loggedIn) return;
        el('btnBuild').disabled = true;
        appendActivity('Building request plan…');
        let suppliersMap = null;
        try {
            const raw = el('suppliersMap').value.trim();
            if (raw) suppliersMap = raw;
        } catch (e) {
            appendActivity('Invalid suppliers JSON', 'error');
            el('btnBuild').disabled = false;
            return;
        }
        const { ok, data } = await postJson(ROUTES.plan, {
            limit: parseInt(el('limit').value, 10) || 50,
            skip_stock: el('skipStock').checked,
            skip_links: el('skipLinks').checked,
            no_ingredients: el('noIngredients').checked,
            no_recipe_links: el('noRecipeLinks').checked,
            suppliers_map: suppliersMap,
        });
        el('btnBuild').disabled = false;
        if (!ok || !data.success) {
            appendActivity(data.message || 'Plan build failed', 'error');
            return;
        }
        state.steps = data.steps || [];
        updateSummary(data.summary);
        populatePhaseFilter();
        renderSteps();
        el('btnSendAll').disabled = false;
        el('btnSendNext').disabled = false;
        appendActivity(`Plan ready: ${state.steps.length} requests`, 'ok');
    });

    async function executeOne(index) {
        const step = state.steps[index];
        if (!step) return;
        step.status = 'running';
        renderSteps();
        appendActivity(`Sending #${index + 1}: ${step.title}`);
        const { ok, data } = await postJson(ROUTES.execute, { step_index: index });
        if (data.step) {
            state.steps[index] = data.step;
        } else if (!ok) {
            state.steps[index].status = 'failed';
            state.steps[index].result_message = data.message || 'Request failed';
        }
        renderSteps();
        const st = state.steps[index].status || 'failed';
        appendActivity(
            `#${index + 1} ${st}${data.result?.status ? ' HTTP ' + data.result.status : ''}`,
            st === 'success' || st === 'skipped' ? 'ok' : 'error'
        );
        return st === 'success' || st === 'skipped';
    }

    async function runAllPending() {
        if (state.running) return;
        state.running = true;
        state.stopRequested = false;
        el('btnStop').classList.remove('hidden');
        el('btnSendAll').disabled = true;
        el('btnSendNext').disabled = true;

        for (let i = 0; i < state.steps.length; i++) {
            if (state.stopRequested) break;
            if ((state.steps[i].status || 'pending') !== 'pending') continue;
            await executeOne(i);
            await new Promise((r) => setTimeout(r, 250));
        }

        state.running = false;
        el('btnStop').classList.add('hidden');
        el('btnSendAll').disabled = false;
        el('btnSendNext').disabled = false;
        appendActivity('Batch finished.', 'ok');
    }

    el('btnSendAll').addEventListener('click', runAllPending);
    el('btnSendNext').addEventListener('click', async () => {
        const idx = state.steps.findIndex((s) => (s.status || 'pending') === 'pending');
        if (idx === -1) {
            appendActivity('No pending steps.');
            return;
        }
        await executeOne(idx);
    });
    el('btnStop').addEventListener('click', () => {
        state.stopRequested = true;
        appendActivity('Stop requested…');
    });

    el('btnClear').addEventListener('click', async () => {
        await postJson(ROUTES.clear, {});
        state.steps = [];
        renderSteps();
        updateSummary(null);
        el('btnSendAll').disabled = true;
        el('btnSendNext').disabled = true;
        appendActivity('Log cleared.');
    });

    el('phaseFilter').addEventListener('change', renderSteps);
    el('statusFilter').addEventListener('change', renderSteps);
})();
</script>
</body>
</html>
