# T0.6 hosting spike and checklist

This task adds diagnostics, not production storage, financial receipts or cron configuration.
The WordPress/PHP policy update in T0.7 is separate: bootstrap guards and the CI matrix are unchanged.

## System Health

Open **Rental Manager → System Health** as a WordPress System Admin (`manage_options`).
Owners, managers and renters have the `rm_client` WordPress role with `read` only and cannot
access this screen. The callback checks capability again. GET only displays a form.
**Run checks** requires a nonce-protected POST because diagnostics write disposable canaries.
No public REST endpoint is registered.

The twelve results check PHP >= 8.1, sodium, mbstring, GD **or** Imagick, PHP memory >= 256 MB
(unlimited `-1` also passes), `DISABLE_WP_CRON === true`, MySQL >= 8.0 / MariaDB >= 10.6,
default InnoDB, real foreign-key rejection, actual rollback, private-file HTTP protection and
an executed Action Scheduler canary. MariaDB's `5.5.5-` compatibility prefix is handled.
Controls/results have English/Arabic translations; SQL errors, credentials and absolute paths
are not displayed.

The DB probe uses an independent connection with the existing WordPress DB configuration.
It creates random `*_rm_health_*` scratch tables (InnoDB/utf8mb4), requires an invalid FK insert
to fail with error 1452, inserts/reads/rolls back a row, and drops its tables in finally cleanup.
It does not change the tenant schema or commit the caller's transaction. CREATE/DROP permissions
are required; cleanup failure makes the probe fail. A forcibly terminated PHP process may need
an administrator to inspect leftover scratch tables; never delete business tables.

The private-file spike exercises the planned protected-uploads fallback until T3.4 supplies the
real PrivateStorage adapter. A random diagnostic directory below the actual uploads base has
a harmless public control marker and a child protected by `.htaccess` and `index.php`.
The public control must return HTTP 200 with exactly its marker. The private marker must return
403/404 without marker leakage. HTTP requests have a five-second timeout, no redirects/cookies,
and normal TLS verification. Network errors, blocked public controls, redirects/login responses,
exposed markers and cleanup failures do not pass. Only known probe files/directories are removed.
There is no renter data. Nginx does not enforce `.htaccess`: an exposed marker is a hosting failure.
T3.4 must switch this probe to actual configured storage, including `RM_STORAGE_PATH`.

The scheduler probe queues only `rm_health_canary` in `rm-health`, with a random token and no
tenant/business payload. **Pending** is not success. Run checks again after the normal runner
can execute it (within two minutes). Only its callback acknowledgement passes. After two minutes
it fails and cancels only that pending canary; transients expire in five minutes for a fresh attempt.
The page never forces the shared queue to run or changes cron settings. This proves queue execution,
not external cron configuration: the async runner can also execute the canary. This control-plane
diagnostic is separate from tenant jobs/context; the reversible decision is in QUESTIONS.md.

## PDF spike

From a full repository/development checkout with dependencies installed:

```sh
php bin/spike-pdf.php
```

This CLI-only script creates `tests/artifacts/receipt-sample.pdf` and a font-cache workspace in
the ignored artifact directory. It does not boot WordPress, connect to the DB or use real data.
The synthetic sample includes Arabic RTL, bundled mPDF fonts, mixed-direction reference/date,
Arabic-Indic amounts and the not-a-tax-invoice disclaimer. A runtime release may exclude `bin/`;
use a full checkout to run the spike. There are no remote image/font resources.

Linux CI generates the PDF with the Strauss-prefixed loader in each existing backend cell.
Native Windows development may use the unprefixed Composer loader and prints that limitation;
the plugin bootstrap never uses that fallback. This is not the future production receipt renderer.

The health image check permits GD or Imagick. The locked mPDF library still requires GD:
an Imagick-only health pass does not promise PDF dependency installation without GD.
No Composer platform requirement is ignored or removed.

## [AHMED] real-hosting steps — not performed by the coding agent

1. Run System Health on the real hosting as System Admin. Record every result and rerun to
   observe the scheduler acknowledgement. Verify the host's external cron really invokes the
   WordPress/Action Scheduler runner when WP-Cron is disabled; the agent does not configure it.
2. Run `php bin/spike-pdf.php` on the real hosting. Open the PDF and confirm connected Arabic
   shaping, RTL layout, names, mixed Latin/date text, digits, table alignment and disclaimer.
   Record the hosting PDF/extension outcome separately from local/CI results.
3. Report **Apache vs Nginx**, including any proxy, from the hosting configuration. Record private
   canary exposure or loopback failures and the required host action.

Record results here or on issue #1. These human checks are pending; no real-hosting compatibility,
private-file security or production Arabic-rendering claim is made before them.

## Automated validation

`composer check` covers threshold/version cases, storage status/marker/transport/cleanup cases,
PDF sample HTML, architecture, PHPStan, PHPCS and lint. Integration tests cover real DB cleanup
and transaction isolation, permission/nonce checks, scheduler execution and timeout. PHPStan has
a 1 GB analysis-only budget for the mPDF API; runtime settings and the hosting threshold stay unchanged.
Existing frontend gates, build freshness, browser tests and release/activation smoke are preserved.

References: [Action Scheduler](https://actionscheduler.org/) and
[mPDF font selection](https://mpdf.github.io/fonts-languages/automatic-font-selection.html).
