# Diagnóstico de perguntas — recebidas × respondidas

- **Gerado em:** 2026-08-03 00:01:46 -03:00
- **Conta:** 1335 / seller_id `3058804121` (FACILYTY)
- **Fontes:** API ML `GET /questions/search` (read-only) + `ml_questions` local (cruzamento)
- **Escrita ML:** nenhuma (`ML_WRITE_AUTOMATION=false`)

## Janela

| Escopo | De | Até | Observação |
|--------|----|-----|------------|
| Solicitada | 2026-05-05 | 2026-08-03 | 90 dias |
| Disponível na API | 2026-01-23 | 2026-07-24 | 67 perguntas ANSWERED; **0 UNANSWERED** |
| Local `ml_questions` | 2026-03-28 | 2026-07-24 | 45 linhas (atraso de sync vs API) |

> Série diária abaixo usa a **janela de 90 dias solicitada**, filtrando as 31 perguntas da API cujo `date_created` cai nela. Dias sem pergunta aparecem com 0.

## 1. Série diária (90 dias)

| data | recebidas | respondidas | não_respondidas_ainda | tempo_medio_resposta |
|------|-----------|-------------|------------------------|----------------------|
| 2026-05-05 | 1 | 1 | 0 | 2min |
| 2026-05-11 | 1 | 1 | 0 | 10min |
| 2026-05-30 | 1 | 1 | 0 | 1d8h |
| 2026-05-31 | 2 | 2 | 0 | 2min |
| 2026-06-01 | 3 | 3 | 0 | 16min |
| 2026-06-03 | 1 | 1 | 0 | 1h07 |
| 2026-06-04 | 1 | 1 | 0 | 6h49 |
| 2026-06-07 | 1 | 1 | 0 | 7min |
| 2026-06-08 | 3 | 3 | 0 | 24min |
| 2026-06-09 | 1 | 1 | 0 | 3min |
| 2026-06-11 | 1 | 1 | 0 | 29min |
| 2026-06-12 | 1 | 1 | 0 | 6min |
| 2026-06-17 | 1 | 1 | 0 | 4h30 |
| 2026-06-19 | 2 | 2 | 0 | 7h34 |
| 2026-06-21 | 3 | 3 | 0 | 8h07 |
| 2026-07-05 | 1 | 1 | 0 | 1h58 |
| 2026-07-07 | 2 | 2 | 0 | 1h03 |
| 2026-07-10 | 1 | 1 | 0 | 2d3h |
| 2026-07-13 | 1 | 1 | 0 | 8h46 |
| 2026-07-16 | 2 | 2 | 0 | 36min |
| 2026-07-24 | 1 | 1 | 0 | 1h33 |

_Dias sem perguntas omitidos (69 de 90 com zero). Soma recebidas na janela = 31._

## 2. Números-chave (janela 90 dias)

| Métrica | Valor |
|---------|-------|
| Recebidas | **31** |
| Respondidas | **31** |
| Taxa de resposta | **100%** (respondidas/recebidas) |
| Abertas AGORA (API `UNANSWERED`) | **0** |

### Comparativo 30 dias (mesmo recorte do card atual)

| Métrica | Valor |
|---------|-------|
| Recebidas / respondidas | 8 / 8 (100%) |
| Mediana | 1h35 (5733s) |
| Média (secundária) | 8h21 (30119s) — distorcida pelo outlier |
| Maior atraso | 2d3h |

## 3. Distribuição do tempo de resposta (90 dias, n=31)

| Estatística | Segundos | Humano | Papel |
|-------------|----------|--------|-------|
| **Mediana** | 1800 | **30min** | **principal** |
| p90 | 31563 | 8h46 | |
| Máximo | 184645 | 2d3h | outlier ~51h (2026-07-10, MLB6574414098) |
| Média | 17948 | 4h59 | secundária (enviesada) |

## 4. Concentração por anúncio (90 dias)

| mlb_id | recebidas | sem resposta | status item | título |
|--------|-----------|--------------|-------------|--------|
| MLB6654697330 | 5 | 0 | active | Espelho Para Colar Guarda Roupas 100x40 Corpo Inteiro Closet Espelho |
| MLB6574414098 | 4 | 0 | active | Espelho Grande De Parede Para Corpo Inteiro 100x40cm Ofertas Espelho E |
| MLB6526494930 | 3 | 0 | active | Guidão Honda Cg 160 Titan Fan Start 2025 2026 Com Rosca Cro Cromado |
| MLB4439459663 | 3 | 0 | active | Bagageiro Cg Fan Titan Start 160 2025 2026 Metal Reforçado Preto |
| MLB6574499804 | 2 | 0 | active | Espelho Guarda Roupas Porta 100x40 Corpo Inteiro Closet Espelho |
| MLB6526483148 | 2 | 0 | active | Cavalete Descanso Lateral Biz 100 Pop 100 110 Dream |
| MLB6574534100 | 2 | 0 | active | Espelho Grande De Parede 100x40 Corpo Inteiro Quarto Closet Espelho |
| MLB4421410197 | 1 | 0 | active | Cavalete Descanso Lateral Pezinho Moto Titan Fan 125 150 160 |
| MLB6654697288 | 1 | 0 | active | Espelho Decorativo 100x40 Parede Quarto Sala Corpo Inteiro Espelho |
| MLB6574413814 | 1 | 0 | inactive | Espelho Corpo Inteiro Parede Loja 100x40 Cm - Oferta Exclusi Botao |
| MLB6654735656 | 1 | 0 | active | Espelho Para Closet Meio Corpo + Suporte 100x40cm Botão Francês - Prat |
| MLB6574534646 | 1 | 0 | active | Espelho Sala Jantar Decorativo Retangular 100x40 Closet Hall Espelho |
| MLB4564060539 | 1 | 0 | active | Guidão Honda Titan Fan Start 160 2025 Com Rosca Awa Prateado Prateado |
| MLB4646291695 | 1 | 0 | closed | Espelho Box De Banho P/ Barbear Banheiro Sintex Não Tem |
| MLB6654697302 | 1 | 0 | active | Espelho Para Colar Porta Guarda Roupa 100x40 Corpo Inteiro Espelho |

> **Perguntas sem resposta acumuladas na janela:** 0 em todos os anúncios. Todos os itens do top estão `active`. Não há evidência atual de abandono por item pausado.

## 5. Perguntas abertas agora

**Lista vazia.** `GET /questions/search?seller_id=3058804121&status=UNANSWERED` → `total=0`.

Não há receita imediatamente parada em pergunta aberta neste instante. O risco operacional está no **atraso** (mediana 30d ≈ 1h35, pior caso 51h), não no abandono atual.

## 6. Padrão de conteúdo (temas, 90 dias)

| Tema | Contagem |
|------|----------|
| outros | 7 |
| material | 6 |
| medida/vão | 6 |
| prazo/frete | 4 |
| compatibilidade moto/modelo | 4 |
| instalação | 3 |
| reclamação | 1 |

> Quase não há perguntas de **compatibilidade pet** nesta amostra — o volume recente está em **espelhos** e **peças de moto** (compatibilidade de modelo, medida, frete).

## Achados para a hipótese “conta desassistida”

1. **Abandono agora: NÃO confirmado** — 0 abertas, taxa 100% nas janelas 30d e 90d.
2. **Lentidão: SIM, episódica** — mediana 90d = 30min; em 30d a mediana sobe para 1h35 e a média 8h21 é puxada por 1 outlier de ~51h.
3. **Sync local incompleto** — `ml_questions` tem 45 vs 67 na API; o card do Pregão hoje lê só o local → subconta o volume real.
4. **Entrega 2 (card)** deve preferir API (ou sync) + **mediana**, com recebidas×respondidas na janela 7d.

## Método

- Paginação `limit=50` em `/questions/search` até `total`.
- Taxa = respondidas / recebidas **na janela** (não respondidas/respondidas).
- Tempo = `answer.date_created - date_created` (só respondidas).
- Status de item via `GET /items/{id}` (read-only).

