#!/bin/bash
# Manutenção semanal auth_failure_log — evita senha na linha de comando do cron
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$APP_DIR/.env"
LOG_FILE="$APP_DIR/storage/logs/db_maintenance.log"

read_env() {
    local key="$1"
    grep -m1 "^${key}=" "$ENV_FILE" 2>/dev/null | cut -d= -f2- | tr -d '\r' | sed -e 's/^["'\'']//' -e 's/["'\'']$//'
}

DB_HOST="$(read_env DB_HOST)"
DB_USER="$(read_env DB_USER)"
DB_PASS="$(read_env DB_PASS)"
DB_NAME="$(read_env DB_NAME)"

DB_HOST="${DB_HOST:-localhost}"
DB_USER="${DB_USER:-root}"
DB_NAME="${DB_NAME:-meli}"

if [ -z "$DB_PASS" ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') | ERRO: DB_PASS não definido" >> "$LOG_FILE"
    exit 1
fi

export MYSQL_PWD="$DB_PASS"
mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" -e \
    "DELETE FROM auth_failure_log WHERE detected_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 500000; OPTIMIZE TABLE auth_failure_log;" \
    >> "$LOG_FILE" 2>&1
unset MYSQL_PWD

echo "$(date '+%Y-%m-%d %H:%M:%S') | OK" >> "$LOG_FILE"
