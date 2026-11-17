<?php

declare(strict_types=1);

namespace RentalManager\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use RentalManager\Kernel\Container\Container;
use RentalManager\Kernel\Container\ContainerException;
use RentalManager\Kernel\Container\Lifetime;
use RentalManager\Tests\Support\ContainerFixtures\AbstractService;
use RentalManager\Tests\Support\ContainerFixtures\Contract;
use RentalManager\Tests\Support\ContainerFixtures\Consumer;
use RentalManager\Tests\Support\ContainerFixtures\Defaults;
use RentalManager\Tests\Support\ContainerFixtures\ScalarService;
use RentalManager\Tests\Support\ContainerFixtures\UntypedService;
use RentalManager\Tests\Support\ContainerFixtures\UnionService;
use RentalManager\Tests\Support\ContainerFixtures\ReferenceService;
use RentalManager\Tests\Support\ContainerFixtures\IntersectionService;
use RentalManager\Tests\Support\ContainerFixtures\CycleA;
use RentalManager\Tests\Support\ContainerFixtures\CycleB;
use RentalManager\Tests\Support\ContainerFixtures\Dependency;
use RentalManager\Tests\Support\ContainerFixtures\PrivateService;
use stdClass;

final class ContainerTest extends TestCase
{
    public function testInstanceRegistrationAndReplacement(): void
    {
        $container = new Container();
        $first = new Dependency();
        $container->set(Contract::class, $first);
        self::assertSame($first, $container->get(Contract::class));
        $second = new Dependency();
        $container->set(Contract::class, $second);
        self::assertSame($second, $container->get(Contract::class));
        $container->factory(Contract::class, static fn (): object => new Dependency());
        self::assertNotSame($second, $container->get(Contract::class));
    }

    public function testClassBindingsAndSingletonVersusTransientLifetimes(): void
    {
        $container = new Container();
        $container->set(Contract::class, Dependency::class);
        self::assertSame($container->get(Contract::class), $container->get(Contract::class));
        $container->set(Contract::class, Dependency::class, Lifetime::Transient);
        self::assertNotSame($container->get(Contract::class), $container->get(Contract::class));
        self::assertSame($container->get(Dependency::class), $container->get(Dependency::class));
        self::assertNotSame($container->get(Dependency::class), (new Container())->get(Dependency::class));
    }

    public function testAutowiresConstructorDependenciesAndRegisteredInterfaces(): void
    {
        $container = new Container();
        $bound = new Dependency();
        $container->set(Contract::class, $bound);
        $result = $container->get(Consumer::class);
        self::assertInstanceOf(Consumer::class, $result);
        self::assertSame($bound, $result->contract);
        self::assertSame($container->get(Dependency::class), $result->concrete);
    }

    public function testFactoriesAreLazyReceiveTheContainerAndRespectLifetimes(): void
    {
        $container = new Container();
        $calls = 0;
        $factory = static function (Container $received) use ($container, &$calls): object {
            self::assertSame($container, $received);
            ++$calls;
            return new Dependency();
        };
        $container->factory('service', $factory);
        self::assertSame(0, $calls);
        self::assertSame($container->get('service'), $container->get('service'));
        self::assertSame(1, $calls);
        $container->factory('service', $factory, Lifetime::Transient);
        self::assertNotSame($container->get('service'), $container->get('service'));
        self::assertSame(3, $calls);
    }

    public function testExplicitDefaultsAndEmptyVariadicsAreRespected(): void
    {
        $result = (new Container())->get(Defaults::class);
        self::assertInstanceOf(Defaults::class, $result);
        self::assertSame(8080, $result->port);
        self::assertNull($result->optional);
        self::assertSame([], $result->rest);
    }

    public function testUnknownServicesAndNonInstantiableTypesHaveClearErrors(): void
    {
        $container = new Container();
        foreach (
            [
            'missing' => 'Unknown service: missing',
            'RentalManager\\Tests\\MissingService' => 'Unknown service:',
            Contract::class => 'Non-instantiable type:',
            AbstractService::class => 'Non-instantiable type:',
            PrivateService::class => 'Non-instantiable type:',
            ] as $id => $message
        ) {
            try {
                $container->get($id);
                self::fail('Resolution must fail: ' . $id);
            } catch (ContainerException $error) {
                self::assertStringContainsString($message, $error->getMessage());
                self::assertStringContainsString($id, $error->getMessage());
            }
        }
    }

    public function testRequiredScalarUntypedAndUnionParametersNeedAnExplicitFactory(): void
    {
        $classes = [
            ScalarService::class, UntypedService::class, UnionService::class,
            ReferenceService::class, IntersectionService::class,
        ];
        foreach ($classes as $class) {
            try {
                (new Container())->get($class);
                self::fail('Required parameter must not be guessed.');
            } catch (ContainerException $error) {
                self::assertStringContainsString('Unresolvable parameter $port', $error->getMessage());
                self::assertStringContainsString($class, $error->getMessage());
            }
        }
        $container = new Container();
        $container->factory(ScalarService::class, static fn (): object => new ScalarService(1234));
        $result = $container->get(ScalarService::class);
        self::assertInstanceOf(ScalarService::class, $result);
        self::assertSame(1234, $result->port);
    }

    public function testNeverAutowiresWordPressVendorOrNativeTypesButAllowsExplicitInstances(): void
    {
        $autoloaded = [];
        $spy = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($spy, true, true);
        try {
            foreach (['WP_User', 'Mpdf\\Mpdf', 'RentalManager\\Vendor\\Mpdf\\Mpdf', stdClass::class] as $id) {
                try {
                    (new Container())->get($id);
                    self::fail('External type must not be autowired.');
                } catch (ContainerException $error) {
                    self::assertStringContainsString('autowiring is restricted', $error->getMessage());
                }
            }
            self::assertSame([], $autoloaded, 'No external autoloader should be invoked.');
        } finally {
            spl_autoload_unregister($spy);
        }
        $container = new Container();
        $external = new stdClass();
        $container->set(stdClass::class, $external);
        self::assertSame($external, $container->get(stdClass::class));
        $container->factory('external', static fn (): object => new stdClass());
        self::assertInstanceOf(stdClass::class, $container->get('external'));
    }

    public function testProjectAliasCannotAutowireAnExternalType(): void
    {
        $alias = 'RentalManager\\Tests\\Unit\\AliasedExternalTestCase';
        class_alias(TestCase::class, $alias);
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Autowiring is restricted to project classes');
        (new Container())->get($alias);
    }

    public function testConstructorCycleReportsTheWholeCyclePath(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Dependency cycle: ' . CycleA::class . ' -> ' . CycleB::class
            . ' -> ' . CycleA::class);
        (new Container())->get(CycleA::class);
    }

    public function testFactoryCyclesAndRecoveryAfterFailure(): void
    {
        $container = new Container();
        $container->factory('a', static fn (Container $c): object => $c->get('b'));
        $container->factory('b', static fn (Container $c): object => $c->get('a'));
        try {
            $container->get('a');
            self::fail('Factory cycle must fail.');
        } catch (ContainerException $error) {
            self::assertSame('Dependency cycle: a -> b -> a', $error->getMessage());
        }
        $container->factory('b', static fn (): object => new Dependency());
        self::assertSame($container->get('b'), $container->get('a'));
        $container->factory('fails', static fn (): object => throw new LogicException('Factory failed.'));
        try {
            $container->get('fails');
            self::fail('Factory exceptions must propagate.');
        } catch (LogicException $error) {
            self::assertSame('Factory failed.', $error->getMessage());
        }
        $container->set('fails', Dependency::class);
        self::assertInstanceOf(Dependency::class, $container->get('fails'));
    }

    public function testInvalidFactoryResultAndTransientInstanceAreRejected(): void
    {
        $container = new Container();
        $container->factory('invalid', static fn (): int => 42);
        try {
            $container->get('invalid');
            self::fail('A scalar is not a service object.');
        } catch (ContainerException $error) {
            self::assertSame('Factory must return an object for service: invalid', $error->getMessage());
        }
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('An instance registration must be a singleton');
        $container->set('invalid', new Dependency(), Lifetime::Transient);
    }
}
