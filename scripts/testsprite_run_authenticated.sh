#!/usr/bin/env bash
# Prepara / reexecuta o lote autenticado TestSprite MCP assim que as credenciais existirem.
# Uso:
#   export E2E_TEST_USER_EMAIL='user@example.com'
#   export E2E_TEST_USER_PASSWORD='...'
#   bash scripts/testsprite_run_authenticated.sh
# Opcional: TESTSPRITE_TEST_IDS='TC001,TC005,TC007'  (default: smoke TC001–TC008)

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG="$ROOT/testsprite_tests/tmp/config.json"
PROXY_PORT="${STAGING_PROXY_PORT:-8877}"
PROXY_SCRIPT="$ROOT/scripts/testsprite_staging_proxy.py"
MCP_NODE="${TESTSPRITE_MCP_NODE:-}"

if [[ -z "${E2E_TEST_USER_EMAIL:-}" || -z "${E2E_TEST_USER_PASSWORD:-}" ]]; then
  echo "Faltam credenciais."
  echo "Exporte E2E_TEST_USER_EMAIL e E2E_TEST_USER_PASSWORD (usuário do dashboard staging) e rode de novo."
  exit 2
fi

ensure_proxy() {
  if curl -fsS --max-time 5 "http://127.0.0.1:${PROXY_PORT}/api/health" >/tmp/ts-health.json 2>/dev/null; then
    env_name="$(python3 -c 'import json;print(json.load(open("/tmp/ts-health.json")).get("environment",""))')"
    if [[ "$env_name" == "staging" ]]; then
      echo "OK staging proxy :${PROXY_PORT}"
      return 0
    fi
    echo "ERRO: :${PROXY_PORT} respondeu environment=$env_name (esperado staging). Derrubando..."
    fuser -k "${PROXY_PORT}/tcp" 2>/dev/null || true
    sleep 0.4
  fi
  echo "Subindo staging proxy em :${PROXY_PORT}..."
  nohup python3 "$PROXY_SCRIPT" >/tmp/testsprite-staging-proxy.log 2>&1 &
  echo $! >/tmp/testsprite-staging-proxy.pid
  sleep 0.8
  curl -fsS --max-time 5 "http://127.0.0.1:${PROXY_PORT}/api/health" >/tmp/ts-health.json
  env_name="$(python3 -c 'import json;print(json.load(open("/tmp/ts-health.json")).get("environment",""))')"
  [[ "$env_name" == "staging" ]] || { echo "Proxy subiu mas environment=$env_name"; exit 1; }
  echo "OK staging proxy :${PROXY_PORT}"
}

ensure_proxy

DEFAULT_IDS='TC001,TC002,TC003,TC004,TC005,TC006,TC007,TC008'
IDS_CSV="${TESTSPRITE_TEST_IDS:-$DEFAULT_IDS}"

python3 - <<PY
import json
from pathlib import Path

root = Path(${ROOT@Q})
config_path = root / "testsprite_tests/tmp/config.json"
config_path.parent.mkdir(parents=True, exist_ok=True)

ids = [x.strip() for x in ${IDS_CSV@Q}.split(",") if x.strip()]
email = ${E2E_TEST_USER_EMAIL@Q}
password = ${E2E_TEST_USER_PASSWORD@Q}
port = ${PROXY_PORT@Q}

cfg = {}
if config_path.exists():
    cfg = json.loads(config_path.read_text())

cfg.update({
    "status": "ready",
    "scope": "codebase",
    "type": "frontend",
    "localEndpoint": f"http://localhost:{port}/login",
    "loginUser": email,
    "loginPassword": password,
})
cfg["executionArgs"] = {
    "projectName": "eskill.com.br",
    "projectPath": str(root),
    "testIds": ids,
    "additionalInstruction": (
        "Target is STAGING via localhost:" + port + " (Host staging.eskill.com.br). "
        "NEVER hit production eskill.com.br or ML account 1335. "
        "Use the provided login credentials. Prefer GET/navigation assertions; "
        "avoid irreversible writes (no catalog publish, no clone jobs, no financial sync POST)."
    ),
    "serverMode": "production",
    "envs": {},
}
config_path.write_text(json.dumps(cfg, indent=2) + "\n")
print("config atualizado:", config_path)
print("testIds:", ", ".join(ids))
print("loginUser:", email)
print("loginPassword: ***")
PY

if [[ -z "$MCP_NODE" ]]; then
  # Resolve o mesmo binário npx usado pelo MCP Cursor, se existir
  CANDIDATE="$(ls -1d /root/.npm/_npx/*/node_modules/@testsprite/testsprite-mcp/dist/index.js 2>/dev/null | head -1 || true)"
  if [[ -n "$CANDIDATE" ]]; then
    MCP_NODE="$CANDIDATE"
  fi
fi

if [[ -z "$MCP_NODE" || ! -f "$MCP_NODE" ]]; then
  echo "Config pronto. Para executar via MCP Cursor, diga: 'rode o lote autenticado'."
  echo "Ou instale/aponte TESTSPRITE_MCP_NODE para dist/index.js do @testsprite/testsprite-mcp."
  exit 0
fi

echo "Executando TestSprite generateCodeAndExecute..."
cd "$ROOT"
# Evita ficar preso 1h no server idle: timeout generoso + INT ao terminar
set +e
node "$MCP_NODE" generateCodeAndExecute
code=$?
set -e
echo "exit=$code"
exit "$code"
