<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support\ContainerFixtures;

final class CycleA
{
    public function __construct(public readonly CycleB $dependency)
    {
    }
}
