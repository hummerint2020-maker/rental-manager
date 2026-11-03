# T0.4 continuous integration

`.github/workflows/skeleton.yml` now runs the CI workflow on every pull request targeting main
and every push to main. There are no path filters that could miss shell scripts or frontend changes.
Runs for the same PR/ref replace obsolete runs. Permissions are read-only and checkout credentials
are not persisted. The automated Claude review loop and its scripts are unchanged.

## Backend matrix

| PHP | Database image |
| --- | --- |
| 8.1 | mysql:8.0 |
| 8.3 | mysql:8.0 |
| 8.1 | mariadb:10.6 |
| 8.3 | mariadb:10.6 |

Every combination gets an isolated service/database and runs independently (`fail-fast: false`).
MySQL uses mysqladmin for readiness; MariaDB uses its official healthcheck.sh connect/InnoDB checks.
The shared test password is only for these disposable CI databases, not a repository secret.

Each combination runs Composer installation and Strauss prefixing, strict Composer validation,
`composer check` (Unit/Application/Architecture, AST/Deptrac, PHPStan level 6, PHPCS and PHP lint),
then the preserved T0.3 installer and Integration/Api suites against real WordPress 6.4.

The runtime release assembly and clean WordPress activation smoke steps are retained in every
combination. The runtime package includes the original Action Scheduler and isolated libraries.
The smoke check still verifies activation, dependency isolation, Action Scheduler and Arabic gettext.

## Frontend pipeline before T0.5

The frontend job uses Node 24 and runs seven dependency-free tests for its prerequisite gate.
`bin/ci-frontend.mjs` permits only the current empty/.gitkeep placeholder to report the pre-scaffold
state. It writes a visible notice and job summary; application build/test have not run at this stage.
Once package.json exists, package-lock.json and non-empty build/test scripts are mandatory, then CI runs:

```sh
npm --prefix frontend ci
npm --prefix frontend run build
CI=true npm --prefix frontend test
```

Missing/invalid manifests, missing locks/scripts or source files without a manifest fail the job.
No continue-on-error or pass-with-no-tests flags are used. T0.5 creates the real scaffold; no frontend
application files or package dependencies are introduced by T0.4. This ordering decision is recorded
in QUESTIONS.md. The T0.5 test script must exit after running tests in CI mode.

Local checks for this workflow change:

```sh
node --test tests/CI/frontend-gate.test.mjs
node --check bin/ci-frontend.mjs
node bin/ci-frontend.mjs
composer validate --strict
composer check
```

The workflow is also checked with the standalone actionlint tool. The actual PHP/database matrix
is validated on GitHub Actions; native Windows does not provide its Linux service-container setup.
