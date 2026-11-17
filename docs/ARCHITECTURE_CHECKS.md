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

KernelShared covers Money, Time, Id, Tenancy and Errors, including AccountContext and the base
DomainRuleException/NotFound/Conflict types planned for Phase 1. All layers may use these shared
types. KernelShared remains free of WordPress and I/O. Domain may additionally use only the
explicitly allowed Clock contract (`Kernel/Clock/Clock`); it cannot depend on general Kernel
interfaces such as UnitOfWork, Router or Storage. Application retains access to all Kernel contracts.
Concrete Kernel adapters cannot enter Application or Domain. The Kernel folders specified in QUESTIONS.md are
treated as infrastructure adapters, matching the planned Connection/Router locations.

Api implementation code may use its own Domain for mapping, but public Api signatures expose only
Api DTOs and KernelShared types, never Domain entities. This signature rule requires code review;
Deptrac's class graph cannot distinguish a facade's internal dependencies from its public signature.

Uncovered dependencies fail the command. No skipped-violation baseline is installed.

## AST boundary check

`tools/Architecture/BoundaryInspector.php` parses production source without executing it.
It uses the installed WordPress stubs as its symbol inventory, including function aliases,
and rejects WordPress access outside infrastructure, bare/property/GLOBALS access to `$wpdb`,
native I/O and request globals in pure layers (including shared Tenancy/Errors), and namespace/directory mismatches.
WordPress callback-string detection is limited to known symbol names containing an underscore or
starting with `wp`; plain text such as `translate` is allowed. Actual WordPress function calls,
including `translate()`, are still rejected outside infrastructure.

It also catches recognizable uppercase SQL in pure layers, literal references to another module's
tables, and database writes in Reporting. Reporting infrastructure may select across tables.
The pure-layer SQL check is deliberately case-sensitive so English messages such as
`Select a unit from the list` pass; SQL keywords should be uppercase for this static check.
These checks do not establish account scoping, locking, transaction correctness, or the behavior
of dynamically constructed SQL/callables. Later integration tests and code review remain required.
The bootstrap is intentionally outside this AST pass: its WordPress glue is required by T0.1,
and it remains covered by PHPStan, PHPCS and bootstrap regression tests.

The AST parser was already installed transitively. T0.2 declares it as a direct development
dependency so architecture checks do not rely on another tool continuing to require it.

## Regression tests

T1.1 classifies Kernel/Container as infrastructure, including any future container interfaces.
Domain/Application imports, constructor types and class-name strings referring to that namespace
are rejected by the AST guard as service-locator dependencies; Deptrac rejects the same class and
interface edges. Module wiring may use the container. See CONTAINER.md for the minimal API,
lifetimes and module-definition lifecycle; no container accessor is exposed by Kernel.

`tests/Architecture/DeptracPolicyTest.php` runs the real config against generated dependencies:
all cross-module API directions, allowed Kernel interfaces/values, forbidden concrete adapters,
forbidden implementation imports, and the read-only Leasing query exception.
`BoundaryInspectorTest.php` exercises WordPress, filesystem, SQL, callback, alias and scope cases.
Fixtures live in isolated temporary directories under ignored tests/artifacts and are removed.

Acceptance check: put a class using `global $wpdb` in src/Modules/Units/Domain and run
`composer test:arch`: it must fail. Remove the sample and rerun the command: it must pass.
T0.2 does not introduce the WordPress PHPUnit/database harness from T0.3.

## MySQL 5.7 compatibility (T0.9)

`composer test:arch` also runs `bin/check-sql.php`. It scans PHP and .sql files in src/, migrations/,
bin/, tests/Support/, tests/Integration/ and tests/Api/. Architecture-test negative fixtures and
vendor/tool implementations are outside that source scan. Explicit directory arguments support
isolated CLI regression fixtures. PHP is parsed with the existing php-parser dependency; source
is never executed. Literal concatenations, interpolation and heredoc/nowdoc text are inspected,
including separately declared schema fragments. SQL string values and ordinary comments are
ignored; executable version comments are inspected. Backtick identifiers do not become keywords.

The gate rejects CTEs, window functions, SKIP LOCKED, NOWAIT, CHECK constraints, descending index
key parts, JSON_TABLE, INSERT row aliases, invisible indexes and utf8mb4_0900_* collations. Normal
ORDER BY DESC, ascending indexes, INSERT SELECT aliases, JSON/generated columns and the portable
utf8mb4_unicode_520_ci collation are permitted. Multiple SQL statements are checked independently.
The INSERT row-alias distinction follows the [MySQL INSERT documentation](https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html).

`SqlCompatibilityInspectorTest` has bad PHP SQL fixtures for every banned feature, valid SQL
fixtures, interpolation/concatenation cases, and a real CLI check that fails for CTE/window SQL
and passes after replacement. Diagnostics include source path/line and the feature name.

This is a conservative syntax guard, not a full SQL parser, data-flow analyzer or query validator.
It cannot resolve SQL assembled from runtime-only values or fragments split across variables or
methods. Do not put SQL structure in dynamic input to evade it. The seven real DB/PHP CI cells
and code review remain required for semantics, account scoping, locks, FKs and transactions.
Phase 1's migration DDL helper must use ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_520_ci; T0.9 only changes existing disposable/test DDL.
