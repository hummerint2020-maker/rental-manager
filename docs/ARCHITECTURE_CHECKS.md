# T0.2 architecture enforcement

Run `composer test:arch` for the AST boundary check followed by Deptrac. `composer check`
also runs these checks, PHPUnit regression fixtures, PHPStan level 6 and PHPCS PSR-12.
PHPStan and PHPCS cover the plugin bootstrap, src, bin, tests and development tools.

## Dependency graph

`deptrac.yaml` declares separate Api, Domain, Application, ApplicationPorts, Infrastructure,
Presentation and wiring layers for every module. ApplicationPorts are interfaces declared under
that module's Application namespace. Application cannot import infrastructure implementations.
Presentation cannot import Domain objects directly. Module registration files may compose their
own layers; they do not gain cross-module access to implementation details.

Cross-module dependencies target Api only, in exactly the directions in AGENTS.md. Settings and
Identity APIs are shared; Reporting is never a dependency of another module. Units can use only
Leasing's `Api/Query` contracts, not its general API. That namespace is reserved for read-only
contracts; implementation review must preserve this guarantee when Leasing is built.

Kernel interfaces and Money/Time/Id value types are available to pure layers. Concrete Kernel
adapters cannot enter Application or Domain. The Kernel folders specified in QUESTIONS.md are
treated as infrastructure adapters, matching the planned Connection/Router locations.

Uncovered dependencies fail the command. No skipped-violation baseline is installed.

## AST boundary check

`tools/Architecture/BoundaryInspector.php` parses production source without executing it.
It uses the installed WordPress stubs as its symbol inventory, including function aliases,
and rejects WordPress access outside infrastructure, bare/property/GLOBALS access to `$wpdb`,
native I/O and request globals in pure layers, and namespace/directory mismatches.

It also catches recognizable SQL in pure layers, literal references to another module's
tables, and database writes in Reporting. Reporting infrastructure may select across tables.
These checks do not establish account scoping, locking, transaction correctness, or the behavior
of dynamically constructed SQL/callables. Later integration tests and code review remain required.
The bootstrap is intentionally outside this AST pass: its WordPress glue is required by T0.1,
and it remains covered by PHPStan, PHPCS and bootstrap regression tests.

The AST parser was already installed transitively. T0.2 declares it as a direct development
dependency so architecture checks do not rely on another tool continuing to require it.

## Regression tests

`tests/Architecture/DeptracPolicyTest.php` runs the real config against generated dependencies:
all cross-module API directions, allowed Kernel interfaces/values, forbidden concrete adapters,
forbidden implementation imports, and the read-only Leasing query exception.
`BoundaryInspectorTest.php` exercises WordPress, filesystem, SQL, callback, alias and scope cases.
Fixtures live in isolated temporary directories under ignored tests/artifacts and are removed.

Acceptance check: put a class using `global $wpdb` in src/Modules/Units/Domain and run
`composer test:arch`: it must fail. Remove the sample and rerun the command: it must pass.
T0.2 does not introduce the WordPress PHPUnit/database harness from T0.3.
