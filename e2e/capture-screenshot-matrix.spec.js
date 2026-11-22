// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface declared in e2e/screenshot-matrix.json through the
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/expensecheck/craft/web/. The companion
 * gate .cursor/atlas-farm-v3/scripts/check-screenshot-matrix.py verifies
 * completeness — a declared cell without a real PNG fails the craft stage.
 *
 * States:
 *   populated — real fixture rows. The claim fixture is created through the
 *               app's own API (draft + receipt line + allocation); the
 *               settlement-batch fixture is driven through the REAL
 *               lifecycle (submit → approve → settle) so the batch surface
 *               never renders fabricated data.
 *   empty     — honest zero-row render driven through the app's own filter
 *               UI (status filter → a state the fixture does not match).
 *   current   — state-invariant chrome (forms, settings, license).
 *
 * Theme is applied client-side on <body> exactly the way Nextcloud theming
 * emits it (theme--*, data-theme-*).
 *
 * Run (from the app dir):
 *   npx playwright test -c e2e/playwright.screenshot-matrix.config.mjs
 */
import { test, expect } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, primaryCreds, credsFromEnv, assertNotNcDeadEnd } from '../tests/e2e/helpers/auth.js'

const __dirname = dirname(fileURLToPath(import.meta.url))
const MANIFEST = JSON.parse(readFileSync(join(__dirname, 'screenshot-matrix.json'), 'utf8'))
const OUT = resolve(
	__dirname,
	'../../../../.cursor/atlas-farm-v3/artifacts/expensecheck',
	MANIFEST.outDir,
)
const APP = '/index.php/apps/expensecheck'
const API = `${APP}/api`

const fixtures = { claimId: 0, batchId: 0, pendingId: 0 }
const captured = []

/** Session-authed JSON call executed inside the logged-in page. */
async function api(page, path, method = 'GET', body) {
	return page.evaluate(async ({ url, method, body }) => {
		const token =
			(typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { Accept: 'application/json', requesttoken: token, 'OCS-APIRequest': 'true' }
		const init = { method, credentials: 'same-origin', headers }
		if (body !== undefined) {
			headers['Content-Type'] = 'application/json'
			init.body = JSON.stringify(body)
		}
		const res = await fetch(url, init)
		let data = null
		try {
			data = await res.json()
		} catch {
			data = null
		}
		return { status: res.status, data }
	}, { url: path, method, body })
}

/**
 * Periods are CLOSED windows — a line date is valid only when it falls
 * outside every closed period. Resolve an open date from the live period
 * list rather than assuming one.
 */
async function openLineDate(page) {
	const periods = (await api(page, `${API}/periods`)).data?.periods || []
	const closed = periods
		.filter((p) => p.status === 'closed')
		.map((p) => [p.start_on, p.end_on])
	let lineDate = '2026-10-15'
	for (let i = 0; i < 40 && closed.some(([a, b]) => lineDate >= a && lineDate <= b); i++) {
		const d = new Date(lineDate)
		d.setDate(d.getDate() - 1)
		lineDate = d.toISOString().slice(0, 10)
	}
	return lineDate
}

/** Lookup ids, seeding the E2E category/cost-center when missing. */
async function ensureLookups(page) {
	let lk = await api(page, `${API}/lookups`)
	if (!(lk.data?.categories || []).length) {
		await api(page, `${API}/admin/categories`, 'POST', {
			name: 'E2E Meals', code: 'e2e-meals', allow_lines: 'receipt',
		})
	}
	if (!(lk.data?.cost_centers || []).length) {
		await api(page, `${API}/admin/cost-centers`, 'POST', {
			name: 'E2E CC', code: 'E2E-CC',
		})
	}
	lk = await api(page, `${API}/lookups`)
	return {
		categories: lk.data?.categories || [],
		costCenters: lk.data?.cost_centers || [],
	}
}

/** Session-authed multipart upload inside the logged-in page. */
async function apiUpload(page, path, filename, b64, mime) {
	return page.evaluate(async ({ url, filename, b64, mime }) => {
		const token =
			(typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| ''
		const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
		const fd = new FormData()
		fd.append('file', new File([bytes], filename, { type: mime }))
		const res = await fetch(url, {
			method: 'POST', credentials: 'same-origin', body: fd,
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
		})
		let data = null
		try { data = await res.json() } catch { data = null }
		return { status: res.status, data }
	}, { url: path, filename, b64, mime })
}

// 64×32 valid PNG — the receipts inbox re-encodes rasters, so the upload
// must survive a real decode, not just a mime label.
const RECEIPT_PNG_B64 =
	'iVBORw0KGgoAAAANSUhEUgAAAEAAAAAgCAIAAAAt/+nTAAAATklEQVR4nO3PUQkAIBTAwFfMSmYyqiH8OITBAtxm7fN1wwUNaEEDWtCAFjSgBQ1oQQNa0IAWNKAFDWhBA1rQgBY0oAUNaEEDWtCAFjx2Ac/nWJfbk2nvAAAAAElFTkSuQmCC'

/** Honest populated receipts inbox — an unattached upload owned by the
 * capture user. */
async function resolveReceiptInbox(page) {
	const cur = await api(page, `${API}/receipts-inbox`)
	if ((cur.data?.receipts || []).length) {
		return
	}
	const up = await apiUpload(page, `${API}/receipts-inbox`, 'e2e-receipt.png', RECEIPT_PNG_B64, 'image/png')
	expect(up.status, `receipt upload failed: ${JSON.stringify(up.data)}`).toBe(201)
}

/**
 * Honest populated inbox: ApprovalService::inboxFor skips the caller's own
 * claims, so the capture user needs a claim submitted by a SECOND persona
 * (e2e-noaccess) whose frozen chain step routes to them. Routing is pinned
 * with a dedicated category-scoped E2E chain (sort -1 first-match but only
 * for its own category) so ordinary claims keep their configured route.
 */
async function resolvePendingInbox(page, browser) {
	const inbox = await api(page, `${API}/inbox`)
	fixtures.pendingId = inbox.data?.claims?.[0]?.id || 0
	if (fixtures.pendingId) {
		return
	}
	const noaccess = credsFromEnv('E2E_NOACCESS')
	expect(noaccess, 'NC_E2E_NOACCESS_* creds required for the inbox fixture').toBeTruthy()

	const myUid = await page.evaluate(() =>
		(window.OC && typeof window.OC.getCurrentUser === 'function' && window.OC.getCurrentUser().uid) || '')
	expect(myUid, 'could not resolve capture uid (OC.getCurrentUser)').toBeTruthy()

	// Reuse an existing chain that resolves a step to me; otherwise mint one
	// scoped to a dedicated category so nothing else re-routes.
	const chains = (await api(page, `${API}/admin/chains`)).data?.chains || []
	const mine = chains.find((c) => c.active && (c.steps || [])
		.some((s) => s.step_type === 'user' && s.user_id === myUid))
	let catId = mine?.category_id || 0
	if (!mine) {
		const { categories, costCenters } = await ensureLookups(page)
		void costCenters
		const made = await api(page, `${API}/admin/categories`, 'POST', {
			name: 'E2E Inbox Route', code: `e2e-inbox-${Date.now().toString(36)}`, allow_lines: 'receipt',
		})
		void made
		const lk = await api(page, `${API}/lookups`)
		catId = (lk.data?.categories || []).find((c) => String(c.code || '').startsWith('e2e-inbox-'))?.id
			|| categories[0]?.id || 0
		const res = await api(page, `${API}/admin/chains`, 'POST', {
			name: 'E2E Inbox Route', active: '1', sort: -1, category_id: catId,
			steps: [{ step_type: 'user', user_id: myUid }],
		})
		expect(res.data?.ok, `E2E inbox chain create failed: ${JSON.stringify(res.data)}`).toBeTruthy()
	}
	if (!catId) {
		catId = (await ensureLookups(page)).categories[0]?.id || 0
	}
	const { costCenters } = await ensureLookups(page)
	const ccId = costCenters[0]?.id || 0
	const lineDate = await openLineDate(page)

	const ctx = await browser.newContext()
	const np = await ctx.newPage()
	try {
		await login(np, noaccess)
		await np.goto(APP, { waitUntil: 'domcontentloaded' })
		const created = await api(np, `${API}/claims`, 'POST', {
			title: 'E2E inbox pending claim',
			currency: 'EUR',
			request_key: `e2e-inbox-${Date.now()}`,
			lines: [{
				line_type: 'receipt', occurred_on: lineDate,
				category_id: catId, merchant: 'E2E Taxi', amount: '7.25',
				allocations: [{ cost_center_id: ccId, share_percent: '100' }],
			}],
		})
		const pid = created.data?.claim?.id || 0
		expect(pid, `pending claim create failed: ${JSON.stringify(created.data)}`).toBeGreaterThan(0)
		const det = await api(np, `${API}/claims/${pid}`)
		const sub = await api(np, `${API}/claims/${pid}/submit`, 'POST', {
			version: det.data?.claim?.version,
		})
		expect(sub.data?.ok !== false, `pending claim submit failed: ${JSON.stringify(sub.data)}`).toBeTruthy()
	} finally {
		await ctx.close()
	}

	const after = await api(page, `${API}/inbox`)
	fixtures.pendingId = after.data?.claims?.[0]?.id || 0
}

/**
 * Resolve real ids for {placeholder} surface paths via the app's own API.
 * Seeds a category/cost-center when missing, creates a real claim with a
 * receipt line, then drives the honest lifecycle to produce a settlement
 * batch (submit → approve → settle). Fails loudly — a missing fixture means
 * the prep stack is incomplete, which is real signal, not a skipped cell.
 */
async function resolveFixtures(page, browser) {
	// Existing claim wins — the e2e user may already have fixture data.
	const claims = await api(page, `${API}/claims?limit=1`)
	fixtures.claimId = claims.data?.claims?.[0]?.id || claims.data?.data?.[0]?.id || 0

	if (!fixtures.claimId) {
		const lineDate = await openLineDate(page)
		const { categories, costCenters } = await ensureLookups(page)
		const catId = categories[0]?.id || 0
		const ccId = costCenters[0]?.id || 0
		const created = await api(page, `${API}/claims`, 'POST', {
			title: 'E2E matrix fixture claim',
			currency: 'EUR',
			request_key: `e2e-matrix-${Date.now()}`,
			lines: [{
				line_type: 'receipt',
				occurred_on: lineDate,
				category_id: catId,
				merchant: 'E2E Café',
				amount: '12.50',
				allocations: [{ cost_center_id: ccId, share_percent: '100' }],
			}],
		})
		fixtures.claimId = created.data?.claim?.id || 0
	}

	await resolvePendingInbox(page, browser)
	await resolveReceiptInbox(page)

	const batches = await api(page, `${API}/settlements/batches`)
	fixtures.batchId = batches.data?.batches?.[0]?.id || 0

	if (!fixtures.batchId && fixtures.claimId) {
		// Drive the honest lifecycle: submit → approve → settle.
		const detail = await api(page, `${API}/claims/${fixtures.claimId}`)
		const claim = detail.data?.claim
		if (claim && claim.status === 'draft' && (claim.lines || []).length) {
			await api(page, `${API}/claims/${fixtures.claimId}/submit`, 'POST', {
				version: claim.version,
			})
		}
		const after = await api(page, `${API}/claims/${fixtures.claimId}`)
		const submitted = after.data?.claim
		if (submitted && submitted.status === 'submitted') {
			await api(page, `${API}/claims/${fixtures.claimId}/decisions`, 'POST', {
				decision: 'approve',
				version: submitted.version,
			})
		}
		const final = await api(page, `${API}/claims/${fixtures.claimId}`)
		const approved = final.data?.claim
		if (approved && approved.status === 'approved') {
			const settled = await api(page, `${API}/settlements`, 'POST', {
				claim_ids: [fixtures.claimId],
				paid_ref: `E2E-BATCH-${Date.now()}`,
				format: 'csv',
			})
			fixtures.batchId = settled.data?.batch?.id || 0
		}
	}
}

function surfacePath(surface) {
	return surface.path
		.replace('{claimId}', String(fixtures.claimId))
		.replace('{batchId}', String(fixtures.batchId))
		.replace('{pendingId}', String(fixtures.pendingId))
}

function shotName(surface, state, vp, theme) {
	return `${MANIFEST.prefix}-${surface.id}-${state}-${vp.name}${theme === 'dark' ? '-dark' : ''}.png`
}

function* matrixCells(theme) {
	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states) {
			for (const vp of MANIFEST.viewports) {
				if (theme === 'dark' && (!surface.dark || vp.name !== 'desktop' || state !== surface.states[0])) {
					continue
				}
				yield { surface, state, vp }
			}
		}
	}
}

/**
 * Load-error states: the list surface fetches a 500 through route
 * interception and must render its own error panel + retry — the shared
 * CheckViewComponents.listFetch chrome (.exp-empty-state--error inside the
 * list host).
 */
const LOAD_ERROR_ROUTES = {
	claims: /\/apps\/expensecheck\/api\/claims(\?.*)?$/,
	inbox: /\/apps\/expensecheck\/api\/inbox(\?.*)?$/,
}

/**
 * Dialog states on the pending claim (capture user holds decision rights):
 * the action buttons are structural-only — a decidable claim renders exactly
 * Approve (primary) / Request changes (secondary) / Reject (danger).
 */
const DIALOG_BUTTON = {
	'dialog-approve': '#exp-detail-actions .exp-btn--primary',
	'dialog-changes': '#exp-detail-actions .exp-btn--secondary',
	'dialog-reject': '#exp-detail-actions .exp-btn--danger',
}

/** native_dialog_margin_reset_uncentered: the pack re-declares margin:auto
 * on dialog.exp-dialog[open] — assert real both-axis centering, not just
 * "dialog exists". */
async function assertDialogCentered(dlg, where) {
	const m = await dlg.evaluate((d) => {
		const r = d.getBoundingClientRect()
		return { l: r.left, t: r.top, w: r.width, h: r.height, vw: window.innerWidth, vh: window.innerHeight }
	})
	expect(m.w, `${where}: dialog has zero width`).toBeGreaterThan(0)
	const expL = Math.max(0, (m.vw - m.w) / 2)
	const expT = Math.max(0, (m.vh - m.h) / 2)
	expect(Math.abs(m.l - expL),
		`${where}: dialog left ${Math.round(m.l)} ≠ centered ${Math.round(expL)} — margin:auto lost to the NC reset`)
		.toBeLessThanOrEqual(2)
	expect(Math.abs(m.t - expT),
		`${where}: dialog top ${Math.round(m.t)} ≠ centered ${Math.round(expT)}`)
		.toBeLessThanOrEqual(2)
}

async function driveDialog(page, surface, state) {
	const sel = DIALOG_BUTTON[state]
	if (!sel) {
		throw new Error(`surface ${surface.id}: no button driver for ${state}`)
	}
	await expect(page.locator(sel), `${surface.id}: ${state} button absent — pending claim lacks canDecide`)
		.toBeVisible({ timeout: 15_000 })
	await page.locator(sel).click()
	const dlg = page.locator('dialog.exp-dialog[open]')
	await expect(dlg, `${surface.id}: ${state} did not open a top-layer dialog`).toBeVisible({ timeout: 10_000 })
	await assertDialogCentered(dlg, `${surface.id} ${state}`)
}

/**
 * Access-denied cells: tighten the real access policy through the admin API
 * (restriction on, only the capture uid allowed), open a second context as
 * the no-access fixture user, assert the 403 access-denied surface, then
 * restore the prior policy in finally — even when the cell fails.
 */
async function captureDeniedCells(page, browser, surface, theme) {
	const noaccess = credsFromEnv('E2E_NOACCESS')
	expect(noaccess, 'NC_E2E_NOACCESS_* creds required for access-denied cells').toBeTruthy()
	const myUid = await page.evaluate(() =>
		(window.OC && typeof window.OC.getCurrentUser === 'function' && window.OC.getCurrentUser().uid) || '')
	const prev = (await api(page, `${API}/admin/access`)).data?.access || {}
	try {
		const deny = await api(page, `${API}/admin/access`, 'POST', {
			restriction_enabled: '1',
			allowed_user_ids: [myUid],
			allowed_group_ids: [],
			app_admin_user_ids: [myUid],
		})
		expect(deny.data?.ok !== false, `access policy tighten failed: ${JSON.stringify(deny.data)}`).toBeTruthy()

		// one dark cell per surface: primary state at desktop width
		const vps = theme === 'dark'
			? MANIFEST.viewports.filter((v) => v.name === 'desktop')
			: MANIFEST.viewports
		for (const vp of vps) {
			const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } })
			const np = await ctx.newPage()
			try {
				await login(np, noaccess)
				const resp = await np.goto(APP, { waitUntil: 'domcontentloaded' })
				// deny_page_status_200: the denied surface must fail closed at
				// HTTP level — a 200 "denied" page is an existence oracle.
				expect(resp?.status(), 'access-denied page must answer 403').toBe(403)
				await expect(np.locator(surface.ready)).toBeVisible({ timeout: 15_000 })
				if (theme === 'dark') {
					await applyTheme(np, 'dark')
				}
				await np.evaluate(() => new Promise((r) =>
					requestAnimationFrame(() => requestAnimationFrame(r))))
				const state = surface.states?.[0] || 'restricted'
				const name = shotName(surface, state, vp, theme)
				await np.screenshot({ path: join(OUT, name) })
				captured.push({
					file: name, surface: surface.id, state,
					viewport: vp.name, theme, rendered: 'denied-403',
				})
			} finally {
				await ctx.close()
			}
		}
	} finally {
		const restore = await api(page, `${API}/admin/access`, 'POST', {
			restriction_enabled: prev.restriction_enabled ? '1' : '0',
			allowed_user_ids: prev.allowed_user_ids || [],
			allowed_group_ids: prev.allowed_group_ids || [],
			app_admin_user_ids: prev.app_admin_user_ids || [],
		})
		expect(restore.data?.ok !== false,
			`access policy RESTORE failed: ${JSON.stringify(restore.data)}`).toBeTruthy()
	}
}

/** Drive a list surface into its honest empty state through the real filter UI. */
async function driveEmpty(page, surface) {
	if (surface.emptyDriver !== 'statusFilter') {
		throw new Error(`surface ${surface.id}: unknown emptyDriver ${surface.emptyDriver}`)
	}
	const bar = page.locator(surface.filterSel)
	await expect(bar).toBeVisible({ timeout: 15_000 })
	// The capture user accumulates claims across runs — click the app's own
	// recipe-B filter pills until one yields the honest zero-row render.
	for (const status of ['changes_requested', 'rejected', 'approved', 'settled']) {
		await bar.locator(`button[data-value="${status}"]`).click()
		if (await page.locator('#exp-claims-empty')
			.isVisible({ timeout: 8_000 }).catch(() => false)) return
	}
	throw new Error(`${surface.id}: no status filter produced the empty state`)
}

/** Apply a Nextcloud theme to <body> the way core theming emits it. */
async function applyTheme(page, themeId) {
	await page.evaluate((id) => {
		const body = document.body
		;['theme--light', 'theme--dark', 'theme--dark-highcontrast', 'theme--light-highcontrast']
			.forEach((c) => body.classList.remove(c))
		;['data-theme-default', 'data-theme-light', 'data-theme-dark', 'data-theme-dark-highcontrast', 'data-theme-light-highcontrast']
			.forEach((a) => body.removeAttribute(a))
		if (id === 'dark') {
			body.classList.add('theme--dark')
			body.setAttribute('data-theme-dark', 'true')
			body.setAttribute('data-themes', 'dark')
		} else {
			body.classList.add('theme--light')
			body.setAttribute('data-theme-light', 'true')
			body.setAttribute('data-themes', 'default')
		}
		document.documentElement.style.colorScheme = id === 'dark' ? 'dark' : 'light'
	}, themeId)
	await page.evaluate(() => new Promise((r) =>
		requestAnimationFrame(() => requestAnimationFrame(r))))
}

test.describe('SCREENSHOT_MATRIX capture', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(30 * 60_000)

	test('resolves fixture ids', async ({ page, browser }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* creds (tests/e2e/.env)')
		await login(page, creds)
		await page.goto(APP, { waitUntil: 'domcontentloaded' })
		await assertNotNcDeadEnd(page, 'matrix fixture resolution')
		await expect(page.locator('#exp-main-content')).toBeVisible({ timeout: 30_000 })
		await resolveFixtures(page, browser)
		expect(fixtures.claimId, 'no claim row and API seed failed — run npm run e2e:prep')
			.toBeGreaterThan(0)
		expect(fixtures.batchId,
			'no settlement batch and lifecycle drive failed — check allow_self_approve + finance role (e2e-prep)')
			.toBeGreaterThan(0)
		expect(fixtures.pendingId,
			'inbox fixture unresolved — e2e-noaccess claim did not route to the capture user (chain/category/self-approve)')
			.toBeGreaterThan(0)
	})

	test('captures every manifest cell', async ({ page, browser }) => {
		test.skip(!fixtures.claimId, 'fixture resolution failed')
		const creds = primaryCreds()
		await login(page, creds)
		mkdirSync(OUT, { recursive: true })
		const missing = []
		const deniedDone = new Set()

		for (const theme of ['light', 'dark']) {
			for (const { surface, state, vp } of matrixCells(theme)) {
				if (surface.id === 'access-denied') {
					// One policy mutation per theme — the routine iterates the
					// viewport cells itself and restores the policy in finally.
					if (!deniedDone.has(theme)) {
						deniedDone.add(theme)
						await captureDeniedCells(page, browser, surface, theme)
					}
					continue
				}
				await page.setViewportSize({ width: vp.width, height: vp.height })
				// load-error must intercept before navigation — the list fetch
				// fires on page load.
				const failPattern = state === 'load-error' ? LOAD_ERROR_ROUTES[surface.id] : null
				if (failPattern) {
					await page.route(failPattern, (route) => route.fulfill({
						status: 500,
						contentType: 'application/json',
						body: '{"ok":false,"error":{"code":"E2E_MATRIX_FAIL"}}',
					}))
				}
				await page.goto(`${APP}${surfacePath(surface)}`, { waitUntil: 'domcontentloaded' })
				await assertNotNcDeadEnd(page, `matrix ${surface.id}`)
				const ready = page.locator(surface.ready).first()
				await expect(ready, `${surface.id}: ready ${surface.ready}`).toBeVisible({ timeout: 30_000 })
				await expect(page.locator('#app-content [aria-busy="true"]'))
					.toHaveCount(0, { timeout: 30_000 })
				if (theme === 'dark') {
					await applyTheme(page, 'dark')
				}
				let rendered = 'content'
				if (state === 'empty') {
					await driveEmpty(page, surface)
					rendered = 'empty'
				} else if (state === 'load-error') {
					const errSel = `${surface.listSel || '#exp-main-content'} .exp-empty-state--error`
					await expect(page.locator(errSel).first(),
						`${surface.id}: 500 did not render the listFetch error panel`)
						.toBeVisible({ timeout: 20_000 })
					rendered = 'load-error'
				} else if (state.startsWith('dialog-')) {
					await driveDialog(page, surface, state)
					rendered = 'dialog'
				} else if (state === 'populated' && surface.listSel) {
					const rows = page.locator(
						`${surface.listSel} tbody tr:visible, ${surface.listSel} .exp-card:visible, ${surface.listSel} .exp-row:visible, ${surface.listSel} div[role='listitem']:visible`,
					)
					const empty = page.locator(`${surface.listSel} .exp-empty:visible, ${surface.listSel} ~ .exp-empty:visible`)
					// Wait until the async fetch resolves into either rows or the
					// app's own empty state — never capture mid-render.
					const rowVisible = await rows.first()
						.waitFor({ state: 'visible', timeout: 20_000 })
						.then(() => true).catch(() => false)
					const emptyVisible = rowVisible ? false : await empty.first()
						.waitFor({ state: 'visible', timeout: 5_000 })
						.then(() => true).catch(() => false)
					expect(rowVisible || emptyVisible, `${surface.id}: neither rows nor empty state rendered`).toBeTruthy()
					rendered = rowVisible ? 'rows' : 'empty'
				}
				await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
				await page.evaluate(() => new Promise((r) =>
					requestAnimationFrame(() => requestAnimationFrame(r))))
				const contentH = await page.evaluate(() =>
					(document.querySelector('#app-content') || document.documentElement).scrollHeight)
				const shotH = Math.min(Math.max(contentH + 60, vp.height), 16384)
				const clipped = contentH + 60 > 16384
				if (shotH !== vp.height) {
					await page.setViewportSize({ width: vp.width, height: shotH })
					await page.evaluate(() => new Promise((r) =>
						requestAnimationFrame(() => requestAnimationFrame(r))))
				}
				const name = shotName(surface, state, vp, theme)
				await page.screenshot({ path: join(OUT, name) })
				captured.push({ file: name, surface: surface.id, state, viewport: vp.name, theme, rendered, contentH, shotH, clipped })
				if (!existsSync(join(OUT, name))) {
					missing.push(name)
				}
				if (failPattern) {
					await page.unroute(failPattern)
				}
				if (theme === 'dark') {
					await applyTheme(page, 'light')
				}
			}
		}

		writeFileSync(
			resolve(OUT, '_manifest-screenshot-matrix.json'),
			JSON.stringify(
				{
					app: 'expensecheck',
					lane: 'ds_chrome',
					generated_by: 'e2e/capture-screenshot-matrix.spec.js',
					generated_at: new Date().toISOString(),
					fixtures,
					cells: captured,
				},
				null,
				2,
			),
		)
		expect(missing, `matrix cells missing: ${missing.join(', ')}`).toEqual([])
	})
})
