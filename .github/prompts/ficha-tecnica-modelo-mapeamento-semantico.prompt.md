---
description: "Prompt escopado — MODEL como identificador limpo via mineração de consultas + mapeamento semântico por campo"
agent: Implementador
status: implemented
owner: Jesse
created: 2026-08-07
approved: 2026-08-07
implemented: 2026-08-07
account_impact: "Altera como sugestões de MODEL são geradas; apply no ML 1335 só com OK explícito item/lote"
source_notes: "documentacao/arm/ficha.tecnica.md"
---

# Prompt: Ficha Técnica — MODEL (mapeamento semântico por campo)

## Objetivo
Tratar o atributo **MODEL** como **identificador limpo**, não como área de palavra-chave SEO.

Estratégia (nome de trabalho): **mineração de consultas + mapeamento semântico por campo**.

- Buscas relacionadas, autocomplete e tendências = **sinais de linguagem do cliente**, não o campo em si.
- Coletar → classificar por intenção/compatibilidade → distribuir:
  - **título** → termo principal
  - **descrição** → variações e contexto
  - **atributos** → só quando o termo descreve de fato o produto
- **MODEL** não recebe lista de busca; só guarda identificação limpa (ex. `Fan 125`, não “capacete moto fan 125 barato”).

Refs: [Atributos ML](https://developers.mercadolivre.com.br/pt_br/api-docs-pt-br/atributos), [Tendências](https://developers.mercadolivre.com.br/pt_br/relatorios-de-faturamento/tendencias).

## Fora de escopo
- Geração/compra de GTIN/EAN (já coberto por `ficha-tecnica-gtin-ean-pool.prompt.md`).
- Scraping do site ML (só API oficial).
- Apply automático no ML sem aprovação humana.
- Mutar **1335** sem OK explícito.
- Refatorar KeywordMiner / Trends inteiros.

## Contexto runtime (2026-08-07)
- Conta 1335: **28** sugestões MODEL `pending`, **6** `applied`.
- Já existe `TechSheetService` com estratégias de busca para MODEL (trends/autocomplete/title) — hoje podem misturar sinal de busca no valor do atributo.
- Notas do dono em `documentacao/arm/ficha.tecnica.md`.

## Regras de negócio
1. Valor sugerido para `attribute_id = MODEL` (e aliases de veículo: `COMPATIBLE_VEHICLE_MODELS`, `VEHICLE_MODEL`, `MOTO_MODEL`, `ALPHANUMERIC_MODEL` quando forem o gap):
   - Identificador curto e canônico (marca/linha/cilindrada quando fizer parte do modelo oficial).
   - Sem keywords de intenção comercial (“barato”, “original”, “kit”, “envio”, etc.).
   - Sem stuffing de sinônimos de busca.
2. Sinais de mineração (related/autocomplete/trends) alimentam **score/confiança** e, se útil, sugestões de **título/descrição** — não o texto cru do MODEL.
3. Se o melhor sinal for ambíguo → não inventar; marcar skip ou baixa confiança.
4. Idempotência: regenerar não duplica pending MODEL no mesmo item.
5. Fase 1: só itens **sem variações** se MODEL for variation_attribute na categoria; senão documentar limitação.

## Superfície a implementar
1. Serviço/filtro `TechSheetModelSuggestionPolicy` (ou métodos em `TechSheetService`) que:
   - normaliza candidato → identificador limpo;
   - rejeita valores “keywordy”;
   - escolhe melhor candidato com score.
2. Hook no generate/smart-fill de MODEL existente (não nova rota pública se possível).
3. Testes unitários: limpeza, rejeição de stuffing, preferência a identificador canônico.
4. Staging smoke em conta ≠1335; dry-run CLI se houver.
5. Entrada em `claude-progress.txt`; sem `project-status.json` sem evidência.

## Critérios de aceite
- [x] MODEL sugerido é identificador limpo (sem lista de busca).
- [x] Sinais de trends/autocomplete não viram valor bruto do atributo.
- [x] PHPUnit verde no filtro Model/TechSheet relacionado.
- [x] Staging smoke ≠1335.
- [x] Sem apply ML 1335 neste prompt.

## Aprovação
- [x] Jesse aprova este prompt (capability nova liberada) — via “continue” no prompt aberto
- [x] Após aprovação, agent implementa **somente** o escopo acima
