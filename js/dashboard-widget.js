(function () {
	'use strict';
	var E = window.EXP;
	if (!E) return;
	var root = document.querySelector('.widget-expensecheck');
	if (!root) return;
	E.api('/inbox').then(function (d) {
		var n = ((d && d.claims) || []).length;
		root.innerHTML = '<span class="exp-badge exp-badge--info">' + n + '</span> ' + E.esc(E.t('awaiting your decision'));
	});
})();
