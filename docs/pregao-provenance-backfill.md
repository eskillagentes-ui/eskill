# Pregão — backfill de proveniência (P2 / 2026-08-03)

## Problema

A migration `2026_08_03_pregao_fase2_provenance.sql` criou `pregao_events.source`
com `DEFAULT 'live'` **sem backfill**. Eventos do smoke `pregao_emit_sale`
(documentado em `docs/qa/PREGAO_HERMES_REPORT.md`) poderiam ficar marcados como
`live`, prejudicando a proveniência, a fita e a auditoria dos eventos.

Isso **não contaminou o cálculo do ESKL11**: o índice não lê `pregao_events`.
O P2 é uma correção de higiene e rastreabilidade da trilha de eventos.

## Critério de marcação `source='seed'`

Um evento é marcado como seed se **qualquer** condição for verdadeira:

1. `payload` contém `Teste Hermes` (título do smoke Hermes)
2. `payload` contém `"order_id":"T…` (IDs sintéticos `T1`, `T99`, …)
3. `payload` contém `"sku":"MLB1"` (SKU do exemplo documentado)
4. Interseção da janela **2026-08-02 00:00:00 ≤ ts < 2026-08-03 00:00:00** (BRT)
   com os padrões acima (deploy Fase 2 + smoke)

Implementação: `App\Services\Pregao\PregaoProvenanceService::smokeMatchSql()`
e `bin/pregao-backfill-seed.php`.

**Critério efetivamente implementado:** a janela declarada é
`2026-08-02 00:00:00 <= ts < 2026-08-03 00:00:00` BRT, mas as três assinaturas
de payload são ligadas por `OR` fora da condição temporal. Assim, qualquer evento
histórico com uma dessas assinaturas é candidato, independentemente da data. O ramo
que também verifica `type IN ('sale','op','metric.update')` e a janela é redundante.
A migration SQL também aplica somente as assinaturas, sem filtro por `ts`. Não há
conversão explícita de timezone no SQL; a indicação BRT é documental.

Na execução de 2026-08-03 nenhuma linha existente foi alterada (`seed_marked=0`),
mas o critério efetivo é mais amplo que a janela declarada. Ele deve ser estreitado
antes de reutilizar o backfill em outra base.

## Constraint

Após o backfill, `source` perde o `DEFAULT` silencioso:

```sql
ALTER TABLE pregao_events MODIFY COLUMN `source` varchar(32) NOT NULL;
```

Novos INSERTs (via `PregaoEmitService`) sempre enviam `source` explicitamente.

## Fonte real do ESKL11

`AccountIndexService::tick()` lê exclusivamente:

- `account_index_metrics`: `vendas_7d`, `posicao_media`, `health_medio`,
  `reputacao_cor` e `tacos`;
- `account_index_baselines`: baselines de vendas, posição e TACOS.

O coletor calcula `vendas_7d` e seu baseline a partir de `ml_orders`, não de
`pregao_events`. A calculadora pura recebe esses valores e não consulta tabelas.

`pregao_events` é usado para persistência/stream da fita e, no coletor, para derivar
`acoes_hora`. Essa métrica não participa da fórmula ESKL11. O helper
`pregao_emit_sale` também incrementa `vendas_hoje`, `receita_hoje` e `ticket_medio`,
mas nenhum desses três campos é fator do índice; o coletor depois os reconcilia com
`ml_orders`.

Logo, eventos `source='seed'` nunca entraram diretamente no ESKL11. O P2 não remove
contaminação matemática do índice; corrige somente proveniência, filtros da fita e
auditabilidade.

## Verificação do candle

`recalculateDailyExcludingSeed($accountId)`:

1. Lê candle `account_index_daily` atual (before)
2. Roda o `AccountIndexService::tick()` normal, usando as métricas atuais
3. Registra log `before_c` / `after_c` / `delta`

Apesar do nome legado, esse método não filtra `pregao_events` durante o cálculo. O
delta zero observado é o resultado esperado da arquitetura baseada em métricas.

Snapshot e fita filtram `source <> 'seed'` quando `PREGAO_SEED=false`.

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
