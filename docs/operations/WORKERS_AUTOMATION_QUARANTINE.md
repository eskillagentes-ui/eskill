# Workers em quarentena (ADR-003)

**Data:** 2026-07-17  
**Flag:** `ML_WRITE_AUTOMATION` (default `false`)

Workers que **escrevem** no Mercado Livre ou disparam automação de clone/SEO/pricing devem sair cedo via `MlWriteAutomation::exitIfDisabledForCli()` enquanto a flag estiver desligada.

## Permitidos (leitura / sync / tokens)

| Worker | Motivo |
|--------|--------|
| `auto-token-refresh-worker.php` | Renovação OAuth |
| `orders-sync-worker.php` | Sync pedidos |
| `questions-sync-worker.php` | Sync perguntas |
| `items-sync-worker.php` | Sync itens |
| `shipments-sync-worker.php` | Sync envios |
| `stock-sync-worker.php` | Sync estoque (leitura/reconciliação — revisar se faz PUT) |
| `webhook-processor-worker.php` | Ingress |
| `bin/sync-irregularities.php` | Motor irregularidades **read-only** + SalesBlockerStore |

## Quarentena (escrita / automação)

| Worker | Risco |
|--------|-------|
| `pricing-worker.php` | Altera preços |
| `scheduled-price-worker.php` | Preços agendados |
| `catalog-clone-worker.php` | Clone |
| `clone-automation-worker.php` | Auto-clone |
| `clone-sync-worker.php` | Sync clone |
| `clone-scheduler-worker.php` | Agenda clone |
| `bulk-seo-worker.php` | SEO em massa |
| `seo-worker.php` | SEO automático |
| `ml-ai-optimization-worker.php` | Otimização AI escrita |
| `awa-sellers-scan-worker.php` | Scan lateral (ADR-003 arquivar) |

## Cron recomendado (Facility)

```cron
# Irregularidades → fila persistida (read-only ML)
*/30 * * * * php /home/eskill/htdocs/eskill.com.br/bin/sync-irregularities.php --all-active --actor-user-id=1 >> /home/eskill/htdocs/eskill.com.br/storage/logs/sync-irregularities.log 2>&1
```

Substitua `--actor-user-id` pelo owner real da conta Facility, ou use o modo `--all-active` (autoriza cada conta com seu `user_id` dono).

## Ativar escrita (só com aprovação humana)

```bash
# .env
ML_WRITE_AUTOMATION=true
```

Sem essa flag, apply* e workers de quarentena não executam escrita.
