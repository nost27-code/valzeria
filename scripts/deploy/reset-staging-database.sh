#!/usr/bin/env bash

set -Eeuo pipefail

# This script is intentionally staging-only. It is uploaded by the dedicated
# GitHub Actions workflow after an explicit confirmation has been entered.
: "${DEPLOY_ROOT:?DEPLOY_ROOT is required}"
DEPLOY_PHP_BINARY="${DEPLOY_PHP_BINARY:-php}"

DEPLOY_ROOT="$(cd "$DEPLOY_ROOT" && pwd -P)"
CURRENT_LINK="$DEPLOY_ROOT/staging_valzeria_current"

if [[ ! -f "$CURRENT_LINK/artisan" ]]; then
    echo "Staging release is not available: $CURRENT_LINK" >&2
    exit 66
fi

cleanup() {
    "$DEPLOY_PHP_BINARY" "$CURRENT_LINK/artisan" up --no-interaction >/dev/null 2>&1 || true
}
trap cleanup EXIT

"$DEPLOY_PHP_BINARY" "$CURRENT_LINK/artisan" down --render="errors::503" --retry=60 --no-interaction
"$DEPLOY_PHP_BINARY" "$CURRENT_LINK/artisan" db:wipe --force --no-interaction
"$DEPLOY_PHP_BINARY" "$CURRENT_LINK/artisan" migrate --force --no-interaction
"$DEPLOY_PHP_BINARY" "$CURRENT_LINK/artisan" db:seed --force --no-interaction
"$DEPLOY_PHP_BINARY" "$CURRENT_LINK/artisan" dungeon:validate --no-interaction
"$DEPLOY_PHP_BINARY" "$CURRENT_LINK/artisan" optimize:clear --no-interaction

echo "Staging database reset and seeded successfully."
