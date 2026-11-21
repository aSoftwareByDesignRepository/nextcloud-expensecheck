/** Fail fast when Nextcloud served a 404 / access-masked page instead of the app. */
export async function assertNotNcDeadEnd(page, context = 'navigation') {
	const dead = page.getByRole('heading', { name: /page not found|seite nicht gefunden/i })
	if (await dead.isVisible({ timeout: 800 }).catch(() => false)) {
		throw new Error(
			`Nextcloud “Page not found” during ${context} at ${page.url()} — check app enablement, access restriction, and routes (run npm run e2e:prep)`,
		)
	}
}

export async function login(page, { username, password }) {
	await page.goto('/index.php/login', { waitUntil: 'networkidle' })

	const updateNeeded = page.getByRole('heading', { name: /update needed|aktualisierung erforderlich/i })
	if (await updateNeeded.isVisible({ timeout: 1500 }).catch(() => false)) {
		throw new Error('Nextcloud shows “Update needed” — run `docker compose exec -u www-data nextcloud php occ upgrade` before E2E')
	}

	const maintenance = page.getByText(/maintenance mode|update is in progress|needs to be updated|app update required|start update/i)
	if (await maintenance.first().isVisible({ timeout: 1500 }).catch(() => false)) {
		throw new Error('Nextcloud is in maintenance/upgrade mode — finish `occ upgrade` before E2E')
	}

	// NC 32+ login is a Vue mount on #login — wait for the hydrated form.
	const userInput = page
		.getByRole('textbox', { name: /account name|email|benutzername|kontoname|e-mail/i })
		.or(page.locator('#user'))
		.or(page.locator('input[name="user"]'))
		.or(page.locator('input[type="text"]'))
		.first()
	const passInput = page
		.getByRole('textbox', { name: /^password$|^passwort$/i })
		.or(page.locator('#password'))
		.or(page.locator('input[name="password"]'))
		.or(page.locator('input[type="password"]'))
		.first()

	await userInput.waitFor({ state: 'visible', timeout: 30_000 })
	await userInput.fill(username)
	await passInput.fill(password)
	await page.getByRole('button', { name: /log in|anmelden/i }).or(page.locator('input[type="submit"]')).first().click()

	const wrong = page.getByText(/wrong login or password|falscher login|ungültige anmeldedaten/i)
	const leftLogin = page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	const sawWrong = wrong.first().waitFor({ state: 'visible', timeout: 30_000 }).then(() => 'wrong')
	const winner = await Promise.race([
		leftLogin.then(() => 'ok'),
		sawWrong,
	])
	if (winner === 'wrong') {
		throw new Error(`Login failed for user "${username}" — wrong login or password`)
	}
}

export function credsFromEnv(prefix = 'E2E') {
	const username = process.env[`NC_${prefix}_USER`]
	const password = process.env[`NC_${prefix}_PASS`]
	if (!username || !password) {
		return null
	}
	return { username, password }
}

/** Prefer E2E user (stable in shared Docker), then ADMIN. */
export function primaryCreds() {
	return credsFromEnv('E2E') || credsFromEnv('ADMIN')
}

/** App-admin capable creds — prefer E2E when it is an NC admin (shared Docker). */
export function adminCreds() {
	return credsFromEnv('E2E') || credsFromEnv('ADMIN')
}
