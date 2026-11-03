# Rental Manager

Multi-tenant SaaS for individual property owners to manage units, renters, contracts, charges,
payments, receipts, deposits, expenses and maintenance — delivered as a WordPress plugin hosting an
Arabic-first (RTL) single-page app for owners and renters.

## Status

T0.1 repository skeleton, T0.2 architecture checks, T0.3 test harness, T0.4 CI and T0.5 frontend
scaffold and T0.6 hosting diagnostics/PDF spike are implemented. The SPA has an Arabic RTL test page; business modules and WordPress
app routing are not implemented yet.

## Development

Requires PHP 8.1+ with sodium and mbstring, Composer 2, and WordPress 7.0+ (single site).
The current mPDF dependency also requires GD; the GD/Imagick hosting decision is tracked for T0.6.

```sh
composer install
composer check
```

Composer runs Strauss after installation to generate `vendor-prefixed/`. The plugin loads that
isolated autoloader, including the project namespace, and directly loads Action Scheduler's
original loader so its shared version registry works alongside other plugins. It does not load
Composer's unprefixed autoloader or development libraries. Complete releases must include both
`vendor-prefixed/` and the entire `vendor/woocommerce/action-scheduler/` package (including its license).
Use Linux (or WSL) for dependency prefixing. On native Windows, Strauss 0.31 has upstream path
errors; `composer install --no-scripts` still permits PHP checks but does not produce a deployable plugin.

The CI workflow builds on Linux, runs the PHP checks, assembles a runtime-only package
with the original Action Scheduler, and activates that package on disposable WordPress 7.1.4 installations.
`.github/workflows/ci.yml` validates PHP 8.1/8.4/8.5 against MySQL 8.0 and MariaDB 10.6 (six cells).
The WordPress release is pinned once in that workflow's `WP_VERSION`. WordPress 6.x activation is refused
with the bundled localized requirement message. System Health checks the WordPress 7.0 minimum too.
Frontend CI now installs locked dependencies, builds assets and runs Chromium tests. See
[`docs/CI.md`](docs/CI.md) for CI behavior, [`docs/TESTING.md`](docs/TESTING.md) for installation and suites, and
[`docs/ARCHITECTURE_CHECKS.md`](docs/ARCHITECTURE_CHECKS.md) for the enforced boundaries and regression fixtures.

`bin/smoke-wordpress.php` is for the fresh CI database only. Never run it on real hosting.
Compile Arabic gettext changes with `msgfmt languages/rental-manager-ar.po -o languages/rental-manager-ar.mo`.

For the Node 24 frontend setup, production build and browser tests, see
[`docs/FRONTEND.md`](docs/FRONTEND.md). Built assets and the Vite manifest are committed in `build/`.

## Docs

- [`AGENTS.md`](AGENTS.md) — engineering rules for humans and coding agents
- [`docs/DOMAIN.md`](docs/DOMAIN.md) — business rules, states, invariants, schema
- [`docs/PLAN.md`](docs/PLAN.md) — phased implementation plan (Phase 0 → 11)
- [`docs/CODEX_PROMPTS.md`](docs/CODEX_PROMPTS.md) — prompts for running each phase with Codex
- [`docs/QUESTIONS.md`](docs/QUESTIONS.md) — open questions and decisions
- [`docs/HOSTING.md`](docs/HOSTING.md) — System Health, PDF spike and pending [AHMED] hosting checklist
- `docs/wireframes/` — screen wireframes (PNG exports)
