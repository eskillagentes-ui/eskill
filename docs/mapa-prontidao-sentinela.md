# Mapa de prontidão do Sentinela

**Data:** 2026-08-03  
**Conta auditada:** FACILYTY · account_id `1335` · seller `3058804121`  
**Modo:** read-only — inspeção de código, `SELECT` e evidências já coletadas; nenhuma chamada de escrita ao Mercado Livre

## Resumo executivo

Dos **11 riscos**, **10 têm uma fonte de dado utilizável hoje**:

- **4 já entram no tick do Pregão:** reputação, taxa de reclamações, taxa de atraso de despacho e taxa de cancelamentos;
- **5 têm dados no sistema, mas ainda não são riscos independentes do Sentinela:** moderação, bloqueio de catálogo, saúde OAuth, rate limit e queda brusca de vendas;
- **1 existe na API/código, mas não é coletado nem persistido:** chargeback/disputa;
- **1 não tem fonte operacional implementada:** NF pendente.

A interface atual faz parecer que só há um risco porque `PregaoMetricsCollector::collectReputation()` compacta reputação + reclamações + atrasos + cancelamentos em um único `metric.update`/robô **REPUTAÇÃO**. Para uma grade de 11 riscos, esses quatro sinais precisam virar estados independentes, mantendo a regra `op` somente na transição.

## Evidência atual da conta

| Sinal | Valor observado | Fonte |
|---|---:|---|
| Reputação | verde-escuro | `account_index_metrics`, atualizado em 2026-08-03 04:42:36 |
| Reclamações | 0,74% | `seller_reputation.metrics.claims.rate` persistido em `account_index_metrics` |
| Atrasos de despacho | 0,77% | `seller_reputation.metrics.delayed_handling_time.rate` |
| Cancelamentos que afetam reputação | 0,00% | `seller_reputation.metrics.cancellations.rate` |
| Semáforo composto | verde | `account_index_metrics.semaforo_status` |
| Token OAuth | active; zero falha consecutiva | `ml_accounts` + `token_refresh_audit` |
| Vendas | 11 em 7 dias; 0 hoje no momento da sonda | `account_index_metrics` + `ml_orders` |
| Moderação disponível localmente | 365 itens; 230 com algum `sub_status` | `ml_items.data` — o valor inclui substatus não necessariamente punitivos |
| Catálogo disponível localmente | 113/365 itens ligados a produto de catálogo | `ml_items.catalog_product_id` |
| Shipments locais | 91; 7 `ready_to_ship`, 4 `pending`; `is_delayed=0` | `shipments`; sem prazo SLA normalizado, portanto não é fila confiável de atraso ainda |
| Claims persistidos | 0 | `ml_claims` está vazia; só a taxa agregada é coletada |
| Pagamentos persistidos | 0 | `ml_payments` está vazia |
| NF | zero marcador `invoice`/`fiscal` em 292 payloads de pedido | `ml_orders.order_data`; não existe tabela de status fiscal |

> **Importante:** `ml_orders` contém 27 pedidos com status `cancelled` nos últimos 60 dias, mas esse número bruto mistura cancelamentos que podem não afetar reputação. Para o limite do ML, a fonte de verdade deve continuar sendo `seller_reputation.metrics.cancellations.rate`.

## Matriz de prontidão

| # | Risco | Fonte exata | Já coletado? | Esforço estimado | Limiar de alerta sugerido |
|---:|---|---|---|---|---|
| 1 | **Reputação** | `GET /users/{seller_id}` (`seller_reputation.level_id`); `account_index_metrics.reputacao_cor`; `pregao_events` | **Sim, no Pregão**, mas agregado ao mesmo robô dos três percentuais | **P** — separar estado/card e manter deduplicação | Amarelo em qualquer queda de nível/cor; vermelho se `seller_reputation` ficar vermelho ou conta perder capacidade de vender. Emitir `op` só na transição. |
| 2 | **Reclamações** | `GET /users/{seller_id}` → `seller_reputation.metrics.claims.rate`; detalhe por `GET /post-purchase/v1/claims/search` e `GET /post-purchase/v1/claims/{id}/affects-reputation`; `account_index_metrics.reclamacoes_pct`; `ml_claims` existe, mas tem 0 linhas | **Taxa sim; detalhes não** | **P/M** — estado independente é simples; persistir claims e deduplicar webhook/polling é médio | **Amarelo ≥1,0%** (50% do limite de 2%); vermelho ≥1,6%; crítico ≥2%. Qualquer claim com ação obrigatória e prazo <24h também alerta. |
| 3 | **Fila/atraso de despacho** | `seller_reputation.metrics.delayed_handling_time.rate`; `GET /orders/search`; `GET /shipments/{id}`; tabelas `shipments` e `ml_orders` | **Taxa sim; fila SLA não** | **M** — normalizar prazo de handling e estado por shipment | **Amarelo ≥7,0%**; vermelho ≥12%; crítico ≥15%. Na fila, alertar pedido vencido ou a ≤2h do prazo. Não usar apenas `shipped_at IS NULL`. |
| 4 | **Cancelamentos** | `seller_reputation.metrics.cancellations.rate`; `GET /orders/search?order.status=cancelled`; `ml_orders.status`; `account_index_metrics.cancelamentos_pct` | **Sim, taxa agregada** | **P** — separar estado e usar taxa oficial | **Amarelo ≥1,0%**; vermelho ≥2,0%; crítico ≥2,5%. Pedido cancelado individual só gera `metric.update`; `op` apenas se mudar a faixa. |
| 5 | **Moderação de anúncio** | `GET /users/{seller_id}/items/search?status=under_review|inactive`; `GET /items/{id}` (`status`, `sub_status`, `tags`); `ml_items.status` e `ml_items.data` | **Existe no AccountHealth/sync, não no Sentinela** | **M** — classificar substatus punitivo, guardar histórico e transições | Qualquer `under_review` novo = amarelo; item de alto volume suspenso/inativo por moderação = vermelho; ≥3 simultâneos = vermelho. |
| 6 | **Bloqueio/perda de catálogo** | `GET /items/{id}/price_to_win`; `GET /products/{catalog_product_id}`; `GET /items/{id}` (`catalog_listing`, `catalog_product_id`, tags); `ml_items.catalog_product_id` | **Dado atual parcial; não coletado como risco** | **M/G** — polling por prioridade, snapshot e relação original→sucessor | Item top-volume `not_listed`, `item_not_opted_in` ou perda de buy box = amarelo; >5% dos itens de catálogo afetados ou perda >10 visitas/dia = vermelho. |
| 7 | **Chargeback/disputa Mercado Pago** | `GET /v1/chargebacks/{id}` no `PaymentRefundService`; `GET /post-purchase/v1/claims/search?stage=dispute`; busca de pagamentos MP; `ml_payments` existe, mas está vazia | **API/código existe; coleta e descoberta não** | **G** — validar token MP account-scoped, descobrir IDs, paginar e persistir ledger/eventos | Qualquer disputa/chargeback aberto com ação requerida = amarelo; prazo documental <48h, valor relevante ou cobertura negada = vermelho. Nunca usar cliente/token global como fallback. |
| 8 | **Saúde do token OAuth** | `ml_accounts.status`, `token_expires_at`, `last_refresh_at`, `refresh_failure_count`, `last_refresh_error`; `token_refresh_audit`; `MlObservabilityService`; check do `AccountHealthController` | **Sim no subsistema OAuth; não no Sentinela** | **P** — adaptar métrica sem acessar/logar tokens | Amarelo se expira em <2h ou 1 falha; vermelho se `disconnected`, expirado sem refresh token ou ≥2 falhas consecutivas; crítico em `invalid_grant`. |
| 9 | **Rate limit ML (429)** | resposta HTTP no `MercadoLivreClient`; `token_refresh_audit.http_code`; `ml_api_logs.response_status`; alerta parcial em `CloneAlertNotificationService` | **Parcial e fragmentado; não no Sentinela global** | **M** — telemetria única no boundary HTTP e janela Redis/DB | Amarelo no primeiro 429 em 5 min ou >1% das chamadas; vermelho em 3 respostas 429 consecutivas/circuit breaker aberto. Respeitar `Retry-After`; `op` só na entrada/saída do estado. |
| 10 | **NF pendente** | Hoje há apenas `GET /orders/{id}` e billing info do comprador; não há endpoint/tabela de emissão/status NF no sistema | **Não existe** | **G/externo** — integrar emissor/ERP/NFe, chave, status e prazo | Proposta: amarelo se pedido pago sem NF após 30 min; vermelho após 2h ou antes do cutoff de despacho. O dono precisa definir o sistema fiscal de verdade. |
| 11 | **Queda brusca de vendas** | `ml_orders` diário; `account_index_metrics.vendas_hoje/vendas_7d`; `pregao_events metric.update`; histórico `account_health_history.sales_score` | **Vendas sim; detector de queda não** | **P/M** — baseline, estado e cooldown | Amarelo se média 7d cair ≥25% contra 28d anterior por 2 fechamentos; vermelho ≥50%; alerta imediato se dia atual cair ≥70% após normalizar dia da semana. |

## O que já está pronto para o Cursor reutilizar

1. `PregaoMetricsCollector::collectReputation()` já obtém e persiste os três percentuais oficiais e chama `emitOpOnTransition()`.
2. `config/pregao.php` já contém limites ML de reclamações 2%, atrasos 15% e cancelamentos 2,5%.
3. `account_index_metrics` já possui colunas para os quatro riscos iniciais e vendas.
4. `AccountHealthService` já implementa GET de claims, pedidos cancelados e itens `under_review`/`inactive`.
5. `shipments`, `ml_orders`, `ml_items`, `token_refresh_audit` e `pregao_events` já fornecem persistência parcial.
6. `PaymentRefundService` e `ClaimDisputeService` têm clientes read-only, mas ainda precisam de coleta account-scoped e persistência segura.
7. `PregaoEmitService::emitOpOnTransition()` é a primitiva correta para impedir repetição do P0.

## Lacunas estruturais antes de dizer “11/11 monitorados”

- Criar estado independente por risco; hoje quatro sinais são compactados em um robô.
- Persistir histórico por risco e não apenas o valor corrente em `account_index_metrics`.
- Normalizar prazo de despacho por shipment; os campos locais atuais não provam atraso.
- Guardar snapshots de moderação e catálogo para detectar transição.
- Centralizar telemetria 429 no cliente HTTP, não somente em jobs de clonagem.
- Validar autorização Mercado Pago account-scoped antes de chargeback/disputa.
- Definir e integrar a fonte fiscal; sem isso NF pendente continua impossível.
- Implementar baseline por dia da semana para queda de vendas.

## DÚVIDAS PARA O DONO

1. **NF:** qual sistema emite a nota hoje — Mercado Livre, ERP, contador ou emissão manual? Sem essa resposta não existe fonte confiável.
2. **Mercado Pago:** a conta FACILYTY deve autorizar leitura financeira account-scoped para disputes/chargebacks? Nenhum grant novo deve ser feito sem decisão do dono.
3. **Staging:** escolher VPS separado, container isolado ou adiar; isso é requisito para testes mutantes e para qualquer futuro recurso de escrita.
4. **Watchlist:** fornecer CSV `mlb_id,apelido,keyword_alvo` com 8–10 concorrentes. `competitor_items` e `competitor_item_snapshots` estão vazias.
5. **Visual de produção:** o navegador isolado redirecionou `/dashboard/pregao` para `/login`, e o cua-driver está sem sessão; não foram solicitadas nem usadas credenciais. A regressão funcional do placeholder está coberta por Playwright.

## Verificação rápida

- **Produção visual:** a tentativa read-only em `https://eskill.com.br/dashboard/pregao` terminou em `/login`. A captura confirma somente o bloqueio de autenticação; o painel e o placeholder não aparecem, portanto não seria honesto afirmar uma inspeção visual autenticada.
- **Evidência da captura:** `/root/qa-evidence/sentinela-prontidao-2026-08-03/producao-pregao-redireciona-login.png`.
- **Comportamento do placeholder:** o teste `@readonly atributo hidden oculta o placeholder do gráfico` passou com o CSS servido, confirmando `display:none` quando `hidden` está presente e `display:flex` quando removido.
- **Suíte permitida:** `npm run test:e2e:readonly` — **30 passed, 15 skipped, 0 failed** em 18,7s.

## Rastreabilidade

### Código

- `app/Services/Pregao/PregaoMetricsCollector.php:101-205`
- `config/pregao.php:25-29`
- `app/Services/AccountHealthService.php:1595-1693,1712-1767`
- `app/Services/Financial/ClaimDisputeService.php:13-215`
- `app/Services/Financial/PaymentRefundService.php:107-181,364-415`
- `app/Controllers/FlexController.php:45-108`
- `app/Controllers/AccountHealthController.php:219-320`
- `app/Services/CloneAlertNotificationService.php:188-235`

### Evidência read-only

- `/root/qa-evidence/sentinela-prontidao-2026-08-03/schema-probe.json`
- `/root/qa-evidence/sentinela-prontidao-2026-08-03/risk-data-probe.json`
- Sondas locais: `C:\Users\pepe\qa-evidence\sentinela-prontidao-2026-08-03\`

**Nenhuma escrita na API do ML, nenhum publish Redis, nenhum serviço parado e nenhum comando Git mutante foram executados nesta etapa.**
