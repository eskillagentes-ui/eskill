# Pregão — backfill de proveniência (P2 / 2026-08-03)

## Problema

A migration `2026_08_03_pregao_fase2_provenance.sql` criou `pregao_events.source`
com `DEFAULT 'live'` **sem backfill**. Eventos do smoke `pregao_emit_sale`
(documentado em `docs/qa/PREGAO_HERMES_REPORT.md`) ficaram marcados como `live`,
contaminando o histórico do índice.

## Critério de marcação `source='seed'`

Um evento é marcado como seed se **qualquer** condição for verdadeira:

1. `payload` contém `Teste Hermes` (título do smoke Hermes)
2. `payload` contém `"order_id":"T…` (IDs sintéticos `T1`, `T99`, …)
3. `payload` contém `"sku":"MLB1"` (SKU do exemplo documentado)
4. Interseção da janela **2026-08-02 00:00:00 ≤ ts < 2026-08-03 00:00:00** (BRT)
   com os padrões acima (deploy Fase 2 + smoke)

Implementação: `App\Services\Pregao\PregaoProvenanceService::smokeMatchSql()`
e `bin/pregao-backfill-seed.php`.

## Constraint

Após o backfill, `source` perde o `DEFAULT` silencioso:

```sql
ALTER TABLE pregao_events MODIFY COLUMN `source` varchar(32) NOT NULL;
```

Novos INSERTs (via `PregaoEmitService`) sempre enviam `source` explicitamente.

## Recálculo do índice

`recalculateDailyExcludingSeed($accountId)`:

1. Lê candle `account_index_daily` atual (before)
2. Roda `AccountIndexService::tick()` com métricas live
3. Registra log `before_c` / `after_c` / `delta`

Snapshot e fita já filtram `source <> 'seed'` quando `PREGAO_SEED=false`.

## Contagem (produção 2026-08-03)

| Momento | seed | live |
|---------|------|------|
| Antes do backfill | 0 | 1674 |
| Depois | 1 | 1675+ |

- `seed_marked` (payload smoke): **0** — vendas sintéticas `Teste Hermes` já tinham sido
  purgadas; nenhum row restante batia o padrão.
- `seed_total=1` via **evento de auditoria** `robot=PROVENANCE` inserido pelo script
  (rastreia a execução do backfill).
- Recálculo do candle `2026-08-02`: `before_c=957.2764` → `after_c=957.2764` (`delta=0`) —
  o índice já refletia só métricas live (vendas_hoje=0); a divergência esperada era nula
  e ficou documentada no log.

```bash
php bin/pregao-backfill-seed.php --account-id=1335
# ou dry-run:
php bin/pregao-backfill-seed.php --dry-run
```

O teste `PregaoProvenanceServiceTest` garante `seed_total > 0` e log de divergência explicável.
