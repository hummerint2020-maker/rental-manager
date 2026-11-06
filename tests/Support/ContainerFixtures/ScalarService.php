<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support\ContainerFixtures;

final class ScalarService
{
    public function __construct(public readonly int $port)
    {
    }
}
