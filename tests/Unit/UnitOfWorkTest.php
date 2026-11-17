<?php

declare(strict_types=1);

namespace RentalManager\Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentalManager\Kernel\Db\DbException;
use RentalManager\Kernel\Db\TransactionalUnitOfWork;
use RentalManager\Tests\Support\RecordingTransactionManager;
use RuntimeException;
use stdClass;

final class UnitOfWorkTest extends TestCase
{
    public function testFlushesBeforeCommitAndReturnsTheWorkResult(): void
    {
        $transactions = new RecordingTransactionManager();
        $event = new stdClass();
        $audit = ['action' => 'recorded'];
        $result = new stdClass();
        $batches = [];
        $flush = static function (array $events, array $entries) use ($transactions, &$batches): void {
            $transactions->steps[] = 'flush';
            $batches[] = [$events, $entries];
        };
        $unit = new TransactionalUnitOfWork($transactions, $flush);
        $work = static function () use ($transactions, $unit, $event, $audit, $result): stdClass {
            $transactions->steps[] = 'work';
            $unit->recordEvent($event);
            $unit->recordAudit($audit);
            return $result;
        };
        self::assertSame($result, $unit->run($work));
        self::assertSame(['begin', 'work', 'flush', 'commit'], $transactions->steps);
        self::assertNull($unit->run(static fn () => null));
        self::assertSame([[[$event], [$audit]], [[], []]], $batches);
    }

    #[DataProvider('failureStages')]
    public function testRollsBackAndClearsRecordsAfterAnException(bool $failsDuringFlush): void
    {
        $transactions = new RecordingTransactionManager();
        $failure = new RuntimeException('failed');
        $batches = [];
        $flush = static function (array $events, array $entries) use (&$batches, $failsDuringFlush, $failure): void {
            $batches[] = [$events, $entries];
            if ($failsDuringFlush && count($batches) === 1) {
                throw $failure;
            }
        };
        $unit = new TransactionalUnitOfWork($transactions, $flush);
        try {
            $unit->run(static function () use ($unit, $failsDuringFlush, $failure): void {
                $unit->recordEvent(new stdClass());
                $unit->recordAudit(['action' => 'failed']);
                if (!$failsDuringFlush) {
                    throw $failure;
                }
            });
            self::fail('The failure must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(['begin', 'rollback'], $transactions->steps);
        self::assertCount($failsDuringFlush ? 1 : 0, $batches);
        $unit->run(static fn () => null);
        self::assertSame([[], []], end($batches));
        self::assertSame(['begin', 'rollback', 'begin', 'commit'], $transactions->steps);
    }

    /** @return iterable<string, array{bool}> */
    public static function failureStages(): iterable
    {
        yield 'work' => [false];
        yield 'flush' => [true];
    }

    public function testRejectsNestingBeforeOpeningAnotherTransaction(): void
    {
        $transactions = new RecordingTransactionManager();
        $unit = new TransactionalUnitOfWork($transactions, static function (): void {
            self::fail('Rejected work must not flush.');
        });
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Nested units of work');
        try {
            $unit->run(static fn () => $unit->run(static fn () => null));
        } finally {
            self::assertSame(['begin', 'rollback'], $transactions->steps);
        }
    }

    #[DataProvider('invalidRecordingScopes')]
    public function testRejectsRecordingOutsideWork(bool $duringFlush, bool $event): void
    {
        $transactions = new RecordingTransactionManager();
        $record = static function (TransactionalUnitOfWork $unit) use ($event): void {
            if ($event) {
                $unit->recordEvent(new stdClass());
            } else {
                $unit->recordAudit([]);
            }
        };
        $unit = new TransactionalUnitOfWork($transactions, static function () use (&$unit, $record): void {
            $record($unit);
        });
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Records can only be added during the unit of work callback.');
        try {
            if ($duringFlush) {
                $unit->run(static fn () => null);
            } else {
                $record($unit);
            }
        } finally {
            self::assertSame($duringFlush ? ['begin', 'rollback'] : [], $transactions->steps);
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function invalidRecordingScopes(): iterable
    {
        yield 'event outside run' => [false, true];
        yield 'audit outside run' => [false, false];
        yield 'event during flush' => [true, true];
        yield 'audit during flush' => [true, false];
    }

    public function testRetriesWithOnlyTheRecordsFromTheSuccessfulAttempt(): void
    {
        $transactions = new RecordingTransactionManager();
        $discarded = new stdClass();
        $retained = new stdClass();
        $batches = [];
        $flush = static function (array $events, array $entries) use (&$batches): void {
            $batches[] = [$events, $entries];
        };
        $unit = new TransactionalUnitOfWork($transactions, $flush);
        $attempt = 0;
        $result = $unit->run(static function () use ($unit, &$attempt, $discarded, $retained): int {
            ++$attempt;
            $unit->recordEvent($attempt === 1 ? $discarded : $retained);
            $unit->recordAudit(['attempt' => $attempt]);
            if ($attempt === 1) {
                throw new DbException('Deadlock', 1213);
            }
            return $attempt;
        });
        self::assertSame(2, $result);
        self::assertSame([[[$retained], [['attempt' => 2]]]], $batches);
        self::assertSame(['begin', 'rollback', 'begin', 'commit'], $transactions->steps);
    }
}
