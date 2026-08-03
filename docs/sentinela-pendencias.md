# Sentinela — pendências

**Data:** 2026-08-03

## S5 — Watchlist populada

**Status:** pendente

**Motivo:** CSV do dono `mlb_id,apelido,keyword_alvo` ainda não disponível no workspace.
CLI existente: `bin/pregao-watchlist.php`. Tabelas `competitor_items` / `competitor_item_snapshots` vazias.
Não inventar concorrentes.

Quando o CSV chegar:

```bash
php bin/pregao-watchlist.php --account-id=1335 --import=/caminho/concorrentes.csv
php bin/pregao-watchlist.php --account-id=1335 --collect
```

Alertas esperados na fita: preço >5% · pause/estoque zero · aceleração de `sold_quantity`.

## NF pendente

**Status:** fora de escopo (exibido como `n/d — aguardando definição do emissor`)

Aguarda decisão do dono sobre emissor/ERP.
