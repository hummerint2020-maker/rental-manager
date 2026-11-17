<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Db;

use Closure;

/** Defines transaction boundaries on the connection owned by Connection. */
interface TransactionStrategy
{
    /** @param Closure(string): void $control */
    public function begin(Closure $control): void;

    /** @param Closure(string): void $control */
    public function commit(Closure $control): void;

    /** @param Closure(string): void $control */
    public function rollBack(Closure $control): void;
}
