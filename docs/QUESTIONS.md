# QUESTIONS.md

Agents add open questions here. Ahmed answers inline and moves resolved items to "Resolved".

| Task | Question | Options | Recommended default | Answer |
| --- | --- | --- | --- | --- |
| T0.1 | Strauss 0.31 has Windows drive-path errors. Where should dependency prefixing run? | Linux/WSL; maintain Windows compatibility patches | Build isolated dependencies on Linux. Windows may use `composer install --no-scripts` for PHP checks; do not ship until the Linux prefixing and activation smoke checks pass | Pending; reversible default applied |

## Resolved
