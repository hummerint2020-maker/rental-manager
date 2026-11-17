<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Db;

use Closure;

final class RealTransaction implements TransactionStrategy
{
    private readonly SavepointTransaction $probe;

    public function __construct()
    {
        $this->probe = new SavepointTransaction();
    }

    public function begin(Closure $control): void
    {
        // Starting a transaction would implicitly commit a raw caller-owned transaction.
        try {
            $this->probe->begin($control);
        } catch (DbException $error) {
            if ($error->getCode() !== 1305) {
                throw $error;
            }
            $control('START TRANSACTION');
            return;
        }
        $this->probe->commit($control);
        throw new DbException('Nested database transactions are not allowed.');
    }

    public function commit(Closure $control): void
    {
        $control('COMMIT');
    }

    public function rollBack(Closure $control): void
    {
        $control('ROLLBACK');
    }
}
