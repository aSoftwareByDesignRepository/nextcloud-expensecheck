// @ts-check
/**
 * §9.4 / gate-19 persona acceptance: what the nav shows must match what the
 * API enforces — for every role. Employee (no roles) must see Work-only nav
 * AND get uniform-404 on every guarded page + admin/finance API; the admin
 * fixture must see all four groups and reach every page. The door-denied leg
 * flips the access restriction under the shared-instance lease and restores
 * it in `finally` — a crashed run must not leave the door shut.
 *
 * Personas live in the shared Docker fixture:
 *   expe2eweb     app_admin_user_ids + expensecheck_finance → all roles
 *   e2e-noaccess  no groups, door open                       → employee
 */
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, credsFromEnv } from './helpers/auth.js'

const APP = '/index.php/apps/expensecheck'
const NC_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..')

function occ(args) {
	return execFileSync('docker', ['compose', 'exec', '-T', 'nextcloud', 'php', 'occ', ...args],
		{ cwd: NC_DIR, encoding: 'utf8' })
}

const ADMIN_ONLY_PAGES = ['/categories', '/rates', '/cost-centers', '/chains', '/settings', '/settings/transfer', '/license']
const FINANCE_PAGES = ['/all', '/settlements', '/reports']

async function navGroupLabels(page) {
	return page.locator('.exp-nav__group-label').allTextContents()
}

test.describe.configure({ mode: 'serial' })

test.describe('persona sweep — nav/API parity per role', () => {
	/** @type {import('@playwright/test').BrowserContext} */
	let adminCtx
	/** @type {import('@playwright/test').Page} */
	let admin
	/** @type {import('@playwright/test').BrowserContext} */
	let empCtx
	/** @type {import('@playwright/test').Page} */
	let emp

	test.beforeAll(async ({ browser }) => {
		const adminCreds = credsFromEnv('E2E') || credsFromEnv('ADMIN')
		const empCreds = credsFromEnv('E2E_NOACCESS')
		test.skip(!adminCreds, 'Requires NC_E2E_* or NC_ADMIN_* credentials')
		test.skip(!empCreds, 'Requires NC_E2E_NOACCESS_* credentials (employee persona)')
		adminCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } })
		admin = await adminCtx.newPage()
		await login(admin, adminCreds)
		empCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } })
		emp = await empCtx.newPage()
		await login(emp, empCreds)
	})

	test.afterAll(async () => {
		await adminCtx?.close()
		await empCtx?.close()
	})

	test('employee: nav exposes Work group only — no Approve/Finance/Admin', async () => {
		const resp = await emp.goto(`${APP}/`)
		expect(resp?.status()).toBe(200)
		const labels = (await navGroupLabels(emp)).map((s) => s.trim())
		expect(labels).toEqual(['Work'])
		await expect(emp.locator('.exp-nav__list .exp-nav__link')).toHaveCount(4) // Overview, My claims, New claim, Receipts
	})

	for (const path of [...ADMIN_ONLY_PAGES, ...FINANCE_PAGES]) {
		test(`employee: ${path} → uniform 404 (no existence oracle)`, async () => {
			const resp = await emp.goto(`${APP}${path}`)
			expect(resp?.status()).toBe(404)
		})
	}

	test('employee: claims status filter is recipe-B pills (press + roving keys)', async () => {
		await emp.goto(`${APP}/claims`)
		const bar = emp.locator('[data-exp-filter="status"]')
		await expect(bar).toBeVisible({ timeout: 15_000 })
		const draft = bar.locator('button[data-value="draft"]')
		await draft.click()
		await expect(draft).toHaveAttribute('aria-pressed', 'true')
		// roving tabindex: ArrowRight moves the active pill one step
		await draft.press('ArrowRight')
		const submitted = bar.locator('button[data-value="submitted"]')
		await expect(submitted).toHaveAttribute('aria-pressed', 'true')
		await expect(submitted).toBeFocused()
	})

	test('employee: /inbox renders but is empty (nav hid it — nothing to decide)', async () => {
		const resp = await emp.goto(`${APP}/inbox`)
		expect(resp?.status()).toBe(200)
		await expect(emp.locator('#exp-inbox-empty')).toBeVisible({ timeout: 15_000 })
	})

	test('employee: finance/admin GET APIs → uniform 404, not 403 oracle', async () => {
		const settle = await emp.request.get(`${APP}/api/settlements`)
		expect(settle.status()).toBe(404)
		const reports = await emp.request.get(`${APP}/api/reports/summary`)
		expect(reports.status()).toBe(404)
		const license = await emp.request.get(`${APP}/api/license`)
		expect(license.status()).toBe(404)
	})

	test('employee: own claims API stays reachable (parity — Work nav is real)', async () => {
		const resp = await emp.request.get(`${APP}/api/claims`)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body.ok).toBe(true)
	})

	test('scope=all: finance sees org claims, member stays own-scoped', async () => {
		// Employee drafts a claim admin cannot own
		const created = await emp.request.post(`${APP}/api/claims`, {
			data: { title: `Scope probe ${Date.now()}`, request_key: `scope-${Date.now()}` },
		})
		expect(created.status()).toBe(200)
		const { claim } = await created.json()
		// Admin's plain list = own claims only — must not contain it
		const mine = await (await admin.request.get(`${APP}/api/claims`)).json()
		expect(mine.claims.map((c) => c.id)).not.toContain(claim.id)
		// Admin + scope=all = org visibility — must contain it
		const all = await (await admin.request.get(`${APP}/api/claims?scope=all`)).json()
		expect(all.claims.map((c) => c.id)).toContain(claim.id)
		// Status filter composes with scope=all
		const filtered = await (await admin.request.get(`${APP}/api/claims?scope=all&status=draft`)).json()
		expect(filtered.claims.map((c) => c.id)).toContain(claim.id)
		// Member scope=all does NOT escalate: no claim owned by anyone else
		const empAll = await (await emp.request.get(`${APP}/api/claims?scope=all`)).json()
		expect(empAll.claims.every((c) => c.owner === claim.owner)).toBe(true)
	})

	test('admin+finance: all four nav groups visible', async () => {
		const resp = await admin.goto(`${APP}/`)
		expect(resp?.status()).toBe(200)
		const labels = (await navGroupLabels(admin)).map((s) => s.trim())
		for (const g of ['Work', 'Approve', 'Finance', 'Admin']) {
			expect(labels, `nav group ${g} missing`).toContain(g)
		}
	})

	for (const path of [...ADMIN_ONLY_PAGES, ...FINANCE_PAGES]) {
		test(`admin+finance: ${path} → 200`, async () => {
			const resp = await admin.goto(`${APP}${path}`)
			expect(resp?.status()).toBe(200)
		})
	}

	// Suite runs under the global instance lease (global-setup) — the door
	// flip is safe inside it. Restore must run even on assertion failure.
	test('door closed: employee → APP_ACCESS_DENIED 403, then restored', async () => {
		try {
			occ(['config:app:set', 'expensecheck', 'access_restriction_enabled', '--value=1'])
			occ(['config:app:set', 'expensecheck', 'access_allowed_user_ids', '--value=["expe2eweb"]'])
			const resp = await emp.goto(`${APP}/`)
			expect(resp?.status()).toBe(403)
			const body = await resp?.json()
			expect(body.error.code).toBe('APP_ACCESS_DENIED')
			// Allowed user still works while the door is shut
			const ok = await admin.goto(`${APP}/`)
			expect(ok?.status()).toBe(200)
		} finally {
			occ(['config:app:set', 'expensecheck', 'access_restriction_enabled', '--value=0'])
			occ(['config:app:set', 'expensecheck', 'access_allowed_user_ids', '--value=[]'])
		}
	})
})
