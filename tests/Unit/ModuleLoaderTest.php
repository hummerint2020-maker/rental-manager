<?php

declare(strict_types=1);

namespace RentalManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RentalManager\Kernel\Container\Container;
use RentalManager\Kernel\Container\ContainerException;
use RentalManager\Kernel\Container\ModuleLoader;
use RentalManager\Tests\Support\ArchitectureSandbox;

final class ModuleLoaderTest extends TestCase
{
    public function testLoadsDefinitionsInOrderRegistersLazyServicesAndRetainsAllMetadata(): void
    {
        $sandbox = new ArchitectureSandbox();
        try {
            mkdir($sandbox->directory . '/src/Beta');
            mkdir($sandbox->directory . '/src/Alpha');
            file_put_contents($sandbox->directory . '/src/Alpha/module.php', <<<'PHP'
<?php
use RentalManager\Kernel\Container\Container;
use RentalManager\Kernel\Container\ModuleDefinition;
return new ModuleDefinition('Alpha', static function (Container $container): void {
    $container->factory('service', static fn (Container $c): object => $c->get('dependency'));
}, routes: ['route-fixture'], migrations: ['migration-fixture'],
listeners: ['listener-fixture'], jobs: ['job-fixture']);
PHP);
            file_put_contents($sandbox->directory . '/src/Beta/module.php', <<<'PHP'
<?php
use RentalManager\Kernel\Container\Container;
use RentalManager\Kernel\Container\ModuleDefinition;
return new ModuleDefinition('Beta', static function (Container $container): void {
    $container->set('dependency', new stdClass());
});
PHP);
            $container = new Container();
            $modules = (new ModuleLoader())->load($container, $sandbox->directory . '/src');
            self::assertSame(['Alpha', 'Beta'], array_keys($modules));
            self::assertSame($container->get('dependency'), $container->get('service'));
            self::assertSame(['route-fixture'], $modules['Alpha']->routes);
            self::assertSame(['migration-fixture'], $modules['Alpha']->migrations);
            self::assertSame(['listener-fixture'], $modules['Alpha']->listeners);
            self::assertSame(['job-fixture'], $modules['Alpha']->jobs);
        } finally {
            $sandbox->remove();
        }
    }

    public function testEveryRepositoryModuleHasAnEmptyDefinition(): void
    {
        $modules = (new ModuleLoader())->load(new Container(), dirname(__DIR__, 2) . '/src/Modules');
        self::assertSame([
            'Activity', 'Billing', 'Documents', 'Expenses', 'Identity', 'Leasing', 'Maintenance',
            'Notifications', 'Platform', 'Renters', 'Reporting', 'Settings', 'Units',
        ], array_keys($modules));
        foreach ($modules as $name => $definition) {
            self::assertSame($name, $definition->name);
            self::assertNull($definition->services);
            self::assertSame([], $definition->routes);
            self::assertSame([], $definition->migrations);
            self::assertSame([], $definition->listeners);
            self::assertSame([], $definition->jobs);
        }
    }

    public function testInvalidDefinitionIsRejectedBeforeAnyServiceRegistrationRuns(): void
    {
        $sandbox = new ArchitectureSandbox();
        try {
            mkdir($sandbox->directory . '/src/Alpha');
            mkdir($sandbox->directory . '/src/Beta');
            file_put_contents($sandbox->directory . '/src/Alpha/module.php', <<<'PHP'
<?php
return new \RentalManager\Kernel\Container\ModuleDefinition('Alpha', static function ($c): void {
    $c->set('must-not-run', new stdClass());
});
PHP);
            $file = $sandbox->directory . '/src/Beta/module.php';
            $container = new Container();
            $invalid = [
                '<?php return 42;',
                '<?php return new \\RentalManager\\Kernel\\Container\\ModuleDefinition("Other");',
            ];
            foreach ($invalid as $source) {
                file_put_contents($file, $source);
                try {
                    (new ModuleLoader())->load($container, $sandbox->directory . '/src');
                    self::fail('An invalid definition must fail.');
                } catch (ContainerException $error) {
                    self::assertStringContainsString('Expected ModuleDefinition named Beta', $error->getMessage());
                }
            }
            $this->expectException(ContainerException::class);
            $this->expectExceptionMessage('Unknown service: must-not-run');
            $container->get('must-not-run');
        } finally {
            $sandbox->remove();
        }
    }

    public function testMissingDefinitionAndUnavailableDirectoryFailClearly(): void
    {
        $sandbox = new ArchitectureSandbox();
        try {
            $loader = new ModuleLoader();
            self::assertSame([], $loader->load(new Container(), $sandbox->directory . '/src'));
            mkdir($sandbox->directory . '/src/Missing');
            $missing = [
                $sandbox->directory . '/src' => 'Missing module definition:',
                $sandbox->directory . '/absent' => 'Module directory is unavailable:',
            ];
            foreach ($missing as $directory => $message) {
                try {
                    $loader->load(new Container(), $directory);
                    self::fail('Missing configuration must fail.');
                } catch (ContainerException $error) {
                    self::assertStringContainsString($message, $error->getMessage());
                }
            }
        } finally {
            $sandbox->remove();
        }
    }
}
