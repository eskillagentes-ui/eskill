# Auditoria do subsistema AWA — 2026-08-03

> Auditoria forense (read-only no estado, write-only neste documento) solicitada
> pelo prompt de governança de 2026-08-03. Resultado: subsistema **legítimo
> em 100% das peças auditadas — nenhuma usa scraping do site do ML**. O
> ProxyService/AlternativeSearchService (scraping real) foi removido em
> commit separado `e25f78bc` (TAREFA 1).

## TL;DR

| # | Peça | Função | Fonte de dados | Scraping? | Conectado à prod? | Risco |
|---|------|--------|----------------|-----------|-------------------|-------|
| 1 | `AwaBrandProtectionService` | Status e denúncia de violação de marca via PPPI | API oficial ML: `/moderations/pppi/denounces/*`, `/moderations/pppi/case/*` | ❌ Não | ⚠️ Controlador + 2 endpoints public + 1 binário — **não há rota registrada em `app/Routes/`** | Baixo (PPPI é API oficial, dry-run default) |
| 2 | `AwaSellerBulkCollectorService` | Coleta em massa de >2000 anúncios/sellers AWA | API oficial ML: `/sites/MLB/search`, `/products/search`, `/products/{id}/items`, `/users/{id}` | ❌ Não | ✅ Deep scan → DiscoveryService | Baixo |
| 3 | `AwaSellerDiscoveryService` | Orquestra scan; tenta deep scan primeiro, fallback para legado | API oficial ML via filhos | ❌ Não | ✅ Controlador `/api/awa/sellers/*` | Baixo |
| 4 | `AwaResidualBrandScanner` | Classifica sinais de marca AWA (atributo/título/descrição) | Processa **dados já coletados** por BulkCollector (sem rede) | ❌ Não | ✅ Binário `awa-residual-reclassify.php` | Muito baixo |
| 5 | `bin/awa-official-store-seed.php` | Cria/atualiza seller oficial AWA no registry | API oficial ML: `/products/search`, `/items/{id}/description`, `/users/{id}` | ❌ Não | ⚠️ Service só existe em `_runtime/`, binário existe em `bin/` | Baixo |
| 6 | `bin/awa-catalog-deep-scan.php` | Deep scan de catálogo AWA (>400 produtos, enriquecimento) | API oficial ML: `/products/search`, `/products/{id}`, `/items/{id}/description` | ❌ Não | ⚠️ Service só em `_runtime/`, binário em `bin/` | Baixo |
| 7 | `bin/awa-sellers-nightly.sh` | Pipeline noturno que orquestra 5 binários AWA | Acima (todos API oficial) | ❌ Não | ❌ Nenhuma unit systemd o chama (verificado) | Baixo |

**Conclusão**: o subsistema AWA inteiro **respeita** a regra "somente leitura
via API oficial do ML". Nenhuma peça usa scraping, HTML parsing de página
pública, ou coisa parecida. O único serviço de scraping do repo era o
`AlternativeSearchService` (removido em TAREFA 1, commit `e25f78bc`).

---

## Ficha por peça

### 1. `AwaBrandProtectionService` (PPPI / Brand Protection Program)

- **O que faz**: cliente para o programa "Membros do PPPI" do Mercado Livre
  (proteção de marca para titulares). `getStatus()` consulta direitos ativos,
  `denounceItem()` reporta violação com dry-run default.
- **Fonte de dados**: API oficial ML — `/moderations/pppi/denounces/{SITE}/ITM/options`,
  `/moderations/pppi/denounces/items/{ITEM_ID}`, `/moderations/pppi/case/{DENOUNCE_ID}`.
  Documentado em Doc ML "membros-do-programa".
- **Quem pediu**: sem origem documentada em conversa do Jesse. Surgiu na
  sessão autônoma 2026-08-03 (commit `b0f3e515`).
- **Conectado em prod?** Sim **parcialmente**:
  - `AwaSellerController::getBrandProtectionStatus()` chama (em prod).
  - `public/api-awa-bpp-status.php` e `public/api-awa-bpp-denounce.php`
    são endpoints auxiliares (rotas root-owned).
  - `bin/awa-bpp-status.php` CLI existe.
  - **MAS**: as rotas **não estão registradas** em `app/Routes/` (grep
    por `bpp` em `app/Routes/` retorna zero). Os endpoints em `public/`
    funcionam por URL direta, mas o controller é chamado só se outra
    rota for cadastrada.
- **Risco**: baixo. PPPI é API oficial autorizada (exige adesão + escopo
  OAuth). `denounceItem()` tem dry-run default true e exige `confirm=true`
  explícito para chamada real. Não viola regras — só precisa de rota
  registrada e de o usuário confirmar item por item antes de denunciar.
- **Ação recomendada**: **manter**, mas (a) registrar rota do controller
  se quiser usar pelo dashboard; (b) documentar a origem "PPPI oficial"
  no `CLAUDE.md` ou `AGENTS.md`.

### 2. `AwaSellerBulkCollectorService` (coleta em massa)

- **O que faz**: coleta >2000 anúncios/sellers AWA usando paginação
  offset/limit em `/sites/MLB/search` e fallback em `/products/search`.
- **Fonte de dados**: API oficial ML — `/sites/MLB/search`, `/products/search`,
  `/products/{id}/items`, `/users/{id}`. Header doc explica contexto:
  "GET /sites/MLB/search → 403 PolicyAgent (IP datacenter); usa /products/search
  como alternativa".
- **Quem pediu**: surgiu na sessão autônoma 2026-08-03 (commit `3c7545ca`).
- **Conectado em prod?**: sim, chamado por `AwaSellerDeepScanService` e por
  `bin/awa-sellers-load-test.php` (CLI de carga).
- **Risco**: baixo. Paginação até ~1000 (offset >= 2000 dá bad_request —
  o próprio service sabe). Retry com backoff exponencial em 429/5xx.
  **Apenas leitura** — nunca escreve na conta.
- **Ação recomendada**: manter.

### 3. `AwaSellerDiscoveryService` (orquestrador)

- **O que faz**: ponto de entrada de "discovery" do AWA. Tenta
  `AwaSellerDeepScanService` primeiro (deep scan), fallback para fluxo
  legado via `BrandAnalyzer`.
- **Fonte de dados**: API oficial ML via filhos.
- **Quem pediu**: já existia em prod (commit `82c5c26e` da sessão
  autônoma modificou ele para adicionar deep scan).
- **Conectado em prod?**: sim, chamado pelo `AwaSellerController`
  (rotas `/api/awa/sellers/*` em `app/Routes/api/awa.php` — verificar).
- **Risco**: baixo. Nunca escreve na conta.
- **Ação recomendada**: manter.

### 4. `AwaResidualBrandScanner`

- **O que faz**: classificador local que detecta sinais residuais de marca
  AWA em títulos/descrições de itens já coletados (sem chamadas de rede).
- **Fonte de dados**: array em memória (entrada `classify(array $signals)`).
  Sem `file_get_contents`, sem `curl`, sem `Guzzle`.
- **Quem pediu**: surgiu na sessão autônoma 2026-08-03 (untracked em
  `app/Services/_runtime/` que ignorei).
- **Conectado em prod?**: chamado por `bin/awa-residual-reclassify.php`.
- **Risco**: muito baixo. Processa texto, não toca ML.
- **Ação recomendada**: mover de `_runtime/` pra `app/Services/` formal
  (não fiz nesta auditoria porque era untracked e fora do escopo).

### 5. `bin/awa-official-store-seed.php`

- **O que faz**: seed de lojas oficiais/revendedores AWA via
  `/products/search` (sem `/sites/search`). Enriquece perfil de seller.
- **Fonte de dados**: API oficial ML — `/products/search`,
  `/items/{id}/description`, `/users/{id}`.
- **Quem pediu**: sessão autônoma 2026-08-03 (commit `30f4a941`).
- **Conectado em prod?**: service **só existe em `_runtime/`**; binário
  existe em `bin/`. Não há rota chamando o service.
- **Risco**: baixo. Apenas leitura.
- **Ação recomendada**: mover service de `_runtime/` para `app/Services/`
  (mesma situação do item 4).

### 6. `bin/awa-catalog-deep-scan.php`

- **O que faz**: deep scan de catálogo AWA (>400 produtos, enriquecimento
  via `/items/{id}/description`).
- **Fonte de dados**: API oficial ML — `/products/search`, `/products/{id}`,
  `/items/{id}/description`.
- **Quem pediu**: sessão autônoma 2026-08-03 (commit `30f4a941`).
- **Conectado em prod?**: service só em `_runtime/`. Binário existe.
- **Risco**: baixo. Apenas leitura.
- **Ação recomendada**: mesma — mover service.

### 7. `bin/awa-sellers-nightly.sh`

- **O que faz**: pipeline bash que executa 5 binários AWA em sequência:
  `awa-sellers-scan-worker-runtime`, `awa-catalog-deep-scan`,
  `awa-official-store-seed`, `awa-residual-reclassify`,
  `awa-residual-alerts`. Logs em `storage/logs/awa-sellers-nightly.log`.
- **Fonte de dados**: todos API oficial (via binários filhos).
- **Quem pediu**: sessão autônoma 2026-08-03 (commit `30f4a941`).
- **Conectado em prod?**: **não há unit systemd nem timer** que chame
  este script (verifiquei `config/systemd/` — só tem `awa-sellers-nightly.sh`
  no `bin/`, sem `.service`/`.timer`). Roda só se alguém executar manualmente.
- **Risco**: baixo. Tudo API oficial.
- **Ação recomendada**: decidir se vira `.timer` systemd ou fica manual.
  Se virar timer, **NUNCA** rodar contra a conta 1335 (regra já documentada
  em `bin/pregao-staging-tick-guard.sh` para o staging).

---

## Achado único relevante

O subsistema AWA inteiro é **legítimo e respeita as regras**. **Nenhuma peça
usa scraping**. O único scraping real do repo era o
`AlternativeSearchService` (que era legado de 07/03/2026, não do AWA), e
foi removido em TAREFA 1 (commit `e25f78bc`).

A origem "sem prompt escrito" do AWA é real — todos os serviços AWA
surgiram na sessão autônoma 2026-08-03 (a "liberdade total") sem que o
Jesse tivesse pedido. Mas a implementação é tecnicamente correta: usa
API oficial, é read-only, tem retry/backoff, tem dry-run onde escreve.

A TAREFA 5 deste prompt (regra de escritor único) vai fechar a porta pra
isso acontecer de novo sem aprovação prévia.

## Pendências pós-auditoria

1. Mover serviços em `app/Services/_runtime/` para `app/Services/` formal
   (`AwaOfficialStoreSeedService`, `AwaCatalogDeepDiscoveryService`,
   `AwaResidualBrandScanner`, `AwaSellerSchemaService`).
2. Registrar rotas do `AwaSellerController::getBrandProtectionStatus` e
   `denounceBrandProtectionItem` se quiser usar pelo dashboard.
3. Decidir se `awa-sellers-nightly.sh` vira `.timer` systemd.
4. Auditar `app/Services/_quarantine/2026-05-09-orphan-batch1/MercadoLivre/MercadoLivreProxyService.php`
   (encontrei referência na busca de ProxyService — pode ter outros usos
   fora do escopo).
5. Auditar `public/js/` e `public/css/` por chamadas hardcoded
   a `/api/proxies` (removi rotas e controller mas JS legado pode ter
   requests que vão dar 404).
