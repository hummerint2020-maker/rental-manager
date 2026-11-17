<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support\ContainerFixtures;

final class UntypedService
{
    public readonly mixed $value;

    /** @param mixed $port */
    public function __construct($port)
    {
        $this->value = $port;
    }
}
