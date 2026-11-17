<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Db;

use Closure;

/** Runs a transaction within a caller-owned outer transaction. */
final class SavepointTransaction implements TransactionStrategy
{
    private readonly string $name;

    public function __construct()
    {
        $this->name = 'rm_uow_' . bin2hex(random_bytes(16));
    }

    public function begin(Closure $control): void
    {
        $control('SAVEPOINT ' . $this->name);
        // With autocommit on and no outer transaction, SAVEPOINT alone can succeed as a no-op.
        $control('ROLLBACK TO SAVEPOINT ' . $this->name);
    }

    public function commit(Closure $control): void
    {
        $control('RELEASE SAVEPOINT ' . $this->name);
    }

    public function rollBack(Closure $control): void
    {
        $control('ROLLBACK TO SAVEPOINT ' . $this->name);
        $control('RELEASE SAVEPOINT ' . $this->name);
    }
}
