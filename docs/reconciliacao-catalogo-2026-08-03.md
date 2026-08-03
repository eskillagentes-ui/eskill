# Reconciliação da migração de catálogo + buy box dos sucessores

**Data da coleta:** 2026-08-03T06:07:39-03:00  
**Conta:** FACILYTY · account_id `1335` · seller `3058804121`  
**Modo:** somente GET/SELECT; sem preço, opt-in, Ads, Redis, serviço ou Git mutante

## Resposta executiva

1. **O tráfego não apenas trocou de ID.** Nas duas famílias, a média caiu de **198,63 visitas/dia** (01–19/07) para **15,67/dia** (22/07–02/08): perda familiar de **182,96/dia (-92,1%)**. Os sucessores responderam por apenas **4,08/dia** quando normalizados na janela pós-migração.
2. **Da queda de 120,57 visitas/dia usada no diagnóstico da conta, o artefato por sucessores ausentes é 0,00/dia; os 120,57/dia são queda real no endpoint agregado da conta.** O Fe chama `GET /users/{seller_id}/items_visits`, que inclui todos os IDs automaticamente.
3. Os dois sucessores pedidos estão presentes em `items` e `ml_items`, atualizados nesta manhã. Não há defeito de sync para esses IDs.
4. Ambos os sucessores pedidos estão **ganhando** a buy box. Não falta nenhuma ação para ganhar hoje; Full, parcelamento sem juros e same-day aparecem apenas como oportunidades de resiliência.
5. A varredura encontrou **6 anúncios de catálogo criados desde 15/07**: os dois conhecidos + **4 outros**; estado atual: 3 ganhando, 2 perdendo e 1 `under_review/not_listed`.

## Tarefa 1 — reconciliação do tráfego

### 1.1 Presença local e método do Fe

| ID | `items` | `ml_items` | estado atual | catálogo | produto |
|---|---:|---:|---|---:|---|
| `MLB6574414098` | sim | sim | active | false | `MLB76055448` |
| `MLB6574534100` | sim | sim | active | false | `MLB70347111` |
| `MLB7297087912` | sim | sim | active | true | `MLB76055448` |
| `MLB7314817026` | sim | sim | active | true | `MLB70347111` |

`PregaoMetricsCollector.php:575-677` prova que o Fe não percorre `items`: ele busca `ml_user_id` e chama diretamente `/users/{seller}/items_visits` para as duas janelas. Assim, uma lista local desatualizada não excluiria sucessores do Fe.

### 1.2 Tabela solicitada

| período | visitas originais | visitas sucessores | soma | média soma/dia | delta vs. baseline |
|---|---:|---:|---:|---:|---:|
| 01/07–19/07 (antes, dias completos) | 3774 (198,63 /d) | 0 (0,00 /d) | 3774 | 198,63 | 0,00 /d (0,00%) |
| 22/07–02/08 (depois, dias completos) | 139 (11,58 /d) | 49 (4,08 /d) | 188 | 15,67 | -182,97 /d (-92,11%) |
| 22/07–03/08 06h (hoje parcial) | 139 (10,69 /d) | 50 (3,85 /d) | 189 | 14,54 | -184,09 /d (-92,68%) |

Para comparação, o endpoint agregado da conta caiu de **6.507/19 = 342,47 visitas/dia** para **2.338/12 = 194,83 visitas/dia** nesta janela fixa (delta -147,64/dia). Esse recorte não é o mesmo rolling 7d×28d que gerou `-120,57/dia`; ambos, porém, vêm da fonte agregada e já incluem os sucessores.

### 1.3 Quanto os sucessores recuperaram

- `MLB7297087912`: 47 visitas em 31/07–02/08, **15,67/dia desde o primeiro dia com visita**; 3,92/dia quando diluído em toda a janela 22/07–02/08.
- `MLB7314817026`: 2 visitas em 02/08, **2,00/dia no único dia completo disponível**; 0,17/dia quando diluído na janela pós-migração.
- Ambos têm `sold_quantity=0`; não existe base para prometer recuperação de vendas.

### 1.4 Os 51 itens ganhadores

**Sim.** O CSV histórico tem 51 ganhadores somando +54,189/dia; os dois sucessores contribuem **+7,000/dia** (+6,714 e +0,286), ou **12,9%** do ganho. Isso prova migração parcial, mas não compensa a perda familiar.

## Tarefa 2 — buy box dos dois sucessores

| sucessor | status | destaque hoje | preço nosso | `price_to_win` | diferença | boosts em oportunidade | ofertas/concorrentes | reputação nossa × destaque |
|---|---|---|---:|---:|---:|---|---:|---|
| `MLB7297087912` | **winning** · maximum | `MLB7297087912` / FACILYTY | R$ 173,25 | R$ 173,00 | +R$ 0,25 (0,14%) | fulfillment, free_installments, same_day_shipping | 1 / 0 | 5_green × 5_green |
| `MLB7314817026` | **winning** · maximum | `MLB7314817026` / FACILYTY | R$ 171,53 | R$ 171,00 | +R$ 0,53 (0,31%) | fulfillment, free_installments, same_day_shipping | 2 / 1 | 5_green × 5_green |

Nos dois anúncios, `free_shipping` e `shipping_collect` já aparecem como `boosted`; Full, juros zero e same-day são oportunidades adicionais, não requisitos pendentes para ganhar hoje.

### Conclusão por sucessor

- **MLB7297087912:** já ganha sozinho; o catálogo tem uma única oferta. Não falta nada para ganhar. Full, juros zero e same-day poderiam fortalecer a defesa, mas a API não fornece efeito causal para estimar visitas adicionais. Recuperação já observada: **15,67 visitas/dia desde a entrada**; vendas recuperadas observadas: **0**.
- **MLB7314817026:** já ganha contra uma oferta de R$157,00. A FACILYTY vence a R$171,53 porque opera em `me2/xd_drop_off`; a concorrente `MAGAZINELIMABELA` está em `not_specified` com `lost_me2_by_restrictions` e reputação `3_yellow`. Não falta preço para ganhar. Recuperação observada: **2 visitas no único dia completo**; vendas: **0**.

> `price_to_win` ligeiramente abaixo do preço atual não é ordem para baixar preço: o status oficial é `winning`, vencedor é a própria FACILYTY e esta missão proíbe alterações.

## Tarefa 3 — varredura do mesmo padrão

### 3.1 Todos os catálogos novos desde 15/07

| prioridade | origem provável | sucessor novo | estado/buy box | visitas/dia em jogo | nosso preço × vencedor | ofertas |
|---:|---|---|---|---:|---|---:|
| 1 | `MLB6574414098` | `MLB7297087912` | active / **winning** | 115,69 | R$ 173,25 × R$ 173,25 | 1 |
| 2 | `MLB6574534100` | `MLB7314817026` | active / **winning** | 67,28 | R$ 171,53 × R$ 171,53 | 2 |
| 3 | `MLB6654735702` | `MLB7313976860` | active / **competing** | 5,12 | R$ 195,80 × R$ 100,00 | 2 |
| 4 | `MLB6654697288` | `MLB7313976854` | active / **competing** | 4,08 | R$ 195,80 × R$ 127,00 | 5 |
| 5 | `MLB6654685380` | `MLB7313976836` | under_review / **not_listed** | 0,00 | — | 2 |
| 6 | `MLB6574559704` | `MLB7313977102` | active / **winning** | 0,00 | R$ 176,14 × R$ 176,14 | 1 |

Leitura da fila:

- **MLB7313976836:** `under_review`, substatus `forbidden`, `not_listed/item_not_opted_in`, zero visitas. Não entrou na disputa.
- **MLB7313976854:** perde para `MLB3984597627`; R$195,80 contra vencedor R$127,00, `price_to_win=R$125,00`, 5 ofertas; FACILYTY `5_green` contra vendedor destaque `5_green/gold`.
- **MLB7313976860:** perde para `MLB6809277534`; R$195,80 contra R$100,00, `price_to_win=R$93,31`, 2 ofertas; o vencedor tem Full e same-day.
- **MLB7313977102:** ganha sozinho, mas ainda teve zero visita no primeiro dia parcial/completo observado; a origem tradicional da família cresceu, portanto não há perda em jogo nesta família.

### 3.2 Pendências históricas

- Há **18** itens locais com `waiting_for_patch`: **17 `closed+deleted`** e **1 `inactive`**.
- Nenhum payload atual contém literalmente `OPT_OBEY` ou “aceitar catálogo”; todos os 18 `health/actions` responderam que o item deletado/buy-it-now não é aceito.
- Esses 18 registros são backlog forense, não uma fila segura de ação. Somente `MLB6574413814` tinha tráfego relevante no recorte (2,21/dia antes; 0 depois) e nenhum sucessor novo confirmado.
- A fila completa, incluindo os seis catálogos novos e as 18 pendências legadas, está em `docs/qa/reconciliacao-catalogo-2026-08-03/fila-migracao-catalogo.csv`.

## Tarefa 4 — defeitos de medição

| defeito proposto | confirmado? | evidência |
|---|---|---|
| Coletor de visitas não inclui sucessores novos | **Não** | Fe usa `/users/{seller}/items_visits`, total agregado da conta. |
| Sync não captura IDs criados pela migração | **Não** | Os 6 IDs novos, inclusive os dois alvos, estão em `items`; os alvos também estão em `ml_items`. |
| Fe calculado sobre base incompleta de IDs | **Não** | A fonte agregada independe da lista local. Artefato atribuído a IDs ausentes: **0,00/dia**. |
| Janela do Fe corresponde exatamente a 7d vs. 28d sem sobreposição | **Não — defeito novo confirmado** | O código consulta `today-7..today` (8 datas inclusivas) e `today-35..today-7` (29 datas inclusivas), sobrepondo `today-7`, depois divide por 4. Isso distorce o Fe, mas não por migração de IDs. |

## Limitações e decisões que ficam com o dono

- 03/08 estava parcial; conclusões de média usam 02/08 como último dia completo.
- A API oferece estado atual, não contrafactual. Não é possível estimar ganho adicional de visitas/vendas por Full, same-day ou preço sem experimento controlado.
- `sold_quantity=0` nos seis novos anúncios no snapshot; qualquer promessa de vendas seria inventada.
- Nenhum preço deve ser alterado com base neste relatório.

## Artefatos e fontes

- `docs/qa/reconciliacao-catalogo-2026-08-03/reconciliacao-trafego.csv`
- `docs/qa/reconciliacao-catalogo-2026-08-03/reconciliacao-trafego-diario.csv`
- `docs/qa/reconciliacao-catalogo-2026-08-03/fila-migracao-catalogo.csv`
- `GET /users/3058804121/items_visits`
- `GET /items/{id}/visits/time_window?last=90&unit=day`
- `GET /items/{id}/price_to_win?version=v2`
- `GET /products/{catalog_product_id}/items`
- `GET /users/{seller_id}`
- tabelas `items`, `ml_items` somente com `SELECT`

**Contrato preservado:** nenhum POST/PUT/PATCH/DELETE no ML, nenhum SQL mutante, nenhum publish Redis, nenhum serviço parado, nenhuma suíte Playwright e nenhum comando Git mutante.
