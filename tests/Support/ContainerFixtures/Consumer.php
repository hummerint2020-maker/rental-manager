<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support\ContainerFixtures;

final class Consumer
{
    public function __construct(public readonly Dependency $concrete, public readonly Contract $contract)
    {
    }
}
