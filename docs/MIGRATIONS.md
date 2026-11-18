# T1.3 migrations

The plugin reads its header version on `init`. If `rm_schema_version` matches, it does not
construct a migration session or inspect the journal. Activation always verifies the catalog
and journal. A successful run records the version only after every migration completes.
Activation and upgrade failures return a localized HTTP 503 without SQL or bound values.
CLI failures throw an exception with sanitized database causes, so test/activation scripts
exit unsuccessfully rather than silently ending through WordPress's HTML wp_die handler.
This deliberately fails closed, including ordinary WordPress requests during an unsuccessful
upgrade; restore service by fixing the deployment/schema before retrying. No business schema
or tenant migration is introduced in T1.3.

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
lock-busy response can be retried after the other run finishes; it creates no started row.
Migration failures never advance the version marker. A later session/lock-release or cache
failure can report an error after all migrations and the marker were persisted; inspect both
the journal and marker rather than assuming a 503 means no changes committed.

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
