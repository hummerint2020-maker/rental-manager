# QUESTIONS.md

Agents add open questions here. Ahmed answers inline and moves resolved items to "Resolved".

| Task | Question | Options | Recommended default | Answer |
| --- | --- | --- | --- | --- |
| T0.1 | Strauss 0.31 has Windows drive-path errors. Where should dependency prefixing run? | Linux/WSL; maintain Windows compatibility patches | Build isolated dependencies on Linux. Windows may use `composer install --no-scripts` for PHP checks; do not ship until the Linux prefixing and activation smoke checks pass | Pending; reversible default applied |

## Resolved

T0.1 review follow-up: the root requirement now uses sodium instead of GD. mPDF itself still
requires GD transitively. Keep its declared platform requirement intact; evaluate that constraint
alongside the GD/Imagick hosting checks and PDF spike in T0.6 before promising Imagick-only hosting.
