#!/usr/bin/env bash
# apply-sync-bugfixes.sh — requer sudo para chown/crontab/kill
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> 1) Matar worker de pedidos travado (se existir)"
pkill -f 'bin/orders-sync-worker.php --account-id=1335' 2>/dev/null || true
sleep 1

echo "==> 2) Restaurar AIConfigService no path PSR-4 canônico"
mkdir -p app/Services/AI/Core
cp -f app/Services/_runtime/AIConfigService.php app/Services/AI/Core/AIConfigService.php
chown eskill:eskill app/Services/AI/Core/AIConfigService.php

echo "==> 3) Promover overrides _runtime para arquivos canônicos"
cp -f app/Services/_runtime/ItemSyncService.php app/Services/ItemSyncService.php
cp -f app/Services/_runtime/AccountSyncService.php app/Services/AccountSyncService.php
chown eskill:eskill app/Services/ItemSyncService.php app/Services/AccountSyncService.php

echo "==> 4) Corrigir contagem de pedidos em sync-now.php"
if grep -q 'FROM ml_orders WHERE account_id = ml_accounts.id' bin/sync-now.php; then
  sed -i 's/FROM ml_orders WHERE account_id = ml_accounts.id/FROM ml_orders WHERE ml_account_id = ml_accounts.id/' bin/sync-now.php
  echo "    sync-now.php atualizado"
else
  echo "    sync-now.php já ok ou padrão diferente — verifique manualmente"
fi

echo "==> 5) Aceitar --account-id nos workers"
for w in bin/items-sync-worker.php bin/orders-sync-worker.php bin/questions-sync-worker.php; do
  if grep -q "getopt('', \\['once', 'account:'" "$w" 2>/dev/null; then
    sed -i "s/getopt('', \\['once', 'account:'/getopt('', ['once', 'account:', 'account-id:'/" "$w" || true
  fi
  if ! grep -q 'account-id' "$w"; then
    echo "    AVISO: revise flags em $w"
  fi
done

echo "==> 6) Instalar crontab canônico (docs/examples/current_crontab)"
if [[ -f docs/examples/current_crontab ]]; then
  crontab docs/examples/current_crontab
  echo "    crontab instalado a partir de docs/examples/current_crontab"
else
  echo "    AVISO: docs/examples/current_crontab não encontrado"
fi

echo "==> 7) Atualizar config/production-crontab para workers bin/*"
cat > config/production-crontab <<'EOF'
# eskill.com.br — Production Crontab (sync bugfix 2026-07-31)
PROJECT=/home/eskill/htdocs/eskill.com.br
PHP=/usr/bin/php
LOG=/home/eskill/htdocs/eskill.com.br/storage/logs

*/30 * * * * cd $PROJECT && $PHP bin/auto-token-refresh-worker.php >> $LOG/auto-token-refresh.log 2>&1
0 * * * * cd $PROJECT && $PHP bin/orders-sync-worker.php --once >> $LOG/orders-sync-worker.log 2>&1
0 */6 * * * cd $PROJECT && $PHP bin/items-sync-worker.php --once >> $LOG/items-sync-worker.log 2>&1
*/30 * * * * cd $PROJECT && $PHP bin/questions-sync-worker.php --once >> $LOG/questions-sync-worker.log 2>&1
* * * * * cd $PROJECT && $PHP bin/webhook-processor-worker.php --once >> $LOG/webhook-processor.log 2>&1
*/5 * * * * cd $PROJECT && $PHP scripts/scheduler.php >> $LOG/cron_scheduler.log 2>&1
* * * * * cd $PROJECT && $PHP scripts/run_queue.php >> $LOG/queue.log 2>&1
0 3 * * * sudo clp-update
EOF
chown eskill:eskill config/production-crontab

echo "==> 8) Sync imediato conta 1335"
$PHP bin/items-sync-worker.php --once --account=1335 || true

echo "Done. Verifique: php -r 'var_export(class_exists(\"App\\\\Services\\\\AI\\\\Core\\\\AIConfigService\"));'"
