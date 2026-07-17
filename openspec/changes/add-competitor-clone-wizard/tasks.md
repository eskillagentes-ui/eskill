## Fase 0 — Alinhamento e Guardrails (obrigatório)
- [x] 0.1 Definir modo seguro padrão (não copiar descrição/imagens automaticamente)
- [x] 0.2 Definir quais campos são “públicos e clonáveis” vs “restritos/não clonáveis”
- [x] 0.3 Definir texto de confirmação e flags de execução (ex.: `include_description`, `include_pictures`)
- [x] 0.4 Validar limites operacionais (rate limit, paginação, limites por job)

## Fase 1 — Backend: origem por seller + facets + filtros
- [x] 1.1 Estender `listSellerItems(sellerId)` para incluir facet de categoria (contagem por `category_id`)
- [x] 1.2 Adicionar endpoint para “resumo do seller” com categorias + marcas (amostra controlada)
- [x] 1.3 Adicionar suporte a filtro por marca e categoria no backend (com paginação consistente)
- [x] 1.4 Adicionar endpoint de “prévia de preço” (simular estratégia em cima dos itens selecionados)

## Fase 2 — Frontend: Wizard de clonagem por concorrente
- [x] 2.1 UI: campo `sellerId` + botão “Buscar loja” + resumo (nickname, reputação, total)
- [x] 2.2 UI: lista paginada dos anúncios com agrupamento por categoria
- [x] 2.3 UI: filtro por marca (dropdown/auto-complete) e busca
- [x] 2.4 UI: seleção em massa (por categoria/por marca/todos)
- [x] 2.5 UI: ajuste de preço (copy/markup/markdown/competitivo) + preview antes de executar
- [x] 2.6 UI: botão “Clonar tudo selecionado” (confirmações e guardrails)

## Fase 3 — Processamento em massa (jobs) e confiabilidade
- [x] 3.1 Criar “job pai” em `catalog_clone_jobs` com `source_type=seller` e filtros (seller/category/brand)
- [x] 3.2 Gerar itens filhos em `catalog_clone_job_items` e vincular ao job
- [x] 3.3 Worker: rate limit/backoff e tolerância a erros (erros por item sem matar o job)
- [x] 3.4 Idempotência/anti-duplicidade: evitar re-clone do mesmo source→target quando já existe
- [x] 3.5 Snapshot/cache opcional do catálogo do seller para grandes volumes (limite/TTL) <!-- seller_catalog_snapshots + getSellerSnapshot/saveSellerSnapshot em CatalogCloneService -->

## Fase 4 — Observabilidade e operação
- [x] 4.1 Métricas: tempo por item, erros top, taxa de duplicados
- [x] 4.2 Monitoramento: healthcheck/flags e alertas
- [x] 4.3 UX: tela de acompanhamento do job (progresso, falhas, reprocessar)

## Fase 5 — Testes e documentação
- [x] 5.1 Teste de integração: seller → filtros → job → worker <!-- tests/Unit/Services/CatalogCloneWizardFlowTest.php -->
- [x] 5.2 Documentação: guia de uso (limites, boas práticas, troubleshooting) <!-- docs/guides/COMPETITOR_CLONE_WIZARD.md -->
