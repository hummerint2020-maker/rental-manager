<?php

declare(strict_types=1);

namespace RentalManager\Kernel;

use RentalManager\Kernel\Container\Container;
use RentalManager\Kernel\Container\ModuleDefinition;
use RentalManager\Kernel\Container\ModuleLoader;

/** Composition root; the container is never exposed to application/domain code. */
final class Kernel
{
    private static ?self $instance = null;
    /** @var array<string, ModuleDefinition> */
    private array $modules = [];

    private function __construct(private readonly Container $container)
    {
    }

    public static function boot(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $kernel = new self(new Container());
        $kernel->modules = (new ModuleLoader())->load($kernel->container, dirname(__DIR__) . '/Modules');
        Health\AdminPage::register();
        self::$instance = $kernel;
        return $kernel;
    }

    /** @return array<string, ModuleDefinition> */
    public function modules(): array
    {
        return $this->modules;
    }
}
