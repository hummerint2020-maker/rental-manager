<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Container;

enum Lifetime
{
    case Singleton;
    case Transient;
}
