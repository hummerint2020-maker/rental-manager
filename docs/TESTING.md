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

Use PHP 8.1+, Composer dependencies, mysqli, ZipArchive, HTTPS stream support and MySQL 8.0+
(or MariaDB 10.6+). Build the isolated dependencies on Linux/WSL before running the plugin tests.
Use a disposable database: the official WordPress installer resets the WordPress test tables.

```sh
export RM_ALLOW_TEST_DB_RESET=1
export WP_CORE_DIR=/tmp/rm-integration-core
export WP_TESTS_DIR=/tmp/rm-integration-tests
bash bin/install-wp-tests.sh rm_test_rental_manager root test-password 127.0.0.1:3306 6.4
composer test:integration
```

The installer is a Bash wrapper around bin/install-wp-tests.php; native Windows developers may
invoke that PHP file with the same arguments. The explicit version defaults to 6.4. Core is downloaded
from wordpress.org and the matching test-library includes/data from WordPress/wordpress-develop
(for example, core version 6.4 maps to the develop repository's 6.4.0 release tag).
Archives are extracted only under the selected directories. Database names must start with rm_test_
and reset opt-in is required both during provisioning and when the integration runner starts.

Database credentials live in the generated wp-tests-config.php outside the repository. The installer
refuses to overwrite existing WordPress configurations. Reuse an installed environment by rerunning
composer test:integration; use new empty directories when provisioning another version/environment.
No installed service, production account, plugin schema migration or hosting configuration is changed.

## PHPUnit 10 bridge

WordPress 6.4's standard includes/bootstrap.php loads a PHPUnit-9 compatibility layer using classes
removed in PHPUnit 10. We do not load or patch that layer. tests/bootstrap-integration.php instead:

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
nested transactions inside DatabaseTestCase. Later transaction/concurrency tests will need their own
connection/cleanup lifecycle. The smoke test inserts a WordPress user and a fixture-table row, rolls
back, clears caches, and verifies neither remains. Another test verifies cross-account FK rejection.
The API suite dispatches a real WordPress REST request as an account's fixture user.

The CI workflow validates this harness on PHP 8.1/8.3 with MySQL 8.0/MariaDB 10.6.
See [CI.md](CI.md) for the matrix, retained release smoke checks and pre-T0.5 frontend gate.
