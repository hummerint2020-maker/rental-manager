<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

use RuntimeException;
use Throwable;

class MigrationException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?string $migrationId = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function migrationId(): ?string
    {
        return $this->migrationId;
    }
}
