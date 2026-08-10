#!/bin/bash
#
# backup-rotate.sh — Retenção de dumps MySQL em storage/backups/
#
# Política (pós-merge 2026-08-10):
#   1. Manter como .sql os 3 dumps ÍNTEGROS mais recentes ("Dump completed").
#   2. Marcos de onda (primeiro dump íntegro de cada): onda1, onda2, onda3,
#      onda3.1, onda2.1, onda3.5, merge — se fora do top-3, manter só .sql.gz.
#   3. Dumps incompletos e excesso: gzip -9; remove .sql só após .gz válido (>1MB + gzip -t).
#   4. Nunca apagar o dump íntegro mais recente.
#   5. Dry-run: bash bin/backup-rotate.sh --dry-run
#
# Cron semanal (domingo 04:30):
#   30 4 * * 0 cd /home/eskill/htdocs/eskill.com.br && bash bin/backup-rotate.sh >> storage/logs/backup-rotate.log 2>&1
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
BACKUP_DIR="${BACKUP_DIR:-$PROJECT_DIR/storage/backups}"
DRY_RUN=0
KEEP_RECENT=3

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --help|-h) sed -n '2,18p' "$0"; exit 0 ;;
  esac
done

ts() { date -u +'%Y-%m-%dT%H:%M:%SZ'; }
log() { echo "[$(ts)] $*"; }

is_complete_sql() {
  tail -n 5 "$1" 2>/dev/null | grep -q 'Dump completed'
}

milestone_of() {
  case "$1" in
    *onda3.1*|*onda3-1*|*pre-onda3.1*|*backup-pre-onda3.1*) echo onda3.1 ;;
    *onda2.1*|*onda2-1*|*pre-onda2.1*) echo onda2.1 ;;
    *onda3.5*|*onda3-5*|*pre-onda3.5*) echo onda3.5 ;;
    *pre-merge-master*|*merge-master*) echo merge ;;
    *pre_onda1*|*pre-onda1*|*backup_pre_onda1*) echo onda1 ;;
    *pre_onda2*|*pre-onda2*|*backup_pre_onda2*) echo onda2 ;;
    *pre_onda3*|*pre-onda3*|*backup_pre_onda3*) echo onda3 ;;
    *) echo "" ;;
  esac
}

compress_one() {
  local f="$1"
  local reason="${2:-compress}"
  local gz="${f}.gz"

  [[ -f "$f" ]] || return 0

  if [[ -f "$gz" ]] && gzip -t -- "$gz" 2>/dev/null; then
    local gzsize
    gzsize=$(stat -c%s -- "$gz")
    if (( gzsize >= 1000000 )); then
      log "REMOVE .sql (gz válido) [$reason]: $(basename "$f")"
      [[ $DRY_RUN -eq 0 ]] && rm -f -- "$f"
      return 0
    fi
  fi

  log "COMPRESS [$reason]: $(basename "$f")"
  if [[ $DRY_RUN -eq 1 ]]; then
    return 0
  fi
  gzip -9 -c -- "$f" > "${gz}.tmp"
  mv "${gz}.tmp" "$gz"
  if ! gzip -t -- "$gz"; then
    log "ERRO gzip inválido — mantendo .sql"
    rm -f -- "$gz"
    return 1
  fi
  local gzsize
  gzsize=$(stat -c%s -- "$gz")
  if (( gzsize < 1000000 )); then
    log "ERRO gzip pequeno ($gzsize) — mantendo .sql"
    rm -f -- "$gz"
    return 1
  fi
  rm -f -- "$f"
  log "OK $(basename "$gz") ($(du -h "$gz" | awk '{print $1}'))"
}

log "=== backup-rotate start dry_run=$DRY_RUN ==="
df -h / | tail -1 | awk '{print "[df] "$0}'

mapfile -t SQL_DESC < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name '*.sql' -printf '%T@\t%p\n' | sort -nr | cut -f2-)
mapfile -t SQL_ASC < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name '*.sql' -printf '%T@\t%p\n' | sort -n | cut -f2-)

declare -A KEEP_SQL=()
declare -a COMPLETE=()

for f in "${SQL_DESC[@]}"; do
  if is_complete_sql "$f"; then
    COMPLETE+=("$f")
  else
    log "INCOMPLETE: $(basename "$f")"
  fi
done

n=0
for f in "${COMPLETE[@]}"; do
  if (( n < KEEP_RECENT )); then
    KEEP_SQL["$f"]=1
    log "KEEP_SQL recent#$((n+1)): $(basename "$f")"
    n=$((n + 1))
  fi
done

# Marcos fora do top-3 → comprimir (artefato fica .gz)
declare -A SEEN_MILESTONE=()
for f in "${SQL_ASC[@]}"; do
  is_complete_sql "$f" || continue
  m=$(milestone_of "$(basename "$f")")
  [[ -z "$m" ]] && continue
  [[ -n "${SEEN_MILESTONE[$m]:-}" ]] && continue
  SEEN_MILESTONE["$m"]=1
  if [[ -n "${KEEP_SQL[$f]:-}" ]]; then
    log "MILESTONE $m já no top-3: $(basename "$f")"
  else
    log "MILESTONE $m fora do top-3 → comprimir: $(basename "$f")"
  fi
done

# Execução
for f in "${SQL_DESC[@]}"; do
  [[ -f "$f" ]] || continue
  if [[ -n "${KEEP_SQL[$f]:-}" ]]; then
    # Top-3: se já tem .gz duplicado, NÃO remove o .sql (precisamos do íntegro recente)
    continue
  fi
  if is_complete_sql "$f"; then
    compress_one "$f" "excess-or-milestone" || true
  else
    compress_one "$f" "incomplete" || true
  fi
done

log "=== resultado ==="
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name '*.sql' -o -name '*.sql.gz' \) -printf '%TY-%Tm-%Td %TH:%TM %10s %f\n' | sort
du -sh "$BACKUP_DIR"
df -h / | tail -1 | awk '{print "[df] "$0}'
log "=== backup-rotate done ==="
