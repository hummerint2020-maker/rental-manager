<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Health;

final class Result
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $message,
        public readonly string $detail = ''
    ) {
    }
}
