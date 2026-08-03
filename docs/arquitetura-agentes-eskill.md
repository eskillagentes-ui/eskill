# Arquitetura dos 7 agentes Eskill (plano — sem execução)

> Documento de planejamento. **Nenhum agente novo entra em produção neste bloco.**
> Data: 2026-08-03 · Branch: `feature/pregao`

## Visão

Sete papéis especializados sob um orquestrador, com fronteiras claras de I/O,
somente leitura na API do Mercado Livre enquanto `ML_WRITE_AUTOMATION=false`,
e regra P0: emissão de `op` apenas na mudança de estado.

| Papel | Missão | Módulo atual (código) | O que falta |
|---|---|---|---|
| **Orquestrador** | Agenda ticks, prioriza filas, aplica gates (freshness, fail-closed, staging≠prod) | `bin/pregao-index-tick.php`, `config/systemd/pregao-tick*.service`, `PregaoMetricsCollector` | Política única de scheduling; kill-switch por conta; sem misturar staging→1335 |
| **Sentinela** | Riscos e semáforo (moderação, OAuth, 429, queda de vendas…) | `App\Services\Sentinela\Sentinela`, mapa em `docs/mapa-prontidao-sentinela.md` | Fechar 11 riscos como estados independentes; hoje vários só existem fora do Sentinela |
| **Coletor** | Ingestão read-only (Ads/PADS, visitas, ranks, perguntas, watchlist) | `AdsMetricsCollector`, `AdsService`, `bin/ads-collect.php`, `PregaoWatchlistCollector`, collectors Pregão | Histórico Ads 36d íntegro (este bloco); provenance fase 2; pacing global anti-429 |
| **Otimizador** | Recomenda preço/Ads/ROAS sem escrever no ML | `RoasTrioCalculator`, `SkuCustoService`, `AdsObservationService`, `AdsAlertService` | UI de recomendação; nunca PATCH enquanto write=false; validar planilha de custos antes de ROAS |
| **Criador** | Clonagem / catálogo / sucessores (preparação de payload) | fluxos AWA / clone / `ads_recovery_milestones` | Separar “montar rascunho” de “publicar”; gate humano |
| **Financeiro** | Margens, TACOS/ACOS, P&L, custos SKU | `sku_custos`, `ads_account_metrics_daily`, índice Ft | Auditoria de planilha (suspeitas copiar-colar); reconciliar `preco_venda_atual` vs `preco_minimo` |
| **QA** | Testes, snapshot review, E2E readonly, hermes | PHPUnit Ads/Pregão, `tests/e2e/*`, `test:e2e:readonly`, Computer Use / browser MCP | Sessão Computer Use autorizada; gate obrigatório antes de merge |

## Contratos transversais

1. **Somente leitura ML** até liberação explícita de escrita.
2. **Fail-closed** em payload incompleto / 429 / 5xx (nunca persistir zero enganoso).
3. **Freshness** Ads no tick: TTL 5 min; histórico só com `--history`.
4. **Snapshot UI** usa candle do dia + índice live; Ft exige TACOS numérico.
5. **Staging ≠ produção**: nunca apontar workers staging à conta ML 1335.

## Ordem sugerida de implementação (futuro)

1. Fechar Coletor Ads/PADS + integridade 36d (este prompt).
2. Sentinela — riscos 5–11 do mapa de prontidão.
3. Financeiro — validação de custos + Ft estável.
4. Otimizador — recomendações read-only.
5. Orquestrador — unificar timers/systemd.
6. Criador — rascunhos com gate humano.
7. QA — Computer Use + E2E readonly contínuo.

## Fora de escopo agora

Implementar runtime dos 7 agentes · automação 24/7 · escrita de preço/Ads · simular clique de mouse.
