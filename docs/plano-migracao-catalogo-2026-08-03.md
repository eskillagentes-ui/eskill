# Plano de migração controlada de catálogo — triagem dos 22 itens

**Data:** 2026-08-03T06:34:24-03:00  
**Conta:** FACILYTY · account_id `1335` · seller `3058804121`  
**Escopo:** 18 pendências históricas + 4 catálogos novos além dos dois sucessores tratados na Parte 0  
**Segurança:** somente GET/SELECT; nenhuma promoção, Ads, Full, opt-in, preço ou outro write

## Resumo executivo

- **Mecânica:** não há uma resposta única “opcional ou obrigatória”. Item apenas elegível (`READY_FOR_OPTIN`) é opt-in; produto `listing_strategy=catalog_required` com `catalog_forewarning` tem prazo e será moderado por `OPT_OBEY` se não publicar no catálogo. Domínio `catalog_only` não permite manter o tradicional.
- **Nesta amostra:** 17 produtos consultáveis são `catalog_required`; entre os 18 legados, 13 têm prazo expirado e 5 não têm data definida. Hoje são 17 `closed` e 1 `inactive`: não são 18 migrações futuras acionáveis.
- **Faixas:** **A=2 · B=2 · C=18**. Os dois A já migraram e perdem buy box; não existe item ativo pré-migração na Faixa A dentro desses 22.
- **Financeiro:** todos os 22 têm 0 venda e R$0 de receita em 90 dias; `product_costs` tem 0 linhas para eles, e `cost_price/min_price` estão vazios. Margem e preço mínimo de combo são **sem dado**.
- **Correção de premissa:** avaliações de catálogo podem ser do produto, não do item. `MLB7313976854` já mostra 242 avaliações (4,5) e `MLB7313976860`, 40 (4,7); portanto a migração não “zera avaliações” universalmente.

## Tarefa 1 — mecânica documentada da migração

### O que é opcional e o que é obrigatório

1. **Elegível:** `catalog_listing_eligible`/`READY_FOR_OPTIN` significa que o anúncio pode ser publicado no catálogo; a elegibilidade isolada não prova prazo.
2. **Obrigatório:** quando o produto tem `settings.listing_strategy=catalog_required`, o vendedor deve publicar no catálogo; a publicação tradicional pode permanecer opcional.
3. **Exclusivo:** em `catalog_only`, a venda é exclusiva no catálogo e o tradicional é inativado.
4. **Pré-aviso e prazo:** `catalog_forewarning` identifica a exigência e `GET /items/{id}/catalog_forewarning/date` retorna a data de moderação. Depois do prazo, o item pode ser moderado por `OPT_OBEY`.
5. **Criação automática:** a documentação reconhece itens criados automaticamente pelo ML e recomenda identificá-los pela tag `catalog_boost`; os seis novos observados localmente não exibem essa tag no snapshot atual, então a autoria automática individual permanece inferência dos dados, não prova pela tag.

### Estados observados

- `waiting_for_patch`: estado genérico de moderação — item pausado porque precisa de modificação. **Não é sinônimo oficial de catálogo.** Nos 18, o cruzamento com `catalog_required` e prazo expirado é que liga o caso ao catálogo.
- `OPT_OBEY`: filtro oficial de moderação de catálogo; solução documentada: criar a publicação no catálogo.
- “Aceitar catálogo no ML”: wording de interface não definido na documentação da API; operacionalmente parece opt-in, mas o mapeamento exato é **sem dado**.

### Recusar, postergar e aceitar antes

- **Recusar:** não há opt-out documentado para `catalog_required`/`catalog_only`; a documentação de sincronização também diz que o vendedor não pode eliminar a sincronização.
- **Postergar:** apenas até a `moderation_date` quando existe pré-aviso; não há endpoint documentado para mudar o prazo.
- **Aceitar antes:** evita chegar à moderação e permite escolher o marco de lançamento. A documentação diz que condições de venda/campanhas são sincronizadas, mas **não promete preservar visitas, ranking ou vendas**. Logo, vantagem de histórico: **sem dado**.

**Fontes oficiais:** [Publicações requeridas no catálogo](https://developers.mercadolivre.com.br/pt_br/publicacoes-necessarias-do-catalogo), [Elegibilidade](https://developers.mercadolivre.com.br/pt_br/elegibilidade-de-catalogo), [Publicar no catálogo](https://developers.mercadolivre.com.br/pt_br/publicacao-no-catalogo), [Moderações](https://developers.mercadolivre.com.br/pt_br/gerenciar-moderacoes).

## Tarefa 2 — ficha dos 22 itens

| faixa | MLB | status/substatus | visitas 28d | em jogo | vendas/receita 90d | avaliações | sucessor / buy box | margem | kit? |
|---|---|---|---:|---:|---|---|---|---|---|
| A | `MLB7313976860` | active/— | 0,036 | 5,118 | 0 / R$ 0,00 | 40 (4,7) | já é sucessor / competing | sem dado | não |
| A | `MLB7313976854` | active/— | 0,107 | 4,079 | 0 / R$ 0,00 | 242 (4,5) | já é sucessor / competing | sem dado | não |
| B | `MLB6574413814` | inactive/waiting_for_patch,deleted | 1,357 | 2,211 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| B | `MLB7313976836` | under_review/forbidden | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | já é sucessor / not_listed | sem dado | não |
| C | `MLB4586760165` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB4586779589` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB4586780367` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | sim |
| C | `MLB6574413712` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB6574414192` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB6574426300` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | sim |
| C | `MLB6574427040` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | sim |
| C | `MLB6574439602` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB6574439628` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB6574452980` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB6574474638` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB6574474974` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB6574488400` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB6574488416` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB6574533960` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | MLB7313976854 (mesma família; vínculo direto não provado) / competing | sem dado | não |
| C | `MLB6574535112` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | sim |
| C | `MLB6574572760` | closed/waiting_for_patch,deleted | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | não confirmado / — | sem dado | não |
| C | `MLB7313977102` | active/— | 0,000 | 0,000 | 0 / R$ 0,00 | 0 (0,0) | já é sucessor / winning | sem dado | não |

CSV completo e justificativa individual: `docs/qa/plano-migracao-catalogo-2026-08-03/fila-priorizada-migracao-catalogo.csv`.

## Tarefa 3 — faixas e ordem de trabalho

### 🔴 Faixa A — 2 itens (recuperar/proteger)

- `MLB7313976860`: **5,118 visitas/dia em jogo**; já migrou, perde buy box e está 109,84% acima do `price_to_win`.
- `MLB7313976854`: **4,079 visitas/dia em jogo**; já migrou, perde buy box e está 56,64% acima do `price_to_win`.

Como ambos já migraram, “criar combo antes” não recupera o histórico deles. A ação coerente é protocolo de lançamento no sucessor + avaliar um anúncio de pack realmente distinto para a família, sempre após custo e compatibilidade.

### 🟡 Faixa B — 2 itens

- `MLB6574413814`: **2,211 visitas/dia históricas em jogo**, prazo expirado em 21/07, agora inativo; exige decisão de recuperar ou arquivar.
- `MLB7313976836`: zero visita, mas bloqueio operacional `under_review/forbidden`; a infração atual é `DUPLIS`.

### 🟢 Faixa C — 18 itens

16 pendências legadas sem tráfego/venda + `MLB7313977102` (ganhando, família cresceu) + o legado residual sem risco mensurável. Não investir trabalho manual antes de novo sinal.

## Tarefa 4 — candidatos a kit/combo

> A documentação não garante que kit/combo seja “não migrável”. Um pack físico legítimo pode não ter correspondência hoje, mas pode ser catalogado futuramente; duplicatas artificiais podem sofrer `DUPLIS`.

| prioridade | conceito | itens/estoque limitante | soma dos preços atuais | preço sugerido | margem | validação |
|---:|---|---|---:|---|---|---|
| 1 | 2× Espelho porta/madeira 100×40 | `MLB6654735702 ×2` / até 597 packs | R$ 391,60 | **sem dado**: `min_price` ausente | **sem dado**: custo ausente | Pack físico de 2 unidades; confirmar embalagem/peso. |
| 2 | 2× Espelho decorativo 100×40 | `MLB6654697288 ×2` / até 631 packs | R$ 391,60 | **sem dado**: `min_price` ausente | **sem dado**: custo ausente | Pack físico de 2 unidades; não anunciar como unidade simples. |
| 3 | Espelho porta/madeira + kit 4 botões | `MLB6654735702 + MLB4646267315` / 143 | R$ 204,80 | **sem dado**: `min_price` ausente | **sem dado**: custo ausente | Compatibilidade e conteúdo físico precisam de confirmação do dono. |

Não publicar nenhum combo antes de: cadastrar custo e preço mínimo por SKU; validar peso/frete; confirmar compatibilidade; garantir que o pacote físico entregue corresponde ao título.

## Tarefa 5 — dois perdendo buy box + under_review

| sucessor | destaque | nosso preço | `price_to_win` | diferença | boosts faltantes | reputação/logística | o que falta |
|---|---:|---:|---:|---:|---|---|---|
| `MLB7313976854` | `MLB3984597627` · R$127,00 | R$195,80 | R$125,00 | +56,64% | Full, juros zero, same-day | FACILYTY `5_green/xd_drop_off` × vencedor `5_green/gold/cross_docking` | Principal: gap de preço; secundário: seller gold/logística. 5 ofertas. |
| `MLB7313976860` | `MLB6809277534` · R$100,00 | R$195,80 | R$93,31 | +109,84% | Full, juros zero, same-day | ambos `5_green`; vencedor `fulfillment` + same-day | Gap de preço + Full/same-day. 2 ofertas. |

`MLB7313976836` não está perdendo buy box: está fora da disputa. A infração exata é **DUPLIS — “É igual a outro anúncio”**; `price_to_win` responde `not_listed/item_not_opted_in`. `health/actions` retornou 404 e a infração não traz remedy, então a ação exata é **sem dado**. Não criar outra duplicata; revisar no painel qual anúncio foi considerado igual.

## DÚVIDAS PARA O DONO

1. Fornecer custo e preço mínimo dos dois espelhos e do kit de fixação; sem isso combo, ROAS break-even e margem são incalculáveis.
2. Confirmar fisicamente se o kit de botões é compatível com o espelho porta/madeira e se acompanha tudo que o título prometeria.
3. Decidir se `MLB6574413814` deve ser recuperado ou arquivado; ele é o único legado com tráfego histórico mensurável.
4. Abrir a moderação de `MLB7313976836` no painel para identificar o anúncio duplicado indicado pelo ML.

## Rastreabilidade

- GETs: `/items/{id}`, `/reviews/item/{id}`, `/items/{id}/catalog_listing_eligibility`, `/items/{id}/catalog_forewarning/date`, `/products/{id}`, `/moderations/infractions/{seller}`.
- Banco: `items`, `ml_orders`, `product_costs`, somente `SELECT`.
- Evidência externa: `/root/qa-evidence/plano-migracao-catalogo-2026-08-03/`.

**Contrato preservado:** zero escrita no ML, zero alteração de preço/promoção/Ads/Full, zero Redis, zero serviço parado, zero suíte Playwright e zero comando Git mutante.
