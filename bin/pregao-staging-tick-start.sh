#!/usr/bin/env bash
set -euo pipefail
ENV_FILE=/home/eskill/htdocs/staging.eskill.com.br/.env
id=$(grep -E '^PREGAO_ACCOUNT_ID=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d "\"'")
exec /usr/bin/php /home/eskill/htdocs/staging.eskill.com.br/bin/pregao-index-tick.php \
  --account-id="$id" --collect --loop --interval=45
