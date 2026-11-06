<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support\ContainerFixtures;

use Stringable;

final class IntersectionService
{
    public function __construct(public readonly Contract&Stringable $port)
    {
    }
}
