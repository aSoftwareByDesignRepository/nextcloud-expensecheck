import { defineConfig, devices } from '@playwright/test'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import baseConfig from '../playwright.config.js'

/**
 * Dedicated config for the ATLAS screenshot-matrix capture
 * (e2e/capture-screenshot-matrix.spec.js). The spec lives outside the main
 * suite's testDir (tests/e2e) on purpose — the farm gate
 * check-screenshot-matrix.py expects the contract at e2e/ — so it gets its
 * own config rather than slowing every default `npx playwright test` run.
 *
 * Inherits env loading, baseURL, timeouts, and the shared-instance lock
 * (global setup/teardown) from playwright.config.js.
 *
 * Run (from the app dir):
 *   npx playwright test -c e2e/playwright.screenshot-matrix.config.mjs
 */

const here = dirname(fileURLToPath(import.meta.url))

export default defineConfig({
	...baseConfig,
	testDir: here,
	testMatch: 'capture-screenshot-matrix.spec.js',
	globalSetup: resolve(here, '../tests/e2e/global-setup.mjs'),
	globalTeardown: resolve(here, '../tests/e2e/global-teardown.mjs'),
	projects: [{ name: 'matrix', use: { ...devices['Desktop Chrome'] } }],
})
