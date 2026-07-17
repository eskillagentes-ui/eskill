# Wizard de Clonagem por Concorrente (Seller)

Guia operacional do fluxo **seller → filtros → prévia de preço → job em massa**.

## Acesso

- **URL:** `/dashboard/catalog/clone-wizard`
- **API base:** `/api/catalog/clone/source/seller/...` e `POST /api/catalog/clone/jobs/seller`
- **Worker:** `bin/catalog-clone-worker.php` (cron a cada minuto)

## Fluxo recomendado

1. Informe o **Seller ID** público do concorrente e clique em **Buscar loja**.
2. Revise o resumo (nickname, reputação, totais por categoria/marca).
3. Filtre por **categoria**, **marca** ou busca textual.
4. Selecione itens (por categoria, marca ou todos).
5. Configure **estratégia de preço** e use **Prévia de preço** antes de confirmar.
6. Confirme guardrails (descrição/imagens **desligados por padrão**).
7. Acompanhe o job em **Métricas / jobs** ou na tela de progresso do wizard.

## Modo seguro (padrão)

| Campo | Padrão | Observação |
|-------|--------|------------|
| `include_description` | `false` | Descrição não é copiada sem confirmação explícita |
| `include_pictures` | `false` | Imagens não são copiadas automaticamente |
| Estrutura/atributos públicos | `true` | Necessários para criar anúncio válido no ML |

## Limites operacionais

- Respeite **rate limit** da API Mercado Livre; o worker aplica backoff.
- Jobs grandes usam **snapshot opcional** do catálogo do seller (`seller_catalog_snapshots`) com TTL.
- **Idempotência:** mesmo par origem→destino não é re-clonado se já existir vínculo ativo.
- Paginação da listagem: use filtros para evitar selecionar volumes acima do limite do job.

## Endpoints principais

| Método | Rota | Uso |
|--------|------|-----|
| GET | `/api/catalog/clone/source/seller/search` | Busca seller por nickname/ID |
| GET | `/api/catalog/clone/source/seller/{id}/summary` | Resumo + facets |
| GET | `/api/catalog/clone/source/seller/{id}/items` | Listagem paginada |
| POST | `/api/catalog/clone/price-preview` | Simulação de preços |
| POST | `/api/catalog/clone/jobs/seller` | Cria job em massa |

## Troubleshooting

| Sintoma | Causa provável | Ação |
|---------|----------------|------|
| Seller não encontrado | ID inválido ou loja privada | Confirme o ID na URL pública do ML |
| Job `pending` eternamente | Worker/cron parado ou DB offline | Verifique `cron-catalog-clone.log` e MySQL |
| Muitos itens `failed` | Token destino expirado ou categoria bloqueada | Reconecte conta destino; revise logs do item |
| Preço diferente da prévia | Arredondamento ML ou promo ativa | Reexecute prévia com mesma estratégia |
| Duplicados ignorados | Idempotência | Esperado; revise `catalog_clone_job_items` |

## Logs

- `storage/logs/cron-catalog-clone.log` — worker principal
- `storage/logs/cron-post-actions.log` — pós-ações (SEO, preço, ativação)
- `storage/logs/cron-recovery.log` — jobs travados (`--recover-stuck`)

## Boas práticas

- Clone primeiro um **lote pequeno** (10–20 itens) antes de milhares.
- Use conta destino com **token válido** e mesma vertical quando possível.
- Não marque cópia de descrição/imagens sem revisão jurídica/compliance.
- Monitore métricas em `/dashboard/catalog/clone-metrics`.
