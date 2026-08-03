#!/usr/bin/env bash
set -euo pipefail
ENV_FILE=/home/eskill/htdocs/staging.eskill.com.br/.env
id=$(grep -E '^PREGAO_ACCOUNT_ID=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d "\"'")
if [[ "$id" == "1335" || "$id" == "0" || -z "$id" ]]; then
  echo "staging tick: defina PREGAO_ACCOUNT_ID de teste (≠1335, ≠0)" >&2
  exit 1
fi
