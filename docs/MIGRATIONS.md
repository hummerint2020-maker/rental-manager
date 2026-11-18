# T1.3 migrations

The plugin reads its header version on `init`. If `rm_schema_version` matches, it does not
construct a migration session or inspect the journal. Activation always verifies the catalog
and journal. A successful run records the version only after every migration completes.
Activation failures deactivate the plugin and give a localized explanation without SQL or bound values.
CLI failures throw an exception with sanitized database causes, so test/activation scripts
exit unsuccessfully rather than silently ending through WordPress's HTML wp_die handler.
Normal `init` requests never terminate WordPress because of a migration problem. A busy lock
means an upgrade is in progress: return quietly without recording failure. Real failures
store `rm_migration_failure` with autoload disabled: plugin version, validated migration id
(null when unknown), UTC timestamp and numeric error code only. No SQL, server text or bound
values are stored. Requests do not automatically retry a recorded failure for the current
version; a new plugin version may try again. No business schema is introduced here.

`SchemaGate::isReady()` requires the stored schema version to match the plugin header and
no failure option to be recorded. It affects only Rental Manager surfaces: the T1.9 REST
router and T2.6 `/app` shell must return HTTP 503 with `Retry-After: 60` while it is false.
Those tasks carry this requirement in PLAN.md; neither surface is implemented early here.
WordPress front end, wp-admin, wp-login and System Health remain available during recovery.

## Catalog and schema

Place ordered, immutable files in the root `migrations/` directory, such as
`0001_create_accounts.php`. Each returns a `Migration` instance with only
`up(Schema $schema): void`; production has no down method. The catalog rejects malformed
names, duplicate four-digit numbers, symbolic links and invalid return values before the
runner writes anything. Module metadata lists stay empty until their business tasks;
numbered files are the single global ordering authority.

```php
<?php
return new class implements \RentalManager\Kernel\Migrations\Migration {
    public function up(\RentalManager\Kernel\Migrations\Schema $schema): void {
        // Example only: introduce actual schemas in their assigned tasks.
        $schema->createTable('example', 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY');
    }
};
```

`createTable` validates the suffix, binds the complete identifier, and always appends
`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci`. Column/index
definitions are trusted source-controlled SQL, never request data. Tenant tables still need
account identities and composite foreign keys from AGENTS.md. The helper cannot modify its
reserved journal. Keep DDL and data changes in separate migrations because MySQL DDL commits
implicitly. The initial helper supports table creation; additional schema/data operations
must preserve the same session, lock and separation when introduced. Never widen the
financial Connection data API to accept arbitrary DDL.

## Lock and history

`Runner` holds a zero-timeout `GET_LOCK` across journal creation, migration execution and the
completion callback. Its bounded name hashes the database and plugin table prefix, excluding
the version so different deployed versions contend for the same lock. A second live session
fails immediately without changing the schema. Same-session reentry is rejected too.

The migration session uses WordPress preparation and its existing native mysqli handle,
without wpdb automatic reconnect/replay. Handle identity, server thread and lock ownership
are checked before mutation. Idle/autocommit checks reject caller-owned or managed
transactions instead of implicitly committing them. The lock is released in finally on both
success and failure. The prepared autocommit probe uses FROM DUAL for MySQL 5.7 syntax compatibility.
A lost session fails rather than continuing on an unlocked connection.

`{$wpdb->prefix}rm_migrations` stores the canonical filename id, UTC `started_at` and nullable
`applied_at`. The started row is committed before `up`; completion is recorded only afterward.
Applied ids must be an exact prefix of the current catalog: renaming/removing an applied
migration or deploying an older catalog fails. An uncached, under-lock version read prevents
a stale worker from downgrading the installed version. WordPress option caches are invalidated
after the prepared marker write. No hook-firing WordPress mutation API runs inside a financial
transaction.

## Failure recovery

An unfinished journal row blocks automatic replay and later migrations, including after a
process crash. Earlier applied migrations remain recorded. DDL can have succeeded partially
even when `up` or its journal update failed; there is no promise of automatic DDL rollback.

An operator must inspect the named migration and actual database schema against the deployed
file before deciding how to reconcile it. Back up first. Complete the intended schema or
restore the backup, then reconcile the one journal entry to reflect what actually happened.
Never delete history blindly, automatically retry partial DDL, or mark a migration applied
without verifying all its effects. Corrected/new migrations retain global ordering. A normal
lock-busy attempt can try again on a later request; it creates no failure or started row.
Migration failures never advance the version marker. A later session/lock-release or cache
failure can report an error after all migrations and the marker were persisted; inspect both
the journal and marker rather than assuming an error means no changes committed.

Administrators with `manage_options` receive a notice linking to System Health. Its passive
Database schema row shows installed versus plugin version and the failed migration id, and
its recovery section lists incomplete journal entries. Only a `manage_options` user with a
valid `rm_retry_migrations` nonce may POST Retry migrations. This explicit action clears the
failure after acquiring the lock and runs once; contention does not erase the previous failure.
An incomplete journal entry still blocks and is recorded again. The action never deletes or
marks history applied automatically: reconcile the actual schema first using the instructions
above. After fixing a pre-DDL catalog failure, retry can complete without editing journal rows.

## Verification and delivery

Unit tests validate catalog ordering and invalid inputs. Integration tests use
`CommittedDatabaseTestCase` for actual DDL, independent-session lock contention, rerun no-op,
partial failure, journal integrity, session/transaction guards and lifecycle version checks.
These tests own and clean exact disposable resources; they do not use the rollback harness's
World. See [TESTING.md](TESTING.md).

Both release workflows use `bin/package.sh`, which includes `migrations/`; package validation
rejects a missing directory. The clean WordPress activation smoke verifies that the packaged
runner creates its journal and installed-version marker. All seven CI database/PHP cells run
the integration and release checks. Actual hosting rollout remains an operator action.

Session lock behavior and implicit-commit semantics are documented in the
[MySQL 5.7 locking reference](https://docs.oracle.com/cd/E17952_01/mysql-5.7-en/locking-functions.html)
and [implicit-commit reference](https://docs.oracle.com/cd/E17952_01/mysql-5.7-en/implicit-commit.html).
