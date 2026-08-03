#!/usr/bin/env bash
# Deploy / sync do staging isolado (NÃO toca produção).
#
# Uso:
#   bash scripts/deploy_staging.sh              # rsync código + restart WS staging
#   bash scripts/deploy_staging.sh --check      # só verifica isolamento
#   bash scripts/deploy_staging.sh --nginx      # instala vhost + reload nginx
#   bash scripts/deploy_staging.sh --services   # instala/enable pregao-ws-staging
#
set -euo pipefail

PROD_DIR="/home/eskill/htdocs/eskill.com.br"
STAGING_DIR="/home/eskill/htdocs/staging.eskill.com.br"
MODE="${1:-full}"

ok()   { echo "  OK  $*"; }
fail() { echo "  FAIL $*" >&2; exit 1; }
warn() { echo "  WARN $*"; }
section() { echo ""; echo "── $* ──"; }

check_isolation() {
  section "Isolamento"
  [[ -d "$STAGING_DIR" ]] || fail "path staging ausente: $STAGING_DIR"
  [[ -f "$STAGING_DIR/.env" ]] || fail ".env staging ausente"
  [[ "$STAGING_DIR" != "$PROD_DIR" ]] || fail "STAGING_DIR == PROD_DIR"

  local env_file="$STAGING_DIR/.env"
  grep -q '^APP_ENV=staging$' "$env_file" || fail "APP_ENV != staging"
  grep -q '^DB_DATABASE=eskill_staging$' "$env_file" || fail "DB_DATABASE != eskill_staging"
  grep -q '^REDIS_DB=1$' "$env_file" || fail "REDIS_DB != 1"
  grep -q '^PREGAO_WS_PORT=8092$' "$env_file" || fail "PREGAO_WS_PORT != 8092"
  grep -q '^ML_WRITE_AUTOMATION=false$' "$env_file" || warn "ML_WRITE_AUTOMATION não está false"
  if grep -qE '^PREGAO_ACCOUNT_ID=1335$' "$env_file"; then
    fail "PREGAO_ACCOUNT_ID=1335 proibido no staging"
  fi
  # PHP-FPM (user eskill) precisa ler o .env — modo igual ao de prod (640)
  chown eskill:eskill "$env_file" 2>/dev/null || true
  chmod 640 "$env_file" 2>/dev/null || true
  ok "env isolation keys (+ perms 640 eskill)"
}

sync_code() {
  section "Rsync código → staging"
  [[ -d "$STAGING_DIR" ]] || mkdir -p "$STAGING_DIR"
  rsync -a \
    --exclude='.env' \
    --exclude='.env.*' \
    --exclude='storage/' \
    --exclude='.tmp/' \
    --exclude='node_modules/' \
    --exclude='playwright-report/' \
    --exclude='test-results/' \
    --exclude='.git/' \
    "$PROD_DIR/" "$STAGING_DIR/"
  mkdir -p "$STAGING_DIR/storage/logs" "$STAGING_DIR/storage/cache" \
    "$STAGING_DIR/storage/sessions" "$STAGING_DIR/.tmp"
  chown -R eskill:eskill "$STAGING_DIR/storage" "$STAGING_DIR/.tmp" 2>/dev/null || true
  ok "rsync completo (sem .env / storage / .git)"
}

install_nginx() {
  section "Nginx staging vhost"
  local src="$PROD_DIR/config/nginx/staging.eskill.com.br.conf"
  [[ -f "$src" ]] || fail "faltando $src"
  cp "$src" /etc/nginx/sites-available/staging.eskill.com.br.conf
  ln -sfn /etc/nginx/sites-available/staging.eskill.com.br.conf \
    /etc/nginx/sites-enabled/staging.eskill.com.br.conf
  nginx -t
  systemctl reload nginx
  ok "nginx reload com staging.eskill.com.br"
}

install_services() {
  section "systemd pregao-*-staging"
  cp "$PROD_DIR/config/systemd/pregao-ws-staging.service" /etc/systemd/system/
  cp "$PROD_DIR/config/systemd/pregao-tick-staging.service" /etc/systemd/system/
  systemctl daemon-reload
  systemctl enable pregao-ws-staging.service
  systemctl restart pregao-ws-staging.service
  # tick NÃO enable por default — exige conta de teste ≠1335
  systemctl disable pregao-tick-staging.service 2>/dev/null || true
  systemctl stop pregao-tick-staging.service 2>/dev/null || true
  ok "pregao-ws-staging active; tick-staging disabled até PREGAO_ACCOUNT_ID de teste"
  systemctl --no-pager --full status pregao-ws-staging.service | head -15 || true
}

smoke() {
  section "Smoke isolamento"
  # Redis DB1 vs DB0
  php <<'PHP'
<?php
require '/home/eskill/htdocs/staging.eskill.com.br/vendor/autoload.php';
Dotenv\Dotenv::createImmutable('/home/eskill/htdocs/staging.eskill.com.br')->safeLoad();
$r = new Redis();
$r->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', (int)($_ENV['REDIS_PORT'] ?? 6379));
$pass = $_ENV['REDIS_PASSWORD'] ?? '';
if ($pass !== '' && !in_array($pass, ['null', 'false'], true)) {
    $r->auth($pass);
}
$r->select(1);
$r->setex('pregao:staging:smoke', 30, 'ok');
echo "redis_db1_write=ok\n";
$r->select(0);
$v = $r->get('pregao:staging:smoke');
echo "redis_db0_leak=" . ($v === false || $v === null ? 'none' : 'LEAK') . "\n";
$pdo = App\Database::getInstance();
echo "staging_db=" . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "pregao_events=" . (int)$pdo->query('SELECT COUNT(*) FROM pregao_events')->fetchColumn() . "\n";
PHP

  if ss -ltn | grep -q ':8092'; then
    ok "WS staging :8092 listening"
  else
    warn "WS staging :8092 não está up (rode --services)"
  fi

  code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: staging.eskill.com.br' http://127.0.0.1/api/health || true)
  echo "  health HTTP Host=staging → ${code}"
}

case "$MODE" in
  --check) check_isolation; smoke ;;
  --nginx) install_nginx ;;
  --services) install_services ;;
  full)
    check_isolation
    sync_code
    install_nginx
    install_services
    smoke
    ;;
  *)
    echo "Uso: $0 [full|--check|--nginx|--services]"
    exit 2
    ;;
esac

echo ""
echo "Deploy staging concluído ($MODE)."
echo "DNS público staging.eskill.com.br ainda precisa apontar para este host."
echo "TLS: certbot --nginx -d staging.eskill.com.br (após DNS)."
echo "E2E mutante: npm run test:e2e:staging"
echo "Prod seguro: npm run test:e2e:readonly"
