<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Db;

interface TransactionManager
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed;
}
