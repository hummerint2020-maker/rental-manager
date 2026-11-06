# Continuous integration (T0.9)

`.github/workflows/ci.yml` runs the workflow named **CI** on every pull request targeting main
and every push to main. There are no path filters that could miss shell scripts or frontend changes.
Runs for the same PR/ref replace obsolete runs. Permissions are read-only and checkout credentials
are not persisted. The automated Claude review loop and its scripts are unchanged.

## Backend matrix

| PHP | Database image |
| --- | --- |
| 8.1 | mysql:8.0 |
| 8.3 | mysql:8.0 |
| 8.5 | mysql:8.0 |
| 8.1 | mariadb:10.6 |
| 8.3 | mariadb:10.6 |
| 8.5 | mariadb:10.6 |
| 8.3 | mysql:5.7.44 (production) |

Every combination gets an isolated service/database and runs independently (`fail-fast: false`).
MySQL uses mysqladmin for readiness; MariaDB uses its official healthcheck.sh connect/InnoDB checks.
The 5.7 cell is a matrix include, not another full PHP axis. It runs every backend step below,
and the harness asserts the server actually reports 5.7.44 using RM_EXPECT_DB_VERSION.
The shared test password is only for these disposable CI databases, not a repository secret.

## Runner and JavaScript action versions

Both CI jobs and the manual package job use **ubuntu-24.04**, so a future ubuntu-latest switch
does not change the host image silently. The actions are pinned to the verified current releases
below. Each tagged action.yml declares `runs.using: node24`; no action needs a Node 20 override.

| Action | Release | Runtime evidence |
| --- | --- | --- |
| Checkout | [v7.0.1](https://github.com/actions/checkout/releases/tag/v7.0.1) | [action.yml](https://github.com/actions/checkout/blob/v7.0.1/action.yml) |
| Setup Node | [v7.1.0](https://github.com/actions/setup-node/releases/tag/v7.1.0) | [action.yml](https://github.com/actions/setup-node/blob/v7.1.0/action.yml) |
| Upload Artifact | [v7.0.2](https://github.com/actions/upload-artifact/releases/tag/v7.0.2) | [action.yml](https://github.com/actions/upload-artifact/blob/v7.0.2/action.yml) |
| Setup PHP | [2.40.0](https://github.com/shivammathur/setup-php/releases/tag/2.40.0) | [action.yml](https://github.com/shivammathur/setup-php/blob/2.40.0/action.yml) |

All JavaScript actions used by ci.yml/package.yml are covered here. The independent protected
Claude review workflow and its scripts are unchanged by T0.9. Check the CI run's annotations
and job logs for absence of the Node.js 20 deprecation notice when validating a future update.

## Backend checks

Each combination runs Composer installation and Strauss prefixing, strict Composer validation,
`composer check` (Unit/Application/Architecture, AST/Deptrac, PHPStan level 6, PHPCS and PHP lint),
the synthetic prefixed mPDF spike, then the T0.3 installer and Integration/Api suites.
WordPress **7.1.4** is defined once as the workflow-level `WP_VERSION`. Both the test installer
and clean activation download consume that variable; the smoke runner verifies the installed
version matches it. PHP 8.1 is the minimum, 8.3 the production target, and 8.5 the newest host option.

The runtime release assembly and clean WordPress activation smoke steps are retained in every
combination. The runtime package includes the original Action Scheduler, isolated libraries and
the committed frontend build; assembly requires its manifest.
Both this workflow and `package.yml` invoke `bash bin/package.sh <new-output-directory>`.
The shared script makes an installable `rental-manager.zip` and enforces **< 8,000,000 bytes**
for both ZIP and extracted file contents. It rejects additional fonts, missing Plex weights or
license, mPDF bundled font/CJK resources, and a missing/or prefixed Action Scheduler.
The source vendor directories are never pruned. Only the staged package loses unused resources.
Every backend cell renders the CLI PDF using the slim package's isolated loader and font files.
The smoke check verifies activation, dependency isolation, Action Scheduler, Arabic gettext and
the real authenticated System Health PDF download from the assembled release.
Fixture and disposable health tables use `utf8mb4_unicode_520_ci`; integration tests assert the
actual fixture collations. MySQL 5.7 reports a translated upstream-EOL warning while passing
the supported-version check. The SQL compatibility guard is part of `composer test:arch` and
`composer check`; see ARCHITECTURE_CHECKS.md for its coverage and static-analysis limits.
Bootstrap tests additionally reject 6.x and pre-7.0 releases; the integration suite exercises
the activation hook's Arabic refusal using real WordPress translation and plugin APIs.
The PHPUnit 10 bridge remains necessary for the pinned upstream test library; see TESTING.md.

## PHP 8.5 compatibility

The first 8.5 run exposed the locked PHPStan 1.12 path flagging implicit CLI `$argc`/`$argv`
as potentially undefined. The installer and bootstrap probe now validate explicit CLI arguments
from `$_SERVER['argv']`; the installer refuses non-CLI execution. No ignore rule, global INI
override or analysis-level reduction was used. See the [upstream report](https://github.com/phpstan/phpstan/issues/14585)
and [PHP's argument documentation](https://www.php.net/manual/en/reserved.variables.argv.php).

Rendering deprecations are handled only inside the scoped PDF renderer; real warnings remain
errors. Its regression tests temporarily enable E_ALL to overcome PHPUnit's own native-output
mask and then restore the previous reporting level. Both PHPUnit configs retain failOnWarning=true.
The six-cell CI logs are the evidence for any remaining runtime/locked-tool deprecations; those
results are recorded in the PR, without claiming coverage from the local PHP 8.3 run alone.

## Frontend pipeline (T0.5)

The frontend job uses Node 24 and runs seven dependency-free tests for its prerequisite gate.
`bin/ci-frontend.mjs` permits only the current empty/.gitkeep placeholder to report the pre-scaffold
state. The T0.5 package.json now enables the pipeline. A package-lock.json and non-empty build/test
scripts are mandatory. CI runs:

```sh
npm --prefix frontend ci
npm --prefix frontend run build
npm --prefix frontend exec -- playwright install --with-deps chromium
CI=true npm --prefix frontend test
```

Missing/invalid manifests, missing locks/scripts or source files without a manifest fail the job.
No continue-on-error or pass-with-no-tests flags are used. Build checks TypeScript and emits the
production assets/manifest. CI rejects differences or untracked files in the committed build.
Seven Playwright tests cover the production preview (Arabic RTL, English LTR and routing),
manifest integrity and logical-direction utilities. The test command exits after the run. See
[FRONTEND.md](FRONTEND.md) for local setup. The original ordering decision remains in QUESTIONS.md.

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
