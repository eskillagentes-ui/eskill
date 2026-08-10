#!/usr/bin/env bash
# Gera pacote do coletor para o PC (IP residencial), sem imprimir a chave.
# Uso (no servidor): bash collector/pack-for-pc.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${ROOT}/storage/rank-collector-pack"
mkdir -p "$OUT"

KEY="$(grep -E '^RANK_COLLECTOR_KEY=' "${ROOT}/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '\r' || true)"
HMAC="$(grep -E '^RANK_COLLECTOR_HMAC_SECRET=' "${ROOT}/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '\r' || true)"
HMAC="${HMAC:-$KEY}"
SERVER="$(grep -E '^APP_URL=' "${ROOT}/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '\r' || true)"
SERVER="${SERVER:-https://eskill.com.br}"
ACCOUNT="$(grep -E '^RANK_COLLECTOR_ACCOUNT_ID=' "${ROOT}/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '\r' || true)"
ACCOUNT="${ACCOUNT:-1335}"

if [[ -z "$KEY" || ${#KEY} -lt 16 ]]; then
  echo "RANK_COLLECTOR_KEY ausente/curta no .env"; exit 2
fi

STAGE="${OUT}/staging"
rm -rf "$STAGE"
mkdir -p "$STAGE"
cp "${ROOT}/collector/rank-collector.php" "$STAGE/"
cp "${ROOT}/collector/install-linux.sh" "$STAGE/"
cp "${ROOT}/collector/install-windows.ps1" "$STAGE/"
cp "${ROOT}/collector/README.md" "$STAGE/"

umask 077
cat > "$STAGE/collector.env" <<EOF
RANK_COLLECTOR_SERVER=${SERVER}
RANK_COLLECTOR_KEY=${KEY}
RANK_COLLECTOR_HMAC_SECRET=${HMAC}
RANK_COLLECTOR_ACCOUNT_ID=${ACCOUNT}
EOF

cat > "$STAGE/RUN-LINUX.txt" <<'EOF'
1) unzip rank-collector-pc.zip && cd pasta
2) set -a && source collector.env && set +a
3) php rank-collector.php --dry
4) php rank-collector.php
5) bash install-linux.sh
EOF

cat > "$STAGE/RUN-WINDOWS.txt" <<'EOF'
1) Extraia o zip
2) PowerShell na pasta:
   Get-Content collector.env | ForEach-Object { if ($_ -match '^([^=]+)=(.*)$') { Set-Item -Path "env:$($matches[1])" -Value $matches[2] } }
3) php .\rank-collector.php --dry
4) php .\rank-collector.php
5) .\install-windows.ps1
EOF

ZIP="${OUT}/rank-collector-pc.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -q -r "$ZIP" . )
cp "$STAGE/collector.env" "${OUT}/collector.env"
chmod 600 "${OUT}/collector.env" "$STAGE/collector.env"

GI="${ROOT}/.gitignore"
grep -q 'storage/rank-collector-pack' "$GI" 2>/dev/null || echo 'storage/rank-collector-pack/' >> "$GI"

echo "OK: pacote em ${ZIP}"
echo "    config: ${OUT}/collector.env (chmod 600, fora do git)"
echo "Baixe no PC e siga RUN-LINUX.txt ou RUN-WINDOWS.txt"
