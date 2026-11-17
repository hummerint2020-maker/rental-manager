<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Db;

interface UnitOfWork
{
    /**
     * Runs work in a transaction, flushing its recorded events and audit entries before commit.
     * Work may be repeated after rollback and must not perform irreversible side effects.
     *
     * @template TResult
     * @param callable(): TResult $work
     * @return TResult
     */
    public function run(callable $work): mixed;

    /** Records an event during the work callback. */
    public function recordEvent(object $event): void;

    /** @param array<string, mixed> $entry */
    public function recordAudit(array $entry): void;
}
