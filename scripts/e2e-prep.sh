#!/usr/bin/env bash
# Prepare the shared Nextcloud Docker stack for ExpenseCheck Playwright E2E.
# Run from repo: nextcloud/apps/expensecheck/scripts/e2e-prep.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
NC_DIR="${ROOT}/nextcloud"
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${APP_DIR}/tests/e2e/.env"
if [[ -f "${ENV_FILE}" ]]; then
	set -a
	while IFS= read -r line; do
		case "$line" in
			NC_*=*) eval "export ${line}";;
		esac
	done < <(grep -E '^NC_[A-Z0-9_]+=' "${ENV_FILE}" || true)
	set +a
fi
PASS="${NC_E2E_PASS:-ExpE2eWeb_2026!}"
USER="${NC_E2E_USER:-admin}"
NOACCESS_USER="${NC_E2E_NOACCESS_USER:-e2e-noaccess}"
NOACCESS_PASS="${NC_E2E_NOACCESS_PASS:-McE2eNoAccess_2026!}"

cd "${NC_DIR}"

if ! docker compose ps --status running --services 2>/dev/null | grep -qx nextcloud; then
	echo "error: nextcloud service is not running (cd nextcloud && docker compose up -d)" >&2
	exit 1
fi

APPS_HOST="$(cd "${NC_DIR}/apps" && pwd)"
echo "Purging leftover Atlas quarantine app dirs under ${APPS_HOST}…"
docker compose exec -T nextcloud bash -lc '
set -e
cd /var/www/html/custom_apps
shopt -s nullglob dotglob
removed=0
for stale in *atlas_quarantine*; do
  [[ -d "$stale" ]] || continue
  echo "  removing $stale"
  rm -rf -- "$stale"
  removed=$((removed+1))
done
echo "  purged ${removed} quarantine dir(s)"
' || true

# Dedicated e2e uid — never run the app as the shared admin uid.
echo "Ensuring e2e user ${USER}…"
if docker compose exec -T -u www-data nextcloud php occ user:info "${USER}" >/dev/null 2>&1; then
	docker compose exec -T -u www-data -e OC_PASS="${PASS}" nextcloud \
		php occ user:resetpassword "${USER}" --password-from-env
else
	docker compose exec -T -u www-data -e OC_PASS="${PASS}" nextcloud \
		php occ user:add --password-from-env --display-name "E2E Expense" "${USER}"
fi

echo "Clearing bruteforce for loopback…"
docker compose exec -T -u www-data nextcloud php occ security:bruteforce:reset 127.0.0.1 || true
docker compose exec -T -u www-data nextcloud php occ security:bruteforce:reset ::1 || true

GATEWAY="$(docker compose exec -T nextcloud sh -c "ip route 2>/dev/null | awk '/default/ {print \$3; exit}'" || true)"
if [ -n "${GATEWAY}" ]; then
	echo "Clearing bruteforce for compose gateway ${GATEWAY}…"
	docker compose exec -T -u www-data nextcloud php occ security:bruteforce:reset "${GATEWAY}" || true
fi

# The e2e user needs every primary route: app-admin membership + open access
# restriction on this local stack only.
echo "Granting ${USER} ExpenseCheck app-admin + opening access door…"
docker compose exec -T -u www-data nextcloud \
	php occ config:app:set expensecheck access_restriction_enabled --value=0
docker compose exec -T -u www-data nextcloud \
	php occ config:app:set expensecheck access_allowed_user_ids --value='[]'
docker compose exec -T -u www-data nextcloud \
	php occ config:app:set expensecheck app_admin_user_ids --value="[\"${USER}\"]"

echo "Ensuring no-role user ${NOACCESS_USER}…"
if docker compose exec -T -u www-data nextcloud php occ user:info "${NOACCESS_USER}" >/dev/null 2>&1; then
	docker compose exec -T -u www-data -e OC_PASS="${NOACCESS_PASS}" nextcloud \
		php occ user:resetpassword "${NOACCESS_USER}" --password-from-env
else
	docker compose exec -T -u www-data -e OC_PASS="${NOACCESS_PASS}" nextcloud \
		php occ user:add --password-from-env --display-name "E2E No Access" "${NOACCESS_USER}"
fi

echo "E2E prep done. Credentials: ${USER} / (NC_E2E_PASS); no-role: ${NOACCESS_USER}."

# Lifecycle fixtures drive submit→approve→settle through the real API: the
# e2e uid is its own app admin, so self-approval must be allowed locally and
# the uid must sit in the finance group for the settle gate.
echo "Enabling self-approve + finance membership for local E2E…"
docker compose exec -T -u www-data nextcloud \
	php occ config:app:set expensecheck allow_self_approve --value=1
docker compose exec -T -u www-data nextcloud \
	php occ group:add expensecheck_finance 2>/dev/null || true
docker compose exec -T -u www-data nextcloud \
	php occ group:adduser expensecheck_finance "${USER}" 2>/dev/null || true
