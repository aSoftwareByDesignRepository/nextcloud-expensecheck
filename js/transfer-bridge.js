/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.ExpenseCheckApi / ExpenseCheckMessaging contract the
 * synced js/transfer.js expects, in ExpenseCheck's own envelope
 * ({success:true,data} / {success:false,error,fields}). App-specific — not
 * synced.
 */
(function () {
	'use strict';
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (j && j.context) || {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	window.ExpenseCheckApi = {
		url(path) {
			return OC.generateUrl('/apps/expensecheck' + path);
		},
		async get(path, params) {
			let u = this.url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	// Messaging: the synced transfer.js asks for .alert/.polite — route both
	// through the app-owned toast channel (EXPToasts) and mirror into the
	// exp-transfer-result-live status region the synced part renders.
	const t = (s) => (window.OC && OC.L10N && OC.L10N.translate)
		? OC.L10N.translate('expensecheck', s) : s;
	function announce(text, kind) {
		try {
			const toasts = window.EXPToasts;
			if (toasts && kind === 'error' && typeof toasts.error === 'function') {
				toasts.error(String(text));
				return;
			}
			if (toasts && typeof toasts.success === 'function') {
				toasts.success(String(text));
				return;
			}
		} catch (e) { /* fall through to the platform fallback */ }
		if (window.OC && OC.Notification && OC.Notification.showTemporary) {
			OC.Notification.showTemporary(text);
		}
	}
	function live(text) {
		const el = document.getElementById('exp-transfer-result-live');
		if (el) el.textContent = text;
	}
	const msg = window.ExpenseCheckMessaging || {};
	if (typeof msg.alert !== 'function') {
		msg.alert = function (text) { announce(text, 'error'); live(text); };
	}
	if (typeof msg.polite !== 'function') {
		msg.polite = function (text) { announce(text, 'success'); live(text); };
	}
	window.ExpenseCheckMessaging = msg;
})();
