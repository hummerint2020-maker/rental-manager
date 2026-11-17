# T1.1 container and module registration

The Kernel owns a request-local Container. Domain and Application classes receive their
dependencies in constructors; they must never depend on Container or use it as a service
locator. Deptrac classifies the entire Kernel/Container namespace (interfaces included) as
infrastructure. The AST guard also rejects class names, imports and class-name strings there
from Domain/Application. Kernel exposes module metadata, not a container accessor.

## Registration and resolution

- `set($id, $instance)` registers that exact object as a singleton.
- `set($id, ProjectClass::class, Lifetime::Singleton)` creates a lazy class binding. Use
  `Lifetime::Transient` for a fresh instance on each get. Interface bindings use this form or
  an explicit factory. An already-created instance cannot be registered as transient.
- `factory($id, $closure, $lifetime)` registers a lazy factory. The closure receives the same
  Container and must return an object; external/vendor/WordPress objects require an explicit
  instance or factory, never implicit construction.
- `get($id)` returns a registered service or autowires a concrete RentalManager class.
  RentalManager/Vendor is excluded, and canonical reflection names are checked so class aliases
  cannot bypass that boundary. Unknown external IDs are rejected without invoking autoloaders.

Defaults are singleton per service ID within a Container, not static/global service instances. Registrations can
be replaced; replacement clears the previous cached instance. A singleton retains the actual
dependencies resolved during its construction, so registrations should finish before resolving
the graph. There is no reset/rebuild of already-constructed dependent objects.

Constructor parameters with declared defaults use those defaults, including nullable defaults.
Required named class/interface types resolve through get. No scalar value, union/intersection
choice or reference argument is guessed: use an explicit factory. Empty variadics receive no
arguments. Factory/constructor exceptions propagate, and resolution state is cleared in finally.
Only completed singleton construction is cached. A repeated resolution in the active stack
throws ContainerException with its service-ID cycle path, e.g. `a -> b -> a`.

These reversible infrastructure defaults are recorded as a pending T1.1 question in QUESTIONS.md.
The container adds no dependency or PSR container adapter.

## Module definitions and boot

Each existing `src/Modules/<Name>/module.php` returns a ModuleDefinition with the matching name.
All 13 current modules are empty. ModuleLoader reads directories in sorted order, validates
every definition before invoking registration callbacks, then registers services. Missing files,
wrong return types and mismatched names fail clearly. Definition files do not implicitly receive
the container; services callbacks receive it explicitly and should register lazy services only.

ModuleDefinition has an optional `services` closure and readonly `routes`, `migrations`,
`listeners` and `jobs` lists. The lists are retained as metadata; T1.1 does not define their future
element contracts, register routes, run migrations, dispatch events or schedule jobs. Those belong
to later tasks. The Kernel loads definitions before registering the existing System Health adapter.
Boot is idempotent after success, so repeated boot calls do not duplicate hooks or registrations.

Unit tests exercise registrations, both lifetimes, constructor injection, factories, defaults,
errors and constructor/factory cycles; module-loader tests verify ordering, metadata, validation
and lazy cross-definition resolution. Integration tests verify real plugin boot loads all empty
definitions once. Existing seven-cell CI tests and release/activation/PDF checks remain required.
