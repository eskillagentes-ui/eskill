#!/bin/bash

# ====================================
# MONITORAMENTO DE UPTIME
# Mercado Livre Manager
# ====================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
CONFIG_FILE="$PROJECT_DIR/.env"

# Cores
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Carregar apenas variáveis necessárias (evita export quebrado em comentários/valores com espaço)
load_dotenv_var() {
    local key="$1"
    local val
    val=$(grep -m1 "^${key}=" "$CONFIG_FILE" 2>/dev/null | cut -d= -f2- | tr -d '\r' | sed -e 's/^["'\'']//' -e 's/["'\'']$//')
    if [ -n "$val" ]; then
        export "${key}=${val}"
    fi
}

if [ -f "$CONFIG_FILE" ]; then
    for key in APP_URL HEALTH_ENDPOINT TELEGRAM_ENABLED TELEGRAM_BOT_TOKEN TELEGRAM_CHAT_ID; do
        load_dotenv_var "$key"
    done
fi

APP_URL="${APP_URL:-https://eskill.com.br}"
HEALTH_ENDPOINT="${HEALTH_ENDPOINT:-/api/health}"
LOG_FILE="${PROJECT_DIR}/storage/logs/uptime.log"

log() {
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo -e "${GREEN}[$timestamp]${NC} $1"
    echo "[$timestamp] $1" >> "$LOG_FILE"
}

warning() {
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo -e "${YELLOW}[$timestamp] WARNING:${NC} $1"
    echo "[$timestamp] WARNING: $1" >> "$LOG_FILE"
}

error_log() {
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo -e "${RED}[$timestamp] ERROR:${NC} $1"
    echo "[$timestamp] ERROR: $1" >> "$LOG_FILE"
}

# Enviar alerta via Telegram
send_telegram_alert() {
    local message="$1"
    local status="$2"

    if [ "$TELEGRAM_ENABLED" != "true" ] || [ -z "$TELEGRAM_BOT_TOKEN" ] || [ -z "$TELEGRAM_CHAT_ID" ]; then
        return 0
    fi

    local emoji="⚠️"
    [ "$status" = "up" ] && emoji="✅"
    [ "$status" = "down" ] && emoji="🔴"

    local text="$emoji <b>ML Manager Monitor</b>

$message

🕐 $(date '+%Y-%m-%d %H:%M:%S')
🌐 $APP_URL"

    curl -s -X POST "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/sendMessage" \
        -d chat_id="$TELEGRAM_CHAT_ID" \
        -d text="$text" \
        -d parse_mode="HTML" >/dev/null 2>&1 || true
}

# Verificar health endpoint
check_health() {
    local url="${APP_URL}${HEALTH_ENDPOINT}"
    local timeout=10
    local max_retries=3
    local retry=0

    while [ $retry -lt $max_retries ]; do
        local start_time=$(date +%s%N)

        local response=$(curl -s -o /dev/null -w "%{http_code}|%{time_total}" \
            --max-time $timeout \
            --connect-timeout 5 \
            "$url" 2>/dev/null || echo "000|0")

        local http_code=$(echo "$response" | cut -d'|' -f1)
        local response_time=$(echo "$response" | cut -d'|' -f2)

        if [ "$http_code" = "200" ]; then
            echo "$http_code|$response_time"
            return 0
        fi

        retry=$((retry + 1))
        [ $retry -lt $max_retries ] && sleep 2
    done

    echo "$http_code|$response_time"
    return 1
}

# Verificar serviços
check_services() {
    local all_ok=true

    echo ""
    echo "🔍 Verificando Serviços"
    echo "========================"

    # PHP-FPM
    if systemctl is-active --quiet php8.4-fpm 2>/dev/null; then
        echo "  ✅ PHP-FPM: Rodando"
    else
        echo "  ❌ PHP-FPM: Parado"
        all_ok=false
    fi

    # Nginx
    if systemctl is-active --quiet nginx 2>/dev/null; then
        echo "  ✅ Nginx: Rodando"
    else
        echo "  ❌ Nginx: Parado"
        all_ok=false
    fi

    # MySQL
    if systemctl is-active --quiet mysql 2>/dev/null || systemctl is-active --quiet mariadb 2>/dev/null; then
        echo "  ✅ MySQL: Rodando"
    else
        echo "  ❌ MySQL: Parado"
        all_ok=false
    fi

    # Verificar espaço em disco
    local disk_usage=$(df -h / | awk 'NR==2 {print $5}' | tr -d '%')
    if [ "$disk_usage" -gt 90 ]; then
        echo "  ⚠️ Disco: ${disk_usage}% (crítico)"
        all_ok=false
    elif [ "$disk_usage" -gt 80 ]; then
        echo "  ⚠️ Disco: ${disk_usage}% (alto)"
    else
        echo "  ✅ Disco: ${disk_usage}%"
    fi

    # Verificar memória
    local mem_usage=$(free | awk 'NR==2 {printf "%.0f", $3/$2 * 100}')
    if [ "$mem_usage" -gt 90 ]; then
        echo "  ⚠️ Memória: ${mem_usage}% (crítico)"
        all_ok=false
    elif [ "$mem_usage" -gt 80 ]; then
        echo "  ⚠️ Memória: ${mem_usage}% (alto)"
    else
        echo "  ✅ Memória: ${mem_usage}%"
    fi

    # Verificar load average
    local load=$(cat /proc/loadavg | cut -d' ' -f1)
    local cores=$(nproc)
    local load_per_core=$(echo "$load $cores" | awk '{printf "%.1f", $1/$2}')

    if (( $(echo "$load_per_core > 1.5" | bc -l) )); then
        echo "  ⚠️ Load: $load (alto)"
    else
        echo "  ✅ Load: $load"
    fi

    echo ""

    [ "$all_ok" = true ]
}

# Verificar certificado SSL
check_ssl() {
    local domain=$(echo "$APP_URL" | sed -E 's|https?://||' | cut -d'/' -f1)

    echo "🔒 Verificando SSL: $domain"

    local expiry=$(echo | openssl s_client -servername "$domain" -connect "${domain}:443" 2>/dev/null | \
        openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)

    if [ -n "$expiry" ]; then
        local expiry_epoch=$(date -d "$expiry" +%s 2>/dev/null || echo 0)
        local now_epoch=$(date +%s)
        local days_left=$(( (expiry_epoch - now_epoch) / 86400 ))

        if [ $days_left -lt 7 ]; then
            echo "  ⚠️ Expira em $days_left dias! ($expiry)"
            return 1
        elif [ $days_left -lt 30 ]; then
            echo "  ⚠️ Expira em $days_left dias ($expiry)"
        else
            echo "  ✅ Válido por $days_left dias ($expiry)"
        fi
    else
        echo "  ❌ Não foi possível verificar"
        return 1
    fi

    echo ""
}

# Verificar API do Mercado Livre
check_ml_api() {
    local url="${APP_URL}/api/health"

    echo "🛒 Verificando API..."

    local response=$(curl -s --max-time 10 "$url" 2>/dev/null || echo "{}")

    if echo "$response" | grep -q '"status".*:.*"healthy"'; then
        echo "  ✅ API: Healthy"

        # Verificar database
        if echo "$response" | grep -q '"database"'; then
            echo "  ✅ Database: OK"
        fi

        # Verificar cache
        if echo "$response" | grep -q '"cache"'; then
            echo "  ✅ Cache: OK"
        fi
    else
        echo "  ❌ API: Unhealthy ou inacessível"
        return 1
    fi

    echo ""
}

# Status completo
show_status() {
    echo ""
    echo "📊 MONITORAMENTO - ML Manager"
    echo "=============================="
    echo "🌐 URL: $APP_URL"
    echo "⏰ Verificado em: $(date '+%Y-%m-%d %H:%M:%S')"
    echo ""

    # Health check
    echo "🏥 Health Check"
    echo "---------------"
    local result=$(check_health)
    local http_code=$(echo "$result" | cut -d'|' -f1)
    local response_time=$(echo "$result" | cut -d'|' -f2)

    if [ "$http_code" = "200" ]; then
        echo "  ✅ Status: UP"
        echo "  ⏱️ Response: ${response_time}s"
    else
        echo "  ❌ Status: DOWN (HTTP $http_code)"
    fi
    echo ""

    check_services
    check_ssl
    check_ml_api
}

# Modo de monitoramento contínuo
monitor_loop() {
    local interval="${1:-60}"
    local last_status="up"

    echo "🔄 Iniciando monitoramento contínuo (intervalo: ${interval}s)"
    echo "   Pressione Ctrl+C para parar"
    echo ""

    while true; do
        local result=$(check_health)
        local http_code=$(echo "$result" | cut -d'|' -f1)
        local response_time=$(echo "$result" | cut -d'|' -f2)

        if [ "$http_code" = "200" ]; then
            if [ "$last_status" = "down" ]; then
                log "✅ Site voltou: HTTP $http_code (${response_time}s)"
                send_telegram_alert "Site voltou ao ar!" "up"
            else
                log "✅ OK: HTTP $http_code (${response_time}s)"
            fi
            last_status="up"
        else
            if [ "$last_status" = "up" ]; then
                error_log "❌ Site caiu: HTTP $http_code"
                send_telegram_alert "Site fora do ar! HTTP $http_code" "down"
            else
                error_log "❌ Ainda fora: HTTP $http_code"
            fi
            last_status="down"
        fi

        sleep "$interval"
    done
}

# Menu de ajuda
show_help() {
    echo ""
    echo "📊 Monitor de Uptime - ML Manager"
    echo ""
    echo "Uso: $0 <comando> [opções]"
    echo ""
    echo "Comandos:"
    echo "  status          Mostrar status completo"
    echo "  check           Verificar health endpoint"
    echo "  services        Verificar serviços do sistema"
    echo "  ssl             Verificar certificado SSL"
    echo "  monitor [seg]   Monitoramento contínuo (padrão: 60s)"
    echo "  help            Mostrar esta ajuda"
    echo ""
    echo "Exemplos:"
    echo "  $0 status"
    echo "  $0 monitor 30"
    echo ""
}

# Main
main() {
    case "${1:-status}" in
        status)
            show_status
            ;;
        check)
            local result=$(check_health)
            local http_code=$(echo "$result" | cut -d'|' -f1)
            if [ "$http_code" = "200" ]; then
                echo "UP"
                exit 0
            else
                echo "DOWN ($http_code)"
                exit 1
            fi
            ;;
        services)
            check_services
            ;;
        ssl)
            check_ssl
            ;;
        monitor)
            monitor_loop "${2:-60}"
            ;;
        help|--help|-h)
            show_help
            ;;
        *)
            echo "Comando desconhecido: $1"
            show_help
            exit 1
            ;;
    esac
}

main "$@"
