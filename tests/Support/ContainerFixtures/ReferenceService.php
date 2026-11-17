<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support\ContainerFixtures;

final class ReferenceService
{
    public readonly int $port;

    public function __construct(int &$port)
    {
        $this->port = $port;
    }
}
