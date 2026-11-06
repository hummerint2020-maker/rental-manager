<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Container;

use Closure;

/** Metadata is retained for the later router/migration/event/job tasks, never executed here. */
final class ModuleDefinition
{
    /**
     * @param null|Closure(Container): void $services Registration only; do not resolve services here.
     * @param list<mixed> $routes
     * @param list<mixed> $migrations
     * @param list<mixed> $listeners
     * @param list<mixed> $jobs
     */
    public function __construct(
        public readonly string $name,
        public readonly ?Closure $services = null,
        public readonly array $routes = [],
        public readonly array $migrations = [],
        public readonly array $listeners = [],
        public readonly array $jobs = []
    ) {
    }
}
