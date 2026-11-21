/**
 * Inline glossary helper — reads `data-exp-glossary` on `#app-content`.
 * Ported from deskcheck/mobilitycheck (§9.3 dotted-underline terms).
 */
(function () {
	'use strict';

	function loadFromDom() {
		const root = document.getElementById('app-content');
		if (!root) return {};
		const raw = root.getAttribute('data-exp-glossary');
		if (!raw) return {};
		try { return JSON.parse(raw) || {}; } catch (_) { return {}; }
	}

	const dict = loadFromDom();

	function humanizeKey(key) {
		return String(key || '')
			.replace(/_/g, ' ')
			.replace(/\b\w/g, (c) => c.toUpperCase());
	}

	function define(key) {
		const item = dict[key];
		if (!item) return '';
		if (typeof item === 'string') return item;
		return item.definition || item.def || '';
	}

	function label(key) {
		const item = dict[key];
		if (!item) return humanizeKey(key);
		if (typeof item === 'string') return humanizeKey(key);
		return item.term || humanizeKey(key);
	}

	function escAttr(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	function tooltip(key) {
		const item = dict[key];
		if (!item) return '';
		const def = define(key);
		if (!def) return '';
		const term = label(key);
		const accessible = 'Glossary: ' + term + ' — ' + def;
		return ' <button type="button" class="exp-glossary-term" data-exp-glossary-key="'
			+ escAttr(key) + '" aria-label="' + escAttr(accessible) + '" title="'
			+ escAttr(def) + '">?</button>';
	}

	function attach() {
		document.addEventListener('click', (ev) => {
			const btn = ev.target.closest('[data-exp-glossary-key]');
			if (!btn) return;
			const key = btn.getAttribute('data-exp-glossary-key');
			const T = window.EXPToasts;
			const msg = label(key) + ' — ' + define(key);
			if (T && typeof T.warn === 'function') {
				T.warn(msg);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', attach);
	} else {
		attach();
	}

	const glossary = { define, label, tooltip, all: () => dict };
	window.EXP = window.EXP || {};
	window.EXP.glossary = glossary;
	window.EXPGlossary = glossary;
})();
