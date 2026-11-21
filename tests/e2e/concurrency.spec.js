// @ts-check
/**
 * Zeus §3 live race proofs — the failure modes that cannot be verified with
 * mocks: two HTTP requests genuinely in flight at the same time.
 *
 * Basic auth (app-password class) — the SessionCsrfMiddleware exempts
 * non-ambient auth, so no requesttoken is needed. Ground truth is the
 * server state afterwards, not either individual response.
 */
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const api = '/index.php/apps/expensecheck/api'
const NC_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..')

function sql(query) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', 'mariadb',
		'mariadb', '-unextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-e', query,
	], { cwd: NC_DIR, encoding: 'utf8' })
}

function basicAuth(user, pass) {
	return { Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64') }
}

async function createDraft(request, user, pass, requestKey) {
	const res = await request.post(`${api}/claims`, {
		headers: { ...basicAuth(user, pass), 'Content-Type': 'application/json' },
		data: {
			title: 'race probe',
			currency: 'EUR',
			request_key: requestKey,
			lines: [{
				line_type: 'receipt', occurred_on: '2026-12-15', // Nov 2026 is closed in the e2e fixture
				category_id: 1, amount: '10.00',
				allocations: [{ cost_center_id: 2, share_percent: '100' }],
			}],
		},
	})
	return res
}

test.describe('live concurrency proofs', () => {
	const user = process.env.NC_E2E_USER
	const pass = process.env.NC_E2E_PASS

	test.skip(!user || !pass, 'NC_E2E credentials not set')

	test('parallel creates with same request_key produce exactly one claim', async ({ request }) => {
		const key = `race-${Date.now()}`
		const results = await Promise.all(
			Array.from({ length: 5 }, () => createDraft(request, user, pass, key)),
		)
		const ids = new Set()
		for (const r of results) {
			const body = await r.json()
			expect(r.ok(), `create failed: ${JSON.stringify(body)}`).toBeTruthy()
			ids.add(body.claim.id)
		}
		expect(ids.size, 'parallel creates returned different claim ids').toBe(1)

		// DB truth: exactly one claim carries that request key.
		const list = await request.get(`${api}/claims`, { headers: basicAuth(user, pass) })
		const { claims } = await list.json()
		const mine = claims.filter((c) => ids.has(c.id))
		expect(mine.length).toBe(1)
	})

	test('parallel submits leave exactly one submit event (CAS serialises)', async ({ request }) => {
		const res = await createDraft(request, user, pass, `race-sub-${Date.now()}`)
		const { claim } = await res.json()

		const results = await Promise.all(
			Array.from({ length: 5 }, () =>
				request.post(`${api}/claims/${claim.id}/submit`, {
					headers: basicAuth(user, pass),
				})),
		)
		const statuses = results.map((r) => r.status())
		// At most one may win the state transition; losers must be clean 4xx,
		// never 5xx and never a silent double-apply.
		expect(statuses.filter((s) => s >= 500), `5xx seen: ${statuses}`).toHaveLength(0)
		const wins = statuses.filter((s) => s < 300).length
		expect(wins, `expected exactly one submit win, got ${statuses}`).toBe(1)

		// Journal truth: exactly one status transition recorded.
		const events = await request.get(`${api}/claims/${claim.id}/events`, {
			headers: basicAuth(user, pass),
		})
		const { events: evs } = await events.json()
		const transitions = evs.filter((e) => e.to_status && e.to_status !== 'draft')
		expect(transitions.length).toBe(1)
		const detail = await request.get(`${api}/claims/${claim.id}`, {
			headers: basicAuth(user, pass),
		})
		const d = await detail.json()
		expect(['submitted', 'pending', 'pending_admin_review']).toContain(d.claim.status)
	})
	test('parallel settles produce exactly one batch (CAS double-payout race)', async ({ request }) => {
		const marker = `settle-race-${Date.now()}`
		sql(`INSERT INTO oc_exp_claims (claim_no, user_id, title, status, currency,
			total_minor, reimbursable_total_minor, org_total_minor, version, created_at, updated_at)
			VALUES ('EXP-${marker.slice(-9)}', 'exp.tester', '${marker}', 'approved', 'EUR',
			1000, 1000, 1000, 3, NOW(), NOW())`)
		const claimId = parseInt(sql(`SELECT id FROM oc_exp_claims WHERE title='${marker}'`), 10)
		const admin = process.env.NC_ADMIN_USER
		const adminPass = process.env.NC_ADMIN_PASS
		test.skip(!admin || !adminPass, 'NC_ADMIN credentials not set')

		const results = await Promise.all(
			Array.from({ length: 3 }, () =>
				request.post(`${api}/settlements`, {
					headers: { ...basicAuth(admin, adminPass), 'Content-Type': 'application/json' },
					data: { claim_ids: [claimId], paid_ref: marker, format: 'csv' },
				})),
		)
		const statuses = results.map((r) => r.status())
		expect(statuses.filter((s) => s >= 500), `5xx seen: ${statuses}`).toHaveLength(0)
		expect(statuses.filter((s) => s < 300).length, `expected exactly one settle win: ${statuses}`).toBe(1)

		// DB truth: exactly one batch, one row, claim settled once.
		const rowCount = parseInt(sql(
			`SELECT COUNT(*) FROM oc_exp_settlement_rows WHERE claim_id=${claimId}`), 10)
		const batchCount = parseInt(sql(
			`SELECT COUNT(DISTINCT batch_id) FROM oc_exp_settlement_rows WHERE claim_id=${claimId}`), 10)
		const settled = sql(`SELECT status FROM oc_exp_claims WHERE id=${claimId}`).trim()
		expect(rowCount).toBe(1)
		expect(batchCount).toBe(1)
		expect(settled).toBe('settled')
		sql(`DELETE b, r FROM oc_exp_settlement_batches b LEFT JOIN oc_exp_settlement_rows r
			ON r.batch_id=b.id WHERE r.claim_id=${claimId}`)
		sql(`DELETE FROM oc_exp_claims WHERE id=${claimId}`)
	})
})
