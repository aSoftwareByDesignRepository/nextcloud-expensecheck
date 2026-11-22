// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — the tall settings/access page must scroll
 * its last control into reach. Guards CSS Overflow L3 unpaired
 * overflow-x:clip truncating the Save CTA. Access & roles is the app's
 * tallest settings surface (three people/group pickers + save row,
 * JS-rendered into #exp-settings-body).
 */
import { test } from '@playwright/test'
import { createRequire } from 'node:module'
import { login, credsFromEnv, adminCreds } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasVerticalScrollReachable } = require('../../../_shared/e2e/atlas-vertical-scroll-contract.js')

const APP = '/index.php/apps/expensecheck'
// /settings/access is app-admin only — a non-admin gets a 404 and the form
// never renders. Prefer ADMIN creds explicitly.
const creds = credsFromEnv('ADMIN') || adminCreds()
const SAVE = '#exp-settings-body form .exp-btn--primary'

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test.skip(!creds, 'Requires NC_E2E_*/NC_ADMIN_* credentials')

	test('settings access scrolls to the Save CTA', async ({ page }) => {
		await login(page, creds)
		await page.setViewportSize({ width: 1280, height: 640 })
		await page.goto(`${APP}/settings/access`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector(SAVE, { timeout: 45_000 })

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: `${SAVE}, #exp-settings-body form .exp-picker`,
			bottomSlopPx: 12,
		})
	})

	test('settings access stays reachable at phone height', async ({ page }) => {
		await login(page, creds)
		await page.setViewportSize({ width: 390, height: 667 })
		await page.goto(`${APP}/settings/access`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector(SAVE, { timeout: 45_000 })

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: SAVE,
			bottomSlopPx: 16,
		})
	})
})
