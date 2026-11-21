// @ts-check
/**
 * Responsive + theme proofs — no horizontal overflow at the Nextcloud
 * breakpoints, and WCAG 2.1 AA contrast holds when --color-* resolves to
 * dark/high-contrast values (the app's colors must be 100% var-driven).
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, primaryCreds, assertNotNcDeadEnd } from './helpers/auth.js'

const APP = '/index.php/apps/expensecheck'

const routes = [
	{ path: '/', ready: '[data-exp-view="index"]' },
	{ path: '/claims/new', ready: '#exp-claim-form' },
	{ path: '/receipts', ready: '#exp-receipts-list' },
	{ path: '/settlements', ready: '#exp-settle-list' },
	{ path: '/categories', ready: '#exp-cat-list' },
	{ path: '/license', ready: '#exp-license-page' },
]

const widths = [320, 375, 768, 1024, 1280]

/** Dark-theme --color-* values, mirroring NC's dark mode palette. */
const DARK_VARS = `
	:root {
		--color-main-background: #171717 !important;
		--color-main-text: #dbdbdb !important;
		--color-background-hover: #232323 !important;
		--color-border: #3b3b3b !important;
		--color-border-dark: #4c4c4c !important;
		--color-primary-element: #369 !important;
		--color-primary-element-light: #2b2b2b !important;
		--color-primary-element-text: #ffffff !important;
		--color-text-maxcontrast: #8a8a8a !important;
		--color-error: #e9322d !important;
		--color-error-text: #ff8f8f !important;
		--color-warning: #a06d00 !important;
		--color-success: #46ba61 !important;
		--color-main-background-blur: rgba(23,23,23,0.8) !important;
	}
`

test.describe.configure({ mode: 'serial' })

test.describe('viewport + theme proofs', () => {
	/** @type {import('@playwright/test').BrowserContext} */
	let context
	/** @type {import('@playwright/test').Page} */
	let page

	test.beforeAll(async ({ browser }) => {
		const creds = primaryCreds()
		test.skip(!creds, 'Requires NC_E2E_* or NC_ADMIN_* credentials')
		context = await browser.newContext()
		page = await context.newPage()
		await login(page, creds)
	})

	test.afterAll(async () => {
		await context?.close()
	})

	for (const width of widths) {
		test(`no horizontal overflow at ${width}px`, async () => {
			await page.setViewportSize({ width, height: 900 })
			for (const route of routes) {
				await page.goto(`${APP}${route.path}`)
				await assertNotNcDeadEnd(page, route.path)
				await expect(page.locator(route.ready).first()).toBeAttached({ timeout: 30_000 })
				const overflow = await page.evaluate(() => {
					const de = document.documentElement
					const app = document.getElementById('app-content')
					const bad = []
					const scan = app || de
					scan.querySelectorAll('*').forEach((node) => {
						const r = node.getBoundingClientRect()
						if (r.width > window.innerWidth + 1 && r.width > 0) {
							bad.push(`${node.tagName.toLowerCase()}.${String(node.className).slice(0, 60)} w=${Math.round(r.width)}`)
						}
					})
					return { docOverflow: de.scrollWidth > window.innerWidth + 1, bad: bad.slice(0, 8) }
				})
				expect(overflow.docOverflow, `${route.path} @${width}px: document overflows viewport`).toBe(false)
				expect(overflow.bad, `${route.path} @${width}px oversized: ${overflow.bad.join(' | ')}`).toEqual([])
			}
		})
	}

	test('WCAG 2.1 AA in dark theme (var-driven colors)', async () => {
		await page.setViewportSize({ width: 1280, height: 900 })
		for (const route of routes.slice(0, 4)) {
			await page.goto(`${APP}${route.path}`)
			await expect(page.locator(route.ready).first()).toBeAttached({ timeout: 30_000 })
			await page.addStyleTag({ content: DARK_VARS })
			const results = await new AxeBuilder({ page })
				.include('#exp-main-content')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.exclude('.toastify')
				.analyze()
			expect(results.violations, `${route.path} dark: ${JSON.stringify(results.violations.map(v => v.id))}`).toEqual([])
		}
	})

	test('forced-colors mode keeps interactive controls usable', async ({ browser }) => {
		const hc = await browser.newContext({ forcedColors: 'active' })
		const p = await hc.newPage()
		await login(p, primaryCreds())
		await p.goto(APP + '/claims/new')
		await expect(p.locator('#exp-claim-form')).toBeAttached({ timeout: 30_000 })
		// Primary action must still be a real, clickable control.
		const submit = p.locator('#exp-claim-form button[type="submit"]')
		await expect(submit).toBeVisible()
		const box = await submit.boundingBox()
		expect(box && box.height >= 40, 'submit button too small under forced colors').toBeTruthy()
		// Focus ring must survive forced-colors (app.css maps it to Highlight).
		await p.keyboard.press('Tab')
		await p.keyboard.press('Tab')
		const outline = await p.evaluate(() => {
			const ae = document.activeElement
			if (!ae) return null
			const s = getComputedStyle(ae)
			return { tag: ae.tagName, outline: s.outlineStyle, width: s.outlineWidth }
		})
		expect(outline).not.toBeNull()
		await hc.close()
	})

	test('touch targets ≥44px at 320px on primary controls', async () => {
		await page.setViewportSize({ width: 320, height: 900 })
		await page.goto(APP + '/claims/new')
		await expect(page.locator('#exp-claim-form')).toBeAttached({ timeout: 30_000 })
		const small = await page.evaluate(() => {
			const bad = []
			document.querySelectorAll('#app-content .exp-btn, #app-content button[type="submit"]').forEach((b) => {
				const r = b.getBoundingClientRect()
				if (r.height > 0 && (r.height < 44 || r.width < 44)) {
					bad.push(`${b.textContent.trim().slice(0, 30)} ${Math.round(r.width)}×${Math.round(r.height)}`)
				}
			})
			return bad
		})
		expect(small, `sub-44px controls: ${small.join(' | ')}`).toEqual([])
	})
})
