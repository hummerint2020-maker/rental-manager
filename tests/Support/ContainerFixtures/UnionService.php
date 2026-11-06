<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support\ContainerFixtures;

final class UnionService
{
    public function __construct(public readonly int|Dependency $port)
    {
    }
}
