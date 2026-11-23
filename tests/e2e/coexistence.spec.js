// @ts-check
/**
 * MC coexistence mode picker (edge 38, spec §12.4b):
 * with MobilityCheck installed and mode `unset`, the settings page must
 * surface the disambiguation callout + Mode A/B picker — never silently
 * configured, never blocked. Applying a mode persists
 * mc_coexistence_mode AND the EXP-side mileage consequence, and re-renders
 * the callout in its settled state.
 *
 * Restore runs in finally — the spec must never leave a mode it didn't
 * find configured.
 */
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, adminCreds, assertNotNcDeadEnd } from './helpers/auth.js'

const APP = '/index.php/apps/expensecheck'
const NC_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..')

function occ(args) {
	return execFileSync('docker', ['compose', 'exec', '-T', 'nextcloud', 'php', 'occ', ...args],
		{ cwd: NC_DIR, encoding: 'utf8' }).trim()
}

/** config:app:get exits non-zero for keys never written — treat as default. */
function getConfig(key, fallback) {
	try { return occ(['config:app:get', 'expensecheck', key]) || fallback }
	catch { return fallback }
}

test.describe.configure({ mode: 'serial' })

test.describe('MC coexistence picker (edge 38)', () => {
	let context
	let page
	let priorMode = ''
	let priorMileage = ''

	test.beforeAll(async ({ browser }) => {
		const creds = adminCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials (tests/e2e/.env)')
		context = await browser.newContext()
		page = await context.newPage()
		await login(page, creds)
		// Self-gating: the whole MC UI surface is unpublished until
		// MobilityCheck ships (IntegrationsApiController::MC_SURFACE_ENABLED).
		// Skips reactivate automatically when the flag flips.
		const summary = await page.request.get(`${APP}/api/integrations/summary`)
		const mcInstalled = (await summary.json())?.siblings?.mobilitycheck?.installed === true
		test.skip(!mcInstalled, 'MC surface unpublished (MC_SURFACE_ENABLED) — re-enable when MobilityCheck ships')
		priorMode = getConfig('mc_coexistence_mode', 'unset')
		priorMileage = getConfig('enable_mileage_claims', '1')
	})

	test.afterAll(async () => {
		occ(['config:app:set', 'expensecheck', 'mc_coexistence_mode', `--value=${priorMode || 'unset'}`])
		occ(['config:app:set', 'expensecheck', 'enable_mileage_claims', `--value=${priorMileage || '1'}`])
		await context?.close()
	})

	test('unset mode → warning callout + both choice cards', async () => {
		occ(['config:app:set', 'expensecheck', 'mc_coexistence_mode', '--value=unset'])
		await page.goto(`${APP}/settings`, { waitUntil: 'domcontentloaded' })
		await assertNotNcDeadEnd(page, 'settings')
		const alert = page.locator('.exp-license-callout--warning', { hasText: 'Two apps can submit vehicle expenses' })
		await expect(alert).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('input[name="exp-coex-mode"][value="a"]')).toBeAttached()
		await expect(page.locator('input[name="exp-coex-mode"][value="b"]')).toBeAttached()
	})

	test('Mode B apply → persists mode + keeps mileage on, callout settles', async () => {
		occ(['config:app:set', 'expensecheck', 'mc_coexistence_mode', '--value=unset'])
		await page.goto(`${APP}/settings`, { waitUntil: 'domcontentloaded' })
		await page.locator('input[name="exp-coex-mode"][value="b"]').check()
		await page.getByRole('button', { name: 'Apply mode' }).click()
		// Server state is the contract — the checkbox sync is cosmetic.
		await expect.poll(() =>
			getConfig('mc_coexistence_mode', 'unset'), { timeout: 15_000 })
			.toBe('b')
		expect(getConfig('enable_mileage_claims', '1')).toBe('1')
		await expect(
			page.locator('.exp-license-callout--info', { hasText: 'Mode B' }),
		).toBeVisible({ timeout: 15_000 })
	})

	test('Mode A apply → persists mode + mileage off', async () => {
		await page.goto(`${APP}/settings`, { waitUntil: 'domcontentloaded' })
		await page.locator('input[name="exp-coex-mode"][value="a"]').check()
		await page.getByRole('button', { name: 'Apply mode' }).click()
		await expect.poll(() =>
			getConfig('mc_coexistence_mode', 'unset'), { timeout: 15_000 })
			.toBe('a')
		expect(getConfig('enable_mileage_claims', '1')).toBe('0')
		const mil = page.locator('#exp-set-enable_mileage_claims')
		await expect(mil).not.toBeChecked()
	})
})
