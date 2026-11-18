<?php

declare(strict_types=1);

namespace RentalManager\Tests\Integration;

use Closure;
use ReflectionProperty;
use RentalManager\Kernel\Db\Connection;
use RentalManager\Kernel\Migrations\Migration;
use RentalManager\Kernel\Migrations\MigrationException;
use RentalManager\Kernel\Migrations\MigrationSession;
use RentalManager\Kernel\Migrations\Runner;
use RentalManager\Kernel\Migrations\Schema;
use RentalManager\Tests\Support\CommittedDatabaseTestCase;
use RentalManager\Tests\Support\FixtureSchema;
use wpdb;

final class MigrationsTest extends CommittedDatabaseTestCase
{
    public function testCreatesInnoDbTablesWithThePortableCollationAndAppliesInOrderOnlyOnce(): void
    {
        $journal = $this->ownedTable('migrations');
        $base = $this->ownedTable('base');
        $child = $this->ownedTable('child');
        $calls = [];
        $runner = new Runner(new MigrationSession($this->db, $this->migrationPrefix()), [
            '0002_create_child' => $this->migration(static function (Schema $schema) use (&$calls, $base): void {
                $calls[] = 2;
                $schema->createTable('child', 'id INT PRIMARY KEY, base_id INT NOT NULL, '
                    . 'FOREIGN KEY (base_id) REFERENCES `' . $base . '` (id)');
            }),
            '0001_create_base' => $this->migration(static function (Schema $schema) use (&$calls): void {
                $calls[] = 1;
                $schema->createTable('base', 'id INT PRIMARY KEY');
            }),
        ]);
        $runner->run();
        $connection = new Connection($this->db);
        $history = $connection->fetchAll('SELECT id, started_at, applied_at FROM %i ORDER BY id', $journal);
        $runner->run();

        self::assertSame([1, 2], $calls);
        self::assertSame(['0001_create_base', '0002_create_child'], array_column($history, 'id'));
        self::assertNotNull($history[0]['applied_at']);
        self::assertNotNull($history[1]['applied_at']);
        self::assertSame($history, $connection->fetchAll(
            'SELECT id, started_at, applied_at FROM %i ORDER BY id',
            $journal
        ));
        foreach ([$journal, $base, $child] as $table) {
            self::assertSame([
                'ENGINE' => 'InnoDB',
                'TABLE_COLLATION' => 'utf8mb4_unicode_520_ci',
            ], $connection->fetchRow(
                'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES '
                    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
        }
    }

    public function testDifferentDatabaseSessionCannotRunWhileLockIsHeld(): void
    {
        $journal = $this->ownedTable('migrations');
        $client = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $first = new Runner(new MigrationSession($this->db, $this->migrationPrefix()), []);
        $second = new Runner(new MigrationSession($client, $this->migrationPrefix()), []);
        try {
            $first->run(before: function () use ($second, $journal): bool {
                $error = $this->migrationFailure($second->run(...));
                self::assertStringContainsString('Another migration run holds the lock', $error->getMessage());
                $this->assertTableMissing($journal);
                return true;
            });
            $second->run();
        } finally {
            $client->close();
        }
        self::assertSame('0', (new Connection($this->db))->fetchValue('SELECT COUNT(*) FROM %i', $journal));
    }

    public function testSameDatabaseSessionCannotAcquireTheSameLockAgain(): void
    {
        $journal = $this->ownedTable('migrations');
        $first = new Runner(new MigrationSession($this->db, $this->migrationPrefix()), []);
        $second = new Runner(new MigrationSession($this->db, $this->migrationPrefix()), []);
        $first->run(before: function () use ($second, $journal): bool {
            $error = $this->migrationFailure($second->run(...));
            self::assertStringContainsString('already owns the migration lock', $error->getMessage());
            $this->assertTableMissing($journal);
            return false;
        });
        $second->run();
    }

    public function testFailedDdlLeavesUnresolvedJournalBlocksReplayAndReleasesTheLock(): void
    {
        $journal = $this->ownedTable('migrations');
        $partial = $this->ownedTable('partial');
        $this->ownedTable('invalid');
        $calls = [];
        $migrations = [
            '0001_create_partial' => $this->migration(static function (Schema $schema) use (&$calls): void {
                $calls[] = 1;
                $schema->createTable('partial', 'id INT PRIMARY KEY');
                $schema->createTable('invalid', 'id INVALID_TYPE');
            }),
            '0002_must_wait' => $this->migration(static function () use (&$calls): void {
                $calls[] = 2;
            }),
        ];
        $runner = new Runner(new MigrationSession($this->db, $this->migrationPrefix()), $migrations);
        $error = $this->migrationFailure($runner->run(...));
        self::assertStringContainsString('0001_create_partial stopped before completion', $error->getMessage());
        self::assertGreaterThan(0, $error->getCode());

        $connection = new Connection($this->db);
        self::assertSame('0', $connection->fetchValue('SELECT COUNT(*) FROM %i', $partial));
        self::assertSame([['id' => '0001_create_partial', 'applied_at' => null]], $connection->fetchAll(
            'SELECT id, applied_at FROM %i',
            $journal
        ));
        $client = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        try {
            $other = new Runner(new MigrationSession($client, $this->migrationPrefix()), $migrations);
            $error = $this->migrationFailure($other->run(...));
            self::assertStringContainsString('0001_create_partial is incomplete', $error->getMessage());
        } finally {
            $client->close();
        }
        self::assertSame([1], $calls);
    }

    public function testRejectsRawAndManagedTransactionsWithoutCommittingCallerWrites(): void
    {
        $journal = $this->ownedTable('migrations');
        $sentinel = $this->ownedTable('sentinel');
        FixtureSchema::query($this->db, 'CREATE TABLE `' . $sentinel . '` (id INT PRIMARY KEY) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
        $connection = new Connection($this->db);
        $runner = new Runner(new MigrationSession($this->db, $this->migrationPrefix()), []);

        FixtureSchema::query($this->db, 'START TRANSACTION');
        try {
            $connection->execute('INSERT INTO %i (id) VALUES (%d)', $sentinel, 1);
            $error = $this->migrationFailure($runner->run(...));
            self::assertStringContainsString('idle database session', $error->getMessage());
        } finally {
            FixtureSchema::query($this->db, 'ROLLBACK');
        }
        self::assertSame('0', $connection->fetchValue('SELECT COUNT(*) FROM %i', $sentinel));
        $connection->transaction(function () use ($runner): void {
            $error = $this->migrationFailure($runner->run(...));
            self::assertStringContainsString('idle database session', $error->getMessage());
        });
        $this->assertTableMissing($journal);
    }

    public function testRejectsDdlAfterTheNativeSessionChangesAndReleasesOriginalLock(): void
    {
        $journal = $this->ownedTable('migrations');
        $replacementTable = $this->ownedTable('must_not_create');
        $replacement = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $property = new ReflectionProperty(wpdb::class, 'dbh');
        $original = $property->getValue($this->db);
        $runner = new Runner(new MigrationSession($this->db, $this->migrationPrefix()), [
            '0001_change_session' => $this->migration(function (Schema $schema) use ($property, $replacement): void {
                $property->setValue($this->db, $property->getValue($replacement));
                $schema->createTable('must_not_create', 'id INT PRIMARY KEY');
            }),
        ]);
        try {
            try {
                $error = $this->migrationFailure($runner->run(...));
                self::assertStringContainsString('0001_change_session stopped before completion', $error->getMessage());
            } finally {
                $property->setValue($this->db, $original);
            }
            $this->assertTableMissing($replacementTable);
            $other = new Runner(new MigrationSession($replacement, $this->migrationPrefix()), []);
            $error = $this->migrationFailure($other->run(...));
            self::assertStringContainsString('0001_change_session is incomplete', $error->getMessage());
            self::assertSame('1', (new Connection($this->db))->fetchValue('SELECT COUNT(*) FROM %i', $journal));
        } finally {
            $replacement->close();
        }
    }

    public function testRejectsOlderOrRenamedMigrationCatalogAfterNewerMigrationsWereApplied(): void
    {
        $this->ownedTable('migrations');
        $session = new MigrationSession($this->db, $this->migrationPrefix());
        (new Runner($session, [
            '0002_already_applied' => $this->migration(static function (): void {
            }),
        ]))->run();

        $older = new Runner($session, [
            '0001_older_applied_late' => $this->migration(static function (): void {
                self::fail('An older catalog must not run.');
            }),
        ]);
        $error = $this->migrationFailure($older->run(...));
        self::assertStringContainsString('Applied migrations do not match', $error->getMessage());
    }

    /** @param Closure(Schema): void $up */
    private function migration(Closure $up): Migration
    {
        return new class ($up) implements Migration {
            /** @param Closure(Schema): void $up */
            public function __construct(private readonly Closure $up)
            {
            }

            public function up(Schema $schema): void
            {
                ($this->up)($schema);
            }
        };
    }

    /** @param Closure(): void $work */
    private function migrationFailure(Closure $work): MigrationException
    {
        try {
            $work();
        } catch (MigrationException $error) {
            return $error;
        }
        self::fail('Expected a migration exception.');
    }

    private function assertTableMissing(string $table): void
    {
        self::assertSame('0', (new Connection($this->db))->fetchValue(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));
    }
}
