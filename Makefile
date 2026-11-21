# ExpenseCheck — release packaging for Nextcloud App Store
# Usage: make verify-store && make release  then  make sign  (with your key/cert)

app_name = expensecheck
build_dir = build
release_dir = $(build_dir)/release

# Files/dirs to exclude from release archive (store and security)
# README.md, CHANGELOG.md, LICENSE, AUTHORS, SECURITY.md are included (required by store)
release_exclude = \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.cursor' \
	--exclude='.gitignore' \
	--exclude='build' \
	--exclude='node_modules' \
	--exclude='vendor' \
	--exclude='tests' \
	--exclude='e2e' \
	--exclude='docs' \
	--exclude='test-results' \
	--exclude='playwright-report' \
	--exclude='playwright.config.js' \
	--exclude='vitest.config.js' \
	--exclude='package.json' \
	--exclude='package-lock.json' \
	--exclude='phpunit.xml' \
	--exclude='phpunit.integration.xml' \
	--exclude='phpstan.neon*' \
	--exclude='phpstan-bootstrap.php' \
	--exclude='stubs' \
	--exclude='infection*.json5' \
	--exclude='.phpunit.result.cache' \
	--exclude='.phpunit.cache' \
	--exclude='.eslintcache'

.PHONY: release sign clean verify-store

# Store pre-flight: info.xml well-formed + every screenshot URL maps to a
# committed local PNG under screenshots/appstore/.
verify-store:
	php scripts/verify-store-screenshots.php --skip-http

release: clean
	@mkdir -p $(release_dir)
	@tar --mode='u+rwX,go+rX,go-w' --owner=0 --group=0 --numeric-owner -czf $(release_dir)/$(app_name).tar.gz \
		$(release_exclude) \
		--exclude='Makefile' \
		--transform 's,^\.,$(app_name),' \
		.
	@echo "Created $(release_dir)/$(app_name).tar.gz"
	@echo "Add appinfo/signature.json with: make sign"

sign:
	@echo "Sign the app with: php /path/to/nextcloud/occ integrity:sign-app --privateKey=... --certificate=... --path=$(CURDIR)"
	@test -f appinfo/signature.json && echo "appinfo/signature.json already exists." || true

clean:
	@rm -rf $(build_dir)
