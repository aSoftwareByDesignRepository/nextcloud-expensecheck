/* ExpenseCheck page views — each data-exp-view section gets a renderer.
 * All data flows through the JSON API (same contract as the companion). */
(function () {
	'use strict';
	var E = window.EXP;
	if (!E) return;
	var t = E.t, el = E.el, api = E.api, esc = E.esc, fmtDate = E.fmtDate, fmtDateTime = E.fmtDateTime;

	function money(minor, cur) {
		if (minor === null || minor === undefined) return '—';
		var n = Number(minor) / 100;
		try { return n.toLocaleString(undefined, { style: 'currency', currency: cur || 'EUR' }); }
		catch (e) { return n.toFixed(2) + ' ' + (cur || ''); }
	}
	var STATUS_VARIANT = {
		draft: 'neutral', submitted: 'info', changes_requested: 'warn',
		approved: 'success', rejected: 'danger', settled: 'success', cancelled: 'neutral'
	};
	function claimRow(c) {
		var card = el('article', { class: 'exp-card', role: 'listitem' });
		card.appendChild(el('div', { class: 'exp-card__head' }, [
			el('strong', { text: c.claim_no || c.claimNo || ('#' + c.id) }),
			E.badge(STATUS_VARIANT[c.status] || 'neutral', c.status || '')
		]));
		card.appendChild(el('p', { class: 'exp-card__title', text: c.title || '' }));
		var meta = [];
		if (c.reimbursable_total_minor !== undefined) meta.push(money(c.reimbursable_total_minor, c.currency));
		if (c.service_date) meta.push(fmtDate(c.service_date));
		if (c.owner_uid && c.owner_uid !== (E.boot && E.boot.uid)) meta.push(c.owner_uid);
		if (meta.length) card.appendChild(el('p', { class: 'exp-card__meta', text: meta.join(' · ') }));
		var open = el('a', { class: 'exp-btn exp-btn--secondary exp-card__open', href: '#' });
		open.textContent = t('Open');
		open.addEventListener('click', function (ev) {
			ev.preventDefault();
			location.href = E.appBase() + '/claims/' + (c.claim && c.claim.id ? c.claim.id : c.id);
		});
		card.appendChild(open);
		return card;
	}
	function renderList(host, emptyEl, items) {
		host.innerHTML = '';
		items.forEach(function (c) { host.appendChild(claimRow(c)); });
		if (emptyEl) emptyEl.hidden = items.length > 0;
	}

		/* Six-state surfaces (M1.6): every async list renders Loading →
	 * Populated | Empty | Error(with retry) via the shared
	 * CheckViewComponents.listFetch — aria-busy marks the fetch, a failed
	 * load shows an inline error panel + retry, never a blank page. */
	var EXP_LIST_CLASSES = {
		loading: 'exp-loading',
		errorBox: 'exp-empty-state exp-empty-state--error',
		errorTitle: 'exp-empty-state__title',
		errorText: 'exp-empty-state__text',
		retryBtn: 'exp-btn exp-btn--secondary'
	};
	function listFetch(host, emptyEl, fetcher, render, countEl) {
		return CheckViewComponents.listFetch(host, {
			fetch: fetcher,
			render: function (data) {
				var n = render(data);
				if (countEl && typeof n === 'number') {
					countEl.textContent = t('{n} results', { n: n });
					countEl.hidden = false;
				}
			},
			emptyEl: emptyEl,
			onError: E.handleError,
			classes: EXP_LIST_CLASSES,
			strings: {
				loading: t('Loading…'),
				errorTitle: t('Could not load this list.'),
				errorText: t('Check your connection and try again.'),
				retry: t('Retry')
			}
		});
	}

	var views = {};
	views.claims = function (root) {
		var list = document.getElementById('exp-claims-list');
		var empty = document.getElementById('exp-claims-empty');
		var bar = root.querySelector('[data-exp-filter="status"]');
		var status = '';
		var group = window.CheckViewComponents && window.CheckViewComponents.pillGroup(bar, function (v) {
			status = v;
			load();
		});
		function load() {
			var qs = status ? '?status=' + encodeURIComponent(status) : '';
			return listFetch(list, empty, function () { return api('/claims' + qs); },
				function (data) { var items = (data && data.claims) || []; renderList(list, empty, items); return items.length; },
				document.getElementById('exp-claims-count'))();
		}
		// pillGroup's initial activation already fired the first load via
		// onSelect; without the pack (missing sync) the bar stays inert —
		// fall back to an unfiltered load so the list still renders.
		if (!group || !bar) { load(); }
	};

	views['claim-form'] = function () {
		var form = document.getElementById('exp-claim-form');
		var linesHost = document.getElementById('exp-lines');
		var addBtn = document.getElementById('exp-add-line');
		var seq = 0;
		// One lookup fetch for the whole form — per-line/per-row fetches
		// turned every added line into an N+1 waterfall.
		var lookupsCache = {};
		var lookupsP = api('/lookups').then(function (d) { lookupsCache = d || {}; return lookupsCache; });
		var lineRefs = {};
		function lineFields() {
			var row = el('fieldset', { class: 'exp-line' });
			var i = seq++;
			row.appendChild(el('legend', { text: t('Line') + ' ' + (i + 1) }));
			var grid = el('div', { class: 'exp-line__grid' });
			function fld(name, label, type, attrs) {
				var wrap = el('div', { class: 'exp-field' });
				var id = 'exp-line-' + i + '-' + name;
				wrap.appendChild(el('label', { class: 'exp-label', 'for': id, text: label }));
				var input = el('input', Object.assign({ class: 'exp-input', id: id, name: 'lines[' + i + '][' + name + ']', type: type }, attrs || {}));
				wrap.appendChild(input);
				grid.appendChild(wrap);
				return input;
			}
			var typeWrap = el('div', { class: 'exp-field' });
			var typeId = 'exp-line-' + i + '-type';
			typeWrap.appendChild(el('label', { class: 'exp-label', 'for': typeId, text: t('Type') }));
			var typeSel = el('select', { class: 'exp-select', id: typeId, name: 'lines[' + i + '][line_type]' });
			[['receipt', t('Receipt')], ['mileage', t('Mileage')], ['per_diem', t('Per diem')], ['flat', t('Flat rate')]].forEach(function (o) {
				typeSel.appendChild(el('option', { value: o[0], text: o[1] }));
			});
			typeWrap.appendChild(typeSel);
			grid.appendChild(typeWrap);
			fld('description', t('Description'), 'text', { required: 'required', maxlength: '191' });
			var occ = fld('occurred_on', t('Date'), 'date', { required: 'required', value: E.todayYmd() });
			var amt = fld('amount', t('Amount'), 'text', { inputmode: 'decimal', 'aria-describedby': 'exp-line-' + i + '-amt-h' });
			var qty = fld('qty', t('Quantity'), 'text', { inputmode: 'decimal' });
			var rateWrap = el('div', { class: 'exp-field' });
			var rateId = 'exp-line-' + i + '-rate';
			rateWrap.appendChild(el('label', { class: 'exp-label', 'for': rateId, text: t('Rate') }));
			var rate = el('select', { class: 'exp-select', id: rateId, name: 'lines[' + i + '][rate_code]' });
			rateWrap.appendChild(rate);
			grid.appendChild(rateWrap);
			// receipt uses amount; rated types use qty + rate_code — same gate
			// server-side, mirrored here so the user gets a clear signal.
			function refillRates() {
				var prev = rate.value;
				var date = occ.value || '';
				var opts = (lookupsCache.rates || []).filter(function (rt) {
					return rt.rate_type === typeSel.value
						&& (!rt.effective_from || !date || rt.effective_from <= date);
				});
				rate.innerHTML = '';
				rate.appendChild(el('option', { value: '', text: t('Choose…') }));
				opts.forEach(function (rt) {
					var label = rt.name + (rt.rate_minor ? ' · ' + money(rt.rate_minor, rt.currency || cur()) : '');
					rate.appendChild(el('option', { value: rt.code, text: label }));
				});
				rate.value = prev;
				if (rate.value !== prev) rate.value = '';
				if (rate.options.length === 2) rate.selectedIndex = 1;
			}
			function syncTypeFields() {
				var isReceipt = typeSel.value === 'receipt';
				amt.disabled = !isReceipt;
				amt.required = isReceipt;
				qty.disabled = isReceipt;
				qty.required = !isReceipt;
				rate.disabled = isReceipt;
				rate.required = !isReceipt;
				fxWrap.hidden = !isReceipt;
				refillRates();
				if (typeof refillCats === 'function') refillCats();
			}
			occ.addEventListener('change', refillRates);
			typeSel.addEventListener('change', syncTypeFields);
			var catWrap = el('div', { class: 'exp-field' });
			var catId = 'exp-line-' + i + '-category';
			catWrap.appendChild(el('label', { class: 'exp-label', 'for': catId, text: t('Category') }));
			var sel = el('select', { class: 'exp-select', id: catId, name: 'lines[' + i + '][category_id]', required: 'required' });
			sel.appendChild(el('option', { value: '', text: t('Choose…') }));
			catWrap.appendChild(sel);
			grid.appendChild(catWrap);
			// Category-gated fields (server contract): attendees when the
			// category demands it, justification above its threshold,
			// original currency when the receipt was paid in foreign money.
			var attWrap = el('div', { class: 'exp-field', hidden: 'hidden' });
			var attId = 'exp-line-' + i + '-attendees';
			attWrap.appendChild(el('label', { class: 'exp-label', 'for': attId, text: t('Attendees') }));
			var attIn = el('input', { class: 'exp-input', id: attId, name: 'lines[' + i + '][attendees]', maxlength: '2000' });
			attWrap.appendChild(attIn);
			attWrap.appendChild(el('p', { class: 'exp-hint', text: t('Names of everyone present — legally required for entertainment expenses.') }));
			var occWrap = el('div', { class: 'exp-field', hidden: 'hidden' });
			var occId = 'exp-line-' + i + '-occasion';
			occWrap.appendChild(el('label', { class: 'exp-label', 'for': occId, text: t('Occasion') }));
			var occIn = el('input', { class: 'exp-input', id: occId, name: 'lines[' + i + '][occasion]', maxlength: '2000' });
			occWrap.appendChild(occIn);
			occWrap.appendChild(el('p', { class: 'exp-hint', text: t('The business reason — legally required for entertainment expenses.') }));
			var justWrap = el('div', { class: 'exp-field', hidden: 'hidden' });
			var justId = 'exp-line-' + i + '-justification';
			justWrap.appendChild(el('label', { class: 'exp-label', 'for': justId, text: t('Justification') }));
			var justIn = el('textarea', { class: 'exp-input', id: justId, name: 'lines[' + i + '][justification]', maxlength: '2000', rows: '2' });
			justWrap.appendChild(justIn);
			var justHint = el('p', { class: 'exp-hint' });
			justWrap.appendChild(justHint);
			var fxWrap = el('div', { class: 'exp-field', hidden: 'hidden' });
			var fxAmtId = 'exp-line-' + i + '-fxamt';
			fxWrap.appendChild(el('label', { class: 'exp-label', 'for': fxAmtId, text: t('Foreign receipt? Original amount') }));
			var fxAmt = el('input', { class: 'exp-input', id: fxAmtId, name: 'lines[' + i + '][foreign_amount]', inputmode: 'decimal' });
			var fxCur = el('input', { class: 'exp-input', id: 'exp-line-' + i + '-fxcur', name: 'lines[' + i + '][foreign_currency]', maxlength: '3', placeholder: 'USD', style: 'text-transform:uppercase', 'aria-label': t('Currency') });
			var fxRate = el('input', { class: 'exp-input', id: 'exp-line-' + i + '-fxrate', name: 'lines[' + i + '][foreign_fx]', inputmode: 'decimal', placeholder: t('FX rate'), 'aria-label': t('Exchange rate') });
			fxAmt.addEventListener('input', function () {
				var on = fxAmt.value.trim() !== '';
				fxCur.required = on;
				fxRate.required = on;
			});
			fxWrap.appendChild(fxAmt);
			fxWrap.appendChild(el('p', { class: 'exp-hint', text: t('Paid in another currency — original amount, currency code and exchange rate are recorded for the audit.') }));
			var fxRow = el('div', { class: 'exp-line__grid' });
			fxRow.appendChild(fxCur);
			fxRow.appendChild(fxRate);
			fxWrap.appendChild(fxRow);
			var cats = [];
			function curCat() {
				return cats.filter(function (x) { return String(x.id) === sel.value; })[0] || null;
			}
			function amtMinor() {
				return Math.round(parseFloat((amt.value || '0').replace(',', '.')) * 100) || 0;
			}
			function syncCat() {
				var c = curCat();
				var needAtt = !!(c && c.requires_attendees);
				attWrap.hidden = !needAtt;
				attIn.required = needAtt;
				occWrap.hidden = !needAtt;
				occIn.required = needAtt;
				var th = c && c.justification_threshold_minor;
				var needJust = !!(c && th !== null && amtMinor() >= th);
				justWrap.hidden = !needJust;
				justIn.required = needJust;
				if (needJust) {
					var n = Math.max(1, c.justification_min_length || 1);
					justIn.minLength = n;
					justHint.textContent = t('Required above {amount} — at least {n} characters.', { amount: money(th, cur()), n: n });
				}
			}
			function refillCats() {
				var cur = sel.value;
				sel.innerHTML = '';
				sel.appendChild(el('option', { value: '', text: t('Choose…') }));
				cats.filter(function (c) {
					return (c.allow_lines || '').indexOf(typeSel.value) !== -1;
				}).forEach(function (c) {
					sel.appendChild(el('option', { value: String(c.id), text: c.name }));
				});
				sel.value = cur;
				if (sel.value !== cur) sel.value = '';
				syncCat();
			}
			sel.addEventListener('change', syncCat);
			amt.addEventListener('input', syncCat);
			function cur() {
				var f = document.querySelector('[name="currency"]');
				return ((f && f.value) || 'EUR').toUpperCase();
			}
			var reimbWrap = el('label', { class: 'exp-check' });
			var cb = el('input', { type: 'checkbox', name: 'lines[' + i + '][reimbursable]', checked: 'checked' });
			reimbWrap.appendChild(cb);
			reimbWrap.appendChild(document.createTextNode(' ' + t('Reimbursable to me')));
			grid.appendChild(attWrap);
			grid.appendChild(occWrap);
			grid.appendChild(justWrap);
			grid.appendChild(fxWrap);
			row.appendChild(grid);
			row.appendChild(reimbWrap);
			// Allocations — required server-side; percent shares must sum to 100.
			var allocHost = el('div', { class: 'exp-alloc' });
			var allocSeq = 0;
			function allocRow() {
				var a = el('div', { class: 'exp-alloc__row' });
				var ccId = 'exp-line-' + i + '-cc-' + allocSeq;
				a.appendChild(el('label', { class: 'exp-sr-only', 'for': ccId, text: t('Cost center') }));
				var ccSel = el('select', { class: 'exp-select exp-alloc__cc', id: ccId, name: 'lines[' + i + '][alloc_cc][]', required: 'required' });
				ccSel.appendChild(el('option', { value: '', text: t('Cost center…') }));
				var pctId = 'exp-line-' + i + '-pct-' + allocSeq;
				a.appendChild(el('label', { class: 'exp-sr-only', 'for': pctId, text: t('Percent') }));
				var pct = el('input', { class: 'exp-input exp-alloc__pct', id: pctId, name: 'lines[' + i + '][alloc_pct][]', value: '100', inputmode: 'decimal', required: 'required', 'aria-label': t('Percent share') });
				allocSeq++;
				var delA = el('button', { type: 'button', class: 'exp-btn exp-btn--danger exp-alloc__del', text: '×', 'aria-label': t('Remove allocation') });
				delA.addEventListener('click', function () { a.remove(); });
				a.appendChild(ccSel);
				a.appendChild(pct);
				a.appendChild(delA);
				allocHost.appendChild(a);
				lookupsP.then(function (lk2) {
					var list = (lk2 && lk2.cost_centers) || [];
					list.forEach(function (c) {
						ccSel.appendChild(el('option', { value: String(c.id), text: c.code + ' — ' + c.name }));
					});
					if (list.length === 1) ccSel.selectedIndex = 1;
				});
			}
			var allocWrap = el('fieldset', { class: 'exp-fieldset' });
			allocWrap.appendChild(el('legend', { text: t('Where does this cost go?') }));
			allocWrap.appendChild(el('p', { class: 'exp-hint', text: t('Allocations must cover a cost center and total 100% per line.') }));
			allocWrap.appendChild(allocHost);
			var addAlloc = el('button', { type: 'button', class: 'exp-btn exp-btn--secondary', text: t('Split allocation') });
			addAlloc.addEventListener('click', allocRow);
			allocWrap.appendChild(addAlloc);
			var allocErr = el('p', { class: 'exp-field-error', 'data-alloc-error': '1', hidden: 'hidden' });
			allocWrap.appendChild(allocErr);
			allocHost.addEventListener('input', function () { allocErr.hidden = true; });
			row.appendChild(allocWrap);
			allocRow();
			var del = el('button', { type: 'button', class: 'exp-btn exp-btn--danger exp-line__del', text: t('Remove') });
			del.addEventListener('click', function () {
				row.remove();
				delete lineRefs[i];
				syncDelBtns();
			});
			row.appendChild(del);
			linesHost.appendChild(row);
			lineRefs[i] = { row: row, allocErr: allocErr };
			E.clearFieldError(addBtn);
			syncDelBtns();
			syncTypeFields();
			lookupsP.then(function (lk2) {
				cats = (lk2 && lk2.categories) || [];
				refillCats();
				refillRates();
			});
		}
		function syncDelBtns() {
			var rows = linesHost.querySelectorAll('fieldset.exp-line');
			rows.forEach(function (r) {
				var b = r.querySelector('.exp-line__del');
				if (b) b.disabled = rows.length === 1;
			});
		}
		addBtn.addEventListener('click', lineFields);
		lineFields();
		// Sibling integration surface (F21/F25/F26 + §0.1 hints): only
		// rendered when the summary endpoint reports an installed sibling —
		// standalone installs keep the form exactly as before.
		(function integrations() {
			var wrap = document.getElementById('exp-integrations');
			if (!wrap) return;
			api('/integrations/summary').then(function (s) {
				var sib = (s && s.siblings) || {};
				var azc = sib.arbeitszeitcheck || {};
				var mc = sib.mobilitycheck || {};
				if (azc.installed) {
					var tripWrap = document.getElementById('exp-f-trip-wrap');
					if (tripWrap) tripWrap.hidden = false;
					var link = document.getElementById('exp-f-trip-link');
					if (link && azc.trips_list_url) link.href = azc.trips_list_url;
				}
			if (!mc.installed) return;
			lookupsP.then(function (lk) {
				var cats = (lk && lk.categories) || [];
				var rates = (lk && lk.rates) || [];
				var ccs = (lk && lk.cost_centers) || [];
				// Same gate on both sides: the server requires category +
				// cost-center allocation on every imported line (mileage also a
				// rate) — money fields are user-confirmed, never auto-guessed.
				var pickSeq = 0;
				function labeled(label, opts, valKey, labelKey) {
					var id = 'exp-imp-' + (++pickSeq);
					var s = el('select', { class: 'exp-input', id: id });
					opts.forEach(function (o) {
						var op = document.createElement('option');
						op.value = String(o[valKey]);
						op.textContent = String(o[labelKey]);
						s.appendChild(op);
					});
					var w = el('div', { class: 'exp-field' });
					w.appendChild(el('label', { class: 'exp-label', 'for': id, text: t(label) }));
					w.appendChild(s);
					return { wrap: w, sel: s };
				}
				function catsFor(lineType) {
					return cats.filter(function (c) { return (c.allow_lines || '').indexOf(lineType) !== -1; });
				}
				// F25 — claimable drive segments → draft mileage claims.
				api('/integrations/mc/segments').then(function (r) {
					var host = document.getElementById('exp-integrations-seg-list');
					var box = document.getElementById('exp-integrations-seg');
					var claimed = (r && r.claimed) || {};
					var segs = ((r && r.segments) || []).filter(function (g) { return g.claimable; });
					if (!segs.length) return;
					wrap.hidden = false;
					box.hidden = false;
					segs.forEach(function (g) {
						var ref = String(g.segment_id);
						var item = el('div', { class: 'exp-card', role: 'listitem' });
						var title = fmtDate(g.date) + ' · ' + g.distance_km + ' km';
						var route = (g.from || '') + ' → ' + (g.to || '');
						item.appendChild(el('strong', { text: title }));
						item.appendChild(el('p', { class: 'exp-card__meta', text: route + (g.vehicle_label ? ' · ' + g.vehicle_label : '') }));
						if (claimed[ref]) {
							item.appendChild(E.badge('info', t('Already in claim {no}', { no: claimed[ref] })));
						} else {
							var catF = labeled('Category', catsFor('mileage'), 'id', 'name');
							var rateF = labeled('Rate', rates.filter(function (rt) {
								return rt.rate_type === 'mileage' && (!rt.effective_from || rt.effective_from <= g.date);
							}), 'code', 'name');
							var ccF = labeled('Cost center', ccs, 'id', 'name');
							item.appendChild(el('div', {}, [catF.wrap, rateF.wrap, ccF.wrap]));
							var btn = el('button', { type: 'button', class: 'exp-btn exp-btn--secondary', text: t('Create mileage draft') });
							btn.addEventListener('click', function () {
								btn.disabled = true;
								api('/integrations/mc/segments/claim', { method: 'POST', json: {
									segment_id: g.segment_id,
									category_id: Number(catF.sel.value) || 0,
									rate_code: rateF.sel.value || '',
									cost_center_id: Number(ccF.sel.value) || 0
								} })
									.then(function (r2) {
										E.toast('success', t('Draft created.'));
										location.href = E.appBase() + '/claims/' + r2.claim.id;
									}, function (err) { btn.disabled = false; E.handleError(err); });
							});
							item.appendChild(btn);
						}
						host.appendChild(item);
					});
				}, function () {});
				// F26 — counter-signed charge-backs → deduction drafts.
				api('/integrations/mc/chargebacks').then(function (r) {
					var host = document.getElementById('exp-integrations-cb-list');
					var box = document.getElementById('exp-integrations-cb');
					var claimed = (r && r.claimed) || {};
					var cbs = (r && r.chargebacks) || [];
					if (!cbs.length) return;
					wrap.hidden = false;
					box.hidden = false;
					cbs.forEach(function (cb) {
						var ref = 'chargeback:' + cb.chargeback_id;
						var item = el('div', { class: 'exp-card', role: 'listitem' });
						item.appendChild(el('strong', { text: fmtDate(cb.entry_date) + ' · −' + money(cb.amount_minor, 'EUR') }));
						item.appendChild(el('p', { class: 'exp-card__meta', text: (cb.vehicle_label || '') + (cb.notes ? ' — ' + cb.notes : '') }));
						if (claimed[ref]) {
							item.appendChild(E.badge('info', t('Already in claim {no}', { no: claimed[ref] })));
						} else {
							var catF = labeled('Category', catsFor('receipt'), 'id', 'name');
							var ccF = labeled('Cost center', ccs, 'id', 'name');
							item.appendChild(el('div', {}, [catF.wrap, ccF.wrap]));
							var btn = el('button', { type: 'button', class: 'exp-btn exp-btn--secondary', text: t('Create deduction draft') });
							btn.addEventListener('click', function () {
								btn.disabled = true;
								api('/integrations/mc/chargebacks/claim', { method: 'POST', json: {
									chargeback_id: cb.chargeback_id,
									category_id: Number(catF.sel.value) || 0,
									cost_center_id: Number(ccF.sel.value) || 0
								} })
									.then(function (r2) {
										E.toast('success', t('Draft created.'));
										location.href = E.appBase() + '/claims/' + r2.claim.id;
									}, function (err) { btn.disabled = false; E.handleError(err); });
							});
							item.appendChild(btn);
						}
						host.appendChild(item);
					});
				}, function () {});
			}, function () {});
			}, function () {});
		})();
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var fd = new FormData(form);
			var lines = {};
			for (var pair of fd.entries()) {
				var m = /^lines\[(\d+)\]\[(\w+)\](\[\])?$/.exec(pair[0]);
				if (m) {
					lines[m[1]] = lines[m[1]] || {};
					if (m[3]) {
						lines[m[1]][m[2]] = lines[m[1]][m[2]] || [];
						lines[m[1]][m[2]].push(pair[1]);
					} else {
						lines[m[1]][m[2]] = pair[1];
					}
				}
			}
			var lineKeys = Object.keys(lines);
			if (lineKeys.length === 0) {
				E.markFieldError(addBtn, t('Add at least one expense line to save the claim.'));
				addBtn.focus();
				return;
			}
			// Client-side mirror of the server's allocation gate: percent
			// shares must sum to exactly 100 — and the message lands on the
			// offending line itself, not only a toast.
			var allocBad = [];
			lineKeys.forEach(function (k) {
				var l = lines[k];
				var sum = 0;
				(l.alloc_pct || []).forEach(function (p) { sum += parseFloat(p) || 0; });
				if (!l.alloc_cc || !l.alloc_cc.length || l.alloc_cc.indexOf('') !== -1 || Math.abs(sum - 100) > 0.005) {
					allocBad.push(k);
				}
			});
			if (allocBad.length) {
				var firstBad = null;
				allocBad.forEach(function (k) {
					var ref = lineRefs[k];
					if (!ref) return;
					ref.allocErr.textContent = t('Allocations must cover a cost center and total 100% per line.');
					ref.allocErr.hidden = false;
					var s2 = ref.row.querySelector('.exp-alloc__cc');
					if (!firstBad && s2) firstBad = s2;
				});
				E.toast('error', t('Allocations must cover a cost center and total 100% per line.'));
				if (firstBad) firstBad.focus();
				return;
			}
			var payload = {
				title: fd.get('title'), purpose: fd.get('purpose'),
				currency: (fd.get('currency') || 'EUR').toUpperCase(),
				azc_trip_id: fd.get('azc_trip_id') ? Number(fd.get('azc_trip_id')) : null,
				lines: Object.keys(lines).sort().map(function (k) {
					var l = lines[k];
					var out = {
						line_type: l.line_type || 'receipt',
						description: l.description, occurred_on: l.occurred_on,
						category_id: l.category_id ? Number(l.category_id) : null,
						reimbursable: l.reimbursable === 'on',
						attendees: l.attendees || null,
						occasion: l.occasion || null,
						justification: l.justification || null,
						allocations: (l.alloc_cc || []).map(function (cc, ai) {
							return { cost_center_id: Number(cc), share_percent: (l.alloc_pct || [])[ai] || '0' };
						})
					};
					if (out.line_type === 'receipt') {
						out.amount = l.amount;
						if (l.foreign_amount) {
							out.foreign_amount = l.foreign_amount;
							out.foreign_currency = l.foreign_currency;
							out.foreign_fx = l.foreign_fx;
						}
					} else {
						out.qty = l.qty;
						out.rate_code = l.rate_code;
					}
					return out;
				})
			};
			api('/claims', { method: 'POST', json: payload }).then(function (c) {
				E.toast('success', t('Claim created.'));
				location.href = E.appBase() + '/claims/' + (c.claim && c.claim.id ? c.claim.id : c.id);
			}, function (err) {
				// Server returns flat keys (amount, rate_code, occasion…) while
				// controls are named lines[N][key] — map the suffix so the
				// inline error lands on the actual input, not only a toast.
				var f = err && err.fields;
				if (f) {
					Object.keys(f).forEach(function (key) {
						var input = form.querySelector('[name$="[' + key + ']"]');
						if (input) {
							E.markFieldError(input, f[key] === 'required'
								? t('This field is required') : t('Check this value'));
						}
					});
				}
				E.handleError(err);
			});
		});
	};

	views['claim-detail'] = function (root) {
		var id = root.getAttribute('data-exp-claim-id');
		var body = document.getElementById('exp-detail-body');
		var linesHost = document.getElementById('exp-detail-lines');
		var eventsHost = document.getElementById('exp-detail-events');
		var attachHost = document.getElementById('exp-detail-attachments');
		var decisionsHost = document.getElementById('exp-detail-decisions');
		var actions = document.getElementById('exp-detail-actions');
		function render(c) {
			document.getElementById('exp-detail-no').textContent = c.claim_no || ('#' + c.id);
			body.innerHTML = '';
			var dl = el('dl', { class: 'exp-detail-dl' });
			[['Status', c.status], ['Title', c.title], ['Purpose', c.purpose],
			 ['Reimbursable', money(c.reimbursable_total_minor, c.currency)],
			 ['Non-reimbursable', money(c.nonreimbursable_total_minor, c.currency)],
			 ['Owner', c.owner_uid]].forEach(function (p) {
				dl.appendChild(el('dt', { text: p[0] }));
				dl.appendChild(el('dd', { text: String(p[1] === null || p[1] === undefined ? '—' : p[1]) }));
			});
			body.appendChild(dl);
			linesHost.innerHTML = '';
			(c.lines || []).forEach(function (l) {
				linesHost.appendChild(el('div', { class: 'exp-card' }, [
					el('strong', { text: l.description || '' }),
					el('p', { class: 'exp-card__meta', text: fmtDate(l.service_date) + ' · ' + money(l.amount_minor, l.currency || c.currency) })
				]));
			});
			attachHost.innerHTML = '';
			var atts = c.attachments || [];
			if (atts.length === 0) {
				attachHost.appendChild(el('p', { class: 'exp-empty', text: t('No attachments filed yet.') }));
			}
			atts.forEach(function (a) {
				attachHost.appendChild(el('div', { class: 'exp-card' }, [
					el('strong', { text: a.name || ('#' + a.id) }),
					el('p', { class: 'exp-card__meta', text: (a.mime || '') + ' · ' +
						(a.size ? Math.ceil(a.size / 1024) + ' KB' : '') +
						(a.line_id ? ' · ' + t('line #{n}', { n: a.line_id }) : '') })
				]));
			});
			decisionsHost.innerHTML = '';
			var decs = c.decisions || [];
			if (decs.length === 0) {
				decisionsHost.appendChild(el('p', { class: 'exp-empty', text: t('No decisions yet.') }));
			}
			decs.forEach(function (d) {
				decisionsHost.appendChild(el('div', { class: 'exp-timeline__item' }, [
					el('span', { class: 'exp-timeline__when', text: fmtDateTime(d.created_at) }),
					el('span', { text: t('step {n}', { n: d.step_no }) + ' · ' + (d.decided_by || '') +
						' · ' + (d.decision || '') + (d.note ? ' · ' + d.note : '') })
				]));
			});
			eventsHost.innerHTML = '';
			var evs = c.events || [];
			if (evs.length === 0) {
				eventsHost.appendChild(el('p', { class: 'exp-empty', text: t('No audit events yet.') }));
			}
			evs.forEach(function (ev2) {
				eventsHost.appendChild(el('div', { class: 'exp-timeline__item' }, [
					el('span', { class: 'exp-timeline__when', text: fmtDateTime(ev2.created_at) }),
					el('span', { text: (ev2.actor || '') + ' ' + (ev2.event || '') +
						(ev2.from_status ? ' ' + ev2.from_status + '→' + ev2.to_status : '') })
				]));
			});
			actions.innerHTML = '';
			function act(label, cls, fn) {
				var b = el('button', { type: 'button', class: 'exp-btn ' + cls, text: label });
				b.addEventListener('click', fn);
				actions.appendChild(b);
			}
			var ver = c.version;
			var can = c.can || {};
			if (can.canSubmit) {
				act(t('Submit'), 'exp-btn--primary', function () {
					api('/claims/' + id + '/submit', { method: 'POST', json: { version: ver } })
						.then(reload, E.handleError);
				});
			}
			if (can.canWithdraw) {
				act(t('Withdraw'), 'exp-btn--danger', function () {
					E.confirmDialog(t('Withdraw this claim back to draft?')).then(function (ok) {
						if (!ok) return;
						api('/claims/' + id + '/withdraw', { method: 'POST', json: { version: ver } }).then(reload, E.handleError);
					});
				});
			}
			if (can.canDecide) {
				act(t('Approve'), 'exp-btn--primary', function () {
					E.confirmDialog(t('Approve this claim?')).then(function (ok) {
						if (!ok) return;
						api('/claims/' + id + '/decisions', { method: 'POST', json: { decision: 'approved', version: ver } }).then(reload, E.handleError);
					});
				});
				act(t('Request changes'), 'exp-btn--secondary', function () {
					var note = document.createElement('textarea');
					note.className = 'exp-input';
					note.required = true;
					note.name = 'note';
					E.openDialog({
						title: t('Request changes'), body: note, submitLabel: t('Send'),
						onSubmit: function () {
							return api('/claims/' + id + '/decisions', { method: 'POST', json: { decision: 'changes_requested', note: note.value, version: ver } }).then(reload);
						}
					});
				});
				act(t('Reject'), 'exp-btn--danger', function () {
					var note = document.createElement('textarea');
					note.className = 'exp-input';
					note.required = true;
					note.name = 'note';
					E.openDialog({
						title: t('Reject claim'), body: note, danger: true, submitLabel: t('Reject'),
						onSubmit: function () {
							return api('/claims/' + id + '/decisions', { method: 'POST', json: { decision: 'rejected', note: note.value, version: ver } }).then(reload);
						}
					});
				});
			}
			// Receipt attach on own editable claims.
			if (can.canAttach) {
				var up = el('label', { class: 'exp-btn exp-btn--secondary' });
				up.textContent = t('Attach receipt');
				var fi = el('input', { type: 'file', class: 'exp-sr-only', accept: 'image/*,application/pdf' });
				fi.addEventListener('change', function () {
					if (!fi.files || !fi.files[0]) return;
					var fdata = new FormData();
					fdata.append('file', fi.files[0]);
					fdata.append('claim_id', String(id));
					api('/receipts-inbox', { method: 'POST', formData: fdata }).then(function () {
						E.toast('success', t('Receipt attached.'));
					}, E.handleError);
				});
				up.appendChild(fi);
				actions.appendChild(up);
			}
		}
		function reload() {
			return listFetch(body, null,
				function () { return api('/claims/' + id); },
				function (data) { render(data && data.claim ? data.claim : data); })();
		}
		reload();
	};

	views.inbox = function () {
		var list = document.getElementById('exp-inbox-list');
		var empty = document.getElementById('exp-inbox-empty');
		listFetch(list, empty, function () { return api('/inbox'); },
			function (data) { var items = (data && data.claims) || []; renderList(list, empty, items); return items.length; },
			document.getElementById('exp-inbox-count'))();
	};

	views.receipts = function () {
		var list = document.getElementById('exp-receipts-list');
		var empty = document.getElementById('exp-receipts-empty');
		var file = document.getElementById('exp-receipt-file');
		function load() {
			return listFetch(list, empty, function () { return api('/receipts-inbox'); }, function (data) {
				var items = (data && data.receipts) || [];
				items.forEach(function (r) {
					list.appendChild(el('div', { class: 'exp-card', role: 'listitem' }, [
						el('strong', { text: r.original_name || ('#' + r.id) }),
						el('p', { class: 'exp-card__meta', text: fmtDateTime(r.captured_at || r.uploaded_at) + ' · ' + (r.mime || '') })
					]));
				});
				return items.length;
			}, document.getElementById('exp-receipts-count'))();
		}
		file.addEventListener('change', function () {
			if (!file.files || !file.files[0]) return;
			var fd = new FormData();
			fd.append('file', file.files[0]);
			api('/receipts-inbox', { method: 'POST', formData: fd }).then(function () {
				E.toast('success', t('Receipt uploaded.'));
				file.value = '';
				load();
			}, E.handleError);
		});
		load();
	};

	views.all = function () {
		var list = document.getElementById('exp-all-list');
		listFetch(list, null, function () { return api('/claims?scope=all'); },
			function (data) { var items = (data && data.claims) || []; renderList(list, null, items); return items.length; },
			document.getElementById('exp-all-count'))();
	};

	views.settlements = function () {
		var list = document.getElementById('exp-settle-list');
		var form = document.getElementById('exp-settle-form');
		var batches = document.getElementById('exp-batches-list');
		var selected = {};
		listFetch(list, null, function () { return api('/settlements'); }, function (data) {
			var items = (data && data.claims) || [];
			items.forEach(function (c) {
				var card = claimRow(c);
				var cb = el('input', { type: 'checkbox' });
				cb.addEventListener('change', function () {
					if (cb.checked) selected[c.id] = c.version;
					else delete selected[c.id];
					form.hidden = Object.keys(selected).length === 0;
				});
				card.prepend(cb);
				list.appendChild(card);
			});
			return items.length;
		}, document.getElementById('exp-settle-count'))();
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var ref = document.getElementById('exp-paid-ref').value;
			var format = document.getElementById('exp-settle-format').value;
			var ids = Object.keys(selected).map(Number);
			// §9.3 finality gate: payout is documented-irrevocable.
			E.confirmDialog(t('Payout is documented and cannot be undone — corrections need a correction claim. Settle {n} claims?', { n: ids.length })).then(function (ok) {
				if (!ok) return;
				api('/settlements', { method: 'POST', json: { claim_ids: ids, paid_ref: ref, format: format } })
					.then(function () { E.toast('success', t('Batch settled.')); location.reload(); },
						E.handleError);
			});
		});
		listFetch(batches, null, function () { return api('/settlements/batches'); }, function (data) {
			((data && data.batches) || []).forEach(function (b) {
				var a = el('a', { class: 'exp-card', href: '#' });
				a.textContent = '#' + b.id + ' · ' + fmtDateTime(b.created_at)
					+ ' · ' + t('{n} rows', { n: b.row_count }) + ' · ' + String(b.format || 'csv').toUpperCase();
				a.addEventListener('click', function (ev) {
					ev.preventDefault();
					location.href = E.appBase() + '/settlements/' + b.id;
				});
				batches.appendChild(el('div', { role: 'listitem' }, [a]));
			});
		})();
	};

	views['settlement-detail'] = function (root) {
		var id = root.getAttribute('data-exp-batch-id');
		var body = document.getElementById('exp-batch-body');
		var status = document.getElementById('exp-export-status');
		var fmt = document.getElementById('exp-export-format');

		function saveBlob(blob, filename) {
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = filename;
			document.body.appendChild(a);
			a.click();
			setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 100);
		}

		function downloadExport(channel) {
			status.textContent = t('Preparing export…');
			fetch(E.appBase() + '/api/settlements/batches/' + id + '/export?channel=' + channel + '&format=' + fmt.value, {
				credentials: 'same-origin'
			}).then(function (res) {
				if (res.status === 202) {
					return res.json().then(function (d) { return pollExport(d, status); });
				}
				if (!res.ok) {
					return res.json().then(function (d) { throw new Error(d.error || 'EXPORT_FAILED'); });
				}
				var name = (res.headers.get('content-disposition') || '').split('filename=')[1] || ('export.' + fmt.value);
				return res.blob().then(function (blob) {
					saveBlob(blob, name.replace(/"/g, ''));
					status.textContent = '';
				});
			}, E.handleError).catch(function (err) {
				status.textContent = '';
				E.handleError(err);
			});
		}

		function pollExport(d, statusEl) {
			statusEl.textContent = t('Export is being generated…');
			var timer = setInterval(function () {
				fetch(E.appBase() + '/api/settlements/exports/' + encodeURIComponent(d.request_id), { credentials: 'same-origin' })
					.then(function (res) {
						if (res.status === 409) { return; } // EXPORT_NOT_READY
						clearInterval(timer);
						if (!res.ok) {
							return res.json().then(function (e) { throw new Error(e.error || 'EXPORT_FAILED'); });
						}
						statusEl.textContent = '';
						location.href = E.appBase() + '/api/settlements/exports/' + encodeURIComponent(d.request_id)
							+ '/download?token=' + encodeURIComponent(d.token);
					})
					.catch(function (err) { clearInterval(timer); statusEl.textContent = ''; E.handleError(err); });
			}, 2000);
		}

		document.getElementById('exp-export-reimb').addEventListener('click', function () { downloadExport('reimbursable'); });
		document.getElementById('exp-export-nonreimb').addEventListener('click', function () { downloadExport('nonreimbursable'); });
		listFetch(body, null, function () { return api('/settlements/batches/' + id); }, function (d) {
			var b = (d && d.batch) || {};
			var dl = el('dl', { class: 'exp-detail-dl' });
			[[t('Created by'), b.created_by], [t('Created at'), fmtDateTime(b.created_at)],
			 [t('Rows'), b.row_count], [t('Format'), String(b.format || 'csv').toUpperCase()],
			 [t('Integrity (SHA-256)'), b.file_hash]].forEach(function (p) {
				dl.appendChild(el('dt', { text: p[0] }));
				dl.appendChild(el('dd', { text: String(p[1] === null || p[1] === undefined || p[1] === '' ? '—' : p[1]) }));
			});
			body.appendChild(dl);
			var rows = (d && d.rows) || [];
			if (rows.length) {
				var table = el('table', { class: 'exp-table exp-table--cards' });
				var thead = el('thead');
				thead.appendChild(el('tr', {}, [t('Claim'), t('Payment reference'), t('Paid at')]
					.map(function (h) { return el('th', { scope: 'col', text: h }); })));
				table.appendChild(thead);
				var tb = el('tbody');
				rows.forEach(function (row) {
					tb.appendChild(el('tr', {}, [
						el('td', { 'data-cell': t('Claim'), text: '#' + row.claim_id }),
						el('td', { 'data-cell': t('Payment reference'), text: row.paid_ref || '—' }),
						el('td', { 'data-cell': t('Paid at'), text: fmtDateTime(row.paid_at) })
					]));
				});
				table.appendChild(tb);
				body.appendChild(table);
			}
		})();
	};

	views.reports = function () {
		var body = document.getElementById('exp-reports-body');
		var monthLabel = document.getElementById('exp-rep-month-label');
		var cur = new Date();
		var month = cur.getFullYear() + '-' + String(cur.getMonth() + 1).padStart(2, '0');
		function shift(d) {
			var p = month.split('-').map(Number);
			var dt = new Date(p[0], p[1] - 1 + d, 1);
			month = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0');
			load();
		}
		document.getElementById('exp-rep-prev').addEventListener('click', function () { shift(-1); });
		document.getElementById('exp-rep-next').addEventListener('click', function () { shift(1); });
		document.getElementById('exp-rep-today').addEventListener('click', function () {
			var n = new Date();
			month = n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0');
			load();
		});
		function table(headers, rows) {
			var table = el('table', { class: 'exp-table exp-table--cards' });
			var thead = el('thead');
			thead.appendChild(el('tr', {}, headers.map(function (h) { return el('th', { scope: 'col', text: h }); })));
			table.appendChild(thead);
			var tb = el('tbody');
			rows.forEach(function (r) { tb.appendChild(el('tr', {}, r)); });
			table.appendChild(tb);
			return table;
		}
		var orgCur = '';
		function orgMoney(minor) { return money(minor, orgCur || undefined); }
		function load() {
			return listFetch(body, null, function () { return api('/reports/summary?month=' + month); }, function (data) {
				if (monthLabel) {
					monthLabel.textContent = (data && data.month) || month;
					monthLabel.hidden = false;
				}
				var d = data || {};
				orgCur = d.org_currency || orgCur;
				var t0 = d.totals || {};
				var strip = el('div', { class: 'exp-stat-strip', role: 'list' }, [
					el('div', { class: 'exp-stat-tile', role: 'listitem' }, [
						el('span', { class: 'exp-stat-tile__label', text: t('Submitted claims') }),
						el('span', { class: 'exp-stat-tile__value', text: String(t0.claims || 0) })
					]),
					el('div', { class: 'exp-stat-tile', role: 'listitem' }, [
						el('span', { class: 'exp-stat-tile__label', text: t('Org total') }),
						el('span', { class: 'exp-stat-tile__value', text: orgMoney(t0.org_total_minor || 0) })
					])
				]);
				body.appendChild(strip);
				if (!t0.claims) {
					body.appendChild(el('p', { class: 'exp-empty', text: t('No submitted claims in this month.') }));
					return;
				}
				body.appendChild(el('h3', { class: 'exp-section-h', text: t('By claimant') }));
				body.appendChild(table([t('Claimant'), t('Claims'), t('Total')], (d.by_claimant || []).map(function (r) {
					return [el('td', { 'data-cell': t('Claimant'), text: r.uid || '' }),
						el('td', { 'data-cell': t('Claims'), text: String(r.claims || 0) }),
						el('td', { 'data-cell': t('Total'), text: orgMoney(r.org_total_minor || 0) })];
				})));
				body.appendChild(el('h3', { class: 'exp-section-h', text: t('By cost center') }));
				body.appendChild(table([t('Cost center'), t('Claims'), t('Total')], (d.by_cost_center || []).map(function (r) {
					return [el('td', { 'data-cell': t('Cost center'), text: r.name || t('Unassigned') }),
						el('td', { 'data-cell': t('Claims'), text: String(r.claims || 0) }),
						el('td', { 'data-cell': t('Total'), text: orgMoney(r.org_total_minor || 0) })];
				})));
				body.appendChild(el('h3', { class: 'exp-section-h', text: t('By category') }));
				body.appendChild(table([t('Category'), t('Lines'), t('Total')], (d.by_category || []).map(function (r) {
					return [el('td', { 'data-cell': t('Category'), text: r.name || ('#' + r.id) }),
						el('td', { 'data-cell': t('Lines'), text: String(r.lines || 0) }),
						el('td', { 'data-cell': t('Total'), text: money(r.amount_minor, r.currency) })];
				})));
				if ((d.anomalies || []).length) {
					body.appendChild(el('h3', { class: 'exp-section-h', text: t('Outliers') }));
					body.appendChild(table([t('Claim'), t('Claimant'), t('Total')], d.anomalies.map(function (r) {
						return [el('td', { 'data-cell': t('Claim'), text: r.claim_no || ('#' + r.id) }),
							el('td', { 'data-cell': t('Claimant'), text: r.uid || '' }),
							el('td', { 'data-cell': t('Total'), text: orgMoney(r.org_total_minor || 0) })];
					})));
				}
			})();
		}
		load();
	};

	['admin-categories', 'admin-cost-centers'].forEach(function (name) {
		views[name] = function (root) {
			var isCat = name === 'admin-categories';
			var listId = isCat ? 'exp-cat-list' : 'exp-cc-list';
			var formId = isCat ? 'exp-cat-form' : 'exp-cc-form';
			var ep = isCat ? '/admin/categories' : '/admin/cost-centers';
			var list = document.getElementById(listId);
			var form = document.getElementById(formId);
			function load() {
				return listFetch(list, null, function () { return api(ep); }, function (data) {
					var items = (data && (data.categories || data.cost_centers)) || [];
					items.forEach(function (c) {
						list.appendChild(el('div', { class: 'exp-card' }, [
							el('strong', { text: c.code + ' — ' + c.name }),
							el('p', { class: 'exp-card__meta', text: c.active ? t('Active') : t('Inactive') })
						]));
					});
				})();
			}
			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var fd = new FormData(form);
				api(ep, { method: 'POST', json: {
					code: fd.get('code'), name: fd.get('name'), active: true
				} }).then(function () { form.reset(); load(); }, E.handleError);
			});
			load();
		};
	});

	views['admin-rates'] = function () {
		var list = document.getElementById('exp-rates-list');
		var form = document.getElementById('exp-rate-form');
		function load() {
			return listFetch(list, null, function () { return api('/admin/rates'); }, function (data) {
				((data && data.rates) || []).forEach(function (rt) {
					list.appendChild(el('div', { class: 'exp-card' }, [
						el('strong', { text: rt.code + ' — ' + rt.name }),
						el('p', { class: 'exp-card__meta', text: money(rt.rate_minor, rt.currency) + ' · ' + rt.rate_type + ' · ' + fmtDate(rt.effective_from) })
					]));
				});
			})();
		}
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var fd = new FormData(form);
			api('/admin/rates', { method: 'POST', json: {
				rate_type: fd.get('rate_type'), code: fd.get('code'), name: fd.get('name'),
				rate: fd.get('rate'), currency: (fd.get('currency') || 'EUR').toUpperCase(),
				effective_from: fd.get('effective_from')
			} }).then(function () { form.reset(); load(); }, E.handleError);
		});
		load();
	};

	views['admin-chains'] = function () {
		var list = document.getElementById('exp-chains-list');
		listFetch(list, null, function () { return api('/admin/chains'); }, function (data) {
			((data && data.chains) || []).forEach(function (ch) {
				list.appendChild(el('div', { class: 'exp-card' }, [
					el('strong', { text: ch.name }),
					el('p', { class: 'exp-card__meta', text: (ch.steps || []).map(function (s) { return s.approver_uid; }).join(' → ') })
				]));
			});
		})();
	};

	views.settings = function () {
		var body = document.getElementById('exp-settings-body');
		listFetch(body, null, function () { return api('/settings'); }, function (data) {
			// Envelope is {ok, settings:{…}, roles:{…}} — the registry keys
			// live one level down; the whole body is the flat-ok shape.
			var s = (data && data.settings) || {};
			var form = el('form', { class: 'exp-form' });
			var KEYS = ['org_currency', 'enable_mileage_claims', 'allow_route_evidence',
				'allow_self_approve', 'attachment_max_mb', 'inbox_purge_days',
				'retention_years', 'digest_cadence'];
			var BOOL_KEYS = ['enable_mileage_claims', 'allow_route_evidence', 'allow_self_approve'];
			KEYS.forEach(function (k) {
				if (!(k in s)) return;
				var wrap = el('div', { class: 'exp-field' });
				var id = 'exp-set-' + k;
				wrap.appendChild(el('label', { class: 'exp-label', 'for': id, text: k.replace(/_/g, ' ') }));
				if (BOOL_KEYS.indexOf(k) !== -1) {
					// Design-system: hidden '0' + checkbox '1' — FormData last-wins.
					var hid = el('input', { type: 'hidden', name: k, value: '0' });
					var cb = el('input', { type: 'checkbox', id: id, name: k, value: '1' });
					cb.checked = s[k] === '1' || s[k] === 1 || s[k] === true;
					wrap.appendChild(hid);
					wrap.appendChild(cb);
				} else {
					wrap.appendChild(el('input', { class: 'exp-input', id: id, name: k, value: String(s[k]) }));
				}
				form.appendChild(wrap);
			});
			var save = el('button', { type: 'submit', class: 'exp-btn exp-btn--primary', text: t('Save') });
			form.appendChild(save);
			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var fd = new FormData(form);
				var patch = {};
				for (var p of fd.entries()) patch[p[0]] = p[1];
				api('/settings', { method: 'POST', json: patch }).then(function () {
					E.toast('success', t('Settings saved.'));
				}, E.handleError);
			});
			body.appendChild(form);

			// Edge 38 / §12.4b: with MobilityCheck installed, an unset
			// coexistence mode is a double-payment surface — the admin must
			// pick a pipeline explicitly; never auto-configured, never blocked.
			function renderCoex() {
				api('/integrations/summary').then(function (sum) {
				var mc = (sum && sum.siblings && sum.siblings.mobilitycheck) || {};
				if (!mc.installed) return;
				var co = (sum && sum.coexistence) || {};
				var mode = co.mode || 'unset';

				var section = el('section', { class: 'exp-section', 'aria-labelledby': 'exp-coex-title' });
				section.appendChild(el('h2', { id: 'exp-coex-title', text: t('MobilityCheck coexistence') }));

				var unset = mode === 'unset';
				var callout = el('aside', {
					class: 'exp-license-callout ' + (unset ? 'exp-license-callout--warning' : 'exp-license-callout--info'),
					role: unset ? 'alert' : 'note'
				});
				var cbody = el('div', { class: 'exp-license-callout__body' });
				cbody.appendChild(el('h3', {
					class: 'exp-license-callout__title',
					text: unset
						? t('Two apps can submit vehicle expenses')
						: (mode === 'a'
							? t('Mode A — MobilityCheck settles vehicle money')
							: t('Mode B — ExpenseCheck is the single claims pipeline'))
				}));
				cbody.appendChild(el('p', {
					class: 'exp-license-callout__text',
					text: unset
						? t('ExpenseCheck and MobilityCheck are both installed and no coexistence mode is configured. Pick one pipeline so a trip can never be claimed twice — nothing is blocked until you choose.')
						: (mode === 'a'
							? t('Mileage claims are disabled here; drivers claim private-car kilometres in MobilityCheck. Applies to new claims only.')
							: t('Drivers claim kilometres here, linked to MobilityCheck trips. Disable MobilityCheck\u2019s own expense submit in its settings.'))
				}));
				var help = el('a', {
					href: 'https://nextcloud.software-by-design.de/',
					target: '_blank', rel: 'noopener noreferrer',
					class: 'exp-license-callout__link',
					text: t('Handbook: choosing a mode')
				});
				cbody.appendChild(help);
				callout.appendChild(cbody);
				section.appendChild(callout);

				var cards = el('div', { class: 'exp-choice-cards', role: 'radiogroup', 'aria-label': t('Coexistence mode') });
				function modeCard(value, title, hint) {
					var input = el('input', { type: 'radio', name: 'exp-coex-mode', value: value });
					input.checked = (mode === value);
					var label = el('label', { class: 'exp-choice-card' }, [
						input,
						el('span', { class: 'exp-choice-card__copy' }, [
							el('span', { class: 'exp-choice-card__title', text: title }),
							el('span', { class: 'exp-choice-card__hint', text: hint })
						])
					]);
					return label;
				}
				cards.appendChild(modeCard('a',
					t('Mode A — MobilityCheck settles'),
					t('Mileage claims are turned off here. Drivers submit private-car kilometres in MobilityCheck\u2019s own module.')));
				cards.appendChild(modeCard('b',
					t('Mode B — ExpenseCheck settles'),
					t('One approval queue and one settlement for all employee money. Requires MobilityCheck\u2019s own expense submit to be switched off there.')));
				section.appendChild(cards);

				var mcState = el('p', { class: 'exp-field-hint' });
				mcState.appendChild(document.createTextNode(
					mc.expense_submit_enabled
						? t('MobilityCheck\u2019s own expense submit is currently ON — switch it off under MobilityCheck → Settings for Mode B.')
						: t('MobilityCheck\u2019s own expense submit is off.')));
				var mcLink = el('a', {
					href: (window.OC && OC.generateUrl) ? OC.generateUrl('/apps/mobilitycheck/settings') : '/apps/mobilitycheck/settings',
					text: t('Open MobilityCheck settings')
				});
				mcState.appendChild(document.createTextNode(' '));
				mcState.appendChild(mcLink);
				section.appendChild(mcState);

				var apply = el('button', { type: 'button', class: 'exp-btn exp-btn--primary', text: t('Apply mode') });
				apply.addEventListener('click', function () {
					var picked = section.querySelector('input[name="exp-coex-mode"]:checked');
					if (!picked) return;
					apply.disabled = true;
					api('/settings', { method: 'POST', json: {
						mc_coexistence_mode: picked.value,
						enable_mileage_claims: picked.value === 'a' ? '0' : '1'
					} }).then(function () {
						var mil = document.getElementById('exp-set-enable_mileage_claims');
						if (mil) mil.checked = picked.value !== 'a';
						E.toast('success', t('Coexistence mode saved.'));
						// Re-render the section with the new state.
						section.remove();
						renderCoex();
					}, function (err) { apply.disabled = false; E.handleError(err); });
				});
				section.appendChild(apply);
				body.appendChild(section);
				}, function () { /* summary endpoint absent → standalone, nothing to show */ });
			}
			renderCoex();
		})();
	};

	views.license = function () {
		var body = document.getElementById('exp-license-body');
		listFetch(body, null, function () { return api('/admin/license'); }, function (d) {
			body.appendChild(el('p', { text: t('Status') + ': ' + (d.status || t('none')) }));
			if (d.seats !== undefined) body.appendChild(el('p', { text: t('Seats') + ': ' + d.seats }));
		})();
	};

	views.index = function () {
		var btn = document.getElementById('exp-ob-dismiss');
		var card = document.getElementById('exp-onboarding');
		if (!btn || !card) return;
		btn.addEventListener('click', function () {
			btn.disabled = true;
			api('/onboarding/dismiss', { method: 'POST' }).then(function () {
				card.remove();
			}, function (err) { btn.disabled = false; E.handleError(err); });
		});
	};

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.querySelector('[data-exp-view]');
		if (!root) return;
		var name = root.getAttribute('data-exp-view');
		if (views[name]) views[name](root);
	});
})();
