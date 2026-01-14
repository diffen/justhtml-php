#!/usr/bin/env bash
set -euo pipefail

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required. Install Docker Desktop and try again." >&2
  exit 1
fi

versions=("$@")
if [ "${#versions[@]}" -eq 0 ]; then
  versions=(7.4 8.0 8.1 8.2 8.3)
fi

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

for v in "${versions[@]}"; do
  echo "== PHP ${v} =="
  docker run --rm -v "${root_dir}":/work -w /work "php:${v}-cli" php -v
  docker run --rm -v "${root_dir}":/work -w /work "php:${v}-cli" php run_tests.php
  echo ""
done
