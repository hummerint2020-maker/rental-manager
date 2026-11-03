<?php

declare(strict_types=1);

namespace RentalManager\Kernel;

/**
 * Composition root. Module registration is introduced with the Phase 1 container.
 */
final class Kernel
{
    public static function boot(): void
    {
        Health\AdminPage::register();
    }
}
