// @ts-check
/**
 * ExpenseCheck e2e smoke — every primary route renders the app shell and its
 * own surface anchor (never a Nextcloud 404 shell, never a blank mount).
 */
import { test, expect } from '@playwright/test'
import { login, primaryCreds, assertNotNcDeadEnd } from './helpers/auth.js'

const APP = '/index.php/apps/expensecheck'

const routes = [
	{ path: '/', ready: '[data-exp-view="index"]' },
	{ path: '/claims', ready: '#exp-claims-list' },
	{ path: '/claims/new', ready: '#exp-claim-form' },
	{ path: '/inbox', ready: '#exp-inbox-list' },
	{ path: '/receipts', ready: '#exp-receipts-list' },
	{ path: '/all', ready: '#exp-all-list' },
	{ path: '/settlements', ready: '#exp-settle-list' },
	{ path: '/reports', ready: '#exp-reports-body' },
	{ path: '/categories', ready: '#exp-cat-list' },
	{ path: '/rates', ready: '#exp-rates-list' },
	{ path: '/cost-centers', ready: '#exp-cc-list' },
	{ path: '/chains', ready: '#exp-chains-list' },
	{ path: '/settings', ready: '#exp-settings-body' },
	{ path: '/settings/general', ready: '#exp-settings-body' },
	{ path: '/settings/transfer', ready: '#exp-transfer-export-card' },
	{ path: '/license', ready: '#exp-license-page' },
]

test.describe.configure({ mode: 'serial' })

test.describe('expensecheck route smoke', () => {
	/** @type {import('@playwright/test').BrowserContext} */
	let context
	/** @type {import('@playwright/test').Page} */
	let page

	test.beforeAll(async ({ browser }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials (tests/e2e/.env)')
		context = await browser.newContext()
		page = await context.newPage()
		await login(page, creds)
	})

	test.afterAll(async () => {
		await context?.close()
	})

	for (const route of routes) {
		test(`renders ${route.path}`, async () => {
			await page.goto(`${APP}${route.path}`, { waitUntil: 'domcontentloaded' })
			await assertNotNcDeadEnd(page, route.path)
			await expect(page.locator('#exp-main-content')).toBeVisible({ timeout: 30_000 })
			await expect(
				page.locator(route.ready).first(),
				`${route.path}: surface anchor ${route.ready}`,
			).toBeAttached({ timeout: 30_000 })
		})
	}

	test('claims list reaches a stable state, never blank', async () => {
		// Six-state contract: after the async fetch the surface must land in
		// populated (exp-table rows), empty (#exp-claims-empty), or inline error
		// (.exp-empty-state with retry) — a blank surface is a regression.
		await page.goto(`${APP}/claims`, { waitUntil: 'domcontentloaded' })
		const cards = page.locator('#exp-claims-list .exp-table tbody tr, #exp-claims-list .exp-card')
		const empty = page.locator('#exp-claims-empty')
		const inlineError = page.locator('#exp-claims-list .exp-empty-state')
		await expect
			.poll(async () =>
				(await cards.count())
				|| ((await empty.isVisible()) ? 1 : 0)
				|| (await inlineError.count()), { timeout: 30_000 })
			.toBeGreaterThan(0)
		await expect(page.locator('#exp-claims-list')).not.toHaveAttribute('aria-busy', 'true')
	})
})
