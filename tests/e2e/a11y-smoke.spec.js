// @ts-check
/**
 * WCAG 2.1 AA axe sweep over the ExpenseCheck primary surfaces.
 * One login per suite avoids Nextcloud bruteforce throttling.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, primaryCreds, assertNotNcDeadEnd } from './helpers/auth.js'

const APP = '/index.php/apps/expensecheck'

const routes = [
	{ path: '/', ready: '[data-exp-view="index"]' },
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
	{ path: '/settings/transfer', ready: '#exp-transfer-export-card' },
	{ path: '/license', ready: '#exp-license-body' },
]

async function axeMain(page) {
	const results = await new AxeBuilder({ page })
		.include('#exp-main-content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
}

test.describe.configure({ mode: 'serial' })

test.describe('a11y primary routes', () => {
	/** @type {import('@playwright/test').BrowserContext} */
	let context
	/** @type {import('@playwright/test').Page} */
	let page

	test.beforeAll(async ({ browser }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials')
		context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
		page = await context.newPage()
		await login(page, creds)
	})

	test.afterAll(async () => {
		await context?.close()
	})

	for (const route of routes) {
		test(`WCAG 2.1 AA: ${route.path || '/'}`, async () => {
			await page.goto(`${APP}${route.path}`)
			await assertNotNcDeadEnd(page, route.path)
			await expect(page.locator('#exp-main-content')).toBeVisible({ timeout: 30_000 })
			await expect(page.locator(route.ready).first()).toBeAttached({ timeout: 30_000 })
			await expect(page.locator('.exp-skip-link')).toBeAttached()
			await axeMain(page)
		})
	}

	test('WCAG 2.1 AA: claim detail (chipbar + sections)', async () => {
		const res = await page.evaluate(async (appBase) => {
			const r = await fetch(`${appBase}/api/claims`, {
				headers: { requesttoken: (globalThis.OC?.requestToken || '') },
			})
			return r.json()
		}, APP)
		const id = res?.claims?.[0]?.id
		test.skip(!id, 'No claim available for detail sweep')
		await page.goto(`${APP}/claims/${id}`)
		await assertNotNcDeadEnd(page, '/claims/{id}')
		await expect(page.locator('#exp-main-content')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('.exp-chipbar__chip').first()).toBeAttached({ timeout: 30_000 })
		await axeMain(page)
	})

	test('WCAG 2.1 AA at 320px: claims', async () => {
		await page.setViewportSize({ width: 320, height: 720 })
		await page.goto(`${APP}/claims`)
		await expect(page.locator('#exp-main-content')).toBeVisible({ timeout: 30_000 })
		await axeMain(page)
		await page.setViewportSize({ width: 1280, height: 900 })
	})
})
