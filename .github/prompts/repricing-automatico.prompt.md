---
description: "Prompt escopado — repricing automático governado (simulação → staging → prod com tetos)"
agent: Implementador
status: draft
owner: Jesse
created: 2026-08-08
approved: ~
implemented: ~
account_impact: "Fase 0 read-only (simulação). Fases 1-2 escrevem preço no ML — staging 1336 primeiro; 1335 só com OK explícito por lote"
depends_on: "SniperAgent (app/Agents/SniperAgent.php), AdvancedPricingEngine, PricingScenarioService, jobs-once-worker (cron 1min), Pregão/Sentinela para observação"
---

# Prompt: Repricing Automático Governado

## Contexto
O repricing automático está **OFF desde sempre**: `scripts/run_repricer.php` referenciava `App\Services\RepricingService`, classe que nunca existiu no git (cron desativado em 2026-08-08). O caminho governado já existe e deve ser reutilizado — **não criar engine nova**:

- `SniperAgent` — reajusta apenas itens com `auto_reprice=1` (flag por item no DB), usando dados do CompetitorSpy.
- `AdvancedPricingEngine` — elasticidade, psychological pricing, budget-aware (atrás de feature flag).
- `PricingScenarioService` — simulação sem escrita.
- `JobService::runAutonomousAgentJob` — executa agentes via fila (`jobs-once-worker` já roda a cada minuto).

## Objetivo
Ligar repricing automático em 3 fases, cada uma com gate de aprovação:

### Fase 0 — Simulação (read-only, sem aprovação adicional)
- Rodar o engine em modo simulação (`PricingScenarioService` ou Sniper com dry-run) sobre os itens candidatos, logando "o que faria" (item, preço atual, preço proposto, delta %, motivo) em tabela/relatório.
- Rodar por ~1 semana para validar a lógica contra dados reais de concorrência.
- Nenhuma escrita no ML. Nenhuma mutation em itens.

### Fase 1 — Staging 1336 (escrita controlada)
- Marcar `auto_reprice=1` em 3–5 itens de teste da conta staging.
- Agendar o Sniper via fila de jobs (enfileirar `autonomous_agent` com `agent=sniper`), NÃO via cron novo direto.
- Verificar no Pregão staging que preços mudaram como esperado e que o lock/queue não duplica execução.

### Fase 2 — Produção 1335 (só com OK explícito do dono, por lote)
- Lote inicial: máx. 5 itens com `auto_reprice=1`, escolhidos pelo dono.
- Tetos duros obrigatórios (config, não hardcode): variação máx. ±5% por execução, frequência máx. 1x/dia por item, preço mínimo = custo + margem mínima (nunca abaixo).
- Observação: Pregão (margem, vendas) + Sentinela (riscos de conta) por 1–2 semanas antes de expandir o lote.
- Rollback: registrar preço anterior por item antes de cada escrita (tabela de histórico) para permitir reversão.

## Fora de escopo
- Recriar `RepricingService` ou reativar `scripts/run_repricer.php`.
- Repricing sem flag `auto_reprice` por item (opt-in obrigatório).
- Escrita em 1335 nas fases 0 e 1.
- Mudar `AdvancedPricingEngine` inteiro / refatorar pricing legado.
- Scraping do site ML (somente API oficial).

## Regras
1. Toda escrita de preço passa pelo SafetyGuard/flag equivalente (SAFE_MODE, FORBIDDEN_ACCOUNTS=1335 até aprovação da Fase 2).
2. Teto de variação e preço mínimo validados ANTES de qualquer PUT — violação = skip + log + alerta, nunca clamp silencioso.
3. Idempotência: mesma execução não aplica 2x no mesmo item (lock + checagem de preço atual antes do PUT).
4. Rate limit ML: backoff exponencial; circuit breaker existente respeitado.
5. Auditoria: toda decisão (simulada ou aplicada) logada com motivo, preço anterior e correlação.
6. Staging smoke antes de qualquer fase; `claude-progress.txt` atualizado ao fim de cada fase.

## Superfície esperada
1. Config de tetos (`.env.example` + validação): `REPRICE_MAX_PCT`, `REPRICE_MAX_ITEMS_PER_RUN`, `REPRICE_MIN_MARGIN_PCT`.
2. Fase 0: comando/modo de simulação + relatório em `storage/reports/`.
3. Fase 1: enfileiramento do job Sniper + flag `auto_reprice` na UI (se ausente).
4. Tabela de histórico de preços aplicados (migration) para rollback e auditoria.
5. Testes unitários (tetos, idempotência, skip em conta proibida) + smoke staging.

## Critérios de aceite por fase
- **F0**: relatório de simulação por ≥5 dias sem erro; deltas coerentes (nunca >teto).
- **F1**: preço muda em staging apenas nos itens flagados; fila não duplica; rollback funciona.
- **F2** (após OK do dono): lote de 5 em prod com 0 incidentes por 1–2 semanas; Sentinela verde.
