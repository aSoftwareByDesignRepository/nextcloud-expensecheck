(function () {
	'use strict';

	var DURATIONS = { success: 4000, warn: 5000, error: 7000 };

	function live(id, text) {
		var el = document.getElementById(id);
		if (el) {
			el.textContent = text;
		}
	}

	function ensureContainer() {
		var c = document.getElementById('exp-toasts');
		if (!c) {
			c = document.createElement('div');
			c.id = 'exp-toasts';
			c.className = 'exp-toasts';
			document.body.appendChild(c);
		}
		return c;
	}

	/**
	 * Dedup on kind+text with timer reset: an identical toast already showing
	 * gets its dismiss timer reset instead of stacking a duplicate (rapid
	 * retries, repeated API failures).
	 */
	function show(kind, regionId, message) {
		live(regionId, message);
		var container = ensureContainer();
		var dedupKey = kind + '|' + String(message);
		var existing = typeof container.querySelectorAll === 'function'
			? container.querySelectorAll('.exp-toast[data-exp-toast-key]')
			: Array.prototype.slice.call(container.childNodes || []);
		for (var i = 0; i < existing.length; i++) {
			var prev = existing[i];
			var prevKey = typeof prev.getAttribute === 'function'
				? prev.getAttribute('data-exp-toast-key')
				: prev._expToastKey;
			if (prevKey === dedupKey || prev._expToastKey === dedupKey) {
				if (prev._expDismissTimer) {
					window.clearTimeout(prev._expDismissTimer);
				}
				prev._expDismissTimer = window.setTimeout(function () {
					if (prev.parentNode) {
						prev.parentNode.removeChild(prev);
					}
				}, DURATIONS[kind]);
				return;
			}
		}
		var toast = document.createElement('div');
		toast.className = 'exp-toast exp-toast--' + kind;
		toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		toast.setAttribute('data-exp-toast-key', dedupKey);
		toast._expToastKey = dedupKey;
		var text = document.createElement('span');
		text.className = 'exp-toast__text';
		text.textContent = String(message);
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'exp-toast__close';
		close.setAttribute('aria-label', t('expensecheck', 'Dismiss'));
		close.textContent = '✕';
		close.addEventListener('click', function () {
			if (toast._expDismissTimer) {
				window.clearTimeout(toast._expDismissTimer);
			}
			toast.remove();
		});
		toast.appendChild(text);
		if (kind === 'error') {
			// Family contract (_shared/app-feedback): error toasts offer a
			// "Report this problem" mailto next to the message.
			try {
				var feedback = window.SbdAppFeedback;
				if (feedback && typeof feedback.buildMailto === 'function') {
					var link = document.createElement('a');
					link.className = 'exp-toast__feedback';
					link.href = feedback.buildMailto('problem', {});
					link.textContent = t('expensecheck', 'Report this problem');
					toast.appendChild(link);
				}
			} catch (e) { /* never break the toast */ }
		}
		toast.appendChild(close);
		container.appendChild(toast);
		toast._expDismissTimer = window.setTimeout(function () {
			if (toast.parentNode) {
				toast.parentNode.removeChild(toast);
			}
		}, DURATIONS[kind]);
	}

	window.EXPToasts = {
		success: function (message) {
			show('success', 'exp-live-region', message);
		},
		warn: function (message) {
			show('warn', 'exp-live-region', message);
		},
		error: function (message) {
			show('error', 'exp-alert-region', message);
		},
		// Back-compat aliases (contractcheck-era call sites).
		showSuccess: function (message) {
			show('success', 'exp-live-region', message);
		},
		showError: function (message) {
			show('error', 'exp-alert-region', message);
		}
	};
})();
