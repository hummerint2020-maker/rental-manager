<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Container;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/** Request-local composition infrastructure. Inject dependencies, never this container, into use cases. */
final class Container
{
    /** @var array<string, array{Closure(self): mixed, Lifetime}> */
    private array $definitions = [];
    /** @var array<string, object> */
    private array $instances = [];
    /** @var list<string> */
    private array $resolving = [];

    /** @param object|class-string $service */
    public function set(string $id, object|string $service, Lifetime $lifetime = Lifetime::Singleton): void
    {
        if (is_object($service)) {
            if ($lifetime !== Lifetime::Singleton) {
                throw new ContainerException('An instance registration must be a singleton: ' . $id);
            }
            $this->assertId($id);
            unset($this->definitions[$id]);
            $this->instances[$id] = $service;
            return;
        }
        $this->factory($id, fn (): object => $this->autowire($service), $lifetime);
    }

    /** @param Closure(self): mixed $factory */
    public function factory(string $id, Closure $factory, Lifetime $lifetime = Lifetime::Singleton): void
    {
        $this->assertId($id);
        unset($this->instances[$id]);
        $this->definitions[$id] = [$factory, $lifetime];
    }

    public function get(string $id): object
    {
        $this->assertId($id);
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        $cycle = array_search($id, $this->resolving, true);
        if ($cycle !== false) {
            $path = array_merge(array_slice($this->resolving, $cycle), [$id]);
            throw new ContainerException('Dependency cycle: ' . implode(' -> ', $path));
        }
        $this->resolving[] = $id;
        try {
            [$factory, $lifetime] = $this->definitions[$id] ?? [
                fn (): object => $this->autowire($id), Lifetime::Singleton,
            ];
            $service = $factory($this);
            if (!is_object($service)) {
                throw new ContainerException('Factory must return an object for service: ' . $id);
            }
            if ($lifetime === Lifetime::Singleton) {
                $this->instances[$id] = $service;
            }
            return $service;
        } finally {
            array_pop($this->resolving);
        }
    }

    private function assertId(string $id): void
    {
        if ($id === '') {
            throw new ContainerException('Service id must not be empty.');
        }
    }

    private function autowire(string $class): object
    {
        if (!str_starts_with($class, 'RentalManager\\') || str_starts_with($class, 'RentalManager\\Vendor\\')) {
            throw new ContainerException('Unknown service: ' . $class
                . '; autowiring is restricted to project classes.');
        }
        if (!class_exists($class) && !interface_exists($class)) {
            throw new ContainerException('Unknown service: ' . $class);
        }
        $type = new ReflectionClass($class);
        // Check the canonical name too: aliases must not bypass the vendor/WordPress boundary.
        $name = $type->getName();
        if (!str_starts_with($name, 'RentalManager\\') || str_starts_with($name, 'RentalManager\\Vendor\\')) {
            throw new ContainerException('Autowiring is restricted to project classes: ' . $class);
        }
        if (!$type->isInstantiable()) {
            throw new ContainerException('Non-instantiable type: ' . $class . '; register an instance or factory.');
        }
        $arguments = [];
        foreach ($type->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (!$parameter->isVariadic()) {
                $arguments[] = $this->argument($parameter, $class);
            }
        }
        return $type->newInstanceArgs($arguments);
    }

    private function argument(ReflectionParameter $parameter, string $class): mixed
    {
        if ($parameter->isDefaultValueAvailable() && !$parameter->isPassedByReference()) {
            return $parameter->getDefaultValue();
        }
        $type = $parameter->getType();
        if (!$parameter->isPassedByReference() && $type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = $type->getName();
            if ($name === 'self') {
                $name = $parameter->getDeclaringClass()?->getName() ?? $name;
            } elseif ($name === 'parent') {
                $parent = $parameter->getDeclaringClass()?->getParentClass();
                $name = $parent ? $parent->getName() : $name;
            }
            return $this->get($name);
        }
        throw new ContainerException('Unresolvable parameter $' . $parameter->getName() . ' of ' . $class
            . '; scalar, untyped, union/intersection or reference parameters require a factory.');
    }
}
