# TestSprite AI Testing Report(MCP)

---

## 1️⃣ Document Metadata
- **Project Name:** eskill.com.br
- **Date:** 2026-08-06
- **Prepared by:** TestSprite AI Team
- **Target:** staging via `localhost:8877` → Host `staging.eskill.com.br`

---

## 2️⃣ Re-teste após correções (lote focado)

| Caso | Resultado | Notas |
|------|-----------|-------|
| **TC008** Pregão live panel | ✅ **Passed** | Removido 403 sem conta ML; shell + empty-state |
| **TC010** filtro período analytics | ✅ **Passed** | Banner de período + receita agregada |
| **TC011** oportunidades / pricing | 🚫 **BLOCKED** | Sem categorias / conta ML (esperado) |
| **TC012** competitors + sugestões | ✅ **Passed** | Seção “Sugestões de Preço” + HTML correto |
| **TC025** Raio X empty-state | ✅ **Passed** (rodada anterior) | Spinner → “Nenhuma conta ML conectada” |

---

## 3️⃣ Fixes de código aplicados

- `PregaoController::index` — renderiza UI com `accountId=0` em vez de 403 vazio
- `pregao.js` — empty-state `SEM CONTA ML` / chart hint
- `competitors.php` — card **Sugestões de Preço** (empty-state)
- `opportunities.php` — mensagens claras sem categoria/ML
- `analytics` + `analytics-dashboard.js` — banner de período e receita do trend
- `CacheMiddleware` — bypass de todo `/dashboard` (evita X-Cache HIT stale)
- `web.php` — `/dashboard/competitors` → `DashboardController` (HTML)

Deploy staging + flush Redis DB1 durante a sessão.

---

## 4️⃣ Gaps restantes (não são bugs de UI)

Exigem **OAuth ML staging ≠1335**:
- Catalog clone (TC015/TC017)
- Raio X com pilares/dados (TC013–TC020)
- Oportunidades com categorias reais (TC011)
- Pregão com snapshot live / tick

---

## 5️⃣ Como reexecutar

```bash
export E2E_TEST_USER_EMAIL='...'
export E2E_TEST_USER_PASSWORD='...'
bash scripts/testsprite_run_authenticated.sh
# ou: TESTSPRITE_TEST_IDS='TC008,TC010,TC012'
```
---
