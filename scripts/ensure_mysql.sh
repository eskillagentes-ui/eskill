#!/bin/bash
# Garante que o MySQL de produção (Percona) esteja ativo — evita downtime silencioso.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="${SCRIPT_DIR}/../storage/logs/mysql_watchdog.log"
mkdir -p "$(dirname "$LOG_FILE")"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOG_FILE"
}

if systemctl is-active --quiet mysql.service 2>/dev/null; then
    exit 0
fi

log "WARN: mysql.service inativo — tentando systemctl start"
if systemctl start mysql.service 2>>"$LOG_FILE"; then
    sleep 2
    if systemctl is-active --quiet mysql.service; then
        log "OK: MySQL reiniciado com sucesso"
        exit 0
    fi
fi

log "CRITICAL: falha ao reiniciar MySQL"
exit 1
