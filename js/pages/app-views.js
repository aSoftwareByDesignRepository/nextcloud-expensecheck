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
			location.href = E.appBase() + '/claims/' + c.id;
		});
		card.appendChild(open);
		return card;
	}
	function renderList(host, emptyEl, items) {
		host.innerHTML = '';
		items.forEach(function (c) { host.appendChild(claimRow(c)); });
		if (emptyEl) emptyEl.hidden = items.length > 0;
	}
	var views = {};

	views.claims = function (root) {
		var list = document.getElementById('exp-claims-list');
		var empty = document.getElementById('exp-claims-empty');
		var sel = root.querySelector('[data-exp-filter="status"]');
		function load() {
			var qs = sel && sel.value ? '?status=' + encodeURIComponent(sel.value) : '';
			return api('/claims' + qs).then(function (data) {
				renderList(list, empty, (data && data.claims) || []);
			}, E.handleError);
		}
		if (sel) sel.addEventListener('change', load);
		load();
	};

	views['claim-form'] = function () {
		var form = document.getElementById('exp-claim-form');
		var linesHost = document.getElementById('exp-lines');
		var addBtn = document.getElementById('exp-add-line');
		var seq = 0;
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
			fld('occurred_on', t('Date'), 'date', { required: 'required', value: E.todayYmd() });
			var amt = fld('amount', t('Amount'), 'text', { inputmode: 'decimal', 'aria-describedby': 'exp-line-' + i + '-amt-h' });
			var qty = fld('qty', t('Qty (km/days/units)'), 'text', { inputmode: 'decimal' });
			var rate = fld('rate_code', t('Rate code'), 'text', { maxlength: '64' });
			// receipt uses amount; rated types use qty + rate_code — same gate
			// server-side, mirrored here so the user gets a clear signal.
			function syncTypeFields() {
				var isReceipt = typeSel.value === 'receipt';
				amt.disabled = !isReceipt;
				amt.required = isReceipt;
				qty.disabled = isReceipt;
				qty.required = !isReceipt;
				rate.disabled = isReceipt;
				rate.required = !isReceipt;
			}
			typeSel.addEventListener('change', syncTypeFields);
			syncTypeFields();
			var catWrap = el('div', { class: 'exp-field' });
			var catId = 'exp-line-' + i + '-category';
			catWrap.appendChild(el('label', { class: 'exp-label', 'for': catId, text: t('Category') }));
			var sel = el('select', { class: 'exp-select', id: catId, name: 'lines[' + i + '][category_id]', required: 'required' });
			sel.appendChild(el('option', { value: '', text: t('Choose…') }));
			catWrap.appendChild(sel);
			grid.appendChild(catWrap);
			var reimbWrap = el('label', { class: 'exp-check' });
			var cb = el('input', { type: 'checkbox', name: 'lines[' + i + '][reimbursable]', checked: 'checked' });
			reimbWrap.appendChild(cb);
			reimbWrap.appendChild(document.createTextNode(' ' + t('Reimbursable to me')));
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
				var pct = el('input', { class: 'exp-input exp-alloc__pct', id: pctId, name: 'lines[' + i + '][alloc_pct][]', value: '100', inputmode: 'decimal', 'aria-label': t('Percent share') });
				allocSeq++;
				var delA = el('button', { type: 'button', class: 'exp-btn exp-btn--danger exp-alloc__del', text: '×', 'aria-label': t('Remove allocation') });
				delA.addEventListener('click', function () { a.remove(); });
				a.appendChild(ccSel);
				a.appendChild(pct);
				a.appendChild(delA);
				allocHost.appendChild(a);
				api('/lookups').then(function (lk) {
					((lk && lk.cost_centers) || []).forEach(function (c) {
						ccSel.appendChild(el('option', { value: String(c.id), text: c.code + ' — ' + c.name }));
					});
				});
			}
			var allocWrap = el('fieldset', { class: 'exp-fieldset' });
			allocWrap.appendChild(el('legend', { text: t('Cost allocation (must total 100%)') }));
			allocWrap.appendChild(allocHost);
			var addAlloc = el('button', { type: 'button', class: 'exp-btn exp-btn--secondary', text: t('Split allocation') });
			addAlloc.addEventListener('click', allocRow);
			allocWrap.appendChild(addAlloc);
			var allocErr = el('p', { class: 'exp-field-error', 'data-alloc-error': '1', hidden: 'hidden' });
			allocWrap.appendChild(allocErr);
			row.appendChild(allocWrap);
			allocRow();
			var del = el('button', { type: 'button', class: 'exp-btn exp-btn--danger exp-line__del', text: t('Remove') });
			del.addEventListener('click', function () { row.remove(); });
			row.appendChild(del);
			linesHost.appendChild(row);
			api('/lookups').then(function (lk) {
				((lk && lk.categories) || []).forEach(function (c) {
					sel.appendChild(el('option', { value: String(c.id), text: c.name }));
				});
			});
		}
		addBtn.addEventListener('click', lineFields);
		lineFields();
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
			// Client-side mirror of the server's allocation gate: percent
			// shares must sum to exactly 100 — catches the dead-end early.
			var allocOk = true;
			Object.keys(lines).forEach(function (k) {
				var l = lines[k];
				var sum = 0;
				(l.alloc_pct || []).forEach(function (p) { sum += parseFloat(p) || 0; });
				if (!l.alloc_cc || !l.alloc_cc.length || l.alloc_cc.indexOf('') !== -1 || Math.abs(sum - 100) > 0.005) {
					allocOk = false;
				}
			});
			if (!allocOk) {
				E.toast('error', t('Allocations must cover a cost center and total 100% per line.'));
				return;
			}
			var payload = {
				title: fd.get('title'), purpose: fd.get('purpose'),
				currency: (fd.get('currency') || 'EUR').toUpperCase(),
				lines: Object.keys(lines).sort().map(function (k) {
					var l = lines[k];
					var out = {
						line_type: l.line_type || 'receipt',
						description: l.description, occurred_on: l.occurred_on,
						category_id: l.category_id ? Number(l.category_id) : null,
						reimbursable: l.reimbursable === 'on',
						allocations: (l.alloc_cc || []).map(function (cc, ai) {
							return { cost_center_id: Number(cc), share_percent: (l.alloc_pct || [])[ai] || '0' };
						})
					};
					if (out.line_type === 'receipt') {
						out.amount = l.amount;
					} else {
						out.qty = l.qty;
						out.rate_code = l.rate_code;
					}
					return out;
				})
			};
			api('/claims', { method: 'POST', json: payload }).then(function (c) {
				E.toast('success', t('Claim created.'));
				location.href = E.appBase() + '/claims/' + c.id;
			}, E.handleError);
		});
	};

	views['claim-detail'] = function (root) {
		var id = root.getAttribute('data-exp-claim-id');
		var body = document.getElementById('exp-detail-body');
		var linesHost = document.getElementById('exp-detail-lines');
		var eventsHost = document.getElementById('exp-detail-events');
		var actions = document.getElementById('exp-detail-actions');
		function render(c) {
			document.getElementById('exp-detail-no').textContent = c.claim_no || ('#' + c.id);
			body.innerHTML = '';
			var dl = el('dl', { class: 'exp-detail-dl' });
			[['Status', c.status], ['Title', c.title], ['Purpose', c.purpose],
			 ['Reimbursable', money(c.reimbursable_total_minor, c.currency)],
			 ['Non-reimbursable', money(c.nonreimbursable_total_minor, c.currency)],
			 ['Version', c.version], ['Owner', c.owner_uid]].forEach(function (p) {
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
			eventsHost.innerHTML = '';
			(c.events || []).forEach(function (ev2) {
				eventsHost.appendChild(el('div', { class: 'exp-timeline__item' }, [
					el('span', { class: 'exp-timeline__when', text: fmtDateTime(ev2.occurred_at) }),
					el('span', { text: (ev2.actor_uid || '') + ' ' + (ev2.event_type || '') +
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
			if (c.status === 'draft' || c.status === 'changes_requested') {
				act(t('Submit'), 'exp-btn--primary', function () {
					api('/claims/' + id + '/submit', { method: 'POST', json: { version: ver } })
						.then(reload, E.handleError);
				});
				act(t('Cancel'), 'exp-btn--danger', function () {
					E.confirmDialog(t('Cancel this claim?')).then(function (ok) {
						if (!ok) return;
						api('/claims/' + id + '/cancel', { method: 'POST', json: { version: ver } }).then(reload, E.handleError);
					});
				});
			}
			if (c.my_decision) {
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
			// Receipt attach on own open claims.
			if (c.mine && (c.status === 'draft' || c.status === 'changes_requested')) {
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
		function reload() { return api('/claims/' + id).then(render, E.handleError); }
		reload();
	};

	views.inbox = function () {
		var list = document.getElementById('exp-inbox-list');
		var empty = document.getElementById('exp-inbox-empty');
		api('/inbox').then(function (data) {
			renderList(list, empty, (data && data.claims) || []);
		}, E.handleError);
	};

	views.receipts = function () {
		var list = document.getElementById('exp-receipts-list');
		var empty = document.getElementById('exp-receipts-empty');
		var file = document.getElementById('exp-receipt-file');
		function load() {
			api('/receipts-inbox').then(function (data) {
				list.innerHTML = '';
				var items = (data && data.receipts) || [];
				items.forEach(function (r) {
					list.appendChild(el('div', { class: 'exp-card', role: 'listitem' }, [
						el('strong', { text: r.original_name || ('#' + r.id) }),
						el('p', { class: 'exp-card__meta', text: fmtDateTime(r.captured_at || r.uploaded_at) + ' · ' + (r.mime || '') })
					]));
				});
				empty.hidden = items.length > 0;
			}, E.handleError);
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
		api('/claims?scope=all').then(function (data) {
			renderList(list, null, (data && data.claims) || []);
		}, E.handleError);
	};

	views.settlements = function () {
		var list = document.getElementById('exp-settle-list');
		var form = document.getElementById('exp-settle-form');
		var batches = document.getElementById('exp-batches-list');
		var selected = {};
		api('/settlements').then(function (data) {
			list.innerHTML = '';
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
		}, E.handleError);
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var ref = document.getElementById('exp-paid-ref').value;
			var format = document.getElementById('exp-settle-format').value;
			var ids = Object.keys(selected).map(Number);
			api('/settlements', { method: 'POST', json: { claim_ids: ids, paid_ref: ref, format: format } })
				.then(function () { E.toast('success', t('Batch settled.')); location.reload(); },
					E.handleError);
		});
		api('/settlements/batches').then(function (data) {
			batches.innerHTML = '';
			((data && data.batches) || []).forEach(function (b) {
				var a = el('a', { class: 'exp-card', href: '#' });
				a.textContent = '#' + b.id + ' · ' + fmtDateTime(b.created_at)
					+ ' · ' + t('{n} rows', { n: b.row_count }) + ' · ' + String(b.format || 'csv').toUpperCase();
				a.addEventListener('click', function (ev) {
					ev.preventDefault();
					location.href = E.appBase() + '/settlements/' + b.id;
				});
				batches.appendChild(a);
			});
		}, E.handleError);
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
		api('/settlements/batches/' + id).then(function (d) {
			body.innerHTML = '';
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
				var table = el('table', { class: 'exp-table' });
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
		}, E.handleError);
	};

	views.reports = function () {
		var body = document.getElementById('exp-reports-body');
		api('/reports/summary').then(function (data) {
			body.innerHTML = '';
			var table = el('table', { class: 'exp-table' });
			var thead = el('thead');
			thead.appendChild(el('tr', {}, ['Period', 'Claims', 'Total'].map(function (h) { return el('th', { scope: 'col', text: h }); })));
			table.appendChild(thead);
			var tb = el('tbody');
			((data && data.periods) || []).forEach(function (p) {
				tb.appendChild(el('tr', {}, [
					el('td', { 'data-cell': 'Period', text: p.label || p.id }),
					el('td', { 'data-cell': 'Claims', text: String(p.count || 0) }),
					el('td', { 'data-cell': 'Total', text: money(p.total_minor, p.currency) })
				]));
			});
			table.appendChild(tb);
			body.appendChild(table);
		}, E.handleError);
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
				api(ep).then(function (data) {
					list.innerHTML = '';
					var items = (data && (data.categories || data.cost_centers)) || [];
					items.forEach(function (c) {
						list.appendChild(el('div', { class: 'exp-card' }, [
							el('strong', { text: c.code + ' — ' + c.name }),
							el('p', { class: 'exp-card__meta', text: c.active ? t('Active') : t('Inactive') })
						]));
					});
				}, E.handleError);
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
			api('/admin/rates').then(function (data) {
				list.innerHTML = '';
				((data && data.rates) || []).forEach(function (rt) {
					list.appendChild(el('div', { class: 'exp-card' }, [
						el('strong', { text: rt.code + ' — ' + rt.name }),
						el('p', { class: 'exp-card__meta', text: money(rt.rate_minor, rt.currency) + ' · ' + rt.rate_type + ' · ' + fmtDate(rt.effective_from) })
					]));
				});
			}, E.handleError);
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
		api('/admin/chains').then(function (data) {
			list.innerHTML = '';
			((data && data.chains) || []).forEach(function (ch) {
				list.appendChild(el('div', { class: 'exp-card' }, [
					el('strong', { text: ch.name }),
					el('p', { class: 'exp-card__meta', text: (ch.steps || []).map(function (s) { return s.approver_uid; }).join(' → ') })
				]));
			});
		}, E.handleError);
	};

	views.settings = function () {
		var body = document.getElementById('exp-settings-body');
		api('/settings').then(function (s) {
			body.innerHTML = '';
			var form = el('form', { class: 'exp-form' });
			var KEYS = ['org_currency', 'enable_mileage_claims', 'allow_route_evidence',
				'allow_self_approve', 'attachment_max_mb', 'inbox_purge_days',
				'retention_years', 'digest_cadence'];
			KEYS.forEach(function (k) {
				if (!(k in s)) return;
				var wrap = el('div', { class: 'exp-field' });
				var id = 'exp-set-' + k;
				wrap.appendChild(el('label', { class: 'exp-label', 'for': id, text: k.replace(/_/g, ' ') }));
				var v = s[k];
				if (v === true || v === false || v === 1 || v === 0) {
					var hid = el('input', { type: 'hidden', name: k, value: '0' });
					var cb = el('input', { type: 'checkbox', id: id, name: k, value: '1' });
					cb.checked = !!v && v !== '0';
					wrap.appendChild(hid);
					wrap.appendChild(cb);
				} else {
					wrap.appendChild(el('input', { class: 'exp-input', id: id, name: k, value: String(v) }));
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
		}, E.handleError);
	};

	views.license = function () {
		var body = document.getElementById('exp-license-body');
		api('/admin/license').then(function (d) {
			body.innerHTML = '';
			body.appendChild(el('p', { text: t('Status') + ': ' + (d.status || t('none')) }));
			if (d.seats !== undefined) body.appendChild(el('p', { text: t('Seats') + ': ' + d.seats }));
		}, E.handleError);
	};

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.querySelector('[data-exp-view]');
		if (!root) return;
		var name = root.getAttribute('data-exp-view');
		if (views[name]) views[name](root);
	});
})();
