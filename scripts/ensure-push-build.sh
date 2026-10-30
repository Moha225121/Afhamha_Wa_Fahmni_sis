#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."

command -v node >/dev/null 2>&1 || { echo 'Node.js is required to verify notification assets; deployment stopped.' >&2; exit 1; }
if [ "${1:-}" != '--rebuild' ] && node scripts/verify-push-build.mjs; then
  exit 0
fi
command -v npm >/dev/null 2>&1 || { echo 'Notification assets need rebuilding, but npm is unavailable; deployment stopped.' >&2; exit 1; }
npm ci --ignore-scripts
npm run build
node scripts/verify-push-build.mjs
