<?php

declare(strict_types=1);

$pageTitle = '🔥 SEO Killer';
$activePage = 'seo-killer';

// Account Selector
$requireAccountSelection = true;
$showAccountBanner = true;
include __DIR__ . '/../components/account-selector.php';

// Page Header
$title = '🔥 SEO Killer';
$subtitle = 'Otimize seus anúncios do Mercado Livre com diagnóstico e ações guiadas.';
include __DIR__ . '/../layouts/modern/partials/page-header.php';

$seoKillerAssetBase = dirname(__DIR__, 2) . '/public/assets';
$seoKillerCssVersion = @filemtime($seoKillerAssetBase . '/css/seo-killer.css') ?: time();
$seoKillerChatbotCssVersion = @filemtime($seoKillerAssetBase . '/css/seo-killer-chatbot.css') ?: time();
$seoKillerJsVersion = @filemtime($seoKillerAssetBase . '/js/seo-killer.js') ?: time();
?>

<link rel="stylesheet" href="/assets/css/seo-killer.css?v=<?= $seoKillerCssVersion ?>">
<link rel="stylesheet" href="/assets/css/seo-killer-chatbot.css?v=<?= $seoKillerChatbotCssVersion ?>">

<link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

<div class="seo-killer-dashboard">
    <div class="visually-hidden" aria-live="polite" id="seo-killer-live"></div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-4" id="seoKillerTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="dashboard-tab" data-bs-toggle="tab" data-bs-target="#dashboard" type="button" role="tab" aria-controls="dashboard" aria-selected="true">
                <i class="bi bi-speedometer2 me-2"></i>Dashboard
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="technical-sheet-tab" data-bs-toggle="tab" data-bs-target="#technical-sheet" type="button" role="tab" aria-controls="technical-sheet" aria-selected="false">
                <i class="bi bi-card-checklist me-2"></i>Ficha Técnica
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="seo-kpi-results-tab" data-bs-toggle="tab" data-bs-target="#seo-kpi-results" type="button" role="tab" aria-controls="seo-kpi-results" aria-selected="false">
                <i class="bi bi-clipboard2-pulse me-2"></i>Resultados
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="competitor-spy-tab" data-bs-toggle="tab" data-bs-target="#competitor-spy" type="button" role="tab" aria-controls="competitor-spy" aria-selected="false">
                <i class="bi bi-binoculars me-2"></i>Espião de Concorrentes
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="performance-tracker-tab" data-bs-toggle="tab" data-bs-target="#performance-tracker" type="button" role="tab" aria-controls="performance-tracker" aria-selected="false">
                <i class="bi bi-graph-up-arrow me-2"></i>Performance Tracker
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="ab-testing-tab" data-bs-toggle="tab" data-bs-target="#ab-testing" type="button" role="tab" aria-controls="ab-testing" aria-selected="false">
                <i class="bi bi-clipboard-data me-2"></i>Testes A/B
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="gsc-tab" data-bs-toggle="tab" data-bs-target="#gsc-dashboard" type="button" role="tab" aria-controls="gsc-dashboard" aria-selected="false">
                <i class="bi bi-google me-2"></i>Search Console
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="ai-insights-tab" data-bs-toggle="tab" data-bs-target="#ai-insights" type="button" role="tab" aria-controls="ai-insights" aria-selected="false">
                <i class="bi bi-lightbulb me-2"></i>AI Insights
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="ai-pricing-tab" data-bs-toggle="tab" data-bs-target="#ai-pricing" type="button" role="tab" aria-controls="ai-pricing" aria-selected="false">
                <i class="bi bi-calculator me-2"></i>AI Pricing
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="ai-images-tab" data-bs-toggle="tab" data-bs-target="#ai-images" type="button" role="tab" aria-controls="ai-images" aria-selected="false">
                <i class="bi bi-images me-2"></i>AI Images
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="seoKillerTabContent">

        <!-- Dashboard Tab -->
        <div class="tab-pane fade show active" id="dashboard" role="tabpanel" aria-labelledby="dashboard-tab">

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h2 class="h4 mb-2 fw-bold">Comece por aqui: rode um diagnóstico e aplique melhorias guiadas</h2>
                            <p class="text-muted mb-0">Você vai ver o que está travando seu SEO e a próxima ação recomendada para cada anúncio.</p>
                            <small class="text-muted d-block mt-2">Última atualização: <span id="seo-killer-last-updated">—</span></small>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-info px-4 py-2 fw-bold text-white" onclick="SEOKiller.syncItems()" id="btn-sync-items">
                                <i class="bi bi-arrow-repeat me-2"></i>Sincronizar Anúncios
                            </button>
                            <button type="button" class="btn btn-primary px-4 py-2 fw-bold" onclick="SEOKiller.runDiagnosis()">
                                <i class="bi bi-search me-2"></i>Rodar diagnóstico agora
                            </button>
                            <button type="button" class="btn btn-outline-primary px-4 py-2 fw-bold" onclick="SEOKiller.showBulkOptimizer()">
                                <i class="bi bi-lightning-charge me-2"></i>Otimizar em lote
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card-modern">
                        <div class="stat-icon-wrapper bg-primary-soft">
                            <i class="bi bi-layers"></i>
                        </div>
                        <div>
                            <h3 class="mb-0 fw-bold" id="total-items">-</h3>
                            <small class="text-muted text-uppercase fw-bold">Anúncios</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card-modern">
                        <div class="stat-icon-wrapper bg-success-soft">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <h3 class="mb-0 fw-bold" id="optimized-items">-</h3>
                            <small class="text-muted text-uppercase fw-bold">Otimizados</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card-modern">
                        <div class="stat-icon-wrapper bg-warning-soft">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div>
                            <h3 class="mb-0 fw-bold" id="pending-items">-</h3>
                            <small class="text-muted text-uppercase fw-bold">Pendentes</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card-modern">
                        <div class="stat-icon-wrapper bg-info-soft">
                            <i class="bi bi-bullseye"></i>
                        </div>
                        <div>
                            <h3 class="mb-0 fw-bold" id="avg-score">-</h3>
                            <small class="text-muted text-uppercase fw-bold">Score Médio</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SEO Tools -->
            <div class="row mt-4">
                <div class="col-md-8">
                    <h4 class="mb-4 fw-bold">Ferramentas de Otimização</h4>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <button type="button" class="tool-card-modern" onclick="SEOKiller.openTitleGenerator()" aria-label="Abrir Gerador de Títulos (abre modal)">
                                <div class="tool-icon">
                                    <span class="fs-2">📝</span>
                                </div>
                                <div class="tool-title">Gerador de Títulos</div>
                                <div class="tool-desc">Monte um título de alta conversão com palavras-chave relevantes (até 60 caracteres).</div>
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="tool-card-modern" onclick="SEOKiller.openDescriptionGenerator()" aria-label="Abrir Gerador de Descrições (abre modal)">
                                <div class="tool-icon">
                                    <span class="fs-2">📄</span>
                                </div>
                                <div class="tool-title">Gerador de Descrições</div>
                                <div class="tool-desc">Crie descrições claras, persuasivas e fáceis de escanear para aumentar conversão.</div>
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="tool-card-modern" onclick="SEOKiller.openAttributeFiller()" aria-label="Abrir Preenchimento de Atributos (abre modal)">
                                <div class="tool-icon">
                                    <span class="fs-2">🏷️</span>
                                </div>
                                <div class="tool-title">Preenchimento de Atributos</div>
                                <div class="tool-desc">Complete campos importantes para ganhar mais exposição e reduzir dúvidas.</div>
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="tool-card-modern" onclick="SEOKiller.openCompetitorSpy()" aria-label="Abrir Espião de Concorrentes (abre modal)">
                                <div class="tool-icon">
                                    <span class="fs-2">🔍</span>
                                </div>
                                <div class="tool-title">Espião de Concorrentes</div>
                                <div class="tool-desc">Compare preço, título e estratégia com quem está vendendo mais na sua categoria.</div>
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="tool-card-modern" onclick="SEOKiller.openImageAnalyzer()" aria-label="Abrir Análise de Imagens (abre modal)">
                                <div class="tool-icon">
                                    <span class="fs-2">📸</span>
                                </div>
                                <div class="tool-title">Análise de Imagens</div>
                                <div class="tool-desc">Checagem rápida de fundo branco, nitidez e conformidade para reduzir rejeições.</div>
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="tool-card-modern" onclick="SEOKiller.openSchemaMarkup()" aria-label="Abrir Schema Markup (abre modal)">
                                <div class="tool-icon">
                                    <span class="fs-2">🏗️</span>
                                </div>
                                <div class="tool-title">Schema Markup</div>
                                <div class="tool-desc">Gere JSON-LD para Rich Snippets no Google.</div>
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="tool-card-modern border-info position-relative" onclick="window.location.href='/dashboard/seo-killer/strategies'" aria-label="Abrir SEO Strategies Engine (abre página)">
                                <span class="position-absolute top-0 end-0 badge bg-info m-3">NOVO</span>
                                <div class="tool-icon">
                                    <span class="fs-2">🎯</span>
                                </div>
                                <div class="tool-title">SEO Strategies Engine</div>
                                <div class="tool-desc">12 estratégias avançadas com score e recomendações priorizadas por impacto.</div>
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="tool-card-modern border-primary position-relative" onclick="SEOKiller.openAIInsights()" aria-label="Abrir AI Insights (abre aba)">
                                <span class="position-absolute top-0 end-0 badge bg-primary m-3">PRO</span>
                                <div class="tool-icon">
                                    <span class="fs-2">🤖</span>
                                </div>
                                <div class="tool-title">AI Insights Dashboard</div>
                                <div class="tool-desc">Insights acionáveis com prioridade de impacto e esforço, prontos para executar.</div>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <h4 class="mb-4 fw-bold">Ações rápidas</h4>

                    <button class="btn btn-primary w-100 mb-3 py-3 rounded-pill fw-bold shadow-sm" onclick="SEOKiller.runDiagnosis()">
                        <i class="bi bi-search me-2"></i> Diagnóstico Completo
                    </button>

                    <button class="btn btn-success w-100 mb-3 py-3 rounded-pill fw-bold shadow-sm" onclick="SEOKiller.showBulkOptimizer()">
                        <i class="bi bi-lightning-charge me-2"></i> Otimização em Lote
                    </button>

                    <!-- AutoPilot Card -->
                    <div class="autopilot-premium mt-4">
                        <div class="autopilot-content">
                            <h3>🤖 SEO AutoPilot</h3>
                            <p class="mb-3 opacity-75">Otimização automática diária dentro dos limites que você definir.</p>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span id="autopilot-status" class="badge bg-white text-primary">Desativado</span>
                                <i class="bi bi-infinity fs-4"></i>
                            </div>
                            <div class="d-grid gap-2">
                                <button class="btn btn-white text-primary fw-bold" id="autopilot-toggle" onclick="SEOKiller.toggleAutoPilot()">Ativar AutoPilot</button>
                                <button class="btn btn-outline-white btn-sm" onclick="SEOKiller.configureAutoPilot()">Configurar limites</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="action-section mt-3">
                <h4 class="mb-3">Atividade recente</h4>
                <div id="recent-activity">
                    <p class="text-muted">Carregando...</p>
                </div>
            </div>

            <!-- Top Performers -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-0 pt-4 px-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <h4 class="mb-0 fw-bold">🏆 Melhores Anúncios (Top Performers)</h4>
                                <select class="form-select w-auto" id="top-performers-period" onchange="SEOKiller.loadTopPerformers()">
                                    <option value="7d">Últimos 7 dias</option>
                                    <option value="30d" selected>Últimos 30 dias</option>
                                    <option value="all">Todo o período</option>
                                </select>
                            </div>
                        </div>
                        <div class="card-body px-4 pb-4">
                            <div id="top-performers-list" class="table-responsive">
                                <div class="text-center py-5">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results Section -->
            <div id="results-section" class="optimization-results mt-5" style="display:none;">
                <h4 class="mb-4 fw-bold"><i class="bi bi-radar me-2"></i>Diagnóstico SEO</h4>
                <div id="results-content"></div>
            </div>
        </div>
        <!-- End Dashboard Tab -->

        <!-- Technical Sheet Tab -->
        <div class="tab-pane fade" id="technical-sheet" role="tabpanel" aria-labelledby="technical-sheet-tab">
            <?php include __DIR__ . '/seo-killer/components/technical-sheet-tab.php'; ?>
        </div>

        <div class="tab-pane fade" id="seo-kpi-results" role="tabpanel" aria-labelledby="seo-kpi-results-tab">
            <?php include __DIR__ . '/seo-killer/components/seo-kpi-results-tab.php'; ?>
        </div>
        <!-- End Technical Sheet Tab -->

        <!-- Competitor Spy Tab -->
        <?php include __DIR__ . '/seo-killer/components/competitor-spy-tab.php'; ?>
        <!-- End Competitor Spy Tab -->

        <!-- Performance Tracker Tab -->
        <?php include __DIR__ . '/seo-killer/components/performance-tracker-tab.php'; ?>
        <!-- End Performance Tracker Tab -->

        <!-- A/B Testing Tab -->
        <?php include __DIR__ . '/seo-killer/components/ab-test-tab.php'; ?>
        <!-- End A/B Testing Tab -->

        <!-- GSC Tab -->
        <div class="tab-pane fade" id="gsc-dashboard" role="tabpanel" aria-labelledby="gsc-tab">
            <?php include __DIR__ . '/seo-killer/components/gsc-dashboard-tab.php'; ?>
        </div>
        <!-- End GSC Tab -->

        <!-- Developer Hub Tab -->
        <div class="tab-pane fade" id="developer-hub" role="tabpanel" aria-labelledby="developer-hub-tab">
            <?php include __DIR__ . '/seo-killer/components/developer-hub-tab.php'; ?>
        </div>
        <!-- End Developer Hub Tab -->

        <!-- AI Insights Tab -->
        <div class="tab-pane fade" id="ai-insights" role="tabpanel" aria-labelledby="ai-insights-tab">
            <?php include __DIR__ . '/seo-killer/components/ai-insights-dashboard.php'; ?>
        </div>
        <!-- End AI Insights Tab -->

        <!-- AI Pricing Tab -->
        <div class="tab-pane fade" id="ai-pricing" role="tabpanel" aria-labelledby="ai-pricing-tab">
            <?php include __DIR__ . '/seo-killer/components/ai-pricing-optimizer.php'; ?>
        </div>
        <!-- End AI Pricing Tab -->

        <!-- AI Images Tab -->
        <div class="tab-pane fade" id="ai-images" role="tabpanel" aria-labelledby="ai-images-tab">
            <?php include __DIR__ . '/seo-killer/components/ai-image-analyzer.php'; ?>
        </div>
        <!-- End AI Images Tab -->

    </div>
    <!-- End Tab Content -->

</div>

<!-- Load SEO Killer Components -->
<?php include __DIR__ . '/seo-killer/components/bulk-optimizer-modal.php'; ?>
<?php include __DIR__ . '/seo-killer/components/title-generator-modal.php'; ?>
<?php include __DIR__ . '/seo-killer/components/keyword-research-modal.php'; ?>
<?php include __DIR__ . '/seo-killer/components/description-generator-modal.php'; ?>
<?php include __DIR__ . '/seo-killer/components/attribute-filler-modal.php'; ?>
<?php include __DIR__ . '/seo-killer/components/schema-markup-modal.php'; ?>
<?php include __DIR__ . '/seo-killer/components/backlink-analysis-modal.php'; ?>
<?php include __DIR__ . '/seo-killer/components/pdf-export-modal.php'; ?>
<?php include __DIR__ . '/seo-killer/components/autopilot-config-modal.php'; ?>
<?php include __DIR__ . '/seo-killer/components/image-analyzer-modal.php'; ?>

<!-- Load AI Chatbot Widget (Global) -->
<?php include __DIR__ . '/seo-killer/components/ai-chatbot-widget.php'; ?>

<script nonce="<?= CSP_NONCE ?>" src="https://cdn.jsdelivr.net/npm/toastify-js" defer></script>
<script nonce="<?= CSP_NONCE ?>" src="/assets/js/seo-killer-utils.js" defer></script>
<script nonce="<?= CSP_NONCE ?>" src="/assets/js/seo-killer.js?v=<?= $seoKillerJsVersion ?>" defer></script>
<script nonce="<?= CSP_NONCE ?>" src="/assets/js/seo-killer-ai-insights.js?v=<?= $seoKillerJsVersion ?>" defer></script>
<script nonce="<?= CSP_NONCE ?>" src="/assets/js/seo-killer-chatbot.js?v=<?= $seoKillerJsVersion ?>" defer></script>
<script nonce="<?= CSP_NONCE ?>" src="/assets/js/ai-optimization.js?v=<?= $seoKillerJsVersion ?>" defer></script>
<script nonce="<?= CSP_NONCE ?>">
    document.addEventListener('DOMContentLoaded', function() {
        function resolveTabIdFromLocation() {
            var rawHash = (window.location.hash || '').replace('#', '').trim();
            var hash = rawHash.split('?')[0].split('/')[0];

            if (!hash) {
                var tabParam = new URLSearchParams(window.location.search).get('tab');
                if (tabParam) {
                    hash = tabParam;
                }
            }

            return hash || '';
        }

        function activateTabFromLocation() {
            var hash = resolveTabIdFromLocation();
            if (!hash || !window.bootstrap || !window.bootstrap.Tab) {
                return false;
            }

            var tabBtn = document.querySelector('#seoKillerTabs button[data-bs-toggle="tab"][data-bs-target="#' + hash + '"]');
            if (!tabBtn) {
                return false;
            }

            var tab = new window.bootstrap.Tab(tabBtn);
            tab.show();
            return true;
        }

        activateTabFromLocation();

        window.addEventListener('hashchange', function() {
            activateTabFromLocation();
        });
    });
</script>
