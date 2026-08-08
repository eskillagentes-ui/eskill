---
description: "Prompt escopado — integrar Ficha Técnica com EanService para sugerir/aplicar GTIN legítimo (ou EMPTY_GTIN_REASON)"
agent: Implementador
status: implemented
owner: Jesse
created: 2026-08-07
approved: 2026-08-07
implemented: 2026-08-07
account_impact: "Pode escrever GTIN na conta ML ativa após approve+apply; NÃO inventar EAN; NÃO aplicar em 1335 sem OK explícito item/lote"
---

# Prompt: Ficha Técnica ↔ EanService (GTIN)

## Objetivo
Permitir que o módulo **Ficha Técnica** sugira GTIN de forma segura:
1. **Prioridade A** — alocar EAN-13 legítimo do pool já existente (`EanService` / inventário GS1 da conta).
2. **Prioridade B** — se não houver saldo EAN, sugerir `EMPTY_GTIN_REASON` (motivo oficial ML), nunca fabricar dígitos.

Não implementar gerador algorítmico de GTIN/EAN.

## Fora de escopo (NÃO fazer nesta feature)
- Compra/pagamento de pacotes EAN (já existe em `EanService` / admin EAN).
- Scraping de GTIN de sites/concorrentes.
- Apply automático no ML sem aprovação humana.
- Mutar conta **1335** em produção sem OK explícito do dono (usar staging / dry-run primeiro).
- Refatorar o painel admin EAN.
- Unificar os dois `TechSheetService` (root vs SEO).

## Contexto runtime (evidência 2026-08-07)
- Conta 1335 active: ~123 itens com lacunas Hidden; GTIN aparece com frequência em `missing_hidden`.
- Categoria amostra `MLB438146`: atributo `GTIN` com tags `conditional_required`, `validate`, `used_hidden`, `multivalued`.
- Docs ML: priorizar GTIN; se indisponível usar `EMPTY_GTIN_REASON` (Artesanal / Kit / No registrado / Otro).
- `EanService` já tem inventário, saldo, `getNextAvailableEan`, `useEan`, validação EAN-13.
- Saldo 1335 na data do levantamento: **available = 0** (feature deve degradar para EMPTY_GTIN_REASON + UI de “sem saldo”).
- `HiddenFieldsService` hoje só **extrai** GTIN existente; não aloca do pool.

## Regras de negócio

### Quando sugerir GTIN (pool)
- Item `active`, categoria tem atributo `GTIN`, item sem GTIN preenchido.
- Conta tem `EanService::getBalance(accountId).available > 0`.
- Sugestão em `tech_sheet_suggestions`:
  - `attribute_id = GTIN`
  - `suggested_value = <ean13>`
  - `source = ean_pool`
  - `confidence = 100` (código do inventário, não “chute”)
  - `meta` JSON: `{ "ean_assignment_id": ..., "ean_id": ..., "reservation": "soft|hard" }`

### Reserva de EAN
- **Gerar sugestão**: reserva *soft* (não marcar `sold` ainda; impedir que outro item pegue o mesmo EAN — ex. status `reserved` / `ml_item_id` provisório ou coluna dedicada). Se o schema atual não tiver `reserved`, estender `ean_assignments` com migration mínima (status ou `reserved_at` + `reserved_for_item_id`).
- **Rejeitar / expirar sugestão**: liberar reserva.
- **Apply aprovada**: chamar fluxo equivalente a `useEan` (marcar sold, debitar saldo, vincular `ml_item_id`) **somente após** PUT ML bem-sucedido; se PUT falhar, não consumir EAN.
- **Idempotência**: re-gerar no mesmo item não consome segundo EAN se já há sugestão pending/approved com `source=ean_pool`.

### Quando sugerir EMPTY_GTIN_REASON
- Sem saldo EAN **ou** flag/config da conta `tech_sheet_gtin_mode = empty_reason_preferred` (default: tentar pool primeiro).
- Motivo default para peças aftermarket AWA: **No registrado** (value_id da categoria, não string solta).
- Sugestão: `attribute_id = EMPTY_GTIN_REASON`, `source = empty_gtin_policy`, `confidence` alta (ex. 90), `meta.reason_value_id`.
- Não sugerir EMPTY se GTIN já preenchido ou se já há sugestão GTIN pending do pool.

### Apply
- Reutilizar `TechSheetService::applyApproved` (já faz PUT attributes).
- Tratar resposta ML com `error` **ou** `success === false` (já alinhado).
- GTIN em variação: se o atributo for `variation_attribute` na categoria, apply deve respeitar estrutura de variations (fase 2 se complexo; fase 1: só itens sem variação ou GTIN no nível item). Documentar limitação se adiado.

## Superfície a implementar

### Backend
1. `App\Services\TechSheetGtinSuggestionService` (ou métodos em `TechSheetService`) que:
   - decide pool vs EMPTY;
   - integra `EanService` sem duplicar SQL de inventário.
2. Endpoint(s) existentes de generate (item/batch) passam a incluir GTIN/EMPTY quando elegível — **sem** nova rota pública se possível; se precisar, `POST /api/seo/technical-sheet/items/{id}/suggestions/gtin` + batch.
3. Decisões reject/approve liberam ou mantêm reserva.
4. Logging Monolog estruturado (nunca echo); sem secrets.

### Frontend (SEO Killer tab)
- Ao gerar sugestões, mostrar origem: “EAN do pool” vs “Motivo sem GTIN”.
- Se `available === 0`, banner discreto: “Sem EAN no inventário — sugestões usarão EMPTY_GTIN_REASON” + link para admin EAN (rota já existente).
- Não auto-aplicar.

### CLI (opcional nesta feature)
- `php bin/tech-sheet suggest-gtin --account=X --limit=N --dry-run`
- Staging only por default; prod exige `--i-understand-prod`.

## Testes obrigatórios
- Unit: decisão pool vs EMPTY; não inventa EAN; idempotência; rollback de reserva no reject; apply falha → EAN não sold.
- Feature/integration com DB de teste / staging: gerar → aprovar → apply (mock ML client) atualiza suggestion status.
- PHPUnit verde no filtro TechSheet/Ean relacionado.
- Não rodar apply real em 1335 neste prompt.

## Critérios de aceite
- [x] Zero geração algorítmica de GTIN.
- [ ] Com saldo > 0, item elegível recebe sugestão `source=ean_pool` com EAN válido (checksum EAN-13). *(unit dry-run + código; staging saldo=0 — aguarda compra EAN)*
- [x] Com saldo = 0, recebe `EMPTY_GTIN_REASON` (No registrado / “não tem código cadastrado”) quando categoria permitir o atributo.
- [x] Reject libera EAN; apply só consome após PUT OK. *(hooks + unit release/confirm skip)*
- [x] UI SEO Killer indica origem e falta de saldo.
- [x] Staging smoke: generate em fixture ≠1335 (conta 1336).
- [x] Docs: entrada em `claude-progress.txt`; **não** marcar passes em `project-status.json` sem evidência.

## Ordem de implementação sugerida
1. Extensão mínima de reserva no `EanService` (se necessário).
2. Serviço de sugestão GTIN + testes unitários.
3. Hook no generate da Ficha Técnica.
4. UI banner + labels.
5. CLI dry-run + smoke staging.
6. Parar — aguardar OK explícito para apply em produção 1335 (e compra de EANs se saldo 0).

## Dependência operacional (dono)
Para a conta FACILYTY (1335) passar a usar pool de verdade: comprar/importar EANs no admin EAN até `available > 0`. Sem isso a feature só emite `EMPTY_GTIN_REASON`.

## Aprovação
- [x] Jesse aprova este prompt (capability nova liberada)
- [x] Após aprovação, agent implementa **somente** o escopo acima
