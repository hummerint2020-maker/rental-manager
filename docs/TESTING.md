# T0.3 test harness

## Database-free checks

```sh
composer install
composer check
```

The default phpunit.xml.dist runs Unit, Application and Architecture. No WordPress installation
or database is loaded. Integration and Api use a separate configuration so missing database
dependencies fail explicitly instead of silently skipping tests or breaking the pure suites.

## WordPress integration and API tests

Use PHP 8.1+, Composer dependencies, mysqli, ZipArchive, HTTPS stream support and MySQL 5.7.44+
(or MariaDB 10.6+). Build the isolated dependencies on Linux/WSL before running the plugin tests.
Use a disposable database: the official WordPress installer resets the WordPress test tables.

```sh
export RM_ALLOW_TEST_DB_RESET=1
export WP_CORE_DIR=/tmp/rm-integration-core
export WP_TESTS_DIR=/tmp/rm-integration-tests
bash bin/install-wp-tests.sh rm_test_rental_manager root test-password 127.0.0.1:3306 7.1.4
composer test:integration
```

The installer is a Bash wrapper around bin/install-wp-tests.php; native Windows developers may
invoke that PHP file with the same arguments. The explicit version defaults to 7.1.4. Core is downloaded
from wordpress.org and the matching test-library includes/data from WordPress/wordpress-develop
(7.1.4 uses exactly the develop repository's 7.1.4 release tag; a two-part version maps to its .0 tag).
Archives are extracted only under the selected directories. Database names must start with rm_test_
and reset opt-in is required both during provisioning and when the integration runner starts.

Database credentials live in the generated wp-tests-config.php outside the repository. The installer
refuses to overwrite existing WordPress configurations. Reuse an installed environment by rerunning
composer test:integration; use new empty directories when provisioning another version/environment.
No installed service, production account, plugin schema migration or hosting configuration is changed.

## PHPUnit 10 bridge

Rechecked the official **7.1.4** test bootstrap, `phpunit6/compat.php` and `abstract-testcase.php`.
The bootstrap still requires Yoast Polyfills 1.x and loads the legacy compatibility layer.
That layer and the abstract test case still call `PHPUnit\\Util\\Test::parseTestMethodAnnotations()`.
Running its `PHPUnit_Util_Test::getTickets()` with our locked PHPUnit 10.5.66 reproduced
`Call to undefined method PHPUnit\\Util\\Test::parseTestMethodAnnotations()`.
The official path therefore cannot replace our bridge yet. No PHPUnit downgrade, dependency,
upstream patch or weakened warning setting was introduced. tests/bootstrap-integration.php:

1. Loads the generated test config and verifies the disposable database opt-in.
2. Runs the official includes/install.php in a child PHP process.
3. Loads the official test hook helpers, registers the plugin before plugins_loaded, boots real WordPress,
   and loads the official factory.php classes.
4. Creates the fixture schema before any test transaction starts.

Tests extend the project's PHPUnit 10 DatabaseTestCase, not WP_UnitTestCase. It starts a transaction,
rolls back after each test, flushes object caches, resets the current user, and restores hook state.
WordPress test-factory declarations in wordpress-tests.stub are for PHPStan only, never runtime fakes.
This bridge is a documented reversible decision in QUESTIONS.md and adds no dependency or upstream patch.

## Two-account World

`new World($db)` creates A and B with separate account/public ids, Cairo/EGP/Arabic settings,
one renter each, and an owner, manager and renter user for each account. All six users have only
the test rm_client WordPress role; account roles live in active membership fixtures. Each renter
membership and portal-user link points to that account's renter.

The schema is deliberately test-only: wptests_rm_test_accounts/settings/renters/memberships/probe.
It uses InnoDB, utf8mb4, composite tenant identities and a composite membership-to-renter foreign key.
No production account migration, role authorization, API endpoint or financial behavior is implemented.
Future modules extend the builder using their production migrations; fixture ids are deterministic
Crockford-compatible values, not the Phase 1 production ULID generator.

DDL runs before transactions because MySQL DDL commits implicitly. Tests must not issue DDL or start
nested transactions inside DatabaseTestCase. The smoke test inserts a WordPress user and a fixture-table row, rolls
back, clears caches, and verifies neither remains. Another test verifies cross-account FK rejection.
The API suite dispatches a real WordPress REST request as an account's fixture user.

The CI workflow validates this harness on WordPress 7.1.4 with PHP 8.1/8.3/8.5 and
MySQL 8.0/MariaDB 10.6, plus PHP 8.3 × MySQL 5.7.44. `WP_VERSION` is defined once in `.github/workflows/ci.yml`.
See [CI.md](CI.md) for the matrix and retained release smoke checks. The T0.5 frontend gate
enables build and seven Playwright tests of the production bundle/direction guard. Run the build, install
Chromium and then `npm --prefix frontend test`; see [FRONTEND.md](FRONTEND.md) for commands.

T0.6 adds unit coverage for host thresholds, MariaDB version parsing and private-file canary
failure/cleanup cases. Integration tests exercise disposable DB probes on an independent connection,
admin capability/nonce checks and Action Scheduler canary execution/timeout. Each backend CI cell
also generates the synthetic Arabic PDF using prefixed mPDF. Real-hosting HTTP protection and
visual Arabic shaping remain [AHMED] checks in [HOSTING.md](HOSTING.md).

The System Health PDF download is covered by integration tests for admin capability, POST-only
access, its own nonce, prefixed mPDF output/headers, workspace cleanup and safe errors. Informational
server/version rows are tested for escaped rendering and their respective info/check status.

In T0.7 the WordPress row becomes pass/fail at 7.0; the web-server row remains informational.
PDF download tests emit E_USER_DEPRECATED during real prefixed mPDF construction and exercise
the E_DEPRECATED handler branch: the download stays valid with no diagnostic bytes in its body.
A separate E_USER_WARNING test still fails safely. These two tests enable E_ALL only for their
rendering scope and restore the prior level, since PHPUnit masks native warning output itself.
Deprecation consumption is scoped to rendering;
the previous error handler is restored, and both PHPUnit configurations retain failOnWarning=true.

Upstream evidence: [7.1.4 bootstrap](https://github.com/WordPress/wordpress-develop/blob/7.1.4/tests/phpunit/includes/bootstrap.php),
[compatibility layer](https://github.com/WordPress/wordpress-develop/blob/7.1.4/tests/phpunit/includes/phpunit6/compat.php),
[abstract test case](https://github.com/WordPress/wordpress-develop/blob/7.1.4/tests/phpunit/includes/abstract-testcase.php).

## T0.8 slim package and Arabic shaping

`PdfFontsTest` exercises the real mPDF OpenType engine for Regular and Bold: repeated Arabic
beh must become three distinct contextual glyphs (initial, medial, final). This catches missing
GSUB shaping even when a PDF can still be generated. The shared PdfFonts config supplies only
Plex, disables CJK/automatic fallback and keeps kashida off. The synthetic receipt also has a
visual check for joined letters, mixed-direction references and Arabic-Indic digits.

`PackageCheckTest` checks independent ZIP/extracted 8 MB limits, rejected DejaVu/CJK/core-font
data, and missing required font/Scheduler files. On Linux, run:

```sh
composer install
bash bin/package.sh /tmp/rm-release-new
php bin/spike-pdf.php --package /tmp/rm-release-new/rental-manager
```

Use a new output directory; packaging deliberately refuses to overwrite existing output.
The script includes only the runtime, local fonts/licenses, translations and committed SPA
build, strips unused mPDF resources, writes BUILD.txt and verifies its ZIP and staged directory.
CI runs this in all seven backend cells before the clean-install smoke, which renders through
the real authorized System Health download. Native Windows can run the PHP checks and native
mPDF spike, but Linux CI remains required for Strauss and assembled-release checks.

## Committed database tests

Extend `CommittedDatabaseTestCase` for migration tests and other tests that need DDL visible to
another connection. It uses the same disposable-database guard as the integration bootstrap
and requires an idle WordPress connection in autocommit mode. Keep `World` on `DatabaseTestCase`, where
rollback removes the shared fixture rows and users it creates.

- Reserve each test table with `ownedTable($suffix)` before passing its name to DDL or running a
  migration that creates it. Reserve referenced tables before referring tables so cleanup drops
  them in reverse order. `migrationPrefix()` returns the test's unique prefix for runner wiring.
- When inserting into a shared `FixtureSchema` table, call `trackFixtureRow($fixture, $id)`
  immediately after each successful insert.
- Use `createOwnedUser()` to register WordPress fixture users as they are created. Call
  `trackOption($name)` before changing a plugin option so teardown restores its original state.
- For setup that needs these helpers, override `prepareCommittedFixtures()`. Registered cleanup
  runs even if that method fails partway through.
