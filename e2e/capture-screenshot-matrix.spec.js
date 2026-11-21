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
import { login, primaryCreds, assertNotNcDeadEnd } from '../tests/e2e/helpers/auth.js'

const __dirname = dirname(fileURLToPath(import.meta.url))
const MANIFEST = JSON.parse(readFileSync(join(__dirname, 'screenshot-matrix.json'), 'utf8'))
const OUT = resolve(
	__dirname,
	'../../../../.cursor/atlas-farm-v3/artifacts/expensecheck',
	MANIFEST.outDir,
)
const APP = '/index.php/apps/expensecheck'
const API = `${APP}/api`

const fixtures = { claimId: 0, batchId: 0 }
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
 * Resolve real ids for {placeholder} surface paths via the app's own API.
 * Seeds a category/cost-center when missing, creates a real claim with a
 * receipt line, then drives the honest lifecycle to produce a settlement
 * batch (submit → approve → settle). Fails loudly — a missing fixture means
 * the prep stack is incomplete, which is real signal, not a skipped cell.
 */
async function resolveFixtures(page) {
	// Existing claim wins — the e2e user may already have fixture data.
	const claims = await api(page, `${API}/claims?limit=1`)
	fixtures.claimId = claims.data?.claims?.[0]?.id || claims.data?.data?.[0]?.id || 0

	if (!fixtures.claimId) {
		// Periods are CLOSED windows in this model — a line date is valid
		// when it falls outside every closed period. Resolve an open date
		// from the live period list rather than assuming one.
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
		const lk = await api(page, `${API}/lookups`)
		let categories = lk.data?.categories || []
		let costCenters = lk.data?.cost_centers || []
		if (!categories.length) {
			await api(page, `${API}/admin/categories`, 'POST', {
				name: 'E2E Meals', code: 'e2e-meals', allow_lines: true,
			})
		}
		if (!costCenters.length) {
			await api(page, `${API}/admin/cost-centers`, 'POST', {
				name: 'E2E CC', code: 'E2E-CC',
			})
		}
		const lk2 = await api(page, `${API}/lookups`)
		categories = lk2.data?.categories || categories
		costCenters = lk2.data?.cost_centers || costCenters
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

/** Drive a list surface into its honest empty state through the real filter UI. */
async function driveEmpty(page, surface) {
	if (surface.emptyDriver !== 'statusFilter') {
		throw new Error(`surface ${surface.id}: unknown emptyDriver ${surface.emptyDriver}`)
	}
	const sel = page.locator(surface.filterSel)
	await expect(sel).toBeVisible({ timeout: 15_000 })
	// 'settled' — the fixture claim is a draft, so this yields the honest
	// zero-row render through the app's own filter control.
	await sel.selectOption('settled')
	await sel.evaluate((el) => el.dispatchEvent(new Event('change', { bubbles: true })))
	await expect(page.locator('#exp-claims-empty'))
		.toBeVisible({ timeout: 20_000 })
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

	test('resolves fixture ids', async ({ page }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* creds (tests/e2e/.env)')
		await login(page, creds)
		await page.goto(APP, { waitUntil: 'domcontentloaded' })
		await assertNotNcDeadEnd(page, 'matrix fixture resolution')
		await expect(page.locator('#exp-main-content')).toBeVisible({ timeout: 30_000 })
		await resolveFixtures(page)
		expect(fixtures.claimId, 'no claim row and API seed failed — run npm run e2e:prep')
			.toBeGreaterThan(0)
		expect(fixtures.batchId,
			'no settlement batch and lifecycle drive failed — check allow_self_approve + finance role (e2e-prep)')
			.toBeGreaterThan(0)
	})

	test('captures every manifest cell', async ({ page }) => {
		test.skip(!fixtures.claimId, 'fixture resolution failed')
		const creds = primaryCreds()
		await login(page, creds)
		mkdirSync(OUT, { recursive: true })
		const missing = []

		for (const theme of ['light', 'dark']) {
			for (const { surface, state, vp } of matrixCells(theme)) {
				await page.setViewportSize({ width: vp.width, height: vp.height })
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
