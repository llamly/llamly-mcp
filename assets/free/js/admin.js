/* Llamly Free · admin.
   The approved admin design, wired to real data and the Llamly REST API.
   Shell: header, 5 tabs by query string (&tab=), modal, toast, copy and "..." menus.
   No secret goes to localStorage, URL, persisted attribute or console.
   Text: English source through wp.i18n (domain llamly); pt_BR ships in languages/. */
(function () {
	'use strict';

	const config = window.LLAMLY_FREE_BOOTSTRAP || {};
	const mount = document.getElementById('ll-app');
	if (!mount || !window.LLAMLY_FREE_ICONS || !window.wp || !window.wp.i18n) return;
	const {__, _n, _x, sprintf} = window.wp.i18n;
	// Numbers in the admin language format (WordPress puts the user locale in <html lang>).
	const LOCALE = document.documentElement.lang || 'en-US';
	const fmtNum = (n, opts) => { try { return Number(n).toLocaleString(LOCALE, opts); } catch (e) { return Number(n).toLocaleString('en-US', opts); } };

	/* ---------- utilities ---------- */
	const ESC = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};
	const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ESC[c]);
	const $ = (sel, root) => (root || document).querySelector(sel);
	const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
	const icon = (name, cls) => '<span class="ll-icon ' + (cls || '') + '" aria-hidden="true">' + ((window.LLAMLY_FREE_ICONS && window.LLAMLY_FREE_ICONS[name]) || '') + '</span>';
	const asset = (path) => String(config.assetUrl || '').replace(/\/$/, '') + '/' + String(path).replace(/^\//, '');
	const adminPath = (path) => String(config.adminRoot || '').replace(/\/?$/, '/') + String(path).replace(/^\//, '');
	const plainUrl = (url) => String(url || '').replace(/&amp;/g, '&');
	const pill = (text, tone) => '<span class="ll-pill' + (tone ? ' ll-pill--' + tone : '') + '">' + esc(text) + '</span>';
	const clone = (v) => JSON.parse(JSON.stringify(v == null ? {} : v));

	const MONTHS = [_x('Jan', 'month abbreviation', 'llamly'), _x('Feb', 'month abbreviation', 'llamly'), _x('Mar',
		'month abbreviation', 'llamly'), _x('Apr', 'month abbreviation', 'llamly'), _x('May', 'month abbreviation', 'llamly'),
		_x('Jun', 'month abbreviation', 'llamly'), _x('Jul', 'month abbreviation', 'llamly'), _x('Aug', 'month abbreviation',
		'llamly'), _x('Sep', 'month abbreviation', 'llamly'), _x('Oct', 'month abbreviation', 'llamly'), _x('Nov',
		'month abbreviation', 'llamly'), _x('Dec', 'month abbreviation', 'llamly')];
	function parseUtc(value) {
		if (!value) return null;
		const d = new Date(String(value).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(String(value)) ? '' : 'Z'));
		return Number.isNaN(d.getTime()) ? null : d;
	}
	const pad = (n) => String(n).padStart(2, '0');
	const hm = (d) => pad(d.getHours()) + ':' + pad(d.getMinutes());
	function sameDay(a, b) { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }
	/** "Today", "Yesterday" or a short date. inline=true gives the form used inside a sentence ("today", "yesterday"). */
	function dayLabel(value, inline) {
		const d = value instanceof Date ? value : parseUtc(value);
		if (!d) return '—';
		const now = new Date();
		const y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
		if (sameDay(d, now)) return inline ? __('today', 'llamly') : __('Today', 'llamly');
		if (sameDay(d, y)) return inline ? __('yesterday', 'llamly') : __('Yesterday', 'llamly');
		/* translators: short date. 1: day of the month, 2: abbreviated month name, 3: year. */
		return sprintf(__('%2$s %1$d, %3$d', 'llamly'), d.getDate(), MONTHS[d.getMonth()], d.getFullYear());
	}
	function dayTime(value, inline) {
		const d = parseUtc(value);
		/* translators: 1: day ("Today", "Yesterday" or a short date), 2: time (HH:MM). */
		return d ? sprintf(__('%1$s, %2$s', 'llamly'), dayLabel(d, inline), hm(d)) : '—';
	}
	function relative(value) {
		const d = parseUtc(value);
		if (!d) return '—';
		const diff = Math.max(0, Date.now() - d.getTime());
		const min = Math.floor(diff / 60000);
		if (min < 1) return __('just now', 'llamly');
		/* translators: %d: number of minutes. */
		if (min < 60) return sprintf(_n('%d minute ago', '%d minutes ago', min, 'llamly'), min);
		const h = Math.floor(min / 60);
		/* translators: %d: number of hours. */
		if (h < 24 && sameDay(d, new Date())) return sprintf(_n('%d hour ago', '%d hours ago', h, 'llamly'), h);
		const now = new Date();
		const y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
		/* translators: %s: time (HH:MM). */
		if (sameDay(d, y)) return sprintf(__('yesterday, %s', 'llamly'), hm(d));
		/* translators: %s: time (HH:MM). */
		if (sameDay(d, now)) return sprintf(__('today, %s', 'llamly'), hm(d));
		return dayLabel(d);
	}

	/* ---------- API ---------- */
	const ERRORS = {
		stale_revision: __('The content changed after this change. Refresh the list and undo the newer changes first.', 'llamly'),
		confirmation_required: __('This action needs a new confirmation. Try again.', 'llamly'),
		already_undone: __('This change was already undone.', 'llamly'),
		already_redone: __('This change was already redone.', 'llamly'),
		undo_cycle_closed: __('This change was already undone and redone once.', 'llamly'),
		nothing_to_redo: __('Undo the change before redoing it.', 'llamly'),
		undo_expired: __('The time to undo this change is over.', 'llamly'),
		force_not_allowed: __('Block changes can\'t be force undone. Undo the newer changes first.', 'llamly'),
		operation_in_progress: __('Another change is in progress on this content. Try again in a moment.', 'llamly'),
		recovery_required: __('An interrupted change is blocking this content. Review and release it in Activity.', 'llamly'),
		journal_unavailable: __('The change log is unavailable. Nothing was changed.', 'llamly'),
		restore_mismatch: __('WordPress changed the restored content while saving. The action was canceled.', 'llamly'),
		write_not_ready: __('Writing is paused on this site.', 'llamly'),
		forbidden: __('Your user doesn\'t have permission for this action.', 'llamly'),
		rest_cookie_invalid_nonce: __('Your session expired. Reload the page.', 'llamly'),
		rest_forbidden: __('Your user doesn\'t have permission for this action.', 'llamly'),
		invalid_credential: __('Give the key a name (up to 120 characters) and choose the access.', 'llamly'),
		invalid_scope: __('Choose at least one tool.', 'llamly'),
		credential_unavailable: __('The key couldn\'t be created. No key was issued.', 'llamly'),
		connection_not_found: __('This connection no longer exists. Refresh the page.', 'llamly'),
		storage_unavailable: __('The database didn\'t confirm the change. Nothing was saved.', 'llamly'),
		settings_unavailable: __('The saved settings are invalid. Nothing was changed.', 'llamly'),
		invalid_settings: __('One of the values isn\'t valid. Check the fields and try again.', 'llamly'),
		operation_not_found: __('This item is no longer available. Refresh the list.', 'llamly'),
		restore_generation_changed: __('The site database was restored after this change. It can no longer be undone or redone.', 'llamly'),
		object_not_found: __('The item no longer exists.', 'llamly'),
		approval_expired: __('The approval expired. Ask the agent to repeat the request.', 'llamly'),
		approval_not_found: __('This request is no longer waiting for approval.', 'llamly'),
		approval_rejected: __('This request was rejected.', 'llamly'),
		invalid_arguments: __('The data sent isn\'t valid.', 'llamly'),
		idempotency_mismatch: __('This request was already used with different data.', 'llamly'),
	};
	const SETTINGS_ERRORS = {
		stale_revision: __('The settings changed in another session. Reload the page to see the current version. Your draft is still here.', 'llamly'),
		confirmation_required: __('This change needs confirmation. Save again and confirm.', 'llamly'),
	};
	// A failed READ must not say that a write failed. Storage errors on GET get a read message.
	const READ_ERRORS = {
		storage_unavailable: __('The database couldn\'t be read right now. Nothing was changed. Try again.', 'llamly'),
		journal_unavailable: __('The change log couldn\'t be read right now. Nothing was changed. Try again.', 'llamly'),
	};

	async function api(path, opts) {
		const o = opts || {};
		const method = o.method || 'GET';
		const headers = {'X-WP-Nonce': String(config.nonce || ''), Accept: 'application/json'};
		if (o.body !== undefined) headers['Content-Type'] = 'application/json';
		// _locale=user: server strings (notices, reasons, Doctor, error messages, item labels) follow the admin's language, like this script.
		const url = adminPath(path);
		let response;
		try {
			response = await fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + '_locale=user', {method: method,
				credentials: 'same-origin', cache: 'no-store', headers: headers, body: o.body === undefined ? undefined :
				JSON.stringify(o.body)});
		} catch (e) {
			const failure = new Error(__('No response from the server. Check your connection and try again.', 'llamly'));
			failure.status = 0; failure.code = 'network';
			throw failure;
		}
		let payload = null;
		try { payload = await response.json(); } catch (e) { payload = null; }
		if (!response.ok) {
			const code = payload && payload.code ? String(payload.code) : '';
			const map = Object.assign({}, ERRORS, method === 'GET' ? READ_ERRORS : {}, o.errors || {});
			const message = map[code] || (payload && (payload.message || payload.error) ? String(payload.message ||
				payload.error) : __('The request wasn\'t completed.', 'llamly'));
			const failure = new Error(message);
			failure.status = response.status; failure.code = code;
			failure.data = payload && payload.data && typeof payload.data === 'object' ? payload.data : null;
			throw failure;
		}
		return payload;
	}

	/* ---------- state ---------- */
	const S = {data: null, conns: null};
	const site = () => (S.data && S.data.site) || config.site || {};
	const server = () => (S.data && S.data.server) || {};
	const settings = () => (S.data && S.data.settings) || {};
	const tools = () => (S.conns && Array.isArray(S.conns.tools) ? S.conns.tools : (S.data && Array.isArray(S.data.tools) ? S.data.tools : []));
	const connItems = () => (S.conns && Array.isArray(S.conns.items) ? S.conns.items : (S.data && Array.isArray(S.data.connections) ? S.data.connections : []));
	const activeConns = () => connItems().filter((x) => !x.revoked_at && x.status !== 'revoked' && x.label !== DOCTOR_KEY_LABEL);
	const writeEnabled = () => !!(S.conns && 'write_enabled' in S.conns ? S.conns.write_enabled : server().write_enabled);
	const endpoint = () => site().endpoint || config.mcpEndpoint || '';
	// LLP-467: the last "Add to <app>" click and how far the app got (server-side, from the audit log).
	const attemptState = () => (S.conns && 'attempt' in S.conns ? S.conns.attempt : (S.data && S.data.attempt)) || null;
	const windowState = () => (S.conns && S.conns.window) || settings().window || {open: false, seconds_left: 0};
	// Stored label of the Doctor's temporary key (server value, compared as data, never translated).
	const DOCTOR_KEY_LABEL = 'Temporary Llamly Doctor probe';

	async function loadState() {
		S.data = await api('state');
		return S.data;
	}
	async function loadConnections() {
		S.conns = await api('connections');
		return S.conns;
	}

	/* ---------- toast ---------- */
	function toast(message, opts) {
		const o = opts || {};
		let stack = $('.ll-toasts');
		if (!stack) {
			stack = document.createElement('div');
			stack.className = 'll-toasts llamly';
			stack.setAttribute('role', 'status');
			stack.setAttribute('aria-live', 'polite');
			document.body.appendChild(stack);
		}
		const node = document.createElement('div');
		node.className = 'll-toast' + (o.tone === 'error' ? ' ll-toast--error' : '');
		node.innerHTML = icon(o.icon || (o.tone === 'error' ? 'alert-circle' : 'check-circle-2')) + '<span>' + esc(message) +
			'</span>' + (o.actionLabel ? '<button type="button">' + esc(o.actionLabel) + '</button>' : '');
		if (o.actionLabel) node.querySelector('button').addEventListener('click', () => { if (o.onAction) o.onAction(); node.remove(); });
		stack.appendChild(node);
		setTimeout(() => node.remove(), o.duration || (o.tone === 'error' ? 7000 : 4200));
	}
	const fail = (error, fallback) => toast((error && error.message) || fallback || __('The action wasn\'t completed.', 'llamly'), {tone: 'error'});

	/* ---------- copy ---------- */
	async function copyText(text, btn) {
		try {
			await navigator.clipboard.writeText(String(text));
		} catch (e) {
			const area = document.createElement('textarea');
			area.value = String(text);
			area.setAttribute('readonly', '');
			area.style.position = 'fixed'; area.style.opacity = '0';
			document.body.appendChild(area); area.select();
			const ok = document.execCommand('copy'); area.remove();
			if (!ok) { toast(__('The browser didn\'t allow copying. Select the text and copy it manually.', 'llamly'), {tone: 'error'}); return false; }
		}
		if (btn) {
			const old = btn.innerHTML;
			btn.classList.add('is-done');
			btn.innerHTML = icon('check') + '<span>' + esc(__('Copied', 'llamly')) + '</span>';
			setTimeout(() => { if (btn.isConnected) { btn.classList.remove('is-done'); btn.innerHTML = old; } }, 1600);
		}
		return true;
	}
	// Copyable values live in an in-memory map, never in an attribute (the key goes into the commands).
	const copyStore = new Map();
	let copySeq = 0;
	function copyRef(text) {
		const id = 'c' + (++copySeq);
		copyStore.set(id, String(text));
		return id;
	}
	document.addEventListener('click', (ev) => {
		const btn = ev.target.closest('[data-copy-ref]');
		if (btn) { ev.preventDefault(); const v = copyStore.get(btn.getAttribute('data-copy-ref')); if (v !== undefined) copyText(v, btn); }
	});

	/* ---------- blocks ---------- */
	function codeBlock(label, codeHtml, copyPlain) {
		return '<div class="ll-code"><div class="ll-code__bar"><span>' + esc(label) + '</span>' +
			'<button type="button" class="ll-copy" data-copy-ref="' + copyRef(copyPlain) + '">' + icon('copy') + '<span>' +
				esc(__('Copy', 'llamly')) + '</span></button></div>' +
			'<pre><code>' + codeHtml + '</code></pre></div>';
	}
	function copyField(value, label) {
		return '<div class="ll-copyfield"><code title="' + esc(value) + '">' + esc(value) + '</code>' +
			'<button type="button" class="ll-btn ll-btn--sm" data-copy-ref="' + copyRef(value) + '">' + icon('copy') + '<span>' +
				esc(label || __('Copy', 'llamly')) + '</span></button></div>';
	}
	function switchEl(name, checked, label, disabled) {
		return '<label class="ll-switch"><span class="ll-switch-label">' + esc(label) + '</span>' +
			'<input type="checkbox" name="' + esc(name) + '"' + (checked ? ' checked' : '') + (disabled ? ' disabled' : '') +
				'><span class="ll-switch__track"></span></label>';
	}

	/* ---------- modal ---------- */
	let lastFocus = null;
	function modal(o) {
		closeModal(true);
		const dismissible = o.dismissible !== false;
		const back = document.createElement('div');
		back.className = 'll-modal-backdrop llamly';
		back.innerHTML = '<div class="ll-modal' + (o.wide ? ' ll-modal--wide' : '') + '" role="dialog" aria-modal="true" aria-labelledby="ll-modal-title">' +
			'<div class="ll-modal__head"><div><h2 class="ll-modal__title" id="ll-modal-title">' + o.title + '</h2>' + (o.sub ?
				'<p class="ll-modal__sub">' + o.sub + '</p>' : '') + '</div>' +
			(dismissible ?
				'<button type="button" class="ll-btn ll-btn--ghost ll-btn--icon ll-btn--sm" data-modal-close aria-label="' +
				esc(__('Close', 'llamly')) + '">' + icon('x') + '</button>' : '') + '</div>' +
			'<div class="ll-modal__body">' + (o.body || '') + '</div>' +
			(o.actions ? '<div class="ll-modal__foot">' + o.actions + '</div>' : '') + '</div>';
		lastFocus = document.activeElement;
		document.body.appendChild(back);
		back.addEventListener('click', (ev) => {
			if (ev.target.closest('[data-modal-close]') || (dismissible && ev.target === back)) { closeModal(); if (o.onCancel) o.onCancel(); }
		});
		const focusable = back.querySelector('input:not([disabled]), select, textarea, .ll-modal__foot .ll-btn--primary, .ll-modal__foot .ll-btn--danger, .ll-modal__foot .ll-btn, button');
		if (focusable) focusable.focus();
		if (o.onMount) o.onMount(back);
		return back;
	}
	function closeModal(silent) {
		const m = $('.ll-modal-backdrop');
		if (m) { m.remove(); if (!silent && lastFocus && lastFocus.focus && lastFocus.isConnected) lastFocus.focus(); }
	}
	document.addEventListener('keydown', (ev) => {
		const close = ev.key === 'Escape' && $('.ll-modal-backdrop [data-modal-close]');
		if (close) close.click();
	});
	const cancelBtn = () => '<button type="button" class="ll-btn" data-modal-close>' + esc(__('Cancel', 'llamly')) + '</button>';
	/** Confirm button that shows a busy state and keeps the modal open if the API fails. */
	function busyButton(btn, fn) {
		return async () => {
			if (btn.disabled) return;
			const old = btn.innerHTML;
			btn.disabled = true;
			btn.innerHTML = icon('loader-2', 'll-spin') + '<span>' + esc(btn.textContent.trim()) + '</span>';
			try { await fn(); } catch (error) { fail(error); } finally { if (btn.isConnected) { btn.disabled = false; btn.innerHTML = old; } }
		};
	}
	function confirmModal(o) {
		return new Promise((resolve) => {
			let done = false;
			const finish = (v) => { if (!done) { done = true; resolve(v); } };
			modal({
				title: o.title, sub: o.sub, body: o.body,
				actions: '<button type="button" class="ll-btn" data-modal-close>' + esc(o.cancelLabel || __('Cancel', 'llamly')) +
					'</button><button type="button" class="ll-btn ' + (o.tone || 'll-btn--primary') + '" data-ok>' + esc(o.okLabel) +
					'</button>',
				onCancel: () => finish(false),
				onMount: (m) => $('[data-ok]', m).addEventListener('click', () => { closeModal(); finish(true); }),
			});
		});
	}

	/* ---------- menus "..." ---------- */
	document.addEventListener('click', (ev) => {
		const trigger = ev.target.closest('[data-menu-trigger]');
		$$('.llamly .ll-menu').forEach((m) => {
			if (!trigger || m !== trigger.nextElementSibling) {
				m.hidden = true;
				const t = m.previousElementSibling;
				if (t && t.hasAttribute('data-menu-trigger')) t.setAttribute('aria-expanded', 'false');
			}
		});
		if (trigger) {
			const menu = trigger.nextElementSibling;
			menu.hidden = !menu.hidden;
			trigger.setAttribute('aria-expanded', String(!menu.hidden));
		}
	});

	/* ---------- routing by query (&tab=) ---------- */
	const TAB_ORDER = ['connections', 'activity', 'logs', 'settings', 'about'];
	const tabs = {};
	const moduleSections = Array.isArray(config.sections) ? config.sections.filter((s) => s && /^[a-z][a-z0-9_-]*$/.test(String(s.id || ''))) : [];
	const params = () => new URLSearchParams(location.search);
	const param = (k, d) => params().get(k) || d || '';
	function tabOrder() { return TAB_ORDER.concat(moduleSections.map((s) => 'module-' + s.id)); }
	// Old (pre-1.0) tab slugs, accepted only so bookmarks keep working. The server redirects them too.
	const LEGACY_TABS = {conexoes: 'connections', atividade: 'activity', configuracoes: 'settings', sobre: 'about'};
	function activeTab() {
		const raw = param('tab', 'connections');
		const t = Object.prototype.hasOwnProperty.call(LEGACY_TABS, raw) ? LEGACY_TABS[raw] : raw;
		if (t !== raw) { const p = params(); p.set('tab', t); history.replaceState({}, '', location.pathname + '?' + p.toString()); }
		return Object.prototype.hasOwnProperty.call(tabs, t) ? t : 'connections';
	}
	function tabHref(id) { const p = new URLSearchParams({page: param('page', 'llamly'), tab: id}); return location.pathname + '?' + p.toString(); }

	let leaveGuard = null; // async function that decides whether the current tab may be left
	async function navigate(patch, opts) {
		const o = opts || {};
		const p = params();
		const nextTab = patch.tab !== undefined ? patch.tab : p.get('tab');
		if (leaveGuard && nextTab !== p.get('tab') && !(await leaveGuard())) return false;
		Object.keys(patch).forEach((k) => { const v = patch[k]; if (v === null || v === undefined || v === '') p.delete(k); else p.set(k, v); });
		if (!p.get('page')) p.set('page', 'llamly');
		const url = location.pathname + '?' + p.toString();
		if (o.replace) history.replaceState({}, '', url); else history.pushState({}, '', url);
		render();
		return true;
	}
	let shownUrl = location.href;
	window.addEventListener('popstate', async () => {
		// Back/forward must not silently drop an unsaved settings draft.
		if (leaveGuard && !(await leaveGuard())) { history.pushState({}, '', shownUrl); return; }
		render();
	});

	/* ---------- shell ---------- */
	function header(active) {
		const enabled = !!server().enabled;
		const status = '<span class="ll-row ll-faint" style="gap:8px"><span class="ll-dot ' + (enabled ? 'll-dot--success' :
			'll-dot--off') + '"></span>' + esc(enabled ? _x('MCP server on', 'header status', 'llamly') : __('MCP server off',
			'llamly')) + '</span>';
		const nav = tabOrder().filter((id) => tabs[id]).map((id) => {
			const t = tabs[id];
			const count = t.count ? t.count() : '';
			return '<a class="ll-tab" href="' + esc(tabHref(id)) + '" data-tab="' + esc(id) + '"' + (id === active ?
				' aria-current="page"' : '') + '>' + esc(t.label) + (count ? '<span class="ll-tab__count">' + esc(count) + '</span>' :
				'') + '</a>';
		}).join('');
		return '<header class="ll-header"><div class="ll-header__inner"><div class="ll-header__top">' +
			'<a class="ll-brand" href="' + esc(tabHref('connections')) + '" data-tab="connections" aria-label="' + esc(__('Llamly, home', 'llamly')) + '">' +
			'<span class="ll-brand__mark"><img src="' + esc(asset('assets/brand/llamly-mark.svg')) + '" alt=""></span>' +
			'<span class="ll-brand__word">' + (window.LLAMLY_FREE_BRAND_WORDMARK || 'Llamly') + '</span></a>' +
			'<div class="ll-header__status">' + status + '</div></div>' +
			'<nav class="ll-tabs" aria-label="' + esc(__('Llamly sections', 'llamly')) + '">' + nav + '</nav></div></header>';
	}

	let lastTab = null;
	/** LAY-02: while Safe Mode is off, every tab says so and offers to turn it back on. */
	function safeModeNotice(main) {
		if (!main || settings().safe_mode !== false || $('[data-safe-off]', main)) return;
		const box = document.createElement('div');
		box.className = 'll-callout ll-callout--warning';
		box.setAttribute('data-safe-off', '');
		box.innerHTML = icon('shield-alert') + '<div class="ll-callout__body"><span class="ll-callout__title">' +
			esc(__('Safe mode is off', 'llamly')) + '</span><span>' +
			esc(__('Agents can move to trash and delete, undo changes and make changes that can\'t be undone (like granting administrator access) without asking you first.',
			'llamly')) +
			'</span></div><div class="ll-callout__actions"><button type="button" class="ll-btn ll-btn--sm ll-btn--dark">' +
			esc(__('Turn on safe mode', 'llamly')) + '</button></div>';
		main.prepend(box);
		const btn = $('button', box);
		btn.addEventListener('click', async () => {
			btn.disabled = true;
			try {
				await withRevision((revision) => api('settings', {method: 'PUT', body: {settings: {safe_mode: true}, revision: revision}, errors: SETTINGS_ERRORS}));
				await loadState();
				refresh(); toast(__('Safe mode on', 'llamly'), {icon: 'shield-check'});
			} catch (error) {
				fail(error);
				if (btn.isConnected) btn.disabled = false;
			}
		});
	}

	function render() {
		shownUrl = location.href;
		if (!S.data) return;
		const active = activeTab();
		if (param('tab') !== active) {
			const p = params(); p.set('tab', active); if (!p.get('page')) p.set('page', 'llamly');
			history.replaceState({}, '', location.pathname + '?' + p.toString());
		}
		if (active !== 'settings') leaveGuard = null;
		if (active !== 'connections') stopPoll();
		copyStore.clear();
		const keepScroll = lastTab === active ? window.scrollY : 0;
		mount.innerHTML = header(active) + '<main class="ll-main" id="ll-main" tabindex="-1"></main>';
		$$('.ll-tab, .ll-brand', mount).forEach((a) => a.addEventListener('click', async (ev) => {
			if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button === 1) return;
			ev.preventDefault();
			const target = a.dataset.tab;
			// After choosing a tab (mouse or Enter), focus moves to the panel instead of falling back to <body>.
			if (await navigate({tab: target, client: null, novo: null})) {
				const main = $('#ll-main', mount);
				if (main && activeTab() === target) main.focus({preventScroll: true});
			}
		}));
		tabs[active].render($('#ll-main', mount));
		safeModeNotice($('#ll-main', mount));
		/* translators: browser tab title. 1: Llamly tab name, 2: site name. */
		document.title = sprintf(__('%1$s ‹ Llamly ‹ %2$s — WordPress', 'llamly'), tabs[active].label, site().name || '');
		window.scrollTo(0, lastTab !== active ? 0 : keepScroll);
		lastTab = active;
	}
	const refresh = () => render();

	function renderFatal(message) {
		mount.innerHTML = '<main class="ll-main"><div class="ll-callout ll-callout--danger">' + icon('alert-triangle') +
			'<div class="ll-callout__body"><span class="ll-callout__title">' + esc(__('Llamly couldn\'t be loaded', 'llamly')) +
			'</span><span>' + esc(message) +
			'</span></div><div class="ll-callout__actions"><button type="button" class="ll-btn ll-btn--sm" data-reload>' +
			esc(__('Try again', 'llamly')) + '</button></div></div></main>';
		$('[data-reload]', mount).addEventListener('click', boot);
	}

	/* =====================================================================
	   Connections (tab id: connections)
	   ===================================================================== */
	const CLIENTS = {
		'claude-ai': {label: 'claude.ai', group: 'remote', auth: 'oauth', icon: 'claude'},
		'claude-desktop': {label: 'Claude Desktop', group: 'remote', auth: 'oauth', icon: 'claude'},
		chatgpt: {label: 'ChatGPT', group: 'remote', auth: 'oauth', icon: 'openai'},
		'claude-code': {label: 'Claude Code', group: 'terminal', auth: 'key', icon: 'claude'},
		codex: {label: 'Codex', group: 'terminal', auth: 'key', icon: 'openai'},
		opencode: {label: 'OpenCode', group: 'terminal', auth: 'key', icon: 'opencode'},
		cursor: {label: 'Cursor', group: 'terminal', auth: 'key', icon: 'cursor'},
		antigravity: {label: 'Antigravity CLI', group: 'terminal', auth: 'key', icon: 'terminal'},
	};
	const CHATGPT_URL = 'https://chatgpt.com/plugins';
	const METHOD = {oauth: __('Browser sign-in', 'llamly'), key: __('Access key', 'llamly'), oauth_client: __('Browser sign-in', 'llamly')};
	const ACCESS_LABEL = {full: __('Full access', 'llamly'), read_only: __('Read only', 'llamly'), custom: __('Chosen tools', 'llamly')};
	const ACCESS_CHOICE = {full: __('Full access', 'llamly'), read_only: __('Read only', 'llamly'), custom: __('Choose tools', 'llamly')};
	const PROMPT = __('List the drafts on my site that haven\'t been published yet.', 'llamly');
	const POLL_MS = 3000;
	const POLL_MAX_MS = 30 * 60 * 1000;
	const domainId = () => String(site().host || __('my-site', 'llamly')).replace(/\./g, '-');

	// UI state for this session (memory only). keys[client].secret is dropped on confirm or when switching apps.
	const ui = {keys: {}, opened: {}, connected: {}, expired: {}, altOpen: {}, altDismissed: {}, opencodeScope: 'global', doctorRunning: false, doctor: null, poll: null, lastClient: ''};

	function clientIcon(clientId, cls) {
		const c = CLIENTS[clientId];
		const svg = (window.LLAMLY_FREE_CLIENT_ICONS && c && window.LLAMLY_FREE_CLIENT_ICONS[c.icon]) || '';
		return svg ? '<span class="ll-icon ll-client-icon ' + (cls || '') + '" aria-hidden="true">' + svg + '</span>' :
			icon(c && window.LLAMLY_FREE_ICONS && window.LLAMLY_FREE_ICONS[c.icon] ? c.icon : 'plug', cls);
	}
	function brandClass(clientId) { return CLIENTS[clientId] ? ' ll-brand-' + CLIENTS[clientId].icon : ''; }

	/** Which app this connection is: keys by the name given in the recipe, OAuth by the verified return domain. */
	function inferClient(item) {
		if (!item) return '';
		if (item.kind === 'key') {
			const label = String(item.label || '').toLowerCase();
			const ids = Object.keys(CLIENTS).sort((a, b) => CLIENTS[b].label.length - CLIENTS[a].label.length);
			return ids.find((id) => label.startsWith(CLIENTS[id].label.toLowerCase())) || '';
		}
		const host = String(item.redirect_host || item.label || '');
		if (/^claude$/i.test(host) || /claude/i.test(String(item.label || ''))) return 'claude-ai';
		if (/chatgpt/i.test(host)) return 'chatgpt';
		if (/cursor/i.test(host)) return 'cursor';
		const name = String(item.label || '').toLowerCase();
		return Object.keys(CLIENTS).sort((x,
			y) => CLIENTS[y].label.length - CLIENTS[x].label.length).find((id) => name.startsWith(CLIENTS[id].label.toLowerCase())) ||
			'';
	}
	function connectedClients() {
		const set = new Set();
		activeConns().forEach((x) => {
			if (x.kind === 'oauth_client') return;
			const c = inferClient(x);
			if (c) set.add(c);
			if (c === 'claude-ai' && x.kind === 'oauth') set.add('claude-desktop'); // the same connector works in the Claude Desktop app
		});
		Object.keys(ui.connected).forEach((c) => set.add(c));
		return set;
	}

	/* ---------- tools by category (real state) ---------- */
	const CATEGORY = {content: __('Content', 'llamly'), posts: __('Content', 'llamly'), general: __('General', 'llamly'),
		revisions: __('Revisions', 'llamly'), terms: __('Taxonomies', 'llamly'), media: __('Media', 'llamly'),
		taxonomy: __('Taxonomies', 'llamly'), taxonomies: __('Taxonomies', 'llamly'), menus: __('Menus', 'llamly'),
		comments: __('Comments', 'llamly'), users: __('Users', 'llamly'), site: __('Site', 'llamly'),
		blocks: __('Gutenberg · blocks', 'llamly'), gutenberg: __('Gutenberg · blocks', 'llamly'), history: __('History',
		'llamly'), elementor: 'Elementor'};
	const catLabel = (key) => CATEGORY[key] || (String(key || 'general').charAt(0).toUpperCase() + String(key || 'general').slice(1).replace(/_/g, ' '));
	function toolGroups() {
		const groups = [];
		tools().forEach((t) => {
			const key = String(t.category || 'general') + ':' + (t.access === 'write' ? 'write' : 'read');
			let g = groups.find((x) => x.key === key);
			/* translators: tool group in the access picker. 1: category name, 2: "write" or "read". */
			if (!g) { g = {key: key, label: sprintf(__('%1$s · %2$s', 'llamly'), catLabel(t.category), t.access === 'write' ?
				_x('write', 'tool access', 'llamly') : _x('read', 'tool access', 'llamly')), tools: []}; groups.push(g); }
			g.tools.push(String(t.name));
		});
		return groups;
	}
	function catGrid(selected, hidden) {
		const sel = new Set(selected || []);
		return '<div class="ll-catgrid"' + (hidden ? ' hidden' : '') + ' data-catgrid>' + toolGroups().map((g) =>
			'<label class="ll-catcheck"><input type="checkbox" name="cat" value="' + esc(g.key) + '"' +
				(g.tools.every((n) => sel.has(n)) ? ' checked' : '') + '><span>' + esc(g.label) + '</span><span class="ll-faint">' +
				g.tools.length + '</span></label>').join('') + '</div>';
	}
	function selectedTools(root) {
		const keys = new Set($$('input[name=cat]:checked', root).map((i) => i.value));
		return toolGroups().filter((g) => keys.has(g.key)).reduce((all, g) => all.concat(g.tools), []);
	}
	function accessChoices(name, current, row) {
		const write = writeEnabled();
		const desc = {
			full: write ? __('Read and edit the site', 'llamly') : __('Unavailable: writing is paused on this site', 'llamly'),
			read_only: __('Look up without changing', 'llamly'),
			custom: __('By category', 'llamly'),
		};
		return ['full', 'read_only', 'custom'].map((a) => {
			const disabled = a === 'full' && !write;
			return '<label class="ll-choice__item"' + (disabled ? ' aria-disabled="true"' : '') + '><input type="radio" name="' +
				name + '" value="' + a + '"' + (current === a ? ' checked' : '') + (disabled ? ' disabled' : '') +
				'><span><span class="ll-choice__title">' + esc(ACCESS_CHOICE[a]) + '</span><span class="ll-choice__desc">' + esc(row &&
				row[a] ? row[a] : desc[a]) + '</span></span></label>';
		}).join('');
	}

	/* ---------- picker ---------- */
	function pickerItem(id, current, connected) {
		const c = CLIENTS[id];
		return '<button type="button" class="ll-pick' + (id === current ? ' is-active' : '') + '" data-client="' + id + '" aria-pressed="' + (id === current) + '">' +
			'<span class="ll-pick__tile' + brandClass(id) + '">' + clientIcon(id) + '</span>' +
			'<span class="ll-pick__text"><span class="ll-pick__name">' + esc(c.label) + '</span><span class="ll-pick__sub">' + esc(METHOD[c.auth]) + '</span></span>' +
			(connected.has(id) ? '<span class="ll-dot ll-dot--success" title="' + esc(__('Connected', 'llamly')) + '"></span>' : '') + '</button>';
	}
	function picker(current) {
		const connected = connectedClients();
		const group = (g, title, desc) => '<div class="ll-pick-group"><p class="ll-pick-group__title">' + esc(title) +
			'</p><p class="ll-pick-group__desc">' + esc(desc) + '</p>' +
			Object.keys(CLIENTS).filter((id) => CLIENTS[id].group === g).map((id) => pickerItem(id, current, connected)).join('') + '</div>';
		return '<aside class="ll-picker" aria-label="' + esc(__('Choose the app', 'llamly')) + '">' + group('remote',
			__('No terminal', 'llamly'), __('Connects through the browser', 'llamly')) + group('terminal', __('Terminal and editor',
			'llamly'), __('Connects with a key', 'llamly')) +
			'<div class="ll-picker__other"><p>' + esc(__('Another MCP-compatible app? Use this address:', 'llamly')) + '</p>' + copyField(endpoint()) + '</div></aside>';
	}
	function welcome() {
		return '<div class="ll-welcome"><img class="ll-welcome__art" src="' + esc(asset('assets/brand/llamly-mark.svg')) + '" alt="" width="80" height="80">' +
			'<h3>' + esc(__('Which app do you want to connect?', 'llamly')) + '</h3><p>' +
				esc(__('Choose from the list. You get the right step-by-step for it, and it takes about a minute.', 'llamly')) +
				'</p>' +
			'<div class="ll-welcome__hints"><div>' + icon('globe') + '<span>' +
				__('<strong>No terminal</strong>: claude.ai, Claude Desktop and ChatGPT. You approve access on your own site.',
				'llamly') + '</span></div>' +
			'<div>' + icon('terminal') + '<span>' +
				__('<strong>Terminal and editor</strong>: Claude Code, Codex, Cursor and others. You create a key and paste a command.',
				'llamly') + '</span></div></div></div>';
	}

	/* ---------- new connections gate (real OAuth window) ---------- */
	function gate(appName) {
		const w = windowState();
		if (w.open) {
			const min = Math.max(1, Math.ceil(Number(w.seconds_left || 0) / 60));
			/* translators: %d: minutes left. */
			return '<div class="ll-gate is-open"><span class="ll-dot ll-dot--success"></span><div class="ll-gate__text"><strong>' +
				esc(sprintf(_n('New connections: open for %d minute', 'New connections: open for %d minutes', min, 'llamly'), min)) +
				'</strong><span>' + esc(__('They close on their own afterwards. Existing connections aren\'t affected.', 'llamly')) +
				'</span></div>' +
				'<button type="button" class="ll-btn ll-btn--sm ll-btn--ghost" data-act="gate-close">' + esc(__('Close now', 'llamly')) + '</button></div>';
		}
		/* translators: %s: app name (Claude or ChatGPT). */
		return '<div class="ll-gate"><span class="ll-dot ll-dot--off"></span><div class="ll-gate__text"><strong>' +
			esc(__('New connections: closed', 'llamly')) + '</strong><span>' +
			esc(sprintf(__('The “Add to %s” button opens them for 30 minutes. Adding it by hand? Open them first.', 'llamly'),
			appName)) + '</span></div>' +
			'<button type="button" class="ll-btn ll-btn--sm" data-act="gate-open">' + esc(__('Open for 30 min', 'llamly')) + '</button></div>';
	}

	const step = (n, title, body, state) => '<li class="ll-step ' + (state || '') + '"><span class="ll-step__n">' +
		(state === 'is-done' ? icon('check', 'll-icon--sm') : n) +
		'</span><div class="ll-step__body"><h4 class="ll-step__title">' + title + '</h4>' + body + '</div></li>';
	const promptBtn = () => '<button type="button" class="ll-prompt" data-copy-ref="' + copyRef(PROMPT) + '"><span>“' +
		esc(PROMPT) + '”</span>' + icon('copy', 'll-icon--sm') + '</button>';

	function connectedAtLabel(id) {
		const d = parseUtc(ui.connected[id]);
		return d ? hm(d) : '';
	}
	function liveStatus(id, waitingText, okText) {
		/* translators: %s: time of the first request (HH:MM). */
		if (ui.connected[id]) return '<div class="ll-live is-ok">' + icon('check-circle-2') + '<span><strong>' +
			esc(__('Connected.', 'llamly')) + '</strong> ' + esc(sprintf(__('First request received at %s.', 'llamly'),
			connectedAtLabel(id))) + ' ' + okText + '</span></div>';
		/* translators: %s: "check again" button. */
		if (ui.expired[id]) return '<div class="ll-live">' + icon('clock') + '<span>' +
			sprintf(esc(__('No request arrived in 30 minutes. Check the steps above and %s.', 'llamly')),
			'<button type="button" class="ll-linkbtn" data-act="poll-again" data-client="' + id + '">' + esc(__('check again',
			'llamly')) + '</button>') + '</span></div>';
		return '<div class="ll-live"><span class="ll-dot ll-dot--warning ll-dot--pulse"></span><span>' + waitingText + '</span></div>';
	}

	function useStep(n, id) {
		const c = CLIENTS[id];
		const done = !!ui.connected[id];
		const where = {
			'claude-ai': __('In a chat, click <strong>+</strong>, open <strong>Connectors</strong> and check that your site is turned on.', 'llamly'),
			'claude-desktop': __('In a chat, click <strong>+</strong>, open <strong>Connectors</strong> and check that your site is turned on.', 'llamly'),
			chatgpt: __('In a chat, open the <strong>+</strong> menu, choose <strong>Developer mode</strong> and select your site.', 'llamly'),
		}[id] || __('Open a new conversation with the agent.', 'llamly');
		/* translators: 1: instruction where to find the site in the app, 2: link to the Logs tab. */
		return step(n, esc(__('Ask for something in the conversation', 'llamly')), '<p>' +
			sprintf(__('%s Then ask, for example:', 'llamly'), where) + '</p>' + promptBtn() +
			/* translators: %s: app name. */
			liveStatus(id, esc(sprintf(__('Waiting for the first request from %s…', 'llamly'), c.label)),
				sprintf(esc(__('It already shows up in %s.', 'llamly')), '<a href="' + esc(tabHref('logs')) + '" data-goto="logs">' +
				esc(__('Logs', 'llamly')) + '</a>')), done ? 'is-done' : '');
	}

	/* ---------- LLP-467: how far the app got, and another way when it did not get there ---------- */
	function attemptFor(id) {
		const a = attemptState();
		return a && a.client === id && windowState().open ? a : null;
	}
	function funnelBox(id, appName) {
		const a = attemptFor(id);
		if (!a) return '';
		const order = ['waiting', 'registered', 'approved', 'connected'];
		const at = order.indexOf(a.stage);
		const rows = [[
			/* translators: %s: app name (Claude or ChatGPT). Registration is anonymous: only the client's address is known. */
			sprintf(__('A client with the %s address registered on your site', 'llamly'), appName),
			/* translators: %s: app name (Claude or ChatGPT). */
			sprintf(__('Waiting for %s to reach your site', 'llamly'), appName)],
			[__('You approved access', 'llamly'), __('You approve access on your site', 'llamly')],
			[__('Connected', 'llamly'), __('Connected', 'llamly')]];
		const items = rows.map((r, i) => {
			const done = at > i, current = at === i;
			return '<li class="ll-funnel__step' + (done ? ' is-done' : current ? ' is-current' : '') + '">' +
				(done ? icon('check', 'll-icon--sm') : current ? '<span class="ll-dot ll-dot--warning ll-dot--pulse"></span>' :
					'<span class="ll-dot ll-dot--off"></span>') + '<span>' + esc(done ? r[0] : r[1]) + '</span></li>';
		}).join('');
		const quiet = a.quiet ? '<div class="ll-callout ll-callout--warning ll-funnel__quiet" role="status">' + icon('alert-triangle') +
			'<div class="ll-callout__body"><span class="ll-callout__title">' +
			/* translators: %s: app name (Claude or ChatGPT). */
			esc(sprintf(__('%s hasn\'t reached your site yet', 'llamly'), appName)) + '</span><span>' +
			esc(__('More than 3 minutes have passed since you clicked. The usual causes:', 'llamly')) + '</span><ul class="ll-funnel__causes">' +
			(appName === 'Claude' ? '<li>' + esc(__('Your Claude account is in a Team or Enterprise organization, and the owner hasn\'t added the connector yet. See below.',
				'llamly')) + '</li>' : '') +
			/* translators: %s: the /.well-known/ path. */
			'<li>' + sprintf(esc(__('The web server doesn\'t pass the site\'s %s addresses to WordPress. Run the connection diagnostics below.',
				'llamly')), '<code>/.well-known/</code>') + '</li></ul></div></div>' : '';
		return '<div class="ll-funnel" data-stage="' + esc(a.stage) + '"><span class="ll-funnel__title">' +
			/* translators: %s: app name (Claude or ChatGPT). */
			esc(sprintf(__('Connecting to %s', 'llamly'), appName)) + '</span><ol class="ll-funnel__steps">' + items + '</ol></div>' + quiet;
	}
	function altBlock(id) {
		const a = attemptFor(id);
		const auto = !!(a && a.quiet);
		const open = (auto && !ui.altDismissed[id]) || !!ui.altOpen[id];
		const org = site().claude_org_install_url;
		const orgLink = org ? '<a href="' + esc(org) +
			'" target="_blank" rel="noopener noreferrer" data-act="open-org"><strong>Organization settings › Connectors</strong></a>' :
			'<strong>Organization settings › Connectors</strong>';
		const name = site().host || '';
		return '<details class="ll-alt' + (auto ? ' is-highlight' : '') + '" data-alt="' + id + '"' + (open ? ' open' : '') + '><summary>' +
			icon('chevron-right') + esc(__('Didn\'t work? Try another way', 'llamly')) + '</summary><div class="ll-alt__body">' +
			'<div class="ll-alt__part"><h5 class="ll-alt__title">' + esc(__('Claude Team or Enterprise account', 'llamly')) + '</h5>' +
			'<ol class="ll-substeps"><li>' +
			/* translators: %s: "Organization settings › Connectors" (Claude menu path, kept in English). */
			sprintf(esc(__('The organization owner adds the connector in %s, with this name and address:', 'llamly')), orgLink) +
			'<div class="ll-alt__fields">' + copyField(name, __('Copy name', 'llamly')) + copyField(endpoint(), __('Copy address', 'llamly')) +
			'</div></li><li>' +
			__('Then you find it in <strong>Customize › Connectors</strong> and click <strong>Connect</strong>.', 'llamly') +
			'</li></ol></div>' +
			'<div class="ll-alt__part"><h5 class="ll-alt__title">' + esc(__('Connect from the terminal instead', 'llamly')) + '</h5>' +
			'<p>' + esc(__('Claude Code and Codex connect with an access key. They don\'t need the organization owner.', 'llamly')) + '</p>' +
			'<div class="ll-row"><button type="button" class="ll-btn ll-btn--sm" data-act="goto-client" data-client="claude-code">' +
			esc(__('Connect Claude Code', 'llamly')) + '</button><button type="button" class="ll-btn ll-btn--sm" data-act="goto-client" data-client="codex">' +
			esc(__('Connect Codex', 'llamly')) + '</button></div></div></div></details>';
	}

	function oauthRecipe(id) {
		const isGpt = id === 'chatgpt';
		const isDesktop = id === 'claude-desktop';
		const ep = endpoint();
		const opened = !!ui.opened[id] || !!ui.connected[id];
		const appName = isGpt ? 'ChatGPT' : 'Claude';
		const manual = isGpt
			? '<ol class="ll-substeps"><li>' +
				__('Go to <code>chatgpt.com</code> in the browser (MCP apps only work on the web).', 'llamly') + '</li><li>' +
				__('In <strong>Settings › Security and login</strong>, turn on <strong>Developer mode</strong> (only once).',
				'llamly') + '</li><li>' +
				__('In <strong>Plugins</strong>, click <strong>+</strong> and choose <strong>Add custom MCP server</strong>.',
				'llamly') + '</li><li>' +
				__('Give it a name, paste the address above into <strong>MCP server URL</strong>, choose <strong>OAuth</strong> authentication and create it.',
				'llamly') + '</li></ol>'
			: '<ol class="ll-substeps"><li>' + (isDesktop ?
				__('Open the <strong>Claude</strong> app and sign in to your account.', 'llamly') :
				__('Go to <code>claude.ai</code> in the browser.', 'llamly')) + '</li><li>' +
				__('Go to <strong>Customize › Connectors</strong>.', 'llamly') + '</li><li>' +
				__('Click <strong>+ Add</strong> and then <strong>Add custom connector</strong>.', 'llamly') + '</li><li>' +
				__('Give it a name, paste the address above and click <strong>Continue</strong> and <strong>Add</strong>. If it asks which OAuth client to use, choose <strong>Register automatically</strong>.',
				'llamly') + '</li></ol>';
		const step1 = isGpt
			? step(1, esc(_x('Open ChatGPT', 'step title', 'llamly')),
				'<div class="ll-row"><button type="button" class="ll-btn ll-btn--primary" data-act="open-app" data-client="' + id +
				'">' + esc(_x('Open ChatGPT', 'button', 'llamly')) + ' ' + icon('external-link') + '</button></div>' +
				funnelBox(id, appName) +
				'<p>' + esc(__('Copies your site address, opens new connections for 30 minutes and opens ChatGPT in another tab.', 'llamly')) + '</p>' +
				'<details class="ll-disclosure"><summary>' + icon('chevron-right') +
					esc(__('I\'d rather copy the address and do it by hand', 'llamly')) + '</summary><div class="ll-disclosure__body">' +
					copyField(ep, __('Copy address', 'llamly')) + manual + '</div></details>', opened ? 'is-done' : 'is-current')
			/* translators: %s: app name (Claude). */
			: step(1, esc(sprintf(__('Add to %s', 'llamly'), appName)),
				'<div class="ll-row"><button type="button" class="ll-btn ll-btn--primary" data-act="open-app" data-client="' + id +
				'">' + esc(__('Add to Claude', 'llamly')) + ' ' + icon('external-link') + '</button></div>' +
				funnelBox(id, appName) + altBlock(id) +
				/* translators: %s: MCP endpoint address. */
				'<p>' +
					sprintf(__('Opens claude.ai in another tab with this site already filled in and opens new connections for 30 minutes. Check that the address is %s and click <strong>Continue</strong> and <strong>Add</strong>.',
					'llamly'), '<code>' + esc(ep) + '</code>') + (isDesktop ? ' ' +
					esc(__('The connector also shows up in the Claude Desktop app, on the same account.', 'llamly')) : '') + '</p>' +
				'<p class="ll-faint">' +
					__('Already added it? Claude says the connector exists. Find it in <strong>Customize › Connectors</strong> and click <strong>Connect</strong>.',
					'llamly') + '</p>' +
				'<details class="ll-disclosure"><summary>' + icon('chevron-right') + esc(__('Add manually', 'llamly')) +
					'</summary><div class="ll-disclosure__body">' + copyField(ep, __('Copy address', 'llamly')) + manual +
					'</div></details>', opened ? 'is-done' : 'is-current');
		const org = site().claude_org_install_url;
		const orgLink = org ? '<a href="' + esc(org) +
			'" target="_blank" rel="noopener noreferrer" data-act="open-org"><strong>Organization settings › Connectors</strong></a>' :
			'<strong>Organization settings › Connectors</strong>';
		/* translators: %s: app name (Claude or ChatGPT). */
		const step2 = step(2, esc(__('Approve access on your site', 'llamly')), '<p>' +
			sprintf(__('%s opens a sign-in page <strong>on your site</strong>. Check what it asks for, choose the access level and click <strong>Approve access</strong>.',
			'llamly'), esc(appName)) + '</p>' +
			'<p class="ll-faint">' + (isGpt
				? esc(__('Requires ChatGPT Plus, Pro, Business, Enterprise or Edu, on the web. On Business and Enterprise, an admin has to allow Developer mode first.',
					'llamly'))
				/* translators: %s: "Organization settings › Connectors" (Claude menu path, kept in English). */
				: sprintf(esc(__('Custom connectors work on every Claude plan (on Free, one at a time). On Team and Enterprise, the organization owner adds it first in %s.',
					'llamly')), orgLink)) + '</p>', ui.connected[id] ? 'is-done' : (opened ? 'is-current' : ''));
		return gate(appName) + '<ol class="ll-steps">' + step1 + step2 + useStep(3, id) + '</ol>';
	}

	/* key recipes */
	const FILES = {opencode: {global: '~/.config/opencode/opencode.json',
		project: __('opencode.json (in the project root)', 'llamly')}, cursor: '~/.cursor/mcp.json',
		'claude-desktop': 'claude_desktop_config.json'};
	function snippet(id, key) {
		const d = domainId(), ep = endpoint();
		const placeholder = __('YOUR_KEY', 'llamly');
		const k = key ? '<span class="tok-secret">' + esc(key) + '</span>' : '<span class="tok-s">' + esc(placeholder) + '</span>';
		const raw = key || placeholder;
		const S2 = {
			'claude-code': ['<span class="tok-k">claude mcp add</span> --transport http ' + esc(d) + ' ' + esc(ep) +
				' \\\n  --header "Authorization: Bearer ' + k + '"', 'claude mcp add --transport http ' + d + ' ' + ep +
				' --header "Authorization: Bearer ' + raw + '"'],
			codex: ['<span class="tok-k">export</span> LLAMLY_API_KEY="' + k + '"\n<span class="tok-k">codex mcp add</span> ' +
				esc(d) + ' --url ' + esc(ep) + ' \\\n  --bearer-token-env-var LLAMLY_API_KEY', 'export LLAMLY_API_KEY="' + raw +
				'"\ncodex mcp add ' + d + ' --url ' + ep + ' --bearer-token-env-var LLAMLY_API_KEY'],
			antigravity: ['<span class="tok-k">agy mcp add</span> --header "Authorization: Bearer ' + k + '" \\\n  ' + esc(d) +
				' ' + esc(ep), 'agy mcp add --header "Authorization: Bearer ' + raw + '" ' + d + ' ' + ep],
			opencode: ['{\n  "mcp": {\n    "' + esc(d) + '": {\n      "type": "remote",\n      "url": "' + esc(ep) +
				'",\n      "oauth": false,\n      "headers": { "Authorization": "Bearer ' + k + '" }\n    }\n  }\n}', null],
			cursor: ['{\n  "mcpServers": {\n    "' + esc(d) + '": {\n      "url": "' + esc(ep) +
				'",\n      "headers": { "Authorization": "Bearer ' + k + '" }\n    }\n  }\n}', null],
			'claude-desktop': ['{\n  "mcpServers": {\n    "' + esc(d) +
				'": {\n      "command": "npx",\n      "args": ["-y", "mcp-remote", "' + esc(ep) +
				'",\n        "--header", "Authorization:${AUTH}"],\n      "env": { "AUTH": "Bearer ' + k + '" }\n    }\n  }\n}', null],
		}[id];
		const plain = S2[1] || S2[0].replace(/<[^>]+>/g, '').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g,
			'<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
		return {html: S2[0], plain: plain};
	}

	function keyStep(id) {
		const c = CLIENTS[id];
		const k = ui.keys[id];
		if (k) {
			return step(1, esc(__('Key created', 'llamly')), '<p><strong>' + esc(k.label) + '</strong> · ' +
				esc(ACCESS_LABEL[k.access] || '') + '.' + (k.secret ? ' ' + esc(__('It\'s already in the command below.', 'llamly')) :
				'') + '</p>' +
				(k.secret
					? '<div class="ll-callout ll-callout--warning">' + icon('eye') +
						'<div class="ll-callout__body"><span class="ll-callout__title">' + esc(__('Copy it now', 'llamly')) + '</span><span>' +
						esc(__('For security, the key isn\'t shown again. If you lose it, just create another one and revoke this one.',
						'llamly')) +
						'</span></div><div class="ll-callout__actions"><button type="button" class="ll-btn ll-btn--sm ll-btn--ghost" data-act="hide-key" data-client="' +
						id + '">' + esc(__('I copied it, hide', 'llamly')) + '</button></div></div>'
					: '<p class="ll-faint">' +
						esc(__('The key was hidden from this page. Lost it? Create another one and revoke this one in Connected apps.',
						'llamly')) + '</p>'), 'is-done');
		}
		const write = writeEnabled();
		/* translators: %s: app name. Default name of a new access key. */
		const defaultName = sprintf(__('%s · my computer', 'llamly'), c.label);
		/* translators: %s: name of the WordPress user. */
		const actingAs = sprintf(esc(__('Acting as %s, with your content permissions.', 'llamly')), '<strong>' + esc(site().user || '') + '</strong>');
		return step(1, esc(__('Create an access key', 'llamly')), '<p>' +
			esc(__('Give it a name to recognize it later and choose what the agent can do.', 'llamly')) + '</p>' +
			'<form class="ll-keyform" data-form="key" data-client="' + id + '">' +
			'<div class="ll-field"><label class="ll-label" for="key-name-' + id + '">' + esc(__('Name', 'llamly')) +
				'</label><input class="ll-input" id="key-name-' + id + '" name="name" maxlength="120" value="' + esc(defaultName) +
				'" autocomplete="off"></div>' +
			'<fieldset class="ll-field ll-fieldset"><legend class="ll-label">' + esc(__('Access', 'llamly')) +
				'</legend><div class="ll-choice ll-choice--row">' + accessChoices('access', write ? 'full' : 'read_only') + '</div>' +
				catGrid([], true) + '</fieldset>' +
			'<div class="ll-callout">' + icon('shield-check') + '<div class="ll-callout__body"><span class="ll-callout__title">' +
				esc(__('What this key allows', 'llamly')) + '</span><span>' +
				esc(__(
					'The agent works on this site with your content permissions, never administrator ones: no site settings, users, plugins, themes, code files or unfiltered HTML, whatever your role. It connects straight to this site, with no Llamly server in between. Revoke the key at any time in Connections.',
					'llamly')) + '</span></div></div>' +
			'<div class="ll-row"><button type="submit" class="ll-btn ll-btn--primary">' + icon('key-round') +
				esc(__('Create key', 'llamly')) + '</button><span class="ll-faint">' + actingAs + '</span></div></form>', 'is-current');
	}

	function installStep(n, id) {
		const k = ui.keys[id];
		const sn = snippet(id, k && k.secret);
		let intro, label;
		/* translators: %s: configuration file path. */
		const addToFile = (file, restart) => '<p>' + sprintf(esc(restart), '<code>' + esc(file) + '</code>') + '</p>';
		if (id === 'opencode') {
			const scope = ui.opencodeScope;
			intro = '<div class="ll-row"><div class="ll-segmented" role="group" aria-label="' + esc(__('Where to configure',
				'llamly')) + '"><button type="button" data-act="oc-scope" data-scope="global" aria-pressed="' + (scope === 'global') +
				'">' + esc(__('For all projects', 'llamly')) +
				'</button><button type="button" data-act="oc-scope" data-scope="project" aria-pressed="' + (scope === 'project') +
				'">' + esc(__('Only in this project', 'llamly')) + '</button></div></div>' +
				/* translators: %s: configuration file path. */
				addToFile(FILES.opencode[scope], __('Add it to the %s file and restart OpenCode.', 'llamly'));
			label = FILES.opencode[scope];
		} else if (id === 'cursor') {
			/* translators: %s: configuration file path. */
			intro = addToFile('~/.cursor/mcp.json', __('Add it to the %s file (create it if it doesn\'t exist) and restart Cursor.', 'llamly'));
			label = FILES.cursor;
		} else if (id === 'codex') {
			intro = '<p>' +
				__('Run it in the terminal. Codex reads the key every time it starts, so also add the <code>export</code> line to your <code>~/.zshrc</code>.',
				'llamly') + '</p>';
			label = __('Terminal', 'llamly');
		} else {
			intro = '<p>' + esc(__('Run it in the terminal:', 'llamly')) + '</p>';
			label = __('Terminal', 'llamly');
		}
		// Notes learned from connecting each client for real.
		const notes = {
			codex: __('In interactive use, Codex asks before each tool call. To run without prompts (<code>codex exec</code>), approve the tools with <code>default_tools_approval_mode = "approve"</code> in this server\'s section of <code>~/.codex/config.toml</code>.',
				'llamly'),
		};
		const note = notes[id] ? '<p class="ll-faint">' + notes[id] + '</p>' : '';
		const extra = id === 'cursor' ? '<details class="ll-disclosure"><summary>' + icon('chevron-right') +
			esc(__('Prefer signing in through the browser, without a key?', 'llamly')) +
			'</summary><div class="ll-disclosure__body"><p class="ll-muted">' +
			esc(__('Cursor also accepts OAuth sign-in. Use only the address and approve access on your site when it asks. Open new connections for 30 minutes first.',
			'llamly')) + '</p>' +
			codeBlock(FILES.cursor, '{\n  "mcpServers": {\n    "' + esc(domainId()) + '": { "url": "' + esc(endpoint()) +
				'" }\n  }\n}', '{\n  "mcpServers": {\n    "' + domainId() + '": { "url": "' + endpoint() + '" }\n  }\n}') +
			'<div class="ll-row"><button type="button" class="ll-btn ll-btn--sm" data-act="gate-open">' +
				esc(__('Open for 30 min', 'llamly')) + '</button></div></div></details>' : '';
		return step(n, esc(id === 'opencode' || id === 'cursor' ? __('Add it to the configuration file', 'llamly') : __('Run this command', 'llamly')), intro +
			'<div class="' + (k && k.secret ? '' : 'll-is-waiting') + '">' + codeBlock(label, sn.html, sn.plain) + '</div>' +
			(k ? '' : '<p class="ll-faint">' + esc(__('Create the key in step 1 and it goes into the command automatically.',
				'llamly')) + '</p>') + note + extra, ui.connected[id] ? 'is-done' : (k ? 'is-current' : ''));
	}

	function verifyStep(n, id) {
		const d = '<code>' + esc(domainId()) + '</code>';
		/* translators: %s: server name as shown by the app. */
		const how = {
			'claude-code': sprintf(__('Open a new Claude Code session and run <code>/mcp</code>. %s should show up as connected.', 'llamly'), d),
			/* translators: %s: server name as shown by the app. */
			codex: sprintf(__('Start Codex and run <code>/mcp</code>. %s should show up with its tools.', 'llamly'), d),
			/* translators: %s: server name as shown by the app. */
			opencode: sprintf(__('Run <code>opencode mcp list</code>. %s should show up as connected.', 'llamly'), d),
			/* translators: %s: server name as shown by the app. */
			cursor: sprintf(__('Open <strong>Customize</strong> in the Cursor sidebar. %s should show up turned on. Errors are in <strong>Output › MCP Logs</strong>.',
				'llamly'), d),
			/* translators: %s: server name as shown by the app. */
			antigravity: sprintf(__('Start Antigravity CLI and ask it to do something on your site. %s should show up with its tools.', 'llamly'), d),
			/* translators: %s: server name as shown by the app. */
			'claude-desktop': sprintf(__('Open a new chat in Claude Desktop and click the tools icon. %s should show up. The first time can take a while as the bridge is downloaded.',
				'llamly'), d),
		}[id];
		const done = !!ui.connected[id];
		const status = !ui.keys[id] ? '' : liveStatus(id, esc(__('Waiting for the first request…', 'llamly')),
			esc(__('Now just ask for something in the conversation.', 'llamly'))) + (done ? promptBtn() : '');
		return step(n, esc(__('Check that it works', 'llamly')), '<p>' + how + '</p>' + status, done ? 'is-done' : '');
	}

	const keyRecipe = (id) => '<ol class="ll-steps">' + keyStep(id) + installStep(2, id) + verifyStep(3, id) + '</ol>';

	function desktopBridgeRecipe() {
		const id = 'claude-desktop';
		const k = ui.keys[id];
		const sn = snippet(id, k && k.secret);
		return '<div class="ll-callout">' + icon('laptop') +
			'<div class="ll-callout__body"><span class="ll-callout__title">' + esc(__('This site runs on your computer',
			'llamly')) + '</span><span>' +
			__('Claude\'s cloud connector can\'t reach local sites. Use a <strong>local bridge</strong>: a small program that runs here and links Claude Desktop to the site. Nothing is exposed to the internet.',
			'llamly') + '</span></div></div>' +
			'<ol class="ll-steps">' + keyStep(id) +
			/* translators: %s: configuration file name. */
			step(2, esc(__('Add the bridge to Claude Desktop', 'llamly')), '<p>' +
				sprintf(esc(__('Add it to the %s file (create it if it doesn\'t exist). Then quit Claude completely and open it again.',
				'llamly')), '<code>claude_desktop_config.json</code>') + '</p>' +
				'<div class="' + (k && k.secret ? '' : 'll-is-waiting') + '">' + codeBlock(FILES['claude-desktop'], sn.html, sn.plain) + '</div>' +
				'<dl class="ll-paths"><dt>macOS</dt><dd><code>~/Library/Application Support/Claude/claude_desktop_config.json</code></dd><dt>Windows</dt><dd><code>%APPDATA%\\Claude\\claude_desktop_config.json</code></dd></dl>' +
				'<p class="ll-faint">' +
					__('Needs Node.js on this computer. The bridge is the open-source <code>mcp-remote</code> package, started on demand by <code>npx</code>. The key is saved as plain text in that file; prefer <strong>read only</strong> if you don\'t need to edit.',
					'llamly') + '</p>', ui.connected[id] ? 'is-done' : (k ? 'is-current' : '')) +
			verifyStep(3, id) + '</ol>' +
			'<details class="ll-disclosure"><summary>' + icon('chevron-right') +
				esc(__('Is the site actually at a public HTTPS address? Use the cloud connector', 'llamly')) +
				'</summary><div class="ll-disclosure__body">' + oauthRecipe(id) + '</div></details>';
	}

	function localCloudNotice(id) {
		const isGpt = id === 'chatgpt';
		/* translators: %s: site host name. */
		const onYourComputer = sprintf(esc(__('%s is on your computer.', 'llamly')), '<strong>' + esc(site().host || '') + '</strong>');
		return '<div class="ll-callout ll-callout--warning">' + icon('alert-triangle') +
			'<div class="ll-callout__body"><span class="ll-callout__title">' + esc(isGpt ? __('ChatGPT can\'t reach this site',
			'llamly') : __('claude.ai can\'t reach this site', 'llamly')) + '</span><span>' + esc(isGpt ?
			__('It connects through the OpenAI cloud and only reaches sites at a public HTTPS address.', 'llamly') :
			__('It connects through the Anthropic cloud and only reaches sites at a public HTTPS address.', 'llamly')) + ' ' +
			onYourComputer + '</span></div></div>' +
			'<div class="ll-local-options"><p class="ll-label">' + esc(__('What works on a local site', 'llamly')) + '</p>' +
			'<button type="button" class="ll-local-opt" data-client="claude-code">' + icon('terminal') + '<span><strong>' +
				esc(__('Terminal and editor apps', 'llamly')) + '</strong><span>' +
				esc(__('Claude Code, Codex, Cursor, Antigravity CLI and OpenCode connect now, without a public address.', 'llamly')) +
				'</span></span>' + icon('chevron-right') + '</button>' +
			'<button type="button" class="ll-local-opt" data-client="claude-desktop">' + icon('laptop') +
				'<span><strong>Claude Desktop</strong><span>' + esc(__('Connects through a local bridge that runs on this computer.',
				'llamly')) + '</span></span>' + icon('chevron-right') + '</button>' +
			'<div class="ll-local-opt is-static">' + icon('cloud') + '<span><strong>' + esc(__('Temporary tunnel', 'llamly')) +
				'</strong><span>' +
				esc(__('ngrok or Cloudflare Tunnel work, but they expose the whole site while running, and the Site Address in WordPress has to be the tunnel\'s.',
				'llamly')) + '</span></span></div></div>';
	}

	/** Which recipe is on screen: decides what polling waits for. */
	function recipeMode(id) {
		const local = !!site().is_local;
		if (local && (id === 'claude-ai' || id === 'chatgpt')) return 'local-notice';
		if (local && id === 'claude-desktop') return 'bridge';
		return CLIENTS[id].auth === 'oauth' ? 'oauth' : 'key';
	}

	function recipe(id) {
		const c = CLIENTS[id];
		const mode = recipeMode(id);
		const body = mode === 'local-notice' ? localCloudNotice(id) : mode === 'bridge' ? desktopBridgeRecipe() : mode === 'oauth' ? oauthRecipe(id) : keyRecipe(id);
		const auth = mode === 'bridge' ? 'key' : c.auth;
		/* translators: %s: app name. */
		return '<div class="ll-recipe"><div class="ll-recipe__head"><span class="ll-recipe__tile' + brandClass(id) + '">' +
			clientIcon(id, 'll-icon--lg') + '</span>' +
			'<div><h3 class="ll-recipe__title">' + esc(sprintf(__('Connect %s', 'llamly'), c.label)) +
				'</h3><p class="ll-faint">' + esc(METHOD[auth]) + ' · ' + esc(auth === 'oauth' ? __('About 1 minute', 'llamly') :
				__('About 2 minutes', 'llamly')) + '</p></div></div>' + body + '</div>';
	}

	function connectCard(current) {
		const has = activeConns().length > 0;
		return '<section class="ll-card ll-connect" aria-labelledby="connect-title"><div class="ll-card__head"><div><h2 class="ll-card__title" id="connect-title">' +
			esc(has ? __('Connect another app', 'llamly') : __('Connect your first app', 'llamly')) + '</h2>' +
			'<p class="ll-card__desc">' + esc(__('Choose the AI app or coding tool you use.', 'llamly')) + '</p></div>' +
			(has ? '<button type="button" class="ll-btn ll-btn--ghost ll-btn--sm" data-act="close-picker">' + icon('x') +
				esc(__('Close', 'llamly')) + '</button>' : '') + '</div>' +
			'<div class="ll-connect__grid">' + picker(current) + '<div class="ll-connect__panel">' + (current ? recipe(current) : welcome()) + '</div></div></section>';
	}

	/* ---------- connected apps ---------- */
	function accessOf(x) {
		if (x.kind === 'key') {
			/* translators: %d: number of tools. */
			if (x.scope_mode === 'custom') { const n = (x.allowed_tools || []).length; return [sprintf(_n('%d tool', '%d tools', n, 'llamly'), n), '']; }
			return x.scope_mode === 'read_only' ? [ACCESS_LABEL.read_only, 'info'] : [ACCESS_LABEL.full, ''];
		}
		if (x.kind === 'oauth') return (x.scopes || []).indexOf('llamly.write') >= 0 ? [ACCESS_LABEL.full, ''] : [ACCESS_LABEL.read_only, 'info'];
		return [__('Waiting for approval', 'llamly'), 'warning'];
	}
	function connectionsCard(showConnectButton) {
		const rows = activeConns().map((x) => {
			const client = inferClient(x);
			const clientLabel = client ? CLIENTS[client].label : (x.kind === 'key' ? __('App', 'llamly') : (ptLabel(x.redirect_host) || __('App', 'llamly')));
			const acc = accessOf(x);
			const used = x.kind === 'oauth_client'
				? '<span class="ll-row ll-nowrap" style="gap:8px"><span class="ll-dot ll-dot--warning"></span>' + esc(__('Not approved', 'llamly')) + '</span>'
				: x.last_used_at
					? '<span class="ll-row ll-nowrap" style="gap:8px"><span class="ll-dot ll-dot--success"></span>' + esc(relative(x.last_used_at)) + '</span>'
					: '<span class="ll-row ll-nowrap" style="gap:8px"><span class="ll-dot ll-dot--warning ll-dot--pulse"></span>' +
						esc(__('Waiting for first use', 'llamly')) + '</span>';
			const sub = x.kind === 'key'
				? esc(clientLabel) + ' · <span class="ll-mono">' + esc(String(x.prefix || '').slice(0, 8)) + '…</span>'
				/* translators: %s: name of the WordPress user the connection acts as. */
				: esc(clientLabel) + (x.user_name ? ' · ' + esc(sprintf(__('as %s', 'llamly'), x.user_name)) : '');
			const menu = (x.kind === 'key' ? '<button type="button" data-act="edit-access" data-id="' + esc(x.id) + '">' +
				icon('shield-check') + esc(__('Edit access', 'llamly')) + '</button>' : '') +
				(x.kind !== 'oauth_client' ? '<button type="button" data-act="see-activity" data-id="' + esc(x.id) + '">' +
					icon('history') + esc(__('See activity', 'llamly')) + '</button>' : '') +
				(x.kind !== 'oauth_client' ? '<hr>' : '') + '<button type="button" class="is-danger" data-act="revoke" data-id="' +
					esc(x.id) + '">' + icon('unplug') + esc(__('Revoke access', 'llamly')) + '</button>';
			/* translators: %s: connection name. */
			const actionsLabel = sprintf(__('Actions for %s', 'llamly'), x.label || __('connection', 'llamly'));
			return '<tr><td><div class="ll-conn"><span class="ll-conn__tile' + brandClass(client) + '">' + (client ?
				clientIcon(client) : icon(x.kind === 'key' ? 'key-round' : 'globe')) + '</span>' +
				'<div><strong>' + esc(x.label || __('App', 'llamly')) + '</strong><span class="ll-cell-sub">' + sub + '</span></div></div></td>' +
				'<td class="ll-hide-sm">' + esc(METHOD[x.kind]) + '</td><td>' + pill(acc[0], acc[1]) + '</td>' +
				'<td class="ll-hide-sm ll-nowrap">' + esc(dayLabel(x.created_at)) + '</td><td class="ll-nowrap">' + used + '</td>' +
				'<td class="ll-cell-actions"><div class="ll-menu-wrap"><button type="button" class="ll-btn ll-btn--ghost ll-btn--icon ll-btn--sm" data-menu-trigger aria-label="' +
					esc(actionsLabel) + '" aria-expanded="false">' + icon('more-horizontal') + '</button><div class="ll-menu" hidden>' +
					menu + '</div></div></td></tr>';
		}).join('');
		return '<section class="ll-card" aria-labelledby="apps-title"><div class="ll-card__head"><div><h2 class="ll-card__title" id="apps-title">' +
			esc(__('Connected apps', 'llamly')) + '</h2><p class="ll-card__desc">' +
			esc(__('Each connection has its own access level and can be revoked at any time.', 'llamly')) + '</p></div>' +
			(showConnectButton ? '<button type="button" class="ll-btn ll-btn--primary" data-act="open-picker">' + icon('plus') +
				esc(__('Connect another app', 'llamly')) + '</button>' : '') + '</div>' +
			'<div class="ll-table-wrap"><table class="ll-table"><thead><tr><th>' + esc(__('Connection', 'llamly')) +
				'</th><th class="ll-hide-sm">' + esc(__('Type', 'llamly')) + '</th><th>' + esc(__('Access', 'llamly')) +
				'</th><th class="ll-hide-sm">' + esc(__('Created', 'llamly')) + '</th><th>' + esc(__('Last used', 'llamly')) +
				'</th><th><span class="ll-sr">' + esc(__('Actions', 'llamly')) + '</span></th></tr></thead><tbody>' + rows +
				'</tbody></table></div></section>';
	}

	/* ---------- diagnostics (real Doctor) ---------- */
	const CHECK_LABEL = {
		settings: __('Valid Llamly settings', 'llamly'), server_enabled: _x('MCP server on', 'diagnostic check', 'llamly'),
			https: __('Site on HTTPS', 'llamly'), public_hostname: __('Address reachable by cloud apps', 'llamly'),
		permalinks: __('Permalinks', 'llamly'), api_keys: __('Access key available', 'llamly'),
			oauth_prereqs: __('Browser sign-in requirements', 'llamly'), registration_window: __('New connections window',
			'llamly'),
		rest_blockers: __('Plugins that block the REST API', 'llamly'), abilities_bridge: 'WordPress Abilities API',
			schema: __('Llamly tables in the database', 'llamly'), mutation_storage: __('Undo history storage', 'llamly'),
		loopback_rest_index: __('REST API reachable by the server', 'llamly'),
			loopback_endpoint_unauth: __('MCP endpoint requires authentication (server)', 'llamly'),
			loopback_endpoint_auth: __('Authenticated MCP connection (server)', 'llamly'),
		loopback_temp_key_cleanup: __('Removal of the diagnostics temporary key', 'llamly'),
			well_known_pr: __('OAuth discovery: protected resource (server)', 'llamly'),
			well_known_as: __('OAuth discovery: authorization server (server)', 'llamly'),
		doctor_audit: __('Diagnostics record', 'llamly'),
	};
	async function runBrowserDoctor() {
		const targets = [
			{id: 'browser_rest_index', label: __('REST API reachable in this browser', 'llamly'), url: config.restIndex,
				method: 'GET', check: (r, b) => r.status === 200 && b && Array.isArray(b.namespaces) &&
				b.namespaces.includes('llamly/v1')},
			{id: 'browser_endpoint_unauth', label: __('MCP endpoint requires authentication (browser)', 'llamly'),
				url: config.mcpEndpoint, method: 'POST', body: {jsonrpc: '2.0', id: 'browser-doctor', method: 'initialize',
				params: {protocolVersion: '2025-06-18', capabilities: {}, clientInfo: {name: 'llamly-doctor-browser', version: '1'}}},
				check: (r, b) => r.status === 401 && b && b.error === 'unauthorized'},
			{id: 'browser_well_known_pr', label: __('OAuth discovery: protected resource (browser)', 'llamly'),
				url: config.protectedResource, method: 'GET', field: 'resource'},
			{id: 'browser_well_known_as', label: __('OAuth discovery: authorization server (browser)', 'llamly'),
				url: config.authorizationServer, method: 'GET', field: 'issuer'},
		];
		return Promise.all(targets.map(async (t) => {
			try {
				const response = await fetch(t.url, {method: t.method, credentials: 'same-origin', cache: 'no-store',
					redirect: 'manual', headers: t.method === 'POST' ? {Accept: 'application/json', 'Content-Type': 'application/json'} :
					{Accept: 'application/json'}, body: t.body ? JSON.stringify(t.body) : undefined});
				const type = (response.headers.get('content-type') || '').toLowerCase();
				let body = null;
				if (type.includes('json')) { try { body = await response.json(); } catch (e) { body = null; } }
				const ok = t.check ? t.check(response, body) : response.status === 200 && !!(body && body[t.field]);
				const fingerprint = !ok && type.includes('text/html') ? 'html_intercept' : !ok && !type.includes('json') ?
					'unexpected_content_type' : !ok && !body ? 'invalid_json' : null;
				return {id: t.id, label: t.label, status: ok ? 'pass' : 'fail', detail: ok ?
					__('Response observed in this browser.', 'llamly') : __('The response in the browser didn\'t match what was expected.',
					'llamly'), evidence: ['HTTP ' + response.status, 'content-type: ' + (type || __('missing', 'llamly'))],
					fingerprint: fingerprint, fix: ok ? null :
					__('Check the server\'s WAF, cache, rewrite rules and CORS. The server result doesn\'t replace this check.', 'llamly')};
			} catch (e) {
				return {id: t.id, label: t.label, status: 'fail', detail: __('The browser couldn\'t read this address.', 'llamly'),
					evidence: [__('fetch rejected', 'llamly')], fingerprint: 'browser_network_or_cors',
					fix: __('Check that the browser reaches the same origin and that the proxy allows these addresses.', 'llamly')};
			}
		}));
	}
	// When every browser-side check passed, a server that can't call its own URL is a warning (it only limits server-side features), not a failure.
	function softenLoopback(serverReport, browserChecks) {
		const browserOk = browserChecks.length > 0 && browserChecks.every((c) => c.status === 'pass');
		if (!browserOk) return serverReport;
		// One warning instead of one per probe: they all share the same cause.
		const isLoop = (c) => c.status === 'fail' && c.fingerprint === 'loopback_unreachable';
		const loops = (serverReport.checks || []).filter(isLoop);
		if (!loops.length) return serverReport;
		const merged = {id: loops[0].id, label: loops[0].label, status: 'warn', fingerprint: 'loopback_unreachable',
			detail: __('The server could not request its own address, but this browser reached the site. AI apps connect from outside, so this usually does not block them.',
				'llamly'),
			evidence: loops.map((c) => CHECK_LABEL[c.id] || c.label ||
				c.id).concat([__('Could not verify that the server passes the Authorization header; if the app connects but every call returns 401, that is the cause.',
				'llamly')]),
			fix: __('If connections fail, ask your host to allow the site to request its own URL.', 'llamly')};
		const checks = [];
		let placed = false;
		(serverReport.checks || []).forEach((c) => { if (!isLoop(c)) checks.push(c); else if (!placed) { checks.push(merged); placed = true; } });
		return Object.assign({}, serverReport, {checks: checks});
	}
	function doctorChecks(report) { return [].concat(report.checks || [], report.browser_checks || []); }
	function reportText(report) {
		/* translators: %s: date and time the report was generated. */
		const lines = ['Llamly ' + (server().plugin_version || '') + ' · ' + (site().host || ''), sprintf(__('Generated: %s',
			'llamly'), report.generated_at || new Date().toISOString()), sprintf(__('Environment: %s', 'llamly'),
			JSON.stringify(report.environment || {}))];
		doctorChecks(report).forEach((c) => {
			lines.push('[' + String(c.status || '').toUpperCase() + '] ' + (CHECK_LABEL[c.id] || c.label) + ' (' + c.id + '): ' + (c.detail || '') +
				/* translators: %s: suggested fix. */
				((c.evidence || []).length ? ' · ' + c.evidence.join(' · ') : '') + (c.fingerprint ? ' · fingerprint: ' +
					c.fingerprint : '') + (c.fix ? '\n  ' + sprintf(__('Fix: %s', 'llamly'), c.fix) : ''));
		});
		return lines.join('\n');
	}
	function doctorCard() {
		const d = ui.doctor;
		let body;
		if (ui.doctorRunning) {
			body = '<div class="ll-live">' + icon('loader-2', 'll-spin') + '<span>' +
				esc(__('Checking endpoint, HTTPS, headers and OAuth discovery…', 'llamly')) + '</span></div>';
		} else if (d && d.error) {
			body = '<div class="ll-callout ll-callout--danger">' + icon('alert-circle') +
				'<div class="ll-callout__body"><span class="ll-callout__title">' + esc(__('The diagnostics didn\'t finish', 'llamly')) +
				'</span><span>' + esc(d.error) + '</span></div></div>';
		} else if (d) {
			const counts = {pass: 0, warn: 0, fail: 0, other: 0};
			const checks = doctorChecks(d.report);
			checks.forEach((c) => { if (c.status === 'pass') counts.pass++; else if (c.status === 'warn') counts.warn++; else if (c.status === 'fail' ||
				c.status === 'error') counts.fail++; else counts.other++; });
			const ICON = {pass: 'check-circle-2', warn: 'alert-triangle', fail: 'alert-circle', error: 'alert-circle', skip: 'circle-help', pending: 'clock'};
			const CLS = {pass: 'is-pass', warn: 'is-warn', fail: 'is-fail', error: 'is-fail'};
			/* translators: %s: diagnostic code. */
			const codeLine = (fp) => '<span class="ll-faint">' + sprintf(esc(__('Code: %s', 'llamly')), '<code>' + esc(fp) + '</code>') + '</span>';
			const items = checks.map((c) => '<li class="ll-check ' + (CLS[c.status] || '') + '">' + icon(ICON[c.status] || 'circle-help') +
				'<div class="ll-check__body"><strong>' + esc(CHECK_LABEL[c.id] || c.label || c.id) + '</strong><span>' + esc(c.detail || '') + '</span>' +
				((c.evidence || []).length ? '<span class="ll-mono">' + esc(c.evidence.join(' · ')) + '</span>' : '') +
				(c.fingerprint && c.status !== 'pass' ? codeLine(c.fingerprint) : '') +
				(c.fix && c.status !== 'pass' ? '<p class="ll-check__fix">' + esc(c.fix) + '</p>' : '') + '</div></li>').join('');
			/* translators: %d: number of problems found. */
			const summary = counts.fail ? pill(sprintf(_n('%d problem', '%d problems', counts.fail, 'llamly'), counts.fail),
				'danger') : pill(__('All good', 'llamly'), 'success');
			const at = parseUtc(d.report.generated_at);
			/* translators: %d: number of warnings. */
			const warnPill = counts.warn ? pill(sprintf(_n('%d warning', '%d warnings', counts.warn, 'llamly'), counts.warn), 'warning') : '';
			/* translators: %s: when the check ran ("today, 14:05" or "just now"). */
			body = '<div class="ll-doctor-sum">' + summary + warnPill + '<span class="ll-faint">' + esc(sprintf(__('Checked %s',
				'llamly'), at ? dayTime(d.report.generated_at, true) : __('just now', 'llamly'))) +
				'</span></div><ul class="ll-checks">' + items + '</ul>';
		} else {
			body = '<p class="ll-muted">' + esc(__('Tests whether AI apps can reach this site and builds a report to copy, in case you need help.', 'llamly')) + '</p>';
		}
		const ran = !!(d && d.report) && !ui.doctorRunning;
		const support = (site().links || {}).support;
		return '<section class="ll-card" aria-labelledby="doctor-title"><div class="ll-card__head"><div><h2 class="ll-card__title" id="doctor-title">' +
			icon('stethoscope') + esc(__('Connection diagnostics', 'llamly')) + '</h2>' +
			(ran ? '<p class="ll-card__desc">' + esc(__('Tests whether AI apps can reach this site.', 'llamly')) + '</p>' : '') + '</div><div class="ll-row">' +
			(ran ? '<button type="button" class="ll-btn ll-btn--sm" data-act="copy-report">' + icon('copy') + esc(__('Copy report', 'llamly')) + '</button>' : '') +
			'<button type="button" class="ll-btn ' + (d ? 'll-btn--sm' : 'll-btn--dark') + '" data-act="run-doctor"' +
				(ui.doctorRunning ? ' disabled' : '') + '>' + (d ? icon('refresh-cw') + esc(__('Check again', 'llamly')) :
				esc(__('Run checks', 'llamly'))) + '</button></div></div>' +
			'<div class="ll-card__body">' + body + '</div>' +
			(support ? '<div class="ll-card__foot"><span>' + esc(__('Still having trouble?', 'llamly')) + '</span><a href="' +
				esc(support) + '" target="_blank" rel="noopener noreferrer">' + esc(__('Ask for help on the WordPress.org forum',
				'llamly')) + ' ' + icon('external-link', 'll-icon--sm') + '</a></div>' : '') + '</section>';
	}

	/* ---------- real polling: "Waiting…" → "Connected" ---------- */
	function pollTarget(id) {
		const mode = recipeMode(id);
		if (mode === 'key' || mode === 'bridge') {
			const k = ui.keys[id];
			return k ? {type: 'key', rowId: 'key:' + k.id} : null;
		}
		if (mode === 'oauth') return {type: 'oauth'};
		return null;
	}
	function ensurePoll(id) {
		if (!id || ui.connected[id] || ui.expired[id]) { stopPoll(); return; }
		const target = pollTarget(id);
		if (!target) { stopPoll(); return; }
		if (ui.poll && ui.poll.client === id && ui.poll.target.type === target.type && ui.poll.target.rowId === target.rowId) { schedulePoll(); return; }
		stopPoll();
		if (target.type === 'oauth') {
			target.baseline = new Set(connItems().filter((x) => x.kind === 'oauth').map((x) => x.id));
			target.forAttempt = !!attemptFor(id);
		}
		ui.poll = {client: id, target: target, started: Date.now(), timer: null, sig: connSignature()};
		schedulePoll();
	}
	function stopPoll() {
		if (ui.poll) { clearTimeout(ui.poll.timer); ui.poll = null; }
	}
	function schedulePoll() {
		const p = ui.poll;
		if (!p) return;
		clearTimeout(p.timer);
		if (document.hidden) return; // resumes when the tab becomes visible again
		p.timer = setTimeout(pollTick, POLL_MS);
	}
	function connSignature() {
		const at = attemptState();
		return JSON.stringify([connItems().map((x) => [x.id, x.last_used_at, x.revoked_at]), !!windowState().open,
			at ? [at.client, at.stage, !!at.quiet] : null]);
	}
	function pollHit(p) {
		const items = connItems();
		if (p.target.type === 'key') return items.find((x) => x.id === p.target.rowId && x.last_used_at && !x.revoked_at) || null;
		// LLP-467: with an "Add to <app>" attempt, only the connection of the attempt's own grant counts (correlated on the
		// server by client redirect, administrator and grant); any attempt for another app counts for nothing here.
		const at = attemptState();
		if (at && windowState().open) {
			if (at.client === p.client) p.target.forAttempt = true; // an attempt seen while polling binds this poll to it
			if (at.client !== p.client || !at.connection_id) return null;
			return items.find((x) => x.id === at.connection_id && x.last_used_at && !x.revoked_at) || null;
		}
		// A poll started for an "Add to <app>" attempt ends with it: a closed window never falls back to "any new
		// connection". The gate button starts a new poll for the manual path (below).
		if (p.target.forAttempt) return null;
		return items.find((x) => x.kind === 'oauth' && !p.target.baseline.has(x.id) && x.last_used_at && !x.revoked_at) || null;
	}
	function renderConexoesSafely() {
		const a = document.activeElement;
		if (a && mount.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return false; // does not interrupt someone typing
		if ($('.ll-modal-backdrop') || $$('.llamly .ll-menu').some((m) => !m.hidden)) return false;
		refresh();
		return true;
	}
	async function pollTick() {
		const p = ui.poll;
		if (!p) return;
		if (activeTab() !== 'connections') { stopPoll(); return; }
		if (Date.now() - p.started > POLL_MAX_MS) {
			ui.expired[p.client] = true; stopPoll(); renderConexoesSafely(); return;
		}
		try {
			await loadConnections();
			p.failures = 0;
		} catch (error) {
			if (error.status === 401 || error.status === 403) { stopPoll(); fail(error); return; }
			// A server or network failure is shown after three attempts instead of waiting silently for 30 minutes.
			p.failures = (p.failures || 0) + 1;
			if (p.failures >= 3) { stopPoll(); fail(error); return; }
			schedulePoll(); return;
		}
		if (p !== ui.poll) return;
		const hit = pollHit(p);
		if (hit) {
			ui.connected[p.client] = hit.last_used_at;
			stopPoll();
			if (ui.keys[p.client]) ui.keys[p.client].secret = ''; // connected: the key leaves the page
			refresh();
			/* translators: %s: app name. */
			toast(sprintf(__('%s connected', 'llamly'), CLIENTS[p.client].label));
			return;
		}
		const sig = connSignature();
		if (sig !== p.sig) { p.sig = sig; renderConexoesSafely(); }
		schedulePoll();
	}
	document.addEventListener('visibilitychange', () => { if (!document.hidden && ui.poll) schedulePoll(); });
	// "Didn't work? Try another way" stays as the person left it when the page re-renders.
	mount.addEventListener('toggle', (ev) => {
		const d = ev.target;
		if (!d || !d.matches || !d.matches('details[data-alt]')) return;
		const id = d.dataset.alt;
		// Opened on its own (Claude did not arrive): a close by the person sticks; the automatic open is not remembered.
		// Opened again by hand after that close: remembered. (The automatic open never follows a close, so it stays unsaved.)
		if (d.classList.contains('is-highlight')) {
			if (!d.open) { ui.altDismissed[id] = true; ui.altOpen[id] = false; } else if (ui.altDismissed[id]) { ui.altDismissed[id] = false; ui.altOpen[id] = true; }
			return;
		}
		ui.altOpen[id] = d.open;
	}, true);

	/* ---------- real actions ---------- */
	async function withRevision(fn) {
		try { return await fn(Number(settings().revision || 0)); } catch (error) {
			if (error.code !== 'stale_revision') throw error;
			await loadState();
			return fn(Number(settings().revision || 0));
		}
	}
	async function setWindow(action, client) {
		const result = await withRevision((revision) => api('connections/oauth-window', {method: 'POST',
			body: Object.assign({action: action, revision: revision}, client ? {client: client} : {}),
			errors: {stale_revision: __('The settings changed in another session. Try again.', 'llamly')}}));
		if (result && result.settings && S.data) S.data.settings = result.settings;
		if (S.conns && result && result.window) S.conns.window = result.window;
		if (S.conns && result && 'attempt' in result) S.conns.attempt = result.attempt;
		return result;
	}

	function editAccess(rowId) {
		const x = connItems().find((c) => c.id === rowId);
		if (!x || x.kind !== 'key') return;
		const keyId = Number(String(x.id).replace('key:', ''));
		const mode = x.scope_mode === 'full' && !writeEnabled() ? 'read_only' : x.scope_mode;
		const client = inferClient(x);
		/* translators: %s: name of the user who created the key. */
		const fullDesc = writeEnabled() ? sprintf(__('Read and edit the site with the permissions of %s.', 'llamly'),
			x.user_name || __('whoever created the key', 'llamly')) : '';
		modal({
			title: esc(__('Edit access', 'llamly')),
			sub: esc(x.label) + (client ? ' · ' + esc(CLIENTS[client].label) : ''),
			body: '<div class="ll-choice">' + accessChoices('acc', mode, {full: fullDesc,
				read_only: __('Look up content and settings, without changing anything.', 'llamly'),
				custom: __('Only the categories checked below.', 'llamly')}) + '</div>' +
				catGrid(x.scope_mode === 'custom' ? x.allowed_tools : [], x.scope_mode !== 'custom') + '<p class="ll-faint">' +
					esc(__('Applies from the agent\'s next request.', 'llamly')) + '</p>',
			actions: cancelBtn() + '<button type="button" class="ll-btn ll-btn--primary" data-save>' + esc(__('Save access', 'llamly')) + '</button>',
			onMount(m) {
				m.addEventListener('change', (ev) => { if (ev.target.name === 'acc') $('[data-catgrid]', m).hidden = ev.target.value !== 'custom'; });
				const save = $('[data-save]', m);
				save.addEventListener('click', busyButton(save, async () => {
					const checked = $('input[name=acc]:checked', m);
					const scope = checked ? checked.value : 'read_only';
					const allowed = scope === 'custom' ? selectedTools(m) : [];
					if (scope === 'custom' && !allowed.length) throw new Error(__('Check at least one category.', 'llamly'));
					await api('connections/keys/' + keyId, {method: 'PUT', body: {scope_mode: scope, allowed_tools: allowed}});
					await loadConnections();
					closeModal(); refresh(); toast(__('Access updated', 'llamly'));
				}));
			},
		});
	}

	function revoke(rowId) {
		const x = connItems().find((c) => c.id === rowId);
		if (!x) return;
		/* translators: %s: connection name. */
		const sub = sprintf(__('%s loses access to the site on its next request.', 'llamly'), x.label || __('This connection', 'llamly'));
		modal({
			title: esc(__('Revoke access?', 'llamly')),
			sub: esc(sub),
			body: '<p class="ll-muted">' + esc(x.kind === 'key'
				? __('This connection\'s change history and logs stay saved. To connect again, you create a new key.', 'llamly')
				: __('This connection\'s change history and logs stay saved. To connect again, you create a new approval.', 'llamly')) + '</p>',
			actions: cancelBtn() + '<button type="button" class="ll-btn ll-btn--danger" data-ok>' + esc(__('Revoke access', 'llamly')) + '</button>',
			onMount(m) {
				const ok = $('[data-ok]', m);
				ok.addEventListener('click', busyButton(ok, async () => {
					const path = x.kind === 'key' ? 'connections/keys/' + encodeURIComponent(String(x.id).replace('key:', ''))
						: x.kind === 'oauth' ? 'connections/grants/' + encodeURIComponent(x.grant_id)
							: 'connections/clients/' + encodeURIComponent(x.client_id);
					await api(path, {method: 'DELETE'});
					Object.keys(ui.keys).forEach((c) => { if (x.kind === 'key' && 'key:' + ui.keys[c].id === x.id) { delete ui.keys[c]; delete ui.connected[c]; } });
					await Promise.all([loadConnections(), loadState()]);
					/* translators: %s: connection name. */
					closeModal(); refresh(); toast(sprintf(__('%s: access revoked', 'llamly'), x.label || __('Connection', 'llamly')), {icon: 'unplug'});
				}));
			},
		});
	}

	tabs.connections = {
		label: __('Connections', 'llamly'),
		render(root) {
			const has = activeConns().length > 0;
			const requested = param('client');
			const current = Object.prototype.hasOwnProperty.call(CLIENTS, requested) ? requested : '';
			if (current !== ui.lastClient) {
				// Switching apps removes any displayed key from the page.
				Object.keys(ui.keys).forEach((c) => { if (c !== current) ui.keys[c].secret = ''; });
				ui.lastClient = current;
			}
			const showPicker = !has || params().has('novo') || !!current;
			const serverOff = !server().enabled;
			root.innerHTML = '<p class="ll-lede">' +
				__('Connect AI agents like <strong>Claude</strong>, <strong>ChatGPT</strong> or <strong>Codex</strong> to this site through the Model Context Protocol (MCP).',
				'llamly') + '</p>' +
				(serverOff ? '<div class="ll-callout ll-callout--warning">' + icon('power') +
					'<div class="ll-callout__body"><span class="ll-callout__title">' + esc(__('The MCP server is off', 'llamly')) +
					'</span><span>' + esc(__('No agent can reach the site right now. Connections stay saved.', 'llamly')) +
					'</span></div><div class="ll-callout__actions"><button type="button" class="ll-btn ll-btn--sm ll-btn--dark" data-act="server-on">' +
					esc(__('Turn on server', 'llamly')) + '</button></div></div>' : '') +
				(showPicker ? connectCard(current) : '') + (has ? connectionsCard(!showPicker) : '') + doctorCard();
			bindConexoes(root);
			if (showPicker && current) ensurePoll(current); else stopPoll();
		},
	};

	function bindConexoes(root) {
		root.addEventListener('change', (ev) => {
			if (ev.target.name === 'access') {
				const grid = $('[data-catgrid]', ev.target.closest('form'));
				if (grid) grid.hidden = ev.target.value !== 'custom';
			}
		});
		root.addEventListener('click', async (ev) => {
			const pick = ev.target.closest('.ll-pick[data-client], .ll-local-opt[data-client]');
			if (pick) { navigate({client: pick.dataset.client}); return; }
			const go = ev.target.closest('[data-goto]');
			if (go) { ev.preventDefault(); navigate({tab: go.dataset.goto, client: null, novo: null}); return; }
			const a = ev.target.closest('[data-act]');
			if (!a) return;
			const act = a.dataset.act;
			try {
				if (act === 'open-picker') navigate({novo: 1});
				else if (act === 'close-picker') navigate({novo: null, client: null});
				else if (act === 'gate-open' || act === 'gate-close') {
					a.disabled = true;
					await setWindow(act === 'gate-open' ? 'open' : 'close');
					// Opening by hand (manual add): follow any new connection again, with a fresh baseline.
					const shown = params().get('client');
					if (act === 'gate-open' && shown && !attemptFor(shown)) { delete ui.expired[shown]; stopPoll(); }
					refresh();
					if (act === 'gate-open') toast(__('New connections open for 30 minutes', 'llamly'), {icon: 'unlock'});
				} else if (act === 'open-app') {
					const id = a.dataset.client;
					const url = id === 'chatgpt' ? CHATGPT_URL : site().claude_install_url;
					if (id === 'chatgpt') copyText(endpoint());
					// Opens on click (pop-up blockers), then opens the window on the server.
					if (url) window.open(url, '_blank', 'noopener,noreferrer');
					await setWindow('open', id);
					// A new click is a new attempt: follow it again even if an earlier one connected or timed out.
					delete ui.connected[id]; delete ui.expired[id];
					stopPoll(); // the next render takes a fresh baseline for the new attempt
					delete ui.altDismissed[id];
					ui.opened[id] = true;
					refresh();
					toast(id === 'chatgpt' ? __('Address copied. Paste it into ChatGPT as the MCP server URL.', 'llamly') :
						__('We opened claude.ai in another tab', 'llamly'), {icon: 'external-link'});
				} else if (act === 'oc-scope') { ui.opencodeScope = a.dataset.scope; refresh(); }
				else if (act === 'hide-key') { const k = ui.keys[a.dataset.client]; if (k) k.secret = ''; refresh(); }
				else if (act === 'goto-client') navigate({client: a.dataset.client});
				else if (act === 'poll-again') { delete ui.expired[a.dataset.client]; refresh(); }
				else if (act === 'run-doctor') {
					ui.doctorRunning = true; refresh();
					try {
						const serverReport = await api('doctor', {method: 'POST', body: {}});
						const browserChecks = await runBrowserDoctor();
						ui.doctor = {report: Object.assign({}, softenLoopback(serverReport, browserChecks), {browser_checks: browserChecks})};
					} catch (error) {
						ui.doctor = {error: error.message || __('Try again.', 'llamly'), report: null};
					} finally {
						ui.doctorRunning = false;
						if (activeTab() === 'connections') refresh();
					}
				} else if (act === 'copy-report') { if (ui.doctor && ui.doctor.report) copyText(reportText(ui.doctor.report), a); }
				else if (act === 'edit-access') editAccess(a.dataset.id);
				else if (act === 'revoke') revoke(a.dataset.id);
				else if (act === 'see-activity') navigate({tab: 'logs', conn: a.dataset.id, client: null, novo: null});
				else if (act === 'server-on') {
					a.disabled = true;
					await withRevision((revision) => api('settings', {method: 'PUT', body: {settings: {enabled: true}, revision: revision}, errors: SETTINGS_ERRORS}));
					await Promise.all([loadState(), loadConnections()]);
					refresh(); toast(_x('MCP server on', 'diagnostic check', 'llamly'), {icon: 'power'});
				}
			} catch (error) {
				fail(error);
				if (a.isConnected) a.disabled = false;
			}
		});
		root.addEventListener('submit', async (ev) => {
			const f = ev.target.closest('[data-form=key]');
			if (!f) return;
			ev.preventDefault();
			const id = f.dataset.client;
			const btn = $('button[type=submit]', f);
			const label = f.elements.name.value.trim();
			const checked = $('input[name=access]:checked', f);
			const scope = checked ? checked.value : 'read_only';
			const allowed = scope === 'custom' ? selectedTools(f) : [];
			if (!label) { toast(__('Give the key a name.', 'llamly'), {tone: 'error'}); f.elements.name.focus(); return; }
			if (scope === 'custom' && !allowed.length) { toast(__('Check at least one category.', 'llamly'), {tone: 'error'}); return; }
			btn.disabled = true;
			try {
				const result = await api('connections/keys', {method: 'POST', body: {label: label, scope_mode: scope, allowed_tools: allowed}});
				const k = result && result.key;
				if (!k || !k.secret) throw new Error(__('The key didn\'t come back from the server. Revoke the new connection and try again.', 'llamly'));
				ui.keys[id] = {id: k.id, label: k.label, access: k.scope_mode, secret: k.secret};
				delete ui.connected[id]; delete ui.expired[id];
				await loadConnections();
				refresh();
			} catch (error) {
				fail(error);
				btn.disabled = false;
			}
		});
	}

	/* ---------- shared pagination (Logs and Activity) ---------- */
	const PAGE_SIZES = [25, 50, 100];
	function pager(page, per, pages, shown, total) {
		const start = (page - 1) * per;
		/* translators: pagination. 1: first item shown, 2: last item shown, 3: total items. */
		return '<div class="ll-pager"><span>' + esc(sprintf(__('%1$s–%2$s of %3$s', 'llamly'), start + 1, start + shown,
			total)) + '</span><span class="ll-row"><select class="ll-select ll-select--sm" name="per" aria-label="' +
			esc(__('Items per page', 'llamly')) + '">' +
			/* translators: %d: number of items per page. */
			PAGE_SIZES.map((n) => '<option value="' + n + '"' + (per === n ? ' selected' : '') + '>' +
				esc(sprintf(__('%d per page', 'llamly'), n)) + '</option>').join('') + '</select>' +
			'<button type="button" class="ll-btn ll-btn--sm" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') +
				'>' + esc(__('Previous', 'llamly')) + '</button><button type="button" class="ll-btn ll-btn--sm" data-page="' + (page +
				1) + '"' + (page >= pages ? ' disabled' : '') + '>' + esc(__('Next', 'llamly')) + '</button></span></div>';
	}

	/* =====================================================================
	   Logs
	   ===================================================================== */
	const RESULT = {ok: [__('Success', 'llamly'), 'success'], denied: [__('Denied', 'llamly'), 'warning'],
		rate: [__('Limit reached', 'llamly'), 'warning'], error: [__('Error', 'llamly'), 'danger']};
	const LOG_FILTERS = [['all', __('All', 'llamly')], ['ok', __('Success', 'llamly')], ['denied', _x('Denied',
		'logs filter', 'llamly')], ['error', __('Errors', 'llamly')]];
	const EVENT_LABEL = {
		tool_call: __('Tool call', 'llamly'), audit_cleared: __('Logs cleared', 'llamly'),
			auth_failed: __('Authentication denied', 'llamly'), credential_created: __('Key created', 'llamly'),
			credential_revoked: __('Key revoked', 'llamly'),
		credential_scope_updated: __('Key access changed', 'llamly'), doctor_run: __('Diagnostics run', 'llamly'),
			doctor_temporary_key_cleanup_failed: __('Diagnostics key not removed', 'llamly'),
		oauth_client_registered: __('App registered', 'llamly'), oauth_client_revoked: __('App registration revoked',
			'llamly'), oauth_grant_created: __('Access approved', 'llamly'), oauth_grant_revoked: __('Access revoked', 'llamly'),
		oauth_tokens_issued: __('Sign-in completed', 'llamly'), oauth_tokens_refreshed: __('Session renewed', 'llamly'),
			oauth_window_open: __('New connections opened', 'llamly'),
			module_guide_trimmed: __('Add-on guide shortened', 'llamly'),
			connect_started: __('Connection started', 'llamly'), connect_incomplete: __('Connection not completed', 'llamly'), oauth_window_close: __('New connections closed', 'llamly'),
		oauth_code_reuse_detected: __('Sign-in code reused', 'llamly'),
			oauth_refresh_reuse_detected: __('Session renewal reused', 'llamly'), permit_issued: __('Undo or redo approved',
			'llamly'),
		post_created: __('Post created', 'llamly'), post_updated: __('Post updated', 'llamly'),
			post_undone: __('Change undone', 'llamly'), post_redone: __('Change redone', 'llamly'),
			rate_limited: __('Limit reached', 'llamly'),
		recovery_resolved: __('Recovery released', 'llamly'), settings_updated: __('Settings saved', 'llamly'),
			post_recovered: __('Change recovered', 'llamly'), post_trashed: __('Post moved to trash', 'llamly'),
			post_deleted: __('Post deleted', 'llamly'),
		approval_requested: __('Approval requested', 'llamly'), approval_granted: __('Approval granted', 'llamly'),
			approval_rejected: __('Approval rejected', 'llamly'),
	};
	/* translators: %s: internal event name with spaces (for events without a label). */
	const eventLabel = (ev) => EVENT_LABEL[ev] || sprintf(__('Event %s', 'llamly'), String(ev || '').replace(/_/g, ' '));
	const lst = { per: 25, from: '', to: '',f: 'all', q: '', c: '', page: 1, data: null, error: null, loading: false, has: null, seq: 0, timer: null};

	function credentialName(cred) {
		if (!cred) return '';
		if (/^admin:/.test(cred)) return __('Administrator', 'llamly');
		if (/^(test|qa):/.test(cred)) return __('Automated test', 'llamly');
		if (/^ability:/.test(cred)) return __('WordPress Abilities', 'llamly');
		const row = connItems().find((x) => x.id === cred);
		if (!row) return cred;
		return row.label === DOCTOR_KEY_LABEL ? __('Diagnostics', 'llamly') : (row.label || cred);
	}
	function credentialClient(cred) { return inferClient(connItems().find((x) => x.id === cred)); }
	const isPersonCredential = (cred) => /^admin:/.test(String(cred || '')) || connItems().some((x) => x.id === cred && x.label === DOCTOR_KEY_LABEL);

	// LLP-467: where an "Add to <app>" attempt stopped, from the connect_incomplete context.
	function connectIncompleteDetail(ctx) {
		const app = ctx.client === 'chatgpt' ? 'ChatGPT' : /^claude-/.test(String(ctx.client || '')) ? 'Claude' : __('The app', 'llamly');
		return {
			/* translators: %s: app name. */
			waiting: sprintf(__('%s never reached the site. Check the connection diagnostics and, on Claude Team or Enterprise, that the owner added the connector.', 'llamly'), app),
			/* translators: %s: app name. */
			registered: sprintf(__('A client with the %s address registered on the site, but access was not approved on the sign-in page.', 'llamly'), app),
			/* translators: %s: app name. */
			approved: sprintf(__('Access was approved, but %s did not finish signing in.', 'llamly'), app),
		}[ctx.stage] || __('The connection was not completed.', 'llamly');
	}
	function logRow(l) {
		const r = RESULT[l.result] || RESULT.ok;
		const isCall = l.event === 'tool_call' && l.tool;
		const ctx = l.context && typeof l.context === 'object' ? l.context : {};
		const code = ctx.code || ctx.error_code || ctx.error || '';
		/* translators: %s: event label. */
		const detail = l.event === 'connect_incomplete' ? connectIncompleteDetail(ctx) : l.result === 'ok' ? (isCall ? __('Request completed without problems.', 'llamly') : sprintf(__('%s.',
			'llamly'), eventLabel(l.event))) : (l.result === 'rate' ? __('Per-minute request limit reached.', 'llamly') :
			l.result === 'denied' ? __('Request denied.', 'llamly') : __('The request ended with an error.', 'llamly'));
		const name = credentialName(l.credential);
		const client = credentialClient(l.credential);
		const open = lst.open && lst.open.has(l.id);
		const what = isCall ? l.tool : eventLabel(l.event);
		/* translators: %s: tool or event name. */
		const toggleLabel = sprintf(__('See details for %s', 'llamly'), what);
		return '<tr class="lg-row' + (open ? ' is-open' : '') + '" data-log="' + Number(l.id) + '"><td class="ll-mono">' + esc(dayTime(l.created_at)) + '</td>' +
			'<td><span class="lg-conn">' + (name ? (client ? clientIcon(client) : icon(isPersonCredential(l.credential) ?
				'user' : 'key-round')) + '<span>' + esc(name) + '</span>' : '<span class="ll-faint">—</span>') + '</span></td>' +
			'<td' + (isCall ? ' class="ll-mono"' : '') + '>' + esc(what) + '</td><td>' + pill(r[0], r[1]) + '</td>' +
			'<td class="lg-ms ll-faint">' + (l.duration_ms == null ? '—' : Number(l.duration_ms) + ' ms') +
				'</td><td class="lg-ip ll-faint ll-mono">' + esc(l.ip || '—') + '</td>' +
			'<td class="ll-cell-actions"><button type="button" class="ll-btn ll-btn--ghost ll-btn--icon ll-btn--sm lg-toggle" data-toggle="' +
				Number(l.id) + '" aria-expanded="' + (open ? 'true' : 'false') + '" aria-label="' + esc(toggleLabel) + '">' +
				icon('chevron-down') + '</button></td></tr>' +
			'<tr class="lg-detail" id="lg-detail-' + Number(l.id) + '"' + (open ? '' : ' hidden') + '><td colspan="7"><dl class="lg-dl">' +
			'<div' + (l.event === 'connect_incomplete' ? ' class="lg-dl__wide"' : '') + '><dt>' + esc(__('Detail', 'llamly')) + '</dt><dd>' + esc(detail) + '</dd></div>' +
			'<div><dt>' + esc(__('Event', 'llamly')) + '</dt><dd>' + esc(eventLabel(l.event)) + ' · <span class="ll-mono">' + esc(l.event) + '</span></dd></div>' +
			(code ? '<div><dt>' + esc(__('Code', 'llamly')) + '</dt><dd class="ll-mono">' + esc(code) + '</dd></div>' : '') +
			'<div><dt>IP</dt><dd class="ll-mono">' + esc(l.ip || '—') + '</dd></div>' +
			'<div><dt>' + esc(__('Request', 'llamly')) + '</dt><dd class="ll-mono">#' + Number(l.id) + '</dd></div>' +
			(Object.keys(ctx).length ? '<div class="lg-dl__wide"><dt>' + esc(__('Context (redacted)', 'llamly')) +
				'</dt><dd><pre class="ll-mono lg-ctx">' + esc(JSON.stringify(ctx, null, 2)) + '</pre></dd></div>' : '') +
			'</dl></td></tr>';
	}

	function logQuery(extra) {
		const q = new URLSearchParams(extra || {});
		if (lst.f !== 'all') q.set('result', lst.f);
		if (lst.c) q.set('credential', lst.c);
		if (lst.q) q.set('q', lst.q);
		if (lst.from) q.set('date_from', lst.from);
		if (lst.to) q.set('date_to', lst.to);
		return q;
	}
	function emptyBox(iconName, title, text, button) {
		return '<div class="ll-empty"><div class="ll-empty__icon">' + icon(iconName) + '</div><h3 class="ll-empty__title">' +
			esc(title) + '</h3><p class="ll-empty__text">' + esc(text) + '</p>' + (button || '') + '</div>';
	}
	const loadingBox = (text) => '<div class="ll-empty"><div class="ll-live" style="justify-content:center">' +
		icon('loader-2', 'll-spin') + '<span>' + esc(text) + '</span></div></div>';
	function logResults() {
		if (lst.error) {
			return emptyBox('alert-circle', __('The logs couldn\'t be loaded', 'llamly'), lst.error,
				'<button type="button" class="ll-btn" data-retry>' + esc(__('Try again', 'llamly')) + '</button>');
		}
		const d = lst.data;
		if (!d) return loadingBox(__('Loading logs…', 'llamly'));
		const items = d.items || [];
		if (!items.length) {
			return emptyBox('search', __('Nothing found', 'llamly'), __('No request matches these filters.', 'llamly'),
				'<button type="button" class="ll-btn" data-clear>' + esc(__('Clear filters', 'llamly')) + '</button>');
		}
		const total = Number(d.total || 0);
		const pages = Math.max(1, Math.ceil(total / lst.per));
		return '<div class="ll-table-wrap"><table class="ll-table lg-table"><thead><tr><th>' + esc(__('Date and time',
			'llamly')) + '</th><th>' + esc(__('Connection', 'llamly')) + '</th><th>' + esc(__('Tool', 'llamly')) + '</th><th>' +
			esc(__('Result', 'llamly')) + '</th><th class="lg-ms">' + esc(__('Duration', 'llamly')) +
			'</th><th class="lg-ip">IP</th><th><span class="ll-sr">' + esc(__('Details', 'llamly')) +
			'</span></th></tr></thead><tbody>' + items.map(logRow).join('') + '</tbody></table></div>' +
			pager(lst.page, lst.per, pages, items.length, total);
	}
	function logChips() {
		const counts = (lst.data && lst.data.counts) || {};
		return LOG_FILTERS.map((f) => '<button type="button" class="ll-chip" data-f="' + f[0] + '" aria-pressed="' +
			(lst.f === f[0]) + '">' + esc(f[1]) + ' <span class="ll-chip__n">' + Number(counts[f[0]] || 0) +
			'</span></button>').join('');
	}
	function exportHref() {
		const base = plainUrl(config.exportLogsUrl || '');
		const q = logQuery().toString();
		return base + (q ? (base.indexOf('?') >= 0 ? '&' : '?') + q : '');
	}
	function logOptions() {
		const rows = connItems().filter((x) => (x.kind === 'key' || x.kind === 'oauth') && x.label !== DOCTOR_KEY_LABEL);
		/* translators: %s: connection name. */
		return '<option value="">' + esc(__('All connections', 'llamly')) + '</option>' + rows.map((x) => '<option value="' +
			esc(x.id) + '"' + (lst.c === x.id ? ' selected' : '') + '>' + esc(x.revoked_at ? sprintf(__('%s (revoked)', 'llamly'),
			x.label || x.id) : (x.label || x.id)) + '</option>').join('');
	}
	function logsCard() {
		const has = lst.has !== false;
		const retention = Number(settings().audit_retention_days || 30);
		return '<section class="ll-card" id="lg-card">' +
			(has ? '<div class="lg-toolbar"><div class="ll-search">' + icon('search') +
				'<input class="ll-input" type="search" name="q" placeholder="' + esc(__('Search by tool', 'llamly')) +
				'" aria-label="' + esc(__('Search by tool', 'llamly')) + '" value="' + esc(lst.q) + '"></div>' +
				'<select class="ll-select" name="c" aria-label="' + esc(__('Filter by connection', 'llamly')) + '">' + logOptions() + '</select>' +
				'<label class="lg-date"><span class="ll-sr">' + esc(_x('From', 'date range', 'llamly')) +
					'</span><input class="ll-input" type="date" name="from" aria-label="' + esc(__('Start date', 'llamly')) + '" value="' +
					esc(lst.from) + '"></label><label class="lg-date"><span class="ll-sr">' + esc(_x('To', 'date range', 'llamly')) +
					'</span><input class="ll-input" type="date" name="to" aria-label="' + esc(__('End date', 'llamly')) + '" value="' +
					esc(lst.to) + '"></label><div class="ll-chips lg-chips" data-chips>' + logChips() + '</div></div>' +
				'<div id="lg-results">' + logResults() + '</div>'
				: emptyBox('scroll-text', __('No requests logged', 'llamly'), __('Every time an agent talks to your site, the request shows up here.', 'llamly'))) +
			/* translators: %d: number of days logs are kept. */
			'<div class="ll-card__foot lg-foot"><span>' + esc(sprintf(_n('Kept for %d day', 'Kept for %d days', retention, 'llamly'), retention)) + '</span>' +
			(has && lst.data && Number((lst.data.counts || {}).all || 0) > 0 ?
				'<span class="ll-row"><a class="ll-btn ll-btn--ghost" href="' + esc(exportHref()) + '" data-export>' +
				icon('download') + '<span>' + esc(__('Export CSV', 'llamly')) +
				'</span></a><button type="button" class="ll-btn ll-btn--danger-ghost" data-wipe>' + icon('trash-2') + '<span>' +
				esc(__('Clear logs', 'llamly')) + '</span></button></span>' : '') + '</div></section>';
	}
	async function fetchLogs(root) {
		const seq = ++lst.seq;
		lst.error = null;
		try {
			const data = await api('logs?' + logQuery({page: String(lst.page), per_page: String(lst.per)}).toString());
			if (seq !== lst.seq) return;
			lst.data = data;
			const pages = Math.max(1, Math.ceil(Number(data.total || 0) / lst.per));
			if (lst.page > pages && Number(data.total || 0) > 0) { lst.page = pages; fetchLogs(root); return; }
		} catch (error) {
			if (seq !== lst.seq) return;
			lst.error = error.message || __('Try again.', 'llamly');
		}
		if (!root.isConnected) return;
		paintLogs(root);
	}
	function paintLogs(root) {
		const filtered = lst.f !== 'all' || lst.q || lst.c || lst.from || lst.to;
		const has = lst.error ? true : (lst.data ? (Number((lst.data.counts || {}).all || 0) > 0 || !!filtered) : true);
		if (has !== lst.has || !$('#lg-results', root)) {
			lst.has = has;
			const card = $('#lg-card', root);
			if (card) card.outerHTML = logsCard();
			return;
		}
		$('#lg-results', root).innerHTML = logResults();
		const chips = $('[data-chips]', root);
		if (chips) chips.innerHTML = logChips();
		const foot = $('.lg-foot', root);
		if (foot) {
			const tmp = document.createElement('div');
			tmp.innerHTML = logsCard();
			foot.outerHTML = $('.lg-foot', tmp).outerHTML;
		}
	}

	tabs.logs = {
		label: __('Logs', 'llamly'),
		render(root) {
			const conn = param('conn');
			if (conn) { lst.c = /^(key:\d+|oauth:[a-f0-9-]{36})$/.test(conn) ? conn :
				''; lst.page = 1; lst.f = 'all'; lst.q = ''; const p = params(); p.delete('conn'); history.replaceState({}, '',
				location.pathname + '?' + p.toString()); }
			lst.open = new Set();
			lst.data = null; lst.has = null;
			root.innerHTML = '<p class="ll-lede">' +
				esc(__('Audit log of every request made by agents, including denied ones. It\'s always on.', 'llamly')) + '</p>' +
				logsCard();
			fetchLogs(root);
			const reload = () => { lst.page = 1; fetchLogs(root); };
			root.addEventListener('input', (ev) => {
				if (ev.target.name !== 'q') return;
				lst.q = ev.target.value.trim().slice(0, 64);
				clearTimeout(lst.timer);
				lst.timer = setTimeout(reload, 300);
			});
			root.addEventListener('change', (ev) => {
				const n = ev.target.name;
				if (n === 'c') { lst.c = ev.target.value; reload(); }
				else if (n === 'from' || n === 'to') { lst[n] = /^\d{4}-\d{2}-\d{2}$/.test(ev.target.value) ? ev.target.value : ''; reload(); }
				else if (n === 'per') { lst.per = PAGE_SIZES.includes(Number(ev.target.value)) ? Number(ev.target.value) : 25; reload(); }
			});
			root.addEventListener('click', (ev) => {
				const t = (s) => ev.target.closest(s);
				let b;
				const toggle = (id) => {
					const det = $('#lg-detail-' + id, root), btn = $('[data-toggle="' + id + '"]', root);
					if (!det || !btn) return;
					const open = det.hidden;
					det.hidden = !open; btn.setAttribute('aria-expanded', String(open));
					btn.closest('tr').classList.toggle('is-open', open);
					if (open) lst.open.add(Number(id)); else lst.open.delete(Number(id));
				};
				if ((b = t('[data-f]'))) { lst.f = b.dataset.f; $$('[data-f]', root).forEach((x) => x.setAttribute('aria-pressed',
					String(x.dataset.f === lst.f))); reload(); }
				else if ((b = t('[data-page]'))) { lst.page = Math.max(1,
					Number(b.dataset.page)); fetchLogs(root).then(() => { const c = $('#lg-card',
					root); if (c) c.scrollIntoView({block: 'start'}); }); }
				else if (t('[data-clear]')) { lst.f = 'all'; lst.q = ''; lst.c = ''; lst.from = ''; lst.to = ''; lst.page = 1; lst.has = null; const card = $('#lg-card',
					root); if (card) card.outerHTML = logsCard(); fetchLogs(root); }
				else if (t('[data-retry]')) fetchLogs(root);
				else if ((b = t('[data-toggle]'))) toggle(b.dataset.toggle);
				else if ((b = t('.lg-row'))) { if (!t('a, button')) toggle(b.dataset.log); }
				else if (t('[data-wipe]')) wipeLogs(root);
			});
		},
	};

	function wipeLogs(root) {
		const total = Number(((lst.data || {}).counts || {}).all || 0);
		// Two steps, as before: the server only deletes with confirmed:true from the second one.
		modal({
			/* translators: %d: number of logged requests. */
			title: esc(__('Clear all logs?', 'llamly')), sub: esc(sprintf(_n('%d request will be deleted.', '%d requests will be deleted.', total, 'llamly'), total)),
			body: '<p class="ll-muted">' +
				esc(__('This deletes this site\'s audit log and can\'t be undone. Changes in the history aren\'t affected.',
				'llamly')) + '</p>',
			actions: cancelBtn() + '<button type="button" class="ll-btn ll-btn--danger-ghost" data-next>' + esc(__('Continue', 'llamly')) + '</button>',
			onMount: (m) => $('[data-next]', m).addEventListener('click', () => {
				modal({
					title: esc(__('Confirm clearing', 'llamly')), sub: esc(__('Last confirmation.', 'llamly')),
					body: '<p class="ll-muted">' + esc(__('The current logs will be deleted now. Only a record that the clearing happened is kept.', 'llamly')) + '</p>',
					actions: cancelBtn() + '<button type="button" class="ll-btn ll-btn--danger" data-confirm>' + esc(__('Clear logs', 'llamly')) + '</button>',
					onMount: (m2) => {
						const ok = $('[data-confirm]', m2);
						ok.addEventListener('click', busyButton(ok, async () => {
							await api('logs/clear', {method: 'POST', body: {confirmed: true}});
							closeModal();
							lst.f = 'all'; lst.q = ''; lst.c = ''; lst.page = 1; lst.has = null;
							const card = $('#lg-card', root); if (card) card.outerHTML = logsCard();
							fetchLogs(root);
							toast(__('Logs deleted', 'llamly'), {icon: 'trash-2'});
						}));
					},
				});
			}),
		});
	}

	/* =====================================================================
	   Settings (tab id: settings)
	   ===================================================================== */
	const NUMS = {rate_limit_per_minute: [10, 1000], journal_retention_days: [1, 90], audit_retention_days: [1, 365]};
	const BOOLS = ['enabled', 'safe_mode', 'require_approval', 'journal_enabled', 'debug_logging',
		'delete_data_on_uninstall', 'origin_allowlist_enabled', 'oauth_redirect_allowlist_enabled', 'abilities_enabled'];
	const LISTS = ['allowed_origins', 'extra_oauth_redirect_hosts'];
	const SWITCH_ON_OFF = [__('Enabled', 'llamly'), __('Disabled', 'llamly')];
	const SWITCH_TEXT = {enabled: [__('On', 'llamly'), __('Off', 'llamly')]};
	let draft = null;
	let saved = null;
	// Out-of-range numbers stay in the draft as typed, with an inline warning, and block saving.
	const invalidNums = new Set();

	function pickSettings(s) {
		const out = {};
		BOOLS.forEach((k) => { out[k] = !!s[k]; });
		Object.keys(NUMS).forEach((k) => { out[k] = Number(s[k] || 0); });
		LISTS.forEach((k) => { out[k] = Array.isArray(s[k]) ? s[k].map(String) : []; });
		return out;
	}
	const isDirty = () => !!draft && !!saved && JSON.stringify(draft) !== JSON.stringify(saved);
	window.addEventListener('beforeunload', (ev) => { if (activeTab() === 'settings' && (isDirty() ||
		invalidNums.size)) { ev.preventDefault(); ev.returnValue = ''; } });
	/* translators: 1: minimum allowed value, 2: maximum allowed value. */
	const rangeMessage = (key) => sprintf(__('Use a whole number from %1$s to %2$s.', 'llamly'), fmtNum(NUMS[key][0]), fmtNum(NUMS[key][1]));
	function numValid(key, raw) {
		const text = String(raw == null ? '' : raw).trim();
		if (!/^\d+$/.test(text)) return null;
		const n = parseInt(text, 10);
		return n >= NUMS[key][0] && n <= NUMS[key][1] ? n : null;
	}

	function setRow(title, desc, control, extra) {
		return '<div class="ll-setting"><div class="ll-setting__text"><div class="ll-setting__title">' + title +
			'</div><p class="ll-setting__desc">' + desc + '</p></div><div class="ll-setting__control">' + control + '</div></div>' +
			(extra || '');
	}
	function setSection(title, desc, rows, head) {
		return '<section><div class="ll-section-head"><div><h2 class="ll-section-title">' + title +
			'</h2><p class="ll-section-desc">' + desc + '</p></div></div>' + (head || '') +
			'<div class="ll-card"><div class="ll-settings-list">' + rows + '</div></div></section>';
	}
	const sw = (key) => { const t = SWITCH_TEXT[key] || SWITCH_ON_OFF; return switchEl(key, draft[key], draft[key] ? t[0] : t[1]); };
	const num = (key, unit) => '<input class="ll-input ll-input--num" type="number" name="' + key + '" min="' +
		NUMS[key][0] + '" max="' + NUMS[key][1] + '" value="' + Number(draft[key]) + '" aria-label="' + esc(unit) +
		'" aria-describedby="cfg-err-' + key + '"><span>' + esc(unit) + '</span>' +
		'<span class="ll-help cfg-num-error" id="cfg-err-' + key + '" data-num-error="' + key + '" role="alert" hidden></span>';
	const listArea = (key, id, label, placeholder, help, show) => '<div class="ll-setting__extra" data-dep-show="' + show +
		'"' + (draft[show] ? '' : ' hidden') + '><div class="ll-field"><label class="ll-label" for="' + id + '">' + esc(label) +
		'</label><textarea class="ll-textarea" id="' + id + '" name="' + key + '" rows="3" placeholder="' + esc(placeholder) +
		'">' + esc(draft[key].join('\n')) + '</textarea><span class="ll-help">' + esc(help) + '</span></div></div>';

	tabs.settings = {
		label: __('Settings', 'llamly'),
		render(root) {
			saved = pickSettings(settings());
			draft = clone(saved);
			invalidNums.clear();
			const off = !saved.enabled;
			const rec = ' <span class="ll-pill ll-pill--success">' + esc(__('Recommended', 'llamly')) + '</span>';
			const e = esc;
			const invalid = settings().valid === false ? '<div class="ll-callout ll-callout--danger cfg-callout">' +
				icon('alert-circle') + '<div class="ll-callout__body"><span class="ll-callout__title">' +
				e(__('The saved settings are invalid', 'llamly')) + '</span><span>' +
				e(__('Meanwhile, the server denies every request. Review and save again.', 'llamly')) + '</span></div></div>' : '';
			const geral = setSection(e(__('General', 'llamly')), e(__('How the server responds to agents.', 'llamly')),
				setRow(e(__('MCP server', 'llamly')), e(__('When off, every agent request is denied. Connections stay saved.', 'llamly')), sw('enabled')) +
				setRow(e(__('Safe mode', 'llamly')) + rec,
					e(__('The agent must ask you and confirm before destructive actions (like moving to trash or deleting), before undoing changes and before changes that can\'t be undone (like granting administrator access).',
					'llamly')), sw('safe_mode')) +
				setRow(e(__('Require my approval to publish', 'llamly')),
					e(__('When on, publishing or changing anything visitors see (content, comments, menus, templates, styles, media in use) waits for you in Activity. When off, the agent works directly and every change is still recorded.',
					'llamly')), sw('require_approval')) +
				setRow(e(__('Request limit', 'llamly')), e(__('Protects the site from too many calls from the same agent.',
					'llamly')), num('rate_limit_per_minute', __('per minute, per connection', 'llamly'))) +
				setRow(e(__('Detailed logging', 'llamly')),
					e(__('Also stores the argument names of each call in the Logs tab. Values are never stored.', 'llamly')),
					sw('debug_logging')),
				invalid + (off ? '<div class="ll-callout ll-callout--warning cfg-callout">' + icon('alert-triangle') +
					'<div class="ll-callout__body"><span class="ll-callout__title">' + e(__('The MCP server is off', 'llamly')) +
					'</span><span>' + e(__('No agent can use this site until you turn it back on.', 'llamly')) + '</span></div></div>' :
					''));
			const oauth = setSection(e(__('Connections without a terminal (OAuth)', 'llamly')), e(__('For AI apps that connect through the browser.', 'llamly')),
				setRow(e(__('Only accept known AI apps', 'llamly')) + rec,
					e(__('Limits the return addresses to claude.ai, chatgpt.com, cursor.com and localhost. Turn it off only if you trust the app that will connect.',
					'llamly')), sw('oauth_redirect_allowlist_enabled'),
					listArea('extra_oauth_redirect_hosts', 'cfg-hosts', __('Additional allowed addresses', 'llamly'),
						__('n8n.example.com', 'llamly'), __('One per line. Subdomains included.', 'llamly'),
						'oauth_redirect_allowlist_enabled')) +
				setRow(e(__('Only accept allowed origins', 'llamly')) + rec,
					e(__('Requests sent by web pages are only accepted when they come from this site or the addresses below. Terminal apps don\'t send an origin and aren\'t affected.',
					'llamly')), sw('origin_allowlist_enabled'),
					listArea('allowed_origins', 'cfg-origins', __('Allowed HTTPS origins', 'llamly'), __('https://app.example.com',
						'llamly'), __('One per line, with https:// and no path.', 'llamly'), 'origin_allowlist_enabled')));
			const undo = setSection(e(__('Undo', 'llamly')), e(__('How long Llamly keeps what the agent changed.', 'llamly')),
				setRow(e(__('Undo history', 'llamly')),
					e(__('Keeps the before and after of each change so you can undo it. When off, the agent can\'t change the site: writes are paused and only reading continues.',
					'llamly')), sw('journal_enabled')) +
				'<div data-undo-dep>' + setRow(e(__('Keep history for', 'llamly')), e(__('Older changes can no longer be undone.',
					'llamly')), num('journal_retention_days', __('days', 'llamly'))) + '</div>');
			const abilities = server().abilities_available ? setSection('WordPress Abilities API',
				e(__('Integration with other plugins and WordPress features.', 'llamly')),
				setRow(e(__('Expose Llamly tools as WordPress Abilities', 'llamly')),
					e(__('Makes Llamly tools visible to anyone using the WordPress Abilities API, with each user\'s permissions. The same rules apply as for agents: safe mode asks for confirmation, the optional approval setting holds publishing, users and settings for you, and everything stays in the history.',
					'llamly')), sw('abilities_enabled'))) : '';
			const logs = setSection(e(_x('Logs', 'settings section', 'llamly')), e(__('The audit log shows who did what.', 'llamly')),
				setRow(e(__('Keep audit logs for', 'llamly')), e(__('Older logs are deleted automatically.', 'llamly')),
					num('audit_retention_days', __('days', 'llamly'))));
			const data = setSection(e(__('Plugin data', 'llamly')), e(__('What happens when Llamly is deleted from WordPress.', 'llamly')),
				setRow(e(__('Delete data when deleting the plugin', 'llamly')),
					e(__('Removes Llamly connections, undo history, logs and settings. Posts and media aren\'t deleted. When off, the data is kept.',
					'llamly')), sw('delete_data_on_uninstall')));
			root.innerHTML = '<p class="ll-lede">' + e(__('How the server behaves, what it keeps and how far an agent can go.', 'llamly')) + '</p>' +
				'<form class="cfg-form" novalidate>' + geral + oauth + undo + logs + abilities + data +
				'<div class="ll-savebar" data-savebar><span data-savebar-msg>' + e(__('No unsaved changes', 'llamly')) +
					'</span><div class="ll-row" style="gap:8px"><button type="button" class="ll-btn ll-btn--ghost" data-discard hidden>' +
					e(__('Discard', 'llamly')) + '</button><button type="submit" class="ll-btn ll-btn--primary" data-save disabled>' +
					e(__('Save changes', 'llamly')) + '</button></div></div></form>';
			bindSettings(root);
			leaveGuard = async () => {
				if (!isDirty() && !invalidNums.size) return true;
				const ok = await confirmModal({title: e(__('Discard changes?', 'llamly')),
					sub: e(__('You have unsaved changes in Settings.', 'llamly')), body: '<p class="ll-muted">' +
					e(__('If you leave now, they\'re lost.', 'llamly')) + '</p>', cancelLabel: __('Keep editing', 'llamly'),
					okLabel: __('Discard and leave', 'llamly'), tone: 'll-btn--danger'});
				if (ok) { draft = clone(saved); invalidNums.clear(); }
				return ok;
			};
		},
	};

	function bindSettings(root) {
		const form = $('.cfg-form', root);
		const bar = $('[data-savebar]', root);
		const showNumError = (input) => {
			const key = input.name;
			const box = $('[data-num-error="' + key + '"]', root);
			const bad = invalidNums.has(key);
			input.setAttribute('aria-invalid', bad ? 'true' : 'false');
			if (box) { box.hidden = !bad; box.textContent = bad ? rangeMessage(key) : ''; }
		};
		const sync = () => {
			const dirty = isDirty() || invalidNums.size > 0;
			bar.classList.toggle('is-dirty', dirty);
			$('[data-savebar-msg]', root).textContent = invalidNums.size
				? __('Fix the highlighted values before saving.', 'llamly')
				: (dirty ? __('You have unsaved changes', 'llamly') : __('No unsaved changes', 'llamly'));
			$('[data-save]', root).disabled = !isDirty() || invalidNums.size > 0;
			$('[data-discard]', root).hidden = !dirty;
			$$('[data-dep-show]', root).forEach((el) => { el.hidden = !draft[el.dataset.depShow]; });
			const dep = $('[data-undo-dep]', root);
			$$('input', dep).forEach((i) => { i.disabled = !draft.journal_enabled; });
			dep.style.opacity = draft.journal_enabled ? '' : '0.5';
		};
		form.addEventListener('input', (ev) => {
			const t = ev.target;
			if (!t.name) return;
			if (t.type === 'checkbox') {
				draft[t.name] = t.checked;
				const l = SWITCH_TEXT[t.name] || SWITCH_ON_OFF;
				const lab = $('.ll-switch-label', t.closest('.ll-switch'));
				if (lab) lab.textContent = t.checked ? l[0] : l[1];
			} else if (t.type === 'number' && NUMS[t.name]) {
				const n = numValid(t.name, t.value);
				if (n === null) invalidNums.add(t.name); else { invalidNums.delete(t.name); draft[t.name] = n; }
				// The message appears on change (blur) to avoid flagging a number while it is being typed; a fix clears it at once.
				if (n !== null) showNumError(t);
			} else if (LISTS.indexOf(t.name) >= 0) {
				draft[t.name] = t.value.split(/\r?\n/).map((v) => v.trim()).filter(Boolean);
			}
			sync();
		});
		form.addEventListener('change', (ev) => {
			const t = ev.target;
			if (t.type === 'number' && NUMS[t.name]) { showNumError(t); sync(); }
		});
		$('[data-discard]', root).addEventListener('click', () => { draft = clone(saved); invalidNums.clear(); refresh(); });
		form.addEventListener('submit', async (ev) => {
			ev.preventDefault();
			if (invalidNums.size) {
				$$('input[type=number]', form).forEach((i) => { if (NUMS[i.name]) showNumError(i); });
				const first = $('input[aria-invalid="true"]', form);
				if (first) first.focus();
				sync();
				return;
			}
			if (!isDirty()) return;
			const changes = {};
			Object.keys(draft).forEach((k) => { if (JSON.stringify(draft[k]) !== JSON.stringify(saved[k])) changes[k] = draft[k]; });
			let confirmRisk = false;
			if (changes.delete_data_on_uninstall === true) {
				if (!(await confirmModal({title: esc(__('Delete data when deleting the plugin?', 'llamly')),
					sub: esc(__('Applies when someone deletes Llamly in Plugins.', 'llamly')), body: '<p class="ll-muted">' +
					esc(__('All Llamly connections, undo history, logs and settings will be deleted. Posts and media aren\'t deleted.',
					'llamly')) + '</p>', okLabel: __('Confirm', 'llamly'), tone: 'll-btn--danger'}))) return;
				confirmRisk = true;
			}
			if (changes.origin_allowlist_enabled === false || changes.oauth_redirect_allowlist_enabled === false) {
				if (!(await confirmModal({title: esc(__('Turn off a protection?', 'llamly')),
					sub: esc(__('This widens who can reach your site.', 'llamly')), body: '<p class="ll-muted">' +
					esc(__('HTTPS, the exact return address and an administrator\'s approval still apply. Turn it off only if you trust the app that will connect.',
					'llamly')) + '</p>', okLabel: __('Turn off anyway', 'llamly'), tone: 'll-btn--danger'}))) return;
				confirmRisk = true;
			}
			if (changes.require_approval === false) {
				// Turning the queue off cancels what is still waiting for it (the server does it in the same save).
				let waiting = null;
				try { const a = await api('activity?page=1&per_page=1'); waiting = ((a && a.approvals) || []).reduce((n, x) => n +
					Number(x.count || 1), 0); } catch (error) { waiting = null; }
				if (waiting === null || waiting > 0) {
					const note = waiting === null
						? __('Requests waiting for approval, if any, will be cancelled when you save, and the agent will have to ask again.', 'llamly')
						: (waiting >= 50
							? __('50+ requests are still waiting for your approval. They will be cancelled when you save, and the agent will have to ask again.', 'llamly')
							: sprintf(_n('%d request is still waiting for your approval. It will be cancelled when you save, and the agent will have to ask again.',
								'%d requests are still waiting for your approval. They will be cancelled when you save, and the agent will have to ask again.',
								waiting, 'llamly'), waiting));
					if (!(await confirmModal({title: esc(__('Turn off your approval?', 'llamly')),
						sub: esc(__('Requests may be waiting for you.', 'llamly')), body: '<p class="ll-muted">' + esc(note) + '</p>',
						okLabel: __('Cancel them and save', 'llamly'), tone: 'll-btn--danger'}))) return;
				}
			}
			const btn = $('[data-save]', root);
			btn.disabled = true;
			try {
				const result = await api('settings', {method: 'PUT', body: {settings: changes,
					revision: Number(settings().revision || 0), confirm_risk: confirmRisk}, errors: SETTINGS_ERRORS});
				if (S.data) {
					S.data.settings = result;
					S.data.server = Object.assign({}, S.data.server, {enabled: !!result.enabled, write_enabled: !!result.write_enabled});
				}
				loadConnections().catch(() => {});
				draft = null;
				toast(__('Settings saved.', 'llamly'));
				refresh();
			} catch (error) {
				fail(error, __('Couldn\'t save.', 'llamly')); // the draft stays on screen
				btn.disabled = false;
			}
		});
		sync();
	}

	/* =====================================================================
	   About (tab id: about)
	   ===================================================================== */
	const PROMPTS = [__('List the drafts that haven\'t been published yet', 'llamly'),
		__('Write the alt text for images without a description', 'llamly'),
		__('Create an About us page based on the site\'s content', 'llamly'), __('Reorder the main menu by importance',
		'llamly'), __('Show what changed on the site today', 'llamly'), __('Change the theme\'s accent color to dark green',
		'llamly')];
	const STEPS = [[_x('Connect an agent', 'getting started step', 'llamly'),
		__('Choose Claude, ChatGPT, Cursor or another and follow the step-by-step.', 'llamly')],
		[__('Ask for something in the conversation', 'llamly'), __('Tell the agent what to do on the site, in plain language.',
		'llamly')], [__('Review and undo', 'llamly'), __('See each change in Activity and roll it back whenever you want.',
		'llamly')]];

	/** Add-ons (src/Free/Modules): active ones with version, API and the status text the add-on reports, refused and inactive ones. */
	function modulesSection() {
		const m = server().modules || {};
		const rows = [];
		(m.active || []).forEach((x) => rows.push([x.label || x.id, x.id, x.version, x.api, (x.status ? pill(x.status,
			'info') + ' ' : '') + pill(_x('Active', 'add-on state', 'llamly'), 'success'), '']));
		(m.refused || []).forEach((x) => rows.push([x.label || x.id || '?', x.id, x.version, x.api, pill(_x('Refused',
			'add-on state', 'llamly'), 'danger'), x.reason || '']));
		(m.inactive || []).forEach((x) => rows.push([x.label || x.id, x.id, x.version, null, pill(_x('Inactive',
			'add-on state', 'llamly')), __('Its past changes stay in Activity; activate it again to undo them.', 'llamly')]));
		if (!rows.length) return '';
		/* translators: %d: module API version. */
		const apiLine = sprintf(__('Add-ons extend Llamly through the module API %d. Their changes use the same history, safe mode and undo.',
			'llamly'), Number(m.api || 1));
		return '<section data-modules><div class="ll-section-head"><div><h2 class="ll-section-title">' + esc(__('Add-ons',
			'llamly')) + '</h2><p class="ll-section-desc">' + esc(apiLine) +
			'</p></div></div><div class="ll-card"><dl class="about-dl">' +
			rows.map((r) => '<dt>' + esc(r[0]) + ' <span class="ll-faint ll-mono">' + esc(r[1] || '') + '</span></dt><dd>' + (r[2] ? esc(r[2]) + ' · ' : '') +
				/* translators: %d: module API version required by the add-on. */
				(r[3] ? esc(sprintf(__('API %d', 'llamly'), Number(r[3]))) + ' · ' : '') + r[4] + (r[5] ? '<div class="ll-faint">' +
					esc(r[5]) + '</div>' : '') + '</dd>').join('') + '</dl></div></section>';
	}

	/** Points to Llamly > Llamly Pro (LLP-462), the Llamly page with the plans. Nothing in Llamly is locked; hidden while Llamly Pro is active. */
	function proSection() {
		const pro = config.pro || {};
		if (pro.active) return '';
		const page = typeof pro.page === 'string' && pro.page.indexOf(config.adminUrl || '\u0000') === 0 ? pro.page : '';
		return '<section id="llamly-pro" data-pro><div class="ll-section-head"><div><h2 class="ll-section-title">' +
			esc(__('Llamly Pro', 'llamly')) + '</h2><p class="ll-section-desc">' +
			esc(__('Everything on these screens is free and stays free.', 'llamly')) + '</p></div></div>' +
			'<div class="ll-card about-pro"><p>' +
			esc(__('Llamly Pro is a separate add-on with extra modules for your agent. The first one, Llamly Builder, builds pages in Elementor and in the block editor.',
			'llamly')) + '</p>' + (page ? '<a class="ll-btn" href="' + esc(page) + '">' +
			esc(__('See Llamly Pro plans', 'llamly')) + '</a>' : '') + '</div></section>';
	}

	tabs.about = {
		label: __('About', 'llamly'),
		render(root) {
			const s = site();
			const links = s.links || {};
			const cats = [];
			tools().forEach((t) => {
				let c = cats.find((x) => x.key === t.category);
				if (!c) { c = {key: t.category, name: catLabel(t.category), n: 0, ro: true}; cats.push(c); }
				c.n++;
				if (t.access === 'write') c.ro = false;
			});
			const total = cats.reduce((n, c) => n + c.n, 0);
			const sys = [['Llamly', (server().plugin_version || '') + ' (Free)'], ['WordPress', s.wordpress || ''], ['PHP',
				s.php || ''], [__('MCP endpoint', 'llamly'), endpoint()], [__('Theme', 'llamly'), s.theme || '']];
			const help = [[links.docs, 'book-open', __('Documentation', 'llamly')], [links.support, 'life-buoy',
				__('Support forum on WordPress.org', 'llamly')], [links.issues, 'alert-circle', __('Report a problem',
				'llamly')]].filter((l) => l[0]);
			/* translators: %d: number of tools. */
			const toolCount = sprintf(_n('%d tool', '%d tools', total, 'llamly'), total);
			/* translators: %d: number of site areas. */
			const areaCount = sprintf(_n('%d area', '%d areas', cats.length, 'llamly'), cats.length);
			/* translators: 1: "N tools", 2: "N areas". */
			const capsLine = sprintf(__('%1$s in %2$s of the site.', 'llamly'), toolCount, areaCount);
			root.innerHTML = '<section class="ll-card about-hero"><img class="about-hero__mark" src="' +
				esc(asset('assets/brand/llamly-mark.svg')) + '" alt="" width="64" height="64">' +
				'<div class="about-hero__text"><h2>' + esc(__('Llamly turns your WordPress into an MCP server', 'llamly')) +
					'</h2><p class="ll-muted">' +
					esc(__('AI agents read and edit the site with the permissions you give them. Almost every change stays in the history and can be undone.',
					'llamly')) + '</p>' +
				'<div class="ll-row about-hero__actions"><button type="button" class="ll-btn ll-btn--primary" data-go="connections">' +
					esc(__('Connect an agent', 'llamly')) + icon('arrow-right') + '</button>' +
				(links.docs ? '<a class="ll-btn" href="' + esc(links.docs) + '" target="_blank" rel="noopener noreferrer">' +
					icon('book-open') + esc(__('Documentation', 'llamly')) + '</a>' : '') + '</div></div></section>' +
				'<section><div class="ll-section-head"><div><h2 class="ll-section-title">' + esc(__('Getting started', 'llamly')) +
					'</h2></div></div><ol class="about-steps">' +
				STEPS.map((st, i) => '<li class="ll-card about-step"><span class="about-step__n">' + (i + 1) + '</span><div><h3>' +
					esc(st[0]) + '</h3><p class="ll-section-desc">' + esc(st[1]) + '</p></div></li>').join('') + '</ol></section>' +
				'<section><div class="ll-section-head"><div><h2 class="ll-section-title">' + esc(__('Try asking', 'llamly')) +
					'</h2><p class="ll-section-desc">' + esc(__('Click to copy and paste it into the conversation with your agent.',
					'llamly')) + '</p></div></div><div class="about-prompts">' +
				PROMPTS.map((p) => '<button type="button" class="about-prompt" data-prompt="' + esc(p) + '"><span>' + esc(p) +
					'</span>' + icon('copy') + '</button>').join('') + '</div></section>' +
				'<section><div class="ll-section-head"><div><h2 class="ll-section-title">' + esc(__('What Llamly can do',
					'llamly')) + '</h2><p class="ll-section-desc">' + esc(capsLine) +
				(server().write_enabled ? '' : ' ' + esc(__('Writing is paused on this site; that\'s why only read tools are shown.', 'llamly'))) + '</p></div></div>' +
				(cats.length ? '<div class="about-caps">' +
					cats.map((c) => '<div class="about-cap"><span class="about-cap__name">' + esc(c.name) + '</span>' + (c.ro ?
					pill(ACCESS_LABEL.read_only, 'info') : '') + '<span class="about-cap__n">' + c.n + '</span></div>').join('') +
					'</div>' : '<p class="ll-muted">' + esc(__('The tool catalog is unavailable right now.', 'llamly')) + '</p>') +
				(s.elementor ? '<p class="ll-faint about-note">' +
					esc(__('Elementor detected. In Free, Elementor pages are outside the agent\'s write reach.', 'llamly')) + '</p>' : '') +
					'</section>' +
				modulesSection() +
				proSection() +
				'<section><div class="ll-section-head"><div><h2 class="ll-section-title">' + esc(__('Help', 'llamly')) + '</h2></div></div><div class="ll-card">' +
				(help.length ? '<div class="about-links">' + help.map((l) => '<a href="' + esc(l[0]) +
					'" target="_blank" rel="noopener noreferrer">' + icon(l[1]) + esc(l[2]) + icon('external-link') + '</a>').join('') +
					'</div>' : '') +
				'<div class="about-sys"><div class="about-sys__head"><h3>' + esc(__('System information', 'llamly')) +
					'</h3><button type="button" class="ll-btn ll-btn--sm" data-sys>' + icon('copy') + '<span>' + esc(__('Copy information',
					'llamly')) + '</span></button></div>' +
				'<dl class="about-dl">' + sys.map((r) => '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>').join('') + '</dl></div></div></section>' ;
			$('[data-go]', root).addEventListener('click', () => navigate({tab: 'connections'}));
			$$('[data-prompt]', root).forEach((b) => b.addEventListener('click',
				async () => { if (await copyText(b.dataset.prompt)) toast(__('Prompt copied.', 'llamly')); }));
			$('[data-sys]', root).addEventListener('click', async () => { if (await copyText(sys.map((r) => r[0] + ': ' +
				r[1]).join('\n'))) toast(__('Information copied.', 'llamly')); });
		},
	};

	/* =====================================================================
	   Activity (tab id: activity, ACT-01..17): history with server-decided state, batches and approval requests.
	   Undo/redo keep the approve → permit → undo/redo flow.
	   ===================================================================== */
	const TOOL_SUMMARY = {
		wp_create_post: __('Created a post', 'llamly'), wp_update_post: __('Edited a post', 'llamly'),
			wp_delete_post: __('Removed a post', 'llamly'), create_post: __('Created a draft', 'llamly'),
			update_post: __('Edited a draft', 'llamly'),
		wp_restore_revision: __('Restored a revision', 'llamly'), wp_create_term: __('Created a term', 'llamly'),
			wp_update_term: __('Edited a term', 'llamly'), wp_delete_term: __('Deleted a term', 'llamly'),
		wp_upload_media: __('Uploaded media', 'llamly'), wp_upload_media_finish: __('Uploaded media', 'llamly'),
			wp_update_media: __('Edited media', 'llamly'), wp_delete_media: __('Deleted media', 'llamly'),
		wp_create_menu: __('Created a menu', 'llamly'), wp_update_menu: __('Edited a menu', 'llamly'),
			wp_delete_menu: __('Deleted a menu', 'llamly'), wp_set_menu_items: __('Reorganized the menu items', 'llamly'),
		wp_create_comment: __('Created a comment', 'llamly'), wp_update_comment: __('Edited a comment', 'llamly'),
			wp_delete_comment: __('Deleted a comment', 'llamly'),
		wp_update_option: __('Changed a site setting', 'llamly'),
		wp_edit_blocks: __('Edited blocks', 'llamly'), wp_create_pattern: __('Created a pattern', 'llamly'),
			wp_update_pattern: __('Edited a pattern', 'llamly'), wp_delete_pattern: __('Deleted a pattern', 'llamly'),
		wp_save_template: __('Saved a template', 'llamly'), wp_reset_template: __('Reset the template to the original',
			'llamly'), wp_update_global_styles: __('Changed the global styles', 'llamly'),
	};
	const OBJECT_TYPE = {post: __('Post', 'llamly'), page: __('Page', 'llamly'), term: __('Term', 'llamly'),
		media: __('Media', 'llamly'), attachment: __('Media', 'llamly'), menu: __('Menu', 'llamly'), comment: __('Comment',
		'llamly'), user: __('User', 'llamly'), option: __('Site setting', 'llamly'), pattern: __('Block pattern', 'llamly'),
		template: __('Template', 'llamly'), global_styles: __('Global styles', 'llamly')};
	const typeLabel = (t) => OBJECT_TYPE[t] || String(t || '').replace(/_/g, ' ');
	const FIELD_LABEL = {post_title: _x('title', 'changed field', 'llamly'), post_content: _x('content', 'changed field',
		'llamly'), post_excerpt: _x('excerpt', 'changed field', 'llamly'), post_status: _x('status', 'changed field', 'llamly'),
		post_name: _x('slug', 'changed field', 'llamly'), post_date: _x('date', 'changed field', 'llamly'),
		post_parent: _x('parent page', 'changed field', 'llamly'), menu_order: _x('order', 'changed field', 'llamly'),
		post_author: _x('author', 'changed field', 'llamly'), post_type: _x('type', 'changed field', 'llamly'), name: _x('name',
		'changed field', 'llamly'), slug: _x('slug', 'changed field', 'llamly'), description: _x('description', 'changed field',
		'llamly'), value: _x('value', 'changed field', 'llamly'), alt: _x('alt text', 'changed field', 'llamly'),
		caption: _x('caption', 'changed field', 'llamly')};
	function summaryOf(tool) {
		if (TOOL_SUMMARY[tool]) return TOOL_SUMMARY[tool];
		if (/^force_undo_/.test(tool)) return __('Force undid', 'llamly');
		if (/^undo_/.test(tool)) return __('Undid a change', 'llamly');
		if (/^redo_/.test(tool)) return __('Redid a change', 'llamly');
		if (/_create_|_upload_/.test(tool)) return __('Created an item', 'llamly');
		if (/_(delete|trash)_/.test(tool)) return __('Removed an item', 'llamly');
		return __('Changed the content', 'llamly');
	}
	function changesText(changes) {
		const c = changes && changes.changes;
		if (!c) return '';
		const parts = [];
		(Array.isArray(c.fields) ? c.fields : []).forEach((f) => parts.push(FIELD_LABEL[f] || String(f)));
		const meta = Array.isArray(c.meta) ? c.meta.length : 0;
		/* translators: %d: number of custom fields. */
		if (meta) parts.push(sprintf(_n('%d custom field', '%d custom fields', meta, 'llamly'), meta));
		if (Array.isArray(c.terms) && c.terms.length) parts.push(__('categories and tags', 'llamly'));
		/* translators: %s: comma-separated list of changed fields. */
		return parts.length ? sprintf(__('Changed: %s', 'llamly'), parts.join(', ')) : '';
	}
	function editUrl(type, id) {
		const base = String(config.adminUrl || '').replace(/\/?$/, '/');
		const n = Number(id);
		if (!config.adminUrl || !(n > 0)) return '';
		if (type === 'post' || type === 'page' || type === 'media' || type === 'pattern' || type === 'template') return base + 'post.php?post=' + n + '&action=edit';
		if (type === 'comment') return base + 'comment.php?action=editcomment&c=' + n;
		if (type === 'user') return base + 'user-edit.php?user_id=' + n;
		if (type === 'menu') return base + 'nav-menus.php?action=edit&menu=' + n;
		return '';
	}
	const RISK = {public: [__('Becomes visible to the public', 'llamly'), 'warning'], sensitive: [__('Sensitive data',
		'llamly'), 'warning'], destructive: [__('Removes or replaces content', 'llamly'), 'danger']};
	// Keys are the stored English texts from the server (audit context); values are their display translation.
	const EFFECTS = {
		'Pings, feeds, sitemaps, caches and webhooks of third parties are not undone.': __('Pings, feeds, sitemaps, caches and webhooks of third parties are not undone.',
			'llamly'),
		'Pages that embed this synced pattern change for visitors.': __('Pages that embed this synced pattern change for visitors.', 'llamly'),
		'The whole site changes for visitors; page caches and CDNs are not purged.': __('The whole site changes for visitors; page caches and CDNs are not purged.',
			'llamly'),
		'Every page rendered with this template changes for visitors; page caches and CDNs are not purged.': __('Every page rendered with this template changes for visitors; page caches and CDNs are not purged.',
			'llamly'),
		'CDN, page and image caches and third-party copies of the file are not undone.': __('CDN, page and image caches and third-party copies of the file are not undone.',
			'llamly'),
		'page caches, search engines and feeds may keep the previous value': __('Page caches, search engines and feeds may keep the previous value.', 'llamly'),
	};
	const AT_FILTERS = [['all', _x('All', 'changes filter', 'llamly')], ['active', _x('Active', 'changes filter',
		'llamly')], ['undone', _x('Undone', 'changes filter', 'llamly')], ['irreversible', _x('Not reversible',
		'changes filter', 'llamly')]];
	// Activity has its own page size (25/50/100), sent to the API as per_page.
	const act = {f: 'all', q: '', c: '', t: '', page: 1, per: 25, data: null, error: null, seq: 0, timer: null, open: new Set(), rows: new Map()};

	const filtered = () => act.f !== 'all' || !!act.q || !!act.c || !!act.t;
	function updateTabCount() {
		const tab = $('.ll-tab[data-tab="activity"]', mount);
		if (!tab) return;
		const n = tabs.activity.count();
		let badge = $('.ll-tab__count', tab);
		if (!n) { if (badge) badge.remove(); return; }
		if (!badge) { badge = document.createElement('span'); badge.className = 'll-tab__count'; tab.appendChild(badge); }
		badge.textContent = n;
	}

	/* ---------- data ---------- */
	async function fetchActivity(root) {
		const seq = ++act.seq;
		act.error = null;
		const q = new URLSearchParams({page: String(act.page), per_page: String(act.per)});
		if (act.f !== 'all') q.set('state', act.f);
		if (act.c) q.set('credential', act.c);
		if (act.t) q.set('object_type', act.t);
		if (act.q) q.set('q', act.q);
		try {
			const data = await api('activity?' + q.toString());
			if (seq !== act.seq) return;
			act.data = data;
			act.page = Number(data.page || 1);
			if (S.data && !filtered() && act.page === 1) S.data.activity = data;
		} catch (error) {
			if (seq !== act.seq) return;
			act.error = error.message || __('Try again.', 'llamly');
		}
		if (root.isConnected) paintActivity(root);
	}

	/* ---------- history rows ---------- */
	function connCell(cred) {
		const name = credentialName(cred);
		const client = credentialClient(cred);
		const ic = client ? clientIcon(client) : icon(/^admin:/.test(cred) ? 'user' : /^ability:/.test(cred) ? 'layers' : 'key-round');
		return '<span class="at-conn ll-muted">' + (name ? ic + '<span class="at-conn__name">' + esc(name) + '</span>' :
			'<span class="ll-faint">—</span>') + '</span>';
	}
	function statePill(r) {
		if (r.state === 'undone') return pill(_x('Undone', 'change state', 'llamly'));
		if (r.state === 'review') return pill(__('Conflict', 'llamly'), 'warning');
		if (r.state === 'irreversible') return '<span title="' + esc(r.reason || '') + '">' + pill(_x('Not reversible', 'change state', 'llamly')) + '</span>';
		if (r.state === 'recovery') return pill(__('Recovery needed', 'llamly'), 'danger');
		if (r.state === 'applying') return pill(__('In progress', 'llamly'), 'warning');
		if (r.state === 'module_inactive') return pill(__('Add-on inactive', 'llamly'), 'warning');
		return '';
	}
	const actBtn = (act2, id, ic, label, ghost) => '<button type="button" class="ll-btn ll-btn--sm' + (ghost ?
		' ll-btn--ghost' : '') + '" data-act="' + act2 + '" data-id="' + esc(id) + '">' + (ic ? icon(ic) : '') + '<span>' +
		esc(label) + '</span></button>';
	function itemAction(r, ghost) {
		if (r.state === 'recovery') return actBtn('resolve-recovery', r.id, 'alert-triangle', __('Review and release', 'llamly'), ghost);
		if (r.can_undo) return actBtn('undo', r.id, 'undo-2', __('Undo', 'llamly'), ghost);
		if (r.can_redo) return actBtn('redo', r.id, 'redo-2', __('Redo', 'llamly'), ghost);
		if (r.state === 'review') return actBtn('review', r.id, '', __('Review', 'llamly'), ghost);
		return '';
	}
	// Labels the server stores in English (they are data); shown in the admin language.
	const STORED_LABEL = {'Site option': __('Site option', 'llamly'), '(unnamed term)': __('(unnamed term)', 'llamly'),
		'A desktop or terminal app': __('A desktop or terminal app', 'llamly')};
	const ptLabel = (v) => STORED_LABEL[v] || v;
	/** Rows of an add-on carry module {id, label}: its name replaces the raw object type ("builder elementor"). */
	function objectLine(type, id, title, module) {
		const url = editUrl(type, id);
		const addOn = module && module.label ? String(module.label) : '';
		const kind = addOn ? (OBJECT_TYPE[type] ? addOn + ' · ' + typeLabel(type) : addOn) : typeLabel(type);
		const t = ptLabel(title) || (addOn && !OBJECT_TYPE[type] ? addOn : typeLabel(type));
		return esc(kind) + ' · ' + (url ? '<a href="' + esc(url) + '">' + esc(t) + '</a>' : esc(t)) + (Number(id) > 0 ?
			' <span class="ll-faint">#' + Number(id) + '</span>' : '');
	}
	/** "Undoes “X” from today, 14:00" style lines, with the date part only when known. */
	function relationLine(kind, rel) {
		const what = summaryOf(rel.of_tool || '');
		const when = rel.of_created_at ? dayTime(rel.of_created_at, true) : '';
		if (kind === 'redo') {
			/* translators: 1: summary of the original change, 2: when it happened ("today, 14:00"). */
			return when ? sprintf(__('Redoes “%1$s” from %2$s', 'llamly'), what, when) : sprintf(__('Redoes “%s”', 'llamly'), what);
		}
		if (kind === 'undo') {
			/* translators: 1: summary of the original change, 2: when it happened ("today, 14:00"). */
			return when ? sprintf(__('Undoes “%1$s” from %2$s', 'llamly'), what, when) : sprintf(__('Undoes “%s”', 'llamly'), what);
		}
		/* translators: 1: summary of the original change, 2: when it happened ("today, 14:00"). */
		return (when ? sprintf(__('Reverted “%1$s” from %2$s over newer edits.', 'llamly'), what, when) :
			sprintf(__('Reverted “%s” over newer edits.', 'llamly'), what)) +
			' ' + __('Undoing this change brings back what was replaced.', 'llamly');
	}
	function itemRow(r) {
		const d = parseUtc(r.created_at);
		const inverse = r.state === 'inverse';
		const rel = r.relation;
		const summary = inverse ? (rel && rel.action === 'redo' ? __('Redid a change', 'llamly') : __('Undid a change', 'llamly')) : summaryOf(r.tool);
		const lines = [];
		if (inverse && rel) lines.push(esc(relationLine(rel.action === 'redo' ? 'redo' : 'undo', rel)));
		if (rel && rel.action === 'force') lines.push(esc(relationLine('force', rel)));
		if (!inverse && !(rel && rel.action === 'force') && changesText(r.changes)) lines.push(esc(changesText(r.changes)));
		/* translators: 1: relative time ("5 minutes ago"), 2: who undid it. */
		if (r.undone && r.state !== 'inverse') lines.push(esc(sprintf(__('Undone %1$s by %2$s', 'llamly'),
			relative(r.undone.at), credentialName(r.undone.credential) || __('someone', 'llamly'))));
		/* translators: 1: relative time ("5 minutes ago"), 2: who redid it. */
		if (r.redone) lines.push(esc(sprintf(__('Redone %1$s by %2$s', 'llamly'), relative(r.redone.at),
			credentialName(r.redone.credential) || __('someone', 'llamly'))));
		if (r.reason) lines.push(esc(r.reason));
		if (r.notice) lines.push(esc(r.notice));
		return '<div class="at-row' + (r.state === 'undone' || inverse ? ' is-undone' : '') + '" data-id="' + esc(r.id) + '">' +
			'<span class="at-time ll-mono ll-faint">' + esc(d ? hm(d) : '') + '</span>' + connCell(r.credential) +
			'<div class="at-main"><div class="at-summary">' + esc(summary) + '</div>' +
			'<div class="at-object ll-muted">' + objectLine(r.object_type, r.object_id, r.title, r.module) + '</div>' +
			'<div class="at-tool ll-mono ll-faint">' + esc(r.tool) + '</div>' +
			lines.map((l) => '<div class="at-reason ll-faint">' + l + '</div>').join('') + '</div>' +
			'<div class="at-status">' + statePill(r) + '</div><div class="at-action">' + itemAction(r) + '</div></div>';
	}
	function batchRow(b) {
		const d = parseUtc(b.created_at);
		const open = act.open.has(b.batch_id);
		// Undo batch acts on the whole batch, also on changes the current filters leave out.
		const total = Math.max(Number(b.count), Number(b.total_count || 0));
		const types = (b.object_types || []).map(typeLabel).join(', ');
		const undone = Number((b.states || {}).undone || 0);
		const count = Number(b.count);
		const members = (b.members || []).map((m) => '<li class="at-batch-item"><span>' + objectLine(m.object_type, m.object_id, m.title, m.module) + '</span>' +
			'<span class="ll-mono ll-faint">' + esc(m.tool) + '</span>' + statePill(m) + itemAction(m, true) +
			(m.notice || m.reason ? '<span class="ll-faint at-batch-note">' + esc(m.notice || m.reason) + '</span>' : '') + '</li>').join('');
		const action = b.can_undo ? '<button type="button" class="ll-btn ll-btn--sm" data-act="undo-batch" data-batch="' +
			esc(b.batch_id) + '">' + icon('undo-2') + '<span>' + esc(__('Undo batch', 'llamly')) + '</span></button>' : '';
		return '<div class="at-row' + (b.state === 'undone' ? ' is-undone' : '') + '" data-id="' + esc(b.id) + '">' +
			'<span class="at-time ll-mono ll-faint">' + esc(d ? hm(d) : '') + '</span>' + connCell(b.credential) +
			/* translators: %d: number of changes in the batch. */
			'<div class="at-main"><div class="at-summary">' + esc(sprintf(_n('Batch of %d change', 'Batch of %d changes', total, 'llamly'), total)) + '</div>' +
			'<div class="at-object ll-muted">' + esc(types) + '</div><div class="at-tool ll-mono ll-faint">' + esc((b.tools || []).join(', ')) + '</div>' +
			/* translators: 1: changes of the batch shown with the current filters, 2: total changes in the batch. */
			(total > count ? '<div class="at-reason at-batch-outside">' +
				esc(sprintf(_n('%1$d of %2$d change shown. The others are outside the current filters.',
				'%1$d of %2$d changes shown. The others are outside the current filters.', total, 'llamly'), count, total)) + '</div>' :
				'') +
			/* translators: 1: number of changes already undone, 2: total changes in the batch. */
			(undone && undone < b.count ? '<div class="at-reason ll-faint">' + esc(sprintf(_n('%1$d of %2$d already undone',
				'%1$d of %2$d already undone', undone, 'llamly'), undone, count)) + '</div>' : '') +
			/* translators: %d: number of items in the batch. */
			'<button type="button" class="at-batch-toggle" data-batch-toggle="' + esc(b.batch_id) + '" aria-expanded="' + open +
				'">' + icon('chevron-right') + '<span>' + esc(sprintf(_n('%d item', '%d items', count, 'llamly'), count)) +
				'</span></button>' +
			'<ul class="at-batch-list"' + (open ? '' : ' hidden') + '>' + members + '</ul></div>' +
			'<div class="at-status">' + statePill(b) + '</div><div class="at-action">' + action + '</div></div>';
	}

	function atResults() {
		if (act.error) return emptyBox('alert-circle', __('The history couldn\'t be loaded', 'llamly'), act.error,
			'<button type="button" class="ll-btn" data-retry>' + esc(__('Try again', 'llamly')) + '</button>');
		const d = act.data;
		if (!d) return loadingBox(__('Loading the history…', 'llamly'));
		const items = d.items || [];
		act.rows = new Map();
		items.forEach((r) => { act.rows.set(r.id, r); (r.members || []).forEach((m) => act.rows.set(m.id, m)); });
		if (!items.length) return emptyBox('search', __('Nothing found', 'llamly'), __('No change matches these filters.',
			'llamly'), '<button type="button" class="ll-btn" data-clear>' + esc(__('Clear filters', 'llamly')) + '</button>');
		let html = d.writes_ready ? '' : '<div class="ll-callout ll-callout--warning at-callout">' + icon('lock') +
			'<div class="ll-callout__body"><span>' +
			esc(__('Writing through MCP is paused on this site. Undo and redo are unavailable until it comes back.', 'llamly')) +
			'</span></div></div>';
		let day = null;
		items.forEach((r) => {
			const label = dayLabel(r.created_at);
			if (label !== day) { day = label; html += '<h3 class="at-day">' + esc(day) + '</h3>'; }
			html += r.kind === 'batch' ? batchRow(r) : itemRow(r);
		});
		const total = Number(d.total || 0);
		if (total > PAGE_SIZES[0] || act.per !== PAGE_SIZES[0] || act.page > 1) {
			html += pager(act.page, act.per, Number(d.pages || 1), items.length, total);
		}
		return html;
	}
	function atChips() {
		const counts = (act.data && act.data.counts) || {};
		return AT_FILTERS.map((f) => '<button type="button" class="ll-chip" data-f="' + f[0] + '" aria-pressed="' +
			(act.f === f[0]) + '">' + esc(f[1]) + ' <span class="ll-chip__n">' + Number(counts[f[0]] || 0) +
			'</span></button>').join('');
	}
	function atConnOptions() {
		const creds = (act.data && act.data.credentials) || [];
		const seen = new Set();
		const opts = creds.filter((c) => !/^(test|qa):/.test(c)).map((c) => {
			const row = connItems().find((x) => x.id === c);
			/* translators: %s: connection name. */
			const label = row && row.revoked_at ? sprintf(__('%s (revoked)', 'llamly'), credentialName(c)) : credentialName(c);
			if (seen.has(label + c)) return '';
			seen.add(label + c);
			return '<option value="' + esc(c) + '"' + (act.c === c ? ' selected' : '') + '>' + esc(label) + '</option>';
		}).join('');
		return '<option value="">' + esc(__('All connections', 'llamly')) + '</option>' + opts;
	}
	function atTypeOptions() {
		const types = (act.data && act.data.object_types) || [];
		return '<option value="">' + esc(__('All types', 'llamly')) + '</option>' + types.map((t) => '<option value="' +
			esc(t) + '"' + (act.t === t ? ' selected' : '') + '>' + esc(typeLabel(t)) + '</option>').join('');
	}
	function historyCard() {
		const d = act.data;
		const head = '<div class="ll-card__head"><div><h2 class="ll-card__title">' + esc(__('Change history', 'llamly')) + '</h2></div></div>';
		if (d && !act.error && !filtered() && !Number((d.counts || {}).all || 0)) {
			return '<section class="ll-card" id="at-history" data-empty="1">' + head +
				'<div class="ll-empty"><div class="ll-empty__art"><img src="' + esc(asset('assets/brand/llamly-mark.svg')) +
				'" alt=""></div><h3 class="ll-empty__title">' + esc(__('No changes yet', 'llamly')) + '</h3>' +
				'<p class="ll-empty__text">' + esc(d.writes_ready ?
					__('When an agent creates or edits something on the site, it shows up here with the option to undo.', 'llamly') :
					__('Writing through MCP is paused on this site. Reading is still available.', 'llamly')) + '</p>' +
				'<button type="button" class="ll-btn ll-btn--primary" data-go-connections>' + esc(__('Connect an agent', 'llamly')) + '</button></div></section>';
		}
		return '<section class="ll-card" id="at-history">' + head +
			'<div class="at-toolbar"><div class="ll-search at-search">' + icon('search') +
				'<input class="ll-input" type="search" name="q" maxlength="80" placeholder="' + esc(__('Search by change or item',
				'llamly')) + '" aria-label="' + esc(__('Search the history', 'llamly')) + '" value="' + esc(act.q) + '"></div>' +
			'<select class="ll-select at-conn-select" name="c" aria-label="' + esc(__('Filter by connection', 'llamly')) + '">' + atConnOptions() + '</select>' +
			'<select class="ll-select at-type-select" name="t" aria-label="' + esc(__('Filter by item type', 'llamly')) + '">' + atTypeOptions() + '</select>' +
			'<div class="ll-chips at-chips" data-chips>' + atChips() + '</div></div>' +
			'<div class="at-results" id="at-results">' + atResults() + '</div></section>';
	}

	/* ---------- requests waiting for approval ---------- */
	// The server marks an item that goes away with after_kind; the text is never matched, so a value an agent sends cannot pass for it.
	const AFTER_KIND = {removed: __('(removed)', 'llamly'), trashed: __('(moved to the trash)', 'llamly'), deleted: __('(deleted)', 'llamly')};
	const afterKindText = (kind) => (Object.prototype.hasOwnProperty.call(AFTER_KIND, kind) ? AFTER_KIND[kind] : null);
	function previewField(field) {
		const m = /^(fields|meta|terms)\.(.+)$/.exec(String(field));
		if (!m) return field === 'item' ? _x('item', 'changed field', 'llamly') : String(field);
		return m[1] === 'fields' ? (FIELD_LABEL[m[2]] || m[2]) : m[2];
	}
	/** Line diff (longest common subsequence) of two texts, each cut to 80 lines: [[' ' | '-' | '+', line], ...]. */
	function lineDiff(before, after) {
		const A = String(before).split('\n').slice(0, 80), B = String(after).split('\n').slice(0, 80);
		const m = A.length, n = B.length;
		const dp = Array.from({length: m + 1}, () => new Array(n + 1).fill(0));
		for (let i = m - 1; i >= 0; i--) for (let j = n - 1; j >= 0; j--) dp[i][j] = A[i] === B[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
		const out = [];
		let i = 0, j = 0;
		while (i < m && j < n) {
			if (A[i] === B[j]) { out.push([' ', A[i]]); i++; j++; }
			else if (dp[i + 1][j] >= dp[i][j + 1]) out.push(['-', A[i++]]);
			else out.push(['+', B[j++]]);
		}
		while (i < m) out.push(['-', A[i++]]);
		while (j < n) out.push(['+', B[j++]]);
		return out;
	}
	const DIFF_MAX_LINES = 12;
	/** "field: before → after" for short values, a line diff for long or multi-line ones. Everything goes through esc(). */
	function previewHtml(preview) {
		const list = preview && Array.isArray(preview.changes) ? preview.changes : [];
		if (!list.length) return '';
		const val = (v) => { const t = String(v == null ? '' : v); return t === '' ? '<em class="ll-faint">' + esc(__('(empty)', 'llamly')) + '</em>' : esc(t); };
		const rows = list.map((c) => {
			const before = String(c.before == null ? '' : c.before), after = String(c.after == null ? '' : c.after);
			const name = '<span class="at-diff__field">' + esc(previewField(c.field)) + '</span>';
			const kind = afterKindText(c.after_kind);
			if (kind !== null) return '<div class="at-diff__row">' + name + ': <del class="at-diff__old">' + val(before) +
				'</del> <span aria-hidden="true">→</span> <ins class="at-diff__new">' + esc(kind) + '</ins></div>';
			const tooLong = before.split('\n').length > 80 || after.split('\n').length > 80;
			const incomplete = !!c.truncated || tooLong;
			const note = (text) => '<div class="at-diff__more ll-faint">' + esc(text) + '</div>';
			if (!incomplete && before !== after && before.indexOf('\n') === -1 && after.indexOf('\n') === -1 && before.length <= 80 && after.length <= 80) {
				return '<div class="at-diff__row">' + name + ': <del class="at-diff__old">' + val(before) +
					'</del> <span aria-hidden="true">→</span> <ins class="at-diff__new">' + val(after) + '</ins></div>';
			}
			const lines = lineDiff(before, after).filter((l) => l[0] !== ' ');
			const shown = lines.slice(0, DIFF_MAX_LINES).map((l) => '<div class="at-diff__line ' + (l[0] === '-' ? 'is-old' :
				'is-new') + '"><span class="at-diff__sign" aria-hidden="true">' + (l[0] === '-' ? '−' : '+') +
				'</span><span class="at-diff__text">' + esc(l[1].length > 160 ? l[1].slice(0, 160) + '…' : l[1]) +
				'</span></div>').join('');
			/* translators: %d: number of changed lines that are not shown. */
			const more = lines.length > DIFF_MAX_LINES ? note(sprintf(_n('…and %d more changed line',
				'…and %d more changed lines', lines.length - DIFF_MAX_LINES, 'llamly'), lines.length - DIFF_MAX_LINES)) : '';
			// The shown excerpt may be all that differs, or none of it: say so instead of showing a diff that looks complete.
			const beyond = incomplete && !lines.length ? note(__('Changed beyond the shown excerpt.', 'llamly')) : '';
			const warn = incomplete ? note(__('Incomplete diff: the value is longer than what is shown.', 'llamly')) : '';
			return '<div class="at-diff__block"><div class="at-diff__head">' + name + ':</div>' + shown + more + beyond + warn + '</div>';
		}).join('');
		return '<div class="at-diff">' + rows + (preview.truncated ? '<div class="at-diff__more ll-faint">' +
			esc(__('Only the first changes are shown.', 'llamly')) + '</div>' : '') + '</div>';
	}
	const requestIds = (a) => (a && Array.isArray(a.ids) && a.ids.length ? a.ids : [a && a.id]).filter(Boolean);
	function approvalsCard() {
		const list = (act.data && act.data.approvals) || [];
		if (!list.length) return '';
		const rows = list.map((a) => {
			const d = parseUtc(a.created_at);
			const risk = a.risk;
			const pills = risk ? Object.keys(RISK).filter((k) => risk[k]).map((k) => pill(RISK[k][0], RISK[k][1])).join(' ') : pill(__('Risk not given', 'llamly'));
			const effects = risk && risk.external_effects && risk.external_effects.length ?
				risk.external_effects.map((e) => '<div class="at-reason ll-faint">' + esc(EFFECTS[e] || e) + '</div>').join('') : '';
			const approved = a.approved_until ? parseUtc(a.approved_until) : null;
			const actions = approved
				? '<span class="at-req-ok">' + pill(__('Approved', 'llamly'), 'success') + '</span>'
				: '<button type="button" class="ll-btn ll-btn--sm ll-btn--ghost" data-act="req-reject" data-id="' + esc(a.id) +
					'">' + esc(__('Reject', 'llamly')) +
					'</button><button type="button" class="ll-btn ll-btn--sm ll-btn--primary" data-act="req-approve" data-id="' +
					esc(a.id) + '">' + icon('check') + '<span>' + esc(__('Approve', 'llamly')) + '</span></button>';
			const diff = a.preview && a.preview.changes && a.preview.changes.length ? previewHtml(a.preview) :
				(changesText(a.changes) ? '<div class="at-reason ll-faint">' + esc(changesText(a.changes)) + '</div>' : '');
			const count = Number(a.count || 1);
			/* translators: %d: number of identical requests the agent sent. */
			const dup = count > 1 ? '<div class="at-reason ll-faint">' +
				esc(sprintf(__('The agent sent this request %d times. Your answer applies to all of them.', 'llamly'), count)) +
				'</div>' : '';
			/* translators: %s: when the request leaves the list ("tomorrow, 14:00"). */
			const expires = !approved && a.expires_at ? '<div class="at-reason ll-faint">' + esc(sprintf(__('Expires %s',
				'llamly'), dayTime(a.expires_at, true))) + '</div>' : '';
			return '<div class="at-row at-req" data-id="' + esc(a.id) + '"><span class="at-time ll-mono ll-faint">' + esc(d ?
				(sameDay(d, new Date()) ? hm(d) : dayTime(a.created_at)) : '') + '</span>' + connCell(a.credential) +
				'<div class="at-main"><div class="at-summary">' + esc(summaryOf(a.tool)) +
					'</div><div class="at-object ll-muted">' + objectLine(a.object_type, a.object_id, a.title) + '</div>' +
				'<div class="at-tool ll-mono ll-faint">' + esc(a.tool) + '</div>' + diff + dup +
				'<div class="at-req-risk">' + pills + '</div>' + effects + expires +
				/* translators: %s: time until which the approval is valid (HH:MM). */
				(approved ? '<div class="at-reason ll-faint">' +
					esc(sprintf(__('Approved until %s. Waiting for the agent to repeat the request.', 'llamly'), hm(approved))) + '</div>' :
					'') + '</div>' +
				'<div class="at-action at-req-actions">' + actions + '</div></div>';
		}).join('');
		return '<section class="ll-card at-req-card" id="at-requests"><div class="ll-card__head"><div><h2 class="ll-card__title">' +
			esc(__('Requests waiting for approval', 'llamly')) + ' <span class="ll-faint at-req-count">' + list.length +
			'</span></h2>' +
			'<p class="ll-card__desc">' + esc(__('The agent has to repeat the request after approval.', 'llamly')) + '</p></div></div>' + rows + '</section>';
	}

	/* ---------- painting ---------- */
	function paintTop(root) {
		const top = $('#at-top', root);
		if (top) top.innerHTML = approvalsCard();
	}
	function paintActivity(root) {
		paintTop(root);
		const card = $('#at-history', root);
		const wantEmpty = !!(act.data && !act.error && !filtered() && !Number((act.data.counts || {}).all || 0));
		if (!card || wantEmpty !== card.hasAttribute('data-empty') || !$('#at-results', card)) {
			if (card) card.outerHTML = historyCard();
		} else {
			$('#at-results', card).innerHTML = atResults();
			$('[data-chips]', card).innerHTML = atChips();
			const c = $('select[name=c]', card); if (c) c.innerHTML = atConnOptions();
			const t = $('select[name=t]', card); if (t) t.innerHTML = atTypeOptions();
		}
		updateTabCount();
	}

	/* ---------- actions ---------- */
	async function runInverse(id, action) {
		const approval = await api('activity/' + encodeURIComponent(id) + '/approve/' + action, {method: 'POST', body: {}});
		await api('activity/' + encodeURIComponent(id) + '/' + action, {method: 'POST', body: (approval &&
			approval.required) ? {permit: String(approval.permit || '')} : {}});
	}
	const whatLine = (r, title) => esc(summaryOf(r.tool)) + ' · ' + esc(typeLabel(r.object_type)) + ' ' + esc(title || '');
	function inverseModal(root, id, action) {
		const r = act.rows.get(id);
		const what = r ? whatLine(r, r.title) : '';
		const asNew = __('It\'s recorded as a new change.', 'llamly');
		modal({
			title: esc(action === 'undo' ? __('Undo this change?', 'llamly') : __('Redo this change?', 'llamly')), sub: what || esc(asNew),
			body: '<p class="ll-muted">' + esc(asNew) + ' ' +
				esc(__('Llamly checks that the item is still as the agent left it before changing it. If someone edited it since, nothing is changed.',
				'llamly')) + '</p>',
			actions: cancelBtn() + '<button type="button" class="ll-btn ' + (action === 'undo' ? 'll-btn--danger' :
				'll-btn--primary') + '" data-ok>' + esc(action === 'undo' ? __('Undo', 'llamly') : __('Redo', 'llamly')) + '</button>',
			onMount: (m) => {
				const ok = $('[data-ok]', m);
				ok.addEventListener('click', busyButton(ok, async () => {
					await runInverse(id, action);
					closeModal();
					await fetchActivity(root);
					if (action === 'undo') {
						toast(__('Change undone', 'llamly'), {icon: 'undo-2', actionLabel: __('Redo', 'llamly'), onAction: async () => {
							try { await runInverse(id, 'redo'); toast(__('Change redone', 'llamly'), {icon: 'redo-2'}); } catch (error) { fail(error); }
							if (root.isConnected) fetchActivity(root);
						}});
					} else toast(__('Change redone', 'llamly'), {icon: 'redo-2'});
				}));
			},
		});
	}
	function batchModal(root, batchId) {
		const b = act.rows.get('batch:' + batchId);
		const n = b ? Math.max(Number(b.count), Number(b.total_count || 0)) : 0;
		const outside = b ? Number(b.outside_pending || 0) : 0;
		const outsideList = outside ? (b.outside || []).map((m) => '<li>' + objectLine(m.object_type, m.object_id, m.title,
			m.module) + ' <span class="ll-mono ll-faint">' + esc(m.tool) + '</span></li>').join('') +
			/* translators: %d: number of other changes not listed. */
			(outside > (b.outside || []).length ? '<li class="ll-faint">' + esc(sprintf(_n('and %d more', 'and %d more',
				outside - (b.outside || []).length, 'llamly'), outside - (b.outside || []).length)) + '</li>' : '') : '';
		modal({
			/* translators: %d: number of changes in the batch. */
			title: esc(__('Undo this batch?', 'llamly')), sub: n ? esc(sprintf(_n('%d change made together by the agent.',
				'%d changes made together by the agent.', n, 'llamly'), n)) : '',
			body: (outside ? '<div class="ll-callout ll-callout--warning" data-batch-outside>' + icon('alert-triangle') +
				'<div class="ll-callout__body"><span class="ll-callout__title">' +
				/* translators: %d: number of changes of the batch outside the current filters that are still to undo. */
				esc(sprintf(_n('%d change of this batch is outside the current filters',
					'%d changes of this batch are outside the current filters', outside, 'llamly'), outside)) + '</span>' +
				'<span>' + esc(_n('Undo batch undoes it too:', 'Undo batch undoes them too:', outside, 'llamly')) +
					'</span><ul class="at-batch-outside-list">' + outsideList + '</ul></div></div>' : '') +
				'<p class="ll-muted">' +
					esc(__('Llamly undoes from the newest change to the oldest. If one of them changed since, it stops there and shows what\'s left. Each undo is recorded as a new change.',
					'llamly')) + '</p>',
			actions: cancelBtn() + '<button type="button" class="ll-btn ll-btn--danger" data-ok>' + esc(__('Undo batch', 'llamly')) + '</button>',
			onMount: (m) => {
				const ok = $('[data-ok]', m);
				ok.addEventListener('click', busyButton(ok, async () => {
					const res = await api('activity/batch/' + encodeURIComponent(batchId) + '/undo', {method: 'POST', body: {confirmed: true}});
					closeModal();
					await fetchActivity(root);
					if (res && res.remaining && res.remaining.length) {
						const why = ERRORS[res.error] || __('The change couldn\'t be undone.', 'llamly');
						const left = res.remaining.length;
						modal({
							/* translators: 1: changes undone, 2: total changes in the batch. */
							title: esc(__('Batch partly undone', 'llamly')), sub: esc(sprintf(_n('%1$d of %2$d change undone.',
								'%1$d of %2$d changes undone.', Number(res.total), 'llamly'), Number(res.undone_count), Number(res.total))),
							/* translators: %s: title of the change where the undo stopped. */
							body: '<div class="ll-callout ll-callout--warning">' + icon('alert-triangle') +
								'<div class="ll-callout__body"><span class="ll-callout__title">' + esc(sprintf(__('Stopped at %s', 'llamly'),
								res.stopped_title || __('a change', 'llamly'))) + '</span><span>' + esc(why) + '</span></div></div>' +
								/* translators: %d: number of changes left in the batch. */
								'<p class="ll-muted">' + esc(sprintf(_n('%d change of this batch is left.',
									'%d changes of this batch are left.', left, 'llamly'), left)) + ' ' +
									esc(__('Review the item and try again. The ones already undone aren\'t repeated.', 'llamly')) + '</p>',
							actions: '<button type="button" class="ll-btn ll-btn--primary" data-modal-close>' + esc(__('Got it', 'llamly')) + '</button>',
						});
					} else {
						/* translators: %d: number of items undone. */
						toast(sprintf(_n('Batch undone (%d item)', 'Batch undone (%d items)', Number(res.undone_count), 'llamly'), Number(res.undone_count)), {icon: 'undo-2'});
					}
				}));
			},
		});
	}
	function forceModal(root, r) {
		modal({
			title: esc(__('Undo anyway?', 'llamly')), sub: whatLine(r, ptLabel(r.title)),
			body: '<p class="ll-muted">' + esc(__('The item goes back to how it was before this agent change. Edits made after it are replaced.', 'llamly')) + '</p>' +
				'<p class="ll-muted">' +
					esc(__('Nothing is lost: the force undo stays in the history as a new change, and undoing it brings back the replaced state.',
					'llamly')) + '</p>',
			actions: cancelBtn() + '<button type="button" class="ll-btn ll-btn--danger" data-ok>' + esc(__('Undo anyway', 'llamly')) + '</button>',
			onMount: (m) => {
				const ok = $('[data-ok]', m);
				ok.addEventListener('click', busyButton(ok, async () => {
					const approval = await api('activity/' + encodeURIComponent(r.id) + '/approve-force', {method: 'POST', body: {}});
					await api('activity/' + encodeURIComponent(r.id) + '/force-undo', {method: 'POST', body: {permit: String((approval && approval.permit) || '')}});
					closeModal();
					await fetchActivity(root);
					toast(__('Change force undone. You can undo it from the history.', 'llamly'), {icon: 'undo-2'});
				}));
			},
		});
	}
	function reviewModal(root, id) {
		const r = act.rows.get(id);
		if (!r) return;
		const url = editUrl(r.object_type, r.object_id);
		const canForce = r.can_force && r.tool !== 'wp_edit_blocks';
		modal({
			title: esc(__('Review conflict', 'llamly')), sub: whatLine(r, r.title),
			body: '<div class="ll-callout ll-callout--warning">' + icon('alert-triangle') +
				'<div class="ll-callout__body"><span>' + esc(r.notice || __('The item changed after this change.', 'llamly')) +
				'</span></div></div>' +
				(canForce
					? '<p class="ll-muted">' +
						esc(__('Undo anyway takes the item back to how it was before the agent change and overwrites the edit made after it. The undo stays in the history and can be undone.',
						'llamly')) + '</p>'
					: '<p class="ll-muted">' + esc(__('To undo, the item has to be as the agent left it. Check the item in WordPress.', 'llamly')) + '</p>'),
			actions: (url ? '<a class="ll-btn ll-btn--ghost" href="' + esc(url) + '">' + icon('external-link') + '<span>' +
				esc(__('Open in WordPress', 'llamly')) + '</span></a>' : '') +
				'<button type="button" class="ll-btn" data-modal-close>' + esc(__('Keep as is', 'llamly')) + '</button>' +
				(canForce ? '<button type="button" class="ll-btn ll-btn--danger-ghost" data-force>' + esc(__('Undo anyway', 'llamly')) + '</button>' : ''),
			onMount: (m) => { const f = $('[data-force]', m); if (f) f.addEventListener('click', () => forceModal(root, r)); },
		});
	}
	function resolveModal(root, id) {
		modal({
			title: esc(__('Accept the item\'s current state?', 'llamly')), sub: esc(__('A change was interrupted.', 'llamly')),
			body: '<p class="ll-muted">' +
				esc(__('The item matches neither the previous state nor the request. Open the item in WordPress and check the saved content.',
				'llamly')) + '</p><p class="ll-muted">' +
				esc(__('When you accept, Llamly doesn\'t change the content: it closes this operation, allows new changes and invalidates editors open with the old version.',
				'llamly')) + '</p>',
			actions: cancelBtn() + '<button type="button" class="ll-btn ll-btn--danger" data-ok>' + esc(__('Accept current state', 'llamly')) + '</button>',
			onMount: (mm) => {
				const ok = $('[data-ok]', mm);
				ok.addEventListener('click', busyButton(ok, async () => {
					await api('activity/' + encodeURIComponent(id) + '/resolve', {method: 'POST', body: {}});
					closeModal(); await fetchActivity(root); toast(__('The recovery was recorded and the item was released.', 'llamly'));
				}));
			},
		});
	}
	async function decideRequest(root, id, decision) {
		const a = ((act.data && act.data.approvals) || []).find((x) => x.id === id);
		const who = a ? esc(credentialName(a.credential) || __('The agent', 'llamly')) + ' · <span class="ll-mono">' + esc(a.tool) + '</span>' : '';
		const diff = a ? previewHtml(a.preview) : '';
		const ok = await confirmModal(decision === 'approve'
			? {title: esc(__('Approve this request?', 'llamly')), sub: who, body: diff + '<p class="ll-muted">' +
				esc(__('The approval is only for this request and for a limited time. The agent has to repeat the request after approval. The change goes into the history and can be undone.',
				'llamly')) + '</p>', okLabel: __('Approve', 'llamly')}
			: {title: esc(__('Reject this request?', 'llamly')), sub: who, body: diff + '<p class="ll-muted">' +
				esc(__('Nothing is changed. If the agent repeats the same request, it gets the rejection.', 'llamly')) + '</p>',
				okLabel: __('Reject', 'llamly'), tone: 'll-btn--danger'});
		if (!ok) return;
		try {
			// Identical requests are one card: the answer goes to every one of them.
			const ids = requestIds(a || {id});
			let done = 0, lastError = null;
			for (const rid of ids) {
				try { await api('activity/' + encodeURIComponent(rid) + '/request/' + decision, {method: 'POST', body: {}}); done++; } catch (error) { lastError = error; }
			}
			if (!lastError) toast(decision === 'approve' ? __('Request approved. The agent has to repeat the request.',
				'llamly') : __('Request rejected.', 'llamly'), {icon: decision === 'approve' ? 'check-circle-2' : 'x'});
			else if (ids.length > 1 && done > 0) {
				/* translators: 1: requests answered, 2: requests that failed, 3: reason. */
				toast(sprintf(__('%1$d requests were answered and %2$d failed: %3$s', 'llamly'), done, ids.length - done,
					lastError.message || __('Try again.', 'llamly')), {tone: 'error'});
			} else fail(lastError);
		} catch (error) { fail(error); }
		await fetchActivity(root);
	}


	tabs.activity = {
		label: __('Activity', 'llamly'),
		count() {
			const a = S.data && S.data.activity;
			const n = a ? Number((a.counts || {}).active || 0) + Number((a.approvals || []).length) : 0;
			return n ? String(n) : '';
		},
		render(root) {
			if (!act.data && S.data && S.data.activity && !filtered() && act.per === Number(S.data.activity.per_page || 0)) act.data = S.data.activity;
			root.innerHTML = '<p class="ll-lede">' +
				esc(__('Every change made by an agent is recorded with the previous state. You can undo each change separately.',
				'llamly')) + '</p>' +
				'<div id="at-top">' + approvalsCard() + '</div>' + historyCard();
			fetchActivity(root);
			const reload = () => { act.page = 1; fetchActivity(root); };
			root.addEventListener('input', (ev) => {
				if (ev.target.name !== 'q') return;
				act.q = ev.target.value.trim().slice(0, 80);
				clearTimeout(act.timer);
				act.timer = setTimeout(reload, 300);
			});
			root.addEventListener('change', (ev) => {
				if (ev.target.name === 'c') { act.c = ev.target.value; reload(); }
				else if (ev.target.name === 't') { act.t = ev.target.value; reload(); }
				else if (ev.target.name === 'per') { act.per = PAGE_SIZES.includes(Number(ev.target.value)) ? Number(ev.target.value) : PAGE_SIZES[0]; reload(); }
			});
			root.addEventListener('click', (ev) => {
				const t = (sel) => ev.target.closest(sel);
				let b;
				if ((b = t('[data-f]'))) { act.f = b.dataset.f; $$('[data-f]', root).forEach((x) => x.setAttribute('aria-pressed',
					String(x.dataset.f === act.f))); reload(); }
				else if ((b = t('[data-page]'))) { act.page = Math.max(1,
					Number(b.dataset.page)); fetchActivity(root).then(() => { const c = $('#at-history',
					root); if (c) c.scrollIntoView({block: 'start'}); }); }
				else if (t('[data-clear]')) { act.f = 'all'; act.q = ''; act.c = ''; act.t = ''; act.page = 1; const c = $('#at-history',
					root); if (c) c.outerHTML = historyCard(); fetchActivity(root); }
				else if (t('[data-retry]')) fetchActivity(root);
				else if ((b = t('[data-batch-toggle]'))) {
					const id = b.dataset.batchToggle, list = b.nextElementSibling, open = list.hidden;
					list.hidden = !open; b.setAttribute('aria-expanded', String(open));
					if (open) act.open.add(id); else act.open.delete(id);
				}
				else if (t('[data-go-connections]')) navigate({tab: 'connections'});
				else if ((b = t('[data-act]'))) {
					const id = b.dataset.id;
					const a = b.dataset.act;
					if (a === 'undo' || a === 'redo') inverseModal(root, id, a);
					else if (a === 'undo-batch') batchModal(root, b.dataset.batch);
					else if (a === 'review') reviewModal(root, id);
					else if (a === 'resolve-recovery') resolveModal(root, id);
					else if (a === 'req-approve') decideRequest(root, id, 'approve');
					else if (a === 'req-reject') decideRequest(root, id, 'reject');
				}
			});
		},
	};

	/* ---------- module tabs (config.sections, HTML already sanitized on the server) ---------- */
	moduleSections.forEach((section) => {
		tabs['module-' + section.id] = {
			label: String(section.label || section.id),
			render(root) { root.innerHTML = '<section class="ll-card ll-module-section" data-module="' + esc(section.id) + '">' +
				String(section.html || '') + '</section>'; },
		};
	});

	/* ---------- start ---------- */
	async function boot() {
		// Compatibility: old #tab links become &tab=tab.
		const hashRaw = location.hash.replace(/^#/, '');
		const hash = Object.prototype.hasOwnProperty.call(LEGACY_TABS, hashRaw) ? LEGACY_TABS[hashRaw] : hashRaw;
		if (hash && !params().get('tab') && (TAB_ORDER.indexOf(hash) >= 0 || /^module-/.test(hash))) {
			const p = params(); p.set('tab', hash);
			history.replaceState({}, '', location.pathname + '?' + p.toString());
		}
		// The app replaces the no-JS rendering of extension sections.
		$$('.ll-extension-section').forEach((el) => { el.hidden = true; });
		mount.setAttribute('aria-busy', 'true');
		try {
			await Promise.all([loadState(), loadConnections()]);
			render();
		} catch (error) {
			renderFatal(error.message || __('Try again in a moment.', 'llamly'));
		} finally {
			mount.removeAttribute('aria-busy');
		}
	}
	window.addEventListener('hashchange', () => {
		const tab = location.hash.replace(/^#/, '');
		if (tabs[tab]) { history.replaceState({}, '', location.pathname + location.search); navigate({tab: tab}); }
	});
	boot();
})();
