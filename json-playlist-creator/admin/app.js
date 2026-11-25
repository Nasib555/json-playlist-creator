/*
 * JSON Playlist Creator admin.
 * Lists show cards or rows; adding or editing opens a large popup. "Save" in a popup publishes the whole playlist
 * through api.php, which validates it, keeps a backup and refuses when someone else saved in between. Accounts,
 * settings and categories are edited in place; the bar at the bottom publishes them.
 * The library holds the videos; live channels, movies and episodes all point at library items.
 */
(function () {
    'use strict';

    const BOOT = window.JP_BOOT;
    const $app = document.getElementById('jp-app');
    const $modal = document.getElementById('jp-modal');
    const $dialog = document.getElementById('jp-dialog');
    const $toasts = document.getElementById('jp-toasts');

    const S = {
        doc: null, rev: '', saved: '', base: '', timezones: [], backups: [], files: [], hls: {}, chunk: 4194304, videoExt: [],
        canBuild: false, canProbe: false, now: '', setup: null, version: '', maxCatchup: 30,
        orphans: [], view: 'overview', busy: false, test: null, account: 0,
        uploads: null, // {queue: [{file, status, path, seconds, pct}], cancelled, onDone}
    };
    /** The open popup: {kind, title, draft, orig, ref, isNew, errors, busy, videoMode, timeline, guideTab}. */
    let M = null;

    /* ---------------------------------------------------------------- helpers */

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const clone = o => JSON.parse(JSON.stringify(o));
    const dirty = () => !!S.doc && JSON.stringify(S.doc) !== S.saved;
    const savedDoc = () => JSON.parse(S.saved);
    const asset = p => !p ? '' : p.includes('://') ? p : S.base + '/' + p.split('/').map(encodeURIComponent).join('/');
    const nextId = (list, from) => Math.max(from, ...list.map(x => +x.id || 0)) + 1;
    const lib = () => Object.entries(S.doc.library);
    const item = k => S.doc.library[k] || null;
    const itemTitle = k => (item(k) || {}).title || 'Not picked';
    const mb = b => b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : Math.max(0.1, b / 1048576).toFixed(1) + ' MB';
    const dur = s => { s = Math.round(+s || 0); if (!s) return ''; const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
        return h ? `${h} h ${m} min` : m ? `${m} min${x && m < 10 ? ' ' + x + ' s' : ''}` : `${x} s`; };
    const clock = s => { s = Math.max(0, Math.round(+s || 0)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
        return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(x).padStart(2, '0'); };
    const plural = (n, w, p) => `${n} ${n === 1 ? w : (p || w + 's')}`;
    const slug = t => (String(t || '').toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40)) || 'video';
    const episodesOf = s => Object.values(s.seasons || {}).reduce((n, se) => n + (se.episodes || []).length, 0);
    const expired = a => !!a.expires && a.expires < (S.now || '').slice(0, 10);
    const canSignIn = a => a.enabled && !expired(a);
    const titleFromFile = name => name.replace(/\.[^.]+$/, '').replace(/[._]+/g, ' ').replace(/\s+/g, ' ').trim().replace(/^./, c => c.toUpperCase());

    // Wall-clock times "YYYY-MM-DD HH:MM" in the playlist's time zone. Arithmetic is done in UTC so no browser
    // time zone or daylight-saving shift can creep in.
    const T = {
        parse: s => { const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || ''); return m ? Date.UTC(+m[1], m[2] - 1, +m[3], +m[4], +m[5]) : NaN; },
        fmt: ms => { const d = new Date(ms); const p = n => String(n).padStart(2, '0');
            return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}`; },
        add: (s, minutes) => T.fmt(T.parse(s) + minutes * 60000),
        mins: (a, b) => Math.round((T.parse(b) - T.parse(a)) / 60000),
        input: s => (s || '').replace(' ', 'T'),
        day: s => (s || '').slice(0, 10),
        hm: s => (s || '').slice(11, 16),
        nextHalfHour: s => { const ms = T.parse(s); return T.fmt(Math.ceil(ms / 1800000) * 1800000); },
        nice: s => { const ms = T.parse(s); if (isNaN(ms)) return s; const d = new Date(ms);
            return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }) + ' ' + T.hm(s); },
    };

    function usage(key) {
        const live = S.doc.live.filter(c => c.source === 'files' && c.items.some(x => x.media === key)).length
            + S.doc.live.filter(c => c.programmes.some(p => p.archive === key)).length;
        const vod = S.doc.vod.filter(m => m.media === key).length;
        const eps = S.doc.series.reduce((n, s) => n + Object.values(s.seasons).reduce((m, se) => m + se.episodes.filter(x => x.media === key).length, 0), 0);
        return { live, vod, eps, total: live + vod + eps };
    }

    const ICONS = {
        home: '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        film: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 4v16M17 4v16M3 9h4M3 15h4M17 9h4M17 15h4"/>',
        tv: '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="m8 2 4 4 4-4"/>',
        movie: '<circle cx="12" cy="12" r="9"/><path d="m10 8.5 5 3.5-5 3.5z"/>',
        series: '<rect x="3" y="5" width="18" height="12" rx="2"/><path d="M8 21h8M12 17v4"/>',
        tag: '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        x: '<path d="M18 6 6 18M6 6l12 12"/>',
        search: '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
        check: '<path d="M20 6 9 17l-5-5"/>',
        up: '<path d="m18 15-6-6-6 6"/>',
        down: '<path d="m6 9 6 6 6-6"/>',
        trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
        upload: '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
        download: '<path d="M12 4v12M7 11l5 5 5-5M4 20h16"/>',
        link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        key: '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.3-9.3M16 7l3 3"/>',
        out: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        bolt: '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
    };
    const ic = n => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${ICONS[n] || ''}</svg>`;

    /* ---------------------------------------------------------------- server */

    async function api(action, data = {}) {
        let res;
        try {
            const r = await fetch(BOOT.api, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-JP-Token': BOOT.csrf },
                body: JSON.stringify(Object.assign({ action }, data)),
            });
            const text = await r.text();
            try { res = JSON.parse(text); } catch (e) { res = { ok: false, error: `Server error (HTTP ${r.status}). Nothing was changed.` }; }
        } catch (e) {
            res = { ok: false, error: 'Could not reach the server (' + e.message + '). Your edits are still here; try again.' };
        }
        if (res.login) { await alertBox('Session ended', res.error); location.reload(); }
        return res;
    }

    async function postForm(fd) {
        fd.append('token', BOOT.csrf);
        try {
            const r = await fetch(BOOT.api, { method: 'POST', credentials: 'same-origin', body: fd });
            const text = await r.text();
            try { return JSON.parse(text); } catch (e) { return { ok: false, retry: true, error: `Server error (HTTP ${r.status}).` }; }
        } catch (e) {
            return { ok: false, retry: true, error: 'Connection problem (' + e.message + ').' };
        }
    }

    async function load() {
        const r = await api('load');
        if (!r.ok) { $app.innerHTML = `<div class="content"><div class="banner banner-danger">${esc(r.error)}</div></div>`; return; }
        Object.assign(S, { doc: r.doc, rev: r.rev, base: r.base, timezones: r.timezones, backups: r.backups, files: r.files || [], hls: r.hls || {},
                           chunk: r.chunk_bytes || S.chunk, videoExt: r.video_ext || [], canBuild: !!r.can_build, canProbe: !!r.can_probe, orphans: r.orphans || [],
                           now: r.now, setup: r.setup, version: r.version, maxCatchup: r.max_catchup || 30 });
        S.saved = JSON.stringify(S.doc);
        render();
        pollHls();
    }

    /** Publishes S.doc. Returns the server's reply; on success the playlist is live. */
    async function publish(successText = 'Saved. It\'s live now.') {
        if (S.uploads) { toast('Wait for the uploads to finish (or cancel them) before saving.', 'warn'); return { ok: false, error: 'Uploads are running.' }; }
        S.busy = true; renderBar();
        const r = await api('save', { doc: S.doc, rev: S.rev });
        S.busy = false;
        if (r.ok) {
            Object.assign(S, { doc: r.doc, rev: r.rev, backups: r.backups, files: r.files, hls: r.hls, orphans: r.orphans || [] });
            S.saved = JSON.stringify(S.doc);
            toast(successText);
            pollHls();
        } else if (r.conflict) {
            if (await confirmBox('Someone else saved', r.error + ' Reload to get their version? Your unsaved edits here will be lost.', 'Reload')) {
                S.saved = JSON.stringify(S.doc); M = null; renderModal(); await load();
            }
        } else if (!M) {
            await alertBox('Not saved', [r.error, ...(r.errors || [])].join('\n'));
        }
        render();
        return r;
    }

    /* ---------------------------------------------------------------- toasts and dialogs */

    function toast(text, kind = '') {
        const el = document.createElement('div');
        el.className = 'toast ' + kind;
        el.innerHTML = `${kind ? '' : ic('check')}<span>${esc(text)}</span>`;
        $toasts.appendChild(el);
        setTimeout(() => el.remove(), kind === 'err' ? 8000 : 3500);
    }

    /**
     * A small dialog. Resolves true / false, or with [fields] the values (null when cancelled).
     * Field types: text, password, number, date, datetime, select (options [[v, label]]), checks (options), textarea.
     */
    function dialog({ title, text = '', ok = 'OK', cancel = 'Cancel', danger = false, fields = null, wide = false }) {
        return new Promise(resolve => {
            const field = (f, i) => {
                const name = `f${i}`;
                if (f.type === 'select') return `<select class="sel" name="${name}">${f.options.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(f.value) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
                if (f.type === 'checks') return `<div class="checklist">${f.options.length ? f.options.map(([v, l, sub]) => `<label class="checkrow"><input type="checkbox" name="${name}" value="${esc(v)}">
                    <span><b>${esc(l)}</b>${sub ? `<small>${esc(sub)}</small>` : ''}</span></label>`).join('') : '<p class="muted">Nothing to pick.</p>'}</div>`;
                if (f.type === 'textarea') return `<textarea class="area" name="${name}" rows="4">${esc(f.value || '')}</textarea>`;
                const type = f.type === 'datetime' ? 'datetime-local' : f.type || 'text';
                return `<input class="inp" name="${name}" type="${type}" value="${esc(f.type === 'datetime' ? T.input(f.value) : f.value ?? '')}" ${f.attrs || ''}>`;
            };
            $dialog.innerHTML = `<div class="modal-back dialog-back"><form class="dialog ${wide ? 'wide' : ''}">
                <h3>${esc(title)}</h3>${text ? `<p class="pre">${esc(text)}</p>` : ''}
                ${fields ? `<div class="stack" style="margin-bottom:18px">${fields.map((f, i) => `<div><label class="lbl">${esc(f.label)}</label>${field(f, i)}${f.help ? `<div class="help">${esc(f.help)}</div>` : ''}</div>`).join('')}</div>` : ''}
                <div class="row">${cancel ? `<button type="button" class="btn" data-r="0">${esc(cancel)}</button>` : ''}
                <button type="submit" class="btn ${danger ? 'btn-danger-solid' : 'btn-primary'}">${esc(ok)}</button></div></form></div>`;
            const form = $dialog.querySelector('form');
            const done = v => { $dialog.innerHTML = ''; document.removeEventListener('keydown', onKey, true); resolve(v); };
            const onKey = e => { if (e.key === 'Escape') { e.stopPropagation(); done(fields ? null : false); } };
            document.addEventListener('keydown', onKey, true);
            form.addEventListener('submit', e => {
                e.preventDefault();
                if (!fields) return done(true);
                done(fields.map((f, i) => f.type === 'checks' ? [...form.querySelectorAll(`[name="f${i}"]:checked`)].map(x => x.value)
                    : f.type === 'datetime' ? String(form.elements['f' + i].value).replace('T', ' ') : form.elements['f' + i].value));
            });
            form.querySelector('[data-r="0"]')?.addEventListener('click', () => done(fields ? null : false));
            (form.querySelector('input:not([type=checkbox]),select,textarea') || form.querySelector('[type=submit]')).focus();
        });
    }
    const confirmBox = (title, text, ok = 'Continue', danger = false) => dialog({ title, text, ok, danger });
    const alertBox = (title, text) => dialog({ title, text, cancel: '' });

    function download(name, text, type = 'application/json') {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([text], { type }));
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }

    /* ---------------------------------------------------------------- shell */

    const VIEWS = [
        ['overview', 'Overview', 'home'],
        ['library', 'Library', 'film', () => Object.keys(S.doc.library).length],
        ['live', 'Live channels', 'tv', () => S.doc.live.length],
        ['vod', 'Movies', 'movie', () => S.doc.vod.length],
        ['series', 'Series', 'series', () => S.doc.series.length],
        ['categories', 'Categories', 'tag'],
        ['accounts', 'Accounts', 'user', () => S.doc.accounts.length],
        ['settings', 'Settings', 'gear'],
        ['backups', 'Backups & export', 'history'],
        ['about', 'About & help', 'info'],
    ];
    const HEADS = {
        overview: ['Overview', 'Your playlist at a glance, and the details players need to sign in.'],
        library: ['Library', 'Your videos. Live channels, movies and episodes all play library items.'],
        live: ['Live channels', 'Play your own videos back to back like a TV channel, or relay a stream link. With a guide and catch-up.'],
        vod: ['Movies', 'Library items shown in the Movies section.'],
        series: ['Series', 'Shows with seasons; every episode plays a library item.'],
        categories: ['Categories', 'The groups shown in each section of the players.'],
        accounts: ['Accounts', 'Who can sign in. Each account has its own username and password.'],
        settings: ['Settings', 'Playlist name, time zone and other options.'],
        backups: ['Backups & export', 'A copy is kept before every save. Export the whole playlist to move or keep it.'],
        about: ['About & help', 'Version, terms, and how players connect.'],
    };

    function render() {
        if (!S.doc) return;
        const scroll = window.scrollY;
        const [title, sub] = HEADS[S.view];
        $app.innerHTML = `
            <aside class="side">
                <div class="brand"><span class="brand-mark">JP</span><div><b>${esc(S.doc.settings.name)}</b><small>JSON Playlist Creator</small></div></div>
                ${VIEWS.map(([k, l, i, n], idx) => `${idx === 1 ? '<div class="nav-sec">Content</div>' : idx === 5 ? '<div class="nav-sec">Setup</div>' : ''}
                    <button class="nav ${S.view === k ? 'on' : ''}" data-a="view" data-k="${k}">${ic(i)}<span>${l}</span>${n ? `<span class="count">${n()}</span>` : ''}</button>`).join('')}
                <div class="side-foot">
                    <button class="nav" data-a="password">${ic('key')}<span>Change password</span></button>
                    <button class="nav" data-a="logout">${ic('out')}<span>Log out</span></button>
                </div>
            </aside>
            <main class="main">
                <header class="head"><div class="grow"><h1>${title}</h1><div class="sub">${sub}</div></div>${headActions()}</header>
                <div class="content">${view()}</div>
            </main>
            <div id="jp-bar"></div>`;
        renderBar();
        renderUploads();
        window.scrollTo(0, scroll);
    }

    function headActions() {
        const add = { library: [['upload-many', 'Upload videos', 'upload', ''], ['add-media', 'Add by link', 'link', 'btn-primary']],
                      live: [['add-live', 'Add channel', 'plus', 'btn-primary']], vod: [['add-vod', 'Add movie', 'plus', 'btn-primary']],
                      series: [['add-series', 'Add series', 'plus', 'btn-primary']], accounts: [['add-account', 'Add account', 'plus', 'btn-primary']] }[S.view] || [];
        const search = ['library', 'live', 'vod', 'series'].includes(S.view)
            ? `<label class="search">${ic('search')}<input class="inp" type="search" placeholder="Search" data-search></label>` : '';
        return `<div class="row">${search}${add.map(([a, l, i, c]) => `<button class="btn ${c}" data-a="${a}">${ic(i)}${l}</button>`).join('')}</div>`;
    }

    /** Bottom bar while in-place edits (accounts, settings, categories, order) aren't published yet. */
    function renderBar() {
        const bar = document.getElementById('jp-bar');
        if (!bar) return;
        bar.innerHTML = dirty() && !M ? `<div class="savebar"><span>You have unpublished changes</span>
            <button class="btn btn-ghost btn-sm" data-a="revert">Discard</button>
            <button class="btn btn-primary btn-sm" data-a="publish" ${S.busy || S.uploads ? 'disabled' : ''}>${S.busy ? 'Saving…' : 'Save & publish'}</button></div>` : '';
    }

    function view() {
        return { overview, library: libraryView, live: liveView, vod: vodView, series: seriesView, categories: categoriesView,
                 accounts: accountsView, settings: settingsView, backups: backupsView, about: aboutView }[S.view]();
    }

    /* ---------------------------------------------------------------- views */

    function connection(a) {
        const q = `username=${encodeURIComponent(a.username)}&password=${encodeURIComponent(a.password)}`;
        return [['Server URL', S.base], ['Username', a.username], ['Password', a.password],
                ['M3U playlist link', `${S.base}/get.php?${q}&type=m3u_plus&output=m3u8`], ['Programme guide (XMLTV)', `${S.base}/xmltv.php?${q}`]];
    }

    function connectionCard(a, title) {
        return `<section class="card card-pad"><h2>${esc(title)}</h2>
            <div class="kv">${connection(a).map(([k, v]) => `<div class="k">${k}</div><div class="v"><code>${esc(v)}</code></div>
                <div><button class="btn btn-ghost btn-sm" data-a="copy" data-v="${esc(v)}" title="Copy">${ic('copy')}</button></div>`).join('')}</div>
            <p class="muted small" style="margin:12px 0 0">In a player, choose the <b>JSON Playlist</b> (API) login and enter the server URL, username and password.
                Players that only take M3U links can use the M3U link.</p></section>`;
    }

    function issues() {
        const out = [];
        const saved = savedDoc();
        if (!saved.accounts.some(canSignIn)) out.push(['No account can sign in (add one, or enable one that hasn\'t expired).', 'accounts']);
        for (const [k, m] of lib()) if (!m.file) out.push([`"${m.title || k}" has no video.`, 'edit-media', k]);
        saved.live.forEach((c, i) => {
            if (c.source !== 'files') return;
            const missing = c.items.filter(x => !(S.hls[x.media] || {}).info).length;
            if (missing) out.push([`"${c.name}": ${plural(missing, 'video')} not prepared for live yet, so it can't stream continuously or offer catch-up.`, 'edit-live', i]);
        });
        for (const [t, label] of [['live', 'Live channels'], ['vod', 'Movies'], ['series', 'Series']]) {
            if (saved[t].length && !Object.keys(saved.categories[t] || {}).length) out.push([`${label} has no categories.`, 'categories']);
        }
        return out;
    }

    function overview() {
        const d = S.doc;
        const eps = d.series.reduce((n, x) => n + episodesOf(x), 0);
        const tiles = [['library', 'Library', 'film', Object.keys(d.library).length, '#fde8ea', '#e11d2e'], ['live', 'Live channels', 'tv', d.live.length, '#e8efff', '#2563eb'],
                       ['vod', 'Movies', 'movie', d.vod.length, '#fef3dc', '#b45309'], ['series', 'Series', 'series', `${d.series.length}<small class="muted" style="font-size:13px;font-weight:600"> · ${plural(eps, 'episode')}</small>`, '#e3f6ec', '#0f9d58']];
        const accounts = d.accounts.filter(canSignIn);
        const a = accounts[Math.min(S.account, accounts.length - 1)];
        const list = issues();
        return `<div class="tiles" style="margin-bottom:20px">${tiles.map(([k, l, i, n, bg, fg]) => `<button class="tile" data-a="view" data-k="${k}">
                <span class="ic" style="background:${bg};color:${fg}">${ic(i)}</span><span class="n">${n}</span><span class="t">${l}</span></button>`).join('')}</div>
            <div class="ov-grid">
                <div class="stack">${a ? connectionCard(a, accounts.length > 1 ? `Sign-in details · ${a.username}` : 'Sign-in details') : `<section class="card card-pad"><h2>Sign-in details</h2>
                    <div class="banner banner-warn">No enabled account yet. <button class="btn btn-sm" data-a="view" data-k="accounts">Add one</button></div></section>`}
                    ${accounts.length > 1 ? `<div class="row wrap">${accounts.map((x, i) => `<button class="btn btn-sm ${i === S.account ? 'btn-primary' : ''}" data-a="pick-account" data-i="${i}">${esc(x.username)}</button>`).join('')}</div>` : ''}
                    <section class="card card-pad"><h2>Test</h2><div class="row wrap"><button class="btn" data-a="test">${ic('bolt')}Test like a player</button>
                        <div class="chips">${S.test === 'running' ? '<span class="chip">Testing…</span>' : (S.test || []).map(x => `<span class="chip ${x.ok ? 'green' : 'red'}"><span class="dot"></span>${esc(x.label)}: ${esc(x.detail)}</span>`).join('')}</div></div>
                        <p class="muted small" style="margin:10px 0 0">Signs in through the public API with the first enabled account, and checks that the private data folder is closed.</p></section>
                </div>
                ${gettingStarted()}
                <section class="card card-pad"><h2>Checklist</h2>
                    ${list.length ? `<div class="stack">${list.map(([t, a, k]) => `<div class="banner banner-warn row"><span class="grow">${esc(t)}</span>
                        <button class="btn btn-sm" data-a="${a === 'accounts' || a === 'categories' ? 'view' : a}" data-k="${esc(k ?? a)}" data-i="${esc(k ?? '')}">Fix</button></div>`).join('')}</div>`
                        : `<div class="banner banner-info">Everything looks good.</div>`}
                    <p class="muted small" style="margin:14px 0 0">Times are in ${esc(d.settings.timezone)} (server time now: ${esc(S.now)}).</p>
                </section>
            </div>`;
    }

    function gettingStarted() {
        const d = savedDoc();
        const steps = [
            [Object.keys(d.library).length > 0, 'Add your videos to the <b>Library</b> (upload files or paste links).', 'library'],
            [d.live.length + d.vod.length + d.series.length > 0, 'Create a <b>live channel</b>, <b>movies</b> or a <b>series</b> from them.', 'live'],
            [d.live.every(c => c.source !== 'files' || c.items.every(x => (S.hls[x.media] || {}).info)) && d.live.length > 0, 'Prepare channel videos for live (a button in the channel).', 'live'],
            [!!S.test && S.test !== 'running' && S.test.every(x => x.ok), 'Run <b>Test like a player</b>, then enter the sign-in details in your player.', 'overview'],
        ];
        if (steps.every(s => s[0])) return '';
        return `<section class="card card-pad" style="margin-bottom:20px"><h2>Getting started</h2><ol class="steps">${steps.map(([done, text, v]) =>
            `<li class="${done ? 'done' : ''}">${text} ${done ? '' : `<button class="linklike small" data-a="view" data-k="${v}">Go</button>`}</li>`).join('')}</ol></section>`;
    }

    function poster(src, label) {
        return src ? `<img src="${esc(asset(src))}" alt="" loading="lazy">` : `<span class="ph">${esc((label || '?').trim().slice(0, 1).toUpperCase())}</span>`;
    }

    function hlsChip(key) {
        const h = S.hls[key] || {};
        if (h.running) return '<span class="chip amber">Preparing for live…</span>';
        if (h.info && h.stale) return '<span class="chip amber" title="The video changed since it was prepared">Prepare again</span>';
        if (h.info) return '<span class="chip green">Live-ready</span>';
        return '';
    }

    function libraryView() {
        const cards = lib().map(([k, m]) => {
            const u = usage(k);
            const video = !m.file ? '<span class="chip red">No video</span>' : m.file.startsWith('media/') ? '<span class="chip">Uploaded</span>' : '<span class="chip">Link</span>';
            return `<button class="mcard" data-a="edit-media" data-k="${esc(k)}" data-s="${esc((m.title + ' ' + k + ' ' + m.year).toLowerCase())}">
                <div class="art">${poster(m.poster, m.title)}<div class="badges">${video}${hlsChip(k)}</div></div>
                <div class="t">${esc(m.title || k)}</div><div class="s">${[m.year, dur(m.seconds), u.total ? `used ${u.total}×` : 'not used'].filter(Boolean).map(esc).join(' · ')}</div></button>`;
        }).join('');
        return `<div class="drop" data-drop="library">${ic('upload')}<div><b>Drop video files here</b> or <label class="linklike">choose files<input type="file" multiple hidden accept="${accept()}" data-files="library"></label>.
                Each becomes a library item; uploads continue in the background while you work.</div></div>
            <div class="cards">${cards}</div>${S.files.length ? storage() : ''}
            ${S.orphans.length ? `<div class="banner banner-info row" style="margin-top:18px"><span class="grow">Live segments of ${plural(S.orphans.length, 'deleted library item')} are still on the server.</span>
                <button class="btn btn-sm" data-a="orphans-del">${ic('trash')}Clean up</button></div>` : ''}`;
    }

    const accept = () => S.videoExt.map(x => '.' + x).join(',') + ',video/*';

    function storage() {
        const total = S.files.reduce((n, f) => n + f.size, 0);
        return `<h2 class="section-title" style="margin-top:36px">Uploaded files <span class="muted small">· ${mb(total)} on this server</span></h2>
            <div class="card list">${S.files.map(f => `<div class="lrow static"><div class="grow"><div class="title mono">${esc(f.path.slice(6))}</div>
                <div class="meta">${mb(f.size)} · uploaded ${new Date(f.time * 1000).toLocaleDateString()} · ${f.items.length ? 'used by ' + f.items.map(k => esc(itemTitle(k))).join(', ') : 'not used'}</div></div>
                <button class="btn btn-sm btn-danger" data-a="file-del" data-k="${esc(f.path)}" ${f.items.length ? 'disabled title="A library item uses it"' : ''}>${ic('trash')}Delete</button></div>`).join('')}</div>`;
    }

    function liveView() {
        const cats = S.doc.categories.live || {};
        if (!S.doc.live.length) return emptyState('No channels yet', 'A channel plays your videos back to back around the clock, or relays a stream link, with a programme guide and catch-up.', 'add-live', 'Add channel');
        return `<div class="card list">${S.doc.live.map((c, i) => {
            const files = c.source === 'files';
            const ready = files && c.items.length && c.items.every(x => (S.hls[x.media] || {}).info);
            const length = files ? c.items.reduce((n, x) => n + (+(item(x.media) || {}).seconds || 0), 0) : 0;
            return `<div class="lrow" data-a="edit-live" data-i="${i}" data-s="${esc((c.name + ' ' + c.num).toLowerCase())}">
                <div class="ord"><button class="btn btn-sm" data-a="move" data-t="live" data-i="${i}" data-d="-1" ${i ? '' : 'disabled'} title="Move up">${ic('up')}</button>
                    <button class="btn btn-sm" data-a="move" data-t="live" data-i="${i}" data-d="1" ${i < S.doc.live.length - 1 ? '' : 'disabled'} title="Move down">${ic('down')}</button></div>
                <span class="num">${esc(c.num)}</span>
                ${c.logo ? `<img class="logo" src="${esc(asset(c.logo))}" alt="" loading="lazy">` : '<span class="logo"></span>'}
                <div class="grow"><div class="title">${esc(c.name || 'Unnamed')}</div><div class="meta">${esc(cats[c.cat] || 'No category')} · ${files
                    ? `${plural(c.items.length, 'video')}${length ? ' · ' + dur(length) + (c.loop ? ' loop' : ' once') : ''}` : 'stream link'}</div></div>
                <div class="chips">${files ? (ready ? '<span class="chip green"><span class="dot"></span>Continuous</span>' : '<span class="chip amber" title="Prepare its videos for live">Not prepared</span>') : '<span class="chip blue">Relay</span>'}
                    <span class="chip">${c.guide === 'auto' ? 'Auto guide' : plural(c.programmes.length, 'programme')}</span>
                    ${c.catchup_days > 0 ? `<span class="chip blue">Catch-up ${plural(+c.catchup_days, 'day')}</span>` : ''}</div></div>`;
        }).join('')}</div>`;
    }

    function vodView() {
        const cats = S.doc.categories.vod || {};
        const cards = S.doc.vod.map((v, i) => {
            const m = item(v.media) || {};
            return `<button class="mcard" data-a="edit-vod" data-i="${i}" data-s="${esc(((m.title || '') + ' ' + (cats[v.cat] || '')).toLowerCase())}">
                <div class="art">${poster(m.poster, m.title)}<div class="badges">${v.rating || m.rating ? `<span class="chip">★ ${esc(v.rating || m.rating)}</span>` : ''}</div></div>
                <div class="t">${esc(itemTitle(v.media))}</div><div class="s">${esc([cats[v.cat] || 'No category', m.year].filter(Boolean).join(' · '))}</div></button>`;
        }).join('');
        return `<div class="cards">${cards}<button class="mcard add-card" data-a="add-vod"><div class="art">${ic('plus')}</div><div class="t">Add movie</div><div class="s">Show a library item in Movies</div></button></div>`;
    }

    function seriesView() {
        const cats = S.doc.categories.series || {};
        const cards = S.doc.series.map((s, i) => `<button class="mcard" data-a="edit-series" data-i="${i}" data-s="${esc((s.name + ' ' + (cats[s.cat] || '')).toLowerCase())}">
                <div class="art">${poster(s.cover, s.name)}<div class="badges"><span class="chip">${plural(Object.keys(s.seasons).length, 'season')}</span></div></div>
                <div class="t">${esc(s.name)}</div><div class="s">${esc([cats[s.cat] || 'No category', plural(episodesOf(s), 'episode')].join(' · '))}</div></button>`).join('');
        return `<div class="cards">${cards}<button class="mcard add-card" data-a="add-series"><div class="art">${ic('plus')}</div><div class="t">Add series</div><div class="s">Seasons and episodes</div></button></div>`;
    }

    function emptyState(title, text, action, label) {
        return `<div class="card empty"><b>${esc(title)}</b>${esc(text)}<div style="margin-top:14px"><button class="btn btn-primary" data-a="${action}">${ic('plus')}${esc(label)}</button></div></div>`;
    }

    function categoriesView() {
        const used = (t, id) => S.doc[t].filter(x => x.cat === id).length;
        const block = (t, label, icon) => `<section class="card card-pad cat-card"><h2 class="row">${ic(icon)}${label}</h2>
            ${Object.entries(S.doc.categories[t]).map(([id, name]) => { const n = used(t, id); return `<div class="crow"><span class="id">${esc(id)}</span>
                <input class="inp" data-p="categories.${t}.${id}" value="${esc(name)}"><span class="chip" title="Items in it">${n}</span>
                <button class="btn btn-ghost icon-btn btn-danger" data-a="del-cat" data-t="${t}" data-k="${esc(id)}" ${n ? 'disabled title="Move its items to another category first"' : 'title="Delete"'}>${ic('trash')}</button></div>`; }).join('')
                || '<p class="muted">No categories.</p>'}
            <button class="btn btn-sm" style="margin-top:10px" data-a="add-cat" data-t="${t}">${ic('plus')}Add category</button></section>`;
        return `<div class="grid-3">${block('live', 'Live channels', 'tv')}${block('vod', 'Movies', 'movie')}${block('series', 'Series', 'series')}</div>`;
    }

    function accountsView() {
        if (!S.doc.accounts.length) return emptyState('No accounts yet', 'Players sign in with an account: a username and password you hand out.', 'add-account', 'Add account');
        return `<div class="card list">${S.doc.accounts.map((a, i) => `<div class="lrow static acc">
                <div class="acc-state"><label class="switch" title="${a.enabled ? 'Enabled' : 'Disabled'}"><input type="checkbox" data-p="accounts.${i}.enabled" ${a.enabled ? 'checked' : ''}><span></span></label>
                    ${expired(a) ? '<span class="chip red">Expired</span>' : !a.enabled ? '<span class="chip">Off</span>' : ''}</div>
                <div class="acc-grid">
                    <div><label class="lbl">Username</label>${inp(`accounts.${i}.username`, a.username, 'autocomplete="off"')}</div>
                    <div><label class="lbl">Password</label>${inp(`accounts.${i}.password`, a.password, 'autocomplete="off"')}</div>
                    <div><label class="lbl">Connections</label>${inp(`accounts.${i}.max_connections`, a.max_connections, 'type="number" min="1" max="100"')}</div>
                    <div><label class="lbl">Expires</label>${inp(`accounts.${i}.expires`, a.expires, 'type="date"')}</div>
                    <div class="wide"><label class="lbl">Note</label>${inp(`accounts.${i}.note`, a.note, 'placeholder="Who it is for"')}</div>
                </div>
                <div class="col-actions"><button class="btn btn-sm" data-a="acc-details" data-i="${i}">${ic('copy')}Details</button>
                    <button class="btn btn-sm" data-a="acc-new-pass" data-i="${i}">${ic('key')}New password</button>
                    <button class="btn btn-sm btn-danger" data-a="acc-del" data-i="${i}">${ic('trash')}Delete</button></div></div>`).join('')}</div>
            <p class="muted small" style="margin-top:12px">Changes take effect when you save. An expired or disabled account can't sign in or play.</p>`;
    }

    function settingsView() {
        const s = S.doc.settings;
        return `<section class="card card-pad" style="max-width:900px"><div class="grid-form">
            ${fld('Playlist name', inp('settings.name', s.name, 'maxlength="80"'), 6, 'Shown in this panel and sent to players.', true)}
            ${fld('Time zone', sel('settings.timezone', S.timezones.map(t => [t, t]), s.timezone), 6, 'Schedules, the guide and catch-up use it.')}
            ${fld('Message to players', inp('settings.message', s.message, 'maxlength="300" placeholder="Optional"'), 12, 'Some players show it after signing in.')}
            ${fld('Media base URL', inp('settings.media_base', s.media_base, 'placeholder="https://files.example.com/videos/"'), 12, 'Optional. Only for library items whose video is a bare file name: it\'s put in front of the name. Full links and uploads ignore it.')}
        </div></section>`;
    }

    function backupsView() {
        return `<div class="row wrap" style="margin-bottom:18px"><button class="btn" data-a="export">${ic('download')}Export playlist</button>
                <label class="btn">${ic('upload')}Import playlist…<input type="file" accept=".json,application/json" hidden data-import="1"></label>
                <span class="muted small">The export is the published playlist (catalog.json). Uploaded videos and images stay in media/ and images/.</span></div>
            ${S.backups.length ? `<div class="card list">${S.backups.map((b, i) => `<div class="lrow static"><span class="chip ${i ? '' : 'blue'}">${i ? '#' + (i + 1) : 'Latest'}</span>
                <div class="grow"><div class="title">${new Date(b.time * 1000).toLocaleString()}</div><div class="meta">${Math.max(1, Math.round(b.size / 1024))} KB</div></div>
                <button class="btn btn-sm" data-a="restore" data-k="${esc(b.name)}">${ic('history')}Restore</button></div>`).join('')}</div>`
                : '<div class="card empty"><b>No backups yet</b>One is kept before every save (the last 40).</div>'}`;
    }

    function aboutView() {
        const acc = S.setup ? new Date(S.setup.accepted_at).toLocaleString() : 'unknown';
        const ep = [['player_api.php?username=U&password=P', 'Account and server info'], ['…&action=get_live_categories / get_vod_categories / get_series_categories', 'Categories'],
                    ['…&action=get_live_streams / get_vod_streams / get_series', 'Lists (optional &category_id=)'], ['…&action=get_vod_info&vod_id=ID', 'Movie details'],
                    ['…&action=get_series_info&series_id=ID', 'Seasons and episodes'], ['…&action=get_short_epg&stream_id=ID&limit=N', 'Now and next'],
                    ['…&action=get_simple_data_table&stream_id=ID', 'Full guide of a channel'], ['live/U/P/ID.m3u8', 'Live channel'], ['movie/U/P/ID.ext', 'Movie'],
                    ['series/U/P/EPISODE_ID.ext', 'Episode'], ['timeshift/U/P/MINUTES/YYYY-MM-DD:HH-MM/ID.ts', 'Catch-up'], ['get.php?username=U&password=P&type=m3u_plus', 'M3U playlist'],
                    ['xmltv.php?username=U&password=P', 'Guide (XMLTV)']];
        return `<div class="ov-grid">
            <section class="card card-pad"><h2>Open JSON Playlist API</h2><p class="muted" style="margin-top:0">What players call. Full reference: <code>docs/API.md</code> in this folder.</p>
                <div class="kv">${ep.map(([u, d]) => `<div class="k">${esc(d)}</div><div class="v"><code>${esc(u)}</code></div><div></div>`).join('')}</div></section>
            <section class="card card-pad"><h2>JSON Playlist Creator ${esc(S.version)}</h2>
                <p>A lightweight tool to create a playlist from content you own or are authorized to distribute, and publish it to players in the Open JSON Playlist format.</p>
                <div class="banner banner-info">Terms of use accepted on ${esc(acc)}. Only publish content you own or have the rights holders' permission to distribute.</div>
                <h3 style="margin-top:18px">Server tools</h3>
                <div class="chips"><span class="chip ${S.canBuild ? 'green' : ''}">ffmpeg ${S.canBuild ? 'available' : 'not available'}</span><span class="chip ${S.canProbe ? 'green' : ''}">ffprobe ${S.canProbe ? 'available' : 'not available'}</span></div>
                <p class="muted small">Without ffmpeg, run <code>php tools/build-hls.php &lt;key&gt;</code> on a computer that has it, then upload <code>media/hls/&lt;key&gt;/</code>.</p></section></div>`;
    }

    /* ---------------------------------------------------------------- form parts (data-p = catalog path, data-m = popup draft path) */

    const attr = (path, modal) => modal ? `data-m="${esc(path)}"` : `data-p="${esc(path)}"`;
    const inp = (path, value, extra = '', modal = false) => `<input class="inp" ${attr(path, modal)} value="${esc(value)}" ${extra}>`;
    const area = (path, value, rows = 4, modal = false, extra = '') => `<textarea class="area" ${attr(path, modal)} rows="${rows}" ${extra}>${esc(value)}</textarea>`;
    const sel = (path, options, value, modal = false, extra = '') => `<select class="sel" ${attr(path, modal)} ${extra}>${options.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(value) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
    const fld = (label, html, col = 6, help = '', req = false) => `<div class="c-${col}"><label class="lbl">${label}${req ? ' <span class="req">*</span>' : ''}</label>${html}${help ? `<div class="help">${help}</div>` : ''}</div>`;
    const mInp = (p, v, extra) => inp(p, v, extra, true);
    const mSel = (p, o, v, extra) => sel(p, o, v, true, extra);
    const imgField = (path, value, hint = 'images/… or https://…') => `<div class="img-field">${value ? `<img class="thumb" src="${esc(asset(value))}" alt="">` : '<span class="thumb"></span>'}
        ${mInp(path, value, `placeholder="${esc(hint)}"`)}<label class="btn btn-sm">${ic('upload')}Upload<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden data-image="${esc(path)}"></label></div>`;
    const libOptions = (first = 'Pick a library item…') => [['', first], ...lib().map(([k, m]) => [k, `${m.title || k}${m.year ? ' (' + m.year + ')' : ''}${m.seconds ? ' · ' + dur(m.seconds) : ''}`])];
    const catOptions = t => [['', 'Pick a category…'], ...Object.entries(S.doc.categories[t] || {})];

    function getRef(root, path) {
        const parts = path.split('.');
        const last = parts.pop();
        let o = root;
        for (const k of parts) o = o[k];
        return [o, last];
    }
    const setPath = (root, path, v) => { const [o, k] = getRef(root, path); o[k] = v; };

    /* ---------------------------------------------------------------- popups */

    function openModal(kind, draft, ref, isNew) {
        M = { kind, draft: clone(draft), orig: JSON.stringify(draft), ref, isNew, errors: [], busy: false, videoMode: null, timeline: null };
        renderModal();
        renderBar();
        document.body.style.overflow = 'hidden';
        setTimeout(() => $modal.querySelector('[data-focus]')?.focus(), 30);
        if (kind === 'live') refreshTimeline();
    }

    const modalDirty = () => M && JSON.stringify(M.draft) !== M.orig;

    async function closeModal(force = false) {
        if (!M) return;
        if (!force && modalDirty() && !(await confirmBox('Discard changes?', 'What you changed in this window won\'t be saved.', 'Discard', true))) return;
        M = null;
        $modal.innerHTML = '';
        document.body.style.overflow = '';
        render();
    }

    function renderModal() {
        if (!M) { $modal.innerHTML = ''; return; }
        const body = { media: mediaForm, live: liveForm, vod: vodForm, series: seriesForm }[M.kind]();
        const nouns = { media: 'library item', live: 'channel', vod: 'movie', series: 'series' };
        const name = M.kind === 'media' ? M.draft.title : M.kind === 'vod' ? itemTitle(M.draft.media) : M.draft.name;
        const bodyEl = $modal.querySelector('.m-body');
        const scroll = bodyEl ? bodyEl.scrollTop : 0;
        const focus = document.activeElement && $modal.contains(document.activeElement) ? [document.activeElement.dataset.m, document.activeElement.selectionStart] : null;
        $modal.innerHTML = `<div class="modal-back"><div class="modal" role="dialog" aria-modal="true">
            <div class="m-head"><div class="grow"><h2>${M.isNew ? 'New ' + nouns[M.kind] : esc(name || 'Edit ' + nouns[M.kind])}</h2>
                <div class="sub">${M.isNew ? 'Fill in the details, then save. It goes live right away.' : 'Changes go live when you save.'}</div></div>
                <button class="btn btn-ghost icon-btn" data-a="m-close" title="Close (Esc)">${ic('x')}</button></div>
            <div class="m-body">${M.errors.length ? `<div class="banner banner-danger" style="margin-bottom:18px"><b>Not saved yet. Please fix:</b><ul>${M.errors.map(e => `<li>${esc(e)}</li>`).join('')}</ul></div>` : ''}${body}</div>
            <div class="m-foot">${!M.isNew ? `<button class="btn btn-danger" data-a="m-delete" ${deleteBlock() ? `disabled title="${esc(deleteBlock())}"` : ''}>${ic('trash')}Delete</button>` : ''}
                <span class="spacer"></span><span class="muted small">Ctrl/⌘ S saves</span>
                <button class="btn" data-a="m-close">Cancel</button>
                <button class="btn btn-primary" data-a="m-save" ${M.busy || S.uploads ? 'disabled' : ''}>${M.busy ? 'Saving…' : M.isNew ? 'Add & publish' : 'Save'}</button></div>
        </div></div>`;
        const nb = $modal.querySelector('.m-body');
        if (nb) nb.scrollTop = scroll;
        if (focus && focus[0]) {
            const el = $modal.querySelector(`[data-m="${CSS.escape(focus[0])}"]`);
            if (el) { el.focus(); try { if (focus[1] != null) el.setSelectionRange(focus[1], focus[1]); } catch (e) { /* not a text field */ } }
        }
    }

    function deleteBlock() {
        if (M.kind !== 'media') return '';
        const u = usage(M.ref);
        return u.total ? `Used by ${[u.live && plural(u.live, 'channel'), u.vod && plural(u.vod, 'movie'), u.eps && plural(u.eps, 'episode')].filter(Boolean).join(', ')}. Remove it there first.` : '';
    }

    /* ---- library item */

    function mediaForm() {
        const m = M.draft;
        return `<div class="m-layout"><div class="m-side">
                <div class="preview-poster">${poster(m.poster, m.title)}</div>
                ${m.backdrop ? `<div class="preview-wide"><img src="${esc(asset(m.backdrop))}" alt=""></div>` : ''}
                ${!M.isNew ? usageNote(M.ref) : ''}
            </div><div>
            <section class="fsec"><h3>Basics</h3><div class="grid-form">
                ${fld('Title', mInp('title', m.title, 'data-focus placeholder="e.g. My Holiday Film"'), 8, '', true)}
                ${fld('Year', mInp('year', m.year, 'placeholder="2024" maxlength="4"'), 4)}
                ${fld('Description', area('plot', m.plot, 4, true), 12, 'Shown on info pages, and in the guide when a channel plays it.')}
            </div></section>
            <section class="fsec"><h3>Video <span class="chip ${m.file ? 'green' : 'red'}">${m.file ? 'Set' : 'Required'}</span></h3>${videoField(m)}</section>
            ${!M.isNew ? `<section class="fsec"><h3>Live preparation</h3>${hlsField(M.ref)}</section>` : ''}
            <section class="fsec"><h3>Artwork</h3><div class="grid-form">
                ${fld('Poster (portrait, 2:3)', imgField('poster', m.poster), 6)}
                ${fld('Backdrop (landscape, 16:9)', imgField('backdrop', m.backdrop), 6)}
            </div>${S.canBuild && m.file && (m.file.startsWith('media/') || m.file.includes('://')) ? `<div class="row" style="margin-top:12px">
                <button class="btn btn-sm" data-a="poster" data-v="poster">Poster from the video</button><button class="btn btn-sm" data-a="poster" data-v="backdrop">Backdrop from the video</button>
                <span class="muted small">Takes a frame a little way into the video.</span></div>` : ''}</section>
            <section class="fsec"><h3>Details</h3><div class="grid-form">
                ${fld('Length', `<div class="row">${mInp('seconds', m.seconds || '', 'type="number" min="0" style="max-width:130px"')}<span class="muted">seconds${m.seconds ? ' · ' + dur(m.seconds) : ''}</span></div>`, 6, 'Needed for channel schedules. Read from the video when possible.')}
                ${fld('Rating (0–10)', mInp('rating', m.rating, 'type="number" min="0" max="10" step="0.1"'), 3)}
                ${fld('Age rating', mInp('age_rating', m.age_rating, 'placeholder="PG"'), 3)}
                ${fld('Genre', mInp('genre', m.genre, 'placeholder="Documentary, Travel"'), 4)}
                ${fld('Director', mInp('director', m.director), 4)}
                ${fld('Cast', mInp('cast', m.cast, 'placeholder="Names, comma separated"'), 4)}
                ${fld('Country', mInp('country', m.country), 4)}
                ${fld('Language', mInp('language', m.language), 4)}
                ${fld('Trailer', mInp('trailer', m.trailer, 'placeholder="YouTube link"'), 4)}
                ${fld('Resolution', `<div class="row">${mInp('width', m.width || '', 'type="number" min="0" placeholder="1920"')}<span>×</span>${mInp('height', m.height || '', 'type="number" min="0" placeholder="1080"')}</div>`, 6)}
                ${fld('Rights / license', mInp('license', m.license, 'placeholder="e.g. Own work, CC BY 4.0, licensed from …"'), 6, 'For your records; shown after the description.')}
                ${fld('TMDB id', mInp('tmdb', m.tmdb, 'placeholder="Optional"'), 4, 'Lets players load extra artwork and cast.')}
            </div></section></div></div>`;
    }

    function usageNote(key) {
        const u = usage(key);
        return `<div class="card card-pad small"><b>Used by</b><div class="muted" style="margin-top:4px">${u.total
            ? [u.live && plural(u.live, 'channel'), u.vod && plural(u.vod, 'movie'), u.eps && plural(u.eps, 'episode')].filter(Boolean).join(' · ')
            : 'Nothing yet. Add it to a channel, Movies or a series.'}</div></div>`;
    }

    function videoField(m) {
        const uploaded = (m.file || '').startsWith('media/');
        const mode = M.videoMode || (uploaded ? 'upload' : 'link');
        const busy = !!S.uploads;
        const seg = `<div class="seg" style="margin-bottom:12px"><button type="button" class="${mode === 'link' ? 'on' : ''}" data-a="vmode" data-v="link">Paste a link</button>
            <button type="button" class="${mode === 'upload' ? 'on' : ''}" data-a="vmode" data-v="upload">Upload a file</button></div>`;
        if (mode === 'link') {
            return seg + `<div class="row">${mInp('file', uploaded ? '' : m.file, 'placeholder="https://…/video.mp4" class="inp grow"')}
                <button class="btn" data-a="detect">${ic('clock')}Read length</button></div>
                <div class="help">A direct link to a video file (MP4 with H.264 and AAC plays everywhere), or a bare file name when Settings has a media base URL.</div>`;
        }
        const f = uploaded ? S.files.find(x => x.path === m.file) : null;
        const mine = busy && S.uploads.queue.some(q => q.target === 'modal');
        return seg + `<div class="row wrap">${uploaded ? `<span class="chip ${f || M.uploadedNow ? 'green' : 'red'}">${esc(m.file.slice(6))}${f ? ' · ' + mb(f.size) : M.uploadedNow ? '' : ' · missing'}</span>` : '<span class="muted">No file uploaded yet.</span>'}
            <label class="btn ${busy ? 'disabled' : ''}">${ic('upload')}${uploaded ? 'Replace video…' : 'Choose video…'}<input type="file" hidden accept="${accept()}" data-files="modal" ${busy ? 'disabled' : ''}></label>
            ${mine ? '<span class="muted small">Uploading (see the panel at the bottom right)…</span>' : ''}</div>
            <div class="help">Stored on this server (up to 20 GB; big files go up in parts and survive hiccups). MP4 (H.264 + AAC) plays everywhere.</div>`;
    }

    function hlsField(key) {
        const h = S.hls[key] || {};
        const saved = !!savedDoc().library[key];
        let status;
        if (h.running) status = `<span class="chip amber"><span class="dot"></span>Preparing…</span><span class="small muted">${esc(h.last || '')}</span>`;
        else if (h.info) status = `<span class="chip ${h.stale ? 'amber' : 'green'}"><span class="dot"></span>${h.stale ? 'Video changed: prepare again' : 'Live-ready'}</span><span class="small muted">${dur(h.info.total)} · ${plural(h.info.count, 'segment')} · ${new Date(h.info.built_at * 1000).toLocaleDateString()}${h.info.encoded ? ' · re-encoded' : ''}</span>`;
        else status = `<span class="chip">Not prepared</span><span class="small muted">${h.last ? esc(h.last) : 'Channels need this to play it continuously.'}</span>`;
        return `<div class="row wrap">${status}<span class="spacer"></span>
            ${S.canBuild ? `<button class="btn btn-sm" data-a="hls-build" data-k="${esc(key)}" ${h.running || !saved ? 'disabled' : ''}>${h.info ? 'Prepare again' : 'Prepare for live'}</button>` : ''}
            ${h.info && !h.running ? `<button class="btn btn-sm btn-danger" data-a="hls-del">Remove</button>` : ''}</div>
            <div class="help">Cuts the video into short segments, so live channels can play it back to back with other videos as one continuous stream, and replay any past moment (catch-up).
            ${S.canBuild ? '' : `This server has no ffmpeg: run <code>php tools/build-hls.php ${esc(key)}</code> on a computer with ffmpeg, then upload <code>media/hls/${esc(key)}/</code>.`}</div>`;
    }

    /* ---- live channel */

    function liveForm() {
        const c = M.draft;
        const files = c.source === 'files';
        return `<div class="m-layout"><div class="m-side"><div class="preview-logo">${c.logo ? `<img src="${esc(asset(c.logo))}" alt="">` : ''}</div>${nowCard()}</div><div>
            <section class="fsec"><h3>Channel</h3><div class="grid-form">
                ${fld('Name', mInp('name', c.name, 'data-focus placeholder="e.g. Family Videos"'), 8, '', true)}
                ${fld('Channel number', mInp('num', c.num, 'type="number" min="0"'), 4)}
                ${fld('Category', mSel('cat', catOptions('live'), c.cat), 6, '', true)}
                ${fld('Logo (square)', imgField('logo', c.logo), 6)}
            </div></section>
            <section class="fsec"><h3>What it plays</h3>
                <div class="seg" style="margin-bottom:14px"><button type="button" class="${files ? 'on' : ''}" data-a="src" data-v="files">My videos, back to back</button>
                    <button type="button" class="${files ? '' : 'on'}" data-a="src" data-v="link">A stream link</button></div>
                ${files ? filesEditor(c) : `${fld('Stream link', mInp('link', c.link, 'placeholder="https://…/live.m3u8" class="inp"'), 12, 'The address of a stream you are authorized to relay (HLS .m3u8, MPEG-TS, RTMP, RTSP). Players are sent there.', true)}`}
            </section>
            ${files ? `<section class="fsec"><h3>Timeline</h3>${timelineBlock()}</section>` : ''}
            <section class="fsec"><h3>Programme guide</h3>${guideEditor(c)}</section>
            <section class="fsec"><h3>Catch-up</h3><div class="grid-form">
                ${fld('Keep', `<div class="row">${mInp('catchup_days', c.catchup_days, `type="number" min="0" max="${S.maxCatchup}" style="max-width:100px"`)}<span class="muted">days replayable (0 = off)</span></div>`, 6,
                    files ? 'Viewers can replay any past programme in this window.' : 'Programmes with a recording attached can be replayed in this window.')}
                ${fld('Stream id', mInp('id', c.id, 'type="number" min="1"'), 6, 'Unique among channels. Changing it breaks favourites in players.')}
            </div></section>
            </div></div>`;
    }

    function nowCard() {
        const t = M.timeline;
        if (!t || M.draft.source !== 'files') return '';
        if (t.loading) return '<div class="card card-pad small muted">Working out the schedule…</div>';
        if (!t.ok) return `<div class="card card-pad small"><b>Not on air</b><div class="muted" style="margin-top:4px">${esc((t.problems || [])[0] || 'Add videos.')}</div></div>`;
        if (!t.playing) return `<div class="card card-pad small"><b>Off air now</b><div class="muted" style="margin-top:4px">${T.parse(t.starts) > T.parse(t.now) ? 'Starts ' + esc(T.nice(t.starts)) : 'The one-time run has ended.'}</div></div>`;
        const p = t.playing;
        return `<div class="card card-pad small now"><span class="chip red"><span class="dot"></span>On air now</span><b style="display:block;margin-top:8px">${esc(p.title)}</b>
            <div class="bar"><span style="width:${Math.min(100, p.offset / Math.max(1, p.seconds) * 100)}%"></span></div>
            <div class="muted">${clock(p.offset)} of ${clock(p.seconds)} · video ${p.index + 1}</div></div>`;
    }

    function filesEditor(c) {
        const t = M.timeline && !M.timeline.loading ? M.timeline : null;
        const total = c.items.reduce((n, x) => n + (+(item(x.media) || {}).seconds || 0), 0);
        const notReady = c.items.filter(x => item(x.media) && !(S.hls[x.media] || {}).info).map(x => x.media);
        const unsaved = notReady.filter(k => !savedDoc().library[k]);
        const busy = !!S.uploads;
        return `<div class="playlist">${c.items.map((x, i) => {
                const m = item(x.media) || {};
                const h = S.hls[x.media] || {};
                const playing = t && t.playing && t.playing.index === i;
                return `<div class="pl-row ${playing ? 'playing' : ''}"><span class="eno">${i + 1}</span>
                    ${mSel(`items.${i}.media`, libOptions(), x.media)}
                    <span class="len">${m.seconds ? dur(m.seconds) : '<span class="chip red" title="Set its length in the library">no length</span>'}</span>
                    ${h.running ? '<span class="chip amber">Preparing…</span>' : h.info ? '<span class="chip green">Ready</span>' : x.media ? '<span class="chip">Not prepared</span>' : ''}
                    <span class="spacer"></span>
                    <button class="btn btn-ghost icon-btn" data-a="pl-move" data-i="${i}" data-d="-1" ${i ? '' : 'disabled'} title="Earlier">${ic('up')}</button>
                    <button class="btn btn-ghost icon-btn" data-a="pl-move" data-i="${i}" data-d="1" ${i < c.items.length - 1 ? '' : 'disabled'} title="Later">${ic('down')}</button>
                    <button class="btn btn-ghost icon-btn btn-danger" data-a="pl-del" data-i="${i}" title="Remove from this channel">${ic('x')}</button></div>`;
            }).join('') || '<div class="muted" style="padding:14px">No videos yet. Add some from the library or upload new ones.</div>'}</div>
            ${total && c.items.length > 1 ? `<div class="tl-bar" title="One run of the channel">${c.items.map((x, i) => { const s = +(item(x.media) || {}).seconds || 0;
                return `<span style="flex:${Math.max(s, total / 200)}" class="${t && t.playing && t.playing.index === i ? 'on' : ''}" title="${esc(itemTitle(x.media))} · ${esc(dur(s))}"></span>`; }).join('')}</div>` : ''}
            <div class="row wrap" style="margin-top:12px">
                <button class="btn btn-sm" data-a="pl-add">${ic('plus')}Add from library…</button>
                <label class="btn btn-sm ${busy ? 'disabled' : ''}">${ic('upload')}Upload videos…<input type="file" multiple hidden accept="${accept()}" data-files="channel" ${busy ? 'disabled' : ''}></label>
                ${c.items.length > 1 ? `<button class="btn btn-sm" data-a="pl-shuffle">Shuffle</button>` : ''}
                <span class="spacer"></span>
                ${notReady.length && S.canBuild ? `<button class="btn btn-sm btn-primary" data-a="pl-prepare" ${unsaved.length ? 'disabled title="Save first: new videos are prepared from the published playlist"' : ''}>${ic('bolt')}Prepare ${plural(notReady.length, 'video')} for live</button>` : ''}
            </div>
            <div class="grid-form" style="margin-top:16px">
                ${fld('Schedule starts', `<div class="row"><input class="inp" type="datetime-local" data-m="start" data-dt="1" value="${esc(T.input(c.start))}"><button class="btn btn-sm" data-a="start-now" title="The first video starts at the next whole minute">Start now</button></div>`, 6, `In ${esc(S.doc.settings.timezone)}. The first video starts at this moment; the rest follow back to back.`)}
                ${fld('When the last video ends', `<div class="seg"><button type="button" class="${c.loop ? 'on' : ''}" data-a="loop" data-v="1">Start over</button><button type="button" class="${c.loop ? '' : 'on'}" data-a="loop" data-v="0">Go off air</button></div>`, 6,
                    total ? `One run is ${dur(total)}${c.loop ? `, so it repeats about ${(86400 / total).toFixed(total > 86400 ? 2 : 1)}× a day` : ''}.` : '')}
            </div>
            ${notReady.length ? `<div class="banner banner-warn" style="margin-top:14px">${plural(notReady.length, 'video')} not prepared for live yet. Until then, viewers get the current video from its beginning, and catch-up can't replay exact moments.
                ${S.canBuild ? '' : ' This server has no ffmpeg: see About &amp; help.'}</div>` : ''}`;
    }

    function timelineBlock() {
        const t = M.timeline;
        if (!t || t.loading) return '<p class="muted">Working out the schedule…</p>';
        if (!t.ok) return `<div class="banner banner-warn">${(t.problems || ['Add videos to see the schedule.']).map(esc).join('<br>')}</div>`;
        const all = t.guide || [];
        const at = all.findIndex(p => !p.past);
        const rows = all.slice(Math.max(0, (at < 0 ? all.length : at) - 3), Math.max(0, (at < 0 ? all.length : at) - 3) + 60);
        return `<div class="row" style="margin-bottom:10px"><span class="muted grow">What's on now and next, as players see it (server time now ${esc(T.hm(t.now))}; the list covers the next 24 hours).</span>
                <button class="btn btn-sm" data-a="tl-refresh">${ic('refresh')}Refresh</button></div>
            <div class="sched">${rows.map(p => `<div class="sched-row ${p.now ? 'now' : ''} ${p.past ? 'past' : ''}">
                <span class="when">${esc(T.nice(p.start))}</span><span class="len">${dur(p.seconds)}</span><b class="grow">${esc(p.title)}</b>
                ${p.now ? '<span class="chip red"><span class="dot"></span>Now</span>' : p.archive ? '<span class="chip blue">Replayable</span>' : ''}</div>`).join('') || '<p class="muted">Nothing scheduled in this period.</p>'}</div>`;
    }

    function guideEditor(c) {
        const files = c.source === 'files';
        const auto = files && c.guide === 'auto';
        const head = files ? `<div class="seg" style="margin-bottom:14px"><button type="button" class="${auto ? 'on' : ''}" data-a="guide" data-v="auto">Automatic, from the videos</button>
            <button type="button" class="${auto ? '' : 'on'}" data-a="guide" data-v="manual">Enter programmes by hand</button></div>` : '';
        if (auto) return head + '<p class="muted" style="margin:0">Each video is a programme, with its library title and description, at the time it plays.</p>';
        const ps = c.programmes;
        const recOptions = [['', 'No recording'], ...lib().map(([k, m]) => [k, m.title || k])];
        // Overlaps and gaps, shown on the row they concern.
        const notes = ps.map((p, i) => {
            const n = [];
            if (p.start && p.end && p.end <= p.start) n.push('ends before it starts');
            if (i && ps[i - 1].end && p.start && p.start < ps[i - 1].end) n.push('overlaps the one before');
            if (i && ps[i - 1].end && p.start && p.start > ps[i - 1].end) n.push(`${dur(T.mins(ps[i - 1].end, p.start) * 60)} gap before`);
            return n;
        });
        const now = S.now;
        return head + `<div class="row wrap" style="margin-bottom:10px">
                <button class="btn btn-sm btn-primary" data-a="pg-add">${ic('plus')}Add programme</button>
                <button class="btn btn-sm" data-a="pg-repeat" ${ps.length ? '' : 'disabled'}>Copy a day…</button>
                <label class="btn btn-sm">${ic('upload')}Import XMLTV…<input type="file" hidden accept=".xml,.xmltv,.gz,text/xml,application/xml" data-xmltv="1"></label>
                <span class="spacer"></span>
                <button class="btn btn-sm" data-a="pg-past" ${ps.some(p => p.end && p.end < T.add(now, -60 * 24 * (+c.catchup_days || 0))) ? '' : 'disabled'}>Remove old</button>
                <button class="btn btn-sm btn-danger" data-a="pg-clear" ${ps.length ? '' : 'disabled'}>Clear all</button></div>
            ${ps.length ? `<div class="prog-table"><div class="prog-head"><span>Starts</span><span>Ends</span><span>Title &amp; description</span><span>Recording (catch-up)</span><span></span></div>
                ${ps.map((p, i) => `<div class="prog-row ${p.end && p.end <= now ? 'past' : p.start <= now && now < p.end ? 'now' : ''}">
                    <input class="inp" type="datetime-local" data-m="programmes.${i}.start" data-dt="1" value="${esc(T.input(p.start))}">
                    <div><input class="inp" type="datetime-local" data-m="programmes.${i}.end" data-dt="1" value="${esc(T.input(p.end))}">
                        <div class="quick">${[15, 30, 60, 90, 120].map(x => `<button type="button" data-a="pg-len" data-i="${i}" data-v="${x}">${x < 60 ? x + 'm' : x / 60 + 'h'}</button>`).join('')}</div></div>
                    <div class="stack-tight">${mInp(`programmes.${i}.title`, p.title, 'placeholder="Title"')}${mInp(`programmes.${i}.description`, p.description, 'placeholder="Description (optional)"')}
                        ${notes[i].length ? `<span class="chip ${notes[i].some(x => /overlap|before it/.test(x)) ? 'red' : 'amber'}">${esc(notes[i].join(', '))}</span>` : ''}</div>
                    <div>${recSelect(i, p.archive, recOptions)}</div>
                    <button class="btn btn-ghost icon-btn btn-danger" data-a="pg-del" data-i="${i}" title="Delete">${ic('x')}</button></div>`).join('')}</div>`
                : '<div class="card empty" style="padding:22px"><b>No programmes yet</b>Add them one by one (each starts when the previous one ends), or import an XMLTV file.</div>'}
            <p class="muted small" style="margin-top:8px">Times are in ${esc(S.doc.settings.timezone)}. ${files ? 'Catch-up replays what the channel played, or the recording you attach.' : 'Attach a recording to make a past programme replayable.'}</p>`;
    }

    function recSelect(i, value, options) {
        const isLink = value && !item(value);
        return `${mSel(`programmes.${i}.archive`, isLink ? [...options, [value, 'Link: ' + value]] : options, value)}
            <button type="button" class="linklike small" data-a="pg-link" data-i="${i}">${isLink ? 'Change link' : 'Use a link'}</button>`;
    }

    /** Fetches the server's view of the draft schedule (same code players get), shortly after edits. */
    let tlTimer = null;
    function refreshTimeline(delay = 0) {
        if (!M || M.kind !== 'live') return;
        clearTimeout(tlTimer);
        tlTimer = setTimeout(async () => {
            if (!M || M.kind !== 'live') return;
            const doc = clone(S.doc);
            const index = M.isNew ? doc.live.length : M.ref;
            doc.live[index] = clone(M.draft);
            if (M.timeline) M.timeline.loading = !M.timeline.guide;
            const r = await api('timeline', { doc, index });
            if (!M || M.kind !== 'live') return;
            M.timeline = r.ok ? r : { ok: false, problems: [r.error] };
            if (r.ok) S.now = r.now;
            const a = document.activeElement;
            if (a && $modal.contains(a) && a.tagName === 'SELECT') return; // re-rendering would close an open list
            renderModal();
        }, delay);
    }

    /* ---- movie / series */

    function vodForm() {
        const v = M.draft;
        const m = item(v.media) || {};
        return `<div class="m-layout"><div class="m-side"><div class="preview-poster">${poster(m.poster, m.title)}</div></div><div>
            <section class="fsec"><h3>Movie</h3><div class="grid-form">
                ${fld('Library item', mSel('media', libOptions(), v.media), 8, 'Title, artwork, description and video come from the library item.', true)}
                ${fld('Rating (0–10)', mInp('rating', v.rating, 'type="number" min="0" max="10" step="0.1" placeholder="From the item"'), 4)}
                ${fld('Category', mSel('cat', catOptions('vod'), v.cat), 8, '', true)}
                ${fld('Stream id', mInp('id', v.id, 'type="number" min="1"'), 4, 'Unique among movies.')}
            </div></section>
            ${v.media && m.title ? `<section class="fsec"><h3>From the library</h3><p style="margin:0 0 6px"><b>${esc(m.title)}</b> ${esc([m.year, dur(m.seconds), m.genre].filter(Boolean).join(' · '))}</p>
                <p class="muted" style="margin:0">${esc(m.plot || 'No description yet.')}</p>
                <button class="btn btn-sm" style="margin-top:12px" data-a="edit-media-from" data-k="${esc(v.media)}">Edit this library item…</button></section>` : ''}
            </div></div>`;
    }

    function seriesForm() {
        const s = M.draft;
        const seasons = Object.entries(s.seasons).sort((a, b) => +a[0] - +b[0]);
        return `<div class="m-layout"><div class="m-side"><div class="preview-poster">${poster(s.cover, s.name)}</div>
                ${s.backdrop ? `<div class="preview-wide"><img src="${esc(asset(s.backdrop))}" alt=""></div>` : ''}</div><div>
            <section class="fsec"><h3>Series</h3><div class="grid-form">
                ${fld('Name', mInp('name', s.name, 'data-focus placeholder="e.g. Cooking at Home"'), 8, '', true)}
                ${fld('Category', mSel('cat', catOptions('series'), s.cat), 4, '', true)}
                ${fld('Year', mInp('year', s.year, 'maxlength="4"'), 3)}
                ${fld('Rating (0–10)', mInp('rating', s.rating, 'type="number" min="0" max="10" step="0.1"'), 3)}
                ${fld('Genre', mInp('genre', s.genre), 6)}
                ${fld('Director', mInp('director', s.director), 6)}
                ${fld('Cast', mInp('cast', s.cast), 6)}
                ${fld('Description', area('plot', s.plot, 3, true), 12)}
            </div></section>
            <section class="fsec"><h3>Seasons & episodes <span class="chip">${plural(seasons.length, 'season')} · ${plural(episodesOf(s), 'episode')}</span></h3>
                ${seasons.map(([n, se]) => `<div class="season"><div class="season-head"><span class="no">S${esc(n)}</span>
                    ${mInp(`seasons.${n}.title`, se.title, `placeholder="Season ${esc(n)} title (optional)" class="inp grow"`)}
                    <button class="btn btn-ghost icon-btn btn-danger" data-a="del-season" data-k="${esc(n)}" title="Delete season">${ic('trash')}</button></div>
                    <div style="padding:10px 14px;border-bottom:1px solid var(--line)">${fld('Season image', imgField(`seasons.${n}.thumb`, se.thumb), 12)}</div>
                    ${se.episodes.map((ep, k) => `<div class="ep"><span class="eno">E${k + 1}</span>${mSel(`seasons.${n}.episodes.${k}.media`, libOptions(), ep.media)}
                        ${imgField(`seasons.${n}.episodes.${k}.thumb`, ep.thumb, 'Episode image (optional)')}
                        <button class="btn btn-ghost icon-btn" data-a="ep-move" data-k="${esc(n)}" data-i="${k}" data-d="-1" ${k ? '' : 'disabled'} title="Earlier">${ic('up')}</button>
                        <button class="btn btn-ghost icon-btn btn-danger" data-a="del-ep" data-k="${esc(n)}" data-i="${k}" title="Remove episode">${ic('x')}</button></div>`).join('')}
                    <div class="row wrap" style="padding:10px 14px"><button class="btn btn-sm" data-a="add-ep" data-k="${esc(n)}">${ic('plus')}Add episode</button>
                        <button class="btn btn-sm" data-a="add-eps" data-k="${esc(n)}">${ic('plus')}Add several from the library…</button></div></div>`).join('')}
                <button class="btn" data-a="add-season" ${seasons.length >= 9 ? 'disabled' : ''}>${ic('plus')}Add season</button>
            </section>
            <section class="fsec"><h3>Artwork & trailer</h3><div class="grid-form">
                ${fld('Cover (portrait, 2:3)', imgField('cover', s.cover), 6)}
                ${fld('Backdrop (landscape, 16:9)', imgField('backdrop', s.backdrop), 6)}
                ${fld('Trailer', mInp('trailer', s.trailer, 'placeholder="YouTube link"'), 8)}
                ${fld('Series id', mInp('id', s.id, 'type="number" min="1" max="2000000"'), 4, 'Unique among series.')}
            </div></section></div></div>`;
    }

    /** What's missing, in plain words, before anything is sent. */
    function missing() {
        const d = M.draft, out = [];
        const need = (ok, text) => { if (!ok) out.push(text); };
        if (M.kind === 'media') { need(String(d.title || '').trim(), 'Give it a title.'); need(String(d.file || '').trim(), 'Add a video: paste a link or upload a file.'); }
        if (M.kind === 'live') {
            need(String(d.name || '').trim(), 'Give the channel a name.');
            need(d.cat, 'Pick a category.');
            if (d.source === 'link') need(/^(https?|rtmps?|rtsp|udp|rtp):\/\//i.test(d.link || ''), 'Paste the stream link.');
            else { need(d.items.length, 'Add at least one video.'); d.items.forEach((x, i) => need(item(x.media), `Video ${i + 1}: pick a library item.`)); need(!isNaN(T.parse(d.start)), 'Set when the schedule starts.'); }
        }
        if (M.kind === 'vod') { need(item(d.media), 'Pick a library item.'); need(d.cat, 'Pick a category.'); }
        if (M.kind === 'series') {
            need(String(d.name || '').trim(), 'Give the series a name.'); need(d.cat, 'Pick a category.');
            Object.entries(d.seasons).forEach(([n, se]) => se.episodes.forEach((ep, k) => need(item(ep.media), `Season ${n}, episode ${k + 1}: pick a library item.`)));
        }
        return out;
    }

    async function saveModal() {
        if (!M || M.busy) return;
        if (S.uploads) return toast('Wait for the uploads to finish first.', 'warn');
        const gaps = missing();
        if (gaps.length) { M.errors = gaps; renderModal(); $modal.querySelector('.m-body').scrollTop = 0; return; }
        const before = clone(S.doc);
        const d = clone(M.draft);
        let key = M.ref;
        if (M.kind === 'media') {
            if (M.isNew) key = newKey(d.title);
            S.doc.library[key] = d;
        } else {
            const list = S.doc[M.kind];
            if (M.isNew) list.push(d); else list[M.ref] = d;
        }
        M.busy = true; M.errors = []; renderModal();
        const r = await publish(M.isNew ? 'Added. It\'s live now.' : 'Saved. It\'s live now.');
        if (!M) return;
        M.busy = false;
        if (!r.ok) {
            if (!r.conflict) S.doc = before;
            M.errors = r.errors && r.errors.length ? r.errors : [r.error || 'Not saved.'];
            renderModal();
            $modal.querySelector('.m-body').scrollTop = 0;
            return;
        }
        // A channel stays open after the first save so its videos can be prepared right away.
        if (M.kind === 'live' && M.draft.source === 'files' && M.draft.items.some(x => !(S.hls[x.media] || {}).info) && S.canBuild) {
            M.ref = M.isNew ? S.doc.live.length - 1 : M.ref; M.isNew = false; M.draft = clone(S.doc.live[M.ref]); M.orig = JSON.stringify(M.draft);
            renderModal(); refreshTimeline();
            return toast('Saved. Now prepare its videos for live.');
        }
        closeModal(true);
    }

    function newKey(title) {
        const base = slug(title);
        let k = base, n = 2;
        while (S.doc.library[k]) k = `${base}-${n++}`;
        return k;
    }

    async function deleteFromModal() {
        const nouns = { media: 'library item', live: 'channel', vod: 'movie', series: 'series' };
        if (deleteBlock()) return;
        if (!(await confirmBox(`Delete this ${nouns[M.kind]}?`, 'It disappears from players right away. A backup of the playlist is kept.', 'Delete', true))) return;
        const before = clone(S.doc);
        if (M.kind === 'media') delete S.doc.library[M.ref]; else S.doc[M.kind].splice(M.ref, 1);
        const r = await publish('Deleted.');
        if (!r.ok) { S.doc = before; M.errors = r.errors && r.errors.length ? r.errors : [r.error]; renderModal(); return; }
        closeModal(true);
    }

    /* ---------------------------------------------------------------- uploads (one queue, shown bottom right) */

    function videoSeconds(src) {
        return new Promise(resolve => {
            const v = document.createElement('video');
            const url = src instanceof File ? URL.createObjectURL(src) : src;
            const done = s => { clearTimeout(t); if (src instanceof File) URL.revokeObjectURL(url); v.removeAttribute('src'); v.load(); resolve(s); };
            const t = setTimeout(() => done(null), 15000);
            v.preload = 'metadata';
            v.muted = true;
            v.onloadedmetadata = () => done(isFinite(v.duration) && v.duration > 0 ? Math.round(v.duration) : null);
            v.onerror = () => done(null);
            v.src = url;
        });
    }

    /**
     * Uploads files one by one. [target]: 'library' (each becomes a library item, then everything is published),
     * 'channel' (library items added to the open channel), 'modal' (replaces the open library item's video).
     */
    async function uploadFiles(fileList, target) {
        const files = [...fileList].filter(f => {
            const ok = S.videoExt.includes((f.name.split('.').pop() || '').toLowerCase());
            if (!ok) toast(`${f.name}: not a supported video (${S.videoExt.join(', ')}).`, 'err');
            return ok;
        });
        if (!files.length) return;
        if (S.uploads) return toast('Uploads are already running. Wait for them to finish.', 'warn');
        if (target === 'library' && dirty() && !(await confirmBox('Unpublished changes', 'The new library items are published when the uploads finish, together with your other unpublished changes. Continue?', 'Continue'))) return;
        S.uploads = { queue: files.map(f => ({ file: f, status: 'waiting', pct: 0, text: '', target })), cancelled: false };
        renderUploads(); if (M) renderModal(); renderBar();
        let added = 0;
        for (const q of S.uploads.queue) {
            if (S.uploads.cancelled) break;
            q.status = 'uploading';
            const r = await uploadOne(q);
            if (!r) { q.status = S.uploads.cancelled ? 'cancelled' : 'failed'; renderUploads(); continue; }
            q.status = 'done';
            const seconds = r.seconds || q.seconds || 0;
            if (target === 'modal' && M && M.kind === 'media') {
                M.draft.file = r.path;
                if (seconds) M.draft.seconds = seconds;
                if (r.poster && !M.draft.poster) M.draft.poster = r.poster;
                if (!M.draft.title) M.draft.title = titleFromFile(q.file.name);
                M.videoMode = 'upload'; M.uploadedNow = true;
            } else {
                const key = newKey(titleFromFile(q.file.name));
                S.doc.library[key] = { title: titleFromFile(q.file.name), file: r.path, seconds, year: '', poster: r.poster || '', backdrop: '', genre: '', director: '', cast: '', plot: '',
                                       license: '', country: '', language: '', age_rating: '', tmdb: '', rating: '', trailer: '', width: 0, height: 0, bitrate: 0 };
                if (target === 'channel' && M && M.kind === 'live') M.draft.items.push({ media: key });
                added++;
            }
            renderUploads();
        }
        const cancelled = S.uploads.cancelled;
        const failed = S.uploads.queue.filter(q => q.status === 'failed').length;
        setTimeout(() => { if (S.uploads && S.uploads.queue.every(q => q.status !== 'uploading' && q.status !== 'waiting')) { S.uploads = null; renderUploads(); renderBar(); if (M) renderModal(); } }, failed ? 8000 : 2500);
        S.uploads.finished = true;
        const f = await api('files');
        if (f.ok) S.files = f.files;
        if (target === 'library' && added) {
            S.uploads = null; renderUploads();
            await publish(`${plural(added, 'video')} added to the library.`);
        } else if (target === 'channel' && added) {
            toast(`${plural(added, 'video')} uploaded and added. Save the channel to publish.`);
            refreshTimeline(200);
        } else if (target === 'modal' && !cancelled && !failed) {
            toast('Video uploaded. Save to use it.');
        }
        if (cancelled) toast('Uploads cancelled.', 'warn');
        if (M) renderModal(); else render();
    }

    /** One file in parts, in order; each part is retried up to 5 times. Returns {path, seconds} or null. */
    async function uploadOne(q) {
        const file = q.file;
        const id = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
        const total = Math.ceil(file.size / S.chunk);
        q.seconds = await videoSeconds(file);
        const started = Date.now();
        for (let i = 0; i < total; i++) {
            let r;
            for (let attempt = 1; attempt <= 5; attempt++) {
                if (S.uploads.cancelled) return null;
                const fd = new FormData();
                fd.append('action', 'upload_chunk'); fd.append('upload_id', id); fd.append('index', i); fd.append('total', total);
                fd.append('size', file.size); fd.append('name', file.name); fd.append('chunk_bytes', S.chunk);
                fd.append('chunk', file.slice(i * S.chunk, Math.min(file.size, (i + 1) * S.chunk)), 'chunk');
                r = await postForm(fd);
                if (r.ok || r.login) break;
                if (!r.retry && !/Retrying/.test(r.error || '')) break; // a real refusal: stop
                q.text = `Retrying part ${i + 1} (${attempt}/5)…`; renderUploads();
                await new Promise(res => setTimeout(res, 1000 * attempt));
            }
            if (r.login) { await alertBox('Session ended', r.error); location.reload(); return null; }
            if (!r.ok) { q.text = r.error; toast(`${file.name}: ${r.error}`, 'err'); return null; }
            const sent = Math.min(file.size, (i + 1) * S.chunk);
            const rate = sent / Math.max(1, (Date.now() - started) / 1000);
            const left = rate > 0 ? Math.round((file.size - sent) / rate) : 0;
            q.pct = Math.floor(sent / file.size * 100);
            q.text = `${mb(sent)} of ${mb(file.size)} · ${mb(rate)}/s${left > 5 ? ` · ${left > 90 ? Math.round(left / 60) + ' min' : left + ' s'} left` : ''}`;
            renderUploads();
            if (r.done) return r;
        }
        return null;
    }

    function renderUploads() {
        let el = document.getElementById('jp-uploads');
        if (!S.uploads) { el?.remove(); return; }
        if (!el) { el = document.createElement('div'); el.id = 'jp-uploads'; el.className = 'uploads'; document.body.appendChild(el); }
        const q = S.uploads.queue;
        const done = q.filter(x => x.status === 'done').length;
        el.innerHTML = `<div class="row"><b class="grow">Uploads · ${done} of ${q.length}</b>${S.uploads.finished ? '' : '<button class="btn btn-sm btn-danger" data-a="up-cancel">Cancel</button>'}</div>
            ${q.map(x => `<div class="up-row ${x.status}"><div class="row"><span class="grow name">${esc(x.file.name)}</span><span class="small muted">${{ waiting: 'waiting', uploading: x.pct + '%', done: 'done', failed: 'failed', cancelled: 'cancelled' }[x.status]}</span></div>
                ${x.status === 'uploading' ? `<div class="progress"><div style="width:${x.pct}%"></div></div>` : ''}${x.text && x.status !== 'done' ? `<div class="small muted">${esc(x.text)}</div>` : ''}</div>`).join('')}`;
        el.querySelector('[data-a="up-cancel"]')?.addEventListener('click', () => { if (S.uploads) S.uploads.cancelled = true; });
    }

    /* ---------------------------------------------------------------- live preparation status */

    let hlsTimer = null;
    function pollHls() {
        clearTimeout(hlsTimer);
        if (!Object.values(S.hls).some(h => h.running)) return;
        hlsTimer = setTimeout(async () => {
            const r = await api('hls');
            if (r.ok) {
                const was = Object.keys(S.hls).filter(k => S.hls[k].running);
                S.hls = r.hls;
                const finished = was.filter(k => !S.hls[k]?.running);
                if (finished.length) {
                    const failed = finished.filter(k => !S.hls[k]?.info);
                    if (failed.length) toast(`Preparing failed for ${failed.map(itemTitle).join(', ')}: ${S.hls[failed[0]]?.last || 'see the server log'}`, 'err');
                    else toast(`${plural(finished.length, 'video')} ready for live.`);
                    refreshTimeline(100);
                }
                if (M) renderModal(); else render();
            }
            pollHls();
        }, 3000);
    }

    async function prepare(keys) {
        const r = await api('hls_build', { keys });
        if (!r.ok) return toast(r.error, 'err');
        S.hls = r.hls;
        pollHls();
        toast(r.started ? `Preparing ${plural(keys.length, 'video')} for live. This runs on the server; you can keep working.` : 'Already being prepared.');
        if (M) renderModal(); else render();
    }

    /* ---------------------------------------------------------------- events */

    function onInput(e) {
        const el = e.target;
        if (el.matches('[data-search]')) {
            const q = el.value.trim().toLowerCase();
            $app.querySelectorAll('[data-s]').forEach(c => c.classList.toggle('hidden', q !== '' && !c.dataset.s.includes(q)));
            return;
        }
        let v = el.type === 'checkbox' ? el.checked : el.value;
        if (el.dataset.dt) v = String(v).replace('T', ' ');
        else if (el.type === 'number') v = v === '' ? '' : +v;
        if (el.dataset.m && M) {
            setPath(M.draft, el.dataset.m, v);
            if (M.kind === 'live' && /^(start|items|programmes\.\d+\.(start|end))/.test(el.dataset.m)) refreshTimeline(600);
            return;
        }
        if (el.dataset.p) { setPath(S.doc, el.dataset.p, v); renderBar(); }
    }
    $app.addEventListener('input', onInput);
    $modal.addEventListener('input', onInput);
    $app.addEventListener('change', e => { if (e.target.type === 'checkbox' || e.target.tagName === 'SELECT') { onInput(e); renderBar(); } });

    $modal.addEventListener('change', async e => {
        const el = e.target;
        if (el.dataset.files) { uploadFiles(el.files, el.dataset.files); el.value = ''; return; }
        if (el.dataset.image) {
            const f = el.files[0];
            if (!f) return;
            const fd = new FormData();
            fd.append('action', 'upload_image'); fd.append('image', f);
            const r = await postForm(fd);
            if (!r.ok) return toast(r.error, 'err');
            setPath(M.draft, el.dataset.image, r.path);
            return renderModal();
        }
        if (el.dataset.xmltv) { importXmltv(el.files[0]); el.value = ''; return; }
        if (el.tagName === 'SELECT' || el.dataset.dt || /poster|cover|logo|backdrop|thumb/.test(el.dataset.m || '')) {
            renderModal();
            if (M && M.kind === 'live') refreshTimeline(100);
        }
    });

    $app.addEventListener('change', e => {
        const el = e.target;
        if (el.dataset.files) { uploadFiles(el.files, el.dataset.files); el.value = ''; }
        if (el.dataset.import) { importCatalog(el.files[0]); el.value = ''; }
    });

    // Drag and drop onto the library.
    $app.addEventListener('dragover', e => { const z = e.target.closest('[data-drop]'); if (z) { e.preventDefault(); z.classList.add('over'); } });
    $app.addEventListener('dragleave', e => e.target.closest('[data-drop]')?.classList.remove('over'));
    $app.addEventListener('drop', e => { const z = e.target.closest('[data-drop]'); if (!z) return; e.preventDefault(); z.classList.remove('over'); uploadFiles(e.dataTransfer.files, 'library'); });

    async function onClick(e) {
        const el = e.target.closest('[data-a]');
        if (!el || el.disabled) return;
        const d = el.dataset;
        if (el.classList.contains('lrow') && e.target.closest('button,input,label') && e.target.closest('button,input,label') !== el) return;
        switch (d.a) {
            case 'view': S.view = d.k; window.scrollTo(0, 0); return render();
            case 'publish': return publish();
            case 'revert': if (await confirmBox('Discard unpublished changes?', 'Everything goes back to what\'s live.', 'Discard', true)) { S.doc = savedDoc(); render(); } return;
            case 'logout': if (!dirty() || await confirmBox('Log out?', 'You have unpublished changes. They\'ll be lost.', 'Log out', true)) { S.saved = JSON.stringify(S.doc); document.getElementById('jp-logout').submit(); } return;
            case 'password': return changePassword();
            case 'copy': navigator.clipboard?.writeText(d.v).then(() => toast('Copied.'), () => toast('Copy failed; select the text instead.', 'warn')); return;
            case 'pick-account': S.account = +d.i; return render();
            case 'test': {
                S.test = 'running'; render();
                const r = await api('test');
                S.test = r.ok ? r.results : [{ label: 'Test', ok: false, detail: r.error }];
                return render();
            }
            case 'move': {
                const l = S.doc[d.t], i = +d.i, j = i + +d.d;
                if (j < 0 || j >= l.length) return;
                [l[i], l[j]] = [l[j], l[i]];
                return render();
            }
            case 'add-cat': {
                const v = await dialog({ title: 'New category', fields: [{ label: 'Name', value: '' }], ok: 'Add' });
                if (!v || !v[0].trim()) return;
                const c = S.doc.categories[d.t];
                c[String(Math.max(0, ...Object.keys(c).map(Number)) + 1)] = v[0].trim();
                return render();
            }
            case 'del-cat': delete S.doc.categories[d.t][d.k]; return render();
            case 'orphans-del': {
                const r = await api('orphans_delete');
                if (!r.ok) return toast(r.error, 'err');
                S.orphans = r.orphans; toast('Cleaned up.');
                return render();
            }
            case 'file-del': {
                if (!(await confirmBox('Delete this file?', 'It\'s removed from the server for good.', 'Delete', true))) return;
                const r = await api('file_delete', { path: d.k });
                if (!r.ok) return toast(r.error, 'err');
                S.files = r.files; toast('File deleted.');
                return render();
            }
            case 'restore': {
                if (!(await confirmBox('Restore this backup?', 'The live playlist is replaced by it (the current one is backed up first).' + (dirty() ? ' Your unpublished changes are lost.' : ''), 'Restore'))) return;
                const r = await api('restore', { name: d.k, rev: S.rev });
                if (!r.ok) return alertBox('Not restored', [r.error, ...(r.errors || [])].join('\n'));
                Object.assign(S, { doc: r.doc, rev: r.rev, backups: r.backups });
                S.saved = JSON.stringify(S.doc);
                toast('Backup restored. It\'s live now.');
                return render();
            }
            case 'export': {
                const stamp = new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-');
                download(`playlist-${slug(S.doc.settings.name)}-${stamp}.json`, JSON.stringify(savedDoc(), null, 2));
                return toast('Exported.');
            }

            // accounts
            case 'add-account': {
                const a = { username: 'user' + randomString(4), password: randomString(10), enabled: true, max_connections: 1, expires: '', note: '' };
                S.doc.accounts.push(a);
                return render();
            }
            case 'acc-del':
                if (!(await confirmBox('Delete this account?', 'Players signed in with it stop working once you save.', 'Delete', true))) return;
                S.doc.accounts.splice(+d.i, 1); return render();
            case 'acc-new-pass': S.doc.accounts[+d.i].password = randomString(10); return render();
            case 'acc-details': {
                const a = S.doc.accounts[+d.i];
                const text = connection(a).map(([k, v]) => `${k}: ${v}`).join('\n');
                navigator.clipboard?.writeText(text).then(() => toast('Sign-in details copied.'), () => {});
                return alertBox('Sign-in details', text + (dirty() ? '\n\nSave first: unsaved changes don\'t work in players yet.' : ''));
            }

            // popups
            case 'upload-many': return $app.querySelector('[data-files="library"]')?.click();
            case 'add-media':
                return openModal('media', { title: '', file: '', seconds: 0, year: '', poster: '', backdrop: '', genre: '', director: '', cast: '', plot: '', license: '',
                                            country: '', language: '', age_rating: '', tmdb: '', rating: '', trailer: '', width: 0, height: 0, bitrate: 0 }, null, true);
            case 'edit-media': if (!S.doc.library[d.k]) return; S.view = 'library'; render(); return openModal('media', S.doc.library[d.k], d.k, false);
            case 'edit-media-from': await closeModal(); if (M) return; S.view = 'library'; render(); return openModal('media', S.doc.library[d.k], d.k, false);
            case 'add-live': {
                const l = S.doc.live;
                return openModal('live', { id: nextId(l, 100), num: Math.max(0, ...l.map(c => +c.num || 0)) + 1, name: '', cat: Object.keys(S.doc.categories.live)[0] || '', logo: '',
                                           source: 'files', link: '', items: [], start: T.nextHalfHour(S.now), loop: true, guide: 'auto', programmes: [], catchup_days: 3 }, null, true);
            }
            case 'edit-live': return openModal('live', S.doc.live[+d.i], +d.i, false);
            case 'add-vod': return openModal('vod', { id: nextId(S.doc.vod, 1000), media: '', cat: Object.keys(S.doc.categories.vod)[0] || '', rating: '', added: S.now }, null, true);
            case 'edit-vod': return openModal('vod', S.doc.vod[+d.i], +d.i, false);
            case 'add-series':
                return openModal('series', { id: nextId(S.doc.series, 100), name: '', cat: Object.keys(S.doc.categories.series)[0] || '', year: String(new Date().getFullYear()),
                                             rating: '', cover: '', backdrop: '', trailer: '', genre: '', director: '', cast: '', plot: '', added: S.now,
                                             seasons: { 1: { title: '', thumb: '', episodes: [] } } }, null, true);
            case 'edit-series': return openModal('series', S.doc.series[+d.i], +d.i, false);

            // inside a popup
            case 'm-close': return closeModal();
            case 'm-save': return saveModal();
            case 'm-delete': return deleteFromModal();
            case 'vmode': M.videoMode = d.v; return renderModal();
            case 'detect': {
                const f = M.draft;
                if (!f.file) return toast('Paste the video link first.', 'warn');
                el.disabled = true;
                let sec = 0;
                if (S.canProbe && (f.file.includes('://') || f.file.startsWith('media/'))) { const r = await api('probe', { src: f.file }); sec = r.ok ? r.seconds : 0; }
                if (!sec) sec = await videoSeconds(f.file.includes('://') ? f.file : f.file.startsWith('media/') ? asset(f.file) : (S.doc.settings.media_base || '') + f.file) || 0;
                if (sec) { f.seconds = sec; toast(`Length: ${dur(sec)}.`); } else toast('Couldn\'t read the length from that link (the host may block it). Type it in.', 'warn');
                return renderModal();
            }
            case 'poster': {
                el.disabled = true;
                const r = await api('poster', { src: M.draft.file, seconds: +M.draft.seconds || 0 });
                if (!r.ok) { el.disabled = false; return toast(r.error, 'err'); }
                M.draft[d.v] = r.path;
                return renderModal();
            }
            case 'hls-build':
                if (modalDirty() && !(await confirmBox('Unsaved changes', 'Videos are prepared from the published playlist. Save first to include your changes. Prepare anyway?', 'Prepare'))) return;
                return prepare([d.k]);
            case 'hls-del': {
                if (!(await confirmBox('Remove the live preparation?', 'Channels with this video stop streaming continuously until it is prepared again.', 'Remove', true))) return;
                const r = await api('hls_delete', { key: M.ref });
                if (!r.ok) return toast(r.error, 'err');
                S.hls = r.hls;
                return renderModal();
            }

            // channel
            case 'src': M.draft.source = d.v; if (d.v === 'link') M.draft.guide = 'manual'; renderModal(); return refreshTimeline();
            case 'loop': M.draft.loop = d.v === '1'; renderModal(); return refreshTimeline(100);
            case 'guide': M.draft.guide = d.v; renderModal(); return refreshTimeline(100);
            case 'tl-refresh': return refreshTimeline();
            case 'start-now': M.draft.start = T.add(T.fmt(Math.floor(T.parse(S.now) / 60000) * 60000), 1); renderModal(); return refreshTimeline(100);
            case 'pl-add': {
                const used = new Set(M.draft.items.map(x => x.media));
                const opts = lib().map(([k, m]) => [k, m.title || k, [dur(m.seconds) || 'no length', used.has(k) ? 'already on this channel' : '', (S.hls[k] || {}).info ? 'live-ready' : ''].filter(Boolean).join(' · ')]);
                const v = await dialog({ title: 'Add from the library', fields: [{ label: 'Pick videos (added in this order)', type: 'checks', options: opts }], ok: 'Add', wide: true });
                if (!v || !v[0].length) return;
                v[0].forEach(k => M.draft.items.push({ media: k }));
                renderModal(); return refreshTimeline(100);
            }
            case 'pl-move': { const l = M.draft.items, i = +d.i, j = i + +d.d; if (j < 0 || j >= l.length) return; [l[i], l[j]] = [l[j], l[i]]; renderModal(); return refreshTimeline(300); }
            case 'pl-del': M.draft.items.splice(+d.i, 1); renderModal(); return refreshTimeline(300);
            case 'pl-shuffle': { const l = M.draft.items; for (let i = l.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [l[i], l[j]] = [l[j], l[i]]; } renderModal(); return refreshTimeline(100); }
            case 'pl-prepare': return prepare(M.draft.items.map(x => x.media).filter(k => item(k) && !(S.hls[k] || {}).info).filter((k, i, a) => a.indexOf(k) === i));

            // manual guide
            case 'pg-add': {
                const ps = M.draft.programmes;
                const last = ps[ps.length - 1];
                const start = last && last.end ? last.end : T.nextHalfHour(S.now);
                ps.push({ start, end: T.add(start, last ? Math.max(5, T.mins(last.start, last.end)) : 30), title: '', description: '', archive: '' });
                renderModal();
                const rows = $modal.querySelectorAll('.prog-row');
                rows[rows.length - 1]?.querySelector('[data-m$=".title"]')?.focus();
                return refreshTimeline(300);
            }
            case 'pg-del': M.draft.programmes.splice(+d.i, 1); renderModal(); return refreshTimeline(300);
            case 'pg-len': { const p = M.draft.programmes[+d.i]; if (!p.start) return; p.end = T.add(p.start, +d.v); renderModal(); return refreshTimeline(300); }
            case 'pg-link': {
                const p = M.draft.programmes[+d.i];
                const v = await dialog({ title: 'Recording link', fields: [{ label: 'Link to the recording of this programme', value: p.archive && !item(p.archive) ? p.archive : '', attrs: 'placeholder="https://…/recording.mp4"' }], ok: 'Use link' });
                if (v === null) return;
                p.archive = v[0].trim();
                return renderModal();
            }
            case 'pg-past': {
                const cutoff = T.add(S.now, -60 * 24 * (+M.draft.catchup_days || 0));
                const before = M.draft.programmes.length;
                M.draft.programmes = M.draft.programmes.filter(p => !p.end || p.end >= cutoff);
                toast(`${plural(before - M.draft.programmes.length, 'programme')} removed.`);
                return renderModal();
            }
            case 'pg-clear':
                if (!(await confirmBox('Remove every programme?', 'The guide of this channel becomes empty (when you save).', 'Remove all', true))) return;
                M.draft.programmes = []; renderModal(); return refreshTimeline();
            case 'pg-repeat': return repeatDay();

            // series
            case 'add-season': {
                const s = M.draft.seasons;
                const n = Math.max(0, ...Object.keys(s).map(Number)) + 1;
                if (n > 9) return toast('At most 9 seasons.', 'warn');
                s[n] = { title: '', thumb: '', episodes: [] };
                return renderModal();
            }
            case 'del-season': {
                if (Object.keys(M.draft.seasons).length <= 1) return toast('A series needs at least one season.', 'warn');
                if (!(await confirmBox(`Remove season ${d.k}?`, 'Its episodes are removed too (when you save).', 'Remove', true))) return;
                delete M.draft.seasons[d.k];
                return renderModal();
            }
            case 'add-ep': M.draft.seasons[d.k].episodes.push({ media: '', thumb: '' }); return renderModal();
            case 'add-eps': {
                const v = await dialog({ title: 'Add episodes', fields: [{ label: 'Pick library items (added in this order)', type: 'checks', options: lib().map(([k, m]) => [k, m.title || k, dur(m.seconds)]) }], ok: 'Add', wide: true });
                if (!v || !v[0].length) return;
                v[0].forEach(k => M.draft.seasons[d.k].episodes.push({ media: k, thumb: '' }));
                return renderModal();
            }
            case 'ep-move': { const l = M.draft.seasons[d.k].episodes, i = +d.i, j = i + +d.d; if (j < 0) return; [l[i], l[j]] = [l[j], l[i]]; return renderModal(); }
            case 'del-ep': M.draft.seasons[d.k].episodes.splice(+d.i, 1); return renderModal();
        }
    }
    $app.addEventListener('click', onClick);
    $modal.addEventListener('click', e => { if (e.target.classList.contains('modal-back')) return closeModal(); onClick(e); });

    function randomString(n) {
        const chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        return Array.from(crypto.getRandomValues(new Uint32Array(n)), x => chars[x % chars.length]).join('');
    }

    /** Copies the programmes of one day to the following days (for a weekly or daily schedule). */
    async function repeatDay() {
        const ps = M.draft.programmes;
        const days = [...new Set(ps.map(p => T.day(p.start)).filter(Boolean))].sort();
        const v = await dialog({ title: 'Copy a day', text: 'Copies every programme that starts on that day to the following days, at the same times.', ok: 'Copy',
            fields: [{ label: 'Day to copy', type: 'select', options: days.map(x => [x, T.nice(x + ' 00:00').replace(/ 00:00$/, '')]), value: days[days.length - 1] },
                     { label: 'Copy to how many following days', type: 'number', value: 6, attrs: 'min="1" max="60"' },
                     { label: 'Every', type: 'select', options: [['1', 'day'], ['7', 'week (same weekday)']], value: '1' }] });
        if (!v) return;
        const [day, count, every] = [v[0], Math.max(1, Math.min(60, +v[1] || 1)), +v[2]];
        const src = ps.filter(p => T.day(p.start) === day);
        let added = 0, skipped = 0;
        for (let k = 1; k <= count; k++) {
            for (const p of src) {
                const n = { ...p, start: T.add(p.start, 1440 * every * k), end: T.add(p.end, 1440 * every * k) };
                if (ps.some(x => x.start < n.end && n.start < x.end)) { skipped++; continue; }
                ps.push(n); added++;
            }
        }
        ps.sort((a, b) => a.start.localeCompare(b.start));
        toast(`${plural(added, 'programme')} added${skipped ? `, ${skipped} skipped (time already taken)` : ''}.`);
        renderModal(); refreshTimeline(200);
    }

    async function importXmltv(file) {
        if (!file) return;
        const fd = new FormData();
        fd.append('action', 'import_xmltv'); fd.append('file', file);
        toast('Reading the guide…');
        const r = await postForm(fd);
        if (!r.ok) return toast(r.error, 'err');
        const v = await dialog({ title: 'Import programmes', text: r.truncated ? 'The file is large: only the first 20,000 programmes were read.' : '', ok: 'Import', wide: true,
            fields: [{ label: 'Channel in the file', type: 'select', options: r.channels.map(c => [c.id, `${c.name} (${plural(c.count, 'programme')})`]), value: r.channels[0].id },
                     { label: 'Existing programmes', type: 'select', options: [['merge', 'Keep them; skip imported ones that overlap'], ['replace', 'Replace them all']], value: 'merge' }] });
        if (!v) return;
        const incoming = r.programmes[v[0]] || [];
        let ps = v[1] === 'replace' ? [] : M.draft.programmes;
        let added = 0;
        for (const p of incoming) {
            if (ps.some(x => x.start < p.end && p.start < x.end)) continue;
            ps.push(p); added++;
        }
        M.draft.programmes = ps.sort((a, b) => a.start.localeCompare(b.start));
        toast(`${plural(added, 'programme')} imported. Save to publish.`);
        renderModal(); refreshTimeline(200);
    }

    async function importCatalog(file) {
        if (!file) return;
        let doc;
        try { doc = JSON.parse(await file.text()); } catch (e) { return toast('That file is not valid JSON.', 'err'); }
        if (!(await confirmBox('Import this playlist?', 'It replaces the published playlist (the current one is backed up first). Uploaded videos and images referenced by it must already be on this server.', 'Import'))) return;
        const r = await api('import_catalog', { doc, rev: S.rev });
        if (!r.ok) return alertBox('Not imported', [r.error, ...(r.errors || [])].join('\n'));
        Object.assign(S, { doc: r.doc, rev: r.rev, backups: r.backups });
        S.saved = JSON.stringify(S.doc);
        toast('Imported. It\'s live now.');
        render();
    }

    async function changePassword() {
        const v = await dialog({ title: 'Change admin password', ok: 'Change password', fields: [
            { label: 'Current password', type: 'password' }, { label: 'New password (10+ characters)', type: 'password' }, { label: 'Repeat the new password', type: 'password' }] });
        if (!v) return;
        if (v[1] !== v[2]) return toast('The new passwords don\'t match.', 'err');
        const r = await api('change_password', { current: v[0], new: v[1] });
        toast(r.ok ? 'Password changed.' : r.error, r.ok ? '' : 'err');
    }

    window.addEventListener('beforeunload', e => { if (dirty() || S.uploads || modalDirty()) { e.preventDefault(); e.returnValue = ''; } });
    window.addEventListener('scroll', () => document.querySelector('.head')?.classList.toggle('scrolled', window.scrollY > 4), { passive: true });
    document.addEventListener('keydown', e => {
        if ($dialog.innerHTML) return;
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') { e.preventDefault(); if (M) saveModal(); else if (dirty()) publish(); }
        else if (e.key === 'Escape' && M) closeModal();
    });
    // The "on air now" card follows the clock while a channel is open.
    setInterval(() => {
        const a = document.activeElement;
        const picking = a && $modal.contains(a) && (a.tagName === 'SELECT' || a.type === 'datetime-local' || a.type === 'date');
        if (M && M.kind === 'live' && M.draft.source === 'files' && !document.hidden && !picking) refreshTimeline();
    }, 30000);

    load();
})();
