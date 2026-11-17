<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support\ContainerFixtures;

final class CycleB
{
    public function __construct(public readonly CycleA $dependency)
    {
    }
}
