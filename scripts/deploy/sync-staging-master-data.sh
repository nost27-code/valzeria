#!/usr/bin/env bash

set -Eeuo pipefail

# Keeps staging gameplay masters aligned with production without copying
# accounts, characters, inventories, logs, payments, or other player data.
: "${DEPLOY_ROOT:?DEPLOY_ROOT is required}"
: "${DEPLOY_PHP_BINARY:?DEPLOY_PHP_BINARY is required}"

MODE="${1:-}"
SYNC_DIR="${2:-}"

if [[ "$MODE" != "prepare" && "$MODE" != "apply" ]] || [[ -z "$SYNC_DIR" ]]; then
    echo "Usage: $0 prepare|apply <sync-directory>" >&2
    exit 64
fi

DEPLOY_ROOT="$(cd "$DEPLOY_ROOT" && pwd -P)"
STAGING_APP="$DEPLOY_ROOT/staging_valzeria_current"
STAGING_ENV="$DEPLOY_ROOT/staging_valzeria_shared/.env"
PRODUCTION_APP="$DEPLOY_ROOT/valzeria_project"
PRODUCTION_ENV="$PRODUCTION_APP/.env"
MASTER_DUMP="$SYNC_DIR/production-master.sql"
STAGING_BACKUP_DIR="$DEPLOY_ROOT/staging_valzeria_shared/backups"

# This list intentionally contains only game masters and their relationships.
# Player-owned, operational, payment, analytics, and log tables are excluded.
MASTER_TABLES=(
    area_discovery_links
    areas
    armor_categories
    armor_category_masters
    armor_city_material_pools
    armor_enhancement_recipes
    armor_evolution_recipe_ingredients
    armor_evolution_recipes
    armor_families
    armor_ranks
    cities
    city_material_pools
    enemies
    enemy_actions
    enemy_drops
    enemy_stat_snapshots
    equipment_affix_prefixes
    equipment_affix_suffixes
    game_settings
    game_texts
    items
    job_armor_permissions
    job_classes
    job_exp_tables
    job_master_bonuses
    job_requirements
    job_skills
    job_weapon_permissions
    jobs
    material_drops
    materials
    monster_marks
    nameless_equipment_material_tiers
    npc_master
    npc_procurement_request_template_materials
    npc_procurement_request_templates
    recipes
    skills
    sub_area_routes
    sub_areas
    titles
    top_updates
    tower_floor_master
    valmon_masters
    valmon_spawn_regions
    weapon_categories
    weapon_enhancement_recipes
    weapon_evolution_recipe_ingredients
    weapon_evolution_recipes
    weapon_families
    weapon_ranks
)

for command in mysql mysqldump gzip; do
    command -v "$command" >/dev/null 2>&1 || {
        echo "Required command is unavailable: $command" >&2
        exit 69
    }
done

for file in "$STAGING_APP/artisan" "$STAGING_ENV" "$PRODUCTION_APP/artisan" "$PRODUCTION_ENV"; do
    [[ -f "$file" ]] || {
        echo "Required staging/production file is missing: $file" >&2
        exit 66
    }
done

write_mysql_defaults() {
    local app_dir="$1"
    local env_file="$2"
    local output_file="$3"

    "$DEPLOY_PHP_BINARY" -r '
        require $argv[1] . "/vendor/autoload.php";
        $dotenv = Dotenv\Dotenv::createImmutable(dirname($argv[2]));
        $dotenv->load();
        $keys = ["DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"];
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $_ENV[$key] ?? "";
        }
        if ($values["DB_DATABASE"] === "" || $values["DB_USERNAME"] === "") {
            fwrite(STDERR, "Database settings are incomplete.\n");
            exit(1);
        }
        $lines = ["[client]", "host=" . $values["DB_HOST"], "port=" . ($values["DB_PORT"] ?: "3306"), "database=" . $values["DB_DATABASE"], "user=" . $values["DB_USERNAME"], "password=" . $values["DB_PASSWORD"]];
        file_put_contents($argv[3], implode(PHP_EOL, $lines) . PHP_EOL);
    ' "$app_dir" "$env_file" "$output_file"
    chmod 600 "$output_file"
}

assert_tables_exist() {
    local defaults_file="$1"
    local database_name="$2"
    local table

    for table in "${MASTER_TABLES[@]}"; do
        if [[ "$(mysql --defaults-extra-file="$defaults_file" --batch --skip-column-names "$database_name" -e "SHOW TABLES LIKE '$table';")" != "$table" ]]; then
            echo "Required master table is missing: $table" >&2
            exit 66
        fi
    done
}

master_count() {
    local defaults_file="$1"
    local database_name="$2"
    local table="$3"

    mysql --defaults-extra-file="$defaults_file" --batch --skip-column-names "$database_name" -e "SELECT COUNT(*) FROM \`$table\`;"
}

mkdir -p "$SYNC_DIR"
PRODUCTION_DEFAULTS="$SYNC_DIR/production.cnf"
STAGING_DEFAULTS="$SYNC_DIR/staging.cnf"
write_mysql_defaults "$PRODUCTION_APP" "$PRODUCTION_ENV" "$PRODUCTION_DEFAULTS"
write_mysql_defaults "$STAGING_APP" "$STAGING_ENV" "$STAGING_DEFAULTS"

cleanup() {
    rm -f "$PRODUCTION_DEFAULTS" "$STAGING_DEFAULTS"
}
trap cleanup EXIT

PRODUCTION_DATABASE="$(mysql --defaults-extra-file="$PRODUCTION_DEFAULTS" --batch --skip-column-names -e 'SELECT DATABASE();')"
STAGING_DATABASE="$(mysql --defaults-extra-file="$STAGING_DEFAULTS" --batch --skip-column-names -e 'SELECT DATABASE();')"

assert_tables_exist "$PRODUCTION_DEFAULTS" "$PRODUCTION_DATABASE"
assert_tables_exist "$STAGING_DEFAULTS" "$STAGING_DATABASE"

if [[ "$MODE" == "prepare" ]]; then
    timestamp="$(date -u +%Y%m%d_%H%M%S)"
    mkdir -p "$STAGING_BACKUP_DIR"
    staging_backup="$STAGING_BACKUP_DIR/master-before-sync-$timestamp.sql.gz"

    mysqldump --defaults-extra-file="$STAGING_DEFAULTS" --single-transaction --skip-lock-tables --no-create-info --skip-triggers --complete-insert "$STAGING_DATABASE" "${MASTER_TABLES[@]}" | gzip -c > "$staging_backup"
    mysqldump --defaults-extra-file="$PRODUCTION_DEFAULTS" --single-transaction --skip-lock-tables --no-create-info --skip-triggers --complete-insert "$PRODUCTION_DATABASE" "${MASTER_TABLES[@]}" > "$MASTER_DUMP"
    chmod 600 "$staging_backup" "$MASTER_DUMP"

    echo "Staging master backup created: $staging_backup"
    echo "Production master snapshot prepared."
    exit 0
fi

[[ -s "$MASTER_DUMP" ]] || {
    echo "Production master snapshot is missing: $MASTER_DUMP" >&2
    exit 66
}

{
    echo 'SET FOREIGN_KEY_CHECKS=0;'
    for table in "${MASTER_TABLES[@]}"; do
        printf 'DELETE FROM `%s`;\n' "$table"
    done
    echo 'SET FOREIGN_KEY_CHECKS=1;'
} | mysql --defaults-extra-file="$STAGING_DEFAULTS" "$STAGING_DATABASE"

mysql --defaults-extra-file="$STAGING_DEFAULTS" "$STAGING_DATABASE" < "$MASTER_DUMP"

for table in "${MASTER_TABLES[@]}"; do
    production_count="$(master_count "$PRODUCTION_DEFAULTS" "$PRODUCTION_DATABASE" "$table")"
    staging_count="$(master_count "$STAGING_DEFAULTS" "$STAGING_DATABASE" "$table")"
    if [[ "$production_count" != "$staging_count" ]]; then
        echo "Master count mismatch after sync: $table (production=$production_count, staging=$staging_count)" >&2
        exit 70
    fi
done

echo "Staging master data synchronized from production."
