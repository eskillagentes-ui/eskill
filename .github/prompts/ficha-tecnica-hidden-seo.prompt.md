---
description: "Prompt escopado — preencher Hidden SEO (LINE, MPN, etc.) na Ficha Técnica sem inventar dados"
agent: Implementador
status: implemented
owner: Jesse
created: 2026-08-07
approved: 2026-08-07
implemented: 2026-08-07
account_impact: "Sugere atributos hidden; apply no ML 1335 só com OK explícito item/lote"
depends_on: "ficha-tecnica-gtin-ean-pool (GTIN), ficha-tecnica-modelo-mapeamento-semantico (MODEL)"
---

# Prompt: Ficha Técnica — Hidden SEO (lacunas ocultas)

## Objetivo
Reduzir lacunas **Hidden SEO** (~123 itens active na 1335) preenchendo atributos `used_hidden` / hidden com valores **evidenciáveis** (título, ficha local, catálogo, API ML) — nunca inventar.

Prioridade inicial (frequência observada): `LINE`, `MPN`, `HANDLE_RISER`, demais hidden por categoria.

## Fora de escopo
- Inventar GTIN/EAN (já coberto pelo pool / EMPTY).
- Scraping do site ML.
- Apply automático sem aprovação.
- Mutar 1335 sem OK explícito.
- Refatorar AttributeKiller inteiro.

## Regras
1. Só sugerir se o atributo for gap hidden/recommended na categoria.
2. Fonte preferencial: extração do título → mesmo item (outros attrs) → catálogo local → skip.
3. Confidence baixa se ambíguo; não forçar.
4. Idempotência por (item, attribute_id, source).
5. Staging smoke ≠1335 antes de qualquer apply.

## Superfície
1. Política/serviço `TechSheetHiddenSuggestionService` (ou métodos em TechSheetService).
2. Hook no generate existente.
3. UI: origem “Hidden SEO” + banner se muitos hidden.
4. CLI opcional: `php bin/tech-sheet suggest-hidden --account=X --limit=N --dry-run`.
5. Testes unitários + staging smoke + `claude-progress.txt`.

## Critérios de aceite
- [x] Zero valores inventados sem evidência no título/dados locais.
- [x] LINE/MPN (quando elegíveis) geram pending com source clara.
- [x] PHPUnit verde no filtro Hidden/TechSheet.
- [x] Staging ≠1335.
- [x] Sem apply ML 1335 neste prompt.

## Aprovação
- [x] Jesse aprova este prompt (via execução autônoma com prompt aberto)
- [x] Após aprovação, agent implementa só o escopo acima
