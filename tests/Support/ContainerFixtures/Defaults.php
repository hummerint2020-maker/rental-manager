<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support\ContainerFixtures;

final class Defaults
{
    /** @var list<string> */
    public readonly array $rest;

    public function __construct(
        public readonly int $port = 8080,
        public readonly ?Contract $optional = null,
        string ...$rest
    ) {
        $this->rest = $rest;
    }
}
