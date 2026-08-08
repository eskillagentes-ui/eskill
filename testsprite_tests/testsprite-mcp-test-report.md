# TestSprite AI Testing Report(MCP)

---

## 1️⃣ Document Metadata
- **Project Name:** eskill.com.br (Ficha Técnica / SEO Killer)
- **Date:** 2026-08-07
- **Prepared by:** TestSprite AI Team + Cursor agent
- **Target:** `http://localhost:8877` → staging.eskill.com.br (DB `eskill_staging`)
- **Fixture:** `ml_accounts.id=1336` (`STAGING_UI_999001`), 25 items active — **nunca** conta ML 1335

---

## 2️⃣ Requirement Validation Summary

### Requirement: Ficha Técnica (SEO Killer tab)
- **Description:** Aba `#technical-sheet` lista anúncios active, KPIs, paginação e toolbar (Aprovar/Aplicar/Completo) sem depender de worker.

#### Test TC037 Open SEO Killer Ficha Técnica tab and see KPIs plus list
- **Test Code:** [TC037_Open_SEO_Killer_Ficha_Tcnica_tab_and_see_KPIs_plus_list.py](./TC037_Open_SEO_Killer_Ficha_Tcnica_tab_and_see_KPIs_plus_list.py)
- **Test Visualization and Result:** (execução anterior nesta onda)
- **Status:** ✅ Passed
- **Severity:** LOW
- **Analysis / Findings:** KPIs + área `#tech-sheet-list` visíveis após login e navegação para Ficha Técnica.

#### Test TC038 Ficha Técnica list shows pagination controls when many items
- **Test Code:** [TC038_Ficha_Tcnica_list_shows_pagination_controls_when_many_items.py](./TC038_Ficha_Tcnica_list_shows_pagination_controls_when_many_items.py)
- **Test Visualization and Result:** https://www.testsprite.com/dashboard/mcp/tests/42cd6a03-b355-4d27-829c-5e31c8068bed/test/fdf7cc68-9655-4d27-829c-5e31c8068bed
- **Status:** ✅ Passed
- **Severity:** MEDIUM
- **Analysis / Findings:**
  - Antes: bloqueado (`Nenhum anúncio` / script com `assert False`) por staging sem items **e** bug de conta: `TechnicalSheetController` usava só `BaseController::getActiveAccountId()` (sessão), sem fallback `SessionHelper` → API respondia `Nenhuma conta conectada` mesmo com `users.active_ml_account_id=1336`.
  - Depois: seed 25 items + fix `SessionHelper::getActiveAccountId() ?? getActiveAccountId()`. API: `total=25, pages=2`. TestSprite regenerado viu botão **Próxima** e linhas na tabela → **PASSED**.

#### Test TC039 Ficha Técnica approve button is enabled without selection
- **Test Code:** [TC039_Ficha_Tcnica_approve_button_is_enabled_without_selection.py](./TC039_Ficha_Tcnica_approve_button_is_enabled_without_selection.py)
- **Status:** ✅ Passed (execução anterior; empty-state toolbar + Completo)
- **Severity:** LOW
- **Analysis / Findings:** Toolbar com “Aprovar pendentes” e link Completo → `/dashboard/tech-sheet` permanecem visíveis.

---

## 3️⃣ Coverage & Matching Metrics

- **100%** dos testes Ficha Técnica desta onda (TC037–TC039) passaram na última execução conhecida de cada um.

| Requirement | Total Tests | ✅ Passed | ❌ Failed |
|-------------|-------------|-----------|-----------|
| Ficha Técnica (SEO Killer) | 3 | 3 | 0 |

---

## 4️⃣ Key Gaps / Risks

1. **Apply no ML (prod 1335)** ainda não executado de propósito — exige OK explícito do dono.
2. Fixture staging tem tokens dummy; serve UI/lista/paginação, **não** sync real com API ML.
3. Script TC038 exportado pelo agente ainda clica várias vezes em chat/Minimizar (ruído); assertions finais estão corretas.
4. Instrumentação de debug (`TechnicalSheetController` staging) ainda ativa até confirmação do usuário.
5. Re-seed: se limpar `eskill_staging`, recriar conta `1336` + 25 items antes de TC038.

### Reproduzir
```bash
# proxy :8877 já usado pelo TestSprite
export TESTSPRITE_TEST_IDS='TC037,TC038,TC039'
# via MCP: testsprite_generate_code_and_execute com testIds
```
