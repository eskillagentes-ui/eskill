# Diagnóstico de exposição (Fe) — conta 1335

- **Gerado em:** 2026-08-02 (America/Sao_Paulo)
- **Script:** `bin/pregao-diagnostico-exposicao.php` (read-only; sem escrita ML)
- **Nickname / ml_user_id:** FACILYTY / 3058804121

## Resumo executivo

1. Hipótese principal (queda por inventário indisponível): **refutada como causa principal (na amostra)** — Maior parte da perda está em itens ainda active (Balde B=175.06 vs A=1.68). Os 80 `paused_by_seller` existem, mas no `time_window` de 35d quase não carregam visita (recuperável estimado ≈ 0 visita/dia).
2. Visitas/dia: média 7d = **180.43** · média 28d anteriores = **305.71** · queda = **125.28** visita/dia.
3. Decomposição: Balde A (indisponíveis) = **1.68** visita/dia (1 itens) · Balde B (ativos) = **175.06** (45 itens). Dois espelhos active (MLB6574414098 + MLB6574534100) sozinhos ≈ **144** visita/dia de perda.
4. Pausados live: **80** · `paused_by_seller` com estoque>0; visita/dia recuperável mensurável no time_window ≈ **0** (não confundir “pausado” com “explicação da queda”).
5. Inflexão (maior queda vs média móvel 3d): **2026-07-21**. Status histórico diário: **sem dado** em `account_health_history` (só scores; janela 2026-05-09 → 2026-08-03, 34 rows). `under_review` live = **0**.

## Fontes (rastreabilidade)

| Dado | Fonte |
|---|---|
| Visitas diárias conta | `GET /users/3058804121/items_visits?date_from=D&date_to=D` |
| Visitas por item | `GET /items/{mlb}/visits/time_window?last=35&unit=day` (somatório 7d vs dias 8–35). **Não** usar `/visits/items` com date_from/date_to — totais inconsistentes entre janelas |
| Status live | `GET /users/3058804121/items/search?status=` |
| Status/sub_status local | tabela `items` (account_id) + refresh `GET /items?ids=` lotes 20 |
| Vendas/receita | `ml_orders` WHERE ml_account_id |
| Health history | `account_health_history` (scores only) |
| Ações catálogo | `GET /items/{id}/health/actions` |

## Snapshot de status (agora)

```json
{
    "active": 127,
    "paused": 80,
    "under_review": 0,
    "closed": 0,
    "inactive": 0
}
```

Nota: `closed` via search pode retornar 0 mesmo com closed locais (API seller search). Contagem local `items`: ver query `SELECT status, COUNT(*) FROM items WHERE account_id=...`.

## Correlação

- **visitas × itens_active (histórica):** sem dado: account_health_history não armazena contagens diárias de status (active/paused/…); só overall_score. Correlação visitas×active não pode ser calculada na janela histórica.
- **visitas × vendas (mesmos dias):** -0.0895 (Pearson; não testa a hipótese de inventário)
- **Data de inflexão (série de visitas):** {"data":"2026-07-21","visitas":172,"media_3d_anterior":371.3,"queda_vs_media3d":199.3}

A ausência de série histórica de `itens_active` **impede** confirmar estatisticamente a correlação visitas↔active. A hipótese é julgada pela decomposição A/B e pelo perfil dos pausados (`paused_by_seller`).

## Baldes

| Balde | Definição | Itens | Visita/dia perdida |
|---|---|---:|---:|
| A | indisponível agora (paused/closed/inactive/under_review) com delta média < 0 | 1 | 1.68 |
| B | active com delta média < 0 | 45 | 175.06 |
| A+B | | | 176.74 |

Diferença queda_conta (125.28) − (A+B=176.74) = -51.46 visita/dia. Possíveis causas: itens closed fora da amostra de visits, visitas de itens não listados localmente, ou sobreposição de janelas/API. Não forçar fechamento.

## Top 20 perda (visita/dia)

| mlb_id | status | média 28d | média 7d | delta | balde | motivo |
|---|---|---:|---:|---:|---|---|
| MLB6574414098 | active | 100.321 | 8.429 | -91.892 | B | queda_em_ativo |
| MLB6574534100 | active | 52.571 | 0.143 | -52.428 | B | queda_em_ativo |
| MLB4439459663 | active | 12.857 | 8.286 | -4.571 | B | queda_em_ativo |
| MLB6654735702 | active | 4.036 | 0.143 | -3.893 | B | queda_em_ativo |
| MLB6654697302 | active | 18.25 | 14.429 | -3.821 | B | queda_em_ativo |
| MLB6654697288 | active | 3.679 | 0 | -3.679 | B | queda_em_ativo |
| MLB6574560004 | active | 5.714 | 3.714 | -2 | B | queda_em_ativo |
| MLB6574413814 | inactive | 1.679 | 0 | -1.679 | A | status:waiting_for_patch |
| MLB4586759809 | active | 2.571 | 1 | -1.571 | B | queda_em_ativo |
| MLB4586792667 | active | 7.071 | 5.857 | -1.214 | B | queda_em_ativo |
| MLB4586883431 | active | 2.286 | 1.286 | -1 | B | queda_em_ativo |
| MLB6187858992 | active | 0.964 | 0.143 | -0.821 | B | queda_em_ativo |
| MLB6574499438 | active | 0.786 | 0 | -0.786 | B | queda_em_ativo |
| MLB6654685402 | active | 4.679 | 4 | -0.679 | B | queda_em_ativo |
| MLB6654685380 | active | 0.786 | 0.143 | -0.643 | B | queda_em_ativo |
| MLB4435126877 | active | 0.893 | 0.286 | -0.607 | B | queda_em_ativo |
| MLB6526494930 | active | 2.607 | 2 | -0.607 | B | queda_em_ativo |
| MLB4421410197 | active | 0.571 | 0 | -0.571 | B | queda_em_ativo |
| MLB4440133313 | active | 0.607 | 0.143 | -0.464 | B | queda_em_ativo |
| MLB4564173029 | active | 0.857 | 0.429 | -0.428 | B | queda_em_ativo |

CSV completo: `storage/diagnostico/2026-08-02/top20-perda.csv`

## waiting_for_patch / health/actions

| mlb_id | status | sub_status | média 28d | health/actions |
|---|---|---|---:|---|
| MLB6574426300 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574413814 | inactive | waiting_for_patch,deleted | 1.679 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574413712 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574533960 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574474638 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574414192 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574439602 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574439628 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574474974 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574427040 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB4586779589 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574452980 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574535112 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574572760 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574488400 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6574488416 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB4586760165 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB4586780367 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6654722926 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |
| MLB6654685366 | closed | waiting_for_patch,deleted | 0 | API erro: Items with buying mode 'buy_it_now' are not allowed |

Obs.: `under_review` via search live = 0. Itens com `waiting_for_patch`+`deleted` não são o mesmo conjunto que under_review operacional.

## Pausados: recuperáveis vs bloqueio ML

| Grupo | Qtd (amostra com visits) | Visita/dia (média 28d) |
|---|---:|---:|
| `paused_by_seller` (recuperável pelo seller) | 80 | 0 |
| outros sub_status / bloqueio | 0 | 0 |
| paused com estoque 0 | 0 | — |

## Plano de recuperação (NÃO EXECUTAR)

1. **Investigar top ativos com queda (preço/CTR/estoque) — sem alterar ainda** — visita/dia≈156.61 · esforço=médio (análise por SKU) · itens: MLB6574414098, MLB6574534100, MLB4439459663, MLB6654735702, MLB6654697302  \n   Passo: Comparar preço/promo vs. histórico interno e health do item; só então propor ajuste

## Conclusão da hipótese

**Veredito: refutada (na amostra).** Maior parte da perda está em itens ainda active (Balde B=175.06 vs A=1.68).

Hipóteses alternativas:
- **Sazonalidade:** série diária em CSV — inspecionar padrão semanal; não modelado formalmente aqui.
- **Concentração em poucos SKUs:** ver top 20 — se poucos MLB dominam `perda_media_dia`, a queda é concentrada.
- **Preço/promo/CTR:** histórico de preço/CTR **sem dado** nesta corrida (não há série de CTR local citada); marcado como lacuna.
- **Variação natural do baseline:** Fe=0,70 está dentro do clamp; a queda ~30% vs baseline 28d é material, não ruído trivial.

## Artefatos

- `/home/eskill/htdocs/eskill.com.br/docs/diagnostico-exposicao-2026-08-02.md`
- `storage/diagnostico/2026-08-02/serie-diaria.csv`
- `storage/diagnostico/2026-08-02/top20-perda.csv`
- `storage/diagnostico/2026-08-02/itens-perda-completa.csv`
- `storage/diagnostico/2026-08-02/waiting-for-patch.csv`
