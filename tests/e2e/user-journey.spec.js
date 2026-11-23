// @ts-check
/**
 * Bachus user-journey proofs — the create-claim flow exercised as a user,
 * not a smoke check. Asserts the forgiveness/simplification changes:
 * rate select (no free-text codes), occasion gate, inline errors,
 * single-option auto-pick, last-line protection, full create→submit.
 */
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, primaryCreds } from './helpers/auth.js'

const APP = '/index.php/apps/expensecheck'
const NC_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..')

function sql(query) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', 'mariadb',
		'mariadb', '-unextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-e', query,
	], { cwd: NC_DIR, encoding: 'utf8' })
}

test.describe.configure({ mode: 'serial' })

test.describe('create-claim journey', () => {
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
		sql(`DELETE FROM oc_exp_categories WHERE code='BACHUS-MEAL'`)
		sql(`INSERT INTO oc_exp_categories (code, name, receipt_required, requires_attendees,
			allow_lines, active, sort, deleted)
			VALUES ('BACHUS-MEAL', 'Meal (journey test)', 0, 1, 'receipt', 1, 99, 0)`)
		// Single-mileage-rate fixture: the auto-pick proof needs exactly one
		// active mileage rate, so park the doc-seed ones for the run.
		sql(`DELETE FROM oc_exp_rate_entries WHERE code='KM'`)
		sql(`UPDATE oc_exp_rate_entries SET active=0 WHERE rate_type='mileage'`)
		sql(`INSERT INTO oc_exp_rate_entries (rate_type, code, name, currency, rate_minor,
			effective_from, active, created_by, created_at)
			VALUES ('mileage', 'KM', 'Kilometer rate', 'EUR', 30, '2026-01-01', 1, 'e2e', NOW())`)
	})

	test.afterAll(async () => {
		sql(`DELETE FROM oc_exp_categories WHERE code='BACHUS-MEAL'`)
		sql(`DELETE FROM oc_exp_rate_entries WHERE code='KM'`)
		sql(`UPDATE oc_exp_rate_entries SET active=1 WHERE rate_type='mileage'`)
		await context?.close()
	})

	test('line fields are forgiving: rate select, labels, last-line lock', async () => {
		await page.goto(APP + '/claims/new')
		await page.waitForSelector('#exp-claim-form')

		// Single line cannot be removed — no zero-line dead end.
		await expect(page.locator('.exp-line__del').first()).toBeDisabled()

		// Rated types get a select of valid rates, not a free-text code.
		const typeSel = page.locator('select[name="lines[0][line_type]"]')
		await typeSel.selectOption('mileage')
		const rateSel = page.locator('select[name="lines[0][rate_code]"]')
		await expect(rateSel).toBeVisible()
		await expect(rateSel).not.toBeDisabled()
		const optionTexts = await rateSel.locator('option').allTextContents()
		expect(optionTexts.join('|')).toMatch(/Kilometer/)
		// Exactly one mileage rate → auto-picked, zero extra clicks.
		await expect(rateSel).toHaveValue('KM')
		// Receipt fields are correctly disabled for mileage.
		await expect(page.locator('[name="lines[0][amount]"]')).toBeDisabled()
		await expect(page.locator('[name="lines[0][qty]"]')).not.toBeDisabled()

		// FX inputs carry accessible names, not placeholder-only labels.
		await typeSel.selectOption('receipt')
		const fxAmt = page.locator('[name="lines[0][foreign_amount]"]')
		await fxAmt.fill('50')
		const fxCur = page.locator('[name="lines[0][foreign_currency]"]')
		const fxRate = page.locator('[name="lines[0][foreign_fx]"]')
		await expect(fxCur).toHaveAttribute('aria-label', /./)
		await expect(fxRate).toHaveAttribute('aria-label', /./)
		await fxAmt.fill('')
	})

	test('occasion gate appears with attendees on entertainment categories', async () => {
		await page.goto(APP + '/claims/new')
		await page.waitForSelector('#exp-claim-form')
		const catSel = page.locator('select[name="lines[0][category_id]"]')
		await catSel.waitFor()
		// Wait for lookups to populate options (TRAVEL + seeded MEAL).
		await expect(async () => {
			expect((await catSel.locator('option').allTextContents()).join('|')).toContain('Meal (journey test)')
		}).toPass({ timeout: 10_000 })

		const mealOpt = await catSel.locator('option').elementHandles()
		let mealVal = null
		for (const h of mealOpt) {
			if ((await h.textContent()) === 'Meal (journey test)') mealVal = await h.getAttribute('value')
		}
		await catSel.selectOption(mealVal)
		const attIn = page.locator('[name="lines[0][attendees]"]')
		const occIn = page.locator('[name="lines[0][occasion]"]')
		await expect(attIn).toBeVisible()
		await expect(occIn).toBeVisible()
		// Required on the DOM — same gate as the server.
		await expect(occIn).toHaveAttribute('required', '')

		// Full create with both compliance fields filled must not dead-end.
		await page.fill('#exp-f-title', 'Journey meal')
		await attIn.fill('Alex, Sam')
		await occIn.fill('Client lunch')
		await page.fill('[name="lines[0][description]"]', 'Lunch')
		await page.fill('[name="lines[0][amount]"]', '25')
		await page.fill('[name="lines[0][occurred_on]"]', '2026-12-01')
		const ccSel = page.locator('select[name="lines[0][alloc_cc][]"]')
		await ccSel.selectOption({ index: 1 })
		await page.click('#exp-claim-form button[type="submit"]')
		await page.waitForURL(/\/claims\/\d+$/, { timeout: 20_000 })
		await expect(page.locator('#exp-detail-no')).not.toBeEmpty()
	})

	test('allocation mistakes land inline on the line, not only a toast', async () => {
		await page.goto(APP + '/claims/new')
		await page.waitForSelector('#exp-claim-form')
		await page.fill('#exp-f-title', 'Alloc journey')
		await page.fill('[name="lines[0][description]"]', 'Taxi')
		await page.fill('[name="lines[0][amount]"]', '40')
		await page.fill('[name="lines[0][occurred_on]"]', '2026-12-01')
		// Split into two rows summing to 150 → inline per-line error.
		await page.click('.exp-line button:has-text("Split allocation")')
		const pcts = page.locator('.exp-alloc__pct')
		await pcts.nth(0).fill('60')
		await page.click('#exp-claim-form button[type="submit"]')
		const allocErr = page.locator('[data-alloc-error]:not([hidden])')
		await expect(allocErr.first()).toBeVisible()
		// Fixing it clears the inline error on edit.
		await pcts.nth(0).fill('50')
		await pcts.nth(1).fill('50')
		await expect(allocErr).toHaveCount(0)
	})

	test('create → detail → submit completes without dead ends', async () => {
		await page.goto(APP + '/claims/new')
		await page.waitForSelector('#exp-claim-form')
		await page.fill('#exp-f-title', 'Full journey')
		await page.fill('[name="lines[0][description]"]', 'Train ticket')
		await page.fill('[name="lines[0][amount]"]', '19.90')
		await page.fill('[name="lines[0][occurred_on]"]', '2026-12-01')
		const catSel = page.locator('select[name="lines[0][category_id]"]')
		await expect(async () => {
			expect((await catSel.locator('option').allTextContents()).join('|')).toContain('Meal (journey test)')
		}).toPass({ timeout: 10_000 })
		const catOpts = await catSel.locator('option').elementHandles()
		for (const h of catOpts) {
			if ((await h.textContent()) === 'Meal (journey test)') {
				await catSel.selectOption(await h.getAttribute('value'))
				break
			}
		}
		await page.fill('[name="lines[0][attendees]"]', 'Alex, Sam')
		await page.fill('[name="lines[0][occasion]"]', 'Team dinner')
		const ccSel = page.locator('select[name="lines[0][alloc_cc][]"]')
		await ccSel.selectOption({ index: 1 })
		await page.click('#exp-claim-form button[type="submit"]')
		await page.waitForURL(/\/claims\/\d+$/, { timeout: 20_000 })

		// Detail renders the primary action unmistakably, no Version noise.
		const submit = page.locator('#exp-detail-actions button:has-text("Submit")')
		await expect(submit).toBeVisible()
		await expect(page.locator('#exp-detail-body')).not.toContainText('Version')
		await submit.click()
		await expect(page.locator('#exp-detail-body')).toContainText(/submitted|pending/i, { timeout: 15_000 })
	})
})
