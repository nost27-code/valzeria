#!/usr/bin/env bash

set -Eeuo pipefail

# Invoked over SSH by GitHub Actions. The archive is already built on the
# runner, so the shared host never needs Composer, Node.js, or an HTTP upload.
: "${DEPLOY_ROOT:?DEPLOY_ROOT is required}"
: "${DEPLOY_TARGET:?DEPLOY_TARGET is required}"
: "${DEPLOY_ARCHIVE:?DEPLOY_ARCHIVE is required}"

DEPLOY_MIGRATION_MODE="${DEPLOY_MIGRATION_MODE:-none}"
DEPLOY_PHP_BINARY="${DEPLOY_PHP_BINARY:-php}"

case "$DEPLOY_TARGET" in
    staging)
        DEPLOY_PREFIX="staging_valzeria"
        PUBLIC_DIR="$DEPLOY_ROOT/public_html/staging.valzeria.com"
        ;;
    production)
        DEPLOY_PREFIX="valzeria"
        PUBLIC_DIR="$DEPLOY_ROOT/public_html"
        ;;
    *)
        echo "DEPLOY_TARGET must be staging or production." >&2
        exit 64
        ;;
esac

case "$DEPLOY_MIGRATION_MODE" in
    none|backward_compatible|maintenance_required) ;;
    *)
        echo "Invalid DEPLOY_MIGRATION_MODE." >&2
        exit 64
        ;;
esac

DEPLOY_ROOT="$(cd "$DEPLOY_ROOT" && pwd -P)"
INCOMING_DIR="$DEPLOY_ROOT/deploy-incoming"
RELEASES_DIR="$DEPLOY_ROOT/${DEPLOY_PREFIX}_releases"
SHARED_DIR="$DEPLOY_ROOT/${DEPLOY_PREFIX}_shared"
CURRENT_LINK="$DEPLOY_ROOT/${DEPLOY_PREFIX}_current"
SHARED_STORAGE="$SHARED_DIR/storage"
SHARED_ENV="$SHARED_DIR/.env"

case "$DEPLOY_ARCHIVE" in
    "$INCOMING_DIR"/*.tar.gz) ;;
    *)
        echo "DEPLOY_ARCHIVE must be inside $INCOMING_DIR." >&2
        exit 64
        ;;
esac

if [[ ! -f "$DEPLOY_ARCHIVE" || ! -r "$DEPLOY_ARCHIVE" ]]; then
    echo "Release archive is not readable." >&2
    exit 66
fi
if [[ ! -f "$SHARED_ENV" ]]; then
    echo "Shared .env is missing: $SHARED_ENV" >&2
    exit 66
fi

mkdir -p "$RELEASES_DIR" "$SHARED_STORAGE/app/public" "$SHARED_STORAGE/framework/cache/data" \
    "$SHARED_STORAGE/framework/sessions" "$SHARED_STORAGE/framework/views" "$SHARED_STORAGE/logs"

release_id="$(date -u +%Y%m%d_%H%M%S)_$RANDOM$RANDOM"
release_dir="$RELEASES_DIR/$release_id"
previous_release=""
maintenance_enabled=0
switched=0

cleanup() {
    status=$?
    if [[ "$maintenance_enabled" == "1" && -f "$CURRENT_LINK/artisan" ]]; then
        "$DEPLOY_PHP_BINARY" "$CURRENT_LINK/artisan" up --no-interaction >/dev/null 2>&1 || true
    fi
    if [[ "$status" != "0" && "$switched" == "0" ]]; then
        rm -rf "$release_dir" || true
    fi
    rm -f "$DEPLOY_ARCHIVE" || true
    exit "$status"
}
trap cleanup EXIT

mkdir -p "$release_dir"
tar -xzf "$DEPLOY_ARCHIVE" -C "$release_dir"
mkdir -p "$release_dir/bootstrap/cache/views"
rm -f "$release_dir/public/hot"
rm -rf "$release_dir/storage" "$release_dir/.env"
ln -s "$SHARED_STORAGE" "$release_dir/storage"
ln -s "$SHARED_ENV" "$release_dir/.env"

for required in artisan vendor/autoload.php bootstrap/app.php public/index.php public/.htaccess; do
    if [[ ! -f "$release_dir/$required" ]]; then
        echo "Required release file is missing: $required" >&2
        exit 65
    fi
done

"$DEPLOY_PHP_BINARY" "$release_dir/artisan" config:clear --no-interaction
"$DEPLOY_PHP_BINARY" "$release_dir/artisan" view:clear --no-interaction

if [[ "$DEPLOY_MIGRATION_MODE" != "none" ]]; then
    preflight_args=()
    if [[ "$DEPLOY_MIGRATION_MODE" == "maintenance_required" ]]; then
        preflight_args+=(--allow-enemy-merge)
    fi
    if [[ "${#preflight_args[@]}" -gt 0 ]]; then
        "$DEPLOY_PHP_BINARY" "$release_dir/artisan" valzeria:preflight-pending-migrations "${preflight_args[@]}" --no-interaction
    else
        "$DEPLOY_PHP_BINARY" "$release_dir/artisan" valzeria:preflight-pending-migrations --no-interaction
    fi

    if [[ "$DEPLOY_MIGRATION_MODE" == "maintenance_required" && -L "$CURRENT_LINK" ]]; then
        "$DEPLOY_PHP_BINARY" "$CURRENT_LINK/artisan" down --retry=60 --no-interaction
        maintenance_enabled=1
    fi

    "$DEPLOY_PHP_BINARY" "$release_dir/artisan" migrate --force --no-interaction
fi

"$DEPLOY_PHP_BINARY" "$release_dir/artisan" valzeria:validate-master-data --no-interaction
"$DEPLOY_PHP_BINARY" "$release_dir/artisan" valzeria:validate-release-readiness --all --no-interaction

"$DEPLOY_PHP_BINARY" "$release_dir/artisan" cache:clear --no-interaction
"$DEPLOY_PHP_BINARY" "$release_dir/artisan" config:cache --no-interaction
"$DEPLOY_PHP_BINARY" "$release_dir/artisan" event:cache --no-interaction
"$DEPLOY_PHP_BINARY" "$release_dir/artisan" view:cache --no-interaction

if [[ -L "$CURRENT_LINK" ]]; then
    previous_release="$(readlink -f "$CURRENT_LINK")"
fi
if [[ -e "$CURRENT_LINK.next" || -L "$CURRENT_LINK.next" ]]; then
    echo "Stale temporary current link exists: $CURRENT_LINK.next" >&2
    exit 73
fi
ln -s "$release_dir" "$CURRENT_LINK.next"
mv -Tf "$CURRENT_LINK.next" "$CURRENT_LINK"
switched=1

escaped_current_link=${CURRENT_LINK//\'/\\\'}
cat > "$PUBLIC_DIR/index.php.next" <<PHP
<?php
declare(strict_types=1);

\$releaseRoot = realpath('${escaped_current_link}');
if (\$releaseRoot === false || !is_file(\$releaseRoot . '/public/index.php')) {
    http_response_code(503);
    exit('Service temporarily unavailable.');
}

require \$releaseRoot . '/public/index.php';
PHP
mv -f "$PUBLIC_DIR/index.php.next" "$PUBLIC_DIR/index.php"
cp "$release_dir/public/.htaccess" "$PUBLIC_DIR/.htaccess"

link_public_directory() {
    local directory="$1"
    local source="$CURRENT_LINK/public/$directory"
    local destination="$PUBLIC_DIR/$directory"

    [[ -d "$source" ]] || return 0
    if [[ -e "$destination" && ! -L "$destination" ]]; then
        echo "Refusing to replace non-link public directory: $destination" >&2
        return 74
    fi

    rm -f "$destination.next"
    ln -s "$source" "$destination.next"
    mv -Tf "$destination.next" "$destination"
}

for directory in build images tools contact_images; do
    link_public_directory "$directory"
done

for file in favicon.ico robots.txt sw.js; do
    if [[ -f "$release_dir/public/$file" ]]; then
        if [[ -e "$PUBLIC_DIR/$file" && "$release_dir/public/$file" -ef "$PUBLIC_DIR/$file" ]]; then
            continue
        fi
        cp "$release_dir/public/$file" "$PUBLIC_DIR/$file"
    fi
done

if [[ ! -e "$PUBLIC_DIR/storage" ]]; then
    ln -s "$SHARED_STORAGE/app/public" "$PUBLIC_DIR/storage"
elif [[ -L "$PUBLIC_DIR/storage" ]]; then
    ln -sfn "$SHARED_STORAGE/app/public" "$PUBLIC_DIR/storage"
fi

if ! "$DEPLOY_PHP_BINARY" "$CURRENT_LINK/artisan" migrate:status --no-interaction >/dev/null; then
    if [[ -n "$previous_release" && -d "$previous_release" ]]; then
        ln -s "$previous_release" "$CURRENT_LINK.next"
        mv -Tf "$CURRENT_LINK.next" "$CURRENT_LINK"
        switched=0
        echo "Release health check failed; restored the previous release." >&2
    fi
    exit 70
fi

if [[ "$maintenance_enabled" == "1" ]]; then
    "$DEPLOY_PHP_BINARY" "$CURRENT_LINK/artisan" up --no-interaction
    maintenance_enabled=0
fi

echo "Release deployed: $DEPLOY_TARGET/$release_id"
