# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.3 - 2026-11-24

### Changed
- All views rewired to the Check-family component recipes; section chip bars
  scroll horizontally instead of overflowing on small screens.
- MobilityCheck integration surface stays hidden until the sibling app ships;
  the store description no longer advertises the link.

### Fixed
- Cataloged the 12 msgids introduced by the view rewire across all locales.
- Get the App page + MobileAppLinks + Mobile nav group
- design-system sweep — access-door UI, scope=all, labels, dead ends
- audit hardening — coexistence picker, sibling-absent + integration e2e, NN-fence/EXIF/golden-vector proofs
- recipe-B status pills + persona nav/API parity spec

### Changed

- focus-preservation contract spec — ds_chrome lane residue
- complete App Store package — files, screenshots, schema-valid info.xml
- fix stale selectors + CSRF-aware persona spec after license-pack/nav changes
- add get-the-app surface to screenshot-matrix
- refresh e2e screenshot-matrix
- update public-routes allowlist for get-the-app
- Wire canonical license page + Support & us section
- Server-authoritative can.* affordances, full retention schedule, mobile ETags, glossary

## 1.0.2 - 2026-11-21

### Added
- **Get the App page** — companion download surface with MobileAppLinks and a
  Mobile nav group, reachable as a public route.
- **License page + Support & us section** wired to the canonical shared pack.
- **Access-denied template** for clean, localized refusal states.
- **Suite integrations (M1.6/M3)** — sibling read facades, capabilities
  pipeline, server-authoritative `can.*` affordances for the mobile client,
  mobile ETags, and a full retention schedule.
- **End-to-end stack** — Playwright persona spec, CSRF-aware flows, and the
  screenshot-matrix surface set.

### Changed
- Design-system sweep: access-door UI, `scope=all` coverage, labels, and dead
  ends removed.
- Recipe-B status pills on claims; persona nav/API parity spec.

### Fixed
- **Security:** self-approve flag is now honored at decision time; submit and
  decision endpoints sit behind `IThrottler`; PostgreSQL portability and
  mutation-hardening pass.
- **Audit hardening:** coexistence picker, sibling-absent integration proofs,
  NN-fence, EXIF handling, and golden-vector proofs.
- **Localization:** 206 real msgids registered and translated across all 15
  locales; page leads routed through l10n with real plural forms; real page
  titles; claim-vocabulary heal; JS catalogs regenerated.
- Help dropdown dead entries removed; Support & us surfaced in sidebar nav.

## 1.0.1 - 2026-11-20

### Added
- **ODS + async export path** with single-use download tokens (spec §11.10).
- **Approver digest mail channel** — real delivery channel for pending-decision
  digests.
- **Config-transfer integration + upgrade-backup suite** (spec §12.6).
- **Sibling read facades (F22/F23)** and billed marker for suite consumers.
- **GDPR erasure service** with journal tombstoning (MH-15).
- **Notifications + capabilities pipeline** (§12.7) and suite discovery.
- Contract census tests as drift detectors (spec §15.2).
- Column-level schema census + settings census tests (§10.7).
- Mobile create endpoint accepts `lines[]` atomically (AC-M1 contract parity);
  late-sync acceptance contract (edge 41, §13.3).

### Fixed
- Claim-create atomicity and web envelope drift.
- `billed_at` entity drift.
- Category-gated line rules and server-side policy flags enforced.

## 1.0.0 - 2026-11-20

### Added
- **Initial release.** Complete employee expense reimbursement inside
  Nextcloud:
  - Claim lifecycle: draft → submit → approve → settle, with withdraw and
    changes-requested, every transition audited.
  - Line types: receipt (amount + tax + evidence), mileage (distance ×
    catalog rate), per-diem (days × catalog rate), flat.
  - Receipt attachments (camera/gallery/PDF/XML), 1–10 files per line;
    receipts inbox for capture-first filing. No OCR.
  - Versioned, effective-dated rate catalog; claims snapshot the rate at
    submit; missing rate fails closed.
  - Cost centers (tree), split allocations per line, optional
    project/customer soft-links, budget caps with warnings.
  - Configurable approval chains (1..N steps by group, named user or line
    manager; amount/category routing).
  - Finance settlement queue with batch settlement, payment references, and
    formula-injection-safe CSV export.
  - Append-only hash-chained audit journal; settled claims immutable; period
    close.
  - Access door (open/restricted + groups/users + app-admin role), settings
    pages, WCAG 2.1 AA responsive UI in 15 languages.
  - EXP2 companion contract for the licensed Android app.
