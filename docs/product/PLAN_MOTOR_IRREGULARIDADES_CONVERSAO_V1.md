# Plano Técnico — Motor de Irregularidades e Conversão (MLB / Facility)

**Documento:** PLAN-IRR-001
**Versão:** 1.0
**Status:** Proposto — aguarda aprovação humana
**Data:** 17/07/2026
**Branch:** `docs/ml-conversion-irregularities`
**Base:** `origin/master` @ `98e54f33`
**Piloto:** Facility · Marketplace: Mercado Livre (MLB)
**Fontes:** MCP Mercado Livre (qualidade-das-publicacoes, gerenciar-moderacoes, com-pausa, reputacao-de-vendedores, diagnostico-de-imagens) · inventário eSkill · código real

**Relacionados:**
- [Mapa de integração](../architecture/ML_SALES_BLOCKERS_INTEGRATION_MAP_V1.md)
- [ADR-003](../adr/ADR-003-FOCO-CONVERSAO-E-IRREGULARIDADES-ML.md)
- Inventário: branch `docs/eskill-module-inventory` → `docs/ESKILL_MODULE_INVENTORY.md`
- SEC-001 / ADR-001 (pré-condição de segurança multi-conta)

---

## 1. Objetivo

Concentrar o eSkill no que **aumenta exposição e desbloqueia vendas** no Mercado Livre, e no que **detecta irregularidades que pausam, revisam ou matam anúncios**.

Fora de escopo imediato: módulos que não convertem a conta/anúncio (clone em massa, ads automático, canais laterais, agentes autônomos).

```text
eSkill        → coleta sinais oficiais ML + executa correções aprovadas
Concept Engine→ prioriza, hypotetiza, aprende (futuro; não bloqueia este plano)
Humano        → aprova ações críticas
```

---

## 2. Problema confirmado no código

| Capacidade oficial ML | Endpoint | No eSkill hoje |
|----------------------|----------|----------------|
| Score + ações | `GET /item/{id}/performance` | **Presente** (`MercadoLivreClient::getItemPerformance`, AccountHealth, ListingSearchVisibilityService) |
| Última moderação | `GET /moderations/last_moderation/{ref}-ITM` | **Presente** (`MercadoLivreClient::getLastModeration` + `ListingIrregularityScanService`) |
| Histórico de infrações | `GET /moderations/infractions/{user_id}` | **Presente** (`MercadoLivreClient::getInfractions` + API `/api/listings/infractions`) |
| Diagnóstico de imagem pré-upload | `POST /moderations/pictures/diagnostic` | **Presente** (`MercadoLivreClient::diagnosePicture` + UI listing-visibility) |
| Busca itens `under_review` / `paused`+tag | `GET /users/{id}/items/search` | **Presente** no scan de irregularidades (materializa reason/remedy via last_moderation) |
| Reputação | `GET /users/{id}` → `seller_reputation` | **Parcial** |
| Compatibilidades autopeças | API de compatibilidades | **Presente** (Controllers Compatibility / BulkCompatibility) |
| Persistência fila (`SalesBlockerStore`) | — | **Presente** (`SalesBlockerStore` + `bin/sync-irregularities.php` + UI “Fila persistida”) |
| SEC-001 isolamento de contas | — | **Presente** nos caminhos críticos (Items/Orders/Listing/Compatibility/Questions/Messages/Pricing); expansão contínua |

**Conclusão (atualizada 2026-07-17):** coleta read-only + store + guards de escrita estão na branch. Próximo: smoke Facility com conta real e aprovação humana para qualquer escrita ML.

---

## 3. Sinais oficiais que travam ou reduzem venda

### 3.1 Bloqueio duro (prioridade máxima)

| Sinal ML | Como detectar | Ação típica (humano) |
|----------|---------------|----------------------|
| `under_review` + `waiting_for_patch` | items/search + last_moderation | Corrigir evidência e reativar |
| `under_review` + `forbidden` / `held` | items/search + last_moderation | Investigar; pode ser irrecuperável |
| `paused` + tag `moderation_penalty` | items/search + last_moderation | Preço incomum, abandono, etc. |
| `active` + `poor_quality_thumbnail` | tags + last_moderation | Corrigir foto (perda de exposição) |
| Brand protection / DENYLIST | last_moderation sem REMEDY | Não tentar auto-fix |

### 3.2 Redução de exposição (alavancagem)

Da API `/performance` (substitui `/health` desde fev/2025):

| Bucket / variável | Mode | Impacto |
|-------------------|------|---------|
| TITLE, PICTURES, GTIN, TECHNICAL_SPECIFICATIONS_MAIN | OPPORTUNITY / WARNING | Score 0–100; WARNING derruba score até corrigir |
| STOCK, FREE_SHIPPING, ME, FINANCING, AVAILABILITY | OPPORTUNITY | Competitividade da oferta |
| Níveis MLB | Básica / Satisfatória / Profissional | Campo `level_wording` |

### 3.3 Conta (reputação — MLB)

Limites oficiais (MCP reputacao-de-vendedores), termômetro Green:

| Métrica | Green (MLB) | Líderes |
|---------|-------------|---------|
| Claims | ≤ 2% | ≤ 1% |
| Cancellations | ≤ 1,5% | ≤ 0,5% |
| Delayed handling time | ≤ 10% | ≤ 6% |

### 3.4 Facility / autopeças

Compatibilidades incompletas → menos match na busca e mais perguntas/devoluções.
Módulo existente deve entrar na **fila de cobertura**, não em clone.

---

## 4. Arquitetura do Motor (somente leitura na Fase 1)

```text
                    ┌─────────────────────┐
  Webhooks items ──►│ IrregularityIngest  │
  Cron sync ───────►│  (eSkill)           │
                    └─────────┬───────────┘
                              │
                              ▼
                    ┌─────────────────────┐
                    │ SalesBlockerStore   │  snapshots sem tokens
                    │ (moderations,       │
                    │  performance,       │
                    │  reputation)        │
                    └─────────┬───────────┘
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
        Fila Urgente    Fila Exposição   Fila Conta
        (bloqueio)      (performance)    (reputação)
              │               │               │
              └───────────────┼───────────────┘
                              ▼
                    UI / Concept Engine
                    (draft + aprovação)
```

**Regras de segurança (obrigatórias):**
- Nunca persistir `access_token`, `refresh_token`, `client_secret`.
- Toda coleta exige `AuthorizedAccountContext` (SEC-001) — **não** `account_id` cru do request.
- Escrita no ML: **desligada** nesta fase (exceto leitura). Reativação de item = fase posterior + aprovação humana.

---

## 5. Vertical slice 1 (ponta a ponta, read-only)

1. Selecionar conta Facility autorizada (SEC-001).
2. Buscar itens:
   - `status=under_review`
   - `status=paused` + `tags=moderation_penalty`
   - `status=active` + tag `poor_quality_thumbnail` (quando aplicável)
3. Para cada item, chamar `GET /moderations/last_moderation/{ITEM_ID}-ITM`.
4. Normalizar: `name`, `reason`, `remedy`, `evidences`, `filter_subgroup` (via infractions se necessário).
5. Exibir fila: **bloqueados → perda exposição → oportunidade performance**.
6. Em paralelo (amostra ou lazy): `GET /item/{id}/performance` → rules PENDING/WARNING.
7. Registrar auditoria da sincronização (sem segredos).
8. **Não** PUT status=active; **não** alterar preço/título/foto.

**Critério de aceite do slice:**
- Lista de irregularidades com reason/remedy oficiais.
- Zero escrita no ML.
- Testes unitários do normalizador + contrato do client.
- PHPUnit suite verde.

---

## 6. Vertical slice 2 (alavancagem)

1. Para itens `active` com score &lt; limiar (ex.: &lt; 70) ou level Básica.
2. Extrair rules `WARNING` primeiro, depois `OPPORTUNITY`.
3. Mapear para rascunhos: atributos required, fotos, GTIN, título (handoff Concept Engine).
4. Compatibilidades: listar itens sem cobertura (BulkCompatibility já lista missing).

---

## 7. Vertical slice 3 (prevenção)

1. Antes de upload de imagem: `POST /moderations/pictures/diagnostic`.
2. Mostrar REMEDY_SHORT ao operador.
3. Não bloquear o fluxo se a API falhar (recomendação oficial ML); avisar.

---

## 8. O que congelar / arquivar / remover

| Classe | Domínios | Ação operacional |
|--------|----------|------------------|
| QUARENTENA | Clone*, pricing auto, SEO massa, ads auto, agents, decision, auto-replies | Workers off; sem novas features |
| ARQUIVAR | Shopee, EAN, WhatsApp/Telegram, Brevo, OpenClaw, AWA seller scan | Sem expansão; docs only |
| REMOVER | `.bak`, `_quarantine/orphan`, `ml-nlp-service/venv`, tests/manual | Remoção em PR de higiene separado |
| REFATORAR | MercadoLivreClient (SEC-001), logging/cache duplicados | SEC-001 antes de multi-conta produção |

**Não deletar código nesta fase** — apenas política de foco + workers.

---

## 9. Contratos internos (rascunho)

### 9.1 Evento `marketplace.listing.moderation.observed`

```json
{
  "event_id": "uuid",
  "schema_version": "1.0",
  "organization_id": 1,
  "account_id": 12,
  "listing_id": "MLB123",
  "correlation_id": "uuid",
  "occurred_at": "2026-07-17T15:00:00Z",
  "payload": {
    "status": "under_review",
    "sub_status": "waiting_for_patch",
    "moderation_name": "POOR_QUALITY_THUMBNAIL",
    "reason": "...",
    "remedy": "...",
    "filter_subgroup": "PQT",
    "severity": "block",
    "source": "ml_moderations"
  }
}
```

### 9.2 Evento `marketplace.listing.performance.observed`

```json
{
  "event_id": "uuid",
  "schema_version": "1.0",
  "organization_id": 1,
  "account_id": 12,
  "listing_id": "MLB123",
  "correlation_id": "uuid",
  "occurred_at": "2026-07-17T15:00:00Z",
  "payload": {
    "score": 69,
    "level_wording": "Satisfatória",
    "pending_warnings": 2,
    "pending_opportunities": 5,
    "top_actions": [
      {"key": "TS_MAIN_QUALITY_INCOMPLETE_REQUIRED", "mode": "WARNING", "label": "Completar características"}
    ],
    "source": "ml_performance"
  }
}
```

**Proibido no payload:** tokens, Authorization, senha, sessão.

---

## 10. Fases

| Fase | Entrega | Pré-condição |
|------|---------|--------------|
| 0 | SEC-001 wired no client (IDOR) | Branch `fix/sec001-core-policy` |
| 1 | Docs deste plano aprovados | Este PR documental |
| 2 | Slice 1 read-only moderations + UI fila | SEC-001 merged |
| 3 | Slice 2 performance + compat coverage | Fase 2 estável |
| 4 | Slice 3 picture diagnostic | Fase 3 |
| 5 | Handoff Concept Engine (eventos) | ADR-002 Concept Engine |

---

## 11. Riscos

| Risco | Mitigação |
|-------|-----------|
| Rate limit ML em last_moderation N itens | Batch + cache Redis + priorizar under_review |
| Auto-reativar item errado | Escrita desligada até política de aprovação |
| Pricing/clone automático → nova pausa DESC | Manter QUARENTENA |
| SEC-001 não mergeado | Não abrir multi-conta Facility/Falcão |
| Confundir score local com score ML | Sempre rotular fonte `ml_performance` |

---

## 12. Testes planejados (quando houver código)

- Unit: normalizador de moderação (reason/remedy/evidences).
- Unit: classificação de severidade (block / exposure / opportunity).
- Integration: client methods com HTTP mock (sem token real).
- Regressão: PHPUnit suite completa = 0 failures / 0 errors.
- Proibido: testes que escrevem em conta real no CI.

---

## 13. Decisões que precisam de aprovação humana

1. Aprovar este plano como **norte operacional** do eSkill Marketplace Core.
2. Confirmar congelamento de Clone/Ads/Pricing auto (sem delete).
3. Confirmar que escrita ML (reativar, patch) só após SEC-001 + UI de aprovação.
4. Prioridade relativa vs. push do PRD Concept Engine (`docs/concept-engine-prd`).
5. Nome do primeiro módulo PHP (sugestão: `App\Services\MercadoLivre\IrregularitySyncService`) — só após aprovação.

---

## 14. Recomendação

**Aprovado para revisão humana.**
Não implementar código até merge deste plano + SEC-001.

*Documento gerado com apoio do MCP Mercado Livre. Nenhum endpoint de escrita foi chamado; nenhum token foi manipulado.*
