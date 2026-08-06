---
description: "TestSprite + Playwright em staging — planos FE, runs e fixes sem tocar prod/1335"
agent: Debugger
tools:
  - codebase
  - runInTerminal
  - editFiles
  - problems
  - search
---

# Missão: TestSprite + E2E mutante só em staging

Alvo: `https://staging.eskill.com.br` (path `/home/eskill/htdocs/staging.eskill.com.br`).
Leia `docs/ops/STAGING.md` antes de qualquer comando.

## Proibições
- Não apontar TestSprite / Playwright / workers / tick para conta ML **1335**.
- Não mutar `eskill.com.br` (prod): sem POST/DELETE, sem seed, sem `bin/deploy.sh`.
- Não criar capability nova sem prompt aprovado (Escritor Único).
- Sem commit/push sem pedido explícito.

## Pré-checks
```bash
bash scripts/deploy_staging.sh --check
# Se código staging desatualizado (só com OK do usuário):
# bash scripts/deploy_staging.sh
testsprite --version
testsprite project list
```

Se auth TestSprite falhar: `testsprite setup` / `testsprite auth` e parar até a key estar ok.

## Escopo por módulo

Se o usuário nomear um módulo, **não** rode a suíte Playwright/TestSprite inteira — filtre specs/planos desse fluxo.

### Exemplo pronto — Financeiro
**UI / API staging**
- Dashboard: `/dashboard/financials` (ou rota equivalente em `financials.php`)
- Controllers: `FinancialReport`, `FinancialDiscrepancy`, `Settlement`
- Backend canônico: `app/Services/Financial/`

**Playwright**
```bash
cd /home/eskill/htdocs/eskill.com.br
# Preferir grep/project do spec financeiro se existir; senão staging full só com OK do usuário
npm run test:e2e:staging -- --grep "financial|financeiro|pnl|settlement"
```

**TestSprite**
- Planos só para páginas/fluxos financeiros em **staging**.
- Nomes sugeridos: `financials-dashboard`, `settlement-report`, `discrepancy-list`.
- Credencial ≠1335.

**Chat de referência**
```
@testsprite-staging
Só Financeiro em staging (dashboard financials / settlement / discrepancy).
Pré-check → Playwright --grep financeiro → TestSprite só esses planos.
Liste falhas antes de corrigir. Sem Pregão.
```

### Padrão (outro módulo)
Informar: path UI, `--grep` Playwright, nomes de planos TestSprite. Conta ML de teste ≠1335.

## Fluxo curto

### 1. Playwright gate
```bash
cd /home/eskill/htdocs/eskill.com.br
# Com módulo: use --grep do escopo. Sem módulo (e com OK): suíte staging full
npm run test:e2e:staging
```
Registrar falhas com arquivo + `grep` do spec.

### 2. TestSprite (FE / planos)
- Projeto TestSprite deve ter **base URL = staging** (nunca prod).
- Credenciais de login: conta de teste staging, nunca tokens da 1335.
- Scaffold / lint local antes de criar na API:

```bash
testsprite test scaffold --help
testsprite test lint --help
```

- Criar/atualizar testes só com planos revisados (`testsprite test create` / `create-batch`).
- Rodar, esperar resultado, baixar artifact se falhar:

```bash
testsprite test result <test-id>
testsprite test artifact get <run-id>   # se disponível no help local
testsprite test diff <run-a> <run-b>    # regressão entre runs
```

### 3. Fix loop
Para cada falha Playwright **ou** TestSprite:
1. Causa raiz no app (staging path ou repo prod sync — editar no repo canônico `/home/eskill/htdocs/eskill.com.br`).
2. Fix mínimo → `php -l` → re-deploy staging se necessário → re-rodar o teste isolado.
3. Codacy analyze no arquivo editado.
4. Log no topo de `claude-progress.txt`.

### 4. Fechamento
| Camada | Antes | Depois |
|--------|-------|--------|
| Playwright staging | | |
| TestSprite (N testes) | | |

**OK para promoção:** Playwright staging verde + TestSprite sem P0/P1 abertos.
**Não** promover a prod sem aprovação + smoke readonly em prod (`npm run test:e2e:readonly`).

## Output
- Tabela de falhas (P0/P1/P2)
- Fixes aplicados (arquivo + 1 frase)
- Bloqueios (DNS/TLS staging, OAuth ≠1335, API key TestSprite)
