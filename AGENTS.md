# AGENTS.md — Rental Manager

Instructions for any coding agent working in this repository. Read this file fully before every task.
Then read `docs/DOMAIN.md` (the business rules) and the phase you were assigned in `docs/PLAN.md`.

## What this is

Rental Manager is a multi-tenant SaaS for individual property owners (1–25 units) to manage units,
renters, contracts, charges, payments, receipts, deposits, expenses and maintenance.
It ships as a **WordPress plugin** that hosts a **standalone SPA** (owner portal + renter portal).
WordPress is the host platform only: users, auth cookies, REST transport, cron runner, hosting.
Target market: Egypt first. **Arabic / RTL is the primary UI language**, English secondary.
Target hosting: shared hosting first, VPS later.

## How to work

- Work on **one phase at a time**, task by task, in the order of `docs/PLAN.md`. Do not start the next phase.
- One task = one focused commit (or PR). Commit message: `T<phase>.<n>: <summary>`.
- Every task ends with: tests written and passing, `composer check` green, task's acceptance criteria met.
- If a requirement is ambiguous or conflicts with `docs/DOMAIN.md`, **stop and write the question** in
  `docs/QUESTIONS.md` (task id, question, options, your recommended default). Then continue with the
  recommended default only if it is easy to change later; otherwise stop.
- Never invent business rules. `docs/DOMAIN.md` is the source of truth for rules, states and invariants.
- Never weaken a test to make it pass. Never delete or skip a failing isolation/financial test.
- Do not add dependencies beyond those listed in "Stack" without writing the reason in `docs/QUESTIONS.md`.

## Stack (fixed)

- PHP **8.1+**, MySQL **8.0+** or MariaDB **10.6+**, **InnoDB** and `utf8mb4` only.
- WordPress **7.0+** (production runs **7.1.x**; keep CI on the current 7.1 release). Single site. **No Multisite.**
  Rental Manager is a SaaS on one install we control, so only current WordPress versions are supported.
- Composer. Bundled vendor libraries are namespace-prefixed with **Strauss** into `RentalManager\Vendor\`,
  except **Action Scheduler**: ship it unchanged in `vendor/woocommerce/action-scheduler/` and load its
  `action-scheduler.php` before `plugins_loaded` completes so its shared version registry remains intact.
- Libraries: `woocommerce/action-scheduler` (background jobs), `mpdf/mpdf` (PDF, Arabic/RTL).
  Everything else (DI container, ULID, Money, Clock) is small in-house code in `src/Kernel`.
- Tests: PHPUnit 10, `deptrac` (architecture rules), PHPStan level 6 with `szepeviktor/phpstan-wordpress`,
  PHP_CodeSniffer PSR-12.
- Frontend: React 18 + TypeScript + Vite, React Router, TanStack Query, Tailwind CSS (logical properties
  only: `ms-/me-/ps-/pe-`, never `ml-/mr-`), i18n via JSON dictionaries (`ar` default, `en`).
  Built assets are committed to `build/` (shared hosting has no Node).
- E2E: Playwright (few tests only).

## Names

| Thing | Value |
| --- | --- |
| Plugin slug / text domain | `rental-manager` |
| PHP root namespace | `RentalManager\` (PSR-4 → `src/`) |
| DB table prefix | `{$wpdb->prefix}rm_` |
| REST namespace | `rental-manager/v1` |
| App URL | `/app/` (owner + renter SPA), `/v/{token}` (public receipt verification, later) |
| WP role for all clients | `rm_client` (no capabilities beyond `read`) |
| Option prefix | `rm_` (use options only for plugin-level settings, never business data) |

## Repository layout

```
rental-manager.php            # bootstrap only: version check, autoload, Kernel::boot()
src/
  Kernel/                     # shared infrastructure, NOT a business module
    Container/ Clock/ Money/ Id/ Time/ Db/ Migrations/ Outbox/ Jobs/
    Sequences/ Tenancy/ Errors/ Http/ Security/ Storage/ Audit/ I18n/ Health/
  Modules/
    Platform/        # accounts, plans, subscriptions, entitlements, overrides, access mode
    Identity/        # memberships, invitations, roles→permissions, support grants, authorization
    Settings/        # per-account settings (timezone, currency, receipt prefix, rounding...)
    Units/
    Renters/
    Leasing/         # contracts, amendments, renewals, terminations
    Billing/         # charges, submissions, transactions, allocations, credits, receipts, deposits
    Expenses/
    Maintenance/
    Documents/
    Notifications/
    Activity/        # timeline projection (user-facing story), NOT audit
    Reporting/       # read-only query services
  Modules/<Name>/
    Api/             # PUBLIC interface of the module (facades + DTOs). Only thing other modules may use.
    Domain/          # entities, value objects, domain services, events. No WordPress. No SQL.
    Application/     # use cases (commands/queries + handlers). Depends on Domain + interfaces.
    Infrastructure/  # wpdb repositories, adapters. Only layer allowed to touch WordPress/$wpdb.
    Presentation/    # REST controllers, request DTO mapping, response mapping. No business logic.
    module.php       # registers services, routes, migrations, listeners for this module
migrations/          # numbered migration classes: 0001_create_accounts.php ...
frontend/            # SPA source (React/TS)
build/               # compiled SPA (committed)
templates/app.php    # bare HTML shell for /app/ — does NOT load the theme
tests/
  Unit/ Application/ Integration/ Api/ Architecture/ E2E/
  Support/           # factories, fixtures, two-account world builder, isolation matrix
docs/
```

## Automated review loop

Work arrives as `@codex` comments on the phase issues (one task per request) and review findings arrive as
`@codex` comments on your pull request. The loop is driven by `.github/workflows/claude-review-loop.yml`.

- One task per pull request. Branch `codex/t<phase>.<n>-<slug>`, PR against `main`, title starts with the task id
  (`T1.3: ...`), body contains `Refs #<phase issue>`. A PR that breaks these conventions is not reviewed.
- When a comment asks you to fix review findings or a CI failure, push the fix to the **same branch**; never open a new PR for it.
- Never modify `.github/workflows/claude-review-loop.yml` or anything under `.github/claude/`.
- Never merge, close, or mark your own PR ready; the loop does that after a clean review and green CI.

## Architecture rules (enforced by deptrac + tests)

1. `Domain` imports nothing from WordPress, `$wpdb`, `WP_*`, REST, filesystem, mail, or `Infrastructure`.
2. `Application` depends on `Domain`, its own repository **interfaces**, `Kernel` interfaces, and other modules' `Api` only.
3. `Infrastructure` is the only layer that may call WordPress functions or `$wpdb`.
4. `Presentation` maps HTTP ⇄ Application commands/queries. **No business rules in controllers.**
5. A module never reads or writes another module's tables and never imports another module's
   `Domain`, `Application` or `Infrastructure`. Cross-module calls go through `Modules\<Other>\Api\*`.
   Public Api signatures expose only Api DTOs and KernelShared types (Money, Time, Id, Tenancy,
   Errors), never Domain entities. Facades map Domain objects to DTOs before returning them.
6. Synchronous cross-module calls are allowed **inside the same Unit of Work** when atomicity requires it,
   only through `Api`, and only in the directions allowed in `deptrac.yaml`. Allowed directions:
   `Leasing → Billing, Units, Renters` · `Billing → Documents` · `Expenses → Billing, Documents` ·
   `Maintenance → Expenses, Documents` · `Units → Leasing` (read-only query for derived status) ·
   `Renters → Identity` (portal invitation) · every module → `Settings`, `Identity` (authorization), `Kernel`.
   Never add a reverse direction; if you need one, write a question in `docs/QUESTIONS.md`.
   **Single exception to rule 5:** `Reporting` may run read-only SQL across module tables (always account-scoped);
   no other module may depend on `Reporting`.
7. Side effects that may lag (notifications, timeline, emails, reports) go through the **Outbox**, never inline.
8. Layering depth by module (do not over-engineer the simple ones):
   - Full DDD (aggregates, value objects, invariants): **Billing, Leasing**.
   - Application service + repository + simple entities: Units, Renters, Expenses, Maintenance, Identity, Settings, Platform.
   - Mostly infrastructure: Documents, Notifications, Activity, Reporting.

## Multi-tenancy rules (most important — a violation is a release blocker)

- Every tenant table has `account_id` and `UNIQUE (account_id, id)`; references between tenant tables use
  **composite foreign keys** `(account_id, x_id) → x(account_id, id)`.
- `AccountContext` (immutable: accountId, userId, membershipId, role, actorType) is resolved per request by
  `AccountResolver` from the authenticated user + `X-RM-Account` header, after checking an **active membership**.
  No context → exception (fail closed). Never "all accounts".
- **`account_id` is never read from request bodies or query strings.** Ignore/reject it.
- Tenant repositories receive `AccountContext` in their constructor (built by a factory). Every read is
  `WHERE account_id = %d AND ...`; every insert takes `account_id` from the context. There is no unscoped method.
- The `TenantQuery` helper refuses to build SQL on an `rm_*` tenant table without the account predicate.
- Cross-account access (platform admin, jobs) uses separate repositories under `Modules\Platform\...\Infrastructure\CrossAccount\*`.
- Renters additionally pass a **resource policy** (`RenterScope`): they only see rows tied to their own `renter_id`.
- A resource in another account (or outside a renter's scope) returns **404**, never 403.
- Every job payload carries `account_id`; the job runner builds `AccountContext` explicitly before running.
- Cache keys start with `account_id`. File paths include the account's public id.

## Authorization pipeline (every non-public endpoint, in this order)

1. **Access mode** of the account: `full` / `read_only` / `blocked` (see DOMAIN.md).
2. **Permission** of the role (static map in code, `Modules\Identity\Domain\RolePermissions`).
3. **Resource policy** (ownership / renter scope).
4. **Entitlement** (plan limits/features) — only where the action consumes a limit or a feature.

Every route is registered through `Kernel\Http\Router` with a declared permission (or `public: true`).
A test fails if any route under `rental-manager/v1` lacks a declaration.

## Money, time and IDs

- Money = `Kernel\Money\Money` (int minor units + ISO currency). **Never float.** DB: `BIGINT` + `CHAR(3)`.
- Rounding only through `Money::multiplyRatio($num, $den, RoundingMode)`; the mode comes from account settings.
- Civil dates (`due_date`, contract dates, paid_on, spent_on) = `Kernel\Time\LocalDate`, DB `DATE`.
- Instants (`created_at`, `approved_at`...) = `DateTimeImmutable` UTC, DB `DATETIME`.
- "Today" = `Clock::today($accountTimezone)`. Never `date()`, `time()`, `current_time()`, `new DateTime()` in Domain/Application.
- Internal PK `BIGINT UNSIGNED AUTO_INCREMENT`; external id `public_id CHAR(26)` ULID, unique. **APIs only expose `public_id`.**

## Database rules

- Schema changes only via numbered migrations in `migrations/` run by `Kernel\Migrations\Runner`
  (table `rm_migrations`, lock, runs on version change — never on every request). Do not use `dbDelta` for core tables.
- DDL and data changes in separate migrations (MySQL DDL auto-commits).
- Financial rows are never deleted or edited in place: corrections are new rows (reversal/void/cancel).
- All writes of a use case happen inside one `UnitOfWork::run(fn)`; nested transactions are not allowed.
- `$wpdb` errors become exceptions (`Kernel\Db\Connection`). Retry the whole unit of work once on deadlock (1213).
- **Never call WordPress APIs that fire hooks (`update_user_meta`, `wp_insert_post`, `wp_mail`...) inside a financial transaction.**
- Use `SELECT ... FOR UPDATE` on rows whose invariants you check; lock multiple rows in ascending id order.

## REST conventions

- Base: `/wp-json/rental-manager/v1`. Resources in plural kebab-case: `/units`, `/units/{id}`, `/payment-submissions/{id}/approve`.
- Auth: WP cookie + `X-WP-Nonce`. Tenant: `X-RM-Account: <account public_id>`. Application Passwords disabled for `rm_client`.
- State-changing financial endpoints require `Idempotency-Key` header (stored in `rm_idempotency_keys`, same key + same body → same response; same key + different body → 409).
- Success: `{"data": ..., "meta": {...}}`. Lists: `?page=1&per_page=25` (max 100), `meta: {page, per_page, total}`.
- Filters and sort only from per-endpoint whitelists: `?filter[status]=open&sort=-due_date`.
- Errors: `{"error": {"code": "contract_overlap", "message": "<localized>", "details": {...}, "request_id": "..."}}`
  with HTTP 400 (validation), 401, 403 (permission inside own scope), 404 (not found / other tenant), 409 (conflict/state), 422 (domain rule), 429.
- Money in JSON: `{"amount": 1200000, "currency": "EGP"}` (minor units). Dates: `YYYY-MM-DD`. Instants: ISO 8601 UTC.
- Responses carry `Cache-Control: private, no-store`.

## Security rules

- Private files never go to the Media Library or `wp-content/uploads` public paths. Use `Kernel\Storage\PrivateStorage`
  (outside webroot if configured, else a random-named protected dir) and download only through the authenticated endpoint.
- Uploads: extension allowlist + `finfo` magic bytes; images re-encoded (strips EXIF/GPS); no SVG/HTML; size and pixel limits.
- National ID numbers encrypted with libsodium (`RM_ENCRYPTION_KEY` in `wp-config.php`), masked in responses; viewing raw value or ID images writes an audit entry.
- Every important action writes `rm_audit_log` in the same transaction.
- Escape all output in the PHP shell template; the SPA never uses `dangerouslySetInnerHTML`.

## Commands

```
composer install
composer test:unit         # no WordPress needed
composer test:integration  # needs WP test suite + MySQL (see docs/PLAN.md T0.3)
composer test:arch         # deptrac
composer stan              # phpstan
composer cs                # phpcs
composer check             # all of the above that run without MySQL
npm --prefix frontend ci && npm --prefix frontend run build
npm --prefix frontend test
```

If MySQL/WordPress test suite is not available in your environment, still write integration tests,
run `composer test:unit test:arch stan cs`, and say clearly in your summary which suites you could not run.

## Definition of done (every task)

- Acceptance criteria of the task are met.
- New behavior has tests at the right level (domain rules → unit; SQL/locks/FKs → integration; endpoints → API test
  including a cross-account 404 case and a permission-denied case).
- No new route without a permission declaration. No `$wpdb` outside Infrastructure.
- Strings shown to users go through i18n (Arabic + English entries).
- Summary at the end: what changed, files, tests run and results, open questions added to `docs/QUESTIONS.md`.
