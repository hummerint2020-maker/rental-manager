# QUESTIONS.md

Agents add open questions here. Ahmed answers inline and moves resolved items to "Resolved".

| Task | Question | Options | Recommended default | Answer |
| --- | --- | --- | --- | --- |
| T0.2 | Units may call Leasing only for read-only queries, but no query API naming contract exists yet. How should the static graph distinguish those contracts? | Dedicated Leasing/Api/Query namespace; method-name convention; explicit attributes | Reserve Leasing/Api/Query for read-only contracts and allow Units to depend only on that API layer; verify implementations when Leasing is built | Pending; reversible naming default applied |
| T0.2 | AGENTS.md restricts WordPress to Infrastructure, but the plan places adapters such as Connection and Router directly under Kernel/Db and Kernel/Http. Which Kernel paths may use WordPress? | Require Infrastructure subfolders everywhere; recognize the planned Kernel adapter folders | Treat Db, Migrations, Outbox, Jobs, Sequences, Storage, Audit, Health, Http, Security and I18n as Kernel infrastructure; keep module access restricted to Infrastructure and pure Kernel values isolated | Pending; reversible static-check default applied |
| T0.2 | Deptrac cannot reliably detect bare $wpdb, function callbacks or SQL table strings. Add a parser dependency for the supplementary AST guard? | Reuse the already locked nikic/php-parser as a direct dev dependency; rely only on class dependencies | Declare the existing parser explicitly; no new runtime dependency or package version is introduced | Pending; reversible tooling default applied |
| T0.1 | Strauss 0.31 has Windows drive-path errors. Where should dependency prefixing run? | Linux/WSL; maintain Windows compatibility patches | Build isolated dependencies on Linux. Windows may use `composer install --no-scripts` for PHP checks; do not ship until the Linux prefixing and activation smoke checks pass | Pending; reversible default applied |

## Resolved

T0.1 review follow-up: the root requirement now uses sodium instead of GD. mPDF itself still
requires GD transitively. Keep its declared platform requirement intact; evaluate that constraint
alongside the GD/Imagick hosting checks and PDF spike in T0.6 before promising Imagick-only hosting.
