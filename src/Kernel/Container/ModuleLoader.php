<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Container;

final class ModuleLoader
{
    /** @return array<string, ModuleDefinition> */
    public function load(Container $container, string $directory): array
    {
        if (!is_dir($directory)) {
            throw new ContainerException('Module directory is unavailable: ' . $directory);
        }
        $directories = glob($directory . '/*', GLOB_ONLYDIR);
        if ($directories === false) {
            throw new ContainerException('Unable to list module definitions.');
        }
        sort($directories, SORT_STRING);
        $definitions = [];
        foreach ($directories as $module) {
            $file = $module . '/module.php';
            if (!is_file($file)) {
                throw new ContainerException('Missing module definition: ' . $file);
            }
            // Definition files have no implicit access to the container or loader scope.
            $definition = (static fn (string $path): mixed => require $path)($file);
            if (!$definition instanceof ModuleDefinition || $definition->name !== basename($module)) {
                throw new ContainerException('Expected ModuleDefinition named ' . basename($module) . ' in ' . $file);
            }
            $definitions[$definition->name] = $definition;
        }
        foreach ($definitions as $definition) {
            if ($definition->services !== null) {
                ($definition->services)($container);
            }
        }
        return $definitions;
    }
}
