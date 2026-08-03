#!/usr/bin/env bash
# Inicia o Codacy MCP Server carregando o token do .env do projeto.
# Não imprime o token. Aceita CODACY_ACCOUNT_TOKEN ou CODACY_API_TOKEN.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${ROOT}/.env"

if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  set -a
  # Extrai só linhas CODACY_* sem source completo (evita side-effects)
  while IFS= read -r line || [[ -n "$line" ]]; do
    case "$line" in
      CODACY_*=*)
        key="${line%%=*}"
        val="${line#*=}"
        val="${val%\"}"; val="${val#\"}"
        val="${val%\'}"; val="${val#\'}"
        export "$key=$val"
        ;;
    esac
  done < "$ENV_FILE"
  set +a
fi

# Pacote oficial usa CODACY_ACCOUNT_TOKEN; o projeto documenta CODACY_API_TOKEN.
if [[ -z "${CODACY_ACCOUNT_TOKEN:-}" && -n "${CODACY_API_TOKEN:-}" ]]; then
  export CODACY_ACCOUNT_TOKEN="$CODACY_API_TOKEN"
fi

if [[ -z "${CODACY_ACCOUNT_TOKEN:-}" ]]; then
  echo "CODACY_ACCOUNT_TOKEN/CODACY_API_TOKEN não definido. Configure em .env ou no ambiente." >&2
  echo "Obtenha em: https://app.codacy.com/account/access-tokens" >&2
  exit 1
fi

if command -v codacy-mcp-server >/dev/null 2>&1; then
  exec codacy-mcp-server
fi

exec npx -y @codacy/codacy-mcp@latest
