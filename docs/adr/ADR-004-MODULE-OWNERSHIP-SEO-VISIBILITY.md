# ADR-004 — Ownership canônico: SEO, Visibilidade e Diagnóstico

**Status:** Aceito
**Data:** 2026-07-17
**Contexto:** Múltiplos módulos calculavam “saúde/SEO/visibilidade” com scores e menus sobrepostos.

## Decisão

Cada superfície tem um dono claro. Não fundir controllers “god”; consolidar navegação, rotas e fontes de dados.

| Módulo | Papel canônico | Fonte de verdade |
|--------|----------------|------------------|
| **Visibilidade ML** (`/dashboard/listing-visibility`) | Exposição oficial + irregularidades (read-only) | `/item/{id}/performance`, moderations |
| **Diagnóstico Conta** (`/dashboard/account-health`) | Diagnóstico executivo multi-pilar | Aggregate interno |
| **Raio X** (`/dashboard/raio-x`) | Recuperação profunda / relatórios X-ray | AccountXRayService |
| **Validação Anúncios** (`/dashboard/quality`) | Validação pré-publicação + score **internal_quality** | Quality* services; `api_health` via performance |
| **SEO Killer** | Hub de otimização/ação (título, attrs, A/B, spy) | Killer engines; **não** redefine exposição oficial |
| **Ficha Técnica** | Workspace avançado de atributos | `/dashboard/tech-sheet` (alias legado redireciona) |
| **Central de IA** | Orquestração/automações cross-domain | `/dashboard/ai-center` |
| **Insights SEO (IA)** | Insights de listing dentro do SEO Killer | `#ai-insights` |
| **Concorrentes** | Watchlist de sellers | `/dashboard/competitors` |
| **Espião SEO** | Benchmark ad-level on-demand | Só dentro do SEO Killer |

### Taxonomia de scores

- `official_ml` — resposta ML (`/performance`, purchase experience, moderations)
- `internal_quality` — heurística Quality / HealthCheck local
- `seo_audit` — auditorias SEOTools / SEOAudit
- `account_aggregate` — Account Health

### Rotas

- `POST /api/seo/analyze` → **SEOToolsController** (canônico)
- `POST /api/seo/ai/analyze` → SEOApiController (legado IA)
- `GET /dashboard/seo/ficha-tecnica` → 301 `/dashboard/tech-sheet`
- APIs `api/competitors` só em `app/Routes/api/items.php`

## Consequências

- Menus não devem listar o mesmo destino com nomes diferentes.
- Quality não usa mais `/items/{id}/health` (deprecated ML fev/2025).
- Merge de engines de score/bulk/competitors fica fora deste ADR (migração futura).
