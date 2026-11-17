<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Db;

use RuntimeException;

final class DbException extends RuntimeException
{
    public function isRetryable(): bool
    {
        return in_array($this->getCode(), [1213, 1205], true);
    }
}
