# Inventário Técnico e Funcional — eSkill

**Versão:** 1.0  
**Status:** Concluído — análise estática e leitura de código  
**Data:** 2026-07-17  
**Branch:** docs/eskill-module-inventory  
**Base:** origin/master @ 98e54f33  
**Baseline de testes:** 4055 testes · 9367 assertions · 0 failures · 0 errors · 26 skipped  
**Documentos soberanos lidos:** 00_MASTER_PLAN · 01_CONSTITUICAO · AUDITORIA_TECNICA_V1 · SEC-001 · ADR-001 · PRD  
**Escopo analisado:** 135 controllers · 371 services · 305 tabelas · 88 workers · 9 middleware · 5 camadas de rota

---

## 1. Parecer executivo

O eSkill possui uma integração com o Mercado Livre madura o suficiente para ser aproveitada como
**núcleo operacional de marketplace (Marketplace Core)**. OAuth, renovação de tokens, sincronização
de anúncios, pedidos, perguntas e webhooks estão implementados e testados.

Porém, o sistema cresceu sem uma política central de autorização de contas — resultando na
vulnerabilidade **SEC-001 (IDOR provável)**. Além disso, acumulou módulos de automação não
validados, múltiplas implementações concorrentes para as mesmas responsabilidades, e integrações
fora do escopo atual (Shopee, EAN, WhatsApp, Telegram).

**Divisão de responsabilidades confirmada:**
- eSkill: conecta, coleta, sincroniza, executa ações autorizadas, fornece API.
- Plataforma de inteligência: investiga, compara, aprende, prioriza.
- CRM: organiza tarefas, controla prazos, registra aprovações.
- Hermes: coordena agentes.
- Humano: aprova decisões críticas.

---

## 2. Dimensão real do projeto

| Artefato | Quantidade |
|----------|-----------|
| Controllers | 135 |
| Services | 371 |
| Tabelas no schema | 305 |
| Workers/scripts bin/ | 88 |
| Arquivos de teste PHPUnit | 305 |
| Migrations | 123+ |
| Rotas registradas (estimado) | 1.712 |
| Rotas duplicadas | 27 |

---

## 3. Classificações — Definições

| Classificação | Critério |
|--------------|---------|
| **CORE** | Indispensável para o eSkill funcionar como Marketplace Core |
| **SENSOR** | Fornece dados para inteligência externa; não precisa ser módulo operacional principal |
| **REFATORAR** | Útil mas com problemas de acoplamento, duplicação, segurança ou responsabilidade mista |
| **QUARENTENA** | Não pode executar ações reais até ser validado; código permanece, workers desligados |
| **ARQUIVAR** | Fora do escopo atual, potencialmente útil no futuro |
| **REMOVER** | Obsoleto, duplicado, morto ou artefato de ambiente/backup |

---

## 4. Inventário por domínio

---

### CORE — Domínios indispensáveis

---

#### D-01 · Autenticação e OAuth Mercado Livre

**Classificação: CORE**

| Componente | Arquivos/Tabelas |
|-----------|-----------------|
| Controllers | AuthController · AuthApiController · MobileAuthController |
| Services | AuthService · MercadoLivreAuthService · RefreshTokenService · SecureTokenService · JwtService · ApiKeyService · ApiTokenService · TwoFactorService · PasswordResetService · UnifiedTokenRefreshService |
| Middleware | AuthMiddleware · ApiAuthMiddleware · CsrfMiddleware |
| Tabelas | users · user_sessions · refresh_tokens · remember_tokens · password_resets · api_tokens · user_api_keys · token_refresh_audit |
| Workers | auto-token-refresh-worker.php |
| Rotas | auth/login · auth/logout · auth/register · auth/authorize · auth/callback · auth/2fa/* |
| Testes | AuthTest · AuthServiceUnitTest · MercadoLivreAuthServiceUnitTest · MLOAuthFlowTest · TokenRefreshCycleTest · SecureTokenServiceUnitTest |

**Justificativa:** Sem autenticação de usuários e OAuth Mercado Livre o sistema é inoperante.
O refresh automático de tokens é crítico para manter sessões ML ativas.

**Notas:** AuthService possui múltiplas responsabilidades (login, 2FA, verificação de e-mail,
API auth) — candidato a refatoração futura, mas não nesta fase.

---

#### D-02 · Contas Mercado Livre (multi-conta)

**Classificação: CORE + REFATORAR (SEC-001 — Prioridade Crítica)**

| Componente | Arquivos/Tabelas |
|-----------|-----------------|
| Controllers | MultiAccountController |
| Services | MercadoLivreClient (**SEC-001**) · AccountSyncService |
| Middleware | AccountContextMiddleware (**SEC-001**) |
| Security | app/Security/ApiRouteScopeResolver.php |
| Tabelas | ml_accounts · ml_sync_status |
| Testes | MercadoLivreAccountPersistenceTest · MultiAccountControllerTest |

**Evidência do problema (SEC-001):**

MercadoLivreClient.php aceita accountId de fontes não validadas:

```
linha 131: $_SESSION['active_ml_account_id']
linha 145: $_SERVER['HTTP_X_ML_ACCOUNT_ID']
linha 151: $_GET['ml_account_id'] / $_GET['account_id']
linha 152: $_POST['ml_account_id'] / $_POST['account_id']
linha 170: ML_ACCESS_TOKEN env (fallback global)
```

AccountContextMiddleware.php linha 32:
`define('CURRENT_ACCOUNT_ID', $accountId)` — constante global sem validação de propriedade.

**Correção:** PR funcional 1 — SEC-001 (AccountAccessPolicy + AuthorizedAccountContext).

---

#### D-03 · Anúncios e Items

**Classificação: CORE**

| Componente | Arquivos/Tabelas |
|-----------|-----------------|
| Controllers | ItemController · CatalogController · BulkEditorController · ListingBuilderController |
| Services | ItemService · ItemSyncService · ItemMetricsService · ListingBuilderService |
| Tabelas | items · item_metrics · item_metrics_history · ml_anuncios_awa |
| Workers | items-sync-worker.php · sync-items.php · sync-now.php |
| Testes | ItemListMLTest · ItemControllerTest · BulkEditorControllerTest |

**Nota SEC-001:** ItemController linha 19 aceita account_id direto do request:
`$accountId = $this->request->get('account_id') ?? SessionHelper::getActiveAccountId();`

---

#### D-04 · Pedidos

**Classificação: CORE + SENSOR**

| Componente | Arquivos/Tabelas |
|-----------|-----------------|
| Controllers | OrderController · OrdersController |
| Services | OrderService |
| Tabelas | ml_orders · order_items · ml_pedidos_awa · ml_payments |
| Workers | orders-sync-worker.php |

**CORE:** sincronizar pedidos, exibir histórico, processar webhooks de pedido.  
**SENSOR:** pedidos = sinal de conversão, receita, frequência de venda.

---

#### D-05 · Perguntas

**Classificação: CORE + SENSOR**

| Componente | Arquivos/Tabelas |
|-----------|-----------------|
| Controllers | QuestionController |
| Services | QuestionService |
| Tabelas | ml_questions · questions |
| Workers | questions-sync-worker.php |
| Testes | QuestionTest |

**CORE:** sincronizar e exibir perguntas de compradores.  
**SENSOR:** perguntas = sinal de intenção de compra e gaps de informação no anúncio.

---

#### D-06 · Webhooks

**Classificação: CORE**

| Componente | Arquivos/Tabelas |
|-----------|-----------------|
| Controllers | MercadoLivreWebhookController · WebhookController |
| Services | MercadoLivreWebhookService · WebhookInboxService · MercadoLivreWebhookReplayService |
| Tabelas | webhook_events · webhook_event_inbox · webhook_logs · webhooks · webhook_receipts |
| Testes | WebhookProcessTest · MercadoLivreWebhookIngressHardeningTest · MercadoLivreWebhookInboxQueueTransitionTest |

---

#### D-07 · Workers de Sincronização

**Classificação: CORE**

| Worker | Função |
|--------|-------|
| auto-token-refresh-worker.php | Mantém tokens ML válidos |
| items-sync-worker.php | Sincroniza anúncios |
| orders-sync-worker.php | Sincroniza pedidos |
| questions-sync-worker.php | Sincroniza perguntas |
| shipments-sync-worker.php | Sincroniza remessas |
| stock-sync-worker.php | Sincroniza estoque |
| sync-items.php · sync-now.php | Sincronização imediata |

---

#### D-08 · Framework Core PHP

**Classificação: CORE**

| Componente | Arquivos |
|-----------|---------|
| Core | Router · Container · Request · Config · ErrorHandler · ExceptionHandler · EventBus · Collection · Paginator · QueryBuilder · Pipeline · Flash · Validator |
| Helpers | Functions.php · ViewHelper.php · ResponseHelper.php · CacheHelper.php · SessionHelper.php · EnvValidator.php |
| Base | app/Router.php · app/Database.php |
| Testes | RouterTest · ContainerTest · CollectionTest · QueryBuilderTest · PaginatorTest · PipelineTest · EventBusTest |

---

#### D-09 · Auditoria e Logging Canônico

**Classificação: CORE**

| Componente | Arquivos/Tabelas |
|-----------|-----------------|
| Service canônico | AuditLogService |
| Tabelas | audit_logs · activity_logs · security_audit_log |
| Testes | AuditServiceTest |

**Nota:** Os outros 4 serviços de logging estão classificados como REFATORAR (ver D-20).

---

### SENSOR — Provedores de dados para inteligência

---

#### D-10 · Estoque

**Classificação: SENSOR**

Services: InventoryService · StockSyncService  
Tabelas: stock_sync_history · inventory_alerts · inventory_movements · inventory_reservations  
Nota: ERP é a fonte soberana. eSkill é receptor e transformador, não gestor.

---

#### D-11 · Preço (histórico e leitura)

**Classificação: SENSOR**

Services: PriceHistoryService · PriceAnalyticsService · SalesAnalyticsService  
Tabelas: price_history · pricing_history · competitor_price_history  
Nota: Execução de mudanças de preço → QUARENTENA (D-25).

---

#### D-12 · Saúde e Reputação da Conta

**Classificação: SENSOR**

Controllers: AccountHealthController · AccountXRayController  
Services: AccountHealthService · AccountXRayService · AccountGovernanceService  
Tabelas: account_health_history · account_xray_reports · reputation_history  
Workers: governance-diagnostic-worker.php · ml-health-check.php

---

#### D-13 · Ads (leitura/dados)

**Classificação: SENSOR**

Services: AdsService  
Tabelas: ads_campaigns_cache · ads_metrics_history  
Nota: Execução de mudanças em Ads → QUARENTENA (D-31).

---

#### D-14 · Logística

**Classificação: SENSOR**

Services: ShippingService · ShipmentSyncService  
Tabelas: shipments · fulfillment_inbound_shipments

---

#### D-15 · Concorrentes (leitura)

**Classificação: SENSOR**

Services: CompetitorAnalysisService · CompetitorService  
Tabelas: competitor_history · competitor_prices · competitor_items · competitor_intelligence  
Nota: Monitor automático de concorrentes → QUARENTENA (D-30).

---

#### D-16 · SEO (análise e score)

**Classificação: SENSOR**

Controllers: SEOController · SeoOptimizationController · SeoCoverageController  
Services: SeoAnalyzerService · SEOOptimizerService · SEOAuditService  
Tabelas: seo_scores · seo_item_scores · seo_audits · seo_optimization_history  
Workers: seo-metrics-worker.php · seo-performance-worker.php  
Nota: Edição automática de anúncios → QUARENTENA.

---

#### D-17 · Financeiro (leitura)

**Classificação: SENSOR**

Services: FinancialService · SettlementService · MarginCalculatorService  
Tabelas: financial_settlements · settlements · ml_payments

---

### REFATORAR — Úteis mas com problemas estruturais

---

#### D-18 · MercadoLivreClient — SEC-001

**Classificação: REFATORAR — Prioridade Crítica**

Arquivo: app/Services/MercadoLivreClient.php  

Problema: aceita accountId de sessão, header HTTP, GET e POST sem validação de propriedade.
loadAccount() consulta tokens por id sem verificar pertencimento ao ator autenticado.

Dependências (consumidores diretos):
ItemController · OrderController · SyncController · AdsController · QuestionController
CatalogCloneController · CompetitorController · StockSyncController

Correção: introduzir AccountAccessPolicy + AuthorizedAccountContext (SEC-001 PR funcional 1).

---

#### D-19 · AccountContextMiddleware — SEC-001

**Classificação: REFATORAR — Prioridade Crítica**

Arquivo: app/Middleware/AccountContextMiddleware.php  
Problema: define('CURRENT_ACCOUNT_ID', $accountId) — constante global sem validação de propriedade.  
Correção: substituir por AccountContextResolver que usa AccountAccessPolicy.

---

#### D-20 · Logging — 5 implementações paralelas

**Classificação: REFATORAR**

Implementações concorrentes:
- AuditLogService.php (canônico — referenciar)
- LoggerService.php
- LoggingService.php
- LogService.php
- CentralizedLogService.php
- StructuredLogService.php

Estratégia: mapear consumidores, migrar para AuditLogService, deprecar demais.

---

#### D-21 · Cache — 4 implementações paralelas

**Classificação: REFATORAR**

- CacheService.php (canônico)
- AdvancedCacheService.php
- AdvancedRedisCacheService.php
- CacheManagerService.php

---

#### D-22 · AI Services — sobreposição

**Classificação: REFATORAR**

Legados: AIService.php · LLMService.php · UnifiedAIService.php · ClaudeClient.php  
Arquitetura atual (correta): app/Services/AI/Providers/ com AbstractAIProvider,
ClaudeProvider, OpenAIProvider, GeminiProvider via AIProviderManager.

Consumidores dos legados: AICenterController · ChatbotAIController · AIOptimizationController  
Estratégia: migrar consumidores para AIProviderManager, depois deprecar legados.

---

#### D-23 · TechSheet — explosão de serviços

**Classificação: REFATORAR**

14 services para um único domínio:
TechSheetService · TechSheetAutoOptimizerService · TechSheetBatchOptimizerService
TechSheetBenchmarkService · TechSheetChartsService · TechSheetEmailService
TechSheetExportService · TechSheetNotificationService · TechSheetSchedulerService
TechSheetSEOIntegrationService · TechSheetSmartGapFillerService · TechSheetWebhookService
TechSheetAlertService · TechSheetAnalyticsService

14 tabelas associadas (tech_sheet_*). Necessita consolidação de responsabilidades.

---

#### D-24 · Rotas duplicadas

**Classificação: REFATORAR**

27 rotas duplicadas identificadas na auditoria técnica.
Exemplo: login e auth/login mapeiam para o mesmo handler.
Ação: auditar cada duplicata — distinguir alias intencional de bug de registro.

---

### QUARENTENA — Código existente; workers desligados por padrão

---

#### D-25 · Precificação automática

**Classificação: QUARENTENA**

Services: AutoPricingOptimizerService · DynamicPricingService · PriceRulesEngineService
         ScheduledPriceService · PricingStrategyService · PricingScenarioService  
Workers: auto-pricing-optimizer.php · scheduled-price-worker.php · pricing-worker.php · rules-engine-worker.php  
Tabelas: automation_rules · price_adjustments · pricing_rule_executions · pricing_campaigns

Risco: alteração automática de preço em contas reais sem aprovação humana.
Toda execução de mudança de preço exige aprovação humana.

---

#### D-26 · Clonagem automática de catálogo

**Classificação: QUARENTENA**

29 services (Clone*Service) · 12 controllers (Clone*Controller)  
Workers: catalog-clone-worker.php · clone-automation-worker.php · clone-ab-testing-worker.php
         clone-scheduler-worker.php · clone-sync-worker.php · clone-post-actions-worker.php  
Tabelas: catalog_clone_jobs · cloned_items · clone_schedules · clone_metrics (e 14 outros)

Risco: publicar anúncios clonados sem revisão pode criar listings inválidos e violar
políticas do Mercado Livre.

---

#### D-27 · SEO automático em massa

**Classificação: QUARENTENA**

Services: AISEOOptimizerService · BulkSEOService · AIContentGeneratorService  
Workers: bulk-seo-worker.php · seo-worker.php  
Tabelas: seo_bulk_jobs · bulk_seo_jobs · active_optimizations

Risco: edição automática de títulos e descrições sem revisão pode degradar anúncios.

---

#### D-28 · Motor de decisão e automações

**Classificação: QUARENTENA**

Services: DecisionEngineService · AutomationOrchestratorService · AutonomousAgentService
          AssistantActionExecutorService  
Workers: rules-engine-worker.php · ml-orchestrator.php · ml-auto-improve.php  
Tabelas: automation_workflows · automation_rules · autonomous_strategies · autopilot_config

Risco: execução de ações em contas reais por motor heurístico sem validação humana.

---

#### D-29 · Respostas automáticas

**Classificação: QUARENTENA**

Services: ChatbotAIService  
Tabelas: auto_responses · chatbot_conversations · chatbot_sessions

Risco: respostas incorretas ou inadequadas a compradores.

---

#### D-30 · Monitor de concorrentes (execução automática)

**Classificação: QUARENTENA**

Services: CompetitorMonitorService  
Workers: competitor-monitor-worker.php · awa-sellers-scan-worker.php  
Tabelas: monitored_competitors · competitor_logs

Risco: raspagem massiva pode violar ToS do Mercado Livre e gerar bloqueio de conta.

---

#### D-31 · Ads (execução automática)

**Classificação: QUARENTENA**

Services: AdsWizardService (automação)

Risco: alteração de orçamento ou status de campanhas sem aprovação humana.

---

#### D-32 · Agentes autônomos

**Classificação: QUARENTENA**

Controllers: AgentController · AutomationController  
Services: AutonomousAgentService · AssistantActionExecutorService  
Tabelas: agent_features · agent_progress_log · agent_projects

Risco: execução sem supervisão humana.

---

### ARQUIVAR — Fora do escopo atual; potencialmente útil no futuro

---

#### D-33 · Shopee

**Classificação: ARQUIVAR**

Controllers: ShopeeController  
Services: ShopeeService  
Tabelas: shopee_auth · shopee_items  
Rotas: zero referências ativas (confirmado por grep nas routes)

Justificativa: fora do escopo do primeiro marketplace (Mercado Livre). A Constituição
prevê outros marketplaces no futuro.

---

#### D-34 · EAN

**Classificação: ARQUIVAR**

Controllers: EanController  
Services: EanService · EanNotificationService · EanReportService  
Tabelas: ean_assignments · ean_balances · ean_inventory · ean_packages · ean_purchases
         ean_settings · ean_transactions (7 tabelas)  
Workers: ean-payment-reconcile-worker.php  
Rotas: zero referências ativas

---

#### D-35 · WhatsApp e Telegram

**Classificação: ARQUIVAR**

Controllers: WhatsAppController  
Services: WhatsAppService · TelegramService  
Tabelas: whatsapp_logs · whatsapp_settings

---

#### D-36 · OpenClaw Connector

**Classificação: ARQUIVAR**

Controllers: OpenClawConnectorController  
Services: OpenClawConnectorService  
Tabelas: openclaw_webhooks

---

#### D-37 · Brevo (e-mail marketing)

**Classificação: ARQUIVAR**

Controllers: BrevoIntegrationController  
Services: Brevo/* (app/Services/Integrations/)  
Tabelas: brevo_contacts · brevo_lists · brevo_sync_runs

Justificativa: e-mail marketing não é responsabilidade do eSkill conforme a Constituição.

---

#### D-38 · AWA Seller Scanning

**Classificação: ARQUIVAR**

Controllers: AwaSellerController  
Services: AwaSellerDiscoveryService · AwaSellerIdentificationService · AwaSellerAlertService
          AwaSellerExportService · AwaSellerRegistryService  
Tabelas: awa_seller_identification · awa_seller_items · awa_seller_registry · awa_scan_runs  
Workers: awa-sellers-scan-worker.php · brand-search-worker.php

---

### REMOVER — Evidência de obsolescência ou artefato indevido

---

#### D-39 · MercadoLivreClient.php.bak-20260509-160404

**Classificação: REMOVER**

Arquivo: app/Services/MercadoLivreClient.php.bak-20260509-160404  
Evidências: backup explicitamente nomeado com data (09/05/2026); sem consumidores;
sem import; sem rota; 2+ meses sem restauração.  
Rollback: git log -- app/Services/MercadoLivreClient.php preserva todo histórico.

---

#### D-40 · _quarantine/2026-05-09-orphan-batch1

**Classificação: REMOVER**

Pasta: app/Services/_quarantine/2026-05-09-orphan-batch1/  
Conteúdo: AI/ · MercadoLivre/ · SEO/ · Webhooks/  
Evidências: pasta explicitamente nomeada como quarentena com data; 2+ meses sem restauração;
nenhum import ativo identificado.  
Rollback: git history preserva tudo; tag backup/master-pre-sec001-sync-20260716 disponível.

---

#### D-41 · ml-nlp-service/venv

**Classificação: REMOVER**

Pasta: ml-nlp-service/venv/ (bin/ · include/ · lib/ · pyvenv.cfg)  
Evidência: virtualenv Python — artefato de ambiente; não deve ser versionado.  
Rollback: não aplicável. Recriar com: python3 -m venv venv && pip install -r requirements.txt

---

#### D-42 · tests/manual/*.php e tests/scripts/test_*.php

**Classificação: REMOVER**

Arquivos: tests/manual/check_db.php · verify_backend.php · verify_metrics_data.php
          verify_orders_api.php · verify_system.php · test_real_ml_api.php
          tests/scripts/test_all_modules.php · test_classes.php · test_competitors_route.php
          tests/system_verification.php · test_account_management.php · manual-account-health-test.php
          (12+ arquivos)  
Evidências: nenhum arquivo usa PHPUnit; nenhum aparece em phpunit.xml; scripts ad-hoc de
diagnóstico sem estrutura de teste formal.  
Rollback: git history preserva. Se necessário para uso operacional, mover para scripts/diagnostics/.

---

## 5. Tabela consolidada de classificações

| # | Domínio | Classe | Negócio | ML | Qualidade | Testes | Segurança | Risco Op. |
|---|---------|--------|---------|-----|-----------|--------|-----------|----------|
| D-01 | Autenticação e OAuth | CORE | 5 | 5 | 3 | 4 | 4 | 2 |
| D-02 | Contas ML (multi-conta) | CORE+REFATORAR | 5 | 5 | 2 | 3 | 0 | 1 |
| D-03 | Anúncios e Items | CORE | 5 | 5 | 3 | 3 | 2 | 2 |
| D-04 | Pedidos | CORE+SENSOR | 5 | 5 | 4 | 3 | 3 | 2 |
| D-05 | Perguntas | CORE+SENSOR | 4 | 4 | 4 | 3 | 3 | 2 |
| D-06 | Webhooks | CORE | 5 | 5 | 4 | 4 | 3 | 2 |
| D-07 | Workers de sincronização | CORE | 5 | 5 | 3 | 2 | 3 | 2 |
| D-08 | Framework Core PHP | CORE | 5 | 3 | 4 | 5 | 4 | 1 |
| D-09 | Auditoria/Log canônico | CORE | 4 | 3 | 4 | 3 | 5 | 1 |
| D-10 | Estoque | SENSOR | 4 | 4 | 3 | 3 | 3 | 2 |
| D-11 | Preço (histórico) | SENSOR | 4 | 5 | 3 | 3 | 3 | 1 |
| D-12 | Saúde e Reputação | SENSOR | 4 | 5 | 3 | 3 | 3 | 1 |
| D-13 | Ads (leitura) | SENSOR | 4 | 5 | 3 | 3 | 3 | 1 |
| D-14 | Logística | SENSOR | 3 | 4 | 3 | 2 | 3 | 1 |
| D-15 | Concorrentes (leitura) | SENSOR | 4 | 5 | 3 | 3 | 3 | 2 |
| D-16 | SEO (análise/score) | SENSOR | 5 | 5 | 3 | 4 | 3 | 1 |
| D-17 | Financeiro (leitura) | SENSOR | 4 | 4 | 3 | 2 | 3 | 1 |
| D-18 | MercadoLivreClient | REFATORAR | 5 | 5 | 2 | 3 | 0 | 1 |
| D-19 | AccountContextMiddleware | REFATORAR | 5 | 4 | 1 | 2 | 0 | 1 |
| D-20 | Logging (5 impl.) | REFATORAR | 3 | 2 | 1 | 3 | 3 | 2 |
| D-21 | Cache (4 impl.) | REFATORAR | 3 | 2 | 2 | 2 | 3 | 2 |
| D-22 | AI Services (legados) | REFATORAR | 3 | 4 | 2 | 2 | 3 | 2 |
| D-23 | TechSheet (14 services) | REFATORAR | 3 | 3 | 2 | 3 | 3 | 2 |
| D-24 | Rotas duplicadas (27) | REFATORAR | 2 | 1 | 1 | 2 | 2 | 2 |
| D-25 | Precificação automática | QUARENTENA | 5 | 5 | 3 | 3 | 2 | 0 |
| D-26 | Clonagem automática | QUARENTENA | 4 | 3 | 2 | 3 | 2 | 1 |
| D-27 | SEO automático em massa | QUARENTENA | 4 | 4 | 3 | 3 | 3 | 1 |
| D-28 | Motor de decisão | QUARENTENA | 3 | 4 | 2 | 2 | 2 | 0 |
| D-29 | Respostas automáticas | QUARENTENA | 3 | 4 | 2 | 2 | 3 | 1 |
| D-30 | Monitor concorrentes auto | QUARENTENA | 3 | 4 | 2 | 2 | 3 | 2 |
| D-31 | Ads automático | QUARENTENA | 4 | 4 | 2 | 2 | 2 | 0 |
| D-32 | Agentes autônomos | QUARENTENA | 2 | 3 | 2 | 1 | 2 | 0 |
| D-33 | Shopee | ARQUIVAR | 1 | 0 | 3 | 1 | 3 | 1 |
| D-34 | EAN | ARQUIVAR | 2 | 0 | 3 | 1 | 3 | 1 |
| D-35 | WhatsApp / Telegram | ARQUIVAR | 2 | 1 | 3 | 2 | 3 | 1 |
| D-36 | OpenClaw Connector | ARQUIVAR | 1 | 1 | 2 | 1 | 2 | 1 |
| D-37 | Brevo | ARQUIVAR | 2 | 1 | 3 | 3 | 3 | 1 |
| D-38 | AWA Seller Scanning | ARQUIVAR | 3 | 2 | 2 | 2 | 3 | 2 |
| D-39 | MercadoLivreClient.bak | REMOVER | 0 | 0 | — | — | 1 | 0 |
| D-40 | _quarantine/orphan-batch1 | REMOVER | 0 | 0 | — | — | 1 | 0 |
| D-41 | ml-nlp-service/venv | REMOVER | 0 | 0 | — | — | 1 | 0 |
| D-42 | tests/manual e scripts | REMOVER | 0 | 0 | — | — | 1 | 0 |

---

## 6. Riscos e limitações desta análise

1. **SEC-001 é o bloqueio principal** para operação multiempresa segura. Sem correção,
   accountId pode ser fornecido por qualquer parâmetro HTTP e o sistema pode carregar
   tokens de outra conta.

2. **305 tabelas** para um monólito de marketplace indicam acúmulo sem governança de dados.
   Recomenda-se uma auditoria de tabelas sem uso como próximo passo de higiene.

3. **Módulos em QUARENTENA** precisam ter seus workers verificados nos crons do servidor
   para confirmar que estão desligados por padrão em produção.

4. **Shopee e EAN** possuem tabelas no schema CI. Arquivar não significa remover as
   tabelas agora. Migrations futuras não devem expandir esses domínios.

5. **Análise baseada em código estático.** Nenhum teste invasivo foi executado contra
   produção. Algumas classificações podem ser refinadas após validação dinâmica.

---

## 7. Ordem de execução recomendada

| Prioridade | Ação |
|-----------|------|
| 1 — Imediato | PR funcional 1 SEC-001: AccountAccessPolicy + AuthorizedAccountContext |
| 2 — Imediato | Remover: .bak, _quarantine/orphan-batch1, venv, scripts manuais de teste |
| 3 — Curto prazo | Auditar workers de QUARENTENA no cron do servidor |
| 4 — Médio prazo | Consolidar logging → AuditLogService único |
| 5 — Médio prazo | Consolidar cache → CacheService canônico |
| 6 — Médio prazo | Migrar consumidores de AIService legado → AIProviderManager |
| 7 — Longo prazo | Consolidar TechSheet (14 services → ~4) |
| 8 — Longo prazo | Resolver 27 rotas duplicadas |

---

## 8. Contagem por classificação

| Classificação | Domínios |
|--------------|---------|
| CORE | 9 (D-01 a D-09) |
| SENSOR | 8 (D-10 a D-17) |
| REFATORAR | 7 (D-18 a D-24) |
| QUARENTENA | 8 (D-25 a D-32) |
| ARQUIVAR | 6 (D-33 a D-38) |
| REMOVER | 4 (D-39 a D-42) |
| **Total** | **42 domínios** |

---

*Documento gerado em análise estática. Nenhum código foi alterado, nenhum dado de
produção foi acessado, nenhuma ação foi executada em contas reais do Mercado Livre.*
