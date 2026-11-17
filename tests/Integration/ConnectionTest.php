<?php

declare(strict_types=1);

namespace RentalManager\Tests\Integration;

use Closure;
use Error;
use ReflectionProperty;
use RentalManager\Kernel\Db\Connection;
use RentalManager\Kernel\Db\DbException;
use RentalManager\Kernel\Db\RealTransaction;
use RentalManager\Kernel\Db\SavepointTransaction;
use RentalManager\Kernel\Db\TransactionStrategy;
use RentalManager\Tests\Support\DatabaseTestCase;
use RentalManager\Tests\Support\FixtureSchema;
use wpdb;

final class ConnectionTest extends DatabaseTestCase
{
    private const TABLE = 'rm_connection_test';
    private wpdb $client;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        // A real transaction must never commit the integration harness's outer transaction.
        $this->client = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $this->createTestTable($this->client);
        $this->connection = new Connection($this->client);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->client)) {
                $this->client->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testBindsIdentifiersAndValuesAndReadsRows(): void
    {
        $value = "a %s, a 'quote' and an Arabic letter ع";
        self::assertSame(1, $this->connection->execute(
            'INSERT INTO %i (id, note) VALUES (%d, %s)',
            self::TABLE,
            1,
            $value
        ));
        self::assertSame(1, $this->connection->execute('INSERT INTO %i (id) VALUES (%d)', self::TABLE, 2));
        self::assertSame($value, $this->connection->fetchValue('SELECT note FROM %i WHERE id = %d', self::TABLE, 1));
        self::assertNull($this->connection->fetchValue('SELECT note FROM %i WHERE id = %d', self::TABLE, 2));
        self::assertNull($this->connection->fetchRow('SELECT note FROM %i WHERE id = %d', self::TABLE, 9));
        self::assertSame(
            ['note' => $value],
            $this->connection->fetchRow('SELECT note FROM %i WHERE id = %d', self::TABLE, 1)
        );
        self::assertSame(
            [['note' => $value], ['note' => null]],
            $this->connection->fetchAll('SELECT note FROM %i ORDER BY id', self::TABLE)
        );
    }

    public function testRejectsUnboundSqlAndTransactionControl(): void
    {
        foreach (['SELECT 1', 'SELECT %d', 'SELECT %d, %d'] as $template) {
            try {
                $this->connection->fetchValue($template);
                self::fail('Expected a missing bindings error.');
            } catch (DbException $error) {
                self::assertStringContainsString('binding', $error->getMessage());
            }
        }
        foreach (['ROLLBACK /* %d */', "SET autocommit = %d", '/* comment */ COMMIT /* %d */'] as $template) {
            try {
                $this->connection->execute($template, 1);
                self::fail('Expected transaction control to be rejected.');
            } catch (DbException $error) {
                self::assertStringContainsString('not allowed', $error->getMessage());
            }
        }
    }

    public function testSanitizesDatabaseErrorsAndClearsStaleError(): void
    {
        $this->insert($this->connection, 1);
        $this->client->show_errors();
        ob_start();
        try {
            $this->insert($this->connection, 1);
            self::fail('Expected duplicate key error.');
        } catch (DbException $error) {
            self::assertSame(1062, $error->getCode());
            self::assertSame('Database statement failed.', $error->getMessage());
            self::assertSame($error->getMessage(), $this->client->last_error);
            self::assertTrue($this->client->show_errors);
        } finally {
            self::assertSame('', ob_get_clean());
        }
        self::assertSame('1', $this->countRows($this->connection));
        self::assertSame('', $this->client->last_error);
    }

    public function testStopsBeforeExecutionWhenWordPressPrepareReportsLastError(): void
    {
        $client = new class (DB_USER, DB_PASSWORD, DB_NAME, DB_HOST) extends wpdb {
            public function prepare($query, ...$args)
            {
                $prepared = parent::prepare($query, ...$args);
                $this->last_error = 'raw diagnostics from WordPress prepare';
                return $prepared;
            }
        };
        try {
            $this->createTestTable($client);
            $connection = new Connection($client);
            $error = $this->databaseFailure(fn () => $this->insert($connection, 1));
            self::assertSame('Database statement failed.', $error->getMessage());
            self::assertSame($error->getMessage(), $client->last_error);
            self::assertSame('0', $client->get_var('SELECT COUNT(*) FROM rm_connection_test'));
        } finally {
            $client->close();
        }
    }

    public function testCommitsAndReturnsResultAndRollsBackAnyThrowable(): void
    {
        $failure = new Error('Abort work.');
        $caught = null;
        try {
            $this->connection->transaction(function () use ($failure): void {
                $this->insert($this->connection, 1);
                throw $failure;
            });
        } catch (Error $error) {
            $caught = $error;
        }
        self::assertSame($failure, $caught);
        self::assertSame('0', $this->countRows($this->connection));
        $result = $this->connection->transaction(function (): string {
            $this->insert($this->connection, 1);
            return 'committed';
        });
        self::assertSame('committed', $result);
        self::assertSame('1', $this->countRows($this->connection));
    }

    public function testBothWrappersRejectNestedTransactionsOnTheSameWordPressHandle(): void
    {
        $wrapper = new Connection($this->client);
        foreach ([$this->connection, $wrapper] as $nested) {
            $error = $this->databaseFailure(function () use ($nested): void {
                $this->connection->transaction(function () use ($nested): void {
                    $this->insert($this->connection, 1);
                    $nested->transaction(static function (): void {
                        self::fail('Nested work must never run.');
                    });
                });
            });
            self::assertStringContainsString('Nested database transactions', $error->getMessage());
            self::assertSame('0', $this->countRows($wrapper));
        }
    }

    public function testRealStrategyDoesNotCommitAnExistingCallerTransaction(): void
    {
        FixtureSchema::query($this->client, 'START TRANSACTION');
        $this->insert($this->connection, 1);
        $error = $this->databaseFailure(function (): void {
            $this->connection->transaction(static function (): void {
                self::fail('Nested work must never run.');
            });
        });
        self::assertStringContainsString('Nested database transactions', $error->getMessage());
        self::assertSame('1', $this->countRows($this->connection));
        FixtureSchema::query($this->client, 'ROLLBACK');
        self::assertSame('0', $this->countRows($this->connection));
    }

    public function testBothRetryableErrorsRollbackBeforeOneRetry(): void
    {
        foreach ([1213, 1205] as $code) {
            $attempts = 0;
            $error = $this->databaseFailure(function () use ($code, &$attempts): void {
                $this->connection->transaction(function () use ($code, &$attempts): void {
                    ++$attempts;
                    // Inserting the same key on each attempt proves the retry starts after rollback.
                    $this->insert($this->connection, 1);
                    throw new DbException('Simulated concurrency failure.', $code);
                });
            });
            self::assertSame($code, $error->getCode());
            self::assertSame(2, $attempts);
            self::assertSame('0', $this->countRows($this->connection));
        }
    }

    public function testRetryCanSucceedAndReturnTheSecondAttemptResult(): void
    {
        $attempts = 0;
        $result = $this->connection->transaction(function () use (&$attempts): int {
            ++$attempts;
            $this->insert($this->connection, 1);
            if ($attempts === 1) {
                throw new DbException('Simulated deadlock.', 1213);
            }
            return $attempts;
        });
        self::assertSame(2, $result);
        self::assertSame('1', $this->countRows($this->connection));
    }

    public function testExactRetryableCommitErrorRestartsTheWorkAfterRollback(): void
    {
        $strategy = new class (new RealTransaction()) implements TransactionStrategy {
            private bool $failed = false;

            public function __construct(private readonly RealTransaction $real)
            {
            }

            public function begin(Closure $control): void
            {
                $this->real->begin($control);
            }

            public function commit(Closure $control): void
            {
                if (!$this->failed) {
                    $this->failed = true;
                    throw new DbException('Simulated commit rejection.', 1205);
                }
                $this->real->commit($control);
            }

            public function rollBack(Closure $control): void
            {
                $this->real->rollBack($control);
            }
        };
        $connection = new Connection($this->client, $strategy);
        $attempts = 0;
        $connection->transaction(function () use ($connection, &$attempts): void {
            ++$attempts;
            $this->insert($connection, 1);
        });
        self::assertSame(2, $attempts);
        self::assertSame('1', $this->countRows($connection));
    }

    public function testCaughtServerDeadlockBlocksFurtherSqlAndStillRetriesTheWholeWork(): void
    {
        $strategy = new class (new RealTransaction()) implements TransactionStrategy {
            /** @var Closure(string): void */
            private Closure $control;

            public function __construct(private readonly RealTransaction $real)
            {
            }

            public function begin(Closure $control): void
            {
                $this->control = $control;
                $this->real->begin($control);
            }

            public function commit(Closure $control): void
            {
                $this->real->commit($control);
            }

            public function rollBack(Closure $control): void
            {
                $this->real->rollBack($control);
            }

            public function simulateDeadlock(): void
            {
                // Reproduce the server's full transaction rollback and numeric error without a lock race.
                ($this->control)('ROLLBACK');
                ($this->control)("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213");
            }
        };
        $connection = new Connection($this->client, $strategy);
        $attempts = 0;
        $connection->transaction(function () use ($connection, $strategy, &$attempts): void {
            ++$attempts;
            $this->insert($connection, 1);
            if ($attempts === 1) {
                $failure = $this->databaseFailure($strategy->simulateDeadlock(...));
                self::assertSame(1213, $failure->getCode());
                $blocked = $this->databaseFailure(fn () => $this->insert($connection, 2));
                self::assertSame($failure, $blocked);
            }
        });
        self::assertSame(2, $attempts);
        self::assertSame('1', $this->countRows($connection));
    }

    public function testTransactionDoesNotReconnectAfterTheHandleCloses(): void
    {
        $attempts = 0;
        $error = $this->databaseFailure(function () use (&$attempts): void {
            $this->connection->transaction(function () use (&$attempts): void {
                ++$attempts;
                $this->insert($this->connection, 1);
                self::assertTrue($this->client->close());
                $this->insert($this->connection, 2);
            });
        });
        self::assertSame(1, $attempts);
        self::assertStringContainsString('connection cannot be reused', $error->getMessage());
    }

    public function testTransactionDoesNotUseAReplacementWordPressHandle(): void
    {
        $replacement = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $property = new ReflectionProperty(wpdb::class, 'dbh');
        $original = $property->getValue($this->client);
        try {
            $error = $this->databaseFailure(function () use ($property, $replacement): void {
                $this->connection->transaction(function () use ($property, $replacement): void {
                    $this->insert($this->connection, 1);
                    $property->setValue($this->client, $property->getValue($replacement));
                    $this->connection->fetchValue('SELECT %s', 'wrong session');
                });
            });
            self::assertStringContainsString('connection changed during a transaction', $error->getMessage());
        } finally {
            $property->setValue($this->client, $original);
            $replacement->close();
        }
        self::assertSame('0', $this->countRows($this->connection));
    }

    public function testSavepointRollbackAndReleaseLeaveTheOuterTransactionInControl(): void
    {
        FixtureSchema::query($this->client, 'START TRANSACTION');
        $connection = new Connection($this->client, new SavepointTransaction());
        $this->insert($connection, 1);
        $error = $this->databaseFailure(function () use ($connection): void {
            $connection->transaction(function () use ($connection): void {
                $this->insert($connection, 2);
                throw new DbException('Abort work.');
            });
        });
        self::assertSame('Abort work.', $error->getMessage());
        self::assertSame('1', $this->countRows($connection));
        $connection->transaction(fn () => $this->insert($connection, 2));
        self::assertSame('2', $this->countRows($connection));
        FixtureSchema::query($this->client, 'ROLLBACK');
        self::assertSame('0', $this->countRows($connection));
    }

    public function testSavepointStrategyRequiresAnOuterTransaction(): void
    {
        $connection = new Connection($this->client, new SavepointTransaction());
        $this->expectException(DbException::class);
        $connection->transaction(static function (): void {
            self::fail('Work must never begin without the outer transaction.');
        });
    }

    public function testLostSavepointFailsClosedAndDoesNotRetry(): void
    {
        FixtureSchema::query($this->client, 'START TRANSACTION');
        $connection = new Connection($this->client, new SavepointTransaction());
        $attempts = 0;
        $error = $this->databaseFailure(function () use ($connection, &$attempts): void {
            $connection->transaction(function () use ($connection, &$attempts): void {
                ++$attempts;
                $this->insert($connection, 1);
                // A real deadlock victim loses its enclosing transaction and every savepoint.
                FixtureSchema::query($this->client, 'ROLLBACK');
                throw new DbException('Simulated deadlock.', 1213);
            });
        });
        self::assertStringContainsString('rollback failed', $error->getMessage());
        self::assertSame(1213, $error->getPrevious()?->getCode());
        self::assertSame(1, $attempts);
        $this->expectException(DbException::class);
        $this->expectExceptionMessage('connection cannot be reused');
        $this->insert(new Connection($this->client), 2);
    }

    private function insert(Connection $connection, int $id): void
    {
        $connection->execute('INSERT INTO %i (id) VALUES (%d)', self::TABLE, $id);
    }

    private function createTestTable(wpdb $client): void
    {
        FixtureSchema::query(
            $client,
            'CREATE TEMPORARY TABLE rm_connection_test (id INT PRIMARY KEY, note VARCHAR(80) NULL) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci'
        );
    }

    private function countRows(Connection $connection): ?string
    {
        return $connection->fetchValue('SELECT COUNT(*) FROM %i', self::TABLE);
    }

    /** @param callable(): void $work */
    private function databaseFailure(callable $work): DbException
    {
        try {
            $work();
        } catch (DbException $error) {
            return $error;
        }
        self::fail('Expected a database exception.');
    }
}
