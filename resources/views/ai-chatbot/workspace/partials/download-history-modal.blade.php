@once
<div id="analytics-downloads-modal" class="fixed inset-0 z-50 hidden items-center justify-center p-3" role="dialog" aria-modal="true" aria-labelledby="analytics-downloads-title" hidden>
    <div class="absolute inset-0 bg-black/40" data-downloads-close></div>
    <div class="relative z-10 flex max-h-[85vh] w-full max-w-md flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
        <header class="flex items-start gap-3 border-b border-[#eadfce] px-4 py-3">
            <div class="min-w-0 flex-1">
                <h3 id="analytics-downloads-title" class="text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.analytics.history_title') }}</h3>
                <p id="analytics-downloads-group" class="mt-0.5 text-xs text-[#a78a6c]"></p>
            </div>
            <button type="button" class="rounded-lg px-2 py-1 text-sm text-[#a78a6c] hover:bg-[#f7efe3]" data-downloads-close aria-label="{{ __('chatbot.workspace.close') }}">×</button>
        </header>
        <div id="analytics-downloads-list" class="min-h-0 flex-1 overflow-y-auto px-4 py-3"></div>
        <div class="border-t border-[#eadfce] px-4 py-3">
            <p id="analytics-downloads-note" class="mb-2 text-xs text-[#a78a6c]"></p>
            <button type="button" id="analytics-downloads-go" class="inline-flex w-full items-center justify-center rounded-xl bg-[#1f7a3a] px-3 py-2.5 text-sm font-semibold text-white disabled:opacity-50"></button>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('analytics-downloads-modal');
    const list = document.getElementById('analytics-downloads-list');
    const note = document.getElementById('analytics-downloads-note');
    const go = document.getElementById('analytics-downloads-go');
    const groupLabel = document.getElementById('analytics-downloads-group');
    if (!modal || !list || !go) return;

    const labels = {
        empty: @json(__('chatbot.workspace.analytics.history_empty')),
        file: @json(__('chatbot.workspace.analytics.history_file')),
        count: @json(__('chatbot.workspace.analytics.history_count')),
        download: @json(__('chatbot.workspace.analytics.download')),
        fresh: @json(__('chatbot.workspace.analytics.history_new')),
        freshFile: @json(__('chatbot.workspace.analytics.history_new_file')),
        noneNew: @json(__('chatbot.workspace.analytics.history_none_new')),
    };
    const fill = (template, map) => template.replace(/:(\w+)/g, (_, key) => map[key] ?? '');

    let exportHref = '';

    const withParams = (href, extra) => {
        const url = new URL(href, window.location.origin);
        Object.entries(extra).forEach(([key, value]) => url.searchParams.set(key, value));
        return url.toString();
    };

    const historyUrl = (href) => href.replace('/analytics/export/', '/analytics/downloads/');

    const filenameOf = (header) => {
        const star = header.match(/filename\*=UTF-8''([^;]+)/i);
        if (star) return decodeURIComponent(star[1]);
        const plain = header.match(/filename="([^"]+)"/i);
        return plain ? plain[1] : 'download';
    };

    const pull = async (url) => {
        go.disabled = true;
        try {
            const res = await fetch(url, { headers: { Accept: '*/*' } });
            if (!res.ok) return;
            const blob = await res.blob();
            const objectUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = objectUrl;
            link.download = filenameOf(res.headers.get('Content-Disposition') || '');
            document.body.append(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(objectUrl);
        } finally {
            go.disabled = false;
        }
    };

    const paint = (data) => {
        const downloads = data.downloads || [];
        list.innerHTML = '';
        if (!downloads.length) {
            const empty = document.createElement('p');
            empty.className = 'text-sm text-[#a78a6c]';
            empty.textContent = labels.empty;
            list.append(empty);
            note.textContent = fill(labels.freshFile, { n: data.next || 1 });
            go.textContent = labels.download;
            go.disabled = false;
            go.dataset.mode = 'save';
            return;
        }
        downloads.forEach((row) => {
            const item = document.createElement('div');
            item.className = 'mb-2 flex items-center justify-between gap-2 rounded-xl bg-[#f7efe3] px-3 py-2';
            const text = document.createElement('div');
            text.className = 'min-w-0';
            const title = document.createElement('p');
            title.className = 'text-sm font-semibold text-[#2b1e11]';
            title.textContent = fill(labels.file, { n: row.sequence });
            const meta = document.createElement('p');
            meta.className = 'text-[11px] text-[#a78a6c]';
            meta.textContent = fill(labels.count, { count: row.count }) + (row.at ? ' · ' + row.at : '');
            text.append(title, meta);
            const again = document.createElement('button');
            again.type = 'button';
            again.className = 'shrink-0 rounded-lg bg-white px-2 py-1 text-[11px] font-medium text-[#2b1e11] ring-1 ring-black/10';
            again.textContent = labels.download;
            again.addEventListener('click', async () => {
                await pull(withParams(exportHref, { download: String(row.id) }));
            });
            item.append(text, again);
            list.append(item);
        });
        note.textContent = (data.fresh_count || 0) > 0
            ? fill(labels.freshFile, { n: data.next })
            : labels.noneNew;
        go.textContent = labels.fresh;
        go.dataset.mode = 'fresh';
        go.disabled = (data.fresh_count || 0) < 1;
    };

    const open = async (link) => {
        exportHref = link.href;
        if (groupLabel) groupLabel.textContent = link.closest('[data-insight-label]')?.dataset.insightLabel || '';
        list.innerHTML = '';
        note.textContent = '';
        go.disabled = true;
        modal.hidden = false;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        try {
            const res = await fetch(historyUrl(exportHref), { headers: { Accept: 'application/json' } });
            paint(res.ok ? await res.json() : { downloads: [], next: 1, fresh_count: 0 });
        } catch (err) {
            paint({ downloads: [], next: 1, fresh_count: 0 });
        }
    };

    const close = () => {
        modal.hidden = true;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a.analytics-download');
        if (!link) return;
        event.preventDefault();
        event.stopPropagation();
        open(link);
    }, true);

    modal.querySelectorAll('[data-downloads-close]').forEach((node) => node.addEventListener('click', close));
    go.addEventListener('click', async () => {
        const mode = go.dataset.mode || 'save';
        await pull(withParams(exportHref, { [mode]: '1' }));
        try {
            const res = await fetch(historyUrl(exportHref), { headers: { Accept: 'application/json' } });
            if (res.ok) paint(await res.json());
        } catch (err) {
            /* the file already downloaded */
        }
    });
});
</script>
@endonce
