# PLAN.md — Rental Manager implementation plan

Read `AGENTS.md` and `docs/DOMAIN.md` first. Execute **one phase per session**, tasks in order.
Each task lists: goal, scope, acceptance criteria (AC), tests. `[AHMED]` = a human step; do not do it, list it in your summary.
Wireframe references (`WF: Approvals`) point to screenshots in `docs/wireframes/<Name>.png`.

## Phase map

| Phase | Outcome | Depends on |
| --- | --- | --- |
| 0 | Repo, tooling, CI, hosting spikes | — |
| 1 | Kernel: DB, migrations, tenancy, money/time, audit, outbox, REST base, test harness | 0 |
| 2 | Identity & access, WordPress hardening, SPA shell | 1 |
| 3 | Settings, Units, Renters, Documents | 2 |
| 4 | Leasing + charge schedule (Billing charges core) | 3 |
| 5 | Billing: manual payments, allocations, credit, receipts + PDF, reversal, statement | 4 |
| 6 | Renter portal + payment submission & approval workflow | 5 |
| 7 | Deposits settlement, Expenses, Maintenance | 6 |
| 8 | Notifications, background jobs, late fees, activity timeline | 7 |
| 9 | Platform control plane: plans, subscriptions, overrides, access modes, support access, export | 8 |
| 10 | Reports + dashboards | 9 |
| 11 | Hardening and beta readiness | 10 |
| 12 (post-MVP) | Public receipt verification + QR, accountant role, OTP login | 11 |

---

## Phase 0 — Foundations

**T0.1 Repository skeleton.** Create the layout from AGENTS.md, `composer.json` (PSR-4, scripts from "Commands"), Strauss config,
`rental-manager.php` with plugin header and minimum-version guard (PHP 8.1, WP 7.0 — raised from 6.4 in T0.7; show admin notice and do not boot if unmet).
AC: plugin activates on a clean WP; `composer check` runs (empty suites pass).

**T0.2 Static analysis and architecture rules.** `phpstan.neon` (level 6), `phpcs.xml` (PSR-12 for `src/`), `deptrac.yaml` encoding
rules 1–6 of AGENTS.md (layers per module, `Api`-only cross-module, exactly the allowed directions listed in AGENTS.md rule 6, Reporting exception). AC: a deliberately bad sample (Domain using `$wpdb`) fails `composer test:arch`; remove the sample after.

**T0.3 Test harness.** PHPUnit config with suites Unit / Application / Integration / Api / Architecture. Integration bootstrap uses
the WordPress PHPUnit test library (`bin/install-wp-tests.sh`) against MySQL 8. `tests/Support/World` builder creating two full accounts
(A and B) with users of each role. AC: an integration smoke test creates WP + a table and rolls back.

**T0.4 CI.** GitHub Actions: PHP 8.1 and 8.3 × MySQL 8.0 and MariaDB 10.6 service; runs all suites + frontend build/test.

**T0.5 Frontend scaffold.** `frontend/` with Vite + React + TS + Tailwind + React Router + TanStack Query, `dir="rtl"` default,
`ar.json`/`en.json` dictionaries, build output to `build/` with a manifest. AC: `npm run build` produces assets; Arabic test page renders RTL.

**T0.6 Hosting spikes (code + checklist).** `Kernel\Health\Checks`: PHP/MySQL versions, InnoDB default engine, FK support,
transaction rollback works, private storage canary (write a file, try to fetch it via HTTP, expect 403/404), `DISABLE_WP_CRON` +
Action Scheduler runner reachable, `sodium` extension, `mbstring`, GD or Imagick, memory limit ≥ 256M.
Expose results on a WP-admin page "Rental Manager → System Health" (System Admin only).
PDF spike: `bin/spike-pdf.php` renders an Arabic RTL receipt sample with mPDF into `tests/artifacts/receipt-sample.pdf`.
`[AHMED]` run the health page and the PDF spike on the real hosting; confirm Arabic shaping in the PDF; report Apache vs Nginx.

**T0.7 Target WordPress 7.1 (production version).** Production runs WordPress 7.1.4. Raise the minimum to WordPress 7.0 (plugin header, bootstrap guard, activation hook, Arabic/English messages, bootstrap tests). Switch CI (backend matrix, integration test library and activation smoke) from 6.4 to the current 7.1 release, pinned to an explicit version (7.1.4); make the version one workflow-level variable. PHP matrix becomes 8.1 (minimum), 8.4 (production) and 8.5 (newest offered by the hosting) × MySQL 8.0 / MariaDB 10.6; fix or document any PHP 8.5 deprecation in our code or locked dev tools (do not relax `failOnWarning`). Re-check the PHPUnit 10 bridge from T0.3 against the 7.1 test library and simplify it if the official bootstrap now supports PHPUnit 10. Rename `.github/workflows/skeleton.yml` to `ci.yml`. Update README, docs/TESTING.md and docs/CI.md. AC: all CI cells green on WordPress 7.1.4 including PHP 8.5; activating on 6.x is refused with the localized message.

**T0.8 Slim the plugin package (PDF fonts).** The packaged plugin is ~44 MB, almost all of it mPDF's bundled fonts (`ttfonts/`) and CJK/other-script data we never use. Keep only the fonts we need: one Arabic+Latin family (DejaVu Sans regular/bold as default; optionally a bundled SIL-OFL Arabic face such as Noto Naskh Arabic or IBM Plex Sans Arabic). Configure mPDF with an explicit `fontdata`/`default_font`, `autoLangToFont` limited to Arabic/Latin, no CJK. Strip unused fonts and data during packaging (Strauss `exclude_from_copy` file patterns or a packaging step), never by editing vendor files in git. Make `.github/workflows/package.yml` and the CI release assembly use the same packaging script (`bin/package.sh`). AC: package < 8 MB; System Health PDF download and `bin/spike-pdf.php` still render connected Arabic with the sample; CI asserts the package size limit.

---

## Phase 1 — Kernel

**T1.1 Container.** Minimal DI container (`set`, `factory`, `get`, singleton/transient, autowiring by constructor type for `RentalManager\` classes only).
Modules register through `module.php` returning a `ModuleDefinition` (services, routes, migrations, listeners, jobs). AC: unit tests for resolution, cycles detected.

**T1.2 DB connection and Unit of Work.** `Connection` wrapping `$wpdb`: `prepare`-only API, throws `DbException` on `last_error`,
`transaction(fn)` with deadlock retry (1 retry on 1213/1205), forbids nesting. `UnitOfWork::run(fn)` collects outbox events and audit entries
and writes them before commit. Transaction strategy is injectable: real `START TRANSACTION/COMMIT` in production,
`SAVEPOINT` / `ROLLBACK TO SAVEPOINT` when the test harness's outer transaction is active (review of T0.3). AC: integration tests: rollback on exception, nested call throws, retry on simulated deadlock.

**T1.3 Migrations.** `Runner` with `rm_migrations`, advisory lock (`GET_LOCK`), runs on plugin version change and on activation,
each migration class `up()` only (no down in prod), DDL helper that always sets `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`.
AC: running twice is a no-op; concurrent run blocked; failed migration stops and reports.
Add `tests/Support/CommittedDatabaseTestCase` (no wrapper transaction; cleans its own `rm_*` tables/rows and users) for DDL tests.

**T1.4 Value objects.** `Money` (int minor, currency, add/sub/compare/allocate/multiplyRatio with rounding modes half_up/half_even and unit),
`LocalDate` (Y-m-d, addMonthsClamped, daysUntil, compare), `Clock` interface + `SystemClock` + `FrozenClock`, `Ulid` generator.
AC: unit tests incl. 31 Jan + 1 month = 28/29 Feb, rounding table tests, Money never accepts float.

**T1.5 Tenancy.** `AccountContext`, `AccountResolver` (interface; real implementation in Phase 2), `TenantRepository` base receiving context,
`TenantQuery` builder that throws if an `rm_*` tenant table lacks the account predicate, `CrossAccount` marker. Request-scoped context holder that throws when empty.
AC: unit tests for the guard; a repository without context cannot be constructed.

**T1.6 Audit writer.** `rm_audit_log` migration; `AuditLog::record(action, entityType, entityId, before, after, meta)` writing inside the UoW;
diff helper; request_id + IP + actor from context. AC: integration test: audit row disappears when UoW rolls back.

**T1.7 Outbox + jobs.** `rm_outbox` migration; `EventRecorder` in UoW; dispatcher job via Action Scheduler (batch 50, retries with backoff, dead-letter status);
`JobRunner` building `AccountContext` from payload. Listener registration per module. AC: event dispatched only after commit; failed listener retried; context set during listener.

**T1.8 Sequences.** `rm_sequences(account_id, name, period, next_value)`; `Sequences::next(name, period)` with `FOR UPDATE` inside the UoW.
AC: concurrency integration test (two connections, on `CommittedDatabaseTestCase`) never duplicates; rollback returns the number.

**T1.9 REST base.** `Router` (declares route, method, permission or `public`, idempotent flag), `BaseController`, request validation helpers,
error envelope + exception mapping (DomainRuleException→422, NotFound→404, Conflict→409...), request_id header, `no-store` headers,
pagination/filter/sort whitelist helpers, `Idempotency` middleware with `rm_idempotency_keys`. Rate limiter on DB table `rm_rate_limits`.
AC: API tests for envelope, 404 mapping, idempotent replay, 409 on key reuse with different body.

**T1.10 Guard tests.** `RouteCoverageTest` (every route declares permission or public), `IsolationMatrixTest` framework that,
given route definitions + a world builder, calls each route as A with B's public ids and expects 404 and no DB change (modules plug in fixtures later).
AC: both run (green with zero business routes).

---

## Phase 2 — Identity, access, shell

**T2.1 Accounts minimal + memberships.** Migrations for `rm_accounts`, `rm_account_settings` (defaults: Africa/Cairo, EGP, ar, `RCPT-`), `rm_memberships`.
WP-admin page (System Admin only) to create an account with an owner (existing or new user). AC: creating an account creates owner membership + settings + audit.

**T2.2 Roles & permissions.** `RolePermissions` static map from DOMAIN §2; `Authorizer` running the 4-gate pipeline (access mode stub = full until Phase 9).
AC: unit tests for the full role × permission matrix.

**T2.3 AccountResolver real implementation.** From current WP user + `X-RM-Account` → active membership → context. Renter context includes `renter_id`.
Endpoint `GET /me` (user, memberships, current account permissions). AC: no header + one membership → that account; no header + many → 400; foreign account → 404.

**T2.4 Invitations.** Create (owner: managers; owner/manager: renter invite with `renter_id` — renter part finished in Phase 3), accept (set password,
create user if needed with placeholder email for mobile-only), revoke, expire. `rm_login_identifiers` + login by mobile or email
(custom login endpoint + `authenticate` filter). AC: token single use, hashed, expired rejected; mobile login works; enumeration-safe messages.

**T2.5 WordPress hardening.** `rm_client` role with `read` only; block `/wp-admin` for non-admins (allow admin-ajax/admin-post); hide admin bar;
restrict core REST `/wp/v2/users*`, `/wp/v2/media*`, `/wp/v2/comments*` to `manage_options`; block `?author=` enumeration; disable XML-RPC;
disable Application Passwords for `rm_client`; session destroy on membership removal.
Update `tests/Api/ApiHarnessTest` to use a test-only route instead of `/wp/v2/users/me`; replace `rm_test_*` fixture adapters in `World` with the production tables from T2.1.
AC: tests asserting each restriction; `rm_client` capability list equals `['read']`.

**T2.6 App shell.** Rewrite `/app/*` → `templates/app.php` (no theme, no `wp_head` from theme; enqueue only built SPA + nonce + boot data), login page `/app/login`.
SPA base path comes from boot data (no hardcoded `/app/`; WordPress may be in a subdirectory); locale comes from `/me` settings. SPA: auth guard, account switcher, layout with owner sidebar and renter bottom nav, i18n, RTL, nonce refresh on `rest_cookie_invalid_nonce`, global error toast with request_id.
AC: E2E: owner logs in and sees empty dashboard in Arabic; renter sees renter home; client hitting /wp-admin is redirected.
WF: RenterLogin, Main (shell only).

**T2.7 Users management.** Endpoints + UI: list members, invite manager, change role (rules from DOMAIN §2), remove. WF: Settings (users card).

---

## Phase 3 — Settings, Units, Renters, Documents

**T3.1 Account settings.** GET/PUT `/settings` (owner). AC: invalid timezone/currency rejected; audit.

**T3.2 Units.** CRUD + archive, filters (status derived, type), `units.max` check with a temporary constant limit (replaced in Phase 9).
Derived status reads contracts via `Leasing\Api` (returns `available` until Phase 4). AC: isolation + permission tests; limit test under concurrency.
WF: Units, UnitDetail (overview/details card).

**T3.3 Renters.** CRUD, party type, encrypted national id (libsodium, last4 stored), masked in responses, `POST /renters/{id}/reveal-id` (audited),
invite to portal (uses T2.4). AC: raw ID never in list/detail responses; reveal writes audit; renter cannot read other renters.
WF: RenterDetail (profile card).

**T3.4 Private storage.** `StorageInterface` + `PrivateLocalStorage` (path from `RM_STORAGE_PATH` constant if outside webroot, else
`uploads/rm-private-<random>/` with `.htaccess` deny + `index.php`), account folder by public id, random file names without extension.
AC: integration test; health canary (T0.6) uses it.

**T3.5 Documents.** Upload endpoint (multipart, size limits per type, finfo, allowlist jpg/png/webp/pdf, image re-encode via GD/Imagick with EXIF strip,
max 6000×6000 px), metadata in `rm_documents`, attach API for other modules (`Documents\Api\DocumentLinker`), download endpoint streaming with
`Content-Disposition`, `nosniff`, `no-store`; audited for sensitive; orphan cleanup job (24h). Storage usage counter. AC: spoofed MIME rejected,
EXIF removed (test fixture with GPS), cross-account 404, renter only own.

---

## Phase 4 — Leasing and charge schedule

**T4.1 Billing charges core.** `Charge` aggregate + repository + `Billing\Api\ChargeScheduler` (`generateForContract`, `cancelFutureUnpaid`,
`addCharge`, `waive`). Statuses per DOMAIN §8 (allocation parts arrive in Phase 5). AC: unit tests for status rules; unique constraint test.

**T4.2 Schedule calculator (domain service).** Implements DOMAIN §7 exactly: anchoring, clamping, due_day, proration of last period, annual increase,
deposit charge, tracking_start_date. AC: table-driven unit tests ≥ 25 cases (31st start, leap years, quarterly, increase percent/fixed, rounding unit 1 EGP).

**T4.3 Contract aggregate + lifecycle.** Draft create/update, activate (lock unit row, overlap check, generate charges via T4.1 in same UoW),
terminate, close, discard; derived statuses; renewal (new draft with `renews_contract_id`, deposit carry-over option).
AC: overlap concurrency integration test (two parallel activations → exactly one active); termination cancels only unpaid future charges.

**T4.4 Opening balance onboarding.** DOMAIN §6 "Existing contract": opening arrears charges, opening deposit, opening credit (transactions without receipts —
transactions table migration introduced here, minimal). AC: renter statement (later) starts with correct opening balance; no receipt numbers consumed.

**T4.5 Amendments.** Rent change with effective date per DOMAIN §6. AC: blocked when a partially paid charge exists in range.

**T4.6 Contracts API + UI.** Endpoints for list/detail/create/activate/terminate/renew/amend/close; wizard steps: unit & renter → terms & schedule preview
(server-calculated preview endpoint, no DB write) → opening balance → documents → review & activate. Unit detail shows current contract + timeline placeholder.
WF: ContractWizard, UnitDetail.

---

## Phase 5 — Billing core (owner side)

**T5.1 Transactions and allocations.** Aggregates, invariants of DOMAIN §10 under row locks (ascending id), FIFO allocator domain service,
charge status recalculation. AC: property-based test (random payments/allocations/reversals, ≥ 500 iterations) asserting all invariants.

**T5.2 Record manual payment.** `RecordPayment` use case (shared core with approval later): renter, amount, received_on, method, reference, allocation lines
(default FIFO), remainder → credit; idempotency key required. AC: double submit with same key → one transaction.
WF: ManualPayment.

**T5.3 Receipts.** Issue in the same UoW (sequence + snapshot), list/register with filters, detail. AC: unique per transaction; snapshot unchanged after renter rename.

**T5.4 Receipt PDF.** mPDF renderer from snapshot (Arabic/RTL font bundled, remote resources disabled, all text escaped), generated outside the
DB transaction, cached as a document `receipt_pdf`; regenerated if missing. Template shows "not a tax invoice". AC: snapshot test of HTML; PDF generated in CI.
WF: Receipt (preview card).

**T5.5 Reversal / void.** Owner-only, reason required, password re-entry (`wp_check_password`), DOMAIN §11 steps in one UoW. AC: charges reopen; receipt voided but readable; reverse twice → 409.
WF: Receipt (void panel).

**T5.6 Credit allocation and refund.** Allocate existing credit to a charge; refund credit (`out`). AC: credit never negative.

**T5.7 Waive & ad-hoc charges.** Add charge (utility/other), waive remaining with reason. AC: cannot waive deposit charge.

**T5.8 Renter statement.** Query service: opening balance at `from`, chronological lines (charges debit, payments credit, reversals, waivers),
running balance, pending submissions shown separately (not in balance). PDF + CSV export. AC: statement balance = Σcharges − Σnet allocations − waivers for any range.
WF: RenterDetail (statement).

---

## Phase 6 — Renter portal and approval workflow

**T6.1 Submissions.** Create (renter only, own contract, proof document required unless method=cash), edit when `clarification_requested`, withdraw;
events log; duplicate detection flag. AC: renter cannot submit for another renter's charge (404); events appended on each change.

**T6.2 Approve / reject / clarify.** Use cases per DOMAIN §9 reusing T5.2 core; approver may change confirmed amount, received_on and allocation.
AC: concurrency test (two approvals in parallel → 1 transaction, 1 receipt, second gets 409); reject creates no financial rows.

**T6.3 Owner approvals UI.** Queue + detail with proof viewer (zoom/rotate), duplicate warning, allocation editor, actions with reason select, event history.
WF: Approvals.

**T6.4 Renter portal UI.** Home (balance, next due, pending submission banner, latest receipt, contract summary), Submit payment (charges checklist, method pills,
proof upload with camera on mobile), Payments & receipts list (statuses incl. clarification with edit/resubmit), statement tab, receipt PDF download, documents.
Mobile-first 360–430px. WF: RenterHome, RenterSubmit, RenterPayments.

---

## Phase 7 — Deposits, Expenses, Maintenance

**T7.1 Deposit settlement.** Aggregate + endpoints + UI per DOMAIN §12, held deposits list. AC: finalized immutable; unpaid_charge line closes that charge; refund transaction created.
WF: Deposit.

**T7.2 Expenses.** CRUD with attachment, categories fixed list, responsibility → charge via Billing Api. AC: renter responsibility creates exactly one charge; deleting an expense with a paid charge is blocked (archive instead).

**T7.3 Maintenance.** Requests (owner + renter create), updates with photos, assignment, status machine, resolve with cost → expense via Expenses Api. Renter UI list + create.
WF: Expenses (board + detail), RenterMaintenance.

---

## Phase 8 — Notifications, jobs, timeline

**T8.1 Notifications module.** `rm_notifications`, listeners on outbox events, dedupe keys, bell + list + mark read endpoints/UI, per-user preferences (in-app always on; email toggles).
**T8.2 Email channel.** `NotificationChannel` interface; `WpMailChannel` (SMTP configured by host/plugin), Arabic templates; sent from jobs only.
**T8.3 Scheduled jobs.** Daily per-account chunks: due-soon/due-today/overdue reminders, contract expiring 60/30, late-fee generation (DOMAIN §13), orphan documents, outbox cleanup.
Cron: `DISABLE_WP_CRON` documented + Action Scheduler; every job idempotent. AC: running a job twice creates no duplicates.
**T8.4 Activity timeline.** Projection from outbox events into `rm_activity` (unit, renter, contract scopes); rebuild command (`wp rm activity:rebuild`).
WF: UnitDetail (timeline card).

---

## Phase 9 — Platform control plane

**T9.1 Plans & entitlements.** Definitions, plans, versions, seed Starter/Standard/Pro; `EntitlementResolver` (override replaces); replace Phase 3 constant limit.
**T9.2 Subscriptions & access mode.** Status, grace days, `AccessMode` gate active in the Authorizer (DOMAIN §3), banner data in `/me`. AC: read-only blocks writes except export; renters keep receipts in blocked mode.
**T9.3 Admin screens (WP-admin, System Admin only, 2FA required — use a vetted 2FA plugin `[AHMED]` or TOTP in-house).** Accounts list with filters, account detail: plan, usage, extend, change plan, suspend/reactivate, overrides (reason required, expiry), audit list. WF: AdminAccounts, AdminAccount.
**T9.4 Support access.** Grants per DOMAIN §17, owner approval UI (Settings), support-mode banner, audit `on_behalf_of`. AC: expired grant denied; read_only grant cannot write or download sensitive docs.
**T9.5 Data export.** Owner exports all account data as a ZIP of CSVs (+ documents optional) via background job, link valid 24h. Allowed in read_only/blocked.

---

## Phase 10 — Reports and dashboards

**T10.1 Dashboard endpoint.** One aggregated `GET /dashboard` (KPIs, attention list, overdue, expiring, open maintenance, 6-month collected vs expected). Must run ≤ 10 queries.
WF: Main.
**T10.2 Five MVP reports.** Overdue (aging buckets), monthly income (collected confirmed vs expected, deposits excluded), renter statement (from T5.8), receipt register, held deposits. Filters, CSV + PDF export.
Query services in `Reporting` (no Domain repositories). WF: Reports.
**T10.3 Unit statement.** Income, expenses, net, occupancy days per unit and year. WF: UnitDetail (statement card).

---

## Phase 11 — Hardening and beta

**T11.1** Security checklist pass (AGENTS.md security + DOMAIN rules), fix findings; `[AHMED]` external review/pentest.
**T11.2** Performance: seed 500 accounts × 10 units × 3 years; measure p95 of main endpoints; add missing indexes; N+1 audit.
**T11.3** Backup/restore runbook (`docs/OPERATIONS.md`) incl. encryption key handling; restore test.
**T11.4** Error logging (PHP errors + REST 5xx to a log table/file with request_id), System Health page complete.
**T11.5** Privacy: consent checkbox for storing ID images, privacy policy page placeholder `[AHMED: legal review under Egyptian Law 151/2020]`, account purge procedure (platform admin, audited).
**T11.6** E2E suite: login (owner, renter-by-mobile), create unit→contract→manual payment→receipt PDF, renter submit→owner approve→receipt, reverse, deposit settlement — in Arabic.
**T11.7** Release packaging: `bin/build-release.sh` (composer install --no-dev, Strauss, frontend build, zip without tests/frontend src).

---

## Release blockers (CI must fail on any)

- Isolation matrix failure on any route; any route without permission declaration.
- Financial invariant property test failure; double approval producing >1 transaction or receipt; rejection producing a transaction.
- Voided receipt deleted or changed; two overlapping active contracts on one unit.
- Private file reachable by direct URL (health canary); `rm_client` has any capability other than `read`.
- deptrac violation; migration from previous release fails on the fixture dataset.

## Open decisions (defaults used unless Ahmed changes them)

| # | Decision | Default in this plan |
| --- | --- | --- |
| D1 | Renter without email | Mobile-number login, owner shares invite link/code manually; OTP post-MVP |
| D2 | Frontend stack | React + TS + Vite + Tailwind, assets committed |
| D3 | PDF library | mPDF (confirm in T0.6 spike) |
| D4 | 2FA for System Admin | Existing vetted plugin on the admin account `[AHMED to pick]` |
| D5 | Rounding default | Nearest 1 EGP, half-up |
| D6 | Late fee | One stage, fixed or percent, after grace days |
| D7 | Receipt QR/public verification | Post-MVP (Phase 12) |
| D8 | Email delivery | SMTP via host or an SMTP plugin `[AHMED to configure]` |
