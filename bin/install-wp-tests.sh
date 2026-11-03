#!/usr/bin/env bash
set -euo pipefail
exec php "$(dirname "$0")/install-wp-tests.php" "$@"
