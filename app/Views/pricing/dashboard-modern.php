<?php

declare(strict_types=1);

/**
 * Dashboard de Precificação Inteligente
 *
 * Interface principal do módulo de precificação com:
 * - Listagem de anúncios com margem
 * - Filtros por categoria, status, margem
 * - Indicadores visuais de saúde financeira
 * - Popup do precificador
 */

use App\Helpers\SecurityHelper;
use App\Helpers\SessionHelper;

$pageTitle = 'Precificação Inteligente';
$activePage = 'pricing';
$accountId = SessionHelper::getActiveAccountId();
?>

<?php ob_start(); ?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-calculator-fill text-primary me-2"></i>
                Precificador Inteligente
            </h1>
            <p class="text-muted mb-0">Gerencie preços e margens dos seus anúncios
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#settingsModal" title="Configurações">
                <i class="bi bi-gear"></i>
            </button>
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-download me-1"></i> Exportar
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="exportarDados">
                            <i class="bi bi-file-earmark-spreadsheet me-2"></i>Custos (CSV)
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="exportarHistorico">
                            <i class="bi bi-clock-history me-2"></i>Histórico de Preços
                        </a></li>
                </ul>
            </div>
            <div class="dropdown">
                <button class="btn btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-graph-up me-1"></i> Relatórios
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirRelatorioPerformance">
                            <i class="bi bi-speedometer2 me-2"></i>Performance (30 dias)
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirMonitorConcorrentes">
                            <i class="bi bi-people me-2"></i>Monitorar Concorrentes
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirPrevisaoMargem">
                            <i class="bi bi-calculator me-2"></i>Previsão de Margem
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirRentabilidade">
                            <i class="bi bi-piggy-bank me-2"></i>Análise de Rentabilidade
                        </a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirPrecoIdeal">
                            <i class="bi bi-bullseye me-2"></i>Calcular Preço Ideal
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#autoSuggestModal">
                            <i class="bi bi-magic me-2"></i>Sugestão Automática
                        </a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirAlertasPreco">
                            <i class="bi bi-bell me-2"></i>Alertas de Preço
                        </a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirAutoOptimizer">
                            <i class="bi bi-robot me-2 text-success"></i>Auto-Otimizador
                            <span class="badge bg-success ms-2">Novo</span>
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirAbTests">
                            <i class="bi bi-signpost-split me-2 text-info"></i>Testes A/B
                            <span class="badge bg-info ms-2">Novo</span>
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirCompetitorMonitor">
                            <i class="bi bi-eye me-2 text-dark"></i>Monitor de Concorrentes
                            <span class="badge bg-dark ms-2">Novo</span>
                        </a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirAIPricing">
                            <i class="bi bi-cpu me-2 text-purple"></i>IA Preditiva
                            <span class="badge bg-purple ms-2">AI</span>
                        </a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li class="dropdown-header">🚀 Phase 3 - Automação Avançada</li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirRulesEngine">
                            <i class="bi bi-gear-wide-connected me-2 text-primary"></i>Motor de Regras
                            <span class="badge bg-primary ms-2">Novo</span>
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirScheduledPrices">
                            <i class="bi bi-calendar-event me-2 text-success"></i>Agendamento de Preços
                            <span class="badge bg-success ms-2">Novo</span>
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirPriceAnalytics">
                            <i class="bi bi-graph-up-arrow me-2 text-info"></i>Analytics Avançado
                            <span class="badge bg-info ms-2">Novo</span>
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirBulkEditor">
                            <i class="bi bi-pencil-square me-2 text-warning"></i>Editor em Massa
                            <span class="badge bg-warning text-dark ms-2">Novo</span>
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-pricing-cmd="abrirNotifications">
                            <i class="bi bi-bell-fill me-2 text-danger"></i>Central de Notificações
                            <span class="badge bg-danger ms-2">Novo</span>
                        </a></li>
                </ul>
            </div>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importCostsModal">
                <i class="bi bi-upload me-1"></i> Importar Custos
            </button>
            <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#bulkActionsModal">
                <i class="bi bi-lightning me-1"></i> Ações em Lote
            </button>
            <button type="button" class="btn btn-primary" data-pricing-cmd="refreshItems">
                <i class="bi bi-arrow-clockwise me-1"></i> Atualizar
            </button>
        </div>
    </div>

    <!-- Alertas Resumo -->
    <div class="row mb-4" id="alertsSummary">
        <div class="col-md-3">
            <div class="card border-danger bg-danger bg-opacity-10">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <span class="badge bg-danger rounded-pill fs-5 px-3" id="alertVermelho">0</span>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="mb-0">Margem Crítica</h6>
                            <small class="text-muted">&lt; 5%</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-warning bg-warning bg-opacity-10">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <span class="badge bg-warning text-dark rounded-pill fs-5 px-3" id="alertAmarelo">0</span>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="mb-0">Margem Baixa</h6>
                            <small class="text-muted">5-10%</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-success bg-success bg-opacity-10">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <span class="badge bg-success rounded-pill fs-5 px-3" id="alertVerde">0</span>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="mb-0">Margem Boa</h6>
                            <small class="text-muted">&gt; 20%</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-secondary bg-secondary bg-opacity-10">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <span class="badge bg-secondary rounded-pill fs-5 px-3" id="alertSemCusto">0</span>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="mb-0">Sem Custos</h6>
                            <small class="text-muted">Configurar</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Métricas Avançadas (colapsável) -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center"
            style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#metricsCollapse">
            <span>
                <i class="bi bi-graph-up-arrow me-2"></i>
                Métricas Avançadas
            </span>
            <i class="bi bi-chevron-down" id="metricsChevron"></i>
        </div>
        <div class="collapse" id="metricsCollapse">
            <div class="card-body">
                <div class="row" id="advancedMetrics">
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center">
                            <h4 class="text-primary mb-1" id="metricMargemMedia">-</h4>
                            <small class="text-muted">Margem Média</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center">
                            <h4 class="text-success mb-1" id="metricLucroTotal">-</h4>
                            <small class="text-muted">Lucro Potencial/Mês</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center">
                            <h4 class="text-info mb-1" id="metricAlteracoes">-</h4>
                            <small class="text-muted">Alterações (7 dias)</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 text-center">
                            <h4 class="text-warning mb-1" id="metricAlertas">-</h4>
                            <small class="text-muted">Alertas Pendentes</small>
                        </div>
                    </div>
                </div>

                <!-- Gráfico de Distribuição -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h6 class="mb-3"><i class="bi bi-pie-chart me-1"></i> Distribuição de Margens</h6>
                        <div class="position-relative" style="height: 260px;">
                            <canvas id="marginDistributionChart"></canvas>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="mb-3"><i class="bi bi-bar-chart me-1"></i> Tendência de Preços (7 dias)</h6>
                        <div class="position-relative" style="height: 260px;">
                            <canvas id="priceTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Buscar</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="searchInput" placeholder="SKU, título...">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="filterStatus">
                        <option value="">Todos</option>
                        <option value="active" selected>Ativos</option>
                        <option value="paused">Pausados</option>
                        <option value="closed">Encerrados</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Margem</label>
                    <select class="form-select" id="filterMargem">
                        <option value="">Todas</option>
                        <option value="com">Com custos</option>
                        <option value="sem">Sem custos</option>
                        <option value="critica">Crítica (&lt;5%)</option>
                        <option value="baixa">Baixa (5-10%)</option>
                        <option value="media">Média (10-20%)</option>
                        <option value="boa">Boa (&gt;20%)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Ordenar por</label>
                    <select class="form-select" id="sortBy">
                        <option value="margem_asc">Margem ↑</option>
                        <option value="margem_desc">Margem ↓</option>
                        <option value="preco_asc">Preço ↑</option>
                        <option value="preco_desc">Preço ↓</option>
                        <option value="vendas_desc">Mais vendidos</option>
                    </select>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-outline-secondary me-2" data-pricing-cmd="limparFiltros">
                        <i class="bi bi-x-lg me-1"></i> Limpar
                    </button>
                    <button class="btn btn-primary" data-pricing-cmd="aplicarFiltros">
                        <i class="bi bi-funnel me-1"></i> Filtrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$accountId): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4" id="pricingNoAccountState" data-pricing-state="no-account" role="status">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <i class="bi bi-link-45deg fs-3" aria-hidden="true"></i>
            <div class="flex-grow-1">
                <strong class="d-block">Nenhuma conta Mercado Livre conectada</strong>
                <span class="small">Conecte ou selecione uma conta ML para listar anúncios e usar o precificador.</span>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="/auth/authorize" class="btn btn-primary btn-sm">Conectar conta ML</a>
                <a href="/dashboard/settings" class="btn btn-outline-secondary btn-sm">Configurações</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabela de Anúncios -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="itemsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 60px;"></th>
                            <th>Anúncio</th>
                            <th class="text-center" style="width: 100px;">Preço</th>
                            <th class="text-center" style="width: 100px;">Custo</th>
                            <th class="text-center" style="width: 100px;">Margem</th>
                            <th class="text-center" style="width: 100px;">Lucro/Un</th>
                            <th class="text-center" style="width: 80px;">Vendidos</th>
                            <th class="text-center" style="width: 160px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <?php if (!$accountId): ?>
                        <tr data-pricing-state="no-account">
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2" aria-hidden="true"></i>
                                <p class="mb-1 fw-semibold">Sem anúncios para exibir</p>
                                <p class="mb-0 small">Conecte uma conta Mercado Livre para carregar o catálogo.</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Carregando...</span>
                                </div>
                                <p class="mt-2 mb-0 text-muted">Carregando anúncios...</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Paginação -->
        <div class="card-footer d-flex justify-content-between align-items-center">
            <div class="text-muted" id="paginationInfo">
                Mostrando 0 de 0 itens
            </div>
            <nav>
                <ul class="pagination pagination-sm mb-0" id="pagination">
                </ul>
            </nav>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<!-- Modal: Popup Precificador -->
<div class="modal fade" id="pricingModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-calculator me-2"></i>
                    Precificador Inteligente
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Tabs Navigation -->
                <ul class="nav nav-tabs px-3 pt-3" id="pricingTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-simulador" data-bs-toggle="tab" data-bs-target="#simulador-panel" type="button">
                            <i class="bi bi-sliders me-1"></i> Simulador
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-promocoes" data-bs-toggle="tab" data-bs-target="#promocoes-panel" type="button">
                            <i class="bi bi-percent me-1"></i> Promoções
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-historico" data-bs-toggle="tab" data-bs-target="#historico-panel" type="button">
                            <i class="bi bi-graph-up me-1"></i> Histórico
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-regras" data-bs-toggle="tab" data-bs-target="#regras-panel" type="button">
                            <i class="bi bi-gear-fill me-1"></i> Regras
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content p-3">
                    <!-- Tab: Simulador -->
                    <div class="tab-pane fade show active" id="simulador-panel" role="tabpanel">
                        <div class="row">
                            <!-- Coluna Esquerda: Info do Produto -->
                            <div class="col-md-4 border-end">
                                <div class="text-center mb-3">
                                    <img src="" alt="" class="img-fluid rounded" id="modalItemImage" style="max-height: 150px;">
                                </div>
                                <h6 class="fw-bold" id="modalItemTitle">-</h6>
                                <p class="text-muted small mb-2" id="modalItemId">MLB000000000</p>

                                <hr>

                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="fw-bold text-primary fs-4" id="modalCurrentPrice">R$ 0,00</div>
                                        <small class="text-muted">Preço Atual</small>
                                    </div>
                                    <div class="col-6">
                                        <div class="fw-bold fs-4" id="modalCurrentMargin">-</div>
                                        <small class="text-muted">Margem Atual</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Coluna Central: Simulador -->
                            <div class="col-md-5 border-end">
                                <h6 class="fw-bold mb-3"><i class="bi bi-sliders me-1"></i> Simulador de Preço</h6>

                                <!-- Input Novo Preço -->
                                <div class="mb-4">
                                    <label class="form-label">Novo Preço (R$)</label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text">R$</span>
                                        <input type="number" class="form-control form-control-lg" id="newPriceInput"
                                            step="0.01" min="0" placeholder="0,00" data-pricing-simulate>
                                    </div>
                                    <div class="form-text" id="priceChangePercent"></div>
                                </div>

                                <!-- Alerta de Ranking -->
                                <div class="alert d-none mb-3" id="rankingAlert" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    <span id="rankingAlertText"></span>
                                </div>

                                <!-- Breakdown Financeiro -->
                                <div class="card bg-light mb-3">
                                    <div class="card-body py-2">
                                        <h6 class="card-title small mb-2">Breakdown Financeiro</h6>
                                        <table class="table table-sm table-borderless mb-0 small">
                                            <tbody>
                                                <tr>
                                                    <td>Preço de Venda</td>
                                                    <td class="text-end fw-bold" id="bkPreco">R$ 0,00</td>
                                                </tr>
                                                <tr class="text-danger">
                                                    <td>(-) Comissão ML <span class="text-muted" id="bkComissaoPercent"></span></td>
                                                    <td class="text-end" id="bkComissao">R$ 0,00</td>
                                                </tr>
                                                <tr class="text-danger">
                                                    <td>(-) Impostos <span class="text-muted" id="bkImpostoPercent"></span></td>
                                                    <td class="text-end" id="bkImposto">R$ 0,00</td>
                                                </tr>
                                                <tr class="text-danger">
                                                    <td>(-) Ads (ACOS) <span class="text-muted" id="bkAdsPercent"></span></td>
                                                    <td class="text-end" id="bkAds">R$ 0,00</td>
                                                </tr>
                                                <tr class="text-danger">
                                                    <td>(-) Custo Produto</td>
                                                    <td class="text-end" id="bkCusto">R$ 0,00</td>
                                                </tr>
                                                <tr class="text-danger">
                                                    <td>(-) Frete Grátis</td>
                                                    <td class="text-end" id="bkFrete">R$ 0,00</td>
                                                </tr>
                                                <tr class="border-top">
                                                    <td class="fw-bold">= Lucro Unitário</td>
                                                    <td class="text-end fw-bold" id="bkLucro">R$ 0,00</td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">= Margem Real</td>
                                                    <td class="text-end fw-bold" id="bkMargem">0%</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Cenários de Desconto -->
                                <div class="mb-3">
                                    <h6 class="small fw-bold mb-2">Cenários de Desconto</h6>
                                    <div class="d-flex flex-wrap gap-2" id="discountScenarios">
                                        <!-- Preenchido via JS -->
                                    </div>
                                </div>
                            </div>

                            <!-- Coluna Direita: Custos e Histórico -->
                            <div class="col-md-3">
                                <!-- Form Custos -->
                                <h6 class="fw-bold mb-3"><i class="bi bi-gear me-1"></i> Custos do Produto</h6>

                                <div class="mb-2">
                                    <label class="form-label small">Custo de Aquisição</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">R$</span>
                                        <input type="number" class="form-control" id="costProducao" step="0.01" data-pricing-simulate>
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small">Embalagem</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">R$</span>
                                        <input type="number" class="form-control" id="costEmbalagem" step="0.01" value="0" data-pricing-simulate>
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small">Frete Grátis</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">R$</span>
                                        <input type="number" class="form-control" id="costFreteGratis" step="0.01" value="0" data-pricing-simulate>
                                    </div>
                                </div>

                                <div class="row mb-2">
                                    <div class="col-6">
                                        <label class="form-label small">Comissão ML</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control" id="taxaComissao" step="0.1" value="16" data-pricing-simulate>
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">Impostos</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control" id="taxaImposto" step="0.1" value="9" data-pricing-simulate>
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small">ACOS Médio (Ads)</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control" id="taxaAcos" step="0.1" value="0" data-pricing-simulate>
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>

                                <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-3" data-pricing-action="salvar-custos">
                                    <i class="bi bi-save me-1"></i> Salvar Custos
                                </button>

                                <hr>

                                <!-- Concorrência -->
                                <h6 class="small fw-bold mb-2"><i class="bi bi-people me-1"></i> Concorrência</h6>
                                <div class="small" id="competitorInfo">
                                    <div class="d-flex justify-content-between">
                                        <span>Menor preço:</span>
                                        <span id="compMin">-</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Preço médio:</span>
                                        <span id="compAvg">-</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Sua posição:</span>
                                        <span id="compPosition">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Promoções -->
                    <div class="tab-pane fade" id="promocoes-panel" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3"><i class="bi bi-percent text-danger me-2"></i>Simulador de Promoções</h5>

                                <div class="mb-4">
                                    <label class="form-label">Percentual de Desconto</label>
                                    <input type="range" class="form-range" id="promoDesconto" min="5" max="50" step="5" value="10" data-pricing-promo-range>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted small">5%</span>
                                        <span class="badge bg-danger fs-6" id="promoDescontoValue">10%</span>
                                        <span class="text-muted small">50%</span>
                                    </div>
                                </div>

                                <div class="card bg-light mb-3">
                                    <div class="card-body">
                                        <div class="row text-center">
                                            <div class="col-4">
                                                <div class="fs-5 fw-bold text-muted" id="promoPrecoOriginal">R$ 0</div>
                                                <small>Original</small>
                                            </div>
                                            <div class="col-4">
                                                <div class="fs-4 fw-bold text-success" id="promoPrecoFinal">R$ 0</div>
                                                <small>Com Desconto</small>
                                            </div>
                                            <div class="col-4">
                                                <div class="fs-5 fw-bold" id="promoMargem">0%</div>
                                                <small>Nova Margem</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="alert d-none" id="promoAlerta" role="alert">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    <span id="promoAlertaText"></span>
                                </div>

                                <button type="button" class="btn btn-danger w-100" data-pricing-action="simular-promocao">
                                    <i class="bi bi-calculator me-1"></i> Simular Promoção Completa
                                </button>
                            </div>

                            <div class="col-md-6">
                                <h5 class="mb-3"><i class="bi bi-lightning text-warning me-2"></i>Cenários de Desconto</h5>

                                <div class="table-responsive">
                                    <table class="table table-sm table-striped" id="promoCenariosTable">
                                        <thead>
                                            <tr>
                                                <th>Desconto</th>
                                                <th>Preço</th>
                                                <th>Margem</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="promoCenariosBody">
                                            <tr>
                                                <td colspan="4" class="text-center text-muted">Clique em simular...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <hr>

                                <h6 class="mb-2"><i class="bi bi-shop me-1"></i> Central de Ofertas ML</h6>
                                <p class="text-muted small mb-2">Simule participação em campanhas do Mercado Livre</p>
                                <button type="button" class="btn btn-outline-warning btn-sm w-100" data-pricing-action="simular-central-ofertas">
                                    <i class="bi bi-stars me-1"></i> Simular Central de Ofertas
                                </button>

                                <div class="mt-3 d-none" id="centralOfertasResult">
                                    <div class="card border-warning">
                                        <div class="card-body py-2 small" id="centralOfertasContent"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Histórico -->
                    <div class="tab-pane fade" id="historico-panel" role="tabpanel">
                        <div class="row">
                            <div class="col-md-8">
                                <h5 class="mb-3"><i class="bi bi-graph-up text-primary me-2"></i>Histórico de Preços</h5>
                                <div style="height: 300px;">
                                    <canvas id="priceHistoryChart"></canvas>
                                </div>

                                <!-- Análise de Tendência -->
                                <div class="mt-3 p-3 bg-light rounded" id="trendAnalysis">
                                    <div class="row text-center">
                                        <div class="col-md-3">
                                            <div class="fw-bold" id="trendDirection">-</div>
                                            <small class="text-muted">Tendência</small>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="fw-bold" id="trendVolatility">-</div>
                                            <small class="text-muted">Volatilidade</small>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="fw-bold" id="trendPriceMin">-</div>
                                            <small class="text-muted">Mínimo</small>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="fw-bold" id="trendPriceMax">-</div>
                                            <small class="text-muted">Máximo</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <h5 class="mb-3"><i class="bi bi-clock-history me-2"></i>Últimas Alterações</h5>
                                <div class="list-group list-group-flush" id="priceHistoryList" style="max-height: 350px; overflow-y: auto;">
                                    <div class="text-center text-muted py-3">Carregando...</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Regras Automáticas -->
                    <div class="tab-pane fade" id="regras-panel" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3"><i class="bi bi-gear-fill text-secondary me-2"></i>Criar Regra Automática</h5>

                                <div class="mb-3">
                                    <label class="form-label">Nome da Regra</label>
                                    <input type="text" class="form-control" id="regraNome" placeholder="Ex: Manter margem mínima 15%">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Tipo de Regra</label>
                                    <select class="form-select" id="regraTipo">
                                        <option value="margem_minima">Manter Margem Mínima</option>
                                        <option value="acompanhar_concorrencia">Acompanhar Concorrência</option>
                                        <option value="preco_maximo">Limitar Preço Máximo</option>
                                        <option value="margem_alvo">Margem Alvo</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Valor</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="regraValor" step="0.01">
                                        <span class="input-group-text" id="regraUnidade">%</span>
                                    </div>
                                </div>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="regraAtiva" checked>
                                    <label class="form-check-label" for="regraAtiva">Ativar imediatamente</label>
                                </div>

                                <button type="button" class="btn btn-primary w-100" data-pricing-action="criar-regra">
                                    <i class="bi bi-plus-lg me-1"></i> Criar Regra
                                </button>
                            </div>

                            <div class="col-md-6">
                                <h5 class="mb-3"><i class="bi bi-list-check me-2"></i>Regras Ativas</h5>
                                <div class="list-group" id="regrasAtivas">
                                    <div class="text-center text-muted py-3">Nenhuma regra configurada</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" data-pricing-action="aplicar-preco" id="btnAplicarPreco">
                    <i class="bi bi-check-lg me-1"></i> Aplicar Preço no ML
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Importar Custos -->
<div class="modal fade" id="importCostsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Importar Custos em Lote</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Cole os dados no formato CSV (item_id, sku, custo_producao)</p>
                <textarea class="form-control" rows="10" id="importCostsData"
                    placeholder="MLB123456789,SKU001,50.00&#10;MLB987654321,SKU002,75.50"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" data-pricing-cmd="processarImportacao">
                    <i class="bi bi-upload me-1"></i> Importar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Ações em Lote -->
<div class="modal fade" id="bulkActionsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-lightning me-2"></i>
                    Ações em Lote
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Tabs -->
                <ul class="nav nav-pills mb-3" id="bulkTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#bulk-rules">
                            <i class="bi bi-gear me-1"></i> Aplicar Regras
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#bulk-costs">
                            <i class="bi bi-currency-dollar me-1"></i> Atualizar Custos
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Aplicar Regras -->
                    <div class="tab-pane fade show active" id="bulk-rules">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Aplique regras de precificação automática aos itens selecionados.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">IDs dos Itens (um por linha ou separados por vírgula)</label>
                            <textarea class="form-control" id="bulkRuleItemIds" rows="4"
                                placeholder="MLB123456789&#10;MLB987654321"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="bulkRuleSimulate" checked>
                            <label class="form-check-label" for="bulkRuleSimulate">
                                Apenas simular (não aplicar alterações)
                            </label>
                        </div>
                        <button class="btn btn-primary" data-pricing-cmd="executarBulkRules">
                            <i class="bi bi-play me-1"></i> Executar
                        </button>
                        <div id="bulkRulesResult" class="mt-3"></div>
                    </div>

                    <!-- Atualizar Custos -->
                    <div class="tab-pane fade" id="bulk-costs">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Atualize custos de múltiplos itens de uma vez. Use com cuidado.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Campo a atualizar</label>
                            <select class="form-select" id="bulkCostField">
                                <option value="custo_producao">Custo de Produção</option>
                                <option value="custo_embalagem">Custo de Embalagem</option>
                                <option value="custo_frete_gratis">Custo de Frete Grátis</option>
                                <option value="taxa_comissao_ml">Taxa Comissão ML (%)</option>
                                <option value="taxa_imposto">Taxa Imposto (%)</option>
                                <option value="acos_medio">ACOS Médio (%)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">IDs dos Itens</label>
                            <textarea class="form-control" id="bulkCostItemIds" rows="3"
                                placeholder="MLB123456789, MLB987654321"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Novo Valor</label>
                            <input type="number" class="form-control" id="bulkCostValue" step="0.01" placeholder="0.00">
                        </div>
                        <button class="btn btn-warning" data-pricing-cmd="executarBulkCosts">
                            <i class="bi bi-pencil me-1"></i> Atualizar Custos
                        </button>
                        <div id="bulkCostsResult" class="mt-3"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Configurações -->
<div class="modal fade" id="settingsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    <i class="bi bi-gear me-2"></i>
                    Configurações do Precificador
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Tabs de Configuração -->
                <ul class="nav nav-tabs mb-3" id="settingsTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-defaults">
                            <i class="bi bi-sliders me-1"></i> Custos Padrão
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-connection">
                            <i class="bi bi-plug me-1"></i> Conexão ML
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-alerts">
                            <i class="bi bi-bell me-1"></i> Alertas
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Custos Padrão -->
                    <div class="tab-pane fade show active" id="tab-defaults">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Defina os valores padrão que serão aplicados a novos produtos sem custos cadastrados.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Taxa Comissão ML (%)</label>
                                <input type="number" class="form-control" id="defaultComissao" value="16" step="0.1">
                                <small class="text-muted">Padrão: 11-16% (depende da categoria)</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Taxa Imposto (%)</label>
                                <input type="number" class="form-control" id="defaultImposto" value="9" step="0.1">
                                <small class="text-muted">Simples Nacional: ~6-15%</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">ACOS Médio (%)</label>
                                <input type="number" class="form-control" id="defaultAcos" value="5" step="0.1">
                                <small class="text-muted">Custo de publicidade</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Custo Embalagem (R$)</label>
                                <input type="number" class="form-control" id="defaultEmbalagem" value="3" step="0.01">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Custo Etiqueta (R$)</label>
                                <input type="number" class="form-control" id="defaultEtiqueta" value="0.50" step="0.01">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Frete Grátis Médio (R$)</label>
                                <input type="number" class="form-control" id="defaultFreteGratis" value="15" step="0.01">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Margem Mínima Aceitável (%)</label>
                                <input type="number" class="form-control" id="defaultMargemMinima" value="5" step="0.1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Margem Alvo (%)</label>
                                <input type="number" class="form-control" id="defaultMargemAlvo" value="15" step="0.1">
                            </div>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-primary" data-pricing-cmd="salvarCustosPadrao">
                                <i class="bi bi-check me-1"></i> Salvar Configurações
                            </button>
                        </div>
                    </div>

                    <!-- Conexão ML -->
                    <div class="tab-pane fade" id="tab-connection">
                        <div id="connectionStatus" class="mb-3">
                            <div class="d-flex align-items-center p-3 border rounded">
                                <i class="bi bi-hourglass-split fs-3 me-3 text-muted"></i>
                                <div>Verificando conexão...</div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-outline-primary" data-pricing-cmd="verificarConexaoML">
                                <i class="bi bi-arrow-repeat me-1"></i> Verificar Conexão
                            </button>
                            <a href="/auth/authorize" class="btn btn-success">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Reconectar Conta ML
                            </a>
                            <button class="btn btn-outline-danger" data-pricing-cmd="confirmarExclusaoConta">
                                <i class="bi bi-trash me-1"></i> Excluir Conta
                            </button>
                        </div>

                        <div class="alert alert-info mt-3 small">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>Sobre os Tokens:</strong> O token de acesso do Mercado Livre expira a cada 6 horas.
                            O sistema tenta renovar automaticamente, mas se o <em>refresh_token</em> também expirar
                            (por inatividade prolongada), você precisará reconectar sua conta manualmente.
                        </div>

                        <!-- Zona de Perigo -->
                        <div class="card border-danger mt-4">
                            <div class="card-header bg-danger text-white">
                                <i class="bi bi-exclamation-triangle me-1"></i> Zona de Perigo
                            </div>
                            <div class="card-body">
                                <p class="mb-2 small text-muted">
                                    A exclusão da conta removerá permanentemente todos os dados associados, incluindo:
                                </p>
                                <ul class="small text-muted mb-3">
                                    <li>Custos de produtos cadastrados</li>
                                    <li>Histórico de preços e alterações</li>
                                    <li>Regras de precificação</li>
                                    <li>Simulações de promoções</li>
                                    <li>Alertas de ranking</li>
                                </ul>
                                <button class="btn btn-danger" data-pricing-cmd="confirmarExclusaoConta">
                                    <i class="bi bi-trash me-1"></i> Excluir Esta Conta Permanentemente
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Configurações de Alertas -->
                    <div class="tab-pane fade" id="tab-alerts">
                        <div class="row g-3">
                            <div class="col-12">
                                <h6>Limites de Margem</h6>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-danger">Margem Crítica abaixo de (%)</label>
                                <input type="number" class="form-control border-danger" id="alertaCritica" value="5" step="0.5">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-warning">Margem Baixa abaixo de (%)</label>
                                <input type="number" class="form-control border-warning" id="alertaBaixa" value="10" step="0.5">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-success">Margem Boa acima de (%)</label>
                                <input type="number" class="form-control border-success" id="alertaBoa" value="20" step="0.5">
                            </div>
                            <div class="col-12 mt-3">
                                <h6>Notificações</h6>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="notifyBrowser" checked>
                                    <label class="form-check-label" for="notifyBrowser">
                                        Notificações do navegador para alertas críticos
                                    </label>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="notifySound" checked>
                                    <label class="form-check-label" for="notifySound">
                                        Som de alerta para margens negativas
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-primary" data-pricing-cmd="salvarConfiguracaoAlertas">
                                <i class="bi bi-check me-1"></i> Salvar Alertas
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Relatório de Performance -->
<div class="modal fade" id="performanceReportModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-speedometer2 me-2"></i>Relatório de Performance
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Período</label>
                    <select class="form-select" id="performancePeriodo" data-pricing-change="carregarPerformance">
                        <option value="7">Últimos 7 dias</option>
                        <option value="30" selected>Últimos 30 dias</option>
                        <option value="60">Últimos 60 dias</option>
                        <option value="90">Últimos 90 dias</option>
                    </select>
                </div>
                <div id="performanceContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-info"></div>
                        <p class="mt-2 text-muted">Carregando relatório...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Sugestão Automática de Preço -->
<div class="modal fade" id="autoSuggestModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-magic me-2"></i>Sugestão Automática de Preço
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Selecione um item</label>
                    <input type="text" class="form-control" id="autoSuggestItemId"
                        placeholder="Ex: MLB1234567890">
                </div>
                <button type="button" class="btn btn-primary" data-pricing-cmd="buscarSugestaoAutomatica">
                    <i class="bi bi-search me-1"></i> Analisar
                </button>

                <div id="autoSuggestResult" class="mt-4" style="display: none;">
                    <!-- Resultado será inserido aqui -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Previsão de Margem -->
<div class="modal fade" id="forecastModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-calculator me-2"></i>Previsão de Margem
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Item ID</label>
                    <input type="text" class="form-control" id="forecastItemId"
                        placeholder="Ex: MLB1234567890">
                </div>
                <button type="button" class="btn btn-warning" data-pricing-cmd="calcularPrevisao">
                    <i class="bi bi-graph-up me-1"></i> Calcular Previsões
                </button>

                <div id="forecastResult" class="mt-4" style="display: none;">
                    <!-- Resultado será inserido aqui -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Monitorar Concorrentes -->
<div class="modal fade" id="competitorsModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-people me-2"></i>Monitorar Concorrentes
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Item para monitorar</label>
                    <input type="text" class="form-control" id="competitorItemId"
                        placeholder="Ex: MLB1234567890">
                </div>
                <button type="button" class="btn btn-success" data-pricing-cmd="monitorarConcorrentes">
                    <i class="bi bi-search me-1"></i> Buscar Concorrentes
                </button>

                <div id="competitorsResult" class="mt-4" style="display: none;">
                    <!-- Resultado será inserido aqui -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Alertas de Preço -->
<div class="modal fade" id="priceAlertsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-bell me-2"></i>Alertas de Preço
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Criar novo alerta -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-plus-circle me-1"></i> Criar Novo Alerta
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Item ID</label>
                                <input type="text" class="form-control" id="alertItemId" placeholder="MLB1234567890">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tipo de Alerta</label>
                                <select class="form-select" id="alertTipo">
                                    <option value="concorrente_abaixo">Concorrente com preço menor</option>
                                    <option value="margem_minima">Margem abaixo do mínimo</option>
                                    <option value="ranking_perdido">Perda de posição no ranking</option>
                                    <option value="preco_alterado">Preço do item alterado</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Valor Gatilho (%)</label>
                                <input type="number" class="form-control" id="alertValor" value="5" min="1" max="100">
                            </div>
                            <div class="col-md-4">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="alertEmail" checked>
                                    <label class="form-check-label" for="alertEmail">
                                        Notificar por Email
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="alertWhatsapp">
                                    <label class="form-check-label" for="alertWhatsapp">
                                        Notificar WhatsApp
                                    </label>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-danger mt-3" data-pricing-cmd="criarAlertaPreco">
                            <i class="bi bi-bell-fill me-1"></i> Criar Alerta
                        </button>
                    </div>
                </div>

                <!-- Lista de alertas -->
                <h6><i class="bi bi-list-ul me-1"></i> Alertas Configurados</h6>
                <div id="alertasList">
                    <div class="text-center py-4">
                        <div class="spinner-border text-danger spinner-border-sm"></div>
                        <span class="ms-2 text-muted">Carregando alertas...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Importar Custos -->
<div class="modal fade" id="importCostsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-upload me-2"></i>Importar Custos (CSV)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <h6>Formato do CSV:</h6>
                    <code>item_id,custo_produto,custo_frete,imposto_percentual,taxa_ml_percentual,custo_fixo</code>
                    <hr>
                    <small>Apenas <strong>item_id</strong> e <strong>custo_produto</strong> são obrigatórios.</small>
                </div>

                <form id="importCostsForm" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Arquivo CSV</label>
                        <input type="file" class="form-control" id="importFile" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-secondary w-100">
                        <i class="bi bi-cloud-upload me-1"></i> Importar
                    </button>
                </form>

                <div id="importResult" class="mt-3" style="display: none;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Calcular Preço Ideal -->
<div class="modal fade" id="idealPriceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-calculator me-2"></i>Calcular Preço Ideal
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Item ID</label>
                    <input type="text" class="form-control" id="idealItemId" placeholder="MLB1234567890">
                </div>
                <div class="mb-3">
                    <label class="form-label">Margem Desejada (%)</label>
                    <input type="number" class="form-control" id="idealMargem" value="20" min="1" max="100">
                </div>
                <button type="button" class="btn btn-primary w-100" data-pricing-cmd="calcularPrecoIdeal">
                    <i class="bi bi-calculator me-1"></i> Calcular
                </button>

                <div id="idealPriceResult" class="mt-4" style="display: none;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Análise de Rentabilidade -->
<div class="modal fade" id="profitabilityModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-piggy-bank me-2"></i>Análise de Rentabilidade
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="profitabilityContent">
                    <div class="text-center py-5">
                        <div class="spinner-border text-success"></div>
                        <p class="mt-2 text-muted">Analisando rentabilidade...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Auto-Otimizador de Preços -->
<div class="modal fade" id="autoOptimizerModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-robot me-2"></i>Auto-Otimizador de Preços
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Tabs -->
                <ul class="nav nav-tabs mb-4" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#optimizer-config">
                            <i class="bi bi-gear me-1"></i> Configuração
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#optimizer-run">
                            <i class="bi bi-play-circle me-1"></i> Executar
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#optimizer-history">
                            <i class="bi bi-clock-history me-1"></i> Histórico
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#optimizer-stats">
                            <i class="bi bi-graph-up me-1"></i> Estatísticas
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Tab: Configuração -->
                    <div class="tab-pane fade show active" id="optimizer-config">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <i class="bi bi-sliders me-1"></i> Configurações Gerais
                                    </div>
                                    <div class="card-body">
                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" id="optimizerEnabled">
                                            <label class="form-check-label" for="optimizerEnabled">
                                                <strong>Ativar Auto-Otimizador</strong>
                                            </label>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Modo de Operação</label>
                                            <select class="form-select" id="optimizerMode">
                                                <option value="suggest">Apenas Sugerir (requer aprovação)</option>
                                                <option value="auto_apply">Aplicar Automaticamente</option>
                                            </select>
                                            <small class="text-muted">No modo automático, os preços são ajustados sem confirmação</small>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Margem Mínima (%)</label>
                                            <input type="number" class="form-control" id="optimizerMinMargin" value="10" min="0" max="100" step="0.5">
                                            <small class="text-muted">Nunca reduzir preço abaixo desta margem</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <i class="bi bi-people me-1"></i> Estratégia de Concorrência
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Estratégia</label>
                                            <select class="form-select" id="optimizerStrategy">
                                                <option value="match_lowest">Igualar ao menor preço</option>
                                                <option value="stay_below">Ficar abaixo da média</option>
                                                <option value="stay_above">Ficar acima do menor</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Buffer de Margem (%)</label>
                                            <input type="number" class="form-control" id="optimizerBuffer" value="2" min="0" max="20" step="0.5">
                                            <small class="text-muted">Margem adicional sobre o preço do concorrente</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <i class="bi bi-arrow-left-right me-1"></i> Limites de Variação
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Máximo Aumento (%)</label>
                                            <input type="number" class="form-control" id="optimizerMaxIncrease" value="8" min="1" max="50">
                                            <small class="text-muted">Limite para não prejudicar ranking</small>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Máxima Redução (%)</label>
                                            <input type="number" class="form-control" id="optimizerMaxDecrease" value="15" min="1" max="50">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <i class="bi bi-bell me-1"></i> Notificações
                                    </div>
                                    <div class="card-body">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="optimizerNotifyEmail" checked>
                                            <label class="form-check-label" for="optimizerNotifyEmail">
                                                Notificar por E-mail
                                            </label>
                                        </div>

                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="optimizerNotifyChanges" checked>
                                            <label class="form-check-label" for="optimizerNotifyChanges">
                                                Notificar a cada alteração
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="button" class="btn btn-primary" data-pricing-cmd="salvarConfigOptimizer">
                                <i class="bi bi-check-lg me-1"></i> Salvar Configuração
                            </button>
                        </div>
                    </div>

                    <!-- Tab: Executar -->
                    <div class="tab-pane fade" id="optimizer-run">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Execute a otimização manualmente para analisar todos os itens e gerar sugestões de preço.
                        </div>

                        <button type="button" class="btn btn-success btn-lg mb-4" data-pricing-cmd="executarOptimizer">
                            <i class="bi bi-play-fill me-1"></i> Executar Otimização
                        </button>

                        <div id="optimizerResults" style="display: none;">
                            <!-- Resultados serão inseridos aqui -->
                        </div>
                    </div>

                    <!-- Tab: Histórico -->
                    <div class="tab-pane fade" id="optimizer-history">
                        <div id="optimizerHistoryContent">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary spinner-border-sm"></div>
                                <span class="ms-2">Carregando histórico...</span>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Estatísticas -->
                    <div class="tab-pane fade" id="optimizer-stats">
                        <div id="optimizerStatsContent">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary spinner-border-sm"></div>
                                <span class="ms-2">Carregando estatísticas...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Testes A/B de Preços -->
<div class="modal fade" id="abTestModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-signpost-split me-2"></i>Testes A/B de Preços
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#abtest-list">
                            <i class="bi bi-list-ul me-1"></i>Testes
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#abtest-new">
                            <i class="bi bi-plus-circle me-1"></i>Novo Teste
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#abtest-analysis">
                            <i class="bi bi-graph-up me-1"></i>Análise
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Lista de Testes -->
                    <div class="tab-pane fade show active" id="abtest-list">
                        <div class="mb-3">
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-secondary active" data-pricing-cmd="filtrarAbTests" data-pricing-args='["all"]'>Todos</button>
                                <button class="btn btn-sm btn-outline-success" data-pricing-cmd="filtrarAbTests" data-pricing-args='["running"]'>Em Execução</button>
                                <button class="btn btn-sm btn-outline-warning" data-pricing-cmd="filtrarAbTests" data-pricing-args='["draft"]'>Rascunho</button>
                                <button class="btn btn-sm btn-outline-primary" data-pricing-cmd="filtrarAbTests" data-pricing-args='["completed"]'>Finalizados</button>
                            </div>
                        </div>
                        <div id="abTestList">
                            <div class="text-center py-4">
                                <div class="spinner-border spinner-border-sm"></div>
                                <span class="ms-2">Carregando testes...</span>
                            </div>
                        </div>
                    </div>

                    <!-- Novo Teste -->
                    <div class="tab-pane fade" id="abtest-new">
                        <form id="newAbTestForm" data-pricing-submit="criarAbTest" data-pricing-pass-event="1">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Nome do Teste *</label>
                                    <input type="text" class="form-control" id="abTestName" required placeholder="Ex: Teste de elasticidade produto X">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Item ID *</label>
                                    <input type="text" class="form-control" id="abTestItemId" required placeholder="MLB123456789">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Descrição</label>
                                    <textarea class="form-control" id="abTestDescription" rows="2" placeholder="Objetivo do teste..."></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Preço Controle (atual) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">R$</span>
                                        <input type="number" class="form-control" id="abTestControlPrice" required step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Preço Variante (teste) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">R$</span>
                                        <input type="number" class="form-control" id="abTestVariantPrice" required step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Split de Tráfego</label>
                                    <select class="form-select" id="abTestSplit">
                                        <option value="50">50/50</option>
                                        <option value="70">70/30 (Variante)</option>
                                        <option value="30">30/70 (Controle)</option>
                                        <option value="80">80/20 (Variante)</option>
                                    </select>
                                    <small class="text-muted">% do tempo com preço variante</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Métrica Principal</label>
                                    <select class="form-select" id="abTestMetric">
                                        <option value="revenue">Receita Total</option>
                                        <option value="units_sold">Unidades Vendidas</option>
                                        <option value="conversion_rate">Taxa de Conversão</option>
                                        <option value="profit">Lucro</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Amostra Mínima</label>
                                    <input type="number" class="form-control" id="abTestMinSample" value="100" min="10">
                                    <small class="text-muted">Visitas necessárias</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Nível de Confiança</label>
                                    <select class="form-select" id="abTestConfidence">
                                        <option value="90">90%</option>
                                        <option value="95" selected>95%</option>
                                        <option value="99">99%</option>
                                    </select>
                                </div>
                                <div class="col-12 text-end">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-plus-circle me-1"></i>Criar Teste
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Análise Detalhada -->
                    <div class="tab-pane fade" id="abtest-analysis">
                        <div id="abTestAnalysis">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Selecione um teste na lista para ver a análise detalhada.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Monitoramento de Concorrentes -->
<div class="modal fade" id="competitorMonitorModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    <i class="bi bi-eye me-2"></i>Monitor de Concorrentes
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#monitor-watchlist">
                            <i class="bi bi-list-check me-1"></i>Watchlist
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#monitor-scan">
                            <i class="bi bi-search me-1"></i>Escanear
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#monitor-alerts">
                            <i class="bi bi-bell me-1"></i>Alertas
                            <span class="badge bg-danger ms-1" id="unreadAlertsCount" style="display:none">0</span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#monitor-analysis">
                            <i class="bi bi-bar-chart me-1"></i>Análise
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Watchlist -->
                    <div class="tab-pane fade show active" id="monitor-watchlist">
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <div class="input-group">
                                    <input type="text" class="form-control" id="watchlistItemId" placeholder="ID do item (MLB...)">
                                    <input type="text" class="form-control" id="watchlistKeywords" placeholder="Palavras-chave (opcional)">
                                    <button class="btn btn-primary" data-pricing-cmd="adicionarWatchlist">
                                        <i class="bi bi-plus"></i> Adicionar
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <button class="btn btn-outline-secondary" data-pricing-cmd="carregarWatchlist">
                                    <i class="bi bi-arrow-clockwise"></i> Atualizar
                                </button>
                            </div>
                        </div>
                        <div id="watchlistContent">
                            <div class="text-center py-4">
                                <div class="spinner-border spinner-border-sm"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Escanear -->
                    <div class="tab-pane fade" id="monitor-scan">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Item para Escanear</label>
                                <input type="text" class="form-control" id="scanItemId" placeholder="MLB123456789">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Palavras-chave (opcional)</label>
                                <input type="text" class="form-control" id="scanKeywords" placeholder="produto marca">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-primary w-100" data-pricing-cmd="escanearConcorrentes">
                                    <i class="bi bi-search"></i> Escanear
                                </button>
                            </div>
                        </div>
                        <div id="scanResults">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Insira o ID de um item para buscar concorrentes no Mercado Livre.
                            </div>
                        </div>
                    </div>

                    <!-- Alertas -->
                    <div class="tab-pane fade" id="monitor-alerts">
                        <div class="mb-3">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-secondary active" data-pricing-cmd="filtrarAlertas" data-pricing-args='["all"]'>Todos</button>
                                <button class="btn btn-outline-danger" data-pricing-cmd="filtrarAlertas" data-pricing-args='["critical"]'>Críticos</button>
                                <button class="btn btn-outline-warning" data-pricing-cmd="filtrarAlertas" data-pricing-args='["high"]'>Altos</button>
                                <button class="btn btn-outline-info" data-pricing-cmd="filtrarAlertas" data-pricing-args='["medium"]'>Médios</button>
                            </div>
                            <button class="btn btn-sm btn-outline-primary float-end" data-pricing-cmd="marcarTodosAlertasLidos">
                                <i class="bi bi-check-all"></i> Marcar todos como lidos
                            </button>
                        </div>
                        <div id="alertsContent">
                            <div class="text-center py-4">
                                <div class="spinner-border spinner-border-sm"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Análise de Mercado -->
                    <div class="tab-pane fade" id="monitor-analysis">
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <input type="text" class="form-control" id="analysisItemId" placeholder="ID do item para análise de mercado">
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-primary" data-pricing-cmd="carregarAnalise">
                                    <i class="bi bi-bar-chart"></i> Analisar Mercado
                                </button>
                            </div>
                        </div>
                        <div id="analysisContent">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Selecione um item para ver a análise completa do mercado.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: IA Preditiva de Preços -->
<div class="modal fade" id="aiPricingModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h5 class="modal-title">
                    <i class="bi bi-cpu me-2"></i>IA Preditiva de Preços
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ai-suggest">
                            <i class="bi bi-magic me-1"></i>Sugestão IA
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ai-elasticity">
                            <i class="bi bi-graph-down me-1"></i>Elasticidade
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ai-forecast">
                            <i class="bi bi-calendar-range me-1"></i>Previsão de Receita
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ai-dynamic">
                            <i class="bi bi-lightning me-1"></i>Pricing Dinâmico
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Sugestão IA -->
                    <div class="tab-pane fade show active" id="ai-suggest">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Item para Análise</label>
                                <input type="text" class="form-control" id="aiSuggestItemId" placeholder="MLB123456789">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Objetivo</label>
                                <select class="form-select" id="aiGoal">
                                    <option value="balanced">Equilibrado</option>
                                    <option value="volume">Maximizar Volume</option>
                                    <option value="profit">Maximizar Lucro</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-primary w-100" data-pricing-cmd="obterSugestaoIA">
                                    <i class="bi bi-cpu"></i> Analisar
                                </button>
                            </div>
                        </div>
                        <div id="aiSuggestResults">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Informe um item para obter sugestão de preço baseada em IA com análise de mercado, concorrência e margem.
                            </div>
                        </div>
                    </div>

                    <!-- Elasticidade -->
                    <div class="tab-pane fade" id="ai-elasticity">
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <label class="form-label">Item para Análise de Elasticidade</label>
                                <input type="text" class="form-control" id="aiElasticityItemId" placeholder="MLB123456789">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-primary w-100" data-pricing-cmd="analisarElasticidade">
                                    <i class="bi bi-graph-down"></i> Analisar Elasticidade
                                </button>
                            </div>
                        </div>
                        <div id="aiElasticityResults">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                A elasticidade de preço mede o quanto a demanda reage a mudanças de preço.
                                Elasticidade > 1 significa alta sensibilidade a preço.
                            </div>
                        </div>
                    </div>

                    <!-- Previsão de Receita -->
                    <div class="tab-pane fade" id="ai-forecast">
                        <div class="row mb-3">
                            <div class="col-md-5">
                                <label class="form-label">Item</label>
                                <input type="text" class="form-control" id="aiForecastItemId" placeholder="MLB123456789">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Preços a Testar (separados por vírgula)</label>
                                <input type="text" class="form-control" id="aiForecastPrices" placeholder="99.90, 119.90, 139.90">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-primary w-100" data-pricing-cmd="preverReceita">
                                    <i class="bi bi-calculator"></i> Prever
                                </button>
                            </div>
                        </div>
                        <div id="aiForecastResults">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Simule diferentes preços para prever volume de vendas e receita estimada em 30 dias.
                            </div>
                        </div>
                    </div>

                    <!-- Pricing Dinâmico -->
                    <div class="tab-pane fade" id="ai-dynamic">
                        <div class="row mb-3">
                            <div class="col-md-5">
                                <label class="form-label">Item</label>
                                <input type="text" class="form-control" id="aiDynamicItemId" placeholder="MLB123456789">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Margem Mínima</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="aiDynamicMinMargin" value="15">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Agressivo</label>
                                <select class="form-select" id="aiDynamicAggressive">
                                    <option value="false">Não</option>
                                    <option value="true">Sim</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-primary w-100" data-pricing-cmd="calcularPricingDinamico">
                                    <i class="bi bi-lightning"></i> Calcular
                                </button>
                            </div>
                        </div>
                        <div id="aiDynamicResults">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                O pricing dinâmico calcula o preço ótimo baseado em concorrência em tempo real,
                                respeitando sua margem mínima.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- PHASE 3: MODAIS AVANÇADOS DE PRECIFICAÇÃO -->
<!-- ========================================= -->

<!-- Modal: Motor de Regras -->
<div class="modal fade" id="rulesEngineModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-gear-wide-connected me-2"></i>Motor de Regras de Precificação
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="rulesTab">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#rules-list">
                            <i class="bi bi-list-ul me-1"></i>Minhas Regras
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#rules-create">
                            <i class="bi bi-plus-lg me-1"></i>Criar Regra
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#rules-templates">
                            <i class="bi bi-file-earmark-text me-1"></i>Templates
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#rules-simulate">
                            <i class="bi bi-play-circle me-1"></i>Simular
                        </button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="rules-list">
                        <div class="d-flex justify-content-between mb-3">
                            <div>
                                <select class="form-select form-select-sm d-inline-block w-auto" id="rulesFilterType">
                                    <option value="">Todos os tipos</option>
                                    <option value="match_competitor">Igualar Concorrente</option>
                                    <option value="floor_ceiling">Piso/Teto</option>
                                    <option value="time_based">Por Horário</option>
                                    <option value="margin_based">Por Margem</option>
                                    <option value="stock_based">Por Estoque</option>
                                    <option value="velocity_based">Por Velocidade</option>
                                    <option value="category_position">Por Posição</option>
                                </select>
                            </div>
                            <button class="btn btn-primary btn-sm" data-pricing-cmd="executeAllEngineRules">
                                <i class="bi bi-play-fill me-1"></i>Executar Todas
                            </button>
                        </div>
                        <div id="rulesListContainer">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status"></div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="rules-create">
                        <form id="createRuleForm">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Nome da Regra</label>
                                    <input type="text" class="form-control" id="ruleName" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tipo</label>
                                    <select class="form-select" id="ruleType" data-pricing-change="updateRuleConfigUI">
                                        <option value="match_competitor">Igualar Concorrente</option>
                                        <option value="floor_ceiling">Piso/Teto</option>
                                        <option value="time_based">Por Horário</option>
                                        <option value="margin_based">Por Margem</option>
                                        <option value="stock_based">Por Estoque</option>
                                        <option value="velocity_based">Por Velocidade</option>
                                        <option value="category_position">Por Posição na Categoria</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Prioridade</label>
                                    <input type="number" class="form-control" id="rulePriority" value="100" min="1" max="999">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Descrição (opcional)</label>
                                    <input type="text" class="form-control" id="ruleDescription">
                                </div>
                                <div class="col-12" id="ruleConfigContainer">
                                    <!-- Config dinâmica baseada no tipo -->
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-lg me-1"></i>Criar Regra
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="rules-templates">
                        <div id="rulesTemplatesContainer" class="row g-3">
                            <!-- Templates carregados via JS -->
                        </div>
                    </div>
                    <div class="tab-pane fade" id="rules-simulate">
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <label class="form-label">IDs dos Itens (separados por vírgula)</label>
                                <input type="text" class="form-control" id="simulateItemIds" placeholder="MLB123, MLB456">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-primary w-100" data-pricing-cmd="simulateEngineRules">
                                    <i class="bi bi-play-fill me-1"></i>Simular
                                </button>
                            </div>
                        </div>
                        <div id="simulationResults"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Agendamento de Preços -->
<div class="modal fade" id="scheduledPricesModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-calendar-event me-2"></i>Agendamento de Preços
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="schedulesTab">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#schedules-calendar">
                            <i class="bi bi-calendar3 me-1"></i>Calendário
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#schedules-list">
                            <i class="bi bi-list-ul me-1"></i>Agendamentos
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#schedules-create">
                            <i class="bi bi-plus-lg me-1"></i>Criar Agendamento
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#schedules-campaigns">
                            <i class="bi bi-megaphone me-1"></i>Campanhas
                        </button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="schedules-calendar">
                        <div id="scheduleCalendarContainer">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Visualize todos os agendamentos de preços em formato de calendário.
                            </div>
                            <div id="scheduleCalendar" class="border rounded p-3"></div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="schedules-list">
                        <div id="schedulesListContainer"></div>
                    </div>
                    <div class="tab-pane fade" id="schedules-create">
                        <form id="createScheduleForm">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">ID do Item</label>
                                    <input type="text" class="form-control" id="scheduleItemId" required placeholder="MLB123456789">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Novo Preço</label>
                                    <div class="input-group">
                                        <span class="input-group-text">R$</span>
                                        <input type="number" class="form-control" id="schedulePrice" step="0.01" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Data/Hora de Execução</label>
                                    <input type="datetime-local" class="form-control" id="scheduleDateTime" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tipo</label>
                                    <select class="form-select" id="scheduleType" data-pricing-change="toggleScheduleRecurrence">
                                        <option value="single">Único</option>
                                        <option value="recurrent">Recorrente</option>
                                    </select>
                                </div>
                                <div class="col-12 d-none" id="recurrenceOptions">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <div class="row g-2">
                                                <div class="col-md-4">
                                                    <label class="form-label">Padrão</label>
                                                    <select class="form-select" id="recurrencePattern">
                                                        <option value="daily">Diário</option>
                                                        <option value="weekly">Semanal</option>
                                                        <option value="monthly">Mensal</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">Até</label>
                                                    <input type="date" class="form-control" id="recurrenceEnd">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Rollback após (opcional)</label>
                                    <input type="datetime-local" class="form-control" id="scheduleRollback">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Preço de Rollback</label>
                                    <div class="input-group">
                                        <span class="input-group-text">R$</span>
                                        <input type="number" class="form-control" id="scheduleRollbackPrice" step="0.01">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-calendar-plus me-1"></i>Criar Agendamento
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="schedules-campaigns">
                        <div class="mb-3">
                            <button class="btn btn-success btn-sm" data-pricing-cmd="showCreateCampaignForm">
                                <i class="bi bi-plus-lg me-1"></i>Nova Campanha
                            </button>
                        </div>
                        <div id="campaignsListContainer"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Analytics Avançado -->
<div class="modal fade" id="priceAnalyticsModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-graph-up-arrow me-2"></i>Analytics de Preços Avançado
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="analyticsTab">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#analytics-dashboard">
                            <i class="bi bi-speedometer2 me-1"></i>Dashboard
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#analytics-trends">
                            <i class="bi bi-graph-up me-1"></i>Tendências
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#analytics-elasticity">
                            <i class="bi bi-arrow-left-right me-1"></i>Elasticidade
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#analytics-roi">
                            <i class="bi bi-cash-coin me-1"></i>ROI
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#analytics-forecast">
                            <i class="bi bi-stars me-1"></i>Previsão
                        </button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="analytics-dashboard">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <select class="form-select" id="analyticsPeriod" data-pricing-change="loadAnalyticsDashboard">
                                    <option value="7d">Últimos 7 dias</option>
                                    <option value="30d" selected>Últimos 30 dias</option>
                                    <option value="90d">Últimos 90 dias</option>
                                </select>
                            </div>
                        </div>
                        <div id="analyticsDashboardContainer">
                            <div class="row g-3" id="analyticsMetrics"></div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="analytics-trends">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="trendItemId" placeholder="ID do Item (MLB...)">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="trendDays">
                                    <option value="7">7 dias</option>
                                    <option value="30" selected>30 dias</option>
                                    <option value="90">90 dias</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-info w-100" data-pricing-cmd="loadPriceTrend">
                                    <i class="bi bi-graph-up me-1"></i>Analisar
                                </button>
                            </div>
                        </div>
                        <div id="trendChartContainer">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="analytics-elasticity">
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <input type="text" class="form-control" id="elasticityItemId" placeholder="ID do Item">
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-info w-100" data-pricing-cmd="analyzeElasticity">
                                    <i class="bi bi-arrow-left-right me-1"></i>Calcular Elasticidade
                                </button>
                            </div>
                        </div>
                        <div id="elasticityResults"></div>
                    </div>
                    <div class="tab-pane fade" id="analytics-roi">
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Item ID</label>
                                <input type="text" class="form-control" id="roiItemId" placeholder="MLB...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Preço Atual</label>
                                <input type="number" class="form-control" id="roiOldPrice" step="0.01">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Novo Preço</label>
                                <input type="number" class="form-control" id="roiNewPrice" step="0.01">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Período</label>
                                <select class="form-select" id="roiPeriod">
                                    <option value="7">7 dias</option>
                                    <option value="30" selected>30 dias</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-info w-100" data-pricing-cmd="calculateROI">
                                    <i class="bi bi-calculator me-1"></i>Calcular ROI
                                </button>
                            </div>
                        </div>
                        <div id="roiResults"></div>
                    </div>
                    <div class="tab-pane fade" id="analytics-forecast">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="forecastItemId" placeholder="ID do Item">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="forecastDays">
                                    <option value="7">7 dias</option>
                                    <option value="14">14 dias</option>
                                    <option value="30" selected>30 dias</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-info w-100" data-pricing-cmd="forecastPrice">
                                    <i class="bi bi-stars me-1"></i>Prever
                                </button>
                            </div>
                        </div>
                        <div id="forecastResults"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Editor em Massa -->
<div class="modal fade" id="bulkEditorModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2"></i>Editor de Preços em Massa
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Atenção:</strong> Alterações em massa afetam múltiplos produtos.
                    Sempre faça preview antes de aplicar.
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Operação</label>
                        <select class="form-select" id="bulkOperation" data-pricing-change="updateBulkValueLabel">
                            <option value="percent_increase">Aumentar % </option>
                            <option value="percent_decrease">Diminuir %</option>
                            <option value="fixed_increase">Aumentar R$</option>
                            <option value="fixed_decrease">Diminuir R$</option>
                            <option value="set_price">Definir Preço</option>
                            <option value="match_competitor">Igualar Menor Concorrente</option>
                            <option value="set_margin">Definir Margem %</option>
                            <option value="round_price">Arredondar (.90 ou .99)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" id="bulkValueLabel">Valor</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="bulkValue" step="0.01">
                            <span class="input-group-text" id="bulkValueSuffix">%</span>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Filtrar Itens</label>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <select class="form-select" id="bulkFilterCategory">
                                <option value="">Todas as categorias</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="bulkFilterIds" placeholder="IDs específicos (separados por vírgula)">
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-primary" data-pricing-cmd="previewBulkEdit">
                        <i class="bi bi-eye me-1"></i>Preview
                    </button>
                    <button class="btn btn-success" data-pricing-cmd="applyBulkEdit" id="applyBulkBtn" disabled>
                        <i class="bi bi-check-lg me-1"></i>Aplicar
                    </button>
                </div>

                <div id="bulkPreviewContainer" class="d-none">
                    <h6>Preview das Alterações:</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped" id="bulkPreviewTable">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Preço Atual</th>
                                    <th>Novo Preço</th>
                                    <th>Variação</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <hr>
                <h6>Histórico de Operações</h6>
                <div id="bulkBatchesHistory"></div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Central de Notificações -->
<div class="modal fade" id="notificationsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-bell-fill me-2"></i>Central de Notificações
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="notificationsTab">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#notif-channels">
                            <i class="bi bi-broadcast me-1"></i>Canais
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#notif-history">
                            <i class="bi bi-clock-history me-1"></i>Histórico
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#notif-events">
                            <i class="bi bi-lightning me-1"></i>Eventos
                        </button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="notif-channels">
                        <div class="mb-3">
                            <button class="btn btn-success btn-sm" data-pricing-cmd="showCreateChannelForm">
                                <i class="bi bi-plus-lg me-1"></i>Novo Canal
                            </button>
                        </div>
                        <div id="channelsListContainer"></div>

                        <div id="createChannelForm" class="d-none border rounded p-3 mt-3">
                            <h6>Criar Novo Canal</h6>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">Nome</label>
                                    <input type="text" class="form-control" id="channelName">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tipo</label>
                                    <select class="form-select" id="channelType" data-pricing-change="updateChannelConfigForm">
                                        <option value="email">Email</option>
                                        <option value="webhook">Webhook</option>
                                        <option value="slack">Slack</option>
                                        <option value="discord">Discord</option>
                                    </select>
                                </div>
                                <div class="col-12" id="channelConfigFields">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" id="channelConfigEmail">
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-success btn-sm" data-pricing-cmd="createNotificationChannel">
                                        <i class="bi bi-check-lg me-1"></i>Criar
                                    </button>
                                    <button class="btn btn-secondary btn-sm" data-pricing-cmd="hideCreateChannelForm">Cancelar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="notif-history">
                        <div class="mb-3">
                            <select class="form-select form-select-sm w-auto d-inline-block" id="historyFilterEvent">
                                <option value="">Todos os eventos</option>
                            </select>
                        </div>
                        <div id="notificationHistoryContainer"></div>
                    </div>
                    <div class="tab-pane fade" id="notif-events">
                        <div id="availableEventsContainer">
                            <p class="text-muted">Configure quais eventos devem disparar notificações em cada canal.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .bg-purple {
        background-color: #667eea !important;
    }

    .text-purple {
        color: #667eea !important;
    }

    .badge.bg-purple {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    }

    .margin-indicator {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        margin-right: 5px;
    }

    .margin-indicator.verde {
        background-color: #198754;
    }

    .margin-indicator.amarelo {
        background-color: #ffc107;
    }

    .margin-indicator.laranja {
        background-color: #fd7e14;
    }

    .margin-indicator.vermelho {
        background-color: #dc3545;
    }

    .margin-indicator.cinza {
        background-color: #6c757d;
    }

    .discount-badge {
        cursor: pointer;
        transition: all 0.2s;
    }

    .discount-badge:hover {
        transform: scale(1.05);
    }

    .discount-badge.viable {
        border-color: #198754 !important;
    }

    .discount-badge.not-viable {
        border-color: #dc3545 !important;
        opacity: 0.6;
    }

    #rankingAlert.alert-success {
        background-color: #d1e7dd;
        border-color: #badbcc;
    }

    #rankingAlert.alert-warning {
        background-color: #fff3cd;
        border-color: #ffecb5;
    }

    #rankingAlert.alert-danger {
        background-color: #f8d7da;
        border-color: #f5c2c7;
    }
</style>

<script nonce="<?= CSP_NONCE ?>">

    const ACCOUNT_ID = <?= json_encode($accountId) ?>;
    const HAS_ACCOUNT_ID = Number.isInteger(Number(ACCOUNT_ID)) && Number(ACCOUNT_ID) > 0;
    const API_BASE = HAS_ACCOUNT_ID ? `/api/pricing-intelligence/${ACCOUNT_ID}` : null;
    const MAX_AGENT_LOG_QUERY_LENGTH = 1800;

    // CSP-safe command bus (substitui onclick/onchange/onsubmit inline)
    function resolvePricingCmdArgs(el, event) {
        const argsJson = el.getAttribute('data-pricing-args');
        if (argsJson) {
            try {
                const parsed = JSON.parse(argsJson);
                return Array.isArray(parsed) ? parsed.map((v) => (v && v.__event ? event : v)) : [parsed];
            } catch (_) {
                /* fall through */
            }
        }
        const numbered = [];
        for (let i = 0; i < 8; i++) {
            if (!el.hasAttribute(`data-pricing-arg${i}`)) break;
            let raw = el.getAttribute(`data-pricing-arg${i}`);
            if (raw === 'true') numbered.push(true);
            else if (raw === 'false') numbered.push(false);
            else if (raw === 'null') numbered.push(null);
            else if (raw !== '' && !Number.isNaN(Number(raw)) && /^-?\d+(\.\d+)?$/.test(raw)) numbered.push(Number(raw));
            else numbered.push(raw);
        }
        if (numbered.length) return numbered;

        const raw = el.getAttribute('data-pricing-args-raw');
        if (raw == null || raw === '') {
            return el.hasAttribute('data-pricing-pass-event') ? [event] : [];
        }
        // Ex.: "123", "123, true", "'MLB1', 9.9", "123, this.checked"
        const parts = [];
        let cur = '';
        let inQuote = null;
        for (let i = 0; i < raw.length; i++) {
            const ch = raw[i];
            if (inQuote) {
                cur += ch;
                if (ch === inQuote) inQuote = null;
                continue;
            }
            if (ch === "'" || ch === '"') {
                inQuote = ch;
                cur += ch;
                continue;
            }
            if (ch === ',') {
                parts.push(cur.trim());
                cur = '';
                continue;
            }
            cur += ch;
        }
        if (cur.trim() !== '') parts.push(cur.trim());

        return parts.map((p) => {
            if (p === 'event' || p === 'e') return event;
            if (p === 'this') return el;
            if (p === 'this.checked') return !!el.checked;
            if (p === 'this.value') return el.value;
            if (p === 'true') return true;
            if (p === 'false') return false;
            if (p === 'null') return null;
            if ((p.startsWith("'") && p.endsWith("'")) || (p.startsWith('"') && p.endsWith('"'))) {
                return p.slice(1, -1);
            }
            if (/^-?\d+(\.\d+)?$/.test(p)) return Number(p);
            return p;
        });
    }

    function invokePricingCommand(el, event, attrName) {
        const fnName = el.getAttribute(attrName);
        if (!fnName) return false;
        const fn = window[fnName];
        if (typeof fn !== 'function') {
            console.warn('[pricing] comando não encontrado:', fnName);
            return false;
        }
        if (el.hasAttribute('data-pricing-prevent') || el.tagName === 'A') {
            event.preventDefault();
        }
        const args = resolvePricingCmdArgs(el, event);
        fn.apply(el, args);
        return true;
    }

    if (!window.__pricingCmdBusBound) {
        window.__pricingCmdBusBound = true;
        document.addEventListener('click', function(event) {
            const el = event.target.closest('[data-pricing-cmd]');
            if (!el) return;
            invokePricingCommand(el, event, 'data-pricing-cmd');
        });
        document.addEventListener('change', function(event) {
            const el = event.target.closest('[data-pricing-change]');
            if (!el) return;
            invokePricingCommand(el, event, 'data-pricing-change');
        });
        document.addEventListener('input', function(event) {
            const el = event.target.closest('[data-pricing-input]');
            if (!el) return;
            invokePricingCommand(el, event, 'data-pricing-input');
        });
        document.addEventListener('submit', function(event) {
            const el = event.target.closest('[data-pricing-submit]');
            if (!el) return;
            event.preventDefault();
            invokePricingCommand(el, event, 'data-pricing-submit');
        });
    }

    let currentPage = 1;
    let totalPages = 1;
    let currentItemId = null;
    let currentItemData = null;
    let currentCosts = null;
    let previewMode = false;

    // Inicialização
    document.addEventListener('DOMContentLoaded', async function() {
        if (!HAS_ACCOUNT_ID) {
            // Empty-state já vem do HTML (banner + tabela). Evita toast efêmero + spinner eterno.
            return;
        }

        // Verificar status da conexão ML
        await checkConnectionStatus();

        loadItems();
        loadDashboardStats();
        loadAdvancedMetrics();

        // CSP bloqueia onclick inline — delegação no tbody
        const itemsTableBody = document.getElementById('itemsTableBody');
        if (itemsTableBody && !itemsTableBody.dataset.pricingActionsBound) {
            itemsTableBody.dataset.pricingActionsBound = '1';
            itemsTableBody.addEventListener('click', function(event) {
                const btn = event.target.closest('[data-action="precificar"]');
                if (!btn || !itemsTableBody.contains(btn)) return;
                event.preventDefault();
                const itemId = btn.getAttribute('data-item-id');
                if (itemId) {
                    abrirPrecificador(itemId);
                }
            });
        }

        // CSP-safe: simulador do modal (inputs/botões sem oninput/onclick)
        const pricingModalEl = document.getElementById('pricingModal');
        if (pricingModalEl && !pricingModalEl.dataset.pricingSimBound) {
            pricingModalEl.dataset.pricingSimBound = '1';
            pricingModalEl.addEventListener('input', function(event) {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                if (target.matches('[data-pricing-simulate]')) {
                    calcularSimulacao();
                    return;
                }
                if (target.id === 'promoDesconto' || target.matches('[data-pricing-promo-range]')) {
                    updatePromoPreview();
                }
            });
            pricingModalEl.addEventListener('click', function(event) {
                const btn = event.target.closest('[data-pricing-action]');
                if (!btn || !pricingModalEl.contains(btn)) return;
                const action = btn.getAttribute('data-pricing-action');
                if (action === 'salvar-custos') {
                    event.preventDefault();
                    salvarCustos();
                } else if (action === 'aplicar-preco') {
                    event.preventDefault();
                    aplicarPreco();
                } else if (action === 'simular-promocao') {
                    event.preventDefault();
                    simularPromocao();
                } else if (action === 'simular-central-ofertas') {
                    event.preventDefault();
                    simularCentralOfertas();
                } else if (action === 'criar-regra') {
                    event.preventDefault();
                    criarRegraAutomatica();
                }
            });
        }

        // Enter no campo de busca
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') aplicarFiltros();
        });

        // Toggle chevron no collapse de métricas
        const metricsCollapse = document.getElementById('metricsCollapse');
        if (metricsCollapse) {
            // Debounce evita storm de re-render (mutation + shown + click) e 429 no status.
            let lastMetricsVisible = metricsCollapse.classList.contains('show');
            let lastChartRenderAt = 0;
            const maybeRenderMetricsCharts = (reason) => {
                const now = Date.now();
                if (now - lastChartRenderAt < 350) {
                    return;
                }
                lastChartRenderAt = now;
                renderAdvancedMetricsCharts(true);
            };

            metricsCollapse.addEventListener('show.bs.collapse', function() {
                document.getElementById('metricsChevron').classList.replace('bi-chevron-down', 'bi-chevron-up');
            });
            metricsCollapse.addEventListener('shown.bs.collapse', function() {
                lastMetricsVisible = true;
                maybeRenderMetricsCharts('shown.bs.collapse');
            });
            metricsCollapse.addEventListener('hide.bs.collapse', function() {
                document.getElementById('metricsChevron').classList.replace('bi-chevron-up', 'bi-chevron-down');
            });

            // Fallback: alguns ambientes não disparam consistentemente os eventos bs.collapse.
            const metricsHeader = document.querySelector('[data-bs-target="#metricsCollapse"]');
            if (metricsHeader) {
                metricsHeader.addEventListener('click', function() {
                    requestAnimationFrame(() => {
                        const visibleAfterClick = metricsCollapse.classList.contains('show');
                        if (visibleAfterClick) {
                            maybeRenderMetricsCharts('header-click');
                        }
                    });
                });
            }

            if (typeof MutationObserver !== 'undefined') {
                const collapseObserver = new MutationObserver(() => {
                    const isVisible = metricsCollapse.classList.contains('show');
                    if (isVisible && !lastMetricsVisible) {
                        maybeRenderMetricsCharts('mutation-visible');
                    }
                    lastMetricsVisible = isVisible;
                });
                collapseObserver.observe(metricsCollapse, { attributes: true, attributeFilter: ['class'] });
            }

            window.addEventListener('pageshow', function(event) {
                if (metricsCollapse.classList.contains('show')) {
                    requestAnimationFrame(() => {
                        maybeRenderMetricsCharts('pageshow-visible');
                    });
                }
            });

            document.addEventListener('visibilitychange', function() {
                if (!document.hidden && metricsCollapse.classList.contains('show')) {
                    requestAnimationFrame(() => {
                        maybeRenderMetricsCharts('visibility-visible');
                    });
                }
            });
        }
    });

    // Verificar status da conexão
    async function checkConnectionStatus() {
        if (!API_BASE) {
            return;
        }

        try {
            const response = await fetch(`${API_BASE}/status`);
            const data = await response.json();
            const connected = data.ml_connection === 'conectado';
            const tokenOk = data.account?.token_status === 'válido';

            // Só alerta quando NÃO está conectado/token inválido (nunca por preview_mode_available)
            if (!connected || !tokenOk) {
                showPreviewBanner(data);
            } else {
                document.getElementById('previewBanner')?.remove();
            }
        } catch (err) {
            console.warn('Não foi possível verificar status da conexão');
        }
    }

    // Mostrar banner de conexão/preview local
    function showPreviewBanner(statusData) {
        const container = document.querySelector('.container-fluid');
        const existingBanner = document.getElementById('previewBanner');
        if (existingBanner) existingBanner.remove();

        const accountInfo = statusData?.account ?
            `Conta: <strong>${statusData.account.nickname}</strong> (${statusData.account.email})` :
            'Nenhuma conta configurada';

        const tokenExpired = statusData?.account?.token_status === 'expirado';
        const refreshTokenInvalid = statusData?.ml_error?.includes('invalid_grant') || statusData?.ml_error?.includes('missing_access_token');

        let actionMessage = '';
        if (refreshTokenInvalid || tokenExpired) {
            actionMessage = `
            <div class="alert alert-danger mt-2 mb-0 py-2">
                <i class="bi bi-exclamation-octagon me-1"></i>
                <strong>Refresh Token Expirado!</strong> O token de atualização do Mercado Livre expirou.
                Para ver seus produtos reais, você precisa <a href="/auth/authorize" class="alert-link fw-bold">reconectar sua conta</a>.
            </div>
        `;
        }

        const banner = document.createElement('div');
        banner.id = 'previewBanner';
        banner.className = 'alert alert-warning alert-dismissible fade show mb-3';
        banner.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-4"></i>
            <div class="flex-grow-1">
                <strong>Conexão Mercado Livre indisponível</strong>
                <div class="small">
                    ${accountInfo} •
                    Token: <span class="badge bg-${statusData?.account?.token_status === 'válido' ? 'success' : 'danger'}">${statusData?.account?.token_status || 'N/A'}</span> •
                    ML: <span class="badge bg-${statusData?.ml_connection === 'conectado' ? 'success' : 'danger'}">${statusData?.ml_connection || 'Desconectado'}</span>
                </div>
                ${actionMessage}
            </div>
            <div class="d-flex flex-column gap-2 ms-3">
                <a href="/auth/authorize" class="btn btn-sm btn-primary">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Reconectar Conta
                </a>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-pricing-cmd="togglePreviewMode">
                    <i class="bi bi-eye me-1"></i> ${previewMode ? 'Tentar API Real' : 'Usar Preview Local'}
                </button>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

        container.insertBefore(banner, container.firstChild);
    }

    // Alternar modo preview local
    function togglePreviewMode() {
        previewMode = !previewMode;
        loadItems(1);
        if (!previewMode) {
            const banner = document.getElementById('previewBanner');
            if (banner) banner.remove();
        }
    }

    // Fallback local: layouts/app.php não carrega dashboard-modern.js (window.ML)
    function mlItemUrl(id) {
        if (window.ML && typeof window.ML.itemUrl === 'function') {
            return window.ML.itemUrl(id);
        }
        const s = String(id || '').trim();
        const m = s.match(/^([A-Z]{3})(\d+)$/);
        const formatted = m ? `${m[1]}-${m[2]}` : s;
        return `https://produto.mercadolivre.com.br/${formatted}`;
    }

    // Carregar itens
    async function loadItems(page = 1) {
        currentPage = page;
        const status = document.getElementById('filterStatus').value;
        const search = document.getElementById('searchInput').value;
        const margemFilter = document.getElementById('filterMargem').value;

        let url = `${API_BASE}/items?page=${page}&limit=20`;
        if (status) url += `&status=${status}`;
        if (search) url += `&q=${encodeURIComponent(search)}`;

        // Filtro de margem / custos
        if (margemFilter === 'critica') url += '&margem_max=5';
        else if (margemFilter === 'baixa') url += '&margem_min=5&margem_max=10';
        else if (margemFilter === 'media') url += '&margem_min=10&margem_max=20';
        else if (margemFilter === 'boa') url += '&margem_min=20';
        else if (margemFilter === 'sem') url += '&custos=sem';
        else if (margemFilter === 'com') url += '&custos=com';

        // Adicionar modo preview local se necessário
        if (previewMode) url += '&preview=true';

        try {
            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                // Se a API retornou preview local explícito, manter estado local
                if (data.preview_mode && !previewMode) {
                    previewMode = true;
                    checkConnectionStatus();
                }

                // Se há aviso da API, exibir
                if (data.aviso && !document.getElementById('previewBanner')) {
                    showApiWarning(data.aviso);
                }

                renderItems(data.items || []);
                updatePagination(data.page || page, Math.ceil((data.total || 0) / 20) || 1, data.total || 0);
                updateStats(data.items || []);
            } else {
                showError('Erro ao carregar itens');
            }
        } catch (err) {
            console.error(err);
            showError('Erro de conexão');
        }
    }

    // Mostrar aviso da API
    function showApiWarning(message) {
        const container = document.querySelector('.container-fluid');
        const existingWarning = document.getElementById('apiWarning');
        if (existingWarning) existingWarning.remove();

        const warning = document.createElement('div');
        warning.id = 'apiWarning';
        warning.className = 'alert alert-info alert-dismissible fade show mb-3';
        warning.innerHTML = `
        <i class="bi bi-info-circle me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
        container.insertBefore(warning, container.firstChild);
    }

    // Renderizar tabela
    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderItems(items) {
        const tbody = document.getElementById('itemsTableBody');

        if (!items || items.length === 0) {
            tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted"></i>
                    <p class="mt-2 mb-0 text-muted">Nenhum anúncio encontrado</p>
                    <p class="small text-muted">
                        ${previewMode ? '' : '<a href="#" data-pricing-cmd="togglePreviewMode" data-pricing-prevent="1">Clique aqui para usar preview local</a>'}
                    </p>
                </td>
            </tr>
        `;
            return;
        }

        tbody.innerHTML = items.map(item => {
            const safeId = escapeHtml(item.id);
            const safeTitle = escapeHtml(item.titulo);
            const safeSku = item.sku ? escapeHtml(item.sku) : '';
            const safeThumb = escapeHtml(item.thumbnail || '/images/no-image.png');
            const safeIndicador = escapeHtml(item.indicador || 'cinza');
            return `
        <tr>
            <td>
                <img src="${safeThumb}"
                     class="rounded" style="width: 50px; height: 50px; object-fit: cover;" alt="">
            </td>
            <td>
                <div class="fw-semibold text-truncate" style="max-width: 300px;" title="${safeTitle}">
                    ${safeTitle}
                </div>
                <small class="text-muted">
                    ${safeId} ${safeSku ? `• SKU: ${safeSku}` : ''}
                </small>
            </td>
            <td class="text-center">
                <span class="fw-bold">R$ ${formatNumber(item.preco)}</span>
            </td>
            <td class="text-center">
                ${item.custos_cadastrados && item.custo != null
                    ? `<span>R$ ${formatNumber(item.custo)}</span>`
                    : '<span class="text-muted">-</span>'
                }
            </td>
            <td class="text-center">
                ${item.margem !== null && item.margem !== undefined
                    ? `<span class="margin-indicator ${safeIndicador}"></span>${formatNumber(item.margem)}%`
                    : '<span class="badge bg-secondary">Configurar</span>'
                }
            </td>
            <td class="text-center">
                ${item.lucro_unitario !== null && item.lucro_unitario !== undefined
                    ? `<span class="${item.lucro_unitario >= 0 ? 'text-success' : 'text-danger'}">
                         R$ ${formatNumber(item.lucro_unitario)}
                       </span>`
                    : '<span class="text-muted">-</span>'
                }
            </td>
            <td class="text-center">
                <span class="badge bg-light text-dark">${item.vendidos || 0}</span>
            </td>
            <td class="text-center text-nowrap" data-pricing-col="actions">
                <button type="button" class="btn btn-sm btn-primary" data-action="precificar" data-item-id="${safeId}" title="Precificar" aria-label="Precificar">
                    <i class="bi bi-calculator" aria-hidden="true"></i>
                    <span class="ms-1">Precificar</span>
                </button>
                <a href="${mlItemUrl(item.id)}" target="_blank" rel="noopener noreferrer"
                   class="btn btn-sm btn-outline-secondary" title="Ver no ML" aria-label="Ver no Mercado Livre">
                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                    <span class="ms-1">ML</span>
                </a>
            </td>
        </tr>
    `;
        }).join('');
    }

    // Abrir modal do precificador
    async function abrirPrecificador(itemId) {
        currentItemId = itemId;

        // Mostrar modal com loading
        const modal = new bootstrap.Modal(document.getElementById('pricingModal'));
        modal.show();

        try {
            // Buscar dados do item e custos
            const [costsRes] = await Promise.all([
                fetch(`${API_BASE}/costs/${itemId}`).then(r => r.json())
            ]);

            if (costsRes.success) {
                currentItemData = costsRes.item_info;
                currentCosts = costsRes.custos;

                // Preencher dados do modal
                document.getElementById('modalItemId').textContent = itemId;
                document.getElementById('modalItemTitle').textContent = currentItemData?.titulo || itemId;
                document.getElementById('modalItemImage').src = currentItemData?.thumbnail || '/images/no-image.png';
                document.getElementById('modalCurrentPrice').textContent = `R$ ${formatNumber(currentItemData?.preco || 0)}`;

                // Preencher custos se existirem
                if (currentCosts) {
                    document.getElementById('costProducao').value = currentCosts.custo_producao || '';
                    document.getElementById('costEmbalagem').value = currentCosts.custo_embalagem || 0;
                    document.getElementById('costFreteGratis').value = currentCosts.custo_frete_gratis || 0;
                    document.getElementById('taxaComissao').value = currentCosts.taxa_comissao_ml || 16;
                    document.getElementById('taxaImposto').value = currentCosts.taxa_imposto || 9;
                    document.getElementById('taxaAcos').value = currentCosts.acos_medio || 0;
                }

                // Setar preço atual no input
                document.getElementById('newPriceInput').value = currentItemData?.preco || '';

                // Calcular simulação inicial
                calcularSimulacao();

                // Buscar concorrência (async)
                loadCompetitorData(currentItemData?.categoria);
            }
        } catch (err) {
            console.error(err);
            showToast('Erro ao carregar dados', 'danger');
        }
    }
    // Expor para handlers (CSP bloqueia onclick inline)
    window.abrirPrecificador = abrirPrecificador;
    window.calcularSimulacao = calcularSimulacao;

    // Calcular simulação em tempo real
    async function calcularSimulacao() {
        const novoPreco = parseFloat(document.getElementById('newPriceInput').value) || 0;
        const precoAtual = currentItemData?.preco || 0;

        const custos = {
            preco_venda: novoPreco,
            custo_producao: parseFloat(document.getElementById('costProducao').value) || 0,
            custo_embalagem: parseFloat(document.getElementById('costEmbalagem').value) || 0,
            custo_frete_gratis: parseFloat(document.getElementById('costFreteGratis').value) || 0,
            taxa_comissao_ml: parseFloat(document.getElementById('taxaComissao').value) || 16,
            taxa_imposto: parseFloat(document.getElementById('taxaImposto').value) || 9,
            acos_medio: parseFloat(document.getElementById('taxaAcos').value) || 0
        };

        if (novoPreco <= 0) return;

        try {
            const response = await fetch(`${API_BASE}/margin/calculate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(custos)
            });
            const data = await response.json();

            if (data.success) {
                // Atualizar breakdown
                const bk = data.breakdown;
                document.getElementById('bkPreco').textContent = `R$ ${formatNumber(novoPreco)}`;
                document.getElementById('bkComissao').textContent = `-R$ ${formatNumber(bk.custos_variaveis.comissao_ml)}`;
                document.getElementById('bkComissaoPercent').textContent = `(${bk.custos_variaveis.comissao_ml_percent}%)`;
                document.getElementById('bkImposto').textContent = `-R$ ${formatNumber(bk.custos_variaveis.imposto)}`;
                document.getElementById('bkImpostoPercent').textContent = `(${bk.custos_variaveis.imposto_percent}%)`;
                document.getElementById('bkAds').textContent = `-R$ ${formatNumber(bk.custos_variaveis.ads)}`;
                document.getElementById('bkAdsPercent').textContent = `(${bk.custos_variaveis.ads_percent}%)`;
                document.getElementById('bkCusto').textContent = `-R$ ${formatNumber(bk.custos_fixos.producao)}`;
                document.getElementById('bkFrete').textContent = `-R$ ${formatNumber(bk.custos_fixos.frete_gratis)}`;
                document.getElementById('bkLucro').textContent = `R$ ${formatNumber(data.lucro_unitario)}`;
                document.getElementById('bkLucro').className = `text-end fw-bold ${data.lucro_unitario >= 0 ? 'text-success' : 'text-danger'}`;
                document.getElementById('bkMargem').textContent = `${formatNumber(data.margem_real)}%`;
                document.getElementById('bkMargem').className = `text-end fw-bold text-${data.indicador === 'verde' ? 'success' : data.indicador === 'amarelo' ? 'warning' : 'danger'}`;

                // Margem atual no header
                document.getElementById('modalCurrentMargin').textContent = `${formatNumber(data.margem_real)}%`;
                document.getElementById('modalCurrentMargin').className = `fw-bold fs-4 text-${data.indicador === 'verde' ? 'success' : data.indicador === 'amarelo' ? 'warning' : 'danger'}`;

                // Variação de preço
                if (precoAtual > 0) {
                    const variacao = ((novoPreco - precoAtual) / precoAtual) * 100;
                    document.getElementById('priceChangePercent').innerHTML = variacao !== 0 ?
                        `<span class="${variacao > 0 ? 'text-danger' : 'text-success'}">${variacao > 0 ? '+' : ''}${formatNumber(variacao)}% em relação ao preço atual</span>` :
                        '';

                    // Verificar impacto no ranking
                    checkRankingImpact(precoAtual, novoPreco);
                }

                // Gerar cenários de desconto
                generateDiscountScenarios(novoPreco, custos);
            }
        } catch (err) {
            console.error(err);
        }
    }

    // Verificar impacto no ranking
    async function checkRankingImpact(precoAtual, precoNovo) {
        try {
            const response = await fetch(`${API_BASE}/ranking-impact`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    preco_atual: precoAtual,
                    preco_novo: precoNovo
                })
            });
            const data = await response.json();

            const alertEl = document.getElementById('rankingAlert');
            const alertText = document.getElementById('rankingAlertText');

            if (data.alerta !== 'verde') {
                alertEl.classList.remove('d-none', 'alert-success', 'alert-warning', 'alert-danger');
                alertEl.classList.add(`alert-${data.alerta === 'amarelo' ? 'warning' : 'danger'}`);
                alertText.textContent = data.mensagem;
            } else {
                alertEl.classList.add('d-none');
            }
        } catch (err) {
            console.error(err);
        }
    }

    // Gerar cenários de desconto
    async function generateDiscountScenarios(precoBase, custos) {
        try {
            const response = await fetch(`${API_BASE}/simulate-discount`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    preco_original: precoBase,
                    desconto_percent: 10,
                    ...custos
                })
            });
            const data = await response.json();

            if (data.cenarios) {
                const container = document.getElementById('discountScenarios');
                container.innerHTML = data.cenarios.map(c => `
                <span class="badge border discount-badge ${c.viavel ? 'viable text-success' : 'not-viable text-danger'}"
                      data-pricing-cmd="aplicarDesconto" data-pricing-arg0="${c.preco}" title="Margem: ${formatNumber(c.margem)}%">
                    -${c.desconto}%<br>
                    <small>R$ ${formatNumber(c.preco)}</small>
                </span>
            `).join('');
            }
        } catch (err) {
            console.error(err);
        }
    }

    function aplicarDesconto(preco) {
        document.getElementById('newPriceInput').value = preco.toFixed(2);
        calcularSimulacao();
    }
    window.aplicarDesconto = aplicarDesconto;

    // Salvar custos
    async function salvarCustos() {
        const custos = {
            custo_producao: parseFloat(document.getElementById('costProducao').value) || 0,
            custo_embalagem: parseFloat(document.getElementById('costEmbalagem').value) || 0,
            custo_frete_gratis: parseFloat(document.getElementById('costFreteGratis').value) || 0,
            taxa_comissao_ml: parseFloat(document.getElementById('taxaComissao').value) || 16,
            taxa_imposto: parseFloat(document.getElementById('taxaImposto').value) || 9,
            acos_medio: parseFloat(document.getElementById('taxaAcos').value) || 0
        };

        try {
            const response = await fetch(`${API_BASE}/costs/${currentItemId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(custos)
            });
            const data = await response.json();

            if (data.success) {
                showToast('Custos salvos com sucesso!', 'success');
                currentCosts = custos;
            } else {
                showToast('Erro ao salvar custos', 'danger');
            }
        } catch (err) {
            showToast('Erro de conexão', 'danger');
        }
    }

    // Aplicar preço no ML
    async function aplicarPreco() {
        const novoPreco = parseFloat(document.getElementById('newPriceInput').value);

        if (!novoPreco || novoPreco <= 0) {
            showToast('Informe um preço válido', 'warning');
            return;
        }

        const btn = document.getElementById('btnAplicarPreco');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Aplicando...';

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const pricingHeaders = {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            };
            const response = await fetch(`${API_BASE}/apply/${currentItemId}`, {
                method: 'POST',
                headers: pricingHeaders,
                body: JSON.stringify({
                    novo_preco: novoPreco,
                    motivo: 'Ajuste via Precificador Inteligente'
                })
            });
            const data = await response.json();

            if (data.success) {
                showToast(`Preço atualizado para R$ ${formatNumber(novoPreco)}`, 'success');
                bootstrap.Modal.getInstance(document.getElementById('pricingModal')).hide();
                loadItems(currentPage);
            } else if (data.warning) {
                if (confirm(`${data.message}\n\nDeseja aplicar mesmo assim?`)) {
                    // Forçar aplicação (exige confirm_risk no backend)
                    const forceResponse = await fetch(`${API_BASE}/apply/${currentItemId}`, {
                        method: 'POST',
                        headers: pricingHeaders,
                        body: JSON.stringify({
                            novo_preco: novoPreco,
                            force: true,
                            confirm_risk: true
                        })
                    });
                    const forceData = await forceResponse.json();

                    if (forceData.success) {
                        showToast('Preço atualizado!', 'success');
                        bootstrap.Modal.getInstance(document.getElementById('pricingModal')).hide();
                        loadItems(currentPage);
                    }
                }
            } else {
                showToast(data.error || 'Erro ao aplicar preço', 'danger');
            }
        } catch (err) {
            showToast('Erro de conexão', 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Aplicar Preço no ML';
        }
    }
    window.salvarCustos = salvarCustos;
    window.aplicarPreco = aplicarPreco;

    // Carregar dados de concorrência
    async function loadCompetitorData(categoryId) {
        if (!categoryId) return;

        try {
            const response = await fetch(`${API_BASE}/competitors/${categoryId}`);
            const data = await response.json();

            if (data.price_stats) {
                document.getElementById('compMin').textContent = `R$ ${formatNumber(data.price_stats.min)}`;
                document.getElementById('compAvg').textContent = `R$ ${formatNumber(data.price_stats.average)}`;
                document.getElementById('compPosition').textContent = `${data.price_stats.count} concorrentes`;
            }
        } catch (err) {
            console.error(err);
        }
    }

    // Dashboard stats
    async function loadDashboardStats() {
        try {
            const response = await fetch(`${API_BASE}/dashboard`);
            const data = await response.json();

            if (data.success && data.estatisticas) {
                const stats = data.estatisticas;
                const dist = stats.distribuicao || {};
                document.getElementById('alertVermelho').textContent = dist.critica || 0;
                document.getElementById('alertAmarelo').textContent = dist.baixa || 0;
                document.getElementById('alertVerde').textContent = dist.boa || 0;
                if (dist.sem_custos != null) {
                    document.getElementById('alertSemCusto').textContent = dist.sem_custos;
                }
            }
        } catch (err) {
            console.error(err);
        }
    }

    // Métricas avançadas com gráficos
    let marginChart = null;
    let trendChart = null;
    let marketTrendChart = null;
    let latestAdvancedMetrics = null;

    async function loadAdvancedMetrics() {
        try {
            const response = await fetch(`${API_BASE}/metrics`);
            const data = await response.json();

            if (data.success) {
                // Atualizar cards de métricas
                document.getElementById('metricMargemMedia').textContent =
                    formatNumber(data.margens?.media || 0) + '%';
                document.getElementById('metricLucroTotal').textContent =
                    'R$ ' + formatNumber(data.lucro_potencial_mensal || 0);
                document.getElementById('metricAlteracoes').textContent =
                    data.alteracoes_7_dias || 0;
                document.getElementById('metricAlertas').textContent =
                    data.alertas_pendentes || 0;

                latestAdvancedMetrics = data;
                renderAdvancedMetricsCharts();
            }
        } catch (err) {
            console.error('Erro ao carregar métricas:', err);
        }
    }

    function renderAdvancedMetricsCharts(force = false) {
        const metricsCollapse = document.getElementById('metricsCollapse');
        const isVisible = !!(metricsCollapse && metricsCollapse.classList.contains('show'));
        if (!latestAdvancedMetrics) return;
        if (!force && !isVisible) {
            return;
        }
        renderMarginDistributionChart(latestAdvancedMetrics.distribuicao);
        renderMetricsTrendChart(latestAdvancedMetrics.tendencia_7_dias);
        if (force) {
        }
    }

    function renderMarginDistributionChart(distribuicao) {
        const ctx = document.getElementById('marginDistributionChart');
        if (!ctx) return;

        if (marginChart) marginChart.destroy();

        const data = {
            labels: ['Crítica (<5%)', 'Baixa (5-10%)', 'Média (10-20%)', 'Boa (>20%)', 'Sem custos'],
            datasets: [{
                data: [
                    distribuicao?.critica || 0,
                    distribuicao?.baixa || 0,
                    distribuicao?.media || 0,
                    distribuicao?.boa || 0,
                    distribuicao?.sem_custos || 0
                ],
                backgroundColor: [
                    '#dc3545',
                    '#ffc107',
                    '#17a2b8',
                    '#28a745',
                    '#6c757d'
                ],
                borderWidth: 0
            }]
        };

        marginChart = new Chart(ctx, {
            type: 'doughnut',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    }

    function renderMetricsTrendChart(tendencia) {
        const ctx = document.getElementById('priceTrendChart');
        if (!ctx) return;

        if (trendChart) trendChart.destroy();

        // Se não houver dados, usar dados padrão
        const labels = tendencia?.map(t => t.data) || ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
        const alteracoes = tendencia?.map(t => t.alteracoes) || [0, 0, 0, 0, 0, 0, 0];
        const aumentos = tendencia?.map(t => t.aumentos) || [0, 0, 0, 0, 0, 0, 0];
        const reducoes = tendencia?.map(t => t.reducoes) || [0, 0, 0, 0, 0, 0, 0];

        trendChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                        label: 'Aumentos',
                        data: aumentos,
                        backgroundColor: '#28a745',
                        stack: 'Stack 0'
                    },
                    {
                        label: 'Reduções',
                        data: reducoes,
                        backgroundColor: '#dc3545',
                        stack: 'Stack 0'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            font: {
                                size: 11
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }

    function updateStats(_items) {
        // Cards de resumo vêm de /dashboard (conta inteira); não sobrescrever com a página atual.
    }

    // Paginação
    function updatePagination(current, total, totalItems) {
        totalPages = total;
        document.getElementById('paginationInfo').textContent =
            `Mostrando ${((current-1)*20)+1}-${Math.min(current*20, totalItems)} de ${totalItems} itens`;

        const pagination = document.getElementById('pagination');
        let html = '';

        if (current > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" data-pricing-cmd="loadItems" data-pricing-arg0="${current-1}">«</a></li>`;
        }

        for (let i = Math.max(1, current - 2); i <= Math.min(total, current + 2); i++) {
            html += `<li class="page-item ${i === current ? 'active' : ''}">
            <a class="page-link" href="#" data-pricing-cmd="loadItems" data-pricing-arg0="${i}">${i}</a>
        </li>`;
        }

        if (current < total) {
            html += `<li class="page-item"><a class="page-link" href="#" data-pricing-cmd="loadItems" data-pricing-arg0="${current+1}">»</a></li>`;
        }

        pagination.innerHTML = html;
    }

    // Helpers
    function formatNumber(num) {
        return parseFloat(num || 0).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function aplicarFiltros() {
        loadItems(1);
    }

    function limparFiltros() {
        document.getElementById('searchInput').value = '';
        document.getElementById('filterStatus').value = 'active';
        document.getElementById('filterMargem').value = '';
        loadItems(1);
    }

    function refreshItems() {
        loadItems(currentPage);
    }

    // Toast Notifications
    function showToast(message, type = 'info') {
        const toastContainer = document.getElementById('toastContainer');
        const toastId = 'toast-' + Date.now();

        const icons = {
            success: 'bi-check-circle-fill',
            danger: 'bi-exclamation-triangle-fill',
            warning: 'bi-exclamation-circle-fill',
            info: 'bi-info-circle-fill'
        };

        const toastHtml = `
        <div id="${toastId}" class="toast align-items-center text-bg-${type} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi ${icons[type] || icons.info} me-2"></i>
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;

        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        const toastEl = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastEl, {
            delay: 4000
        });
        toast.show();

        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }

    function showError(message) {
        document.getElementById('itemsTableBody').innerHTML = `
        <tr>
            <td colspan="8" class="text-center py-5 text-danger">
                <i class="bi bi-exclamation-triangle fs-1"></i>
                <p class="mt-2 mb-0">${message}</p>
            </td>
        </tr>
    `;
    }

    function exportarDados() {
        // Usar nova rota de exportação
        window.location.href = `${API_BASE}/export/csv`;
    }

    // Exportar histórico de preços
    function exportarHistorico() {
        const itemId = document.getElementById('filterItemId')?.value || '';
        const dataInicio = document.getElementById('filterDataInicio')?.value || '';
        const dataFim = document.getElementById('filterDataFim')?.value || '';

        let url = `${API_BASE}/export/history`;
        const params = new URLSearchParams();
        if (itemId) params.append('item_id', itemId);
        if (dataInicio) params.append('data_inicio', dataInicio);
        if (dataFim) params.append('data_fim', dataFim);

        if (params.toString()) url += '?' + params.toString();
        window.location.href = url;
    }

    async function processarImportacao() {
        const data = document.getElementById('importCostsData').value;
        const lines = data.trim().split('\n');
        const items = [];

        lines.forEach(line => {
            const [item_id, sku, custo] = line.split(',').map(s => s.trim());
            if (item_id && custo) {
                items.push({
                    item_id,
                    sku,
                    custo_producao: parseFloat(custo)
                });
            }
        });

        if (items.length === 0) {
            showToast('Nenhum item válido encontrado', 'warning');
            return;
        }

        try {
            const response = await fetch(`${API_BASE}/bulk-costs`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    items
                })
            });
            const result = await response.json();

            showToast(`Importados: ${result.sucesso} | Falhas: ${result.falhas}`, result.falhas > 0 ? 'warning' : 'success');
            bootstrap.Modal.getInstance(document.getElementById('importCostsModal')).hide();
            loadItems(currentPage);
        } catch (err) {
            showToast('Erro na importação', 'danger');
        }
    }

    // =========================================
    // AÇÕES EM LOTE
    // =========================================
    async function executarBulkRules() {
        const textareaValue = document.getElementById('bulkRuleItemIds').value;
        const simulate = document.getElementById('bulkRuleSimulate').checked;

        // Parsear IDs (suporta vírgula, quebra de linha, ou espaço)
        const itemIds = textareaValue.split(/[,\n\s]+/)
            .map(id => id.trim())
            .filter(id => id.length > 0);

        if (itemIds.length === 0) {
            showToast('Informe pelo menos um ID de item', 'warning');
            return;
        }

        const resultDiv = document.getElementById('bulkRulesResult');
        resultDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div> Processando...';

        try {
            const response = await fetch(`${API_BASE}/bulk/apply-rules`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    item_ids: itemIds,
                    simulate
                })
            });
            const data = await response.json();

            if (data.success) {
                let html = `<div class="alert alert-success">
                <strong>${simulate ? 'Simulação concluída!' : 'Regras aplicadas!'}</strong><br>
                Sucesso: ${data.success_count} | Falhas: ${data.error_count}
            </div>`;

                if (data.results && data.results.length > 0) {
                    html += '<div class="table-responsive"><table class="table table-sm table-bordered">';
                    html += '<thead><tr><th>Item ID</th><th>Status</th><th>Resultado</th></tr></thead><tbody>';
                    data.results.forEach(r => {
                        const statusClass = r.success ? 'text-success' : 'text-danger';
                        html += `<tr>
                        <td>${r.item_id}</td>
                        <td><span class="${statusClass}">${r.success ? '✓' : '✗'}</span></td>
                        <td class="small">${r.message || r.error || '-'}</td>
                    </tr>`;
                    });
                    html += '</tbody></table></div>';
                }

                resultDiv.innerHTML = html;

                if (!simulate) {
                    loadItems(currentPage);
                }
            } else {
                resultDiv.innerHTML = `<div class="alert alert-danger">${data.error || 'Erro ao processar'}</div>`;
            }
        } catch (err) {
            resultDiv.innerHTML = `<div class="alert alert-danger">Erro de conexão</div>`;
        }
    }

    async function executarBulkCosts() {
        const field = document.getElementById('bulkCostField').value;
        const value = parseFloat(document.getElementById('bulkCostValue').value);
        const textareaValue = document.getElementById('bulkCostItemIds').value;

        const itemIds = textareaValue.split(/[,\n\s]+/)
            .map(id => id.trim())
            .filter(id => id.length > 0);

        if (itemIds.length === 0) {
            showToast('Informe pelo menos um ID de item', 'warning');
            return;
        }

        if (isNaN(value)) {
            showToast('Informe um valor válido', 'warning');
            return;
        }

        const resultDiv = document.getElementById('bulkCostsResult');
        resultDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div> Processando...';

        try {
            const response = await fetch(`${API_BASE}/bulk/update-costs`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    item_ids: itemIds,
                    field,
                    value
                })
            });
            const data = await response.json();

            if (data.success) {
                resultDiv.innerHTML = `<div class="alert alert-success">
                <strong>Custos atualizados!</strong><br>
                Sucesso: ${data.success_count} | Falhas: ${data.error_count}
            </div>`;
                loadItems(currentPage);
            } else {
                resultDiv.innerHTML = `<div class="alert alert-danger">${data.error || 'Erro ao processar'}</div>`;
            }
        } catch (err) {
            resultDiv.innerHTML = `<div class="alert alert-danger">Erro de conexão</div>`;
        }
    }

    // =========================================
    // SIMULADOR DE PROMOÇÕES
    // =========================================
    let priceHistoryChart = null;

    function updatePromoPreview() {
        const desconto = parseInt(document.getElementById('promoDesconto').value);
        const preco = currentItemData?.preco || 0;
        const precoFinal = preco * (1 - desconto / 100);

        document.getElementById('promoDescontoValue').textContent = desconto + '%';
        document.getElementById('promoPrecoOriginal').textContent = `R$ ${formatNumber(preco)}`;
        document.getElementById('promoPrecoFinal').textContent = `R$ ${formatNumber(precoFinal)}`;

        // Calcular margem estimada
        const custos = getCurrentCosts();
        const custoTotal = custos.custo_producao + custos.custo_embalagem + custos.custo_frete_gratis;
        const taxas = precoFinal * ((custos.taxa_comissao_ml + custos.taxa_imposto + custos.acos_medio) / 100);
        const lucro = precoFinal - custoTotal - taxas;
        const margem = preco > 0 ? (lucro / precoFinal) * 100 : 0;

        document.getElementById('promoMargem').textContent = `${formatNumber(margem)}%`;
        document.getElementById('promoMargem').className = `fs-5 fw-bold ${margem >= 10 ? 'text-success' : margem >= 5 ? 'text-warning' : 'text-danger'}`;

        // Alerta
        const alertEl = document.getElementById('promoAlerta');
        if (margem < 5) {
            alertEl.classList.remove('d-none', 'alert-warning', 'alert-success');
            alertEl.classList.add('alert-danger');
            document.getElementById('promoAlertaText').textContent = 'Desconto inviável! Margem abaixo do mínimo seguro.';
        } else if (margem < 10) {
            alertEl.classList.remove('d-none', 'alert-danger', 'alert-success');
            alertEl.classList.add('alert-warning');
            document.getElementById('promoAlertaText').textContent = 'Atenção: margem baixa. Considere um desconto menor.';
        } else {
            alertEl.classList.add('d-none');
        }
    }

    function getCurrentCosts() {
        return {
            custo_producao: parseFloat(document.getElementById('costProducao').value) || 0,
            custo_embalagem: parseFloat(document.getElementById('costEmbalagem').value) || 0,
            custo_frete_gratis: parseFloat(document.getElementById('costFreteGratis').value) || 0,
            taxa_comissao_ml: parseFloat(document.getElementById('taxaComissao').value) || 16,
            taxa_imposto: parseFloat(document.getElementById('taxaImposto').value) || 9,
            acos_medio: parseFloat(document.getElementById('taxaAcos').value) || 0
        };
    }

    async function simularPromocao() {
        if (!currentItemId || !currentItemData) {
            showToast('Selecione um produto primeiro', 'warning');
            return;
        }

        const desconto = parseInt(document.getElementById('promoDesconto').value);
        const custos = getCurrentCosts();

        try {
            const response = await fetch(`${API_BASE}/promotion/simulate/${currentItemId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    desconto: desconto,
                    custos: custos
                })
            });
            const data = await response.json();

            if (data.success) {
                // Atualizar cenários
                renderPromoCenarios(data.cenarios || []);
                showToast('Simulação concluída!', 'success');
            } else {
                showToast(data.error || 'Erro na simulação', 'danger');
            }
        } catch (err) {
            console.error(err);
            showToast('Erro de conexão', 'danger');
        }
    }

    function renderPromoCenarios(cenarios) {
        const tbody = document.getElementById('promoCenariosBody');

        if (!cenarios.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Nenhum cenário gerado</td></tr>';
            return;
        }

        tbody.innerHTML = cenarios.map(c => `
        <tr class="${c.viavel ? '' : 'table-danger'}">
            <td><span class="badge bg-danger">-${c.desconto}%</span></td>
            <td>R$ ${formatNumber(c.preco_final)}</td>
            <td class="${c.margem >= 10 ? 'text-success' : c.margem >= 5 ? 'text-warning' : 'text-danger'} fw-bold">
                ${formatNumber(c.margem)}%
            </td>
            <td>
                ${c.viavel
                    ? '<span class="badge bg-success">Viável</span>'
                    : '<span class="badge bg-danger">Inviável</span>'}
            </td>
        </tr>
    `).join('');
    }

    async function simularCentralOfertas() {
        if (!currentItemId || !currentItemData) {
            showToast('Selecione um produto primeiro', 'warning');
            return;
        }

        const custos = getCurrentCosts();

        try {
            const response = await fetch(`${API_BASE}/promotion/central-ofertas/${currentItemId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    custos: custos
                })
            });
            const data = await response.json();

            if (data.success) {
                const result = document.getElementById('centralOfertasResult');
                const content = document.getElementById('centralOfertasContent');

                result.classList.remove('d-none');
                content.innerHTML = `
                <div class="mb-2">
                    <strong>Desconto Recomendado:</strong>
                    <span class="badge bg-warning text-dark">${data.desconto_recomendado}%</span>
                </div>
                <div class="mb-2">
                    <strong>Preço Promocional:</strong> R$ ${formatNumber(data.preco_promocional)}
                </div>
                <div class="mb-2">
                    <strong>Margem na Promoção:</strong>
                    <span class="${data.margem_promocao >= 10 ? 'text-success' : data.margem_promocao >= 5 ? 'text-warning' : 'text-danger'}">
                        ${formatNumber(data.margem_promocao)}%
                    </span>
                </div>
                <div>
                    <strong>Vendas Projetadas:</strong> ${data.vendas_projetadas || 'N/A'}
                </div>
                ${data.alerta ? `<div class="alert alert-warning mt-2 mb-0 py-1">${data.alerta}</div>` : ''}
            `;

                showToast('Simulação Central de Ofertas concluída!', 'success');
            } else {
                showToast(data.error || 'Erro na simulação', 'danger');
            }
        } catch (err) {
            console.error(err);
            showToast('Erro de conexão', 'danger');
        }
    }

    // =========================================
    // HISTÓRICO DE PREÇOS
    // =========================================
    async function loadPriceHistory() {
        if (!currentItemId) return;

        try {
            // Carregar histórico e tendência em paralelo
            const [historyRes, trendsRes] = await Promise.all([
                fetch(`${API_BASE}/history/${currentItemId}?dias=30`).then(r => r.json()),
                fetch(`${API_BASE}/trends/${currentItemId}?days=30`).then(r => r.json()).catch(() => null)
            ]);

            if (historyRes.success && historyRes.historico) {
                renderPriceChart(historyRes.historico);
                renderPriceHistoryList(historyRes.historico);
            }

            // Atualizar análise de tendência
            if (trendsRes && trendsRes.success) {
                updateTrendAnalysis(trendsRes);
            }
        } catch (err) {
            console.error(err);
        }
    }

    function updateTrendAnalysis(data) {
        // Direção da tendência
        const trendDirection = document.getElementById('trendDirection');
        if (data.tendencia) {
            const icons = {
                'alta': '<i class="bi bi-arrow-up-circle text-success"></i> Alta',
                'baixa': '<i class="bi bi-arrow-down-circle text-danger"></i> Baixa',
                'estavel': '<i class="bi bi-dash-circle text-secondary"></i> Estável'
            };
            trendDirection.innerHTML = icons[data.tendencia] || data.tendencia;
        }

        // Volatilidade
        const volatility = document.getElementById('trendVolatility');
        if (data.volatilidade !== undefined) {
            const vol = parseFloat(data.volatilidade);
            let volClass = vol < 5 ? 'text-success' : vol < 15 ? 'text-warning' : 'text-danger';
            let volLabel = vol < 5 ? 'Baixa' : vol < 15 ? 'Média' : 'Alta';
            volatility.innerHTML = `<span class="${volClass}">${formatNumber(vol)}%</span> <small>(${volLabel})</small>`;
        }

        // Preço mínimo e máximo
        if (data.preco_minimo !== undefined) {
            document.getElementById('trendPriceMin').innerHTML = `R$ ${formatNumber(data.preco_minimo)}`;
        }
        if (data.preco_maximo !== undefined) {
            document.getElementById('trendPriceMax').innerHTML = `R$ ${formatNumber(data.preco_maximo)}`;
        }
    }

    function renderPriceChart(historico) {
        const ctx = document.getElementById('priceHistoryChart');
        if (!ctx) return;

        // Destruir gráfico anterior se existir
        if (priceHistoryChart) {
            priceHistoryChart.destroy();
        }

        const labels = historico.map(h => {
            const date = new Date(h.data);
            return date.toLocaleDateString('pt-BR', {
                day: '2-digit',
                month: '2-digit'
            });
        });

        const precos = historico.map(h => h.preco);

        priceHistoryChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Preço (R$)',
                    data: precos,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function(value) {
                                return 'R$ ' + value.toFixed(2);
                            }
                        }
                    }
                }
            }
        });
    }

    function renderPriceHistoryList(historico) {
        const container = document.getElementById('priceHistoryList');

        if (!historico.length) {
            container.innerHTML = '<div class="text-center text-muted py-3">Sem histórico disponível</div>';
            return;
        }

        container.innerHTML = historico.slice(0, 10).map(h => {
            const date = new Date(h.data);
            const variacao = h.variacao || 0;
            const varClass = variacao > 0 ? 'text-success' : variacao < 0 ? 'text-danger' : 'text-muted';
            const varIcon = variacao > 0 ? 'bi-arrow-up' : variacao < 0 ? 'bi-arrow-down' : 'bi-dash';

            return `
            <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                <div>
                    <div class="fw-bold">R$ ${formatNumber(h.preco)}</div>
                    <small class="text-muted">${date.toLocaleDateString('pt-BR')}</small>
                </div>
                <span class="${varClass}">
                    <i class="bi ${varIcon}"></i> ${formatNumber(Math.abs(variacao))}%
                </span>
            </div>
        `;
        }).join('');
    }

    // =========================================
    // REGRAS AUTOMÁTICAS
    // =========================================
    async function criarRegraAutomatica() {
        const nome = document.getElementById('regraNome').value.trim();
        const tipo = document.getElementById('regraTipo').value;
        const valor = parseFloat(document.getElementById('regraValor').value);
        const ativa = document.getElementById('regraAtiva').checked;

        if (!nome) {
            showToast('Informe um nome para a regra', 'warning');
            return;
        }

        if (isNaN(valor)) {
            showToast('Informe um valor válido', 'warning');
            return;
        }

        try {
            const response = await fetch(`${API_BASE}/rules`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    nome,
                    tipo,
                    valor,
                    ativa,
                    item_id: currentItemId || null
                })
            });
            const data = await response.json();

            if (data.success) {
                showToast('Regra criada com sucesso!', 'success');
                loadRegrasAtivas();

                // Limpar form
                document.getElementById('regraNome').value = '';
                document.getElementById('regraValor').value = '';
            } else {
                showToast(data.error || 'Erro ao criar regra', 'danger');
            }
        } catch (err) {
            console.error(err);
            showToast('Erro de conexão', 'danger');
        }
    }

    window.updatePromoPreview = updatePromoPreview;
    window.simularPromocao = simularPromocao;
    window.simularCentralOfertas = simularCentralOfertas;
    window.criarRegraAutomatica = criarRegraAutomatica;

    async function loadRegrasAtivas() {
        try {
            const response = await fetch(`${API_BASE}/rules`);
            const data = await response.json();

            if (data.success && data.regras) {
                renderRegras(data.regras);
            }
        } catch (err) {
            console.error(err);
        }
    }

    function renderRegras(regras) {
        const container = document.getElementById('regrasAtivas');

        if (!regras.length) {
            container.innerHTML = '<div class="text-center text-muted py-3">Nenhuma regra configurada</div>';
            return;
        }

        container.innerHTML = regras.map(r => `
        <div class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-bold">${r.nome}</div>
                <small class="text-muted">${getTipoRegraLabel(r.tipo)}: ${r.valor}%</small>
            </div>
            <div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox"
                           ${r.ativa ? 'checked' : ''}
                           data-pricing-change="toggleRegra" data-pricing-args-raw="${r.id}, this.checked">
                </div>
            </div>
        </div>
    `).join('');
    }

    function getTipoRegraLabel(tipo) {
        const labels = {
            margem_minima: 'Margem mínima',
            acompanhar_concorrencia: 'Acompanhar concorrência',
            preco_maximo: 'Preço máximo',
            margem_alvo: 'Margem alvo'
        };
        return labels[tipo] || tipo;
    }

    async function toggleRegra(id, ativa) {
        try {
            const response = await fetch(`${API_BASE}/rules/${id}/toggle`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    ativa
                })
            });
            const data = await response.json();

            if (data.success) {
                showToast(ativa ? 'Regra ativada' : 'Regra desativada', 'success');
            }
        } catch (err) {
            console.error(err);
            showToast('Erro ao atualizar regra', 'danger');
        }
    }

    // =========================================
    // CONFIGURAÇÕES
    // =========================================

    // Carregar configurações salvas do localStorage
    function loadSavedSettings() {
        const defaults = {
            defaultComissao: 16,
            defaultImposto: 9,
            defaultAcos: 5,
            defaultEmbalagem: 3,
            defaultEtiqueta: 0.5,
            defaultFreteGratis: 15,
            defaultMargemMinima: 5,
            defaultMargemAlvo: 15,
            alertaCritica: 5,
            alertaBaixa: 10,
            alertaBoa: 20,
            notifyBrowser: true,
            notifySound: true
        };

        Object.keys(defaults).forEach(key => {
            const saved = localStorage.getItem(`pricing_${key}`);
            const el = document.getElementById(key);
            if (el) {
                if (el.type === 'checkbox') {
                    el.checked = saved !== null ? saved === 'true' : defaults[key];
                } else {
                    el.value = saved !== null ? saved : defaults[key];
                }
            }
        });
    }

    // Salvar custos padrão
    function salvarCustosPadrao() {
        const fields = ['defaultComissao', 'defaultImposto', 'defaultAcos', 'defaultEmbalagem',
            'defaultEtiqueta', 'defaultFreteGratis', 'defaultMargemMinima', 'defaultMargemAlvo'
        ];

        fields.forEach(field => {
            const el = document.getElementById(field);
            if (el) localStorage.setItem(`pricing_${field}`, el.value);
        });

        showToast('Configurações de custos salvas!', 'success');
    }

    // Salvar configuração de alertas
    function salvarConfiguracaoAlertas() {
        const fields = ['alertaCritica', 'alertaBaixa', 'alertaBoa'];
        const checkboxes = ['notifyBrowser', 'notifySound'];

        fields.forEach(field => {
            const el = document.getElementById(field);
            if (el) localStorage.setItem(`pricing_${field}`, el.value);
        });

        checkboxes.forEach(field => {
            const el = document.getElementById(field);
            if (el) localStorage.setItem(`pricing_${field}`, el.checked);
        });

        // Solicitar permissão de notificação se ativado
        if (document.getElementById('notifyBrowser').checked && Notification.permission === 'default') {
            Notification.requestPermission();
        }

        showToast('Configurações de alertas salvas!', 'success');
    }

    // Verificar conexão ML
    async function verificarConexaoML() {
        const statusDiv = document.getElementById('connectionStatus');
        statusDiv.innerHTML = `
        <div class="d-flex align-items-center p-3 border rounded">
            <div class="spinner-border spinner-border-sm me-3" role="status"></div>
            <div>Verificando conexão...</div>
        </div>
    `;

        try {
            const response = await fetch(`${API_BASE}/status`);
            const data = await response.json();

            const isConnected = data.ml_connection === 'conectado';
            const statusClass = isConnected ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10';
            const statusIcon = isConnected ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger';
            const statusText = isConnected ? 'Conectado' : 'Desconectado';

            let tokenRenewedMsg = '';
            if (data.token_renewed) {
                tokenRenewedMsg = `
                <div class="alert alert-success mt-2 mb-0 py-2">
                    <i class="bi bi-check-circle me-1"></i>
                    Token renovado automaticamente!
                </div>
            `;
            }

            statusDiv.innerHTML = `
            <div class="p-3 border rounded ${statusClass}">
                <div class="d-flex align-items-center mb-2">
                    <i class="bi ${statusIcon} fs-3 me-3"></i>
                    <div>
                        <div class="fw-bold">${statusText}</div>
                        <small class="text-muted">Mercado Livre API</small>
                    </div>
                </div>
                ${data.account ? `
                    <div class="border-top pt-2 mt-2">
                        <div class="row small">
                            <div class="col-6">
                                <strong>Conta:</strong> ${data.account.nickname || '-'}
                            </div>
                            <div class="col-6">
                                <strong>Email:</strong> ${data.account.email || '-'}
                            </div>
                            <div class="col-6">
                                <strong>ML User ID:</strong> ${data.account.ml_user_id || '-'}
                            </div>
                            <div class="col-6">
                                <strong>Token:</strong>
                                <span class="badge ${data.account.token_status === 'válido' ? 'bg-success' : 'bg-danger'}">
                                    ${data.account.token_status || 'N/A'}
                                </span>
                            </div>
                            ${data.account.token_expires_at ? `
                                <div class="col-12 mt-1">
                                    <strong>Expira em:</strong> ${new Date(data.account.token_expires_at).toLocaleString('pt-BR')}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                ` : ''}
                ${data.ml_info ? `
                    <div class="border-top pt-2 mt-2">
                        <div class="row small">
                            <div class="col-6">
                                <strong>Reputação:</strong> ${data.ml_info.seller_reputation || 'N/A'}
                            </div>
                            <div class="col-6">
                                <strong>Pontos:</strong> ${data.ml_info.points || 0}
                            </div>
                            <div class="col-6">
                                <strong>Site:</strong> ${data.ml_info.site_id || 'MLB'}
                            </div>
                        </div>
                    </div>
                ` : ''}
                ${data.ml_error ? `
                    <div class="alert alert-danger mt-2 mb-0 py-2 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        ${data.ml_error}
                    </div>
                ` : ''}
                ${tokenRenewedMsg}
                ${!isConnected ? `
                    <div class="alert alert-warning mt-3 mb-0 py-2">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Ação necessária:</strong> Reconecte sua conta para acessar dados reais.
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-primary" data-pricing-cmd="renovarTokenML">
                            <i class="bi bi-arrow-repeat me-1"></i> Tentar Renovar Token
                        </button>
                        <a href="/auth/authorize" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Reconectar Conta
                        </a>
                    </div>
                ` : ''}
            </div>
        `;
        } catch (err) {
            statusDiv.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Erro ao verificar conexão: ${err.message}
            </div>
        `;
        }
    }

    // Renovar token ML manualmente
    async function renovarTokenML() {
        const statusDiv = document.getElementById('connectionStatus');
        const originalContent = statusDiv.innerHTML;

        statusDiv.innerHTML = `
        <div class="d-flex align-items-center p-3 border rounded">
            <div class="spinner-border spinner-border-sm me-3" role="status"></div>
            <div>Renovando token...</div>
        </div>
    `;

        try {
            const response = await fetch(`${API_BASE}/refresh-token`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            const data = await response.json();

            if (data.success) {
                showToast('Token renovado com sucesso! Recarregando...', 'success');
                // Re-verificar conexão após renovação
                setTimeout(() => {
                    verificarConexaoML();
                    refreshItems(); // Recarregar itens
                }, 1000);
            } else {
                statusDiv.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-x-circle me-2"></i>
                    <strong>Falha ao renovar token:</strong> ${data.message || 'Erro desconhecido'}
                    <br><small>${data.action_required || ''}</small>
                </div>
                <div class="mt-2">
                    <a href="/auth/authorize" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Reconectar Conta Mercado Livre
                    </a>
                </div>
            `;
            }
        } catch (err) {
            statusDiv.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Erro ao renovar token: ${err.message}
            </div>
        `;
        }
    }

    // Confirmar exclusão da conta
    function confirmarExclusaoConta() {
        // Primeiro, buscar info da conta para mostrar no modal
        fetch(`${API_BASE}/status`)
            .then(r => r.json())
            .then(data => {
                const accountName = data.account?.nickname || 'Esta conta';
                const accountEmail = data.account?.email || '';

                // Criar modal de confirmação
                const modalHtml = `
                <div class="modal fade" id="deleteAccountModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    Excluir Conta
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-octagon me-2"></i>
                                    <strong>Atenção!</strong> Esta ação é irreversível.
                                </div>
                                <p>Você está prestes a excluir permanentemente a conta:</p>
                                <div class="card bg-light mb-3">
                                    <div class="card-body py-2">
                                        <strong>${accountName}</strong>
                                        ${accountEmail ? `<br><small class="text-muted">${accountEmail}</small>` : ''}
                                    </div>
                                </div>
                                <p class="small text-muted mb-3">
                                    Todos os dados serão perdidos, incluindo custos, histórico de preços,
                                    regras de precificação e alertas.
                                </p>
                                <div class="form-group">
                                    <label class="form-label">Digite <strong>EXCLUIR</strong> para confirmar:</label>
                                    <input type="text" class="form-control" id="confirmDeleteInput"
                                           placeholder="EXCLUIR" autocomplete="off">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    Cancelar
                                </button>
                                <button type="button" class="btn btn-danger" id="btnConfirmDelete" disabled
                                        data-pricing-cmd="executarExclusaoConta">
                                    <i class="bi bi-trash me-1"></i> Excluir Permanentemente
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

                // Remover modal anterior se existir
                const existingModal = document.getElementById('deleteAccountModal');
                if (existingModal) existingModal.remove();

                // Adicionar modal ao DOM
                document.body.insertAdjacentHTML('beforeend', modalHtml);

                // Habilitar botão apenas quando digitar EXCLUIR
                const input = document.getElementById('confirmDeleteInput');
                const btn = document.getElementById('btnConfirmDelete');
                input.addEventListener('input', function() {
                    btn.disabled = this.value.toUpperCase() !== 'EXCLUIR';
                });

                // Abrir modal
                const modal = new bootstrap.Modal(document.getElementById('deleteAccountModal'));
                modal.show();
            })
            .catch(err => {
                showToast('Erro ao carregar informações da conta', 'danger');
            });
    }

    // Executar exclusão da conta
    async function executarExclusaoConta() {
        const btn = document.getElementById('btnConfirmDelete');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Excluindo...';

        try {
            const data = await requestJson(`/auth/account/${ACCOUNT_ID}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (data.success) {
                // Fechar modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteAccountModal'));
                modal.hide();

                // Mostrar mensagem de sucesso
                showToast('Conta excluída com sucesso! Redirecionando...', 'success');

                // Redirecionar para página inicial ou seleção de conta
                setTimeout(() => {
                    window.location.href = '/dashboard';
                }, 2000);
            } else {
                throw new Error(data.error || data.message || 'Erro ao excluir conta');
            }
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = originalText;
            showToast('Erro ao excluir conta: ' + err.message, 'danger');
        }
    }

    // Notificação sonora para alertas críticos
    function playAlertSound() {
        if (localStorage.getItem('pricing_notifySound') !== 'false') {
            // Criar audio context para notificação sonora
            const audioCtx = new(window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);

            oscillator.frequency.value = 440;
            oscillator.type = 'sine';
            gainNode.gain.setValueAtTime(0.3, audioCtx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.5);

            oscillator.start(audioCtx.currentTime);
            oscillator.stop(audioCtx.currentTime + 0.5);
        }
    }

    // Notificação do navegador
    function showBrowserNotification(title, body) {
        if (localStorage.getItem('pricing_notifyBrowser') !== 'false' && Notification.permission === 'granted') {
            new Notification(title, {
                body: body,
                icon: '/icons/icon-192x192.png',
                tag: 'pricing-alert'
            });
        }
    }

    // Inicializar configurações ao abrir modal
    document.getElementById('settingsModal')?.addEventListener('show.bs.modal', function() {
        loadSavedSettings();
        verificarConexaoML();
    });

    // ========================================
    // Funções de Relatórios Avançados
    // ========================================

    // Abrir Relatório de Performance
    function abrirRelatorioPerformance() {
        const modal = new bootstrap.Modal(document.getElementById('performanceReportModal'));
        modal.show();
        carregarPerformance();
    }

    // Carregar dados de performance
    async function carregarPerformance() {
        const days = document.getElementById('performancePeriodo').value;
        const container = document.getElementById('performanceContent');

        container.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-info"></div>
            <p class="mt-2 text-muted">Carregando relatório...</p>
        </div>
    `;

        try {
            const response = await fetch(`${API_BASE}/performance?days=${days}`);
            const data = await response.json();

            if (!data.success) {
                container.innerHTML = `<div class="alert alert-warning">${data.message || 'Erro ao carregar'}</div>`;
                return;
            }

            const m = data.metricas;
            const a = data.alertas;

            container.innerHTML = `
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0">${m.total_alteracoes}</h3>
                            <small>Alterações de Preço</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0">${m.total_items_alterados}</h3>
                            <small>Itens Alterados</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0">${m.aumentos}</h3>
                            <small>Aumentos</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-danger text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0">${m.reducoes}</h3>
                            <small>Reduções</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h6><i class="bi bi-graph-up me-1"></i> Variação Média</h6>
                            <h2 class="${m.variacao_media >= 0 ? 'text-success' : 'text-danger'}">
                                ${m.variacao_media >= 0 ? '+' : ''}${m.variacao_media.toFixed(2)}%
                            </h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h6><i class="bi bi-percent me-1"></i> Margem Média Nova</h6>
                            <h2 class="${m.margem_media_nova >= 15 ? 'text-success' : m.margem_media_nova >= 5 ? 'text-warning' : 'text-danger'}">
                                ${m.margem_media_nova.toFixed(2)}%
                            </h2>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-bell me-1"></i> Alertas no Período
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col">
                            <span class="badge bg-danger fs-5">${a.criticos}</span>
                            <div class="small text-muted">Críticos</div>
                        </div>
                        <div class="col">
                            <span class="badge bg-warning text-dark fs-5">${a.moderados}</span>
                            <div class="small text-muted">Moderados</div>
                        </div>
                        <div class="col">
                            <span class="badge bg-success fs-5">${a.resolvidos}</span>
                            <div class="small text-muted">Resolvidos</div>
                        </div>
                    </div>
                </div>
            </div>

            ${data.top_items && data.top_items.length > 0 ? `
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-trophy me-1"></i> Top 10 Itens Mais Alterados
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Item ID</th>
                                    <th>Alterações</th>
                                    <th>Preço Mín</th>
                                    <th>Preço Máx</th>
                                    <th>Variação Média</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.top_items.map(item => `
                                    <tr>
                                        <td><code>${item.item_id}</code></td>
                                        <td>${item.total_alteracoes}</td>
                                        <td>R$ ${parseFloat(item.preco_min).toFixed(2)}</td>
                                        <td>R$ ${parseFloat(item.preco_max).toFixed(2)}</td>
                                        <td>${parseFloat(item.variacao_media).toFixed(2)}%</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            ` : ''}
        `;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    // Abrir Modal de Monitorar Concorrentes
    function abrirMonitorConcorrentes() {
        const modal = new bootstrap.Modal(document.getElementById('competitorsModal'));
        modal.show();
    }

    // Monitorar Concorrentes
    async function monitorarConcorrentes() {
        const itemId = document.getElementById('competitorItemId').value.trim();
        if (!itemId) {
            showToast('Digite o ID do item', 'warning');
            return;
        }

        const container = document.getElementById('competitorsResult');
        container.style.display = 'block';
        container.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-success"></div>
            <p class="mt-2 text-muted">Buscando concorrentes...</p>
        </div>
    `;

        try {
            const response = await fetch(`${API_BASE}/monitor/competitors/${itemId}`);
            const data = await response.json();

            if (!data.success) {
                container.innerHTML = `<div class="alert alert-warning">${data.message || 'Erro'}</div>`;
                return;
            }

            const stats = data.estatisticas;

            container.innerHTML = `
            <div class="alert alert-info">
                <strong>${data.item.titulo}</strong><br>
                Seu preço: <strong>R$ ${data.item.preco.toFixed(2)}</strong> •
                Posição: <strong>${data.posicao_preco}º de ${data.posicao_total}</strong>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="small text-muted">Menor Preço</div>
                        <strong class="text-success">R$ ${stats.preco_minimo.toFixed(2)}</strong>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="small text-muted">Preço Médio</div>
                        <strong>R$ ${stats.preco_medio.toFixed(2)}</strong>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="small text-muted">Preço Mediano</div>
                        <strong>R$ ${stats.preco_mediano.toFixed(2)}</strong>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="small text-muted">Maior Preço</div>
                        <strong class="text-danger">R$ ${stats.preco_maximo.toFixed(2)}</strong>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Concorrente</th>
                            <th>Preço</th>
                            <th>Diferença</th>
                            <th>Vendidos</th>
                            <th>Frete</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.concorrentes.slice(0, 15).map(c => `
                            <tr>
                                <td>
                                    <small class="text-truncate d-block" style="max-width: 250px;" title="${c.titulo}">
                                        ${c.titulo}
                                    </small>
                                    <small class="text-muted">${c.vendedor || 'N/A'}</small>
                                </td>
                                <td><strong>R$ ${c.preco.toFixed(2)}</strong></td>
                                <td class="${c.diferenca_preco < 0 ? 'text-danger' : c.diferenca_preco > 0 ? 'text-success' : ''}">
                                    ${c.diferenca_preco > 0 ? '+' : ''}${c.diferenca_preco}%
                                </td>
                                <td>${c.vendidos}</td>
                                <td>${c.frete_gratis ? '<span class="badge bg-success">Grátis</span>' : '-'}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    // Abrir Modal de Previsão
    function abrirPrevisaoMargem() {
        const modal = new bootstrap.Modal(document.getElementById('forecastModal'));
        modal.show();
    }

    // Calcular Previsão de Margem
    async function calcularPrevisao() {
        const itemId = document.getElementById('forecastItemId').value.trim();
        if (!itemId) {
            showToast('Digite o ID do item', 'warning');
            return;
        }

        const container = document.getElementById('forecastResult');
        container.style.display = 'block';
        container.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-warning"></div>
            <p class="mt-2 text-muted">Calculando previsões...</p>
        </div>
    `;

        try {
            const response = await fetch(`${API_BASE}/forecast`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    item_id: itemId
                })
            });
            const data = await response.json();

            if (!data.success) {
                container.innerHTML = `<div class="alert alert-warning">${data.message || 'Erro'}</div>`;
                return;
            }

            container.innerHTML = `
            <div class="alert alert-secondary">
                <strong>Preço Atual:</strong> R$ ${data.preco_atual.toFixed(2)} •
                <strong>Margem Atual:</strong> ${data.margem_atual.toFixed(2)}%
            </div>

            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Cenário</th>
                            <th>Preço</th>
                            <th>Margem</th>
                            <th>Lucro Unit.</th>
                            <th>Impacto Ranking</th>
                            <th>Viável</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.previsoes.map(p => `
                            <tr class="${p.cenario === data.cenario_recomendado ? 'table-success' : ''}">
                                <td>
                                    ${p.cenario}
                                    ${p.cenario === data.cenario_recomendado ? '<span class="badge bg-success ms-1">Recomendado</span>' : ''}
                                </td>
                                <td>R$ ${p.preco_novo.toFixed(2)}</td>
                                <td class="${p.margem >= 15 ? 'text-success' : p.margem >= 5 ? 'text-warning' : 'text-danger'}">
                                    ${p.margem.toFixed(2)}%
                                </td>
                                <td>R$ ${p.lucro_unitario.toFixed(2)}</td>
                                <td>
                                    <span class="badge bg-${p.impacto_ranking === 'positivo' ? 'success' : p.impacto_ranking === 'neutro' ? 'secondary' : p.impacto_ranking === 'negativo_moderado' ? 'warning' : 'danger'}">
                                        ${p.impacto_ranking.replace('_', ' ')}
                                    </span>
                                </td>
                                <td>${p.viavel ? '✅' : '❌'}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    // Buscar Sugestão Automática
    async function buscarSugestaoAutomatica() {
        const itemId = document.getElementById('autoSuggestItemId').value.trim();
        if (!itemId) {
            showToast('Digite o ID do item', 'warning');
            return;
        }

        const container = document.getElementById('autoSuggestResult');
        container.style.display = 'block';
        container.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary"></div>
            <p class="mt-2 text-muted">Analisando item...</p>
        </div>
    `;

        try {
            const response = await fetch(`${API_BASE}/auto-suggest/${itemId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            const data = await response.json();

            if (!data.success) {
                container.innerHTML = `<div class="alert alert-warning">${data.message || 'Erro'}</div>`;
                return;
            }

            const estrategias = Object.entries(data.estrategias);

            container.innerHTML = `
            <div class="alert alert-info">
                <div class="row">
                    <div class="col-md-6">
                        <strong>Preço Atual:</strong> R$ ${data.preco_atual.toFixed(2)}<br>
                        <strong>Margem Atual:</strong> ${data.margem_atual.toFixed(2)}%
                    </div>
                    <div class="col-md-6">
                        <strong>Preço Médio Concorrentes:</strong> R$ ${data.preco_medio_concorrentes.toFixed(2)}<br>
                        <strong>Total Concorrentes:</strong> ${data.total_concorrentes}
                    </div>
                </div>
            </div>

            <h6 class="mt-3"><i class="bi bi-lightbulb me-1"></i> Estratégias Sugeridas</h6>
            <div class="row g-3">
                ${estrategias.map(([key, e]) => `
                    <div class="col-md-6">
                        <div class="card ${key === data.recomendacao ? 'border-success' : ''}">
                            <div class="card-header ${key === data.recomendacao ? 'bg-success text-white' : ''}">
                                ${key === data.recomendacao ? '⭐ ' : ''}${key.replace('_', ' ').toUpperCase()}
                            </div>
                            <div class="card-body">
                                <h4>R$ ${e.preco.toFixed(2)}</h4>
                                <p class="mb-1">Margem: <strong class="${e.margem >= 15 ? 'text-success' : e.margem >= 0 ? 'text-warning' : 'text-danger'}">${e.margem.toFixed(2)}%</strong></p>
                                <p class="mb-1">Lucro: R$ ${e.lucro.toFixed(2)}</p>
                                <p class="small text-muted mb-0">${e.descricao}</p>
                                ${!e.viavel ? '<span class="badge bg-danger">Não viável</span>' : ''}
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    // ========================================
    // Funções de Alertas de Preço
    // ========================================

    function abrirAlertasPreco() {
        const modal = new bootstrap.Modal(document.getElementById('priceAlertsModal'));
        modal.show();
        carregarAlertas();
    }

    async function carregarAlertas() {
        const container = document.getElementById('alertasList');

        try {
            const response = await fetch(`${API_BASE}/price-alerts`);
            const data = await response.json();

            if (!data.success || data.alertas.length === 0) {
                container.innerHTML = '<p class="text-muted text-center">Nenhum alerta configurado</p>';
                return;
            }

            container.innerHTML = `
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Tipo</th>
                            <th>Gatilho</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.alertas.map(a => `
                            <tr>
                                <td>
                                    <small class="text-truncate d-block" style="max-width: 200px;">
                                        ${a.titulo || a.item_id}
                                    </small>
                                </td>
                                <td><span class="badge bg-secondary">${a.tipo_alerta.replace('_', ' ')}</span></td>
                                <td>${a.valor_gatilho}%</td>
                                <td>${a.ativo == 1 ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>'}</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger" data-pricing-cmd="removerAlerta" data-pricing-args-raw="${a.id}">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    async function criarAlertaPreco() {
        const itemId = document.getElementById('alertItemId').value.trim();
        const tipo = document.getElementById('alertTipo').value;
        const valor = document.getElementById('alertValor').value;
        const email = document.getElementById('alertEmail').checked;
        const whatsapp = document.getElementById('alertWhatsapp').checked;

        if (!itemId) {
            showToast('Digite o ID do item', 'warning');
            return;
        }

        try {
            const response = await fetch(`${API_BASE}/price-alerts`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    item_id: itemId,
                    tipo: tipo,
                    valor_gatilho: valor,
                    notificar_email: email,
                    notificar_whatsapp: whatsapp
                })
            });

            const data = await response.json();

            if (data.success) {
                showToast('Alerta criado com sucesso!', 'success');
                document.getElementById('alertItemId').value = '';
                carregarAlertas();
            } else {
                showToast(data.message || 'Erro ao criar alerta', 'danger');
            }
        } catch (err) {
            showToast('Erro: ' + err.message, 'danger');
        }
    }

    async function removerAlerta(alertId) {
        if (!confirm('Remover este alerta?')) return;

        try {
            const response = await fetch(`${API_BASE}/price-alerts/${alertId}`, {
                method: 'DELETE'
            });
            const data = await response.json();

            if (data.success) {
                showToast('Alerta removido', 'success');
                carregarAlertas();
            } else {
                showToast(data.message || 'Erro', 'danger');
            }
        } catch (err) {
            showToast('Erro: ' + err.message, 'danger');
        }
    }

    // ========================================
    // Funções de Preço Ideal e Rentabilidade
    // ========================================

    function abrirPrecoIdeal() {
        const modal = new bootstrap.Modal(document.getElementById('idealPriceModal'));
        modal.show();
    }

    async function calcularPrecoIdeal() {
        const itemId = document.getElementById('idealItemId').value.trim();
        const margem = document.getElementById('idealMargem').value;

        if (!itemId) {
            showToast('Digite o ID do item', 'warning');
            return;
        }

        const container = document.getElementById('idealPriceResult');
        container.style.display = 'block';
        container.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border text-primary spinner-border-sm"></div>
            <span class="ms-2">Calculando...</span>
        </div>
    `;

        try {
            const response = await fetch(`${API_BASE}/calculate-ideal-price`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    item_id: itemId,
                    margem_desejada: parseFloat(margem)
                })
            });

            const data = await response.json();

            if (!data.success) {
                container.innerHTML = `<div class="alert alert-warning">${data.message || 'Erro'}</div>`;
                return;
            }

            const acaoClass = data.analise.acao_sugerida === 'aumentar_preco' ? 'success' :
                data.analise.acao_sugerida === 'reduzir_preco' ? 'danger' : 'secondary';

            container.innerHTML = `
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-3">${data.titulo}</h6>
                    <div class="row text-center mb-3">
                        <div class="col-6">
                            <div class="text-muted small">Preço Atual</div>
                            <h4>R$ ${data.preco_atual.toFixed(2)}</h4>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Preço Ideal (${margem}%)</div>
                            <h4 class="text-primary">R$ ${data.preco_ideal.toFixed(2)}</h4>
                        </div>
                    </div>
                    <div class="alert alert-${acaoClass}">
                        <strong>Ação:</strong>
                        ${data.analise.acao_sugerida === 'aumentar_preco' ? 'Aumentar preço' :
                          data.analise.acao_sugerida === 'reduzir_preco' ? 'Reduzir preço' : 'Manter preço'}
                        (${data.diferenca_percentual > 0 ? '+' : ''}${data.diferenca_percentual.toFixed(2)}%)
                    </div>
                    ${data.analise.preco_medio_concorrentes ? `
                        <p class="mb-0"><small>Preço médio concorrentes: R$ ${data.analise.preco_medio_concorrentes.toFixed(2)}</small></p>
                    ` : ''}
                </div>
            </div>
        `;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function abrirRentabilidade() {
        const modal = new bootstrap.Modal(document.getElementById('profitabilityModal'));
        modal.show();
        carregarRentabilidade();
    }

    async function carregarRentabilidade() {
        const container = document.getElementById('profitabilityContent');

        try {
            const response = await fetch(`${API_BASE}/profitability`);
            const data = await response.json();

            if (!data.success) {
                container.innerHTML = `<div class="alert alert-warning">${data.message || 'Erro'}</div>`;
                return;
            }

            if (data.itens_analisados === 0 && data.resumo?.total_itens === 0) {
                container.innerHTML = `<div class="alert alert-info">Nenhum custo cadastrado. Configure os custos dos seus produtos para análise.</div>`;
                return;
            }

            const r = data.resumo;

            container.innerHTML = `
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0">${r.total_itens}</h3>
                            <small>Itens Analisados</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0">${r.itens_lucrativos}</h3>
                            <small>Lucrativos (${r.percentual_lucrativos}%)</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-danger text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0">${r.itens_prejuizo}</h3>
                            <small>Com Prejuízo</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0">R$ ${r.lucro_mensal_estimado.toFixed(2)}</h3>
                            <small>Lucro Mensal Est.</small>
                        </div>
                    </div>
                </div>
            </div>

            ${data.top_lucrativos && data.top_lucrativos.length > 0 ? `
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-trophy me-1"></i> Top 10 Mais Lucrativos
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th>Preço</th>
                                    <th>Margem</th>
                                    <th>Lucro/Un</th>
                                    <th>Lucro Mensal</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.top_lucrativos.map(i => `
                                    <tr>
                                        <td>
                                            <small class="text-truncate d-block" style="max-width: 200px;">
                                                ${i.titulo}
                                            </small>
                                        </td>
                                        <td>R$ ${i.preco.toFixed(2)}</td>
                                        <td class="text-success">${i.margem.toFixed(2)}%</td>
                                        <td>R$ ${i.lucro_unitario.toFixed(2)}</td>
                                        <td><strong>R$ ${i.lucro_mensal_estimado.toFixed(2)}</strong></td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            ` : ''}

            ${data.prejuizo && data.prejuizo.length > 0 ? `
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <i class="bi bi-exclamation-triangle me-1"></i> Itens com Prejuízo
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th>Preço</th>
                                    <th>Margem</th>
                                    <th>Prejuízo/Un</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.prejuizo.map(i => `
                                    <tr>
                                        <td>
                                            <small class="text-truncate d-block" style="max-width: 200px;">
                                                ${i.titulo}
                                            </small>
                                        </td>
                                        <td>R$ ${i.preco.toFixed(2)}</td>
                                        <td class="text-danger">${i.margem.toFixed(2)}%</td>
                                        <td class="text-danger">R$ ${Math.abs(i.lucro_unitario).toFixed(2)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            ` : ''}
        `;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    // ========================================
    // Funções de Importação de Custos
    // ========================================

    document.getElementById('importCostsForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();

        const fileInput = document.getElementById('importFile');
        const resultDiv = document.getElementById('importResult');

        if (!fileInput.files.length) {
            showToast('Selecione um arquivo', 'warning');
            return;
        }

        const formData = new FormData();
        formData.append('file', fileInput.files[0]);

        resultDiv.style.display = 'block';
        resultDiv.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border text-secondary spinner-border-sm"></div>
            <span class="ms-2">Importando...</span>
        </div>
    `;

        try {
            const response = await fetch(`${API_BASE}/import/costs`, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                resultDiv.innerHTML = `
                <div class="alert alert-success">
                    <strong>Importação concluída!</strong><br>
                    ${data.importados} registros importados, ${data.erros} erros.
                    ${data.detalhes_erros && data.detalhes_erros.length > 0 ? `
                        <hr>
                        <small class="text-muted">${data.detalhes_erros.join('<br>')}</small>
                    ` : ''}
                </div>
            `;
                fileInput.value = '';
                loadItems(); // Recarregar lista
            } else {
                resultDiv.innerHTML = `<div class="alert alert-danger">${data.message || 'Erro na importação'}</div>`;
            }
        } catch (err) {
            resultDiv.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    });

    // Event: Carregar dados ao mudar de aba
    document.addEventListener('DOMContentLoaded', function() {
        const tabPromo = document.getElementById('tab-promocoes');
        const tabHistorico = document.getElementById('tab-historico');
        const tabRegras = document.getElementById('tab-regras');

        if (tabPromo) {
            tabPromo.addEventListener('shown.bs.tab', function() {
                updatePromoPreview();
            });
        }

        if (tabHistorico) {
            tabHistorico.addEventListener('shown.bs.tab', function() {
                loadPriceHistory();
            });
        }

        if (tabRegras) {
            tabRegras.addEventListener('shown.bs.tab', function() {
                loadRegrasAtivas();
            });
        }

        // Solicitar permissão de notificação se necessário
        if (Notification.permission === 'default' && localStorage.getItem('pricing_notifyBrowser') !== 'false') {
            // Aguardar interação do usuário
            document.body.addEventListener('click', function requestNotification() {
                Notification.requestPermission();
                document.body.removeEventListener('click', requestNotification);
            }, {
                once: true
            });
        }
    });

    // ========================================
    // Funções do Auto-Otimizador de Preços
    // ========================================

    function abrirAutoOptimizer() {
        const modal = new bootstrap.Modal(document.getElementById('autoOptimizerModal'));
        modal.show();
        carregarConfigOptimizer();
    }

    async function carregarConfigOptimizer() {
        const container = document.getElementById('optimizerConfigForm');
        if (!container) return;

        try {
            container.innerHTML = '<div class="text-center p-3"><div class="spinner-border text-primary"></div></div>';

            const response = await fetch(`${API_BASE}/auto-optimizer/config`);
            const data = await response.json();

            if (data.success && data.config) {
                const config = data.config;

                // Preencher form
                document.getElementById('optimizerEnabled').checked = config.enabled;
                document.getElementById('optimizerMode').value = config.mode || 'suggest';
                document.getElementById('optimizerMinMargin').value = config.min_margin || 10;
                document.getElementById('optimizerMaxMargin').value = config.max_margin || 50;
                document.getElementById('optimizerStrategy').value = config.strategy || 'match_lowest';
                document.getElementById('optimizerDiffPercent').value = config.strategy_diff_percent || 1;
                document.getElementById('optimizerMaxAdjust').value = config.max_adjust_percent || 15;
                document.getElementById('optimizerMinInterval').value = config.min_interval_hours || 24;
                document.getElementById('optimizerNotifyEmail').checked = config.notify_email;
                document.getElementById('optimizerNotifySlack').checked = config.notify_slack;

                renderOptimizerConfigForm();
            } else {
                renderOptimizerConfigForm();
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function renderOptimizerConfigForm() {
        const container = document.getElementById('optimizerConfigForm');
        container.innerHTML = `
        <div class="row g-3">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="optimizerEnabled">
                    <label class="form-check-label fw-bold" for="optimizerEnabled">
                        Ativar Auto-Otimizador
                    </label>
                </div>
                <small class="text-muted">Quando ativo, o sistema analisa e ajusta preços automaticamente</small>
            </div>

            <div class="col-md-6">
                <label class="form-label">Modo de Operação</label>
                <select class="form-select" id="optimizerMode">
                    <option value="suggest">Apenas Sugestões</option>
                    <option value="auto_apply">Aplicar Automaticamente</option>
                </select>
                <small class="text-muted">Sugestões requerem aprovação manual</small>
            </div>

            <div class="col-md-6">
                <label class="form-label">Estratégia</label>
                <select class="form-select" id="optimizerStrategy">
                    <option value="match_lowest">Igualar ao Menor</option>
                    <option value="stay_below">Ficar Abaixo do Menor</option>
                    <option value="stay_above">Ficar Acima do Menor</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Margem Mínima (%)</label>
                <input type="number" class="form-control" id="optimizerMinMargin" value="10" min="0" step="0.1">
            </div>

            <div class="col-md-4">
                <label class="form-label">Margem Máxima (%)</label>
                <input type="number" class="form-control" id="optimizerMaxMargin" value="50" min="0" step="0.1">
            </div>

            <div class="col-md-4">
                <label class="form-label">Diferença Estratégia (%)</label>
                <input type="number" class="form-control" id="optimizerDiffPercent" value="1" min="0" step="0.1">
                <small class="text-muted">Usado em "ficar abaixo/acima"</small>
            </div>

            <div class="col-md-6">
                <label class="form-label">Ajuste Máximo por Vez (%)</label>
                <input type="number" class="form-control" id="optimizerMaxAdjust" value="15" min="1" max="50">
                <small class="text-muted">Limite de variação por otimização</small>
            </div>

            <div class="col-md-6">
                <label class="form-label">Intervalo Mínimo (horas)</label>
                <input type="number" class="form-control" id="optimizerMinInterval" value="24" min="1">
                <small class="text-muted">Tempo entre otimizações do mesmo item</small>
            </div>

            <div class="col-12">
                <hr>
                <label class="form-label">Notificações</label>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="optimizerNotifyEmail">
                    <label class="form-check-label" for="optimizerNotifyEmail">Notificar por Email</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="optimizerNotifySlack">
                    <label class="form-check-label" for="optimizerNotifySlack">Notificar no Slack</label>
                </div>
            </div>

            <div class="col-12 text-end">
                <button type="button" class="btn btn-primary" data-pricing-cmd="salvarConfigOptimizer">
                    <i class="bi bi-check-lg me-1"></i>Salvar Configuração
                </button>
            </div>
        </div>
    `;
    }

    async function salvarConfigOptimizer() {
        const config = {
            enabled: document.getElementById('optimizerEnabled').checked,
            mode: document.getElementById('optimizerMode').value,
            min_margin: parseFloat(document.getElementById('optimizerMinMargin').value),
            max_margin: parseFloat(document.getElementById('optimizerMaxMargin').value),
            strategy: document.getElementById('optimizerStrategy').value,
            strategy_diff_percent: parseFloat(document.getElementById('optimizerDiffPercent').value),
            max_adjust_percent: parseFloat(document.getElementById('optimizerMaxAdjust').value),
            min_interval_hours: parseInt(document.getElementById('optimizerMinInterval').value),
            notify_email: document.getElementById('optimizerNotifyEmail').checked,
            notify_slack: document.getElementById('optimizerNotifySlack').checked
        };

        try {
            const response = await fetch(`${API_BASE}/auto-optimizer/config`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(config)
            });

            const data = await response.json();

            if (data.success) {
                showToast('Configuração salva com sucesso!', 'success');
            } else {
                showToast(data.message || 'Erro ao salvar', 'danger');
            }
        } catch (err) {
            showToast('Erro: ' + err.message, 'danger');
        }
    }

    async function executarOptimizer() {
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Executando...';

        const resultsDiv = document.getElementById('optimizerResults');
        resultsDiv.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary"></div><p class="mt-2">Analisando itens e concorrentes...</p></div>';

        try {
            const response = await fetch(`${API_BASE}/auto-optimizer/run`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            const data = await response.json();

            if (data.success) {
                renderOptimizerResults(data);
            } else {
                resultsDiv.innerHTML = `<div class="alert alert-danger">${data.message || 'Erro na otimização'}</div>`;
            }
        } catch (err) {
            resultsDiv.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    function renderOptimizerResults(data) {
        const resultsDiv = document.getElementById('optimizerResults');
        const results = data.results || [];

        if (results.length === 0) {
            resultsDiv.innerHTML = `
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                Nenhum item necessita ajuste no momento.
                ${data.items_analyzed || 0} itens foram analisados.
            </div>
        `;
            return;
        }

        const suggestions = results.filter(r => r.status === 'suggested');
        const applied = results.filter(r => r.status === 'applied');
        const errors = results.filter(r => r.status === 'error');

        let html = `
        <div class="alert alert-success mb-3">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Otimização concluída!</strong><br>
            ${data.items_analyzed || 0} itens analisados,
            ${applied.length} preços aplicados,
            ${suggestions.length} sugestões pendentes
        </div>
    `;

        if (suggestions.length > 0) {
            html += `
            <h6 class="mb-2"><i class="bi bi-lightbulb text-warning me-1"></i>Sugestões (requer aprovação)</h6>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Item</th>
                            <th>Preço Atual</th>
                            <th>Sugestão</th>
                            <th>Variação</th>
                            <th>Motivo</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

            suggestions.forEach(item => {
                const variacao = ((item.suggested_price - item.current_price) / item.current_price * 100).toFixed(1);
                const varClass = variacao >= 0 ? 'text-success' : 'text-danger';
                const varIcon = variacao >= 0 ? 'arrow-up' : 'arrow-down';

                html += `
                <tr>
                    <td>
                        <small class="text-muted">${item.item_id}</small><br>
                        <span class="text-truncate d-inline-block" style="max-width:200px">${item.title || '-'}</span>
                    </td>
                    <td>R$ ${formatNumber(item.current_price)}</td>
                    <td><strong class="text-primary">R$ ${formatNumber(item.suggested_price)}</strong></td>
                    <td><span class="${varClass}"><i class="bi bi-${varIcon}"></i> ${variacao}%</span></td>
                    <td><small>${item.reason || '-'}</small></td>
                    <td>
                        <button class="btn btn-sm btn-success" data-pricing-cmd="aplicarSugestaoOptimizer" data-pricing-args-raw="'${item.item_id}', ${item.suggested_price}">
                            <i class="bi bi-check"></i>
                        </button>
                    </td>
                </tr>
            `;
            });

            html += '</tbody></table></div>';
        }

        if (applied.length > 0) {
            html += `
            <h6 class="mb-2"><i class="bi bi-check-circle text-success me-1"></i>Aplicados automaticamente</h6>
            <ul class="list-group mb-3">
        `;

            applied.forEach(item => {
                html += `
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span>${item.item_id} - ${item.title || ''}</span>
                    <span class="badge bg-success">R$ ${formatNumber(item.current_price)} → R$ ${formatNumber(item.new_price)}</span>
                </li>
            `;
            });

            html += '</ul>';
        }

        if (errors.length > 0) {
            html += `
            <h6 class="mb-2"><i class="bi bi-exclamation-triangle text-danger me-1"></i>Erros</h6>
            <ul class="list-group">
        `;

            errors.forEach(item => {
                html += `
                <li class="list-group-item list-group-item-danger">
                    ${item.item_id}: ${item.error || 'Erro desconhecido'}
                </li>
            `;
            });

            html += '</ul>';
        }

        resultsDiv.innerHTML = html;
    }

    async function aplicarSugestaoOptimizer(itemId, newPrice) {
        if (!confirm(`Aplicar preço R$ ${formatNumber(newPrice)} ao item ${itemId}?`)) return;

        try {
            const response = await fetch(`${API_BASE}/auto-optimizer/apply/${itemId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    new_price: newPrice
                })
            });

            const data = await response.json();

            if (data.success) {
                showToast('Preço aplicado com sucesso!', 'success');
                executarOptimizer(); // Recarregar resultados
            } else {
                showToast(data.message || 'Erro ao aplicar', 'danger');
            }
        } catch (err) {
            showToast('Erro: ' + err.message, 'danger');
        }
    }

    async function carregarHistoricoOptimizer() {
        const container = document.getElementById('optimizerHistory');
        if (!container) return;

        container.innerHTML = '<div class="text-center p-3"><div class="spinner-border text-primary"></div></div>';

        try {
            const response = await fetch(`${API_BASE}/auto-optimizer/history?limit=50`);
            const data = await response.json();

            if (data.success && data.history && data.history.length > 0) {
                let html = `
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Item</th>
                                <th>Ação</th>
                                <th>Preço Ant.</th>
                                <th>Preço Novo</th>
                                <th>Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

                data.history.forEach(log => {
                    const date = new Date(log.created_at).toLocaleString('pt-BR');
                    const actionBadge = log.action === 'applied' ?
                        '<span class="badge bg-success">Aplicado</span>' :
                        '<span class="badge bg-warning">Sugerido</span>';

                    html += `
                    <tr>
                        <td><small>${date}</small></td>
                        <td><small>${log.item_id}</small></td>
                        <td>${actionBadge}</td>
                        <td>R$ ${formatNumber(log.old_price)}</td>
                        <td>R$ ${formatNumber(log.new_price)}</td>
                        <td><small>${log.reason || '-'}</small></td>
                    </tr>
                `;
                });

                html += '</tbody></table></div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="alert alert-info">Nenhum histórico encontrado.</div>';
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    async function carregarStatsOptimizer() {
        const container = document.getElementById('optimizerStats');
        if (!container) return;

        container.innerHTML = '<div class="text-center p-3"><div class="spinner-border text-primary"></div></div>';

        try {
            const response = await fetch(`${API_BASE}/auto-optimizer/stats`);
            const data = await response.json();

            if (data.success && data.stats) {
                const stats = data.stats;

                container.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-0">${stats.total_optimizations || 0}</h2>
                                <small>Total de Otimizações</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-0">${stats.applied_count || 0}</h2>
                                <small>Preços Aplicados</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-dark">
                            <div class="card-body text-center">
                                <h2 class="mb-0">${stats.suggested_count || 0}</h2>
                                <small>Sugestões Pendentes</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-0">${stats.items_monitored || 0}</h2>
                                <small>Itens Monitorados</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6>Variação Média de Preço</h6>
                                <h3 class="${(stats.avg_price_change || 0) >= 0 ? 'text-success' : 'text-danger'}">
                                    ${(stats.avg_price_change || 0) >= 0 ? '+' : ''}${(stats.avg_price_change || 0).toFixed(2)}%
                                </h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6>Última Otimização</h6>
                                <h5>${stats.last_optimization ? new Date(stats.last_optimization).toLocaleString('pt-BR') : 'Nunca'}</h5>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">Otimizações por Dia (Últimos 7 dias)</div>
                            <div class="card-body">
                                <canvas id="optimizerChart" height="100"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            `;

                // Renderizar gráfico se houver dados
                if (stats.daily_stats && stats.daily_stats.length > 0) {
                    renderOptimizerChart(stats.daily_stats);
                }
            } else {
                container.innerHTML = '<div class="alert alert-info">Estatísticas não disponíveis.</div>';
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function renderOptimizerChart(dailyStats) {
        const ctx = document.getElementById('optimizerChart');
        if (!ctx) return;

        const labels = dailyStats.map(d => d.date);
        const appliedData = dailyStats.map(d => d.applied || 0);
        const suggestedData = dailyStats.map(d => d.suggested || 0);

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                        label: 'Aplicados',
                        data: appliedData,
                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                        borderColor: 'rgb(40, 167, 69)',
                        borderWidth: 1
                    },
                    {
                        label: 'Sugeridos',
                        data: suggestedData,
                        backgroundColor: 'rgba(255, 193, 7, 0.7)',
                        borderColor: 'rgb(255, 193, 7)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // Event listeners para tabs do Auto-Otimizador
    document.addEventListener('DOMContentLoaded', function() {
        const optimizerModal = document.getElementById('autoOptimizerModal');
        if (optimizerModal) {
            optimizerModal.addEventListener('shown.bs.tab', function(e) {
                const target = e.target.getAttribute('data-bs-target');
                if (target === '#optimizerHistory') {
                    carregarHistoricoOptimizer();
                } else if (target === '#optimizerStats') {
                    carregarStatsOptimizer();
                }
            });
        }
    });

    // ========================================
    // Funções de Testes A/B de Preços
    // ========================================

    let abTestFilter = 'all';
    let selectedAbTest = null;

    function abrirAbTests() {
        const modal = new bootstrap.Modal(document.getElementById('abTestModal'));
        modal.show();
        carregarAbTests();
    }

    async function carregarAbTests() {
        const container = document.getElementById('abTestList');
        container.innerHTML = '<div class="text-center p-3"><div class="spinner-border spinner-border-sm"></div></div>';

        try {
            let url = `${API_BASE}/ab-tests`;
            if (abTestFilter !== 'all') {
                url += `?status=${abTestFilter}`;
            }

            const response = await fetch(url);
            const data = await response.json();

            if (data.success && data.tests) {
                renderAbTestList(data.tests);
            } else {
                container.innerHTML = '<div class="alert alert-warning">Nenhum teste encontrado.</div>';
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function filtrarAbTests(status) {
        abTestFilter = status;
        document.querySelectorAll('#abtest-list .btn-group .btn').forEach(btn => btn.classList.remove('active'));
        event.target.classList.add('active');
        carregarAbTests();
    }

    function renderAbTestList(tests) {
        const container = document.getElementById('abTestList');

        if (tests.length === 0) {
            container.innerHTML = '<div class="alert alert-info">Nenhum teste A/B encontrado.</div>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-sm table-hover">';
        html += `
        <thead class="table-light">
            <tr>
                <th>Nome</th>
                <th>Item</th>
                <th>Controle</th>
                <th>Variante</th>
                <th>Status</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
    `;

        const statusBadges = {
            'draft': '<span class="badge bg-secondary">Rascunho</span>',
            'running': '<span class="badge bg-success">Em Execução</span>',
            'paused': '<span class="badge bg-warning">Pausado</span>',
            'completed': '<span class="badge bg-primary">Finalizado</span>',
            'cancelled': '<span class="badge bg-danger">Cancelado</span>'
        };

        tests.forEach(test => {
            const diff = ((test.variant_price - test.control_price) / test.control_price * 100).toFixed(1);
            const diffClass = diff >= 0 ? 'text-success' : 'text-danger';
            const diffIcon = diff >= 0 ? 'arrow-up' : 'arrow-down';

            let actions = '';
            if (test.status === 'draft' || test.status === 'paused') {
                actions += `<button class="btn btn-sm btn-success me-1" data-pricing-cmd="iniciarAbTest" data-pricing-args-raw="${test.id}" title="Iniciar"><i class="bi bi-play"></i></button>`;
            }
            if (test.status === 'running') {
                actions += `<button class="btn btn-sm btn-warning me-1" data-pricing-cmd="pausarAbTest" data-pricing-args-raw="${test.id}" title="Pausar"><i class="bi bi-pause"></i></button>`;
                actions += `<button class="btn btn-sm btn-primary me-1" data-pricing-cmd="finalizarAbTest" data-pricing-args-raw="${test.id}" title="Finalizar"><i class="bi bi-check-lg"></i></button>`;
            }
            if (test.status !== 'completed' && test.status !== 'cancelled') {
                actions += `<button class="btn btn-sm btn-outline-danger me-1" data-pricing-cmd="cancelarAbTest" data-pricing-args-raw="${test.id}" title="Cancelar"><i class="bi bi-x"></i></button>`;
            }
            actions += `<button class="btn btn-sm btn-info" data-pricing-cmd="analisarAbTest" data-pricing-args-raw="${test.id}" title="Analisar"><i class="bi bi-graph-up"></i></button>`;

            html += `
            <tr>
                <td>
                    <strong>${test.name}</strong>
                    ${test.description ? `<br><small class="text-muted">${test.description.substring(0, 50)}...</small>` : ''}
                </td>
                <td><code>${test.item_id}</code></td>
                <td>R$ ${formatNumber(test.control_price)}</td>
                <td>
                    R$ ${formatNumber(test.variant_price)}
                    <small class="${diffClass}"><i class="bi bi-${diffIcon}"></i>${diff}%</small>
                </td>
                <td>${statusBadges[test.status] || test.status}</td>
                <td>${actions}</td>
            </tr>
        `;
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    }

    async function criarAbTest(event) {
        event.preventDefault();

        const data = {
            name: document.getElementById('abTestName').value,
            item_id: document.getElementById('abTestItemId').value,
            description: document.getElementById('abTestDescription').value,
            control_price: parseFloat(document.getElementById('abTestControlPrice').value),
            variant_price: parseFloat(document.getElementById('abTestVariantPrice').value),
            traffic_split: parseInt(document.getElementById('abTestSplit').value),
            target_metric: document.getElementById('abTestMetric').value,
            min_sample_size: parseInt(document.getElementById('abTestMinSample').value),
            confidence_level: parseFloat(document.getElementById('abTestConfidence').value)
        };

        try {
            const response = await fetch(`${API_BASE}/ab-tests`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showToast('Teste A/B criado com sucesso!', 'success');
                document.getElementById('newAbTestForm').reset();
                // Mudar para aba de lista
                const listTab = document.querySelector('[data-bs-target="#abtest-list"]');
                if (listTab) listTab.click();
                carregarAbTests();
            } else {
                showToast(result.message || 'Erro ao criar teste', 'danger');
            }
        } catch (err) {
            showToast('Erro: ' + err.message, 'danger');
        }
    }

    async function iniciarAbTest(testId) {
        if (!confirm('Iniciar este teste A/B? O preço do item será alterado.')) return;

        try {
            const response = await fetch(`${API_BASE}/ab-tests/${testId}/start`, {
                method: 'POST'
            });
            const data = await response.json();

            if (data.success) {
                showToast('Teste iniciado!', 'success');
                carregarAbTests();
            } else {
                showToast(data.message || 'Erro ao iniciar', 'danger');
            }
        } catch (err) {
            showToast('Erro: ' + err.message, 'danger');
        }
    }

    async function pausarAbTest(testId) {
        try {
            const response = await fetch(`${API_BASE}/ab-tests/${testId}/pause`, {
                method: 'POST'
            });
            const data = await response.json();

            if (data.success) {
                showToast('Teste pausado', 'warning');
                carregarAbTests();
            } else {
                showToast(data.message || 'Erro ao pausar', 'danger');
            }
        } catch (err) {
            showToast('Erro: ' + err.message, 'danger');
        }
    }

    async function finalizarAbTest(testId) {
        const winner = prompt('Qual a variante vencedora? (control/variant) ou deixe em branco para calcular automaticamente');

        try {
            const response = await fetch(`${API_BASE}/ab-tests/${testId}/complete`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    winner: winner || null
                })
            });
            const data = await response.json();

            if (data.success) {
                showToast(`Teste finalizado! Vencedor: ${data.winner}. Preço final: R$ ${formatNumber(data.final_price)}`, 'success');
                carregarAbTests();
            } else {
                showToast(data.message || 'Erro ao finalizar', 'danger');
            }
        } catch (err) {
            showToast('Erro: ' + err.message, 'danger');
        }
    }

    async function cancelarAbTest(testId) {
        if (!confirm('Cancelar este teste? O preço original será restaurado.')) return;

        try {
            const response = await fetch(`${API_BASE}/ab-tests/${testId}/cancel`, {
                method: 'POST'
            });
            const data = await response.json();

            if (data.success) {
                showToast('Teste cancelado', 'info');
                carregarAbTests();
            } else {
                showToast(data.message || 'Erro ao cancelar', 'danger');
            }
        } catch (err) {
            showToast('Erro: ' + err.message, 'danger');
        }
    }

    async function analisarAbTest(testId) {
        selectedAbTest = testId;

        // Mudar para aba de análise
        const analysisTab = document.querySelector('[data-bs-target="#abtest-analysis"]');
        if (analysisTab) analysisTab.click();

        const container = document.getElementById('abTestAnalysis');
        container.innerHTML = '<div class="text-center p-4"><div class="spinner-border"></div></div>';

        try {
            const response = await fetch(`${API_BASE}/ab-tests/${testId}/analyze`);
            const data = await response.json();

            if (data.success) {
                renderAbTestAnalysis(data);
            } else {
                container.innerHTML = `<div class="alert alert-danger">${data.message || 'Erro na análise'}</div>`;
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function renderAbTestAnalysis(data) {
        const container = document.getElementById('abTestAnalysis');
        const test = data.test;
        const control = data.control;
        const variant = data.variant;
        const analysis = data.analysis;

        const statusBadges = {
            'draft': '<span class="badge bg-secondary">Rascunho</span>',
            'running': '<span class="badge bg-success">Em Execução</span>',
            'paused': '<span class="badge bg-warning">Pausado</span>',
            'completed': '<span class="badge bg-primary">Finalizado</span>',
            'cancelled': '<span class="badge bg-danger">Cancelado</span>'
        };

        let winnerHtml = '';
        if (data.winner === 'variant') {
            winnerHtml = '<div class="alert alert-success"><i class="bi bi-trophy me-2"></i><strong>Variante vencendo!</strong></div>';
        } else if (data.winner === 'control') {
            winnerHtml = '<div class="alert alert-info"><i class="bi bi-shield-check me-2"></i><strong>Controle vencendo!</strong></div>';
        } else {
            winnerHtml = '<div class="alert alert-warning"><i class="bi bi-hourglass-split me-2"></i><strong>Resultados inconclusivos</strong></div>';
        }

        container.innerHTML = `
        <div class="mb-3">
            <h5>${test.name} ${statusBadges[test.status]}</h5>
            <small class="text-muted">Item: ${test.item_id}</small>
        </div>

        ${winnerHtml}

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card border-secondary">
                    <div class="card-header bg-secondary text-white">
                        <i class="bi bi-circle me-1"></i> Controle (R$ ${formatNumber(control.price)})
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4">
                                <h4>${control.visits}</h4>
                                <small>Visitas</small>
                            </div>
                            <div class="col-4">
                                <h4>${control.conversions}</h4>
                                <small>Conversões</small>
                            </div>
                            <div class="col-4">
                                <h4>${control.conversion_rate}%</h4>
                                <small>Taxa Conv.</small>
                            </div>
                        </div>
                        <hr>
                        <div class="row text-center">
                            <div class="col-6">
                                <h5>R$ ${formatNumber(control.revenue)}</h5>
                                <small>Receita</small>
                            </div>
                            <div class="col-6">
                                <h5>R$ ${formatNumber(control.revenue_per_visit)}</h5>
                                <small>Por Visita</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <i class="bi bi-lightning me-1"></i> Variante (R$ ${formatNumber(variant.price)})
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4">
                                <h4>${variant.visits}</h4>
                                <small>Visitas</small>
                            </div>
                            <div class="col-4">
                                <h4>${variant.conversions}</h4>
                                <small>Conversões</small>
                            </div>
                            <div class="col-4">
                                <h4 class="${variant.conversion_rate > control.conversion_rate ? 'text-success' : 'text-danger'}">${variant.conversion_rate}%</h4>
                                <small>Taxa Conv.</small>
                            </div>
                        </div>
                        <hr>
                        <div class="row text-center">
                            <div class="col-6">
                                <h5 class="${variant.revenue > control.revenue ? 'text-success' : 'text-danger'}">R$ ${formatNumber(variant.revenue)}</h5>
                                <small>Receita</small>
                            </div>
                            <div class="col-6">
                                <h5>R$ ${formatNumber(variant.revenue_per_visit)}</h5>
                                <small>Por Visita</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <i class="bi bi-graph-up me-1"></i> Análise Estatística
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center">
                        <h4 class="${analysis.conversion_rate_lift >= 0 ? 'text-success' : 'text-danger'}">
                            ${analysis.conversion_rate_lift >= 0 ? '+' : ''}${analysis.conversion_rate_lift}%
                        </h4>
                        <small>Lift Conv.</small>
                    </div>
                    <div class="col-md-3 text-center">
                        <h4 class="${analysis.revenue_lift >= 0 ? 'text-success' : 'text-danger'}">
                            ${analysis.revenue_lift >= 0 ? '+' : ''}${analysis.revenue_lift}%
                        </h4>
                        <small>Lift Receita</small>
                    </div>
                    <div class="col-md-3 text-center">
                        <h4 class="${analysis.is_significant ? 'text-success' : 'text-warning'}">
                            ${(analysis.p_value * 100).toFixed(1)}%
                        </h4>
                        <small>P-Value</small>
                    </div>
                    <div class="col-md-3 text-center">
                        <h4>${analysis.total_sample}/${analysis.min_sample_required}</h4>
                        <small>Amostra</small>
                    </div>
                </div>

                <hr>

                <div class="row">
                    <div class="col-md-6">
                        <strong>Estatisticamente Significativo:</strong>
                        ${analysis.is_significant ? '<span class="text-success">✓ Sim</span>' : '<span class="text-warning">✗ Não ainda</span>'}
                    </div>
                    <div class="col-md-6">
                        <strong>Amostra Mínima:</strong>
                        ${analysis.has_min_sample ? '<span class="text-success">✓ Atingida</span>' : '<span class="text-warning">✗ Não atingida</span>'}
                    </div>
                </div>
            </div>
        </div>

        <div class="alert alert-light">
            <i class="bi bi-lightbulb me-2"></i>
            <strong>Recomendação:</strong> ${data.recommendation}
        </div>
    `;
    }

    // ========================================
    // Funções do Monitor de Concorrentes
    // ========================================

    let currentAlertFilter = 'all';

    function abrirCompetitorMonitor() {
        const modal = new bootstrap.Modal(document.getElementById('competitorMonitorModal'));
        modal.show();
        carregarWatchlist();
        carregarAlertas();
    }

    async function carregarWatchlist() {
        const container = document.getElementById('watchlistContent');
        container.innerHTML = '<div class="text-center p-3"><div class="spinner-border spinner-border-sm"></div></div>';

        try {
            const response = await fetch(`${API_BASE}/competitors/watchlist`);
            const data = await response.json();

            if (data.success && data.watchlist && data.watchlist.length > 0) {
                renderWatchlist(data.watchlist);
            } else {
                container.innerHTML = `
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Nenhum item na watchlist. Adicione itens para monitorar concorrentes.
                </div>
            `;
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function renderWatchlist(items) {
        const container = document.getElementById('watchlistContent');
        let html = '<div class="table-responsive"><table class="table table-hover">';
        html += `
        <thead>
            <tr>
                <th>Item</th>
                <th>Preço</th>
                <th>Concorrentes</th>
                <th>Posição</th>
                <th>Tendência</th>
                <th>Última Scan</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
    `;

        items.forEach(item => {
            const positionClass = item.position_percentile <= 30 ? 'text-success' :
                item.position_percentile <= 60 ? 'text-warning' : 'text-danger';
            const trendIcon = item.trend === 'up' ? 'bi-arrow-up text-success' :
                item.trend === 'down' ? 'bi-arrow-down text-danger' : 'bi-dash text-muted';

            html += `
            <tr>
                <td>
                    <div class="fw-bold">${item.item_id}</div>
                    <small class="text-muted">${item.title || 'Sem título'}</small>
                </td>
                <td>R$ ${formatNumber(item.price)}</td>
                <td>
                    <span class="badge bg-secondary">${item.competitor_count || 0}</span>
                </td>
                <td class="${positionClass}">
                    Top ${item.position_percentile || 0}%
                </td>
                <td><i class="bi ${trendIcon}"></i></td>
                <td><small>${item.last_scan || 'Nunca'}</small></td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" data-pricing-cmd="escanearItem" data-pricing-args='["${item.item_id}"]' title="Escanear">
                            <i class="bi bi-search"></i>
                        </button>
                        <button class="btn btn-outline-info" data-pricing-cmd="verAnaliseItem" data-pricing-args='["${item.item_id}"]' title="Análise">
                            <i class="bi bi-bar-chart"></i>
                        </button>
                        <button class="btn btn-outline-danger" data-pricing-cmd="removerWatchlist" data-pricing-args='["${item.item_id}"]' title="Remover">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    }

    async function adicionarWatchlist() {
        const itemId = document.getElementById('watchlistItemId').value.trim();
        const keywords = document.getElementById('watchlistKeywords').value.trim();

        if (!itemId) {
            showAlert('Informe o ID do item', 'warning');
            return;
        }

        try {
            const response = await fetch(`${API_BASE}/competitors/watchlist`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    item_id: itemId,
                    keywords: keywords || null
                })
            });

            const data = await response.json();

            if (data.success) {
                showAlert('Item adicionado à watchlist!', 'success');
                document.getElementById('watchlistItemId').value = '';
                document.getElementById('watchlistKeywords').value = '';
                carregarWatchlist();
            } else {
                showAlert(data.error || 'Erro ao adicionar', 'danger');
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    async function removerWatchlist(itemId) {
        if (!confirm('Remover item da watchlist?')) return;

        try {
            const response = await fetch(`${API_BASE}/competitors/watchlist/${itemId}`, {
                method: 'DELETE'
            });

            const data = await response.json();

            if (data.success) {
                showAlert('Item removido da watchlist', 'success');
                carregarWatchlist();
            } else {
                showAlert(data.error || 'Erro ao remover', 'danger');
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    function escanearItem(itemId) {
        document.getElementById('scanItemId').value = itemId;
        document.querySelector('[data-bs-target="#monitor-scan"]').click();
        escanearConcorrentes();
    }

    async function escanearConcorrentes() {
        const itemId = document.getElementById('scanItemId').value.trim();
        const keywords = document.getElementById('scanKeywords').value.trim();

        if (!itemId) {
            showAlert('Informe o ID do item', 'warning');
            return;
        }

        const container = document.getElementById('scanResults');
        container.innerHTML = '<div class="text-center p-4"><div class="spinner-border"></div><p class="mt-2">Escaneando concorrentes...</p></div>';

        try {
            let url = `${API_BASE}/competitors/scan/${itemId}`;
            if (keywords) {
                url += `?keywords=${encodeURIComponent(keywords)}`;
            }

            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                renderScanResults(data);
            } else {
                container.innerHTML = `<div class="alert alert-danger">Erro: ${data.error}</div>`;
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function renderScanResults(data) {
        const container = document.getElementById('scanResults');
        const item = data.item;
        const competitors = data.competitors || [];

        let html = `
        <div class="card mb-3">
            <div class="card-header">
                <i class="bi bi-box me-2"></i>Seu Produto
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <h6>${item.title || 'Sem título'}</h6>
                        <span class="text-muted">${item.id}</span>
                    </div>
                    <div class="col-md-4 text-end">
                        <h4 class="text-primary">R$ ${formatNumber(item.price)}</h4>
                        <span class="badge ${item.position_percentile <= 30 ? 'bg-success' : item.position_percentile <= 60 ? 'bg-warning' : 'bg-danger'}">
                            Posição: Top ${item.position_percentile}%
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <i class="bi bi-people me-2"></i>Concorrentes Encontrados (${competitors.length})
            </div>
            <div class="card-body p-0">
    `;

        if (competitors.length > 0) {
            html += '<table class="table table-hover mb-0"><thead><tr><th>Vendedor</th><th>Título</th><th>Preço</th><th>Reputação</th><th>Vendas</th></tr></thead><tbody>';

            competitors.forEach((comp, i) => {
                const priceClass = comp.price < item.price ? 'text-danger' :
                    comp.price > item.price ? 'text-success' : 'text-muted';
                const priceDiff = ((comp.price - item.price) / item.price * 100).toFixed(1);

                html += `
                <tr>
                    <td>
                        <span class="badge bg-secondary">#${i + 1}</span>
                        ${comp.seller_nickname || 'Vendedor'}
                    </td>
                    <td><small>${(comp.title || '').substring(0, 40)}...</small></td>
                    <td class="${priceClass}">
                        R$ ${formatNumber(comp.price)}
                        <small class="${priceClass}">(${priceDiff > 0 ? '+' : ''}${priceDiff}%)</small>
                    </td>
                    <td>
                        <span class="badge ${comp.seller_reputation === 'platinum' ? 'bg-primary' :
                                           comp.seller_reputation === 'gold' ? 'bg-warning' : 'bg-secondary'}">
                            ${comp.seller_reputation || 'N/A'}
                        </span>
                    </td>
                    <td>${comp.sold_quantity || 0}</td>
                </tr>
            `;
            });

            html += '</tbody></table>';
        } else {
            html += '<div class="p-3 text-center text-muted">Nenhum concorrente encontrado</div>';
        }

        html += `
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <i class="bi bi-graph-up me-2"></i>Resumo de Mercado
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h5>R$ ${formatNumber(data.market_summary?.min_price || 0)}</h5>
                        <small class="text-muted">Menor Preço</small>
                    </div>
                    <div class="col-md-3">
                        <h5>R$ ${formatNumber(data.market_summary?.max_price || 0)}</h5>
                        <small class="text-muted">Maior Preço</small>
                    </div>
                    <div class="col-md-3">
                        <h5>R$ ${formatNumber(data.market_summary?.avg_price || 0)}</h5>
                        <small class="text-muted">Média</small>
                    </div>
                    <div class="col-md-3">
                        <h5>R$ ${formatNumber(data.market_summary?.median_price || 0)}</h5>
                        <small class="text-muted">Mediana</small>
                    </div>
                </div>
            </div>
        </div>
    `;

        container.innerHTML = html;
    }

    function verAnaliseItem(itemId) {
        document.getElementById('analysisItemId').value = itemId;
        document.querySelector('[data-bs-target="#monitor-analysis"]').click();
        carregarAnalise();
    }

    async function carregarAnalise() {
        const itemId = document.getElementById('analysisItemId').value.trim();

        if (!itemId) {
            showAlert('Informe o ID do item', 'warning');
            return;
        }

        const container = document.getElementById('analysisContent');
        container.innerHTML = '<div class="text-center p-4"><div class="spinner-border"></div><p class="mt-2">Carregando análise de mercado...</p></div>';

        try {
            const response = await fetch(`${API_BASE}/competitors/analysis/${itemId}`);
            const data = await response.json();

            if (data.success) {
                renderMarketAnalysis(data);
            } else {
                container.innerHTML = `<div class="alert alert-danger">Erro: ${data.error}</div>`;
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function renderMarketAnalysis(data) {
        const container = document.getElementById('analysisContent');
        const analysis = data.analysis || {};
        const distribution = analysis.price_distribution || {};
        const trends = analysis.price_trends || {};
        const recommendations = data.recommendations || [];

        let html = `
        <div class="row mb-3">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-pie-chart me-2"></i>Distribuição de Preços
                    </div>
                    <div class="card-body">
                        <canvas id="priceDistributionChart" height="200"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header">
                        <i class="bi bi-bullseye me-2"></i>Sua Posição
                    </div>
                    <div class="card-body text-center">
                        <div class="display-4 ${analysis.position_percentile <= 30 ? 'text-success' : analysis.position_percentile <= 60 ? 'text-warning' : 'text-danger'}">
                            Top ${analysis.position_percentile || 0}%
                        </div>
                        <p class="text-muted mt-2">
                            ${analysis.competitor_count || 0} concorrentes analisados
                        </p>
                        <hr>
                        <div class="row text-start">
                            <div class="col-6">
                                <small class="text-muted">Mais baratos:</small>
                                <div class="fw-bold text-danger">${analysis.competitors_cheaper || 0}</div>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">Mais caros:</small>
                                <div class="fw-bold text-success">${analysis.competitors_expensive || 0}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-graph-up me-2"></i>Tendências (7 dias)
                    </div>
                    <div class="card-body">
                        <canvas id="marketPriceTrendChart" height="180"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <i class="bi bi-calculator me-2"></i>Estatísticas de Mercado
                    </div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <tr><td>Preço Mínimo</td><td class="text-end fw-bold">R$ ${formatNumber(analysis.min_price || 0)}</td></tr>
                            <tr><td>Preço Máximo</td><td class="text-end fw-bold">R$ ${formatNumber(analysis.max_price || 0)}</td></tr>
                            <tr><td>Média</td><td class="text-end fw-bold">R$ ${formatNumber(analysis.avg_price || 0)}</td></tr>
                            <tr><td>Mediana</td><td class="text-end fw-bold">R$ ${formatNumber(analysis.median_price || 0)}</td></tr>
                            <tr><td>Desvio Padrão</td><td class="text-end fw-bold">R$ ${formatNumber(analysis.std_dev || 0)}</td></tr>
                            <tr><td>Seu Preço</td><td class="text-end fw-bold text-primary">R$ ${formatNumber(analysis.your_price || 0)}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <i class="bi bi-lightbulb me-2"></i>Recomendações
            </div>
            <div class="card-body">
    `;

        if (recommendations.length > 0) {
            recommendations.forEach(rec => {
                const typeClass = rec.type === 'success' ? 'success' : rec.type === 'warning' ? 'warning' : 'info';
                html += `
                <div class="alert alert-${typeClass} mb-2">
                    <strong>${rec.title}</strong>
                    <p class="mb-0">${rec.message}</p>
                </div>
            `;
            });
        } else {
            html += '<p class="text-muted">Sem recomendações no momento.</p>';
        }

        html += `
            </div>
        </div>
    `;

        container.innerHTML = html;

        // Renderizar gráficos
        renderPriceDistributionChart(distribution);
        renderMarketPriceTrendChart(trends);
    }

    function renderPriceDistributionChart(distribution) {
        const ctx = document.getElementById('priceDistributionChart');
        if (!ctx) return;

        const labels = Object.keys(distribution);
        const values = Object.values(distribution);

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Qtd Concorrentes',
                    data: values,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    function renderMarketPriceTrendChart(trends) {
        const ctx = document.getElementById('marketPriceTrendChart');
        if (!ctx || !trends.dates) return;

        if (marketTrendChart) {
            marketTrendChart.destroy();
        }
        marketTrendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: trends.dates,
                datasets: [{
                        label: 'Preço Mínimo',
                        data: trends.min,
                        borderColor: 'rgba(75, 192, 192, 1)',
                        fill: false
                    },
                    {
                        label: 'Preço Médio',
                        data: trends.avg,
                        borderColor: 'rgba(255, 159, 64, 1)',
                        fill: false
                    },
                    {
                        label: 'Seu Preço',
                        data: trends.yours,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderDash: [5, 5],
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: false
                    }
                }
            }
        });
    }

    async function carregarAlertas() {
        const container = document.getElementById('alertsContent');
        container.innerHTML = '<div class="text-center p-3"><div class="spinner-border spinner-border-sm"></div></div>';

        try {
            const response = await fetch(`${API_BASE}/competitors/alerts`);
            const data = await response.json();

            if (data.success && data.alerts) {
                renderAlertas(data.alerts);

                // Atualizar contador de alertas não lidos
                const unread = data.alerts.filter(a => !a.read_at).length;
                const badge = document.getElementById('unreadAlertsCount');
                if (unread > 0) {
                    badge.textContent = unread;
                    badge.style.display = 'inline';
                } else {
                    badge.style.display = 'none';
                }
            } else {
                container.innerHTML = '<div class="alert alert-info">Nenhum alerta encontrado.</div>';
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function renderAlertas(alerts) {
        const container = document.getElementById('alertsContent');

        // Filtrar por severidade
        let filteredAlerts = alerts;
        if (currentAlertFilter !== 'all') {
            filteredAlerts = alerts.filter(a => a.severity === currentAlertFilter);
        }

        if (filteredAlerts.length === 0) {
            container.innerHTML = '<div class="alert alert-info">Nenhum alerta encontrado.</div>';
            return;
        }

        let html = '<div class="list-group">';

        filteredAlerts.forEach(alert => {
            const severityClass = alert.severity === 'critical' ? 'danger' :
                alert.severity === 'high' ? 'warning' : 'info';
            const severityIcon = alert.severity === 'critical' ? 'exclamation-triangle-fill' :
                alert.severity === 'high' ? 'exclamation-circle-fill' : 'info-circle-fill';
            const readClass = alert.read_at ? 'text-muted' : '';

            html += `
            <div class="list-group-item ${readClass}">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="badge bg-${severityClass} me-2">
                            <i class="bi bi-${severityIcon}"></i>
                            ${alert.severity.toUpperCase()}
                        </span>
                        <strong>${alert.title || alert.alert_type}</strong>
                    </div>
                    <small class="text-muted">${alert.created_at}</small>
                </div>
                <p class="mb-1 mt-2">${alert.message}</p>
                <small class="text-muted">Item: ${alert.item_id}</small>
            </div>
        `;
        });

        html += '</div>';
        container.innerHTML = html;
    }

    function filtrarAlertas(filter) {
        currentAlertFilter = filter;

        // Atualizar botões ativos
        document.querySelectorAll('#monitor-alerts .btn-group .btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.textContent.toLowerCase().includes(filter) ||
                (filter === 'all' && btn.textContent.includes('Todos'))) {
                btn.classList.add('active');
            }
        });

        carregarAlertas();
    }

    async function marcarTodosAlertasLidos() {
        try {
            const response = await fetch(`${API_BASE}/competitors/alerts/read`, {
                method: 'POST'
            });

            const data = await response.json();

            if (data.success) {
                showAlert('Alertas marcados como lidos', 'success');
                carregarAlertas();
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    // Event listener para carregar alertas quando abrir a aba
    document.addEventListener('DOMContentLoaded', function() {
        const monitorModal = document.getElementById('competitorMonitorModal');
        if (monitorModal) {
            monitorModal.addEventListener('shown.bs.tab', function(e) {
                const target = e.target.getAttribute('data-bs-target');
                if (target === '#monitor-alerts') {
                    carregarAlertas();
                } else if (target === '#monitor-watchlist') {
                    carregarWatchlist();
                }
            });
        }
    });

    // ========================================
    // Funções de IA Preditiva de Preços
    // ========================================

    function abrirAIPricing() {
        const modal = new bootstrap.Modal(document.getElementById('aiPricingModal'));
        modal.show();
    }

    async function obterSugestaoIA() {
        const itemId = document.getElementById('aiSuggestItemId').value.trim();
        const goal = document.getElementById('aiGoal').value;

        if (!itemId) {
            showAlert('Informe o ID do item', 'warning');
            return;
        }

        const container = document.getElementById('aiSuggestResults');
        container.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary"></div><p class="mt-2">Analisando com IA...</p></div>';

        try {
            const data = await requestJson('/api/ai/pricing/suggest', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    item_id: itemId,
                    goal: goal
                })
            });

            if (data.success && data.data) {
                renderSugestaoIA(data.data);
            } else {
                container.innerHTML = `<div class="alert alert-danger">Erro: ${data.error || 'Falha na análise'}</div>`;
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function renderSugestaoIA(data) {
        const container = document.getElementById('aiSuggestResults');
        const priceDiff = data.suggested_price - data.current_price;
        const priceDiffPercent = ((priceDiff / data.current_price) * 100).toFixed(1);
        const priceClass = priceDiff > 0 ? 'text-success' : priceDiff < 0 ? 'text-danger' : 'text-muted';

        let html = `
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <i class="bi bi-lightbulb me-2"></i>Recomendação da IA
                    </div>
                    <div class="card-body text-center">
                        <h6>Preço Atual</h6>
                        <h3 class="text-muted">R$ ${formatNumber(data.current_price)}</h3>
                        <i class="bi bi-arrow-down fs-2 text-primary my-2"></i>
                        <h6>Preço Sugerido</h6>
                        <h2 class="text-primary">R$ ${formatNumber(data.suggested_price)}</h2>
                        <span class="badge ${priceDiff > 0 ? 'bg-success' : priceDiff < 0 ? 'bg-danger' : 'bg-secondary'} fs-6">
                            ${priceDiff > 0 ? '+' : ''}${priceDiffPercent}% (${priceDiff > 0 ? '+' : ''}R$ ${formatNumber(priceDiff)})
                        </span>
                        <hr>
                        <p><strong>Estratégia:</strong> ${data.strategy || 'balanced'}</p>
                        <p><strong>Confiança:</strong> ${Math.round((data.confidence || 0) * 100)}%</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">
                        <i class="bi bi-bar-chart me-2"></i>Resultados Esperados
                    </div>
                    <div class="card-body">
                        ${data.expected_results ? `
                            <table class="table table-sm">
                                <tr>
                                    <td>Variação Volume</td>
                                    <td class="fw-bold ${(data.expected_results.volume_change || 0) >= 0 ? 'text-success' : 'text-danger'}">
                                        ${data.expected_results.volume_change || 0}%
                                    </td>
                                </tr>
                                <tr>
                                    <td>Variação Receita</td>
                                    <td class="fw-bold ${(data.expected_results.revenue_change || 0) >= 0 ? 'text-success' : 'text-danger'}">
                                        ${data.expected_results.revenue_change || 0}%
                                    </td>
                                </tr>
                                <tr>
                                    <td>Nova Margem Estimada</td>
                                    <td class="fw-bold">${data.expected_results.new_margin || 'N/A'}%</td>
                                </tr>
                            </table>
                        ` : '<p class="text-muted">Dados não disponíveis</p>'}
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-bullseye me-2"></i>Posição no Mercado
                    </div>
                    <div class="card-body">
                        ${data.market_position ? `
                            <div class="row text-center">
                                <div class="col-6">
                                    <h5 class="${data.market_position.percentile <= 30 ? 'text-success' : 'text-warning'}">
                                        Top ${data.market_position.percentile || 50}%
                                    </h5>
                                    <small>Posição</small>
                                </div>
                                <div class="col-6">
                                    <h5>${data.market_position.competitor_count || 0}</h5>
                                    <small>Concorrentes</small>
                                </div>
                            </div>
                        ` : '<p class="text-muted">Dados não disponíveis</p>'}
                    </div>
                </div>
            </div>
        </div>

        ${data.reasoning ? `
            <div class="alert alert-light mt-3">
                <i class="bi bi-chat-left-text me-2"></i>
                <strong>Raciocínio:</strong> ${data.reasoning}
            </div>
        ` : ''}

        <div class="text-end mt-3">
            <button class="btn btn-success" data-pricing-cmd="aplicarPrecoSugerido" data-pricing-args-raw="'${data.item_id}', ${data.suggested_price}">
                <i class="bi bi-check-circle me-1"></i>Aplicar Preço Sugerido
            </button>
        </div>
    `;

        container.innerHTML = html;
    }

    async function analisarElasticidade() {
        const itemId = document.getElementById('aiElasticityItemId').value.trim();

        if (!itemId) {
            showAlert('Informe o ID do item', 'warning');
            return;
        }

        const container = document.getElementById('aiElasticityResults');
        container.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary"></div><p class="mt-2">Calculando elasticidade...</p></div>';

        try {
            const data = await requestJson('/api/ai/pricing/elasticity', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    item_id: itemId
                })
            });

            if (data.success && data.data) {
                renderElasticidade(data.data);
            } else {
                container.innerHTML = `<div class="alert alert-danger">Erro: ${data.error || 'Dados insuficientes para análise'}</div>`;
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function renderElasticidade(data) {
        const container = document.getElementById('aiElasticityResults');
        const elasticity = data.elasticity_coefficient || 0;
        const isElastic = Math.abs(elasticity) > 1;

        let html = `
        <div class="row">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h6>Coeficiente de Elasticidade</h6>
                        <h1 class="${isElastic ? 'text-danger' : 'text-success'}">${elasticity.toFixed(2)}</h1>
                        <span class="badge ${isElastic ? 'bg-danger' : 'bg-success'} fs-6">
                            ${isElastic ? 'Demanda Elástica' : 'Demanda Inelástica'}
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">Interpretação</div>
                    <div class="card-body">
                        <p><strong>${data.explanation || data.interpretation || ''}</strong></p>
                        ${isElastic ? `
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Atenção:</strong> Produto sensível a preço. Pequenas reduções podem aumentar significativamente as vendas.
                            </div>
                        ` : `
                            <div class="alert alert-success mb-0">
                                <i class="bi bi-check-circle me-2"></i>
                                <strong>Oportunidade:</strong> Produto pouco sensível a preço. Você pode aumentar o preço sem grandes perdas de volume.
                            </div>
                        `}
                    </div>
                </div>
            </div>
        </div>

        ${data.scenarios && data.scenarios.length > 0 ? `
            <div class="card mt-3">
                <div class="card-header">
                    <i class="bi bi-calculator me-2"></i>Cenários de Preço
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Variação Preço</th>
                                <th>Novo Preço</th>
                                <th>Var. Volume Esperada</th>
                                <th>Efeito Líquido na Receita</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.scenarios.map(s => {
                                const netEffect = parseFloat(String(s.net_effect || s.net_revenue_effect || '0').replace('%', ''));
                                return `
                                    <tr class="${netEffect > 0 ? 'table-success' : netEffect < 0 ? 'table-danger' : ''}">
                                        <td>${s.price_change}</td>
                                        <td>R$ ${formatNumber(s.new_price)}</td>
                                        <td>${s.expected_volume_change}</td>
                                        <td class="fw-bold ${netEffect >= 0 ? 'text-success' : 'text-danger'}">
                                            ${s.net_effect || s.net_revenue_effect}
                                        </td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        ` : ''}

        ${data.recommendations && data.recommendations.length > 0 ? `
            <div class="alert alert-info mt-3">
                <i class="bi bi-lightbulb me-2"></i>
                <strong>Recomendações:</strong>
                <ul class="mb-0 mt-2">
                    ${data.recommendations.map(r => `<li>${r}</li>`).join('')}
                </ul>
            </div>
        ` : ''}
    `;

        container.innerHTML = html;
    }

    async function preverReceita() {
        const itemId = document.getElementById('aiForecastItemId').value.trim();
        const pricesInput = document.getElementById('aiForecastPrices').value.trim();

        if (!itemId) {
            showAlert('Informe o ID do item', 'warning');
            return;
        }

        const pricePoints = pricesInput.split(',').map(p => parseFloat(p.trim())).filter(p => !isNaN(p));

        if (pricePoints.length === 0) {
            showAlert('Informe pelo menos um preço válido', 'warning');
            return;
        }

        const container = document.getElementById('aiForecastResults');
        container.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary"></div><p class="mt-2">Calculando previsões...</p></div>';

        try {
            const data = await requestJson('/api/ai/pricing/forecast', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    item_id: itemId,
                    price_points: pricePoints
                })
            });

            if (data.success && data.data) {
                renderPrevisaoReceita(data.data);
            } else {
                container.innerHTML = `<div class="alert alert-danger">Erro: ${data.error || 'Falha na previsão'}</div>`;
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function renderPrevisaoReceita(data) {
        const container = document.getElementById('aiForecastResults');
        const scenarios = data.scenarios || data.forecasts || [];
        const best = data.best_scenario || scenarios[0];

        let html = `
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h6>Melhor Cenário</h6>
                        <h2>R$ ${formatNumber(best?.price || 0)}</h2>
                        <p class="mb-0">Receita: R$ ${formatNumber(best?.estimated_revenue || 0)}</p>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card h-100">
                    <div class="card-body">
                        <canvas id="forecastChart" height="150"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Preço</th>
                        <th>Volume Estimado</th>
                        <th>Receita Estimada (30 dias)</th>
                        <th>Confiança</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    ${scenarios.map(s => {
                        const isBest = s.price === best?.price;
                        return `
                            <tr class="${isBest ? 'table-success' : ''}">
                                <td class="fw-bold">R$ ${formatNumber(s.price)}</td>
                                <td>${s.estimated_volume} un</td>
                                <td>R$ ${formatNumber(s.estimated_revenue)}</td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-primary" style="width: ${(s.confidence || 0.5) * 100}%">
                                            ${Math.round((s.confidence || 0.5) * 100)}%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    ${isBest ? '<span class="badge bg-success">MELHOR</span>' : ''}
                                </td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        </div>
    `;

        container.innerHTML = html;

        // Renderizar gráfico
        setTimeout(() => {
            const ctx = document.getElementById('forecastChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: scenarios.map(s => 'R$ ' + formatNumber(s.price)),
                        datasets: [{
                            label: 'Receita Estimada',
                            data: scenarios.map(s => s.estimated_revenue),
                            backgroundColor: scenarios.map(s => s.price === best?.price ? 'rgba(40, 167, 69, 0.7)' : 'rgba(54, 162, 235, 0.5)'),
                            borderColor: scenarios.map(s => s.price === best?.price ? 'rgba(40, 167, 69, 1)' : 'rgba(54, 162, 235, 1)'),
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }
        }, 100);
    }

    async function calcularPricingDinamico() {
        const itemId = document.getElementById('aiDynamicItemId').value.trim();
        const minMargin = parseFloat(document.getElementById('aiDynamicMinMargin').value) / 100;
        const aggressive = document.getElementById('aiDynamicAggressive').value === 'true';

        if (!itemId) {
            showAlert('Informe o ID do item', 'warning');
            return;
        }

        const container = document.getElementById('aiDynamicResults');
        container.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary"></div><p class="mt-2">Calculando preço ótimo...</p></div>';

        try {
            const data = await requestJson(`/api/pricing/${ACCOUNT_ID}/calculate/${itemId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    min_margin: minMargin,
                    aggressive: aggressive
                })
            });

            if (data.success) {
                renderPricingDinamico(data);
            } else {
                container.innerHTML = `<div class="alert alert-danger">Erro: ${data.error || 'Falha no cálculo'}</div>`;
            }
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function renderPricingDinamico(data) {
        const container = document.getElementById('aiDynamicResults');
        const changePercent = data.change_percent || 0;
        const changeClass = changePercent > 0 ? 'text-success' : changePercent < 0 ? 'text-danger' : 'text-muted';

        let html = `
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <i class="bi bi-lightning me-2"></i>Preço Ótimo Calculado
                    </div>
                    <div class="card-body text-center">
                        <div class="row">
                            <div class="col-5">
                                <h6 class="text-muted">Atual</h6>
                                <h3>R$ ${formatNumber(data.current_price || 0)}</h3>
                            </div>
                            <div class="col-2 d-flex align-items-center justify-content-center">
                                <i class="bi bi-arrow-right fs-2 text-primary"></i>
                            </div>
                            <div class="col-5">
                                <h6 class="text-primary">Ótimo</h6>
                                <h3 class="text-primary">R$ ${formatNumber(data.optimal_price || 0)}</h3>
                            </div>
                        </div>
                        <hr>
                        <span class="badge ${changePercent > 0 ? 'bg-success' : changePercent < 0 ? 'bg-danger' : 'bg-secondary'} fs-5">
                            ${changePercent > 0 ? '+' : ''}${changePercent}%
                            (${changePercent > 0 ? '+' : ''}R$ ${formatNumber(data.change_amount || 0)})
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <i class="bi bi-info-circle me-2"></i>Análise
                    </div>
                    <div class="card-body">
                        <p><strong>Motivo:</strong> ${data.reason || 'Otimização baseada em concorrência'}</p>

                        ${data.market_data ? `
                            <hr>
                            <h6>Dados de Mercado</h6>
                            <table class="table table-sm">
                                <tr><td>Menor preço</td><td class="fw-bold">R$ ${formatNumber(data.market_data.lowest_price)}</td></tr>
                                <tr><td>2º menor</td><td class="fw-bold">R$ ${formatNumber(data.market_data.second_lowest)}</td></tr>
                                <tr><td>Média</td><td class="fw-bold">R$ ${formatNumber(data.market_data.average_price)}</td></tr>
                                <tr><td>Concorrentes</td><td class="fw-bold">${data.market_data.competitors_count}</td></tr>
                            </table>
                        ` : ''}
                    </div>
                </div>
            </div>
        </div>

        ${data.constraints ? `
            <div class="alert alert-light mt-3">
                <i class="bi bi-shield-check me-2"></i>
                <strong>Limites Aplicados:</strong>
                Margem mínima: ${(data.constraints.min_margin * 100).toFixed(0)}% |
                Preço mínimo: R$ ${formatNumber(data.constraints.min_price)} |
                Desconto máximo: ${(data.constraints.max_discount * 100).toFixed(0)}%
            </div>
        ` : ''}

        <div class="text-end mt-3">
            <button class="btn btn-success" data-pricing-cmd="aplicarPrecoSugerido" data-pricing-args-raw="'${data.item_id || ''}', ${data.optimal_price || 0}">
                <i class="bi bi-check-circle me-1"></i>Aplicar Preço Ótimo
            </button>
        </div>
    `;

        container.innerHTML = html;
    }

    async function aplicarPrecoSugerido(itemId, preco) {
        if (!confirm(`Aplicar preço R$ ${formatNumber(preco)} ao item ${itemId}?`)) return;

        try {
            const response = await fetch(`${API_BASE}/apply/${itemId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    new_price: preco
                })
            });

            const data = await response.json();

            if (data.success) {
                showAlert('Preço aplicado com sucesso!', 'success');
                refreshItems();
            } else {
                showAlert(data.error || 'Erro ao aplicar preço', 'danger');
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    // ========================================
    // PHASE 3: FUNÇÕES AVANÇADAS DE PRECIFICAÇÃO
    // ========================================

    // --- Motor de Regras ---
    function abrirRulesEngine() {
        new bootstrap.Modal(document.getElementById('rulesEngineModal')).show();
        loadRulesList();
        loadRuleTemplates();
    }

    async function loadRulesList() {
        const container = document.getElementById('rulesListContainer');
        const filterType = document.getElementById('rulesFilterType').value;

        try {
            let url = `${API_BASE}/rules-engine/rules`;
            if (filterType) url += `?type=${filterType}`;

            const response = await fetch(url);
            const data = await response.json();

            if (!data.rules || data.rules.length === 0) {
                container.innerHTML = '<div class="alert alert-info">Nenhuma regra criada ainda.</div>';
                return;
            }

            let html = '<div class="list-group">';
            data.rules.forEach(rule => {
                const statusBadge = rule.active ?
                    '<span class="badge bg-success">Ativa</span>' :
                    '<span class="badge bg-secondary">Inativa</span>';

                html += `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1">${rule.name} ${statusBadge}</h6>
                            <p class="mb-1 text-muted small">${rule.description || ''}</p>
                            <span class="badge bg-primary">${getRuleTypeName(rule.rule_type)}</span>
                            <span class="badge bg-light text-dark">Prioridade: ${rule.priority}</span>
                        </div>
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-primary" data-pricing-cmd="toggleEngineRule" data-pricing-args-raw="${rule.id}, ${!rule.active}" title="${rule.active ? 'Desativar' : 'Ativar'}">
                                <i class="bi bi-${rule.active ? 'pause' : 'play'}"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-success" data-pricing-cmd="executeRuleForItems" data-pricing-args-raw="${rule.id}" title="Executar">
                                <i class="bi bi-lightning"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" data-pricing-cmd="deleteEngineRule" data-pricing-args-raw="${rule.id}" title="Excluir">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    function getRuleTypeName(type) {
        const types = {
            'match_competitor': 'Igualar Concorrente',
            'floor_ceiling': 'Piso/Teto',
            'time_based': 'Por Horário',
            'margin_based': 'Por Margem',
            'stock_based': 'Por Estoque',
            'velocity_based': 'Por Velocidade',
            'category_position': 'Por Posição'
        };
        return types[type] || type;
    }

    async function loadRuleTemplates() {
        const container = document.getElementById('rulesTemplatesContainer');

        try {
            const response = await fetch(`${API_BASE}/rules-engine/templates`);
            const data = await response.json();

            if (!data.templates) return;

            let html = '';
            data.templates.forEach(template => {
                html += `
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h6 class="card-title">${template.name}</h6>
                            <p class="card-text small text-muted">${template.description}</p>
                            <span class="badge bg-primary">${getRuleTypeName(template.type)}</span>
                        </div>
                        <div class="card-footer">
                            <button class="btn btn-sm btn-outline-primary" data-pricing-cmd="useRuleTemplate" data-pricing-args='["${template.id}"]'>
                                <i class="bi bi-plus me-1"></i>Usar Template
                            </button>
                        </div>
                    </div>
                </div>`;
            });
            container.innerHTML = html;
        } catch (err) {
            console.error('Erro ao carregar templates:', err);
        }
    }

    function updateRuleConfigUI() {
        const type = document.getElementById('ruleType').value;
        const container = document.getElementById('ruleConfigContainer');

        const configs = {
            'match_competitor': `
            <label class="form-label">Configuração: Igualar Concorrente</label>
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small">Diferença</label>
                    <div class="input-group input-group-sm">
                        <input type="number" class="form-control" id="configDifference" value="-1">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Margem Mínima</label>
                    <div class="input-group input-group-sm">
                        <input type="number" class="form-control" id="configMinMargin" value="10">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
            </div>`,
            'floor_ceiling': `
            <label class="form-label">Configuração: Piso e Teto</label>
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label small">Preço Mínimo (Piso)</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">R$</span>
                        <input type="number" class="form-control" id="configFloor" step="0.01">
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Preço Máximo (Teto)</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">R$</span>
                        <input type="number" class="form-control" id="configCeiling" step="0.01">
                    </div>
                </div>
            </div>`,
            'margin_based': `
            <label class="form-label">Configuração: Por Margem</label>
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small">Margem Alvo</label>
                    <div class="input-group input-group-sm">
                        <input type="number" class="form-control" id="configTargetMargin" value="25">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
            </div>`
        };

        container.innerHTML = configs[type] || '<p class="text-muted small">Selecione o tipo de regra para configurar.</p>';
    }

    document.getElementById('createRuleForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();

        const config = {};
        const type = document.getElementById('ruleType').value;

        if (type === 'match_competitor') {
            config.price_difference_percent = parseFloat(document.getElementById('configDifference')?.value || -1);
            config.min_margin = parseFloat(document.getElementById('configMinMargin')?.value || 10) / 100;
        } else if (type === 'floor_ceiling') {
            config.floor = parseFloat(document.getElementById('configFloor')?.value || 0);
            config.ceiling = parseFloat(document.getElementById('configCeiling')?.value || 0);
        } else if (type === 'margin_based') {
            config.target_margin = parseFloat(document.getElementById('configTargetMargin')?.value || 25) / 100;
        }

        try {
            const response = await fetch(`${API_BASE}/rules-engine/rules`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    name: document.getElementById('ruleName').value,
                    rule_type: type,
                    priority: parseInt(document.getElementById('rulePriority').value),
                    description: document.getElementById('ruleDescription').value,
                    config: config
                })
            });

            const data = await response.json();

            if (data.success) {
                showAlert('Regra criada com sucesso!', 'success');
                loadRulesList();
                document.getElementById('createRuleForm').reset();
                document.querySelector('[data-bs-target="#rules-list"]').click();
            } else {
                showAlert(data.error || 'Erro ao criar regra', 'danger');
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    });

    async function toggleEngineRule(ruleId, active) {
        try {
            const response = await fetch(`${API_BASE}/rules-engine/rules/${ruleId}/toggle`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    active: active
                })
            });

            const data = await response.json();
            if (data.success) {
                showAlert(`Regra ${active ? 'ativada' : 'desativada'}!`, 'success');
                loadRulesList();
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    async function deleteEngineRule(ruleId) {
        if (!confirm('Excluir esta regra permanentemente?')) return;

        try {
            const response = await fetch(`${API_BASE}/rules-engine/rules/${ruleId}`, {
                method: 'DELETE'
            });

            const data = await response.json();
            if (data.success) {
                showAlert('Regra excluída!', 'success');
                loadRulesList();
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    async function executeAllEngineRules() {
        if (!confirm('Executar todas as regras ativas? Isso pode alterar vários preços.')) return;

        showAlert('Executando regras...', 'info');

        try {
            const response = await fetch(`${API_BASE}/rules-engine/execute-all`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            const data = await response.json();

            if (data.success) {
                showAlert(`Execução concluída! ${data.applied_count || 0} preços alterados.`, 'success');
            } else {
                showAlert(data.error || 'Erro na execução', 'danger');
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    async function simulateEngineRules() {
        const itemIds = document.getElementById('simulateItemIds').value
            .split(',').map(id => id.trim()).filter(id => id);

        if (!itemIds.length) {
            showAlert('Informe pelo menos um ID de item', 'warning');
            return;
        }

        const container = document.getElementById('simulationResults');
        container.innerHTML = '<div class="text-center py-3"><div class="spinner-border"></div></div>';

        try {
            const response = await fetch(`${API_BASE}/rules-engine/simulate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    item_ids: itemIds
                })
            });

            const data = await response.json();

            if (!data.results || !data.results.length) {
                container.innerHTML = '<div class="alert alert-info">Nenhuma regra aplicável encontrada.</div>';
                return;
            }

            let html = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Item</th><th>Regra</th><th>Preço Atual</th><th>Preço Sugerido</th></tr></thead><tbody>';

            data.results.forEach(r => {
                html += `<tr>
                <td>${r.item_id}</td>
                <td>${r.rule_name || 'N/A'}</td>
                <td>R$ ${formatNumber(r.current_price)}</td>
                <td>R$ ${formatNumber(r.suggested_price)}</td>
            </tr>`;
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Erro: ${err.message}</div>`;
        }
    }

    // --- Agendamento de Preços ---
    function abrirScheduledPrices() {
        new bootstrap.Modal(document.getElementById('scheduledPricesModal')).show();
        loadScheduleCalendar();
        loadSchedulesList();
        loadCampaignsList();
    }

    async function loadScheduleCalendar() {
        const container = document.getElementById('scheduleCalendar');

        try {
            const response = await fetch(`${API_BASE}/schedules/calendar`);
            const data = await response.json();

            if (!data.events || !data.events.length) {
                container.innerHTML = '<p class="text-muted text-center">Nenhum agendamento no período.</p>';
                return;
            }

            // Simples visualização de lista (pode ser expandido com FullCalendar)
            let html = '<div class="list-group">';
            data.events.forEach(event => {
                const date = new Date(event.scheduled_at);
                html += `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between">
                        <div>
                            <strong>${date.toLocaleDateString('pt-BR')} ${date.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'})}</strong>
                            <br><small class="text-muted">${event.item_id}</small>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-success">R$ ${formatNumber(event.new_price)}</span>
                        </div>
                    </div>
                </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
        }
    }

    async function loadSchedulesList() {
        const container = document.getElementById('schedulesListContainer');

        try {
            const response = await fetch(`${API_BASE}/schedules/list`);
            const data = await response.json();

            if (!data.schedules || !data.schedules.length) {
                container.innerHTML = '<div class="alert alert-info">Nenhum agendamento ativo.</div>';
                return;
            }

            let html = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Item</th><th>Novo Preço</th><th>Data/Hora</th><th>Status</th><th></th></tr></thead><tbody>';

            data.schedules.forEach(s => {
                const date = new Date(s.scheduled_at);
                const statusBadge = {
                    'pending': '<span class="badge bg-warning">Pendente</span>',
                    'executed': '<span class="badge bg-success">Executado</span>',
                    'cancelled': '<span class="badge bg-secondary">Cancelado</span>'
                } [s.status] || s.status;

                html += `<tr>
                <td>${s.item_id}</td>
                <td>R$ ${formatNumber(s.new_price)}</td>
                <td>${date.toLocaleString('pt-BR')}</td>
                <td>${statusBadge}</td>
                <td>
                    ${s.status === 'pending' ? `<button class="btn btn-xs btn-outline-danger" data-pricing-cmd="cancelSchedule" data-pricing-args-raw="${s.id}"><i class="bi bi-x"></i></button>` : ''}
                </td>
            </tr>`;
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
        }
    }

    function toggleScheduleRecurrence() {
        const type = document.getElementById('scheduleType').value;
        document.getElementById('recurrenceOptions').classList.toggle('d-none', type !== 'recurrent');
    }

    document.getElementById('createScheduleForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();

        const data = {
            item_id: document.getElementById('scheduleItemId').value,
            new_price: parseFloat(document.getElementById('schedulePrice').value),
            scheduled_at: document.getElementById('scheduleDateTime').value
        };

        if (document.getElementById('scheduleType').value === 'recurrent') {
            data.recurrence = {
                pattern: document.getElementById('recurrencePattern').value,
                end_date: document.getElementById('recurrenceEnd').value
            };
        }

        const rollbackAt = document.getElementById('scheduleRollback').value;
        if (rollbackAt) {
            data.rollback_at = rollbackAt;
            data.rollback_price = parseFloat(document.getElementById('scheduleRollbackPrice').value);
        }

        try {
            const response = await fetch(`${API_BASE}/schedules/create`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showAlert('Agendamento criado!', 'success');
                document.getElementById('createScheduleForm').reset();
                loadScheduleCalendar();
                loadSchedulesList();
            } else {
                showAlert(result.error || 'Erro ao criar agendamento', 'danger');
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    });

    async function cancelSchedule(scheduleId) {
        if (!confirm('Cancelar este agendamento?')) return;

        try {
            const response = await fetch(`${API_BASE}/schedules/${scheduleId}/cancel`, {
                method: 'POST'
            });

            const data = await response.json();
            if (data.success) {
                showAlert('Agendamento cancelado!', 'success');
                loadSchedulesList();
                loadScheduleCalendar();
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    async function loadCampaignsList() {
        const container = document.getElementById('campaignsListContainer');

        try {
            const response = await fetch(`${API_BASE}/schedules/campaigns`);
            const data = await response.json();

            if (!data.campaigns || !data.campaigns.length) {
                container.innerHTML = '<div class="alert alert-info">Nenhuma campanha criada.</div>';
                return;
            }

            let html = '<div class="list-group">';
            data.campaigns.forEach(c => {
                const start = new Date(c.start_date).toLocaleDateString('pt-BR');
                const end = new Date(c.end_date).toLocaleDateString('pt-BR');

                html += `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">${c.name}</h6>
                            <small class="text-muted">${start} - ${end} | ${c.item_count || 0} itens</small>
                        </div>
                        <span class="badge bg-${c.status === 'active' ? 'success' : 'secondary'}">${c.status}</span>
                    </div>
                </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
        }
    }

    // --- Analytics Avançado ---
    function abrirPriceAnalytics() {
        new bootstrap.Modal(document.getElementById('priceAnalyticsModal')).show();
        loadAnalyticsDashboard();
    }

    async function loadAnalyticsDashboard() {
        const container = document.getElementById('analyticsMetrics');
        const period = document.getElementById('analyticsPeriod').value;

        try {
            const response = await fetch(`${API_BASE}/analytics/dashboard?period=${period}`);
            const data = await response.json();

            if (!data.metrics) return;

            const m = data.metrics;
            container.innerHTML = `
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h4>${m.total_changes || 0}</h4>
                        <small>Alterações de Preço</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h4>${(m.avg_change_percent || 0).toFixed(1)}%</h4>
                        <small>Variação Média</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body text-center">
                        <h4>R$ ${formatNumber(m.revenue_impact || 0)}</h4>
                        <small>Impacto na Receita</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning">
                    <div class="card-body text-center">
                        <h4>${m.items_optimized || 0}</h4>
                        <small>Itens Otimizados</small>
                    </div>
                </div>
            </div>
        `;
        } catch (err) {
            container.innerHTML = `<div class="col-12"><div class="alert alert-danger">${err.message}</div></div>`;
        }
    }

    async function loadPriceTrend() {
        const itemId = document.getElementById('trendItemId').value;
        const days = document.getElementById('trendDays').value;

        if (!itemId) {
            showAlert('Informe o ID do item', 'warning');
            return;
        }

        try {
            const response = await fetch(`${API_BASE}/analytics/trend/${itemId}?days=${days}`);
            const data = await response.json();

            if (data.trend && window.Chart) {
                const ctx = document.getElementById('trendChart').getContext('2d');

                if (window.trendChartInstance) {
                    window.trendChartInstance.destroy();
                }

                window.trendChartInstance = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.trend.map(t => t.date),
                        datasets: [{
                            label: 'Preço',
                            data: data.trend.map(t => t.price),
                            borderColor: '#0d6efd',
                            fill: false
                        }]
                    }
                });
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    async function analyzeElasticity() {
        const itemId = document.getElementById('elasticityItemId').value;
        const container = document.getElementById('elasticityResults');

        if (!itemId) {
            showAlert('Informe o ID do item', 'warning');
            return;
        }

        container.innerHTML = '<div class="text-center py-3"><div class="spinner-border"></div></div>';

        try {
            const response = await fetch(`${API_BASE}/analytics/elasticity/${itemId}`);
            const data = await response.json();

            if (!data.elasticity) {
                container.innerHTML = '<div class="alert alert-warning">Dados insuficientes para calcular elasticidade.</div>';
                return;
            }

            const e = data.elasticity;
            const interpretation = e.coefficient < -1 ? 'Elástica (sensível a preço)' :
                e.coefficient > -1 && e.coefficient < 0 ? 'Inelástica (pouco sensível)' :
                'Neutra';

            container.innerHTML = `
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Análise de Elasticidade</h5>
                    <div class="row">
                        <div class="col-md-4 text-center">
                            <h3>${e.coefficient.toFixed(2)}</h3>
                            <small class="text-muted">Coeficiente</small>
                        </div>
                        <div class="col-md-8">
                            <p><strong>Interpretação:</strong> ${interpretation}</p>
                            <p class="small text-muted">${e.recommendation || ''}</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
        }
    }

    async function calculateROI() {
        const itemId = document.getElementById('roiItemId').value;
        const oldPrice = document.getElementById('roiOldPrice').value;
        const newPrice = document.getElementById('roiNewPrice').value;
        const period = document.getElementById('roiPeriod').value;
        const container = document.getElementById('roiResults');

        if (!itemId || !oldPrice || !newPrice) {
            showAlert('Preencha todos os campos', 'warning');
            return;
        }

        container.innerHTML = '<div class="text-center py-3"><div class="spinner-border"></div></div>';

        try {
            const response = await fetch(`${API_BASE}/analytics/roi`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    item_id: itemId,
                    old_price: parseFloat(oldPrice),
                    new_price: parseFloat(newPrice),
                    period_days: parseInt(period)
                })
            });

            const data = await response.json();

            if (!data.roi) {
                container.innerHTML = '<div class="alert alert-warning">Não foi possível calcular o ROI.</div>';
                return;
            }

            const r = data.roi;
            container.innerHTML = `
            <div class="card">
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-4">
                            <h4 class="${r.revenue_change >= 0 ? 'text-success' : 'text-danger'}">
                                ${r.revenue_change >= 0 ? '+' : ''}R$ ${formatNumber(r.revenue_change)}
                            </h4>
                            <small>Impacto na Receita</small>
                        </div>
                        <div class="col-md-4">
                            <h4>${r.roi_percent.toFixed(1)}%</h4>
                            <small>ROI Estimado</small>
                        </div>
                        <div class="col-md-4">
                            <h4>${r.volume_change >= 0 ? '+' : ''}${r.volume_change}</h4>
                            <small>Variação de Vendas</small>
                        </div>
                    </div>
                </div>
            </div>
        `;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
        }
    }

    async function forecastPrice() {
        const itemId = document.getElementById('forecastItemId').value;
        const days = document.getElementById('forecastDays').value;
        const container = document.getElementById('forecastResults');

        if (!itemId) {
            showAlert('Informe o ID do item', 'warning');
            return;
        }

        container.innerHTML = '<div class="text-center py-3"><div class="spinner-border"></div></div>';

        try {
            const response = await fetch(`${API_BASE}/analytics/forecast`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    prices: [{
                        item_id: itemId,
                        days: parseInt(days)
                    }]
                })
            });

            const data = await response.json();

            if (!data.forecasts || !data.forecasts.length) {
                container.innerHTML = '<div class="alert alert-warning">Dados insuficientes para previsão.</div>';
                return;
            }

            const f = data.forecasts[0];
            container.innerHTML = `
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Previsão de Preço - ${days} dias</h5>
                    <div class="row text-center">
                        <div class="col-md-4">
                            <h4>R$ ${formatNumber(f.current_price)}</h4>
                            <small class="text-muted">Preço Atual</small>
                        </div>
                        <div class="col-md-4">
                            <h4 class="text-primary">R$ ${formatNumber(f.predicted_price)}</h4>
                            <small class="text-muted">Preço Previsto</small>
                        </div>
                        <div class="col-md-4">
                            <h4>${f.confidence || 0}%</h4>
                            <small class="text-muted">Confiança</small>
                        </div>
                    </div>
                </div>
            </div>
        `;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
        }
    }

    // --- Editor em Massa ---
    function abrirBulkEditor() {
        new bootstrap.Modal(document.getElementById('bulkEditorModal')).show();
        loadBulkBatchesHistory();
    }

    function updateBulkValueLabel() {
        const operation = document.getElementById('bulkOperation').value;
        const label = document.getElementById('bulkValueLabel');
        const suffix = document.getElementById('bulkValueSuffix');

        if (operation.includes('percent') || operation === 'set_margin') {
            label.textContent = 'Percentual';
            suffix.textContent = '%';
        } else if (operation.includes('fixed') || operation === 'set_price') {
            label.textContent = 'Valor';
            suffix.textContent = 'R$';
        } else {
            label.textContent = 'Valor';
            suffix.textContent = '';
        }
    }

    async function previewBulkEdit() {
        const container = document.getElementById('bulkPreviewContainer');
        const tbody = document.querySelector('#bulkPreviewTable tbody');

        const operation = document.getElementById('bulkOperation').value;
        const value = parseFloat(document.getElementById('bulkValue').value || 0);
        const category = document.getElementById('bulkFilterCategory').value;
        const ids = document.getElementById('bulkFilterIds').value
            .split(',').map(id => id.trim()).filter(id => id);

        try {
            const response = await fetch(`${API_BASE}/bulk-editor/preview`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    operation_type: operation,
                    value: value,
                    filters: {
                        category_id: category || null,
                        item_ids: ids.length ? ids : null
                    }
                })
            });

            const data = await response.json();

            if (!data.preview || !data.preview.length) {
                showAlert('Nenhum item encontrado com os filtros aplicados', 'warning');
                return;
            }

            let html = '';
            data.preview.forEach(item => {
                const change = item.new_price - item.current_price;
                const changePercent = ((change / item.current_price) * 100).toFixed(1);
                const changeClass = change >= 0 ? 'text-success' : 'text-danger';

                html += `<tr>
                <td>${item.item_id}</td>
                <td>R$ ${formatNumber(item.current_price)}</td>
                <td>R$ ${formatNumber(item.new_price)}</td>
                <td class="${changeClass}">${change >= 0 ? '+' : ''}${changePercent}%</td>
            </tr>`;
            });

            tbody.innerHTML = html;
            container.classList.remove('d-none');
            document.getElementById('applyBulkBtn').disabled = false;

            window.bulkPreviewData = data.preview;
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    async function applyBulkEdit() {
        if (!confirm(`Aplicar alterações em ${window.bulkPreviewData?.length || 0} itens? Esta ação pode ser revertida.`)) {
            return;
        }

        const operation = document.getElementById('bulkOperation').value;
        const value = parseFloat(document.getElementById('bulkValue').value || 0);
        const category = document.getElementById('bulkFilterCategory').value;
        const ids = document.getElementById('bulkFilterIds').value
            .split(',').map(id => id.trim()).filter(id => id);

        try {
            const response = await fetch(`${API_BASE}/bulk-editor/apply`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    operation_type: operation,
                    value: value,
                    filters: {
                        category_id: category || null,
                        item_ids: ids.length ? ids : null
                    }
                })
            });

            const data = await response.json();

            if (data.success) {
                showAlert(`Alterações aplicadas! ${data.applied_count || 0} itens atualizados.`, 'success');
                document.getElementById('bulkPreviewContainer').classList.add('d-none');
                document.getElementById('applyBulkBtn').disabled = true;
                loadBulkBatchesHistory();
                refreshItems();
            } else {
                showAlert(data.error || 'Erro ao aplicar alterações', 'danger');
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    async function loadBulkBatchesHistory() {
        const container = document.getElementById('bulkBatchesHistory');

        try {
            const response = await fetch(`${API_BASE}/bulk-editor/batches?limit=5`);
            const data = await response.json();

            if (!data.batches || !data.batches.length) {
                container.innerHTML = '<p class="text-muted small">Nenhuma operação em massa recente.</p>';
                return;
            }

            let html = '<div class="list-group list-group-flush">';
            data.batches.forEach(b => {
                const date = new Date(b.created_at).toLocaleString('pt-BR');
                const canRollback = b.status === 'completed' && !b.rolled_back;

                html += `
                <div class="list-group-item px-0">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="badge bg-secondary">${b.operation_type}</span>
                            <small class="text-muted ms-2">${date}</small>
                            <br><small>${b.items_count} itens</small>
                        </div>
                        ${canRollback ? `
                            <button class="btn btn-xs btn-outline-warning" data-pricing-cmd="rollbackBulkBatch" data-pricing-args-raw="${b.id}">
                                <i class="bi bi-arrow-counterclockwise"></i> Reverter
                            </button>
                        ` : ''}
                    </div>
                </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<p class="text-danger small">${err.message}</p>`;
        }
    }

    async function rollbackBulkBatch(batchId) {
        if (!confirm('Reverter esta operação em massa? Os preços voltarão aos valores anteriores.')) return;

        try {
            const response = await fetch(`${API_BASE}/bulk-editor/batches/${batchId}/rollback`, {
                method: 'POST'
            });

            const data = await response.json();

            if (data.success) {
                showAlert('Operação revertida com sucesso!', 'success');
                loadBulkBatchesHistory();
                refreshItems();
            } else {
                showAlert(data.error || 'Erro ao reverter', 'danger');
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    // --- Central de Notificações ---
    function abrirNotifications() {
        new bootstrap.Modal(document.getElementById('notificationsModal')).show();
        loadNotificationChannels();
        loadNotificationHistory();
        loadAvailableEvents();
    }

    async function loadNotificationChannels() {
        const container = document.getElementById('channelsListContainer');

        try {
            const response = await fetch(`${API_BASE}/notifications/channels`);
            const data = await response.json();

            if (!data.channels || !data.channels.length) {
                container.innerHTML = '<div class="alert alert-info">Nenhum canal configurado.</div>';
                return;
            }

            let html = '<div class="list-group">';
            data.channels.forEach(ch => {
                const typeBadge = {
                    'email': '<span class="badge bg-primary">Email</span>',
                    'webhook': '<span class="badge bg-dark">Webhook</span>',
                    'slack': '<span class="badge bg-warning">Slack</span>',
                    'discord': '<span class="badge bg-info">Discord</span>'
                } [ch.type] || ch.type;

                html += `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${ch.name}</strong> ${typeBadge}
                            <br><small class="text-muted">${ch.config?.email || ch.config?.url || ''}</small>
                        </div>
                        <div class="btn-group">
                            <button class="btn btn-xs btn-outline-primary" data-pricing-cmd="testNotificationChannel" data-pricing-args-raw="${ch.id}" title="Testar">
                                <i class="bi bi-send"></i>
                            </button>
                            <button class="btn btn-xs btn-outline-danger" data-pricing-cmd="deleteNotificationChannel" data-pricing-args-raw="${ch.id}" title="Excluir">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
        }
    }

    function showCreateChannelForm() {
        document.getElementById('createChannelForm').classList.remove('d-none');
    }

    function hideCreateChannelForm() {
        document.getElementById('createChannelForm').classList.add('d-none');
    }

    function updateChannelConfigForm() {
        const type = document.getElementById('channelType').value;
        const container = document.getElementById('channelConfigFields');

        const configs = {
            'email': '<label class="form-label">Email</label><input type="email" class="form-control" id="channelConfigValue">',
            'webhook': '<label class="form-label">URL do Webhook</label><input type="url" class="form-control" id="channelConfigValue">',
            'slack': '<label class="form-label">Slack Webhook URL</label><input type="url" class="form-control" id="channelConfigValue">',
            'discord': '<label class="form-label">Discord Webhook URL</label><input type="url" class="form-control" id="channelConfigValue">'
        };

        container.innerHTML = configs[type] || '';
    }

    async function createNotificationChannel() {
        const name = document.getElementById('channelName').value;
        const type = document.getElementById('channelType').value;
        const configValue = document.getElementById('channelConfigValue')?.value;

        if (!name || !configValue) {
            showAlert('Preencha todos os campos', 'warning');
            return;
        }

        const config = type === 'email' ? {
            email: configValue
        } : {
            url: configValue
        };

        try {
            const response = await fetch(`${API_BASE}/notifications/channels`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    name,
                    type,
                    config
                })
            });

            const data = await response.json();

            if (data.success) {
                showAlert('Canal criado com sucesso!', 'success');
                hideCreateChannelForm();
                loadNotificationChannels();
            } else {
                showAlert(data.error || 'Erro ao criar canal', 'danger');
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    async function testNotificationChannel(channelId) {
        try {
            const response = await fetch(`${API_BASE}/notifications/channels/${channelId}/test`, {
                method: 'POST'
            });

            const data = await response.json();

            if (data.success) {
                showAlert('Notificação de teste enviada!', 'success');
            } else {
                showAlert(data.error || 'Falha no teste', 'danger');
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    async function deleteNotificationChannel(channelId) {
        if (!confirm('Excluir este canal de notificação?')) return;

        try {
            const response = await fetch(`${API_BASE}/notifications/channels/${channelId}`, {
                method: 'DELETE'
            });

            const data = await response.json();

            if (data.success) {
                showAlert('Canal excluído!', 'success');
                loadNotificationChannels();
            }
        } catch (err) {
            showAlert('Erro: ' + err.message, 'danger');
        }
    }

    async function loadNotificationHistory() {
        const container = document.getElementById('notificationHistoryContainer');

        try {
            const response = await fetch(`${API_BASE}/notifications/history?limit=20`);
            const data = await response.json();

            if (!data.history || !data.history.length) {
                container.innerHTML = '<p class="text-muted">Nenhuma notificação enviada.</p>';
                return;
            }

            let html = '<div class="list-group list-group-flush">';
            data.history.forEach(h => {
                const date = new Date(h.sent_at).toLocaleString('pt-BR');
                const statusIcon = h.status === 'sent' ?
                    '<i class="bi bi-check-circle text-success"></i>' :
                    '<i class="bi bi-x-circle text-danger"></i>';

                html += `
                <div class="list-group-item px-0">
                    ${statusIcon}
                    <span class="badge bg-secondary ms-2">${h.event_type}</span>
                    <small class="text-muted ms-2">${date}</small>
                </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<p class="text-danger">${err.message}</p>`;
        }
    }

    async function loadAvailableEvents() {
        const container = document.getElementById('availableEventsContainer');

        try {
            const response = await fetch(`${API_BASE}/notifications/events`);
            const data = await response.json();

            if (!data.events || !data.events.length) return;

            let html = '<div class="list-group">';
            data.events.forEach(e => {
                html += `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${e.name}</strong>
                        <br><small class="text-muted">${e.description || ''}</small>
                    </div>
                    <span class="badge bg-light text-dark">${e.code}</span>
                </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        } catch (err) {
            console.error('Erro ao carregar eventos:', err);
        }
    }
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layouts/modern/app.php';
?>
