<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Db;

use Closure;
use LogicException;

final class TransactionalUnitOfWork implements UnitOfWork
{
    private bool $running = false;
    private bool $recording = false;

    /** @var list<object> */
    private array $events = [];

    /** @var list<array<string, mixed>> */
    private array $audit = [];

    /**
     * The flusher persists both batches using the same transaction as the work.
     *
     * @param Closure(list<object>, list<array<string, mixed>>): void $flush
     */
    public function __construct(private readonly TransactionManager $transactions, private readonly Closure $flush)
    {
    }

    public function run(callable $work): mixed
    {
        if ($this->running) {
            throw new LogicException('Nested units of work are not allowed.');
        }
        $this->running = true;
        try {
            return $this->transactions->transaction(function () use ($work): mixed {
                try {
                    $this->recording = true;
                    $result = $work();
                    $this->recording = false;
                    ($this->flush)($this->events, $this->audit);
                    return $result;
                } finally {
                    $this->recording = false;
                    $this->events = [];
                    $this->audit = [];
                }
            });
        } finally {
            $this->running = false;
        }
    }

    public function recordEvent(object $event): void
    {
        $this->requireRecording();
        $this->events[] = $event;
    }

    public function recordAudit(array $entry): void
    {
        $this->requireRecording();
        $this->audit[] = $entry;
    }

    private function requireRecording(): void
    {
        if (!$this->recording) {
            throw new LogicException('Records can only be added during the unit of work callback.');
        }
    }
}
