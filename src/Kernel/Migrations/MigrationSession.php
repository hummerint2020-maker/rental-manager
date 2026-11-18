<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

use Closure;
use Error;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use RentalManager\Kernel\Db\Connection;
use RentalManager\Kernel\Db\DbException;
use Throwable;
use wpdb;

/** A migration owns one native session until its advisory lock is released. */
final class MigrationSession
{
    private readonly Connection $connection;
    private ?mysqli $handle = null;
    private ?string $thread = null;
    private ?string $lock = null;

    public function __construct(private readonly wpdb $db, private readonly string $tablePrefix)
    {
        if (preg_match('/^[A-Za-z0-9_]*rm_(?:[A-Za-z0-9_]*_)?$/D', $tablePrefix) !== 1) {
            throw new MigrationException('Invalid migration table prefix.');
        }
        $this->connection = new Connection($db);
    }

    /** @param Closure(): void $work */
    public function withLock(Closure $work): void
    {
        if ($this->handle !== null) {
            throw new MigrationException('This migration session already owns a lock.');
        }
        $this->assertIdle();
        $handle = $this->nativeHandle();
        $thread = $this->threadId($handle);
        $database = $this->scalar($handle, 'SELECT DATABASE()');
        if ($database === null || $database === '') {
            throw new MigrationException('No database selected for migrations.');
        }
        $site = strtr(
            $database . "\0" . $this->tablePrefix,
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'abcdefghijklmnopqrstuvwxyz'
        );
        $lock = 'rm_' . substr(hash('sha256', $site), 0, 61);
        $release = $this->prepare('SELECT RELEASE_LOCK(%s)', $lock);
        if ($this->scalar($handle, $this->prepare('SELECT IS_USED_LOCK(%s)', $lock)) === $thread) {
            throw new MigrationException('This database session already owns the migration lock.');
        }
        $acquired = $this->scalar($handle, $this->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        if ($acquired !== '1') {
            throw new MigrationException(
                $acquired === '0' ? 'Another migration run holds the lock.' : 'Could not acquire the migration lock.'
            );
        }

        $this->handle = $handle;
        $this->thread = $thread;
        $this->lock = $lock;
        $failed = false;
        try {
            $this->assertLock();
            $work();
        } catch (Throwable $error) {
            $failed = true;
            throw $error;
        } finally {
            $this->handle = null;
            $this->thread = null;
            $this->lock = null;
            try {
                $this->release($handle, $thread, $release);
            } catch (MigrationException $error) {
                if (!$failed) {
                    throw $error;
                }
            }
        }
    }

    public function assertLock(): void
    {
        $handle = $this->handle;
        if (
            $handle === null
            || $this->lock === null
            || $this->nativeHandle() !== $handle
            || $this->threadId($handle) !== $this->thread
            || $this->connection->fetchValue('SELECT IS_USED_LOCK(%s)', $this->lock) !== $this->thread
        ) {
            throw new MigrationException('The migration lock is no longer owned by this database session.');
        }
    }

    public function fetchValue(string $template, int|string ...$params): ?string
    {
        $this->assertLock();
        return $this->connection->fetchValue($template, ...$params);
    }

    public function execute(string $template, int|string ...$params): int
    {
        $this->assertLock();
        $this->assertIdle();
        $this->assertLock();
        return $this->connection->execute($template, ...$params);
    }

    public function schema(): Schema
    {
        return new Schema(function (string $suffix, string $definition): void {
            if ($suffix === 'migrations') {
                throw new MigrationException('The migration journal table is reserved.');
            }
            $this->createTable($suffix, $definition);
        });
    }

    /** @return list<string> */
    public function appliedMigrations(): array
    {
        $table = $this->table('migrations');
        $engine = $this->fetchValue(
            'SELECT CONCAT(ENGINE, %s, TABLE_COLLATION) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND TABLE_TYPE = %s',
            ':',
            $table,
            'BASE TABLE'
        );
        if ($engine === null) {
            $this->createTable('migrations', 'id VARCHAR(190) NOT NULL PRIMARY KEY, '
                . 'started_at DATETIME NOT NULL, applied_at DATETIME NULL');
        } elseif ($engine !== 'InnoDB:utf8mb4_unicode_520_ci') {
            throw new MigrationException('The migration journal has unexpected table settings.');
        }

        $this->assertLock();
        $rows = $this->connection->fetchAll('SELECT id, applied_at FROM %i ORDER BY id', $table);
        $applied = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if (!is_string($id)) {
                throw new MigrationException('The migration journal contains an invalid entry.');
            }
            MigrationCatalog::validateIds([$id]);
            if ($row['applied_at'] === null) {
                throw new MigrationException('Migration ' . $id . ' is incomplete. Schema reconciliation is required.');
            }
            $applied[] = $id;
        }
        return $applied;
    }

    public function markStarted(string $id): void
    {
        $affected = $this->execute(
            'INSERT INTO %i (id, started_at) VALUES (%s, UTC_TIMESTAMP())',
            $this->table('migrations'),
            $id
        );
        if ($affected !== 1) {
            throw new MigrationException('Could not record the start of migration ' . $id . '.');
        }
    }

    public function markApplied(string $id): void
    {
        $affected = $this->execute(
            'UPDATE %i SET applied_at = UTC_TIMESTAMP() WHERE id = %s AND applied_at IS NULL',
            $this->table('migrations'),
            $id
        );
        if ($affected !== 1) {
            throw new MigrationException('Could not record completion of migration ' . $id . '.');
        }
    }

    private function createTable(string $suffix, string $definition): void
    {
        $table = $this->table($suffix);
        if (trim($definition) === '') {
            throw new MigrationException('A migration table definition is required.');
        }
        $this->assertLock();
        $this->assertIdle();
        $this->assertLock();
        $handle = $this->nativeHandle();
        $sql = $this->prepare('CREATE TABLE %i', $table) . ' (' . $definition
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
        $this->assertLock();
        if ($this->nativeQuery($handle, $sql) !== true) {
            throw new MigrationException('Could not create the migration table.');
        }
    }

    private function table(string $suffix): string
    {
        $name = $this->tablePrefix . $suffix;
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $suffix) !== 1 || strlen($name) > 64) {
            throw new MigrationException('Invalid migration table name.');
        }
        return $name;
    }

    private function assertIdle(): void
    {
        try {
            // Reuse the transaction guard, including its protection for raw transactions and poisoned sessions.
            $this->connection->transaction(static function (): void {
            });
            if ($this->connection->fetchValue('SELECT @@session.autocommit FROM DUAL WHERE %d = 1', 1) !== '1') {
                throw new MigrationException('Migration journal requires autocommit.');
            }
        } catch (DbException $error) {
            throw new MigrationException(
                'Migration requires an idle database session.',
                (int) $error->getCode(),
                $error
            );
        }
    }

    private function nativeHandle(): mysqli
    {
        $handle = $this->db->__get('dbh');
        if (!$handle instanceof mysqli) {
            throw new MigrationException('Database session is unavailable for migrations.');
        }
        return $handle;
    }

    private function threadId(mysqli $handle): string
    {
        try {
            return (string) mysqli_thread_id($handle);
        } catch (Error) {
            throw new MigrationException('Database session is unavailable for migrations.');
        }
    }

    private function prepare(string $template, string $binding): string
    {
        $this->clearLastError();
        try {
            $sql = $this->db->prepare($template, $binding);
        } catch (mysqli_sql_exception $error) {
            throw new MigrationException('Could not prepare migration statement.', (int) $error->getCode());
        } catch (Error) {
            throw new MigrationException('Could not prepare migration statement.');
        }
        if ($this->db->last_error !== '' || !is_string($sql) || $sql === '') {
            $this->db->last_error = 'Could not prepare migration statement.';
            throw new MigrationException($this->db->last_error);
        }
        return $this->db->remove_placeholder_escape($sql);
    }

    private function release(mysqli $handle, string $thread, string $sql): void
    {
        if ($this->threadId($handle) !== $thread) {
            throw new MigrationException('The migration session changed before the lock could be released.');
        }
        if ($this->scalar($handle, $sql) !== '1') {
            throw new MigrationException('Could not release the migration lock.');
        }
    }

    private function scalar(mysqli $handle, string $sql): ?string
    {
        $result = $this->nativeQuery($handle, $sql);
        if (!$result instanceof mysqli_result) {
            throw new MigrationException('Migration database statement did not return a result.');
        }
        try {
            $value = $result->fetch_row()[0] ?? null;
            return $value === null ? null : (string) $value;
        } finally {
            $result->free();
        }
    }

    /** @return true|mysqli_result */
    private function nativeQuery(mysqli $handle, string $sql): bool|mysqli_result
    {
        $this->clearLastError();
        try {
            $result = @$handle->query($sql);
            if ($result !== false) {
                return $result;
            }
            $code = $handle->errno;
        } catch (mysqli_sql_exception $error) {
            $code = $error->getCode();
        } catch (Error) {
            $code = 0;
        }
        $this->db->last_error = 'Migration database statement failed.';
        throw new MigrationException($this->db->last_error, (int) $code);
    }

    private function clearLastError(): void
    {
        $this->db->last_error = '';
    }
}
