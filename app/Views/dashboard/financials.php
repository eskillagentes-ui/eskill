<?php

declare(strict_types=1);

$title = 'Relatórios Financeiros';
$subtitle = 'Demonstrativo de Resultados e Análise de Lucratividade';
include __DIR__ . '/../layouts/modern/partials/page-header.php';
?>

<div class="row mb-4">
    <!-- Filters -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <form id="financial-filter" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Data Inicial</label>
                        <input type="date" class="form-control" name="start" id="date-start" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Data Final</label>
                        <input type="date" class="form-control" name="end" id="date-end" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-primary w-100" id="btn-financial-filter">
                            <i class="bi bi-funnel"></i> Filtrar
                        </button>
                    </div>
                     <div class="col-md-3">
                        <button type="button" class="btn btn-outline-dark w-100" id="btn-financial-export">
                            <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Alertas financeiros — GET /api/financials/alerts -->
<div class="row mb-3" id="financial-alerts-row" style="display:none;">
    <div class="col-12" id="financial-alerts-banner"></div>
</div>

<!-- Caixa MP — GET /api/financials/balance -->
<div class="row mb-4" id="cash-mp-cards">
    <div class="col-12 mb-2">
        <div class="text-muted small text-uppercase fw-semibold letter-spacing-1">Caixa Mercado Pago</div>
    </div>
    <div class="col-6 col-lg-4 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Saldo disponível</div>
                <div class="fs-5 fw-bold text-success" id="kpi-mp-available">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">A liberar / retido</div>
                <div class="fs-5 fw-bold text-warning" id="kpi-mp-unavailable">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Saldo total</div>
                <div class="fs-5 fw-bold" id="kpi-mp-total">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
                <div class="text-muted small mt-1" id="kpi-mp-balance-note"></div>
            </div>
        </div>
    </div>
</div>

<!-- KPI Cards -->
<div class="row mb-4" id="kpi-cards">
    <?php
    // Placeholders de carregamento; preenchidos via JS a partir de /api/financials/pnl e /api/financials/metrics
    $kpiPlaceholders = [
        ['id' => 'kpi-gross-revenue', 'label' => 'Faturamento'],
        ['id' => 'kpi-net-revenue', 'label' => 'Receita Líquida'],
        ['id' => 'kpi-net-profit', 'label' => 'Lucro Bruto'],
        ['id' => 'kpi-margin', 'label' => 'Margem'],
        ['id' => 'kpi-orders', 'label' => 'Número de Vendas'],
        ['id' => 'kpi-units-sold', 'label' => 'Número de Unidades Vendidas'],
        ['id' => 'kpi-avg-ticket', 'label' => 'Ticket Médio'],
        ['id' => 'kpi-roi', 'label' => 'Retorno Sobre Investimento'],
        ['id' => 'kpi-cost-rate', 'label' => 'Taxa de Custos'],
    ];
    foreach ($kpiPlaceholders as $kpi):
    ?>
    <div class="col-6 col-md-4 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><?= htmlspecialchars($kpi['label']) ?></div>
                <div class="fs-5 fw-bold" id="<?= htmlspecialchars($kpi['id']) ?>">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Resumo de taxas (overview) — GET /api/financials/fees -->
<div class="row mb-4" id="fees-overview-cards">
    <div class="col-12 mb-2 d-flex justify-content-between align-items-center">
        <div class="text-muted small text-uppercase fw-semibold letter-spacing-1">Taxas do período</div>
        <button type="button" class="btn btn-link btn-sm p-0" id="btn-goto-fees-tab">Ver detalhe</button>
    </div>
    <div class="col-6 col-md-4 col-lg-2 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Comissão ML</div>
                <div class="fs-6 fw-bold text-danger" id="kpi-fee-ml">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Taxa pagamento</div>
                <div class="fs-6 fw-bold text-danger" id="kpi-fee-payment">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Taxas fixas</div>
                <div class="fs-6 fw-bold text-danger" id="kpi-fee-fixed">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Frete</div>
                <div class="fs-6 fw-bold" id="kpi-fee-shipping">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Total taxas</div>
                <div class="fs-6 fw-bold text-danger" id="kpi-fee-total">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Taxa efetiva</div>
                <div class="fs-6 fw-bold" id="kpi-fee-rate">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Snapshot liquidações — GET /api/financials/settlements -->
<div class="row mb-4" id="settlements-overview-row">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-3 d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div>
                    <div class="text-muted small mb-1">Liquidações MP (período)</div>
                    <div class="d-flex flex-wrap gap-4">
                        <div>
                            <span class="text-muted small">Líquido</span>
                            <div class="fw-bold text-success" id="kpi-settlements-net">
                                <span class="spinner-border spinner-border-sm text-secondary"></span>
                            </div>
                        </div>
                        <div>
                            <span class="text-muted small">Movimentos</span>
                            <div class="fw-bold" id="kpi-settlements-count">
                                <span class="spinner-border spinner-border-sm text-secondary"></span>
                            </div>
                        </div>
                        <div>
                            <span class="text-muted small" id="kpi-settlements-source-label">Fonte</span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm" id="btn-goto-settlements-tab">
                    Ver liquidações
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Ads KPIs — origem: módulo Ads / Mercado Ads. Usa gasto agregado do período selecionado
     quando houver dados coletados (ads_account_metrics_daily); caso contrário, cai para o
     último snapshot disponível ("hoje"), deixando isso explícito no rótulo. -->
<div class="row mb-4" id="ads-kpi-cards">
    <div class="col-6 col-lg-3 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1" id="kpi-ads-spend-label">Valor em Ads</div>
                <div class="fs-5 fw-bold" id="kpi-ads-spend">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1" id="kpi-tacos-label">TACOS</div>
                <div class="fs-5 fw-bold" id="kpi-tacos">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Lucro Bruto pós-Ads</div>
                <div class="fs-5 fw-bold" id="kpi-net-profit-post-ads">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Margem Pós-Ads (MPA)</div>
                <div class="fs-5 fw-bold" id="kpi-mpa">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" id="financials-tabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-overview-btn" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button" role="tab" aria-controls="tab-overview" aria-selected="true">
            Visão Geral
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-abc-btn" data-bs-toggle="tab" data-bs-target="#tab-abc" type="button" role="tab" aria-controls="tab-abc" aria-selected="false">
            Curva ABC
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-fees-btn" data-bs-toggle="tab" data-bs-target="#tab-fees" type="button" role="tab" aria-controls="tab-fees" aria-selected="false">
            Taxas &amp; Custos
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-compare-btn" data-bs-toggle="tab" data-bs-target="#tab-compare" type="button" role="tab" aria-controls="tab-compare" aria-selected="false">
            Comparativo
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-projection-btn" data-bs-toggle="tab" data-bs-target="#tab-projection" type="button" role="tab" aria-controls="tab-projection" aria-selected="false">
            Projeção
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-claims-btn" data-bs-toggle="tab" data-bs-target="#tab-claims" type="button" role="tab" aria-controls="tab-claims" aria-selected="false">
            Reclamações
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-settlements-btn" data-bs-toggle="tab" data-bs-target="#tab-settlements" type="button" role="tab" aria-controls="tab-settlements" aria-selected="false">
            Liquidações MP
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-cashflow-btn" data-bs-toggle="tab" data-bs-target="#tab-cashflow" type="button" role="tab" aria-controls="tab-cashflow" aria-selected="false">
            Fluxo de Caixa
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-profitability-btn" data-bs-toggle="tab" data-bs-target="#tab-profitability" type="button" role="tab" aria-controls="tab-profitability" aria-selected="false">
            Lucratividade
        </button>
    </li>
</ul>

<div class="tab-content" id="financials-tabs-content">
    <div class="tab-pane fade show active" id="tab-overview" role="tabpanel" aria-labelledby="tab-overview-btn">
        <div class="row">
            <!-- P&L Table -->
            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">Demonstrativo de Resultados (DRE)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="pnl-table">
                                <tbody>
                                    <tr>
                                        <td colspan="2" class="text-center py-5">
                                            <div class="spinner-border text-primary"></div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">Resumo de Receitas</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueChart" height="300"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-abc" role="tabpanel" aria-labelledby="tab-abc-btn">
        <div class="row mb-4" id="abc-summary-cards">
            <div class="col-12 text-center py-5" id="abc-loading">
                <div class="spinner-border text-primary"></div>
            </div>
        </div>
        <div class="row" id="abc-products-row" style="display:none;">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Produtos por Classe</h5>
                        <div class="btn-group btn-group-sm" role="group" id="abc-class-filter">
                            <button type="button" class="btn btn-outline-success active" data-class="a">Curva A</button>
                            <button type="button" class="btn btn-outline-warning" data-class="b">Curva B</button>
                            <button type="button" class="btn btn-outline-secondary" data-class="c">Curva C</button>
                            <button type="button" class="btn btn-outline-dark" data-class="z">Curva Z</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr id="abc-products-thead-row">
                                        <th>Produto</th>
                                        <th class="text-end">Qtd. Vendida</th>
                                        <th class="text-end">Receita</th>
                                        <th class="text-end">% Receita</th>
                                        <th class="text-end">% Acumulado</th>
                                    </tr>
                                </thead>
                                <tbody id="abc-products-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-fees" role="tabpanel" aria-labelledby="tab-fees-btn">
        <div class="row mb-3" id="fees-summary-cards">
            <div class="col-12 text-center py-5" id="fees-loading">
                <div class="spinner-border text-primary"></div>
            </div>
        </div>
        <div class="row" id="fees-detail-row" style="display:none;">
            <div class="col-lg-7 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">Breakdown de Taxas</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th class="text-end">Valor</th>
                                        <th class="text-end">% da Receita</th>
                                    </tr>
                                </thead>
                                <tbody id="fees-breakdown-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">Composição</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="feesChart" height="260"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-compare" role="tabpanel" aria-labelledby="tab-compare-btn">
        <div class="row mb-3">
            <div class="col-12">
                <div class="text-muted small mb-2" id="compare-period-label"></div>
            </div>
        </div>
        <div class="row" id="compare-cards">
            <div class="col-12 text-center py-5" id="compare-loading">
                <div class="spinner-border text-primary"></div>
            </div>
        </div>
        <div class="row" id="compare-table-row" style="display:none;">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">Período atual vs período anterior equivalente</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Métrica</th>
                                        <th class="text-end">Atual</th>
                                        <th class="text-end">Anterior</th>
                                        <th class="text-end">Variação</th>
                                    </tr>
                                </thead>
                                <tbody id="compare-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-projection" role="tabpanel" aria-labelledby="tab-projection-btn">
        <div class="row mb-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="projection-days">Horizonte (dias)</label>
                <select class="form-select" id="projection-days">
                    <option value="7">7 dias</option>
                    <option value="15">15 dias</option>
                    <option value="30" selected>30 dias</option>
                    <option value="60">60 dias</option>
                    <option value="90">90 dias</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-outline-primary w-100" id="btn-reload-projection">Recalcular</button>
            </div>
        </div>
        <div class="row" id="projection-cards">
            <div class="col-12 text-center py-5" id="projection-loading">
                <div class="spinner-border text-primary"></div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-claims" role="tabpanel" aria-labelledby="tab-claims-btn">
        <div class="row mb-3" id="claims-summary-cards">
            <div class="col-12 text-center py-5" id="claims-loading">
                <div class="spinner-border text-primary"></div>
            </div>
        </div>
        <div class="row" id="claims-table-row" style="display:none;">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Reclamações do período</h5>
                        <span class="text-muted small" id="claims-summary-meta"></span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Status</th>
                                        <th>Tipo</th>
                                        <th>Estágio</th>
                                        <th>Motivo</th>
                                        <th>Criada em</th>
                                        <th>Recurso</th>
                                    </tr>
                                </thead>
                                <tbody id="claims-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-settlements" role="tabpanel" aria-labelledby="tab-settlements-btn">
        <div class="row mb-3" id="settlements-summary-cards">
            <div class="col-12 text-center py-5" id="settlements-loading">
                <div class="spinner-border text-primary"></div>
            </div>
        </div>
        <div class="row" id="settlements-table-row" style="display:none;">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Liquidações / liberações</h5>
                        <span class="text-muted small" id="settlements-source-label"></span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Descrição</th>
                                        <th>Tipo</th>
                                        <th>Pedido</th>
                                        <th class="text-end">Bruto</th>
                                        <th class="text-end">Taxa</th>
                                        <th class="text-end">Líquido</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="settlements-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-cashflow" role="tabpanel" aria-labelledby="tab-cashflow-btn">
        <div class="row mb-3" id="cashflow-summary-cards">
            <div class="col-12 text-center py-5" id="cashflow-loading">
                <div class="spinner-border text-primary"></div>
            </div>
        </div>
        <div class="row" id="cashflow-detail-row" style="display:none;">
            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">Entradas</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <tbody id="cashflow-inflows-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">Saídas</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <tbody id="cashflow-outflows-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 mb-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">Composição do fluxo</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="cashflowChart" height="120"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-profitability" role="tabpanel" aria-labelledby="tab-profitability-btn">
        <div class="row mb-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="profitability-limit">Top N</label>
                <select class="form-select" id="profitability-limit">
                    <option value="10">10</option>
                    <option value="20" selected>20</option>
                    <option value="30">30</option>
                    <option value="50">50</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-outline-primary w-100" id="btn-reload-profitability">Recarregar</button>
            </div>
        </div>
        <div class="row" id="profitability-content">
            <div class="col-12 text-center py-5" id="profitability-loading">
                <div class="spinner-border text-primary"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script nonce="<?= CSP_NONCE ?>">
    window.financialManager = {
        chart: null,
        feesChart: null,
        abcData: null,
        abcActiveClass: 'a',
        abcLoadedFor: null,
        feesLoadedFor: null,
        compareLoadedFor: null,
        projectionLoadedFor: null,
        claimsLoadedFor: null,
        settlementsLoadedFor: null,
        cashflowLoadedFor: null,
        profitabilityLoadedFor: null,
        cashflowChart: null,

        init: function() {
            document.getElementById('btn-financial-filter')?.addEventListener('click', () => this.loadData());
            document.getElementById('btn-financial-export')?.addEventListener('click', () => this.exportPdf());
            const onPeriodChange = () => {
                this.normalizePeriod();
                this.loadData();
            };
            document.getElementById('date-start')?.addEventListener('change', onPeriodChange);
            document.getElementById('date-end')?.addEventListener('change', onPeriodChange);
            document.getElementById('tab-abc-btn')?.addEventListener('shown.bs.tab', () => this.loadAbcOnce());
            document.getElementById('tab-fees-btn')?.addEventListener('shown.bs.tab', () => this.loadFeesOnce());
            document.getElementById('tab-compare-btn')?.addEventListener('shown.bs.tab', () => this.loadCompareOnce());
            document.getElementById('tab-projection-btn')?.addEventListener('shown.bs.tab', () => this.loadProjectionOnce());
            document.getElementById('tab-claims-btn')?.addEventListener('shown.bs.tab', () => this.loadClaimsOnce());
            document.getElementById('tab-settlements-btn')?.addEventListener('shown.bs.tab', () => this.loadSettlementsOnce());
            document.getElementById('tab-cashflow-btn')?.addEventListener('shown.bs.tab', () => this.loadCashflowOnce());
            document.getElementById('tab-profitability-btn')?.addEventListener('shown.bs.tab', () => this.loadProfitabilityOnce());
            document.getElementById('btn-reload-projection')?.addEventListener('click', () => {
                this.projectionLoadedFor = null;
                this.loadProjection();
            });
            document.getElementById('btn-reload-profitability')?.addEventListener('click', () => {
                this.profitabilityLoadedFor = null;
                this.loadProfitability();
            });
            document.getElementById('btn-goto-fees-tab')?.addEventListener('click', () => {
                const btn = document.getElementById('tab-fees-btn');
                if (btn && window.bootstrap?.Tab) {
                    window.bootstrap.Tab.getOrCreateInstance(btn).show();
                } else {
                    btn?.click();
                }
            });
            document.getElementById('btn-goto-settlements-tab')?.addEventListener('click', () => {
                const btn = document.getElementById('tab-settlements-btn');
                if (btn && window.bootstrap?.Tab) {
                    window.bootstrap.Tab.getOrCreateInstance(btn).show();
                } else {
                    btn?.click();
                }
            });
            this.normalizePeriod();
            this.loadData();
        },

        normalizePeriod: function() {
            const startEl = document.getElementById('date-start');
            const endEl = document.getElementById('date-end');
            if (!startEl || !endEl) return;
            const today = new Date().toISOString().slice(0, 10);
            if (endEl.max !== today) endEl.max = today;
            if (endEl.value && endEl.value > today) endEl.value = today;
            if (startEl.value && endEl.value && startEl.value > endEl.value) {
                startEl.value = endEl.value;
            }
        },

        periodKey: function() {
            const start = document.getElementById('date-start').value;
            const end = document.getElementById('date-end').value;
            return `${start}|${end}`;
        },

        invalidateSecondaryTabs: function() {
            this.abcData = null;
            this.abcLoadedFor = null;
            this.feesLoadedFor = null;
            this.compareLoadedFor = null;
            this.projectionLoadedFor = null;
            this.claimsLoadedFor = null;
            this.settlementsLoadedFor = null;
            this.cashflowLoadedFor = null;
            this.profitabilityLoadedFor = null;
        },

        loadData: async function() {
            this.normalizePeriod();
            const start = document.getElementById('date-start').value;
            const end = document.getElementById('date-end').value;
            this.invalidateSecondaryTabs();
            let pnl = null;
            let metrics = null;

            try {
                const [pnlData, metricsData] = await Promise.all([
                    requestJson(`/api/financials/pnl?start=${start}&end=${end}`),
                    requestJson(`/api/financials/metrics?start=${start}&end=${end}`),
                ]);

                if (pnlData.success) {
                    pnl = pnlData.pnl;
                    this.renderPnL(pnlData.pnl);
                    this.renderChart(Array.isArray(pnlData.chart) ? pnlData.chart : []);
                } else {
                    alert('Erro ao carregar dados: ' + pnlData.error);
                }

                if (metricsData.success && pnl) {
                    metrics = metricsData.data || metricsData;
                    this.renderKpis(pnl, metrics);
                } else if (!metricsData.success) {
                    console.error('Erro ao carregar métricas:', metricsData.error);
                }
            } catch (e) {
                console.error(e);
            }

            try {
                const adsData = await requestJson(`/api/ads/observation?start=${start}&end=${end}`);
                this.renderAdsKpis(adsData.success ? adsData : null, pnl, metrics);
            } catch (e) {
                console.error('Erro ao carregar dados de Ads:', e);
                this.renderAdsKpis(null, pnl, metrics);
            }

            // Caixa MP, taxas, alertas e snapshot de liquidações (APIs já existentes)
            const [balanceResult, feesResult, alertsResult, settlementsResult] = await Promise.allSettled([
                requestJson('/api/financials/balance'),
                requestJson(`/api/financials/fees?start=${start}&end=${end}`),
                requestJson('/api/financials/alerts'),
                requestJson(`/api/financials/settlements?start=${start}&end=${end}`),
            ]);

            this.renderCashMp(
                balanceResult.status === 'fulfilled' && balanceResult.value?.success
                    ? (balanceResult.value.data || balanceResult.value)
                    : null,
                balanceResult.status === 'fulfilled' ? balanceResult.value : null
            );
            this.renderFeesOverview(
                feesResult.status === 'fulfilled' && feesResult.value?.success
                    ? (feesResult.value.data || feesResult.value)
                    : null
            );
            this.renderAlertsBanner(
                alertsResult.status === 'fulfilled' && alertsResult.value?.success
                    ? (alertsResult.value.data || alertsResult.value)
                    : null
            );
            this.renderSettlementsOverview(
                settlementsResult.status === 'fulfilled' && settlementsResult.value?.success
                    ? (settlementsResult.value.data || settlementsResult.value)
                    : null
            );
        },

        renderCashMp: function(balance, rawResult) {
            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            const set = (id, text) => {
                const el = document.getElementById(id);
                if (el) el.textContent = text;
            };
            const noteEl = document.getElementById('kpi-mp-balance-note');

            if (!balance || balance.error || (rawResult && rawResult.success === false)) {
                const msg = balance?.error || rawResult?.error || 'Saldo indisponível';
                set('kpi-mp-available', 'N/D');
                set('kpi-mp-unavailable', 'N/D');
                set('kpi-mp-total', 'N/D');
                if (noteEl) noteEl.textContent = msg;
                return;
            }

            set('kpi-mp-available', formatMoney(balance.available_balance));
            set('kpi-mp-unavailable', formatMoney(balance.unavailable_balance));
            set('kpi-mp-total', formatMoney(balance.total_amount));
            if (noteEl) {
                noteEl.textContent = balance.updated_at
                    ? `Atualizado: ${balance.updated_at}`
                    : '';
            }
        },

        renderFeesOverview: function(data) {
            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            const set = (id, text) => {
                const el = document.getElementById(id);
                if (el) el.textContent = text;
            };

            if (!data || data.error) {
                set('kpi-fee-ml', 'N/D');
                set('kpi-fee-payment', 'N/D');
                set('kpi-fee-fixed', 'N/D');
                set('kpi-fee-shipping', 'N/D');
                set('kpi-fee-total', 'N/D');
                set('kpi-fee-rate', 'N/D');
                return;
            }

            const fees = data.fees || {};
            set('kpi-fee-ml', formatMoney(fees.ml_commission));
            set('kpi-fee-payment', formatMoney(fees.payment_fees));
            set('kpi-fee-fixed', formatMoney(fees.fixed_fees));
            set('kpi-fee-shipping', formatMoney(data.shipping_cost));
            set('kpi-fee-total', formatMoney(fees.total));
            set('kpi-fee-rate', `${Number(data.fee_rate || 0).toFixed(2)}%`);
        },

        renderAlertsBanner: function(data) {
            const row = document.getElementById('financial-alerts-row');
            const banner = document.getElementById('financial-alerts-banner');
            if (!row || !banner) return;

            const alerts = Array.isArray(data?.alerts) ? data.alerts : [];
            if (!alerts.length) {
                row.style.display = 'none';
                banner.innerHTML = '';
                return;
            }

            const severityClass = (s) => {
                if (s === 'critical') return 'danger';
                if (s === 'warning') return 'warning';
                return 'info';
            };

            banner.innerHTML = alerts.map((a) => {
                const sev = severityClass(a.severity || 'info');
                const rec = a.recommendation
                    ? `<div class="small mt-1">${this.escapeHtml(a.recommendation)}</div>`
                    : '';
                return `<div class="alert alert-${sev} mb-2 py-2">
                    <strong>${this.escapeHtml(a.message || a.type || 'Alerta')}</strong>
                    ${rec}
                </div>`;
            }).join('');
            row.style.display = '';
        },

        renderSettlementsOverview: function(data) {
            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            const set = (id, text) => {
                const el = document.getElementById(id);
                if (el) el.textContent = text;
            };

            if (!data || data.error) {
                set('kpi-settlements-net', 'N/D');
                set('kpi-settlements-count', 'N/D');
                const src = document.getElementById('kpi-settlements-source-label');
                if (src) src.textContent = data?.error || 'Liquidações indisponíveis';
                return;
            }

            const raw = Array.isArray(data.results) ? data.results : (Array.isArray(data) ? data : []);
            const rows = raw.map((r) => this.normalizeSettlementRow(r));
            const totalNet = rows.reduce((s, r) => s + r.net, 0);

            set('kpi-settlements-net', formatMoney(totalNet));
            set('kpi-settlements-count', String(rows.length));

            const sourceMap = {
                api: 'Fonte: API MP/ML',
                local: 'Fonte: dados locais',
                orders_estimated: 'Fonte: estimativa das vendas',
                none: 'Fonte: sem conta ML',
            };
            const src = document.getElementById('kpi-settlements-source-label');
            if (src) {
                src.textContent = sourceMap[data.source] || (data.source ? `Fonte: ${data.source}` : 'Fonte: mista');
            }
        },

        renderAdsKpis: function(ads, pnl, metrics) {
            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            const formatPercentOrNa = (val) => (val === null || val === undefined) ? 'N/D' : `${Number(val).toFixed(2)}%`;

            const set = (id, text) => {
                const el = document.getElementById(id);
                if (el) el.textContent = text;
            };
            const setLabel = (id, text) => {
                const el = document.getElementById(id);
                if (el) el.textContent = text;
            };

            if (!ads || ads.has_campaigns === false) {
                set('kpi-ads-spend', 'Sem campanhas');
                set('kpi-tacos', 'N/D');
                set('kpi-net-profit-post-ads', 'N/D');
                set('kpi-mpa', 'N/D');
                return;
            }

            const periodAvailable = ads.period_metrics && ads.period_metrics.available;
            const gasto = periodAvailable ? ads.period_metrics.gasto : ads.gasto_hoje;
            const tacos = periodAvailable ? ads.period_metrics.tacos : ads.tacos;

            setLabel('kpi-ads-spend-label', periodAvailable ? 'Valor em Ads (período)' : 'Valor em Ads (hoje)');
            setLabel('kpi-tacos-label', periodAvailable ? 'TACOS (período)' : 'TACOS (hoje)');
            set('kpi-ads-spend', formatMoney(gasto));
            set('kpi-tacos', formatPercentOrNa(tacos));

            // Lucro pós-Ads e MPA só com gasto do MESMO período do filtro
            if (periodAvailable && metrics && pnl) {
                const netProfitPostAds = (metrics.net_profit || 0) - (gasto || 0);
                const mpa = pnl.gross_revenue > 0 ? (netProfitPostAds / pnl.gross_revenue) * 100 : 0;
                const el = document.getElementById('kpi-net-profit-post-ads');
                if (el) {
                    el.textContent = formatMoney(netProfitPostAds);
                    el.className = `fs-5 fw-bold ${netProfitPostAds >= 0 ? 'text-success' : 'text-danger'}`;
                }
                set('kpi-mpa', formatPercentOrNa(mpa));
            } else {
                set('kpi-net-profit-post-ads', 'Requer dados de Ads do período');
                set('kpi-mpa', 'N/D');
            }
        },

        renderKpis: function(pnl, metrics) {
            const formatMoney = (val) => {
                return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            };
            const formatPercent = (val) => `${(val || 0).toFixed(2)}%`;

            const set = (id, text, colorClass = '') => {
                const el = document.getElementById(id);
                if (!el) return;
                el.textContent = text;
                if (colorClass) el.className = `fs-5 fw-bold ${colorClass}`;
            };

            set('kpi-gross-revenue', formatMoney(pnl.gross_revenue));
            set('kpi-net-revenue', formatMoney(pnl.net_revenue));
            set('kpi-net-profit', formatMoney(metrics.net_profit), metrics.net_profit >= 0 ? 'text-success' : 'text-danger');
            set('kpi-margin', formatPercent(pnl.avg_margin));
            set('kpi-orders', metrics.total_orders ?? 0);
            set('kpi-units-sold', pnl.units_sold ?? 0);
            set('kpi-avg-ticket', formatMoney(metrics.avg_ticket));
            set('kpi-roi', formatPercent(metrics.roi));
            set('kpi-cost-rate', formatPercent(metrics.cost_rate));
        },

        renderPnL: function(pnl) {
            const formatMoney = (val) => {
                return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val);
            };
            const escapeHtml = (str) => String(str ?? '').replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            }[c]));

            const row = (label, value, cssClass = '', isHeader = false) => {
                const fw = isHeader ? 'fw-bold' : '';
                return `<tr>
                    <td class="${fw}">${label}</td>
                    <td class="text-end ${fw} ${cssClass}">${formatMoney(value)}</td>
                </tr>`;
            };

            let html = '';
            html += row('Receita Bruta', pnl.gross_revenue, '', true);
            html += row('(-) Impostos', pnl.taxes, 'text-danger');
            html += row('Receita Líquida', pnl.net_revenue, '', true);
            html += '<tr><td colspan="2"><hr class="my-1"></td></tr>';
            html += row('(-) Custo Produtos (CMV)', pnl.cogs, 'text-secondary');
            if (pnl.cogs_source) {
                const isReal = pnl.cogs_source === 'sku_custos' || pnl.cogs_source === 'ml_orders';
                const badge = isReal
                    ? '<span class="badge bg-success ms-2">CMV real (' + escapeHtml(pnl.cogs_source) + ')</span>'
                    : '<span class="badge bg-warning text-dark ms-2">estimado (' + escapeHtml(pnl.cogs_source) + ')</span>';
                html += '<tr><td colspan="2" class="small text-muted py-1">Fonte do CMV: ' + badge + ' · <a href="/dashboard/cogs">cadastrar custos</a></td></tr>';
            }
            html += row('(-) Comissões ML', pnl.commissions, 'text-secondary');
            html += row('(-) Taxas Pagamento', pnl.payment_fees, 'text-secondary');
            html += row('(-) Fretes', pnl.shipping_cost, 'text-secondary');
            html += row('(-) Taxas Fixas', pnl.fixed_fees, 'text-secondary');
            html += row('(-) Descontos', pnl.discounts, 'text-secondary');
            html += '<tr><td colspan="2"><hr class="my-1"></td></tr>';

            const profitClass = pnl.net_profit >= 0 ? 'text-success' : 'text-danger';
            html += row('Resultado Operacional', pnl.net_profit, profitClass, true);

            html += `<tr><td colspan="2"><small class="text-muted d-block text-end mt-2">Margem Líquida: ${pnl.avg_margin.toFixed(1)}%</small></td></tr>`;

            document.querySelector('#pnl-table tbody').innerHTML = html;
        },

        renderChart: function(dailyData) {
            const ctx = document.getElementById('revenueChart')?.getContext('2d');
            if (!ctx) return;

            if (this.chart) this.chart.destroy();

            const rows = Array.isArray(dailyData) ? dailyData : [];
            const labels = rows.map(d => {
                const raw = String(d.date || '');
                // Evita off-by-one de Date('YYYY-MM-DD') em UTC
                const parts = raw.slice(0, 10).split('-');
                if (parts.length === 3) {
                    return `${parts[2]}/${parts[1]}/${parts[0]}`;
                }
                return raw;
            });
            const revenue = rows.map(d => d.revenue || 0);
            const profit = rows.map(d => d.profit || 0);
            const costs = revenue.map((r, i) => Math.max(0, r - profit[i]));

            this.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Lucro',
                            data: profit,
                            borderColor: '#198754',
                            backgroundColor: 'rgba(25, 135, 84, 0.35)',
                            tension: 0.3,
                            fill: true,
                            stack: 'resumo'
                        },
                        {
                            label: 'Custos e Taxas',
                            data: costs,
                            borderColor: '#6f42c1',
                            backgroundColor: 'rgba(111, 66, 193, 0.25)',
                            tension: 0.3,
                            fill: true,
                            stack: 'resumo'
                        },
                        {
                            label: 'Receita Total',
                            data: revenue,
                            borderColor: '#0d6efd',
                            backgroundColor: 'transparent',
                            borderDash: [4, 3],
                            tension: 0.3,
                            fill: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: { stacked: true, beginAtZero: true }
                    },
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        },

        exportPdf: function() {
            const start = document.getElementById('date-start').value;
            const end = document.getElementById('date-end').value;
            window.open(`/api/financials/export?start=${start}&end=${end}`, '_blank');
        },

        loadAbcOnce: function() {
            if (this.abcData && this.abcLoadedFor === this.periodKey()) return;
            this.loadAbc();
        },

        loadAbc: async function() {
            const start = document.getElementById('date-start').value;
            const end = document.getElementById('date-end').value;
            const loadingEl = document.getElementById('abc-loading');
            const productsRow = document.getElementById('abc-products-row');
            const summaryEl = document.getElementById('abc-summary-cards');

            if (!document.getElementById('abc-loading') && summaryEl) {
                summaryEl.innerHTML = '<div class="col-12 text-center py-5" id="abc-loading"><div class="spinner-border text-primary"></div></div>';
            }

            try {
                const result = await requestJson(`/api/financials/products/abc?start_date=${start}&end_date=${end}`);
                // Endpoint pode retornar { success, data } ou fields no nível raiz
                const payload = result.data || result;

                if (!result.success || payload.error) {
                    summaryEl.innerHTML =
                        `<div class="col-12"><div class="alert alert-warning mb-0">${payload.error || result.error || 'Sem dados suficientes para análise ABC neste período.'}</div></div>`;
                    productsRow.style.display = 'none';
                    return;
                }

                this.abcData = payload;
                this.abcLoadedFor = `${start}|${end}`;
                this.renderAbcSummary(payload);
                this.renderAbcProducts();
                productsRow.style.display = '';
            } catch (e) {
                console.error(e);
                summaryEl.innerHTML =
                    '<div class="col-12"><div class="alert alert-danger mb-0">Erro ao carregar Curva ABC.</div></div>';
            } finally {
                document.getElementById('abc-loading')?.remove();
            }
        },

        renderAbcSummary: function(data) {
            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);

            const cardConfig = [
                { key: 'class_a', label: 'Curva A', color: 'success' },
                { key: 'class_b', label: 'Curva B', color: 'warning' },
                { key: 'class_c', label: 'Curva C', color: 'secondary' },
                { key: 'class_z', label: 'Curva Z', color: 'dark' },
            ];

            const zCount = data.summary?.class_z?.count ?? 0;
            let html = `
                <div class="col-12 mb-3">
                    <div class="text-muted small">
                        Receita total do período: <strong>${formatMoney(data.total_revenue)}</strong>
                        &middot; ${data.total_products} produtos com venda
                        &middot; ${zCount} produtos ativos sem venda
                    </div>
                </div>`;

            cardConfig.forEach(cfg => {
                const s = data.summary?.[cfg.key];
                if (!s) return;
                const metric = cfg.key === 'class_z'
                    ? `${s.count} itens`
                    : `${Number(s.revenue_share || 0).toFixed(1)}% da receita`;
                html += `
                    <div class="col-12 col-md-3 mb-3">
                        <div class="card border-0 shadow-sm h-100 border-start border-${cfg.color} border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <h6 class="text-${cfg.color} fw-bold mb-2">${cfg.label}</h6>
                                    <span class="badge bg-${cfg.color}">${s.count} itens</span>
                                </div>
                                <div class="fs-5 fw-bold">${metric}</div>
                                <div class="text-muted small">${s.description || ''}</div>
                            </div>
                        </div>
                    </div>`;
            });

            document.getElementById('abc-summary-cards').innerHTML = html;
        },

        renderAbcProducts: function() {
            if (!this.abcData) return;

            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            const escapeHtml = (str) => String(str ?? '').replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            }[c]));
            const products = this.abcData.products?.[`class_${this.abcActiveClass}`] || [];
            const tbody = document.getElementById('abc-products-tbody');
            const theadRow = document.getElementById('abc-products-thead-row');
            const isClassZ = this.abcActiveClass === 'z';

            theadRow.innerHTML = isClassZ
                ? '<th>Produto</th><th class="text-end">Qtd. Disponível</th><th class="text-end">Qtd. Vendida (histórico)</th><th class="text-end"></th><th class="text-end"></th>'
                : '<th>Produto</th><th class="text-end">Qtd. Vendida</th><th class="text-end">Receita</th><th class="text-end">% Receita</th><th class="text-end">% Acumulado</th>';

            if (products.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Nenhum produto nesta classe.</td></tr>';
                return;
            }

            tbody.innerHTML = products.map(p => isClassZ ? `
                <tr>
                    <td>${escapeHtml(p.item_title ?? p.item_id)}</td>
                    <td class="text-end">${p.available_quantity ?? 0}</td>
                    <td class="text-end">${p.sold_quantity ?? 0}</td>
                    <td class="text-end"></td>
                    <td class="text-end"></td>
                </tr>
            ` : `
                <tr>
                    <td>${escapeHtml(p.item_title ?? p.item_id)}</td>
                    <td class="text-end">${p.total_qty}</td>
                    <td class="text-end">${formatMoney(p.total_revenue)}</td>
                    <td class="text-end">${Number(p.revenue_percentage || 0).toFixed(2)}%</td>
                    <td class="text-end">${Number(p.cumulative_percentage || 0).toFixed(2)}%</td>
                </tr>
            `).join('');
        },

        loadFeesOnce: function() {
            if (this.feesLoadedFor === this.periodKey()) return;
            this.loadFees();
        },

        loadFees: async function() {
            const start = document.getElementById('date-start').value;
            const end = document.getElementById('date-end').value;
            const summaryEl = document.getElementById('fees-summary-cards');
            const detailRow = document.getElementById('fees-detail-row');

            summaryEl.innerHTML = '<div class="col-12 text-center py-5" id="fees-loading"><div class="spinner-border text-primary"></div></div>';
            detailRow.style.display = 'none';

            try {
                const result = await requestJson(`/api/financials/fees?start=${start}&end=${end}`);
                const data = result.data || result;
                if (!result.success) {
                    summaryEl.innerHTML = `<div class="col-12"><div class="alert alert-warning mb-0">${result.error || 'Não foi possível carregar as taxas.'}</div></div>`;
                    return;
                }
                this.feesLoadedFor = this.periodKey();
                this.renderFees(data);
                detailRow.style.display = '';
            } catch (e) {
                console.error(e);
                summaryEl.innerHTML = '<div class="col-12"><div class="alert alert-danger mb-0">Erro ao carregar taxas.</div></div>';
            }
        },

        renderFees: function(data) {
            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            const fees = data.fees || {};

            document.getElementById('fees-summary-cards').innerHTML = `
                <div class="col-6 col-md-3 mb-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                        <div class="text-muted small mb-1">Total de Taxas</div>
                        <div class="fs-5 fw-bold text-danger">${formatMoney(fees.total)}</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3 mb-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                        <div class="text-muted small mb-1">Taxa efetiva</div>
                        <div class="fs-5 fw-bold">${Number(data.fee_rate || 0).toFixed(2)}%</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3 mb-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                        <div class="text-muted small mb-1">Fretes</div>
                        <div class="fs-5 fw-bold">${formatMoney(data.shipping_cost)}</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3 mb-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                        <div class="text-muted small mb-1">Pedidos no período</div>
                        <div class="fs-5 fw-bold">${data.total_orders ?? 0}</div>
                    </div></div>
                </div>`;

            const rows = data.breakdown_by_type || [];
            document.getElementById('fees-breakdown-tbody').innerHTML = rows.length
                ? rows.map(r => `
                    <tr>
                        <td>${r.type}</td>
                        <td class="text-end">${formatMoney(r.amount)}</td>
                        <td class="text-end">${Number(r.percentage || 0).toFixed(2)}%</td>
                    </tr>`).join('') + `
                    <tr class="fw-bold">
                        <td>Total</td>
                        <td class="text-end">${formatMoney(fees.total)}</td>
                        <td class="text-end">${Number(data.fee_rate || 0).toFixed(2)}%</td>
                    </tr>`
                : '<tr><td colspan="3" class="text-center text-muted py-4">Sem taxas no período.</td></tr>';

            const canvas = document.getElementById('feesChart');
            if (!canvas) return;
            if (this.feesChart) this.feesChart.destroy();
            this.feesChart = new Chart(canvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: rows.map(r => r.type),
                    datasets: [{
                        data: rows.map(r => r.amount || 0),
                        backgroundColor: ['#0d6efd', '#6f42c1', '#fd7e14'],
                    }],
                },
                options: {
                    responsive: true,
                    plugins: { legend: { position: 'bottom' } },
                },
            });
        },

        previousPeriod: function(start, end) {
            const startDate = new Date(start + 'T00:00:00');
            const endDate = new Date(end + 'T00:00:00');
            const days = Math.max(1, Math.round((endDate - startDate) / 86400000) + 1);
            const prevEnd = new Date(startDate);
            prevEnd.setDate(prevEnd.getDate() - 1);
            const prevStart = new Date(prevEnd);
            prevStart.setDate(prevStart.getDate() - (days - 1));
            const fmt = (d) => d.toISOString().slice(0, 10);
            return { start: fmt(prevStart), end: fmt(prevEnd), days };
        },

        loadCompareOnce: function() {
            if (this.compareLoadedFor === this.periodKey()) return;
            this.loadCompare();
        },

        loadCompare: async function() {
            const start = document.getElementById('date-start').value;
            const end = document.getElementById('date-end').value;
            const prev = this.previousPeriod(start, end);
            const cardsEl = document.getElementById('compare-cards');
            const tableRow = document.getElementById('compare-table-row');

            document.getElementById('compare-period-label').textContent =
                `Atual: ${start} → ${end}  ·  Anterior: ${prev.start} → ${prev.end} (${prev.days} dias)`;
            cardsEl.innerHTML = '<div class="col-12 text-center py-5" id="compare-loading"><div class="spinner-border text-primary"></div></div>';
            tableRow.style.display = 'none';

            try {
                const qs = new URLSearchParams({
                    current_start: start,
                    current_end: end,
                    previous_start: prev.start,
                    previous_end: prev.end,
                });
                const result = await requestJson(`/api/financials/compare?${qs}`);
                const data = result.data || result;
                if (!result.success) {
                    cardsEl.innerHTML = `<div class="col-12"><div class="alert alert-warning mb-0">${result.error || 'Não foi possível comparar períodos.'}</div></div>`;
                    return;
                }
                this.compareLoadedFor = this.periodKey();
                this.renderCompare(data);
                tableRow.style.display = '';
            } catch (e) {
                console.error(e);
                cardsEl.innerHTML = '<div class="col-12"><div class="alert alert-danger mb-0">Erro ao carregar comparativo.</div></div>';
            }
        },

        renderCompare: function(data) {
            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            const formatVar = (val) => {
                const n = Number(val || 0);
                const cls = n > 0 ? 'text-success' : (n < 0 ? 'text-danger' : 'text-muted');
                const sign = n > 0 ? '+' : '';
                return `<span class="${cls} fw-semibold">${sign}${n.toFixed(2)}%</span>`;
            };

            const variations = data.variations || {};
            document.getElementById('compare-cards').innerHTML = `
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Faturamento</div>
                    <div class="fs-5 fw-bold">${formatMoney(data.current?.gross_revenue)}</div>
                    <div class="small">${formatVar(variations.gross_revenue)}</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Lucro</div>
                    <div class="fs-5 fw-bold">${formatMoney(data.current?.net_profit)}</div>
                    <div class="small">${formatVar(variations.net_profit)}</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Vendas</div>
                    <div class="fs-5 fw-bold">${data.current?.total_orders ?? 0}</div>
                    <div class="small">${formatVar(variations.total_orders)}</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Margem (pp)</div>
                    <div class="fs-5 fw-bold">${Number(data.current?.avg_margin || 0).toFixed(2)}%</div>
                    <div class="small">${formatVar(variations.avg_margin)}</div>
                </div></div></div>`;

            const rows = [
                ['Faturamento', formatMoney(data.current?.gross_revenue), formatMoney(data.previous?.gross_revenue), formatVar(variations.gross_revenue)],
                ['Receita Líquida', formatMoney(data.current?.net_revenue), formatMoney(data.previous?.net_revenue), '—'],
                ['Lucro', formatMoney(data.current?.net_profit), formatMoney(data.previous?.net_profit), formatVar(variations.net_profit)],
                ['Vendas', data.current?.total_orders ?? 0, data.previous?.total_orders ?? 0, formatVar(variations.total_orders)],
                ['Unidades', data.current?.units_sold ?? 0, data.previous?.units_sold ?? 0, '—'],
                ['Margem', `${Number(data.current?.avg_margin || 0).toFixed(2)}%`, `${Number(data.previous?.avg_margin || 0).toFixed(2)}%`, formatVar(variations.avg_margin)],
                ['Comissões ML', formatMoney(data.current?.commissions), formatMoney(data.previous?.commissions), '—'],
                ['Fretes', formatMoney(data.current?.shipping_cost), formatMoney(data.previous?.shipping_cost), '—'],
            ];

            document.getElementById('compare-tbody').innerHTML = rows.map(([m, a, p, v]) => `
                <tr>
                    <td>${m}</td>
                    <td class="text-end">${a}</td>
                    <td class="text-end">${p}</td>
                    <td class="text-end">${v}</td>
                </tr>`).join('');
        },

        loadProjectionOnce: function() {
            const days = document.getElementById('projection-days')?.value || '30';
            const key = `proj|${days}`;
            if (this.projectionLoadedFor === key) return;
            this.loadProjection();
        },

        loadProjection: async function() {
            const days = document.getElementById('projection-days')?.value || '30';
            const cardsEl = document.getElementById('projection-cards');
            cardsEl.innerHTML = '<div class="col-12 text-center py-5" id="projection-loading"><div class="spinner-border text-primary"></div></div>';

            try {
                const result = await requestJson(`/api/financials/projection?days=${days}`);
                const data = result.data || result;
                if (!result.success) {
                    cardsEl.innerHTML = `<div class="col-12"><div class="alert alert-warning mb-0">${result.error || 'Não foi possível calcular a projeção.'}</div></div>`;
                    return;
                }
                this.projectionLoadedFor = `proj|${days}`;
                this.renderProjection(data);
            } catch (e) {
                console.error(e);
                cardsEl.innerHTML = '<div class="col-12"><div class="alert alert-danger mb-0">Erro ao carregar projeção.</div></div>';
            }
        },

        renderProjection: function(data) {
            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            const confidenceMap = { high: 'Alta', medium: 'Média', low: 'Baixa' };
            const confidenceClass = { high: 'success', medium: 'warning', low: 'secondary' };
            const conf = data.confidence || 'low';

            document.getElementById('projection-cards').innerHTML = `
                <div class="col-12 mb-3">
                    <span class="badge bg-${confidenceClass[conf] || 'secondary'}">Confiança: ${confidenceMap[conf] || conf}</span>
                    <span class="text-muted small ms-2">Base: últimos ${data.based_on_days || 30} dias · Horizonte: ${data.projection_period_days || 0} dias</span>
                </div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Receita projetada</div>
                    <div class="fs-5 fw-bold">${formatMoney(data.projected?.revenue)}</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Lucro projetado</div>
                    <div class="fs-5 fw-bold ${Number(data.projected?.profit || 0) >= 0 ? 'text-success' : 'text-danger'}">${formatMoney(data.projected?.profit)}</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Pedidos projetados</div>
                    <div class="fs-5 fw-bold">${data.projected?.orders ?? 0}</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Média diária (histórico)</div>
                    <div class="fs-5 fw-bold">${formatMoney(data.historical?.daily_avg_revenue)}</div>
                    <div class="text-muted small">${Number(data.historical?.daily_avg_orders || 0).toFixed(1)} pedidos/dia · margem ${Number(data.historical?.avg_margin || 0).toFixed(1)}%</div>
                </div></div></div>`;
        },

        escapeHtml: function(str) {
            return String(str ?? '').replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            }[c]));
        },

        formatDateTime: function(val) {
            if (!val) return '—';
            const d = new Date(val);
            if (Number.isNaN(d.getTime())) return this.escapeHtml(val);
            return d.toLocaleString('pt-BR');
        },

        loadClaimsOnce: function() {
            if (this.claimsLoadedFor === this.periodKey()) return;
            this.loadClaims();
        },

        loadClaims: async function() {
            const start = document.getElementById('date-start').value;
            const end = document.getElementById('date-end').value;
            const summaryEl = document.getElementById('claims-summary-cards');
            const tableRow = document.getElementById('claims-table-row');

            summaryEl.innerHTML = '<div class="col-12 text-center py-5" id="claims-loading"><div class="spinner-border text-primary"></div></div>';
            tableRow.style.display = 'none';

            try {
                const result = await requestJson(`/api/financials/claims/report?start_date=${start}&end_date=${end}`);
                const data = result.data || result;
                if (!result.success || data.error) {
                    summaryEl.innerHTML = `<div class="col-12"><div class="alert alert-warning mb-0">${this.escapeHtml(data.error || result.error || 'Não foi possível carregar reclamações.')}</div></div>`;
                    return;
                }
                this.claimsLoadedFor = this.periodKey();
                this.renderClaims(data);
                tableRow.style.display = '';
            } catch (e) {
                console.error(e);
                summaryEl.innerHTML = '<div class="col-12"><div class="alert alert-danger mb-0">Erro ao carregar reclamações.</div></div>';
            }
        },

        renderClaims: function(data) {
            const stats = data.statistics || {};
            const summary = data.summary || {};
            const statusBadge = (status) => {
                const map = { opened: 'warning', closed: 'success', unknown: 'secondary' };
                const cls = map[status] || 'secondary';
                return `<span class="badge bg-${cls}">${this.escapeHtml(status || '—')}</span>`;
            };

            document.getElementById('claims-summary-cards').innerHTML = `
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Total no período</div>
                    <div class="fs-5 fw-bold">${stats.total ?? 0}</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Abertas</div>
                    <div class="fs-5 fw-bold text-warning">${stats.opened ?? 0}</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Taxa de resolução</div>
                    <div class="fs-5 fw-bold">${Number(summary.resolution_rate || 0).toFixed(1)}%</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Risco reputação</div>
                    <div class="fs-5 fw-bold ${Number(summary.reputation_risk_count || 0) > 0 ? 'text-danger' : ''}">${summary.reputation_risk_count ?? 0}</div>
                    <div class="text-muted small">Tipo mais comum: ${this.escapeHtml(summary.most_common_type || 'N/D')}</div>
                </div></div></div>`;

            document.getElementById('claims-summary-meta').textContent =
                `${(data.claims || []).length} exibidas · gerado em ${data.generated_at || '—'}`;

            const claims = data.claims || [];
            const tbody = document.getElementById('claims-tbody');
            if (!claims.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Nenhuma reclamação no período.</td></tr>';
                return;
            }

            tbody.innerHTML = claims.map(c => `
                <tr>
                    <td><code>${this.escapeHtml(c.claim_id)}</code></td>
                    <td>${statusBadge(c.status)}</td>
                    <td>${this.escapeHtml(c.type)}</td>
                    <td>${this.escapeHtml(c.stage)}</td>
                    <td>${this.escapeHtml(c.reason_id)}</td>
                    <td>${this.formatDateTime(c.date_created)}</td>
                    <td>${this.escapeHtml(c.resource || c.resource_id || '—')}</td>
                </tr>`).join('');
        },

        loadSettlementsOnce: function() {
            if (this.settlementsLoadedFor === this.periodKey()) return;
            this.loadSettlements();
        },

        loadSettlements: async function() {
            const start = document.getElementById('date-start').value;
            const end = document.getElementById('date-end').value;
            const summaryEl = document.getElementById('settlements-summary-cards');
            const tableRow = document.getElementById('settlements-table-row');

            summaryEl.innerHTML = '<div class="col-12 text-center py-5" id="settlements-loading"><div class="spinner-border text-primary"></div></div>';
            tableRow.style.display = 'none';

            try {
                const result = await requestJson(`/api/financials/settlements?start=${start}&end=${end}`);
                const data = result.data || result;
                if (!result.success || data.error) {
                    summaryEl.innerHTML = `<div class="col-12"><div class="alert alert-warning mb-0">${this.escapeHtml(data.error || result.error || 'Não foi possível carregar liquidações.')}</div></div>`;
                    return;
                }
                this.settlementsLoadedFor = this.periodKey();
                this.renderSettlements(data);
                tableRow.style.display = '';
            } catch (e) {
                console.error(e);
                summaryEl.innerHTML = '<div class="col-12"><div class="alert alert-danger mb-0">Erro ao carregar liquidações.</div></div>';
            }
        },

        normalizeSettlementRow: function(row) {
            // API remota e fallback local podem ter shapes diferentes
            return {
                date: row.date_released || row.date || row.release_date || row.transaction_date || null,
                description: row.description || row.detail || row.concept || '—',
                type: row.type || row.transaction_type || row.movement_type || '—',
                order_id: row.order_id || row.external_reference || row.ml_record_id || '—',
                gross: Number(row.gross_amount ?? row.gross ?? row.amount ?? 0),
                fee: Number(row.fee_amount ?? row.fee ?? row.commission ?? 0),
                net: Number(row.net_amount ?? row.net ?? row.settlement_net ?? (Number(row.gross_amount ?? row.amount ?? 0) - Number(row.fee_amount ?? 0))),
                status: row.status || row.state || '—',
            };
        },

        renderSettlements: function(data) {
            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            const raw = Array.isArray(data.results) ? data.results : (Array.isArray(data) ? data : []);
            const rows = raw.map(r => this.normalizeSettlementRow(r));

            const totalGross = rows.reduce((s, r) => s + r.gross, 0);
            const totalFee = rows.reduce((s, r) => s + r.fee, 0);
            const totalNet = rows.reduce((s, r) => s + r.net, 0);

            document.getElementById('settlements-summary-cards').innerHTML = `
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Movimentos</div>
                    <div class="fs-5 fw-bold">${rows.length}</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Bruto liberado</div>
                    <div class="fs-5 fw-bold">${formatMoney(totalGross)}</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Taxas</div>
                    <div class="fs-5 fw-bold text-danger">${formatMoney(totalFee)}</div>
                </div></div></div>
                <div class="col-6 col-md-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Líquido</div>
                    <div class="fs-5 fw-bold text-success">${formatMoney(totalNet)}</div>
                </div></div></div>`;

            const sourceMap = {
                api: 'Fonte: API Mercado Pago/ML',
                local: 'Fonte: dados locais',
                orders_estimated: 'Fonte: estimativa a partir das vendas (sem settlements sincronizados)',
                none: 'Fonte: indisponível (sem conta ML)',
            };
            const sourceLabel = sourceMap[data.source] || 'Fonte: mista';
            document.getElementById('settlements-source-label').textContent = sourceLabel;
            if (data.note) {
                document.getElementById('settlements-source-label').textContent = sourceLabel + ' — ' + data.note;
            }

            const tbody = document.getElementById('settlements-tbody');
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Nenhuma liquidação no período.</td></tr>';
                return;
            }

            tbody.innerHTML = rows.slice(0, 200).map(r => `
                <tr>
                    <td>${this.formatDateTime(r.date)}</td>
                    <td>${this.escapeHtml(r.description)}</td>
                    <td>${this.escapeHtml(r.type)}</td>
                    <td><code>${this.escapeHtml(r.order_id)}</code></td>
                    <td class="text-end">${formatMoney(r.gross)}</td>
                    <td class="text-end">${formatMoney(r.fee)}</td>
                    <td class="text-end fw-semibold">${formatMoney(r.net)}</td>
                    <td><span class="badge bg-secondary">${this.escapeHtml(r.status)}</span></td>
                </tr>`).join('');
        },

        loadCashflowOnce: function() {
            if (this.cashflowLoadedFor === this.periodKey()) return;
            this.loadCashflow();
        },

        loadCashflow: async function() {
            const start = document.getElementById('date-start').value;
            const end = document.getElementById('date-end').value;
            const summaryEl = document.getElementById('cashflow-summary-cards');
            const detailRow = document.getElementById('cashflow-detail-row');

            summaryEl.innerHTML = '<div class="col-12 text-center py-5" id="cashflow-loading"><div class="spinner-border text-primary"></div></div>';
            detailRow.style.display = 'none';

            try {
                const result = await requestJson(`/api/financials/cashflow?start=${start}&end=${end}`);
                const data = result.data || result;
                if (!result.success || data.error) {
                    summaryEl.innerHTML = `<div class="col-12"><div class="alert alert-warning mb-0">${this.escapeHtml(data.error || result.error || 'Não foi possível carregar o fluxo de caixa.')}</div></div>`;
                    return;
                }
                this.cashflowLoadedFor = this.periodKey();
                this.renderCashflow(data);
                detailRow.style.display = '';
            } catch (e) {
                console.error(e);
                summaryEl.innerHTML = '<div class="col-12"><div class="alert alert-danger mb-0">Erro ao carregar fluxo de caixa.</div></div>';
            }
        },

        renderCashflow: function(data) {
            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            const inflows = data.inflows || {};
            const outflows = data.outflows || {};
            const balance = Number(data.balance || 0);

            document.getElementById('cashflow-summary-cards').innerHTML = `
                <div class="col-6 col-md-4 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Entradas</div>
                    <div class="fs-5 fw-bold text-success">${formatMoney(inflows.total)}</div>
                    <div class="text-muted small">${inflows.transactions ?? 0} transações</div>
                </div></div></div>
                <div class="col-6 col-md-4 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Saídas</div>
                    <div class="fs-5 fw-bold text-danger">${formatMoney(outflows.total)}</div>
                </div></div></div>
                <div class="col-12 col-md-4 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                    <div class="text-muted small mb-1">Saldo do período</div>
                    <div class="fs-5 fw-bold ${balance >= 0 ? 'text-success' : 'text-danger'}">${formatMoney(balance)}</div>
                </div></div></div>`;

            document.getElementById('cashflow-inflows-tbody').innerHTML = `
                <tr><td>Vendas (receita bruta)</td><td class="text-end fw-semibold text-success">${formatMoney(inflows.sales)}</td></tr>
                <tr class="fw-bold"><td>Total entradas</td><td class="text-end text-success">${formatMoney(inflows.total)}</td></tr>`;

            document.getElementById('cashflow-outflows-tbody').innerHTML = `
                <tr><td>Comissões ML</td><td class="text-end">${formatMoney(outflows.commissions)}</td></tr>
                <tr><td>Taxas de pagamento</td><td class="text-end">${formatMoney(outflows.payment_fees)}</td></tr>
                <tr><td>Taxas fixas</td><td class="text-end">${formatMoney(outflows.fixed_fees)}</td></tr>
                <tr><td>Fretes</td><td class="text-end">${formatMoney(outflows.shipping)}</td></tr>
                <tr><td>CMV (custo produtos)</td><td class="text-end">${formatMoney(outflows.cogs)}</td></tr>
                <tr><td>Impostos</td><td class="text-end">${formatMoney(outflows.taxes)}</td></tr>
                <tr class="fw-bold"><td>Total saídas</td><td class="text-end text-danger">${formatMoney(outflows.total)}</td></tr>`;

            const canvas = document.getElementById('cashflowChart');
            if (!canvas) return;
            if (this.cashflowChart) this.cashflowChart.destroy();

            const outflowLabels = ['Comissões', 'Pagamento', 'Fixas', 'Fretes', 'CMV', 'Impostos'];
            const outflowValues = [
                outflows.commissions || 0,
                outflows.payment_fees || 0,
                outflows.fixed_fees || 0,
                outflows.shipping || 0,
                outflows.cogs || 0,
                outflows.taxes || 0,
            ];

            this.cashflowChart = new Chart(canvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: ['Entradas', ...outflowLabels],
                    datasets: [{
                        label: 'Valor (R$)',
                        data: [inflows.total || 0, ...outflowValues],
                        backgroundColor: [
                            'rgba(25, 135, 84, 0.7)',
                            'rgba(13, 110, 253, 0.55)',
                            'rgba(111, 66, 193, 0.55)',
                            'rgba(253, 126, 20, 0.55)',
                            'rgba(220, 53, 69, 0.45)',
                            'rgba(108, 117, 125, 0.55)',
                            'rgba(214, 51, 132, 0.45)',
                        ],
                    }],
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } },
                },
            });
        },

        loadProfitabilityOnce: function() {
            const limit = document.getElementById('profitability-limit')?.value || '20';
            const key = `${this.periodKey()}|${limit}`;
            if (this.profitabilityLoadedFor === key) return;
            this.loadProfitability();
        },

        loadProfitability: async function() {
            const start = document.getElementById('date-start').value;
            const end = document.getElementById('date-end').value;
            const limit = document.getElementById('profitability-limit')?.value || '20';
            const contentEl = document.getElementById('profitability-content');

            contentEl.innerHTML = '<div class="col-12 text-center py-5" id="profitability-loading"><div class="spinner-border text-primary"></div></div>';

            try {
                const result = await requestJson(`/api/financials/profitability?start=${start}&end=${end}&limit=${limit}`);
                const data = result.data || result;
                if (!result.success || data.error) {
                    contentEl.innerHTML = `<div class="col-12"><div class="alert alert-warning mb-0">${this.escapeHtml(data.error || result.error || 'Não foi possível carregar a lucratividade.')}</div></div>`;
                    return;
                }
                this.profitabilityLoadedFor = `${this.periodKey()}|${limit}`;
                this.renderProfitability(data);
            } catch (e) {
                console.error(e);
                contentEl.innerHTML = '<div class="col-12"><div class="alert alert-danger mb-0">Erro ao carregar lucratividade.</div></div>';
            }
        },

        renderProductRows: function(products) {
            const formatMoney = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);
            if (!products || !products.length) {
                return '<tr><td colspan="5" class="text-center text-muted py-4">Sem produtos neste ranking.</td></tr>';
            }
            return products.map(p => `
                <tr>
                    <td>${this.escapeHtml(p.title || p.item_id)}</td>
                    <td class="text-end">${p.sales ?? 0}</td>
                    <td class="text-end">${formatMoney(p.revenue)}</td>
                    <td class="text-end ${Number(p.profit || 0) >= 0 ? 'text-success' : 'text-danger'}">${formatMoney(p.profit)}</td>
                    <td class="text-end">${Number(p.avg_margin || 0).toFixed(1)}%</td>
                </tr>`).join('');
        },

        renderProfitability: function(data) {
            const top = data.top_profitable || [];
            const worst = data.least_profitable || [];

            document.getElementById('profitability-content').innerHTML = `
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 text-success">Mais lucrativos</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Produto</th>
                                            <th class="text-end">Vendas</th>
                                            <th class="text-end">Receita</th>
                                            <th class="text-end">Lucro</th>
                                            <th class="text-end">Margem</th>
                                        </tr>
                                    </thead>
                                    <tbody>${this.renderProductRows(top)}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 text-danger">Menos lucrativos</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Produto</th>
                                            <th class="text-end">Vendas</th>
                                            <th class="text-end">Receita</th>
                                            <th class="text-end">Lucro</th>
                                            <th class="text-end">Margem</th>
                                        </tr>
                                    </thead>
                                    <tbody>${this.renderProductRows(worst)}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>`;
        }
    };

    document.getElementById('abc-class-filter')?.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-class]');
        if (!btn) return;

        document.querySelectorAll('#abc-class-filter button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        window.financialManager.abcActiveClass = btn.dataset.class;
        window.financialManager.renderAbcProducts();
    });

    document.addEventListener('DOMContentLoaded', () => window.financialManager.init());
</script>
