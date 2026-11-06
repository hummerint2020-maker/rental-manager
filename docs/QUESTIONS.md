# QUESTIONS.md

Agents add open questions here. Ahmed answers inline and moves resolved items to "Resolved".

| Task | Question | Options | Recommended default | Answer |
| --- | --- | --- | --- | --- |
| T0.6 | System Health has no tenant account. How should its canary comply with account-scoped job/cache rules? | Wait for tenant context; keep the diagnostic separate from tenant jobs | Reserve rm_health_canary/rm-health and short-lived rm_health_* transients for this System-Admin-only control-plane diagnostic, carrying only a random acknowledgement token. Never use it for tenant work; tenant jobs still require account_id and AccountContext | Pending; reversible diagnostic-only default applied |
| T0.6 | T3.4 provides production PrivateStorage later. What should the hosting canary exercise now? | Introduce the full storage service early; use a disposable probe with the planned fallback protection | Probe a random uploads subdirectory with .htaccess deny/index.php, a public control file and a private marker, then remove only probe files. Require control HTTP 200 and private HTTP 403/404 without redirects or marker leakage; transport errors fail. T3.4 must replace this adapter with the actual configured storage | Pending; reversible spike default applied |
| T0.6 | How can the health spike verify FK/rollback and runner reachability without touching tenant data or claiming external cron configuration is known? | Read configuration only; disposable database tables and a real scheduler canary | Use a separate DB connection with random disposable InnoDB tables and finally cleanup. Enqueue only an rm-health canary and report pending until its callback is observed, fail after two minutes; DISABLE_WP_CRON is a separate check, external cron remains a human verification | Pending; reversible diagnostic default applied |
| T0.4 | The plan requires frontend build/test before T0.5 creates its manifest. How should CI handle the current placeholder without starting T0.5? | Gate the placeholder explicitly; implement the scaffold early; leave CI failing | Report the .gitkeep-only state explicitly, test that gate, and require npm ci/build/test automatically once package.json exists. Missing lockfiles/scripts or orphan source files fail | Pending; reversible CI default applied |
| T0.3 | WordPress 6.4's standard test bootstrap uses removed PHPUnit 9 APIs, while our fixed stack requires PHPUnit 10. Which bridge should the harness use? | Official installer, hook helpers and factories with a project PHPUnit 10 transactional base; downgrade PHPUnit; patch upstream classes | Keep PHPUnit 10, reuse the official test-library installer/helpers/factories without loading the legacy WP_UnitTestCase layer; no upstream patches or extra dependency | Pending; reversible harness default applied |
| T0.3 | Production account/membership migrations are scheduled for Phases 1–2. Where should World store its minimal two-account identity fixtures now? | Test-only rm_test_* tables; introduce production migrations early | Use test-only InnoDB tables with settings, memberships and renter links, composite account foreign keys; replace adapters as production migrations arrive | Pending; reversible fixture default applied |
| T0.2 | Units may call Leasing only for read-only queries, but no query API naming contract exists yet. How should the static graph distinguish those contracts? | Dedicated Leasing/Api/Query namespace; method-name convention; explicit attributes | Reserve Leasing/Api/Query for read-only contracts and allow Units to depend only on that API layer; verify implementations when Leasing is built | Pending; reversible naming default applied |
| T0.2 | AGENTS.md restricts WordPress to Infrastructure, but the plan places adapters such as Connection and Router directly under Kernel/Db and Kernel/Http. Which Kernel paths may use WordPress? | Require Infrastructure subfolders everywhere; recognize the planned Kernel adapter folders | Treat Db, Migrations, Outbox, Jobs, Sequences, Storage, Audit, Health, Http, Security and I18n as Kernel infrastructure; keep module access restricted to Infrastructure and pure Kernel values isolated | Pending; reversible static-check default applied |
| T0.2 | Deptrac cannot reliably detect bare $wpdb, function callbacks or SQL table strings. Add a parser dependency for the supplementary AST guard? | Reuse the already locked nikic/php-parser as a direct dev dependency; rely only on class dependencies | Declare the existing parser explicitly; no new runtime dependency or package version is introduced | Pending; reversible tooling default applied |
| T0.1 | Strauss 0.31 has Windows drive-path errors. Where should dependency prefixing run? | Linux/WSL; maintain Windows compatibility patches | Build isolated dependencies on Linux. Windows may use `composer install --no-scripts` for PHP checks; do not ship until the Linux prefixing and activation smoke checks pass | Pending; reversible default applied |

## Resolved

T0.8 verification (no new decision): IBM Plex Sans Arabic Regular and Bold from the pinned
Google Fonts distribution (see assets/fonts/README.md) render joined Arabic correctly with
useOTL=0xFF and useKashida=0. The synthetic receipt was rendered and visually inspected for
joined Arabic in normal/bold text, mixed Latin references and Arabic-Indic amounts; contextual
initial/medial/final forms are also tested with the real mPDF OTL engine. Keep Plex as specified;
Noto Naskh Arabic fallback is unnecessary. Automatic language font switching is disabled so
Arabic and Latin share Plex and cannot request stripped CJK or other fallback fonts. The package
limit uses decimal MB (strictly less than 8,000,000 bytes), checked zipped and extracted.
No new dependency or business-rule question; real-host visual confirmation remains [AHMED].

T0.7 verification (no new decision): the pinned WordPress 7.1.4 test bootstrap and abstract test
case still call parseTestMethodAnnotations, which is unavailable in our PHPUnit 10 path. Retain
the existing T0.3 bridge/default, with the upstream evidence and reproduction in TESTING.md.
The T0.3 pending decision above is not treated as owner approval by this verification.

T0.5 — Ahmed approved Playwright as the only frontend test framework. Use it against the
production build and for frontend guard tests; no separate DOM/unit-test runner. TypeScript
declarations and the official @tailwindcss/vite integration remain development dependencies.
Run the build and install Chromium before npm test.

T0.1 review follow-up: the root requirement now uses sodium instead of GD. mPDF itself still
requires GD transitively. Keep its declared platform requirement intact; evaluate that constraint
alongside the GD/Imagick hosting checks and PDF spike in T0.6 before promising Imagick-only hosting.
