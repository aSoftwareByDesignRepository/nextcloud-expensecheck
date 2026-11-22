// @ts-check
/**
 * ATLAS_UI_INVARIANTS — shared-contract coverage for ExpenseCheck web
 * surfaces. Wires nextcloud/apps/_shared/e2e/atlas-ui-invariants.js onto
 * representative surfaces with unique `expui-` marked fixtures
 * (created + cleaned up by this spec — no shared fixture mutation):
 *   a11y-dom sweep, console errors, raw i18n keys, O(1) list requests,
 *   stored-xss via claim title, mutation→surface freshness,
 *   double-submit guard, form-survival-on-5xx.
 */
import { test, expect } from '@playwright/test'
import { createRequire } from 'node:module'
import { login, primaryCreds, credsFromEnv, adminCreds } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const {
	ATLAS_XSS_PAYLOADS,
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoDuplicateSubmit,
	assertNoInjection,
	assertNoRawI18nKeys,
	assertSurfaceFresh,
	assertFormSurvivesFailure,
	countApiRequests,
	trackConsoleErrors,
} = require('../../../_shared/e2e/atlas-ui-invariants.js')

const APP = '/index.php/apps/expensecheck'
// The app's own fetches go to OC.generateUrl('/apps/expensecheck') + '/api'
// (no index.php on this instance). Keeping the shorter form means route
// interception + request counting match BOTH URL shapes.
const API = '/apps/expensecheck/api'
const CONTENT = '#exp-main-content'
const MARK = `expui-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`
// Mutating legs need app-admin (admin/* endpoints); expe2eweb is admin on
// the shared lab — prefer ADMIN explicitly like the vertical-scroll spec.
const admin = credsFromEnv('ADMIN') || adminCreds()
const creds = primaryCreds()

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

test.describe('ExpenseCheck UI invariants (atlas-ui-invariants)', () => {
	test.describe.configure({ mode: 'serial' })
	test.skip(!creds, 'Requires NC_E2E_*/NC_ADMIN_* credentials')
	test.beforeEach(async ({ page }) => {
		await login(page, creds)
		await page.setViewportSize({ width: 1280, height: 800 })
	})

	test.afterAll(async ({ browser }) => {
		// Tear down categories this run created (freshness + dblsubmit +
		// survive legs) — MARK is run-unique, so a list sweep finds them all.
		const page = await browser.newPage()
		try {
			await login(page, admin)
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
			const list = await api(page, `${API}/admin/categories`)
			for (const c of list.data?.categories || []) {
				if (String(c.code || '').includes(MARK) || String(c.name || '').includes(MARK)) {
					await api(page, `${API}/admin/categories/${c.id}`, 'DELETE').catch(() => {})
				}
			}
		} finally {
			await page.close()
		}
	})

	for (const [label, path] of [
		['index', `${APP}/`],
		['claims', `${APP}/claims`],
		['claim-new', `${APP}/claims/new`],
		['settings access', `${APP}/settings/access`],
	]) {
		test(`a11y-dom sweep /${label}`, async ({ page }) => {
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			await page.waitForLoadState('networkidle').catch(() => {})
			// WCAG 2.5.8 equivalent-target exception: sub-24px radios/checkboxes
			// are legal only when a wrapping <label> gives the ≥24px target.
			const unlabeled = await page.evaluate(() => {
				const bad = []
				document.querySelectorAll('#app-content input[type="radio"], #app-content input[type="checkbox"]').forEach((inp) => {
					const lbl = inp.closest('label')
						|| (inp.id ? document.querySelector(`label[for="${CSS.escape(inp.id)}"]`) : null)
					if (!lbl) {
						bad.push(`no label: ${inp.id || inp.outerHTML.slice(0, 60)}`)
						return
					}
					const r = lbl.getBoundingClientRect()
					const cs = getComputedStyle(lbl)
					if (cs.display === 'none' || cs.visibility === 'hidden') return
					if (r.width < 24 || r.height < 24) bad.push(`label ${Math.round(r.width)}x${Math.round(r.height)} < 24px: ${inp.id}`)
				})
				return bad
			})
			expect(unlabeled, `radio/checkbox without a ≥24px label target on /${label}:\n${unlabeled.join('\n')}`).toEqual([])
			const findings = await assertA11yDom(page, {
				content: CONTENT,
				sizeAllow: 'input[type="radio"], input[type="checkbox"]',
			})
			// The "not announced" heuristic fires on every *callout*/error-class
			// element, but WCAG 4.1.3 status-message semantics only apply to
			// dynamically-inserted messages. Static content that is already
			// properly marked — role="note" callouts, <details> disclosures,
			// severity .badge pills — is legitimately silent. Verify the mark
			// exists before filtering: an unmarked new alert still fails.
			const properlyMarkedStatic = new Set(await page.evaluate(() => {
				const out = []
				document
					.querySelectorAll('#app-content [class*="error"], #app-content [class*="Error"], #app-content [class*="alert"], #app-content [class*="Alert"], #app-content [class*="toast"], #app-content [class*="Toast"], #app-content [class*="callout"], #app-content [class*="Callout"]')
					.forEach((el) => {
						const isStaticMarked = el.matches('details')
							|| el.closest('details')
							|| el.getAttribute('role') === 'note'
							|| el.closest('[role="note"]')
							|| /\bbadge\b|badge--/.test(String(el.className))
						if (isStaticMarked) out.push(String(el.className).slice(0, 60))
					})
				return out
			}))
			const real = findings.filter((f) => {
				const m = f.match(/^error\/toast element not announced \(no role\/aria-live\): (.+)$/)
				if (!m) return true
				return !properlyMarkedStatic.has(m[1])
			})
			expect(real, `a11y-dom findings on ${path}:\n${real.join('\n')}`).toEqual([])
		})

		test(`console errors + raw i18n keys /${label}`, async ({ page }) => {
			const errs = trackConsoleErrors(page)
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			await page.waitForLoadState('networkidle').catch(() => {})
			assertNoConsoleErrors(errs, { allow: [/favicon/i] })
			await assertNoRawI18nKeys(page, { content: CONTENT })
		})
	}

	test('claims list render is O(1) on API requests', async ({ page }) => {
		const calls = await countApiRequests(page, async () => {
			await page.goto(`${APP}/claims`, { waitUntil: 'domcontentloaded' })
			await page.waitForLoadState('networkidle').catch(() => {})
			await page.waitForSelector('#exp-claims-list .exp-card, #exp-claims-list .exp-empty, #exp-claims-list .exp-empty-state', { timeout: 15_000 }).catch(() => {})
		}, /\/apps\/expensecheck\/api\//)
		expect(
			calls.length,
			`claims list fired ${calls.length} API calls: ${calls.map((c) => c.url).join(', ')}`,
		).toBeLessThanOrEqual(4)
	})

	test('stored-xss: claim title renders escaped on /claims', async ({ page }) => {
		test.skip(!admin, 'Requires admin creds for fixture create')
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
		const lk = await api(page, `${API}/lookups`)
		const catId = lk.data?.categories?.[0]?.id || 0
		const ccId = lk.data?.cost_centers?.[0]?.id || 0
		test.skip(!catId || !ccId, 'no category/cost-center fixture available')
		const created = await api(page, `${API}/claims`, 'POST', {
			title: `${MARK} ${ATLAS_XSS_PAYLOADS[0]}`,
			currency: 'EUR',
			request_key: `${MARK}-xss`,
			lines: [{
				line_type: 'receipt', occurred_on: '2026-10-15',
				description: 'xss probe', amount: '1.00',
				category_id: catId,
				allocations: [{ cost_center_id: ccId, share_percent: '100' }],
			}],
		})
		test.skip(created.status !== 200 && created.status !== 201, `fixture create failed: ${created.status}`)

		await page.goto(`${APP}/claims`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#exp-claims-list')).toContainText(MARK, { timeout: 15_000 })
		await assertNoInjection(page)
	})

	test('mutation freshness: category created via form appears without reload', async ({ page }) => {
		test.skip(!admin, 'Requires admin creds')
		const name = `${MARK} freshness`
		await page.goto(`${APP}/categories`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#exp-cat-form', { timeout: 30_000 })
		await assertSurfaceFresh(page, {
			content: '#exp-cat-list',
			mutate: async () => {
				await page.locator('#exp-cat-code').fill(`${MARK}-fr`.slice(0, 32))
				await page.locator('#exp-cat-name').fill(name)
				await page.locator('#exp-cat-form button[type="submit"]').click()
			},
			visit: async () => { /* in-place list refresh — stay on the surface */ },
			expect: { present: name },
		})
	})

	test('double-submit: category form second click cannot duplicate the write', async ({ page }) => {
		test.skip(!admin, 'Requires admin creds')
		await page.goto(`${APP}/categories`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#exp-cat-form', { timeout: 30_000 })
		await page.locator('#exp-cat-code').fill(`${MARK}-ds`.slice(0, 32))
		await page.locator('#exp-cat-name').fill(`${MARK} dblsubmit`)

		await assertNoDuplicateSubmit(page, {
			mutatingUrl: `${API}/admin/categories`,
			method: 'POST',
			submit: () => page.locator('#exp-cat-form button[type="submit"]')
				.click({ timeout: 5_000 }).catch(() => {}),
		})
	})

	test('form survives a 5xx: category form keeps input + renders error', async ({ page }) => {
		test.skip(!admin, 'Requires admin creds')
		await page.goto(`${APP}/categories`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#exp-cat-form', { timeout: 30_000 })

		await assertFormSurvivesFailure(page, {
			failUrl: `${API}/admin/categories`,
			method: 'POST',
			fields: { '#exp-cat-code': `${MARK}-sx`.slice(0, 32), '#exp-cat-name': `${MARK} survive5xx` },
			submit: () => page.locator('#exp-cat-form button[type="submit"]').click(),
			errorSel: '.exp-toast--error, .exp-field-error:not([hidden])',
		})
	})
})
