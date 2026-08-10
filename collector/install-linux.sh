#!/usr/bin/env bash
# Instalador Linux — agenda coleta diária 05:15 America/Sao_Paulo (cron em horário do sistema).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
KEY="${RANK_COLLECTOR_KEY:-}"
SERVER="${RANK_COLLECTOR_SERVER:-https://eskill.com.br}"
if [[ -z "$KEY" || ${#KEY} -lt 16 ]]; then
  echo "Defina RANK_COLLECTOR_KEY (>=16) antes."; exit 2
fi
LINE="15 5 * * * cd ${ROOT} && RANK_COLLECTOR_SERVER=${SERVER} RANK_COLLECTOR_KEY=${KEY} /usr/bin/php ${ROOT}/collector/rank-collector.php >>${ROOT}/storage/logs/rank-collector.log 2>&1"
(crontab -l 2>/dev/null | grep -v 'rank-collector.php'; echo "$LINE") | crontab -
echo "OK: cron instalado."
echo "Teste dry-run: php ${ROOT}/collector/rank-collector.php --server=${SERVER} --key=*** --dry"
