// Gate 6 / §12 — sibling-absent standalone proof. ExpenseCheck must run a
// complete claim pipeline with arbeitszeitcheck, mobilitycheck and
// customercheck DISABLED: surfaces render, the settings GET stays intact,
// create→line→submit→withdraw works, and every soft-flange endpoint
// degrades to empty/fail-closed instead of 500.
//
// Restoration is mandatory: afterAll re-enables the siblings and verifies
// they came back — a crashed run must never leave the dev door shut.

import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, primaryCreds } from './helpers/auth.js'

const APP = '/index.php/apps/expensecheck'
const NC_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..')
const SIBLINGS = ['arbeitszeitcheck', 'mobilitycheck', 'customercheck']

function occ(args) {
	return execFileSync('docker', ['compose', 'exec', '-T', 'nextcloud', 'php', 'occ', ...args],
		{ cwd: NC_DIR, encoding: 'utf8' }).trim()
}
function sql(query) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', 'mariadb',
		'mariadb', '-unextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-e', query,
	], { cwd: NC_DIR, encoding: 'utf8' })
}
function appEnabled(app) {
	// app:list prints Enabled:/Disabled: sections — match only the enabled
	// block, disabled apps still appear by name further down.
	try {
		const out = occ(['app:list', '--output=json'])
		const parsed = JSON.parse(out)
		return Boolean(parsed.enabled?.[app])
	} catch {
		// JSON mode unavailable → fall back to section parsing.
		try {
			const out = occ(['app:list'])
			const enabled = out.split(/Disabled:/)[0]
			return enabled.includes(`- ${app}:`)
		} catch { return false }
	}
}

test.describe.configure({ mode: 'serial' })
test.describe('sibling-absent standalone (gate 6)', () => {
	/** @type {import('@playwright/test').BrowserContext} */
	let context
	/** @type {import('@playwright/test').Page} */
	let page
	/** @type {string[]} apps we disabled — only re-enable what we turned off */
	let disabled = []
	let claimId = null
	let token = ''

	const apiHeaders = () => ({
		'requesttoken': token,
		'OCS-APIREQUEST': 'true',
	})

	test.beforeAll(async ({ browser }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials')

		// Seed a standalone category + rate (no sibling involvement).
		sql(`DELETE FROM oc_exp_categories WHERE code='SABS-FLAT'`)
		sql(`INSERT INTO oc_exp_categories (code, name, receipt_required, requires_attendees,
			allow_lines, active, sort, deleted)
			VALUES ('SABS-FLAT', 'Standalone flat', 0, 0, 'receipt,mileage,per_diem,flat', 1, 99, 0)`)
		sql(`INSERT INTO oc_exp_rate_entries (rate_type, code, name, currency, rate_minor,
			effective_from, active, created_by, created_at)
			VALUES ('flat', 'SABS-FLAT', 'Standalone flat rate', 'EUR', 500,
			'2020-01-01', 1, 'e2e', NOW())`)
		sql(`INSERT INTO oc_exp_rate_entries (rate_type, code, name, currency, rate_minor,
			effective_from, active, created_by, created_at)
			VALUES ('mileage', 'SABS-KM', 'Standalone km rate', 'EUR', 30,
			'2020-01-01', 1, 'e2e', NOW())`)
		sql(`DELETE FROM oc_exp_cost_centers WHERE code='SABS-CC'`)
		sql(`INSERT INTO oc_exp_cost_centers (code, name, active, deleted)
			VALUES ('SABS-CC', 'Standalone cost center', 1, 0)`)

		disabled = SIBLINGS.filter(appEnabled)
		for (const app of disabled) {
			occ(['app:disable', app])
		}
		// Defensive: the door is actually shut before the journey starts.
		for (const app of disabled) {
			expect(appEnabled(app), `${app} still enabled after disable`).toBe(false)
		}

		context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
		page = await context.newPage()
		await login(page, creds)
		// API mutations need the session CSRF token — same contract the
		// app's own api() helper uses (requesttoken + OCS-APIREQUEST).
		await page.goto(`${APP}/claims`)
		token = await page.evaluate(() => window.OC?.requestToken
			|| document.querySelector('meta[name="requesttoken"]')?.content || '')
	})

	test.afterAll(async () => {
		// Restore FIRST — even on failure the siblings come back.
		for (const app of disabled) {
			try { occ(['app:enable', app]) } catch (e) { console.error(`re-enable ${app} failed`, e) }
		}
		for (const app of disabled) {
			expect(appEnabled(app), `${app} failed to re-enable`).toBe(true)
		}
		sql(`DELETE FROM oc_exp_categories WHERE code='SABS-FLAT'`)
		sql(`DELETE FROM oc_exp_rate_entries WHERE code IN ('SABS-FLAT','SABS-KM')`)
		sql(`DELETE FROM oc_exp_cost_centers WHERE code='SABS-CC'`)
		await context?.close()
	})

	test('core surfaces render with no siblings installed', async () => {
		for (const path of ['/', '/claims', '/settings']) {
			const resp = await page.goto(`${APP}${path}`)
			expect(resp?.status(), path).toBe(200)
			await page.waitForSelector('#app-content', { timeout: 15000 })
		}
		// Settings page must render real controls standalone (the dead-view
		// regression guard).
		await page.goto(`${APP}/settings`)
		await expect(page.locator('#exp-set-enable_mileage_claims')).toBeVisible()
	})

	test('settings GET parity holds standalone', async () => {
		const resp = await page.request.get(`${APP}/api/settings`)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body.ok).toBe(true)
		const s = body.settings ?? body.data?.settings ?? body.data
		expect(s).toHaveProperty('enable_mileage_claims')
	})

	test('create → line → submit → withdraw works fully standalone', async () => {
		const cat = sql(`SELECT id FROM oc_exp_categories WHERE code='SABS-FLAT'`).trim()
		const cc = sql(`SELECT id FROM oc_exp_cost_centers WHERE code='SABS-CC'`).trim()
		const create = await page.request.post(`${APP}/api/claims`, {
			headers: apiHeaders(),
			data: {
				title: 'Sibling-absent pipeline', currency: 'EUR',
				request_key: `sabs-${Date.now()}`,
				lines: [{
					line_type: 'flat', category_id: Number(cat),
					occurred_on: '2027-06-15', qty: '2', rate_code: 'SABS-FLAT',
					allocations: [{ cost_center_id: Number(cc), share_percent: '100' }],
				}],
			},
		})
		const body = await create.json()
		expect(create.status(), JSON.stringify(body)).toBe(201)
		expect(body.ok).toBe(true)
		claimId = body.claim.id
		expect(body.claim.lines[0].amount_minor).toBe(1000)

		const submit = await page.request.post(`${APP}/api/claims/${claimId}/submit`, { headers: apiHeaders() })
		expect([200, 422].includes(submit.status())).toBe(true)
		const submitted = await submit.json()
		// With no chains configured the claim routes to admin-review
		// (NN-09 fail-closed) — submitted either way, never an error.
		expect(submitted.ok).toBe(true)

		const show = await page.request.get(`${APP}/api/claims/${claimId}`)
		const detail = (await show.json()).claim
		expect(['submitted', 'approved'].includes(detail.status)).toBe(true)

		const withdraw = await page.request.post(`${APP}/api/claims/${claimId}/withdraw`, { headers: apiHeaders() })
		expect(withdraw.status()).toBe(200)
		expect((await withdraw.json()).claim.status).toBe('draft')
	})

	test('mc_segment provenance fails closed with MC absent', async () => {
		const cat = sql(`SELECT id FROM oc_exp_categories WHERE code='SABS-FLAT'`).trim()
		const cc = sql(`SELECT id FROM oc_exp_cost_centers WHERE code='SABS-CC'`).trim()
		const resp = await page.request.post(`${APP}/api/claims`, {
			headers: apiHeaders(),
			data: {
				title: 'MC provenance standalone', currency: 'EUR',
				lines: [{
					line_type: 'mileage', category_id: Number(cat),
					occurred_on: '2027-06-15', qty: '10', rate_code: 'SABS-KM',
					distance_source: 'mc_segment',
					allocations: [{ cost_center_id: Number(cc), share_percent: '100' }],
				}],
			},
		})
		// Provenance requires the sibling evidence link — absent it must be
		// a field error, never a 500.
		expect([400, 422]).toContain(resp.status())
		const body = await resp.json()
		expect(body.ok).toBe(false)
	})
})
