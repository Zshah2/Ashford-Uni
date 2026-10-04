#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_FILES=()
while IFS= read -r -d '' file; do
  PHP_FILES+=("$file")
done < <(find app config public scripts -type f -name '*.php' -print0 2>/dev/null)

if [ "${#PHP_FILES[@]}" -eq 0 ]; then
  echo "No PHP files found to lint."
  exit 0
fi

for file in "${PHP_FILES[@]}"; do
  echo "Checking: $file"
  php -l "$file" >/dev/null
  echo "OK: $file"
done

echo "PHP syntax validation passed for ${#PHP_FILES[@]} files."
