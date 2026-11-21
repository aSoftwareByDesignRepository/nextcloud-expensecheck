// Gate 16 — both-directions integration proof (F20/F21/F25/F26 + Mode B).
//
// Direction B (EXP consumes MC): seeded MC draft segments surface in
// /api/integrations/mc/segments as claimable under Mode B; claiming one
// creates an EXP draft whose line carries mc_segment provenance; the
// second claim of the same segment fails ALREADY_CLAIMED (F25 dedupe).
// F26: seeded counter-signed MC charge-back surfaces, claims as a
// deduction draft, dedupes the same way.
//
// Direction A (MC consumes EXP): after the claim, MC's own
// reimbursement-claims API reports claimed_via='expensecheck' with the
// EXP claim number — the "Claimed via ExpenseCheck" chip (F21).
//
// Restore: every config flag + seeded row is reverted/removed in afterAll.

import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, primaryCreds } from './helpers/auth.js'

const APP = '/index.php/apps/expensecheck'
const MC = '/index.php/apps/mobilitycheck'
const NC_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..')

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
function getConfig(app, key, fallback) {
	try { return occ(['config:app:get', app, key]) || fallback } catch { return fallback }
}

test.describe.configure({ mode: 'serial' })
test.describe('integration both directions (gate 16)', () => {
	/** @type {import('@playwright/test').BrowserContext} */ let context
	/** @type {import('@playwright/test').Page} */ let page
	let token = ''
	let uid = ''
	const prior = { expMode: null, mcSubmit: null }
	let segIds = []
	let vehId = null
	let cbId = null

	test.beforeAll(async ({ browser }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* credentials')
		uid = creds.username

		prior.expMode = getConfig('expensecheck', 'mc_coexistence_mode', 'unset')
		prior.mcSubmit = getConfig('mobilitycheck', 'enable_expense_submit', '1')
		occ(['config:app:set', 'expensecheck', 'mc_coexistence_mode', '--value=b'])
		occ(['config:app:set', 'mobilitycheck', 'enable_expense_submit', '--value=0'])

		// EXP-side fixtures: category + mileage rate + cost center.
		sql(`DELETE FROM oc_exp_categories WHERE code='INT-MC'`)
		sql(`INSERT INTO oc_exp_categories (code, name, receipt_required, requires_attendees,
			allow_lines, active, sort, deleted)
			VALUES ('INT-MC', 'Integration mileage', 0, 0, 'receipt,mileage,per_diem,flat', 1, 99, 0)`)
		sql(`INSERT INTO oc_exp_rate_entries (rate_type, code, name, currency, rate_minor,
			effective_from, active, created_by, created_at)
			VALUES ('mileage', 'INT-KM', 'Integration km rate', 'EUR', 30,
			'2020-01-01', 1, 'e2e', NOW())`)
		sql(`DELETE FROM oc_exp_cost_centers WHERE code='INT-CC'`)
		sql(`INSERT INTO oc_exp_cost_centers (code, name, active, deleted)
			VALUES ('INT-CC', 'Integration CC', 1, 0)`)

		// MC-side fixtures: app role (access door) → driver profile →
		// private vehicle → two draft segments → fleet vehicle + charge-back.
		sql(`INSERT INTO oc_mc_user_roles (user_id, role, created_at)
			SELECT '${uid}', 'driver', NOW() FROM DUAL
			WHERE NOT EXISTS (SELECT 1 FROM oc_mc_user_roles WHERE user_id='${uid}' AND role='driver')`)
		sql(`INSERT INTO oc_mc_driver_profiles (user_id, licence_status, created_at, updated_at)
			SELECT '${uid}', 'verified', NOW(), NOW() FROM DUAL
			WHERE NOT EXISTS (SELECT 1 FROM oc_mc_driver_profiles WHERE user_id='${uid}')`)
		const profileId = sql(`SELECT id FROM oc_mc_driver_profiles WHERE user_id='${uid}'`).trim()
		sql(`INSERT INTO oc_mc_private_vehicles (driver_profile_id, make, model, licence_plate,
			engine_type, is_active, created_at, updated_at)
			VALUES (${profileId}, 'Test', 'Integ', 'INT-E2E', 'petrol', 1, NOW(), NOW())`)
		vehId = sql(`SELECT id FROM oc_mc_private_vehicles WHERE licence_plate='INT-E2E'`).trim()
		for (const [addr, km] of [['Home→Office', '12'], ['Office→Client', '34']]) {
			const [from, to] = addr.split('→')
			sql(`INSERT INTO oc_mc_reimbursement_claims (driver_user_id, private_vehicle_id,
				trip_date, start_address, end_address, distance_km, purpose,
				rate_per_km_minor, amount_claimable_minor, status, created_at, updated_at)
				VALUES ('${uid}', ${vehId}, '2027-06-15', '${from}', '${to}', ${km}, 'e2e',
				30, ${Number(km) * 30}, 'draft', NOW(), NOW())`)
		}
		segIds = sql(`SELECT id FROM oc_mc_reimbursement_claims
			WHERE driver_user_id='${uid}' AND purpose='e2e' ORDER BY id`).trim().split('\n').filter(Boolean)

		// Charge-back: fleet vehicle + acknowledged, undisputed cost entry.
		sql(`INSERT INTO oc_mc_vehicles (internal_name, make, model, licence_plate, status,
			fuel_type, transmission, created_by, created_at, updated_at)
			SELECT 'Integ fleet','Test','Van','INT-FLEET','available','diesel','manual','e2e',NOW(),NOW()
			FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM oc_mc_vehicles WHERE licence_plate='INT-FLEET')`)
		const fleetId = sql(`SELECT id FROM oc_mc_vehicles WHERE licence_plate='INT-FLEET'`).trim()
		sql(`INSERT INTO oc_mc_cost_entries (vehicle_id, category_id, entry_date,
			amount_gross_minor, vat_rate_bp, amount_net_minor, vat_amount_minor, notes,
			is_deleted, created_by_user_id, created_at, updated_at,
			chargeable_to_driver, charge_driver_user_id, driver_acknowledged_at)
			VALUES (${fleetId}, 12, '2027-06-15', 4500, 1900, 3782, 718, 'e2e scratch repair',
			0, '${uid}', NOW(), NOW(), 1, '${uid}', NOW())`)
		cbId = sql(`SELECT id FROM oc_mc_cost_entries WHERE notes='e2e scratch repair'`).trim()

		context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
		page = await context.newPage()
		await login(page, creds)
		await page.goto(`${APP}/claims`)
		token = await page.evaluate(() => window.OC?.requestToken
			|| document.querySelector('meta[name="requesttoken"]')?.content || '')
	})

	test.afterAll(async () => {
		occ(['config:app:set', 'expensecheck', 'mc_coexistence_mode', `--value=${prior.expMode}`])
		occ(['config:app:set', 'mobilitycheck', 'enable_expense_submit', `--value=${prior.mcSubmit}`])
		sql(`DELETE FROM oc_exp_categories WHERE code='INT-MC'`)
		sql(`DELETE FROM oc_exp_rate_entries WHERE code='INT-KM'`)
		sql(`DELETE FROM oc_exp_cost_centers WHERE code='INT-CC'`)
		sql(`DELETE FROM oc_mc_reimbursement_claims WHERE purpose='e2e' AND driver_user_id='${uid}'`)
		sql(`DELETE FROM oc_mc_private_vehicles WHERE licence_plate='INT-E2E'`)
		sql(`DELETE FROM oc_mc_cost_entries WHERE notes='e2e scratch repair'`)
		sql(`DELETE FROM oc_mc_vehicles WHERE licence_plate='INT-FLEET'`)
		sql(`DELETE FROM oc_mc_user_roles WHERE user_id='${uid}' AND role='driver'`)
		await context?.close()
	})

	const apiHeaders = () => ({ 'requesttoken': token, 'OCS-APIREQUEST': 'true' })

	test('B: MC segments surface as claimable under Mode B', async () => {
		const resp = await page.request.get(`${APP}/api/integrations/mc/segments`)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body.ok).toBe(true)
		const mine = body.segments.filter((s) => segIds.includes(String(s.segment_id)))
		expect(mine.length).toBe(2)
		for (const s of mine) expect(s.claimable).toBe(true)
	})

	test('B+F25: claim a segment → provenance line; second claim dedupes', async () => {
		const cat = sql(`SELECT id FROM oc_exp_categories WHERE code='INT-MC'`).trim()
		const cc = sql(`SELECT id FROM oc_exp_cost_centers WHERE code='INT-CC'`).trim()
		const first = await page.request.post(`${APP}/api/integrations/mc/segments/claim`, {
			headers: apiHeaders(),
			data: {
				segment_id: Number(segIds[0]), category_id: Number(cat),
				rate_code: 'INT-KM', cost_center_id: Number(cc),
			},
		})
		const firstBody = await first.json()
		expect(first.status(), JSON.stringify(firstBody)).toBe(201)
		expect(firstBody.ok).toBe(true)
		const line = firstBody.claim.lines[0]
		expect(line.distance_source).toBe('mc_segment')
		expect(line.source_app).toBe('mobilitycheck')
		expect(line.source_ref).toBe(String(segIds[0]))
		expect(line.amount_minor).toBe(12 * 30)

		const again = await page.request.post(`${APP}/api/integrations/mc/segments/claim`, {
			headers: apiHeaders(),
			data: {
				segment_id: Number(segIds[0]), category_id: Number(cat),
				rate_code: 'INT-KM', cost_center_id: Number(cc),
			},
		})
		expect(again.status()).toBe(409)
		expect((await again.json()).error_code).toBe('ALREADY_CLAIMED')
	})

	test('A: MC reports the segment as claimed via ExpenseCheck (chip)', async () => {
		const resp = await page.request.get(`${MC}/api/reimbursement-claims`, { headers: apiHeaders() })
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		const rows = Array.isArray(body.data) ? body.data
			: (body.rows ?? body.claims ?? body.data?.rows ?? body.data?.claims ?? [])
		const mine = rows.find((r) => String(r.id) === String(segIds[0]))
		expect(mine, 'seeded MC segment must exist in MC list').toBeTruthy()
		expect(mine.claimed_via).toBe('expensecheck')
		expect(mine.claimed_claim_no).toMatch(/^EXP-/)
	})

	test('B+F26: charge-back claims as deduction draft, then dedupes', async () => {
		const list = await page.request.get(`${APP}/api/integrations/mc/chargebacks`)
		expect(list.status()).toBe(200)
		const cbs = (await list.json()).chargebacks
		const mine = cbs.find((c) => String(c.chargeback_id) === String(cbId))
		expect(mine, 'seeded charge-back must surface').toBeTruthy()
		expect(mine.amount_minor).toBe(4500)

		const cat = sql(`SELECT id FROM oc_exp_categories WHERE code='INT-MC'`).trim()
		const cc = sql(`SELECT id FROM oc_exp_cost_centers WHERE code='INT-CC'`).trim()
		const claim = await page.request.post(`${APP}/api/integrations/mc/chargebacks/claim`, {
			headers: apiHeaders(),
			data: { chargeback_id: Number(cbId), category_id: Number(cat), cost_center_id: Number(cc) },
		})
		const claimBody = await claim.json()
		expect(claim.status(), JSON.stringify(claimBody)).toBe(201)
		expect(claimBody.claim.kind).toBe('deduction')
		expect(claimBody.claim.lines[0].amount_minor).toBe(-4500)

		const again = await page.request.post(`${APP}/api/integrations/mc/chargebacks/claim`, {
			headers: apiHeaders(),
			data: { chargeback_id: Number(cbId), category_id: Number(cat), cost_center_id: Number(cc) },
		})
		expect(again.status()).toBe(409)
		expect((await again.json()).error_code).toBe('ALREADY_CLAIMED')
	})
})
