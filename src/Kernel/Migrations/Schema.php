<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

use Closure;

/** Table definitions are trusted, source-controlled SQL fragments. */
final class Schema
{
    /** @param Closure(string, string): void $createTable */
    public function __construct(private readonly Closure $createTable)
    {
    }

    public function createTable(string $suffix, string $trustedDefinition): void
    {
        ($this->createTable)($suffix, $trustedDefinition);
    }
}
