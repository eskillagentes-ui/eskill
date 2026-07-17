#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MCP_FILE="$ROOT_DIR/.vscode/mcp.json"
SETTINGS_FILE="$ROOT_DIR/.vscode/settings.json"
ML_SCRIPT="$ROOT_DIR/bin/mcp-ml-start.sh"
TRAE_MCP_DIR="/root/.trae/mcps/s_eskill_com_br-6c527cfb/solo_coder"
OUT_DIR="$ROOT_DIR/storage/monitoring"
OUT_FILE="$OUT_DIR/mcp_status_latest.json"

mkdir -p "$OUT_DIR"

PASS_COUNT=0
FAIL_COUNT=0
CHECKS_JSON=""

add_check() {
  local name="$1"
  local status="$2"
  local detail="$3"
  local escaped_detail
  escaped_detail=$(printf '%s' "$detail" | sed 's/\\/\\\\/g; s/"/\\"/g')
  if [ "$status" = "pass" ]; then
    PASS_COUNT=$((PASS_COUNT + 1))
  else
    FAIL_COUNT=$((FAIL_COUNT + 1))
  fi
  if [ -n "$CHECKS_JSON" ]; then
    CHECKS_JSON="${CHECKS_JSON},"
  fi
  CHECKS_JSON="${CHECKS_JSON}{\"name\":\"${name}\",\"status\":\"${status}\",\"detail\":\"${escaped_detail}\"}"
}

if [ -f "$MCP_FILE" ]; then
  add_check "mcp_config_exists" "pass" "$MCP_FILE encontrado"
else
  add_check "mcp_config_exists" "fail" "$MCP_FILE não encontrado"
fi

if grep -q '@latest' "$MCP_FILE"; then
  add_check "version_pinning" "fail" "Ainda há dependências MCP com @latest"
else
  add_check "version_pinning" "pass" "Dependências MCP com versões fixas"
fi

for cmd in bash php node npm npx; do
  if command -v "$cmd" >/dev/null 2>&1; then
    add_check "runtime_${cmd}" "pass" "$cmd disponível"
  else
    add_check "runtime_${cmd}" "fail" "$cmd indisponível"
  fi
done

if grep -q '"github.copilot.chat.githubMcpServer.readonly": true' "$SETTINGS_FILE" && grep -q '"github.copilot.chat.githubMcpServer.lockdown": true' "$SETTINGS_FILE"; then
  add_check "github_mcp_hardening" "pass" "GitHub MCP em readonly+lockdown"
else
  add_check "github_mcp_hardening" "fail" "GitHub MCP sem hardening completo"
fi

if grep -q '"fabricMcp.readOnly": true' "$SETTINGS_FILE"; then
  add_check "fabric_readonly" "pass" "Fabric MCP em modo somente leitura"
else
  add_check "fabric_readonly" "fail" "Fabric MCP fora de modo somente leitura"
fi

if grep -q 'mcp-remote@0.1.38' "$ML_SCRIPT"; then
  add_check "mercadolibre_wrapper_pin" "pass" "mcp-remote fixado"
else
  add_check "mercadolibre_wrapper_pin" "fail" "mcp-remote sem versão fixa"
fi

if bash -n "$ML_SCRIPT" >/dev/null 2>&1; then
  add_check "mercadolibre_wrapper_syntax" "pass" "Script de wrapper válido"
else
  add_check "mercadolibre_wrapper_syntax" "fail" "Erro de sintaxe no wrapper ML"
fi

for server in integrated_browser mcp_GitHub mcp_Playwright mcp_context7; do
  metadata="$TRAE_MCP_DIR/$server/SERVER_METADATA.json"
  tools_dir="$TRAE_MCP_DIR/$server/tools"
  if [ -f "$metadata" ] && [ -d "$tools_dir" ]; then
    count=$(find "$tools_dir" -maxdepth 1 -type f -name '*.json' | wc -l | tr -d ' ')
    if [ "$count" -gt 0 ]; then
      add_check "trae_${server}" "pass" "$count ferramentas encontradas"
    else
      add_check "trae_${server}" "fail" "Sem ferramentas no diretório"
    fi
  else
    add_check "trae_${server}" "fail" "Metadados ou tools ausentes"
  fi
done

timestamp="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
overall="pass"
if [ "$FAIL_COUNT" -gt 0 ]; then
  overall="fail"
fi

printf '{\n  "timestamp":"%s",\n  "overall":"%s",\n  "pass_count":%s,\n  "fail_count":%s,\n  "checks":[%s]\n}\n' \
  "$timestamp" "$overall" "$PASS_COUNT" "$FAIL_COUNT" "$CHECKS_JSON" > "$OUT_FILE"

cat "$OUT_FILE"

if [ "$overall" = "fail" ]; then
  exit 1
fi
