#!/bin/bash
#
# deploy.sh — Deployment & Service Activation for eskill.com.br
#
# Uso:
#   bash bin/deploy.sh              # Deploy completo
#   bash bin/deploy.sh --check      # Só verificação
#   bash bin/deploy.sh --services   # Só checar serviços
#   bash bin/deploy.sh --cron       # Só instalar crontab
#   bash bin/deploy.sh --migrate    # Só aplicar migrations
#

set +e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_DIR"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'
BOLD='\033[1m'

ERRORS=0
WARNINGS=0

ok()   { echo -e "  ${GREEN}✅ $1${NC}"; }
fail() { echo -e "  ${RED}❌ $1${NC}"; ((ERRORS++)); }
warn() { echo -e "  ${YELLOW}⚠️  $1${NC}"; ((WARNINGS++)); }
info() { echo -e "  ${BLUE}ℹ️  $1${NC}"; }
section() { echo -e "\n${BOLD}${CYAN}── $1 ──${NC}"; }

MODE="${1:-full}"

echo ""
echo -e "${BOLD}${CYAN}══════════════════════════════════════════════${NC}"
echo -e "${BOLD}${CYAN}  eskill.com.br — Production Deployment${NC}"
echo -e "${BOLD}${CYAN}══════════════════════════════════════════════${NC}"
echo ""

# ============================================================
# 1. PRE-FLIGHT CHECKS
# ============================================================
check_prerequisites() {
    section "1. Pre-flight Checks"

    if command -v php &>/dev/null; then
        PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
        ok "PHP $PHP_VER"
    else
        fail "PHP não encontrado"
        return 1
    fi

    if [[ -f vendor/autoload.php ]]; then
        ok "Composer autoload"
    else
        warn "vendor/autoload.php não encontrado"
        composer install --no-dev --optimize-autoloader 2>/dev/null || fail "composer install falhou"
    fi

    if [[ -f .env ]]; then
        ok ".env presente"
        for var in DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD ML_APP_ID ML_CLIENT_SECRET ML_REDIRECT_URI APP_URL APP_KEY; do
            val=$(grep "^${var}=" .env 2>/dev/null | cut -d= -f2-)
            if [[ -z "$val" || "$val" == "CHANGE_ME" || "$val" == "your_"* ]]; then
                warn "$var não configurado em .env"
            fi
        done
    else
        fail ".env não encontrado"
    fi

    for dir in storage/logs storage/cache; do
        if [[ -d "$dir" && -w "$dir" ]]; then
            ok "$dir writable"
        else
            mkdir -p "$dir" 2>/dev/null && chmod 775 "$dir" && ok "$dir criado" || fail "$dir não writable"
        fi
    done
}

# ============================================================
# 2. SERVICE CHECKS
# ============================================================
check_services() {
    section "2. Services"

    php bin/production-check.php 2>&1
}

# ============================================================
# 3. DATABASE MIGRATIONS
# ============================================================
run_migrations() {
    section "3. Migrations"

    if [[ -f bin/migrate.php ]]; then
        info "Aplicando migrations pendentes..."
        php bin/migrate.php 2>&1
        if [[ $? -eq 0 ]]; then
            ok "Migrations aplicadas"
        else
            fail "Erro ao aplicar migrations"
        fi
    else
        warn "bin/migrate.php não encontrado"
    fi
}

# ============================================================
# 4. FILE PERMISSIONS
# ============================================================
fix_permissions() {
    section "4. Permissões"

    find storage/ -type d -exec chmod 775 {} \; 2>/dev/null
    find storage/ -type f -exec chmod 664 {} \; 2>/dev/null
    ok "storage/ permissions"

    chmod +x bin/*.sh 2>/dev/null || true
    chmod +x bin/*.php 2>/dev/null || true
    ok "bin/ scripts executáveis"

    if id eskill &>/dev/null; then
        chown -R eskill:eskill storage/ 2>/dev/null || true
        ok "Ownership storage → eskill"
    fi
}

# ============================================================
# 5. HTTPS & SITE CHECK
# ============================================================
check_https() {
    section "5. HTTPS & Site"

    APP_URL=$(grep "^APP_URL=" .env 2>/dev/null | cut -d= -f2-)

    if [[ -z "$APP_URL" ]]; then
        warn "APP_URL não definido no .env"
        return
    fi

    if command -v curl &>/dev/null; then
        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "${APP_URL}/api/health" 2>/dev/null || echo "000")

        if [[ "$HTTP_CODE" == "200" ]]; then
            ok "Health check: $APP_URL/api/health → HTTP $HTTP_CODE"
        elif [[ "$HTTP_CODE" == "000" ]]; then
            fail "Site não responde: $APP_URL (conexão recusada)"
        else
            warn "Health check retorna HTTP $HTTP_CODE"
        fi

        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "${APP_URL}/login" 2>/dev/null || echo "000")
        if [[ "$HTTP_CODE" == "200" ]]; then
            ok "Login page: HTTP $HTTP_CODE"
        else
            warn "Login page retorna HTTP $HTTP_CODE"
        fi
    else
        warn "curl não disponível"
    fi
}

# ============================================================
# SUMMARY
# ============================================================
summary() {
    echo ""
    echo -e "${BOLD}${CYAN}══════════════════════════════════════════════${NC}"
    if [[ $ERRORS -gt 0 ]]; then
        echo -e "${BOLD}${RED}  ❌ Deploy com $ERRORS erros e $WARNINGS avisos${NC}"
    elif [[ $WARNINGS -gt 0 ]]; then
        echo -e "${BOLD}${YELLOW}  ⚠️  Deploy OK com $WARNINGS avisos${NC}"
    else
        echo -e "${BOLD}${GREEN}  ✅ Deploy completo — Sistema pronto!${NC}"
    fi
    echo -e "${BOLD}${CYAN}══════════════════════════════════════════════${NC}"
    echo ""

    if [[ $ERRORS -eq 0 ]]; then
        echo -e "${BOLD}Próximos passos:${NC}"
        echo "  1. Acesse https://eskill.com.br/login e faça login"
        echo "  2. Vincule conta ML: https://eskill.com.br/auth/authorize"
        echo "  3. Verifique o dashboard: https://eskill.com.br/dashboard"
        echo "  4. Rode coleta inicial: php bin/collect-ml-data.php"
        echo "  5. Health check: https://eskill.com.br/api/health"
        echo ""
    fi
}

# ============================================================
# MAIN
# ============================================================
case "$MODE" in
    --check)
        check_prerequisites
        check_services
        check_https
        ;;
    --services)
        check_services
        ;;
    --cron)
        info "Instale o crontab: crontab config/production-crontab"
        ;;
    --migrate)
        run_migrations
        ;;
    full|*)
        check_prerequisites
        check_services
        run_migrations
        fix_permissions
        check_https
        ;;
esac

summary
exit $ERRORS
