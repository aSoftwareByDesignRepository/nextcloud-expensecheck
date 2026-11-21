# COPIED-SOURCES — reuse & port-back ledger (spec §15.2)

Every copied/adapted unit in this repo is registered here with its upstream
source and the source commit hash at copy time. The release checklist diffs
each source for upstream security fixes — the port-back obligation is ≤14
days (KZC D-11/G-08 mechanism). Update this file whenever a copied file is
re-synced or a new copy lands.

## Synced (never fork — `_shared` packs, `--check` gates)

| File(s) | Source pack | Sync gate |
|---------|-------------|-----------|
| `css/common/*`, `js/common/{field-errors,focus-preservation,view-components,mobile-nav,toasts}.js`, `templates/common/*`, `lib/Support/NavGate.php`, `lib/Service/IconCatalog.php` | `nextcloud/apps/_shared/check-shell` + `field-errors` + `focus-preservation` + `view-components` + `nav-gate` packs | `sync_check_shell.py --check`, `sync_view_components.py --check` |

## Copied-and-adapted (renamed, app-owned — upstream diffs reviewed at release)

| This repo | Upstream source | Source commit (copied) | Port-back watch |
|-----------|-----------------|------------------------|-----------------|
| `lib/Service/ExpChainService.php` | `ticketcheck` `lib/Service/ChainService.php` | `044c10c6258346d8240893a221ffa1873c0418ff` | security fixes → TCK journal/chain code |
| `lib/Service/PostCommitEffectQueue.php` | `arbeitszeitcheck` `lib/Service/PostCommitEffectQueue.php` | `38b1eaae0c3108eb7b0f717b4c8364ee76551eb6` | AZC effect-queue fixes |
| `lib/Middleware/{SessionCsrf,ApiException,ClientLicense}Middleware.php`, `lib/Exception/{Companion,SessionCsrf}Exception.php`, `lib/Service/CsrfTokenValidator.php` | `ticketcheck` middleware suite + `Companion*` taxonomy | `044c10c6258346d8240893a221ffa1873c0418ff` | TCK middleware/security fixes |
| `lib/Service/CsvExporter.php` | `sbdlicenseops` `lib/…/CsvExporter.php` + `InputCoercion` boolean-shape | `50036805d99e51dc6a7eb88c0472e9d38b13a810` | formula-injection fixes |
| `lib/Service/{ClaimLifecycleService,ApprovalService}.php` CAS/journal patterns | `ticketcheck` lifecycle/decision services | `044c10c6258346d8240893a221ffa1873c0418ff` | CAS race fixes |
| `lib/License/Exp2Codec.php`, `lib/Service/LicenseService.php` | `dutycheck` DTY2 playbook + `sbdlicenseops` `WirePrefixRegistry` (`EXP2`) | — | signing codec fixes |

## Consumed via facade (never copied — capability-detected, fail-closed)

| Contract | Provider | Version |
|----------|----------|---------|
| F20 org resolution (`managersFor`, `canManage`, `hasManager`) | `OCA\ArbeitszeitCheck\Public\AzcOrgReadFacade` (sister delta — lands with AZC next minor; standalone fallback `exp_manager_map` ships in-app) | `FACADE_VERSION 1` |
| F25 drive segments → mileage drafts | `mobilitycheck` `check:drive-segments` producer surface | contract v1 |
| `check:expense-claims` ingress (this app produces; siblings consume) | this app `IngressApiController` | v1 |

## Ported back upstream (this app → shared pack)

| Change | Destination | Date |
|--------|-------------|------|
| `ApiError`: `error_code` field + `code` alias; `ok` envelope tolerance (`body.data !== undefined ? body.data : body`); `{P}ErrorCopy` hook in `handleError` (§9.2) | `_shared/check-shell/js/app.js.tpl` — siblings inherit on their next re-sync | 2026-11-21 |

## Not yet ported (open §15.2 rows)

| Need | Planned source | Status |
|------|----------------|--------|
| Settlement PDF (per-claimant sums + appended receipts) | AZC `MonthClosurePdfDocumentBuilder` | open |
| ~~Approver digest mail service~~ | AZC `ManagerPendingApprovalMailService` | **done** — `DigestMailService` + `DigestMailJob` (daily/weekly/off, UNIQUE dedupe_key, delegate fan-out) |
| ~~ODS export builder~~ | TCK `OdsBuilder` | **done** — ported into `ExportService` (deterministic meta date, injection-safe) |
