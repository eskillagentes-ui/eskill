#!/usr/bin/env bash
# Pipeline noturno AWA Sellers: scan curto + deep catalog + residual reclassify.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
LOG_DIR="$ROOT/storage/logs"
mkdir -p "$LOG_DIR"
STAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
LOG="$LOG_DIR/awa-sellers-nightly.log"

echo "[$STAMP] AWA nightly start" >>"$LOG"

/usr/bin/php bin/awa-sellers-scan-worker-runtime.php --verbose >>"$LOG" 2>&1 || true
/usr/bin/php bin/awa-catalog-deep-scan.php --account=1335 --use-default-plans --max-products=400 --enrich=150 >>"$LOG" 2>&1 || true
/usr/bin/php bin/awa-official-store-seed.php --account=1335 --max-per-query=10 --enrich=30 >>"$LOG" 2>&1 || true
/usr/bin/php bin/awa-residual-reclassify.php --account=1335 --limit=500 >>"$LOG" 2>&1 || true
/usr/bin/php bin/awa-residual-alerts.php --account=1335 --days=14 >>"$LOG" 2>&1 || true

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] AWA nightly done" >>"$LOG"
