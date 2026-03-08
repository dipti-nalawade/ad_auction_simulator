#!/usr/bin/env bash
# =============================================================================
# Ad Auction Simulator — one-shot setup
# Creates the database, applies the schema, and loads seed data.
#
# Configuration (all optional; falls back to the defaults below):
#   DB_HOST   MySQL host          (default: localhost)
#   DB_USER   MySQL user          (default: root)
#   DB_PASS   MySQL password      (default: empty — socket / passwordless auth)
#   DB_NAME   Database name       (default: ad_auction_simulator)
#
# Usage:
#   chmod +x setup.sh && ./setup.sh
#   DB_USER=myuser DB_PASS=secret ./setup.sh
# =============================================================================

set -euo pipefail

# ── Resolve paths relative to this script ─────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCHEMA="$SCRIPT_DIR/sql/schema.sql"
SEED="$SCRIPT_DIR/sql/seed.sql"

# ── Credentials ───────────────────────────────────────────────────────────────
DB_HOST="${DB_HOST:-localhost}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-ad_auction_simulator}"

# ── Build mysql argument array ────────────────────────────────────────────────
# The password flag is only added when DB_PASS is non-empty to avoid prompting
# for a password on installations that use socket / passwordless auth.
MYSQL_ARGS=(-h "$DB_HOST" -u "$DB_USER")
[[ -n "$DB_PASS" ]] && MYSQL_ARGS+=("--password=$DB_PASS")

# ── Helpers ───────────────────────────────────────────────────────────────────
step() { printf '\n\033[1;32m==>\033[0m \033[1m%s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# ── Pre-flight checks ─────────────────────────────────────────────────────────
command -v mysql  >/dev/null 2>&1 || die "'mysql' not found. Install MySQL/MariaDB client."
command -v php    >/dev/null 2>&1 || die "'php'   not found. Install PHP 8.1+."
[[ -f "$SCHEMA" ]] || die "Schema file not found: $SCHEMA"
[[ -f "$SEED"   ]] || die "Seed file not found:   $SEED"

# ── Verify connectivity before doing anything ─────────────────────────────────
step "Checking MySQL connectivity..."
mysql "${MYSQL_ARGS[@]}" -e "SELECT 1;" >/dev/null 2>&1 \
    || die "Cannot connect to MySQL as '$DB_USER'@'$DB_HOST'. Check credentials."
echo "    Connected to $DB_HOST as $DB_USER"

# ── Step 1: Create database (idempotent — schema.sql uses IF NOT EXISTS) ──────
step "Creating database '$DB_NAME' (if not exists)..."
mysql "${MYSQL_ARGS[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
    && echo "    OK"

# ── Step 2: Apply schema ───────────────────────────────────────────────────────
step "Applying schema..."
mysql "${MYSQL_ARGS[@]}" "$DB_NAME" < "$SCHEMA" \
    && echo "    OK — tables created"

# ── Step 3: Load seed data ────────────────────────────────────────────────────
step "Loading seed data..."
mysql "${MYSQL_ARGS[@]}" "$DB_NAME" < "$SEED" \
    && echo "    OK — 6 bidders, 3 closed auctions, 14 bids, 3 results, 3 CPM entries"

# ── Step 4: Quick verification ────────────────────────────────────────────────
step "Verifying row counts..."
mysql "${MYSQL_ARGS[@]}" "$DB_NAME" --table -e "
    SELECT 'bidders'         AS \`table\`, COUNT(*) AS rows FROM bidders
    UNION ALL
    SELECT 'auctions',                    COUNT(*)         FROM auctions
    UNION ALL
    SELECT 'bids',                        COUNT(*)         FROM bids
    UNION ALL
    SELECT 'auction_results',             COUNT(*)         FROM auction_results
    UNION ALL
    SELECT 'cpm_log',                     COUNT(*)         FROM cpm_log;"

# ── Done ──────────────────────────────────────────────────────────────────────
printf '\n\033[1;32m%s\033[0m\n' "Setup complete. Run: php -S localhost:8080 -t public"
printf '\033[2m%s\033[0m\n\n' \
    "Then open: http://localhost:8080"
