#!/bin/bash
#
# pre-deploy-backup.sh — Backup pré-deploy para janela de manutenção
#
# Cria snapshots de banco, .env e crontab antes de mudanças em produção.
# Salva em storage/backups/pre-deploy/YYYYMMDD_HHMMSS/
#
# Uso:
#   bash bin/pre-deploy-backup.sh
#   bash bin/pre-deploy-backup.sh --skip-db   # Pula mysqldump (ambiente sem acesso direto ao MySQL)
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'
BOLD='\033[1m'

SKIP_DB=0
for arg in "$@"; do
    [[ "$arg" == "--skip-db" ]] && SKIP_DB=1
done

TIMESTAMP="$(date '+%Y%m%d_%H%M%S')"
BACKUP_DIR="${PROJECT_DIR}/storage/backups/pre-deploy/${TIMESTAMP}"

mkdir -p "$BACKUP_DIR"

function ok()   { echo -e "  ${GREEN}✅  $1${NC}"; }
function fail() { echo -e "  ${RED}❌  $1${NC}"; }
function info() { echo -e "  ${BLUE}ℹ️   $1${NC}"; }
function warn() { echo -e "  ${YELLOW}⚠️   $1${NC}"; }

echo ""
echo -e "${BOLD}${CYAN}════════════════════════════════════════════════${NC}"
echo -e "${BOLD}${CYAN}  eskill.com.br — Pre-Deploy Backup${NC}"
echo -e "${BOLD}${CYAN}  Timestamp: ${TIMESTAMP}${NC}"
echo -e "${BOLD}${CYAN}════════════════════════════════════════════════${NC}"
echo ""

# Load .env
ENV_FILE="${PROJECT_DIR}/.env"
if [[ -f "$ENV_FILE" ]]; then
    set -o allexport
    # shellcheck disable=SC1090
    source "$ENV_FILE" 2>/dev/null || true
    set +o allexport
fi

# 1. Backup .env
echo "── 1. Backup .env ──"
if [[ -f "$ENV_FILE" ]]; then
    cp "$ENV_FILE" "${BACKUP_DIR}/.env.backup"
    ok ".env salvo em ${BACKUP_DIR}/.env.backup"
else
    warn ".env não encontrado — sem backup de credenciais"
fi

# 2. Backup crontab
echo ""
echo "── 2. Backup crontab ──"
CRONTAB_OUTPUT="${BACKUP_DIR}/crontab.backup"
if crontab -l > "$CRONTAB_OUTPUT" 2>/dev/null; then
    ok "crontab salvo em ${CRONTAB_OUTPUT}"
else
    info "Nenhum crontab configurado para usuário $(whoami)"
    echo "# No crontab entries" > "$CRONTAB_OUTPUT"
fi

# 3. MySQL dump
echo ""
echo "── 3. MySQL dump ──"
if [[ $SKIP_DB -eq 1 ]]; then
    warn "MySQL dump pulado (--skip-db)"
elif command -v mysqldump >/dev/null 2>&1; then
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT="${DB_PORT:-3306}"
    DB_DATABASE="${DB_DATABASE:-meli}"
    DB_USERNAME="${DB_USERNAME:-}"
    DB_PASSWORD="${DB_PASSWORD:-}"

    if [[ -z "$DB_USERNAME" ]]; then
        warn "DB_USERNAME não definido — dump pulado. Exporte DB_USERNAME e DB_PASSWORD antes de rodar."
    else
        DUMP_FILE="${BACKUP_DIR}/database_${DB_DATABASE}_${TIMESTAMP}.sql.gz"
        if mysqldump \
            --host="$DB_HOST" \
            --port="$DB_PORT" \
            --user="$DB_USERNAME" \
            --password="${DB_PASSWORD}" \
            --single-transaction \
            --routines \
            --triggers \
            --events \
            --add-drop-table \
            "$DB_DATABASE" 2>/dev/null | gzip > "$DUMP_FILE"; then
            if [[ -s "$DUMP_FILE" ]]; then
                DUMP_SIZE="$(du -sh "$DUMP_FILE" | cut -f1)"
                ok "Dump salvo: ${DUMP_FILE} (${DUMP_SIZE})"
            else
                rm -f "$DUMP_FILE"
                fail "mysqldump gerou arquivo vazio — verifique credenciais e schema"
            fi
        else
            fail "mysqldump falhou — verifique usuário/senha MySQL (ML-005: use usuário de app, não root)"
        fi
    fi
else
    warn "mysqldump não encontrado — instale mysql-client para backups completos"
fi

# 4. Git status snapshot
echo ""
echo "── 4. Git status ──"
GIT_STATUS_FILE="${BACKUP_DIR}/git_status.txt"
if command -v git >/dev/null 2>&1 && git -C "$PROJECT_DIR" rev-parse --git-dir >/dev/null 2>&1; then
    {
        echo "=== git log --oneline -10 ==="
        git -C "$PROJECT_DIR" log --oneline -10 2>/dev/null
        echo ""
        echo "=== git status ==="
        git -C "$PROJECT_DIR" status 2>/dev/null
    } > "$GIT_STATUS_FILE"
    CURRENT_COMMIT="$(git -C "$PROJECT_DIR" rev-parse HEAD 2>/dev/null)"
    ok "Commit atual: ${CURRENT_COMMIT}"
    ok "Git status salvo em ${GIT_STATUS_FILE}"
else
    warn "Git não disponível ou fora de repositório"
fi

# 5. ML accounts status snapshot
echo ""
echo "── 5. ML Accounts snapshot ──"
ML_SNAPSHOT_FILE="${BACKUP_DIR}/ml_accounts_snapshot.txt"
if command -v php >/dev/null 2>&1; then
    php -r "
        require_once '${PROJECT_DIR}/vendor/autoload.php';
        try {
            \$dotenv = Dotenv\Dotenv::createImmutable('${PROJECT_DIR}');
            \$dotenv->safeLoad();
            \$host = getenv('DB_HOST') ?: '127.0.0.1';
            \$port = getenv('DB_PORT') ?: '3306';
            \$db   = getenv('DB_DATABASE') ?: 'meli';
            \$user = getenv('DB_USERNAME') ?: '';
            \$pass = getenv('DB_PASSWORD') ?: '';
            if (empty(\$user)) { echo 'DB_USERNAME nao definido' . PHP_EOL; exit(0); }
            \$dsn = \"mysql:host={\$host};port={\$port};dbname={\$db};charset=utf8mb4\";
            \$pdo = new PDO(\$dsn, \$user, \$pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            \$rows = \$pdo->query('SELECT id, nickname, status, token_expires_at, last_refresh_error FROM ml_accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            echo 'id | nickname | status | token_expires_at | last_refresh_error' . PHP_EOL;
            echo str_repeat('-', 100) . PHP_EOL;
            foreach (\$rows as \$r) {
                echo implode(' | ', array_map('strval', array_values(\$r))) . PHP_EOL;
            }
            echo PHP_EOL . count(\$rows) . ' conta(s) total.' . PHP_EOL;
        } catch (\Throwable \$e) {
            echo 'Snapshot ML falhou: ' . \$e->getMessage() . PHP_EOL;
        }
    " > "$ML_SNAPSHOT_FILE" 2>&1
    ok "Snapshot ML accounts: ${ML_SNAPSHOT_FILE}"
else
    warn "PHP não encontrado — snapshot ML pulado"
fi

# Summary
echo ""
echo -e "${BOLD}${CYAN}════════════════════════════════════════════════${NC}"
echo -e "  Backup completo: ${BOLD}${BACKUP_DIR}${NC}"
echo ""
echo "  Para rollback, restaure com:"
echo "    .env:    cp ${BACKUP_DIR}/.env.backup ${PROJECT_DIR}/.env"
echo "    crontab: crontab ${BACKUP_DIR}/crontab.backup"
if [[ $SKIP_DB -eq 0 ]]; then
    echo "    banco:   zcat ${BACKUP_DIR}/database_*.sql.gz | mysql -u\$DB_USERNAME -p\$DB_PASSWORD \$DB_DATABASE"
fi
echo -e "${BOLD}${CYAN}════════════════════════════════════════════════${NC}"
echo ""

exit 0
