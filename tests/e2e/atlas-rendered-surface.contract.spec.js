// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — asserts the *rendered* truth of each app
 * page surface: content list markers are not reset to none by shell CSS
 * leaks, selects are vertically centred (not sunken/clipped), icons and SVGs
 * have non-zero boxes, and form controls/labels are not text-centered by an
 * inherited shell alignment. DOM-level specs pass on pages that are visually
 * broken; this is the pixel-adjacent invariant class.
 */
import { test } from '@playwright/test'
import { createRequire } from 'node:module'
import { login, primaryCreds } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasRenderedSurface } = require('../../../_shared/e2e/atlas-rendered-surface-contract.js')

const APP = '/index.php/apps/expensecheck'

const SURFACES = [
	['/', 'index'],
	['/claims', 'claims list + filters'],
	['/claims/new', 'claim form'],
	['/inbox', 'approval inbox'],
	['/receipts', 'receipts inbox'],
	['/all', 'all claims (admin)'],
	['/settlements', 'settlements'],
	['/reports', 'reports'],
	['/categories', 'admin categories'],
	['/rates', 'admin rates'],
	['/cost-centers', 'admin cost centers'],
	['/chains', 'admin chains'],
	['/settings', 'settings hub'],
	['/settings/general', 'settings general'],
	['/settings/access', 'settings access'],
	['/settings/transfer', 'settings transfer'],
	['/settings/support', 'settings support'],
	['/license', 'license'],
	['/get-the-app', 'get the app'],
]

/**
 * Intentionally marker-less lists: nav, breadcrumbs, combobox/listbox
 * dropdowns, the Help menu, icon-row queues (queue/recent rows carry icon +
 * card chrome, not bullets), and the get-the-app icon feature list. Content
 * lists must NOT appear here — they must show markers.
 */
const LIST_ALLOW = [
	'.exp-nav__list',
	'.exp-breadcrumb__list',
	'.exp-picker',
	'.exp-seat-search-results',
	'.exp-nav-footer__menu',
	'ul.exp-deadline-list',
	'.exp-get-app__features',
	'.exp-settings-nav',
	'.exp-chip-list',
].join(', ')

const creds = primaryCreds()

test.describe('Rendered-surface contract', () => {
	test.skip(!creds, 'Requires NC_E2E_*/NC_ADMIN_* credentials')

	for (const [routePath, name] of SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${routePath})`, async ({ page }) => {
			await login(page, creds)
			await page.setViewportSize({ width: 1280, height: 800 })
			await page.goto(`${APP}${routePath === '/' ? '/' : routePath}`, { waitUntil: 'domcontentloaded' })
			await page.waitForSelector('#exp-main-content', { timeout: 30_000 })
			await page.waitForLoadState('networkidle').catch(() => {})

			await assertAtlasRenderedSurface(page, {
				content: '#exp-main-content',
				navExclude: '#app-navigation, nav, .exp-nav, .exp-breadcrumb, .exp-nav-toggle',
				listAllow: LIST_ALLOW,
			})
		})
	}
})
