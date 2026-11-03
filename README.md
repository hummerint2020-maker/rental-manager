# Rental Manager

Multi-tenant SaaS for individual property owners to manage units, renters, contracts, charges,
payments, receipts, deposits, expenses and maintenance — delivered as a WordPress plugin hosting an
Arabic-first (RTL) single-page app for owners and renters.

## Status

T0.1 repository skeleton implemented. Business modules and the SPA are not implemented yet.

## Development

Requires PHP 8.1+ with GD and mbstring, Composer 2, and WordPress 6.4+ (single site).

```sh
composer install
composer check
```

Composer runs Strauss after installation to generate `vendor-prefixed/`. The plugin loads only
that isolated autoloader, including the project namespace; it does not load unprefixed vendor
or development libraries. Distribute the generated `vendor-prefixed/` with a complete release.
Use Linux (or WSL) for dependency prefixing. On native Windows, Strauss 0.31 has upstream path
errors; `composer install --no-scripts` still permits PHP checks but does not produce a deployable plugin.

The T0.1 GitHub smoke check builds on Linux, runs the PHP checks, and activates the plugin on
a disposable WordPress 6.4/MySQL 8 installation. The full architecture policies, WordPress PHPUnit
harness, and CI version matrix remain T0.2–T0.4. Empty suites are intentional at this stage.

`bin/smoke-wordpress.php` is for the fresh CI database only. Never run it on real hosting.
Compile Arabic gettext changes with `msgfmt languages/rental-manager-ar.po -o languages/rental-manager-ar.mo`.

## Docs

- [`AGENTS.md`](AGENTS.md) — engineering rules for humans and coding agents
- [`docs/DOMAIN.md`](docs/DOMAIN.md) — business rules, states, invariants, schema
- [`docs/PLAN.md`](docs/PLAN.md) — phased implementation plan (Phase 0 → 11)
- [`docs/CODEX_PROMPTS.md`](docs/CODEX_PROMPTS.md) — prompts for running each phase with Codex
- [`docs/QUESTIONS.md`](docs/QUESTIONS.md) — open questions and decisions
- `docs/wireframes/` — screen wireframes (PNG exports)
