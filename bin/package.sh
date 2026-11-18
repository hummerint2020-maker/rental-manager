#!/usr/bin/env bash
# Shared by the downloadable package workflow and every CI release/activation cell.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out="${1:-$root/dist/package}"
if [[ -e "$out" ]]; then
  echo 'Package output must be a new directory; refusing to overwrite it.' >&2
  exit 1
fi
mkdir -p "$out"
out="$(cd "$out" && pwd)"
runtime="$out/rental-manager"
mkdir -p "$runtime/vendor/woocommerce"
cd "$root"
test -f vendor-prefixed/autoload.php
test -f build/manifest.json
cp rental-manager.php "$runtime/"
cp -R src languages assets build migrations "$runtime/"
# Exclude resources from the staged copy only. Composer's installed libraries stay intact.
tar -cf - \
  --exclude='*/ttfonts' --exclude='*/data/font' --exclude='*/data/collations' \
  --exclude='*/data/CJKdata.php' --exclude='*/data/linebrdict*.dat' \
  --exclude='*/data/lang2fonts.css' --exclude='*/tmp' \
  vendor-prefixed | tar -xf - -C "$runtime"
cp -R vendor/woocommerce/action-scheduler "$runtime/vendor/woocommerce/"
find "$runtime" -name '.gitkeep' -delete
printf 'Commit: %s\n' "$(git rev-parse HEAD)" > "$runtime/BUILD.txt"
(cd "$out" && zip -q -r rental-manager.zip rental-manager)
php "$root/bin/check-package.php" "$out"
