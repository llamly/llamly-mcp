(function () {
	'use strict';
	const config = window.LLAMLY_FREE_EDITOR;
	if (!config || (!config.isBlockEditor && (!config.postId || !config.token))) return;
	const __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : (text) => text;

	const staleCodes = new Set([
		'llamly_stale_editor',
		'llamly_edit_token_required',
		'llamly_operation_in_progress'
	]);

	function preserveAndOfferActions() {
		const title = document.getElementById('title');
		const content = document.getElementById('content');
		const copy = [title && title.value ? title.value : '', content && content.value ? content.value : '']
			.filter(Boolean).join('\n\n');
		let banner = document.getElementById('llamly-stale-editor');
		if (!banner) {
			banner = document.createElement('div');
			banner.id = 'llamly-stale-editor';
			banner.setAttribute('role', 'alert');
			banner.style.cssText = 'position:relative;padding:14px 18px;margin:12px 0;background:#fff4e5;border-left:4px solid #b45309;color:#33220d;z-index:10';
			const text = document.createElement('p');
			text.textContent = __('This page changed while the editor was open. Your changes are still here; copy the text before reloading.', 'llamly');
			banner.appendChild(text);
			const copyButton = document.createElement('button');
			copyButton.type = 'button';
			copyButton.className = 'button button-secondary';
			copyButton.textContent = __('Copy my changes', 'llamly');
			copyButton.addEventListener('click', function () {
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(copy).then(() => { copyButton.textContent = __('Copied', 'llamly'); });
				}
			});
			banner.appendChild(copyButton);
			const reload = document.createElement('a');
			reload.href = window.location.href;
			reload.className = 'button button-primary';
			reload.style.marginLeft = '8px';
			reload.textContent = __('Reload editor', 'llamly');
			banner.appendChild(reload);
			const target = document.querySelector('#post-body-content') || document.querySelector('#wpbody-content .wrap');
			if (target) target.insertBefore(banner, target.firstChild);
		}
	}

	// Block editors (post, CPT, wp_navigation, site editor): inject the edit token into writes of guarded types.
	// The token for an item is the FIRST one seen for it (server-localized for the opened item, else the one carried
	// by its first context=edit read) and is never replaced, so a re-fetch after an agent change cannot unlock a save.
	const routes = Array.isArray(config.routes) ? config.routes : [];
	const tokens = Object.create(null);
	if (config.initial && typeof config.initial === 'object') {
		Object.keys(config.initial).forEach(function (key) { if (config.initial[key]) tokens[key] = String(config.initial[key]); });
	}

	function restPath(options) {
		let path = String(options.path || options.url || '');
		const rest = path.match(/[?&]rest_route=([^&]+)/);
		if (rest) path = decodeURIComponent(rest[1]);
		else if (path.indexOf('/wp-json/') !== -1) path = path.slice(path.indexOf('/wp-json/') + 8);
		path = path.split('?')[0].split('#')[0];
		return path.replace(/^\/+/, '');
	}

	// Returns {key, type} for a path that targets a single item of a guarded type, or null.
	function match(path) {
		let id = path.replace(/(^|[^/])\/autosaves$/, '$1');
		for (let i = 0; i < routes.length; i++) {
			const prefix = routes[i].prefix + '/';
			if (id.indexOf(prefix) === 0 && id.length > prefix.length) {
				let rest = id.slice(prefix.length);
				try { rest = decodeURIComponent(rest); } catch (e) { /* keep raw */ }
				if (rest.indexOf('/') !== -1 && rest.indexOf('//') === -1) continue;
				return { key: routes[i].prefix + '/' + rest, type: routes[i].type, id: rest };
			}
		}
		return null;
	}

	function remember(path, data) {
		const hit = match(path);
		if (hit && data && typeof data === 'object' && typeof data.llamly_edit_token === 'string' && !tokens[hit.key]) {
			tokens[hit.key] = data.llamly_edit_token;
		}
	}

	function titleAndContent(hit) {
		let title = '';
		let content = '';
		try {
			const core = window.wp.data.select('core');
			const record = core.getEditedEntityRecord('postType', hit.type, /^[0-9]+$/.test(hit.id) ? Number(hit.id) : hit.id);
			const raw = function (value) { return value && typeof value === 'object' ? (value.raw || '') : (typeof value === 'string' ? value : ''); };
			title = raw(record.title);
			content = typeof record.content === 'function' ? '' : raw(record.content);
			if (!content && record.blocks && window.wp.blocks && window.wp.blocks.serialize) content = window.wp.blocks.serialize(record.blocks);
		} catch (e) { /* best effort */ }
		return [title, content].filter(Boolean).join('\n\n');
	}

	function notifyStale(hit) {
		if (!(window.wp && wp.data && wp.data.dispatch)) return;
		const editor = wp.data.dispatch('core/editor');
		if (editor && editor.lockPostSaving) editor.lockPostSaving('llamly-stale-editor');
		if (editor && editor.lockPostAutosaving) editor.lockPostAutosaving('llamly-stale-editor');
		const notices = wp.data.dispatch('core/notices');
		if (notices && notices.createNotice) {
			notices.createNotice('error', __('This page changed while the editor was open. Your unsaved content was kept; copy it before reloading.', 'llamly'), {
				id: 'llamly-stale-editor',
				isDismissible: false,
				actions: [{
					label: __('Copy my changes', 'llamly'),
					onClick: function () {
						const text = hit ? titleAndContent(hit) : '';
						if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text);
					}
				}, {
					label: __('Reload editor', 'llamly'),
					url: window.location.href
				}]
			});
		}
	}

	function withToken(headers, hit) {
		const token = tokens[hit.key];
		const out = Object.assign({}, headers || {});
		if (token) out['X-Llamly-Edit-Token'] = token;
		return out;
	}

	if (window.wp && wp.apiFetch && config.isBlockEditor) {
		wp.apiFetch.use(function (options, next) {
			const method = String(options.method || 'GET').toUpperCase();
			const path = restPath(options);

			if (method === 'GET' || method === 'HEAD') {
				const hit = match(path);
				if (!hit || !/[?&]context=edit(&|$)/.test(String(options.path || options.url || ''))) return next(options);
				return next(options).then(function (data) { remember(path, data); return data; });
			}

			if (path === 'batch/v1' && options.data && Array.isArray(options.data.requests)) {
				let touched = false;
				const requests = options.data.requests.map(function (request) {
					const hit = request && ['POST', 'PUT', 'PATCH'].includes(String(request.method || 'POST').toUpperCase()) ? match(restPath({ path: request.path })) : null;
					if (!hit) return request;
					touched = true;
					return Object.assign({}, request, { headers: withToken(request.headers, hit) });
				});
				if (!touched) return next(options);
				return next(Object.assign({}, options, { data: Object.assign({}, options.data, { requests }) })).then(function (result) {
					const responses = result && Array.isArray(result.responses) ? result.responses : [];
					responses.forEach(function (response, index) {
						if (response && response.body && staleCodes.has(response.body.code)) {
							notifyStale(match(restPath({ path: options.data.requests[index].path })));
						}
					});
					return result;
				});
			}

			const hit = ['POST', 'PUT', 'PATCH'].includes(method) ? match(path) : null;
			if (!hit) return next(options);
			return next(Object.assign({}, options, { headers: withToken(options.headers, hit) })).catch(function (error) {
				if (error && staleCodes.has(error.code)) notifyStale(hit);
				throw error;
			});
		});
	}

	if (!config.isBlockEditor) {
		if (window.jQuery) {
			window.jQuery(document).on('heartbeat-send', function (event, data) {
				if (data) data.llamly_edit_token = config.token;
			});
			window.jQuery(document).on('heartbeat-tick', function (event, data) {
				if (data && data.wp_autosave && data.wp_autosave.llamly_stale) preserveAndOfferActions();
			});
		}
	}
}());
