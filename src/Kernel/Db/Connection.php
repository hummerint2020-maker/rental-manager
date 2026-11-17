<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Db;

use Error;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use Throwable;
use WeakMap;
use wpdb;

final class Connection implements TransactionManager
{
    private const READ_VERBS = 'SELECT|SHOW|EXPLAIN|DESCRIBE|DESC';

    /** @var WeakMap<wpdb, mysqli>|null */
    private static ?WeakMap $transactions = null;
    /** @var WeakMap<wpdb, DbException>|null */
    private static ?WeakMap $failures = null;
    private readonly TransactionStrategy $strategy;

    public function __construct(private readonly wpdb $db, ?TransactionStrategy $strategy = null)
    {
        $this->strategy = $strategy ?? new RealTransaction();
    }

    public function execute(string $template, int|string ...$params): int
    {
        $handle = $this->handle();
        $sql = $this->prepare($template, array_values($params), 'INSERT|UPDATE|DELETE|REPLACE');
        $result = $this->query($handle, $sql);
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        return (int) $handle->affected_rows;
    }

    public function fetchValue(string $template, int|string ...$params): ?string
    {
        $result = $this->read($template, array_values($params));
        try {
            $value = $result->fetch_row()[0] ?? null;
            return $value === null ? null : (string) $value;
        } finally {
            $result->free();
        }
    }

    /** @return array<string, mixed>|null */
    public function fetchRow(string $template, int|string ...$params): ?array
    {
        $result = $this->read($template, array_values($params));
        try {
            return $result->fetch_assoc() ?: null;
        } finally {
            $result->free();
        }
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $template, int|string ...$params): array
    {
        $result = $this->read($template, array_values($params));
        try {
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
    }

    /** @inheritDoc */
    public function transaction(callable $work): mixed
    {
        $transactions = self::$transactions ??= new WeakMap();
        $failures = self::$failures ??= new WeakMap();
        if (isset($transactions[$this->db])) {
            throw new DbException('Nested database transactions are not allowed.');
        }

        $handle = $this->handle();
        $control = function (string $sql) use ($handle): void {
            $this->query($handle, $sql);
        };
        $transactions[$this->db] = $handle;
        try {
            for ($attempt = 0;; ++$attempt) {
                $this->strategy->begin($control);
                try {
                    $result = $work();
                    $this->handle();
                    $this->strategy->commit($control);
                    return $result;
                } catch (Throwable $error) {
                    try {
                        $this->strategy->rollBack($control);
                    } catch (Throwable $rollbackError) {
                        $failure = new DbException(
                            'Database rollback failed. The connection cannot be reused.',
                            (int) $rollbackError->getCode(),
                            new DbException('Transaction failed before rollback.', (int) $error->getCode())
                        );
                        $failures[$this->db] = $failure;
                        throw $failure;
                    }
                    unset($failures[$this->db]);
                    if ($attempt !== 0 || !$error instanceof DbException || !$error->isRetryable()) {
                        throw $error;
                    }
                }
            }
        } finally {
            unset($transactions[$this->db]);
        }
    }

    /** @param list<int|string> $params */
    private function prepare(string $template, array $params, string $verbs): string
    {
        // DDL and other implicit commits belong outside the transactional data API.
        if (preg_match('/^\s*(?:' . $verbs . ')\b/i', $template) !== 1) {
            throw new DbException('SQL statement is not allowed by this connection method.');
        }
        preg_match_all('/%%|%[dsi]|%/', $template, $matches);
        $bindings = count($matches[0]) - count(array_keys($matches[0], '%%', true));
        if ($bindings === 0 || $bindings !== count($params) || in_array('%', $matches[0], true)) {
            throw new DbException('SQL requires one binding for each %d, %s or %i placeholder.');
        }

        $this->clearLastError();
        try {
            $sql = $this->db->prepare($template, ...$params);
        } catch (mysqli_sql_exception $error) {
            throw $this->dbFailure((int) $error->getCode());
        } catch (Error) {
            throw $this->dbFailure(0);
        }
        if ($this->db->last_error !== '') {
            throw $this->dbFailure(0);
        }
        if (!is_string($sql) || $sql === '') {
            throw new DbException('Unable to prepare SQL.');
        }
        return $this->db->remove_placeholder_escape($sql);
    }

    private function handle(): mysqli
    {
        $failure = self::$failures[$this->db] ?? null;
        if ($failure !== null) {
            throw $failure;
        }
        $handle = $this->db->__get('dbh');
        if (!$handle instanceof mysqli) {
            throw new DbException('WordPress database connection is unavailable.');
        }
        $transaction = self::$transactions[$this->db] ?? null;
        if ($transaction !== null && $transaction !== $handle) {
            throw new DbException('WordPress database connection changed during a transaction.');
        }
        return $handle;
    }

    /** @param list<int|string> $params */
    private function read(string $template, array $params): mysqli_result
    {
        $handle = $this->handle();
        $sql = $this->prepare($template, $params, self::READ_VERBS);
        $result = $this->query($handle, $sql);
        if (!$result instanceof mysqli_result) {
            throw new DbException('Database statement did not return a result set.');
        }
        return $result;
    }

    /**
     * Use WordPress bindings without automatic reconnect and replay.
     *
     * @return true|mysqli_result
     */
    private function query(mysqli $handle, string $sql): bool|mysqli_result
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

        throw $this->dbFailure((int) $code);
    }

    private function clearLastError(): void
    {
        $this->db->last_error = '';
    }

    private function dbFailure(int $code): DbException
    {
        $failure = new DbException('Database statement failed.', $code);
        $this->db->last_error = $failure->getMessage();
        if (
            isset(self::$transactions[$this->db])
            && ($failure->isRetryable() || in_array($code, [2006, 2013, 2055, 0], true))
        ) {
            // MySQL can roll back the whole transaction. Block later statements even if the callback catches this.
            self::$failures[$this->db] = $failure;
        }
        return $failure;
    }
}
