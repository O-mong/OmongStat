#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

if [[ "${1:-}" != '--no-build' ]]; then
    npm --prefix "$ROOT" run build
fi

node "$ROOT/scripts/package.mjs"
