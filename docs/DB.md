# T1.2 database connection and unit of work

Connection is infrastructure around WordPress's existing mysqli connection. Application code
receives the UnitOfWork interface and repository interfaces; it must not use wpdb directly.
No production business tables, migrations, audit writer or outbox dispatcher are introduced here.

## Prepared data API

Connection accepts SQL templates with explicit `%i` identifiers, `%d` integers and `%s` strings:

```php
$connection->execute('UPDATE %i SET note = %s WHERE account_id = %d AND id = %d', $table, $note, $accountId, $id);
$row = $connection->fetchRow('SELECT note FROM %i WHERE account_id = %d AND id = %d', $table, $accountId, $id);
```

execute permits INSERT/UPDATE/DELETE/REPLACE; fetchValue/fetchRow/fetchAll permit read statements.
At least one binding is required and placeholder counts must match. SQL structure is trusted
repository code; request values must be bindings, never concatenated SQL. Values are int/string;
use an explicit SQL NULL where needed, since a null `%s` argument would not mean SQL NULL.
Literal percent signs in templates use `%%`. Raw transaction commands and implicit-commit DDL
are excluded from this data API. The later migration runner needs its own explicit DDL path.
T1.5 will add account-context/TenantQuery guards; these low-level APIs do not establish tenancy.

wpdb::prepare and remove_placeholder_escape provide WordPress's escaping/identifier behavior.
Execution uses its existing mysqli handle directly, with no reconnect/replay: wpdb::query can
reconnect and repeat a query after losing a transaction, which could otherwise autocommit a
financial write. The wrapper mirrors a sanitized last_error, captures numeric MySQL errno,
and throws DbException without including bound SQL, credentials or server error text. Raw WP
diagnostic output is not enabled or globally reconfigured. Connection must be used consistently
inside a transaction; do not mix it with direct wpdb writes or WordPress APIs that fire hooks.

## Transaction boundaries

Default RealTransaction uses START TRANSACTION/COMMIT/ROLLBACK. Before starting, it probes for
a caller-owned transaction using a temporary savepoint, rejecting it without committing it.
Connection also refuses reentrant transactions or transactions through another wrapper over the
same wpdb object. Successful work returns its value. Any thrown work/commit error causes rollback.
Only explicit DbException codes **1213 or 1205** trigger one retry of the entire callback after
a successful rollback; never retry just the failed SQL statement. Other errors, connection loss
and failed rollback are not retried. Rollback failure poisons that wpdb wrapper for further use.

SavepointTransaction is an explicit strategy for the test harness's existing outer transaction.
It verifies the savepoint exists, releases it on success, and rolls back to/releases it on error.
It never commits the outer transaction. The owner must keep control of that transaction.
A real InnoDB deadlock can destroy the entire outer transaction and its savepoints; in that case
rollback-to fails and the wrapper fails closed instead of replaying in autocommit. Simulated
retry tests distinguish this failure from a still-valid outer transaction.

Callbacks must be repeatable and must not send mail, dispatch events, write public files, or
invoke hook-firing WordPress mutation APIs during a financial transaction. Outbox/audit inserts
belong in the same transaction; dispatch happens only later through the outbox.

## Buffered writes

TransactionalUnitOfWork implements UnitOfWork. run executes a callback through TransactionManager,
then invokes a **mandatory** injected flusher with its event and audit batches before commit.
recordEvent(object) and recordAudit(array) only accept records during the work callback, not
outside run or while flushing. Work/flush failures propagate and roll back both the business
writes and partial flush writes. Every attempt has fresh batches, including a retry; buffers and
recording state are cleared on every exit. Nested units of work are rejected.

The flusher must use the same Connection/transaction and persist both batches without dispatching
side effects. There is no default no-op sink. T1.6/T1.7 will introduce the durable audit/outbox
tables and writers; T1.2 tests persist representative records in existing test fixtures. Payload
business contracts and production service wiring wait for those tasks. The reversible default
and the direct-handle transport rationale are recorded in QUESTIONS.md.

## Verification

Unit tests use a deterministic transaction-manager fake to check flush ordering, failures,
nesting, inactive recording and retry-buffer isolation. SQL integration tests check values and
identifiers, rollback, return values, nested wrappers/raw outer transactions, bounded simulated
1213/1205 retries, failed savepoints, sanitized errors and actual UoW writes in a savepoint.
Temporary test tables use InnoDB/utf8mb4_unicode_520_ci; no production migrations are executed.
All seven CI cells, including PHP 8.3/MySQL 5.7.44, run these suites plus release/activation smoke.

References: [wpdb prepare](https://developer.wordpress.org/reference/classes/wpdb/prepare/),
[wpdb query/reconnect implementation](https://developer.wordpress.org/reference/classes/wpdb/query/),
[MySQL InnoDB error handling](https://dev.mysql.com/doc/refman/8.4/en/innodb-error-handling.html),
[savepoints](https://dev.mysql.com/doc/refman/8.4/en/savepoint.html).
