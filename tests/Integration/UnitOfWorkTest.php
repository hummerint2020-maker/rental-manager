<?php

declare(strict_types=1);

namespace RentalManager\Tests\Integration;

use LogicException;
use RentalManager\Kernel\Db\Connection;
use RentalManager\Kernel\Db\DbException;
use RentalManager\Kernel\Db\SavepointTransaction;
use RentalManager\Kernel\Db\TransactionalUnitOfWork;
use RentalManager\Tests\Support\DatabaseTestCase;
use RentalManager\Tests\Support\FixtureSchema;
use RentalManager\Tests\Support\World;
use stdClass;
use wpdb;

final class UnitOfWorkTest extends DatabaseTestCase
{
    public function testWorkAndBothBatchesAreWrittenBeforeTheSavepointIsReleased(): void
    {
        $world = new World($this->db);
        $connection = new Connection($this->db, new SavepointTransaction());
        $table = FixtureSchema::table($this->db, 'probe');
        $flushed = false;
        $uow = new TransactionalUnitOfWork($connection, function (
            array $events,
            array $audit
        ) use (
            $connection,
            $table,
            $world,
            &$flushed
        ): void {
            self::assertCount(1, $events);
            self::assertSame([['action' => 'fixture']], $audit);
            self::assertSame('1', $this->countRows($connection, $table, $world->a->id));
            $this->insert($connection, $table, $world->a->id, 'E', 'event');
            $this->insert($connection, $table, $world->a->id, 'A', 'audit');
            $flushed = true;
        });
        $result = $uow->run(function () use ($uow, $connection, $table, $world): string {
            $this->insert($connection, $table, $world->a->id, 'C', 'command');
            $uow->recordEvent(new stdClass());
            $uow->recordAudit(['action' => 'fixture']);
            return 'done';
        });
        self::assertSame('done', $result);
        self::assertTrue($flushed);
        self::assertSame('3', $this->countRows($connection, $table, $world->a->id));
        $observer = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        try {
            // A successful inner UoW must not commit the harness's outer transaction.
            self::assertSame('0', $observer->get_var($observer->prepare(
                'SELECT COUNT(*) FROM %i WHERE account_id = %d',
                $table,
                $world->a->id
            )));
        } finally {
            $observer->close();
        }
    }

    public function testWorkFailureRollsBackWritesWithoutFlushingRecordedSideEffects(): void
    {
        $world = new World($this->db);
        $connection = new Connection($this->db, new SavepointTransaction());
        $table = FixtureSchema::table($this->db, 'probe');
        $flushed = false;
        $uow = new TransactionalUnitOfWork($connection, static function () use (&$flushed): void {
            $flushed = true;
        });
        $failure = new LogicException('Work failed.');
        $caught = null;
        try {
            $uow->run(function () use ($uow, $connection, $table, $world, $failure): void {
                $this->insert($connection, $table, $world->a->id, 'C', 'command');
                $uow->recordEvent(new stdClass());
                $uow->recordAudit(['action' => 'fixture']);
                throw $failure;
            });
        } catch (LogicException $error) {
            $caught = $error;
        }
        self::assertSame($failure, $caught);
        self::assertFalse($flushed);
        self::assertSame('0', $this->countRows($connection, $table, $world->a->id));
    }

    public function testFlusherFailureRollsBackItsPartialBatchAndTheOriginalWork(): void
    {
        $world = new World($this->db);
        $connection = new Connection($this->db, new SavepointTransaction());
        $table = FixtureSchema::table($this->db, 'probe');
        $failure = new LogicException('Flusher failed.');
        $uow = new TransactionalUnitOfWork($connection, function () use (
            $connection,
            $table,
            $world,
            $failure
        ): void {
            $this->insert($connection, $table, $world->a->id, 'E', 'partial-event-batch');
            throw $failure;
        });
        try {
            $uow->run(function () use ($uow, $connection, $table, $world): void {
                $this->insert($connection, $table, $world->a->id, 'C', 'command');
                $uow->recordEvent(new stdClass());
                $uow->recordAudit(['action' => 'fixture']);
            });
            self::fail('Flusher failure must escape the unit of work.');
        } catch (LogicException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame('0', $this->countRows($connection, $table, $world->a->id));
    }

    public function testRetryRollsBackSqlAndStartsWithFreshEventAndAuditBatches(): void
    {
        $world = new World($this->db);
        $connection = new Connection($this->db, new SavepointTransaction());
        $table = FixtureSchema::table($this->db, 'probe');
        $attempts = 0;
        $uow = new TransactionalUnitOfWork($connection, function (
            array $events,
            array $audit
        ) use (
            $connection,
            $table,
            $world
        ): void {
            self::assertCount(1, $events);
            self::assertSame([['attempt' => 2]], $audit);
            $this->insert($connection, $table, $world->a->id, 'E', 'event-second-attempt');
            $this->insert($connection, $table, $world->a->id, 'A', 'audit-second-attempt');
        });
        $uow->run(function () use ($uow, $connection, $table, $world, &$attempts): void {
            ++$attempts;
            $this->insert($connection, $table, $world->a->id, 'C', 'command-attempt-' . $attempts);
            $uow->recordEvent(new stdClass());
            $uow->recordAudit(['attempt' => $attempts]);
            if ($attempts === 1) {
                throw new DbException('Simulated deadlock.', 1213);
            }
        });
        self::assertSame(2, $attempts);
        self::assertSame('3', $this->countRows($connection, $table, $world->a->id));
        self::assertSame('0', $connection->fetchValue(
            'SELECT COUNT(*) FROM %i WHERE account_id = %d AND note = %s',
            $table,
            $world->a->id,
            'command-attempt-1'
        ));
    }

    private function insert(Connection $connection, string $table, int $account, string $suffix, string $note): void
    {
        $connection->execute(
            'INSERT INTO %i (account_id, public_id, note) VALUES (%d, %s, %s)',
            $table,
            $account,
            str_repeat('0', 25) . $suffix,
            $note
        );
    }

    private function countRows(Connection $connection, string $table, int $account): ?string
    {
        return $connection->fetchValue('SELECT COUNT(*) FROM %i WHERE account_id = %d', $table, $account);
    }
}
