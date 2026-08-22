<?php

declare(strict_types=1);

/**
 * Página de Histórico de Preços
 *
 * Dashboard com gráficos Chart.js para visualizar:
 * - Histórico de preços
 * - Evolução de margens
 * - Comparativo com concorrência
 * - Alertas de ranking
 */

$pageTitle = 'Histórico de Preços - Precificador Inteligente';
$accountId = $accountId ?? \App\Helpers\SessionHelper::getActiveAccountId();
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        body {
            background: #f8f9fc;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        .page-header {
            background: var(--primary-gradient);
            color: white;
            padding: 2rem;
            border-radius: 0 0 20px 20px;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .chart-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .chart-title {
            font-weight: 600;
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        }

        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-card .stat-label {
            font-size: 0.85rem;
            color: #666;
        }

        .stat-card.excellent {
            border-left: 4px solid #38ef7d;
        }

        .stat-card.good {
            border-left: 4px solid #4facfe;
        }

        .stat-card.warning {
            border-left: 4px solid #f5576c;
        }

        .stat-card.danger {
            border-left: 4px solid #dc3545;
        }

        .item-selector {
            max-width: 500px;
        }

        .item-selector .form-control {
            border-radius: 30px;
            padding: 0.75rem 1.25rem;
        }

        .period-selector .btn {
            border-radius: 20px;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        .alert-timeline {
            max-height: 400px;
            overflow-y: auto;
        }

        .alert-item {
            padding: 0.75rem;
            border-left: 3px solid;
            margin-bottom: 0.5rem;
            background: #f8f9fc;
            border-radius: 0 8px 8px 0;
        }

        .alert-item.danger {
            border-color: #dc3545;
        }

        .alert-item.warning {
            border-color: #ffc107;
        }

        .alert-item.good {
            border-color: #0dcaf0;
        }

        .alert-item.excellent {
            border-color: #198754;
        }

        .competitor-table {
            font-size: 0.9rem;
        }

        .competitor-table .price-cell {
            font-weight: 600;
        }

        .competitor-table .position-badge {
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .loading-overlay.active {
            display: flex;
        }

        @media (max-width: 768px) {
            .chart-container {
                height: 250px;
            }
        }
    </style>
</head>

<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="text-center">
            <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;"></div>
            <p class="text-muted">Carregando dados...</p>
        </div>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="mb-2">
                        <i class="bi bi-graph-up-arrow me-2"></i>
                        Histórico de Preços
                    </h1>
                    <p class="mb-0 opacity-75">
                        Análise detalhada de evolução de preços, margens e posicionamento
                    </p>
                </div>
                <div class="col-lg-6">
                    <div class="item-selector ms-auto">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" class="form-control border-start-0"
                                id="itemSearch" list="itemSearchList"
                                placeholder="Buscar item por MLB, SKU ou título...">
                            <datalist id="itemSearchList"></datalist>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <!-- Period Selector -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="text-muted">Período:</span>
                    <div class="period-selector btn-group">
                        <button class="btn btn-outline-primary" data-period="7">7 dias</button>
                        <button class="btn btn-outline-primary active" data-period="30">30 dias</button>
                        <button class="btn btn-outline-primary" data-period="90">90 dias</button>
                        <button class="btn btn-outline-primary" data-period="180">6 meses</button>
                    </div>
                    <div class="ms-auto">
                        <button class="btn btn-light" onclick="exportData()">
                            <i class="bi bi-download me-1"></i> Exportar
                        </button>
                        <button class="btn btn-light" onclick="refreshData()">
                            <i class="bi bi-arrow-clockwise me-1"></i> Atualizar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4" id="statsCards">
            <div class="col-6 col-lg-3 mb-3">
                <div class="stat-card excellent">
                    <div class="stat-value text-success" id="statExcellent">-</div>
                    <div class="stat-label">Excelente (0-8%)</div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="stat-card good">
                    <div class="stat-value text-info" id="statGood">-</div>
                    <div class="stat-label">Bom (8-12%)</div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="stat-card warning">
                    <div class="stat-value text-warning" id="statWarning">-</div>
                    <div class="stat-label">Atenção (12-15%)</div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="stat-card danger">
                    <div class="stat-value text-danger" id="statDanger">-</div>
                    <div class="stat-label">Crítico (&gt;15%)</div>
                </div>
            </div>
        </div>

        <!-- Main Charts Row -->
        <div class="row">
            <!-- Price History Chart -->
            <div class="col-lg-8 mb-4">
                <div class="chart-card">
                    <div class="chart-title">
                        <i class="bi bi-graph-up text-primary"></i>
                        Histórico de Preços
                    </div>
                    <div class="chart-container">
                        <canvas id="priceHistoryChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Alert Timeline -->
            <div class="col-lg-4 mb-4">
                <div class="chart-card">
                    <div class="chart-title">
                        <i class="bi bi-bell text-warning"></i>
                        Alertas Recentes
                        <span class="badge bg-danger ms-auto" id="pendingAlertsCount">0</span>
                    </div>
                    <div class="alert-timeline" id="alertTimeline">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Nenhum alerta recente
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Second Row Charts -->
        <div class="row">
            <!-- Margin Evolution -->
            <div class="col-lg-6 mb-4">
                <div class="chart-card">
                    <div class="chart-title">
                        <i class="bi bi-percent text-success"></i>
                        Evolução de Margem
                    </div>
                    <div class="chart-container">
                        <canvas id="marginChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Ranking Position -->
            <div class="col-lg-6 mb-4">
                <div class="chart-card">
                    <div class="chart-title">
                        <i class="bi bi-trophy text-info"></i>
                        Posição no Ranking
                    </div>
                    <div class="chart-container">
                        <canvas id="rankingChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Third Row -->
        <div class="row">
            <!-- Competitor Comparison -->
            <div class="col-lg-6 mb-4">
                <div class="chart-card">
                    <div class="chart-title">
                        <i class="bi bi-people text-primary"></i>
                        Comparativo com Concorrência
                    </div>
                    <div class="table-responsive">
                        <table class="table competitor-table mb-0" id="competitorTable">
                            <thead>
                                <tr>
                                    <th>Pos</th>
                                    <th>Vendedor</th>
                                    <th class="text-end">Preço</th>
                                    <th class="text-end">Diferença</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        Selecione um item para ver comparativo
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Distribution Chart -->
            <div class="col-lg-6 mb-4">
                <div class="chart-card">
                    <div class="chart-title">
                        <i class="bi bi-pie-chart text-warning"></i>
                        Distribuição por Faixa de Ranking
                    </div>
                    <div class="chart-container">
                        <canvas id="distributionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Price Changes Table -->
        <div class="row">
            <div class="col-12 mb-4">
                <div class="chart-card">
                    <div class="chart-title">
                        <i class="bi bi-clock-history text-secondary"></i>
                        Últimas Alterações de Preço
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="priceChangesTable">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Item</th>
                                    <th class="text-end">Preço Anterior</th>
                                    <th class="text-end">Preço Novo</th>
                                    <th class="text-end">Variação</th>
                                    <th>Motivo</th>
                                </tr>
                            </thead>
                            <tbody id="priceChangesBody">
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <div class="spinner-border spinner-border-sm me-2"></div>
                                        Carregando histórico...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?= CSP_NONCE ?>">
        // Configuration
        const API_BASE = '/api/pricing-intelligence/<?= $accountId ?>';
        let selectedPeriod = 30;
        let selectedItemId = null;
        let charts = {};

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            initializeCharts();
            initFromUrl();
            loadDashboardData();
            setupEventListeners();
        });

        function initFromUrl() {
            const params = new URLSearchParams(window.location.search);
            const itemId = params.get('item_id') || params.get('itemId');
            if (itemId) {
                const input = document.getElementById('itemSearch');
                input.value = itemId;
                applySelectedItem(itemId);
            }
        }

        // Setup event listeners
        function setupEventListeners() {
            // Period selector
            document.querySelectorAll('.period-selector .btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.period-selector .btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    selectedPeriod = parseInt(btn.dataset.period);
                    loadDashboardData();
                });
            });

            // Item search
            let searchTimeout;
            document.getElementById('itemSearch').addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => searchItems(e.target.value), 300);
            });

            document.getElementById('itemSearch').addEventListener('change', (e) => {
                applySelectedItem(e.target.value);
            });

            document.getElementById('itemSearch').addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    applySelectedItem(e.target.value);
                }
            });
        }

        function applySelectedItem(value) {
            const trimmed = (value || '').trim();
            // Aceita diretamente o item_id do ML (ex.: MLB123)
            if (trimmed && trimmed.toUpperCase().startsWith('MLB')) {
                selectedItemId = trimmed.toUpperCase();
                loadDashboardData();
                return;
            }
            selectedItemId = null;
            clearCharts();
        }

        // Initialize Chart.js charts
        function initializeCharts() {
            // Price History Chart
            charts.priceHistory = new Chart(document.getElementById('priceHistoryChart'), {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Seu Preço',
                        data: [],
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Preço Médio',
                        data: [],
                        borderColor: '#f5576c',
                        borderDash: [5, 5],
                        tension: 0.4,
                        fill: false
                    }, {
                        label: 'Preço Mínimo',
                        data: [],
                        borderColor: '#38ef7d',
                        borderDash: [3, 3],
                        tension: 0.4,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            ticks: {
                                callback: value => 'R$ ' + value.toFixed(2)
                            }
                        }
                    }
                }
            });

            // Margin Evolution Chart
            charts.margin = new Chart(document.getElementById('marginChart'), {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Margem Líquida (%)',
                        data: [],
                        borderColor: '#11998e',
                        backgroundColor: 'rgba(17, 153, 142, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: value => value + '%'
                            }
                        }
                    }
                }
            });

            // Ranking Position Chart
            charts.ranking = new Chart(document.getElementById('rankingChart'), {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Posição no Ranking (%)',
                        data: [],
                        borderColor: '#4facfe',
                        backgroundColor: 'rgba(79, 172, 254, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    },
                    scales: {
                        y: {
                            reverse: true, // Lower is better
                            min: 0,
                            max: 100,
                            ticks: {
                                callback: value => value + '%'
                            }
                        }
                    }
                }
            });

            // Distribution Chart (Doughnut)
            charts.distribution = new Chart(document.getElementById('distributionChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Excelente (0-8%)', 'Bom (8-12%)', 'Atenção (12-15%)', 'Crítico (>15%)'],
                    datasets: [{
                        data: [0, 0, 0, 0],
                        backgroundColor: ['#38ef7d', '#4facfe', '#ffc107', '#dc3545'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }

        // Load dashboard data
        async function loadDashboardData() {
            showLoading(true);
            try {
                await Promise.all([
                    loadAlertStats(),
                    loadPriceHistory(),
                    loadAlerts()
                ]);
            } catch (error) {
                console.error('Error loading dashboard:', error);
                showToast('Erro ao carregar dados', 'danger');
            } finally {
                showLoading(false);
            }
        }

        // Load alert statistics
        async function loadAlertStats() {
            try {
                const response = await fetch(`${API_BASE}/alerts/stats?days=${selectedPeriod}`);
                const data = await response.json();

                if (data.success && data.stats) {
                    const stats = data.stats;

                    // Update stat cards
                    const byType = {};
                    const sourceByType = (stats.ranking_distribution && Array.isArray(stats.ranking_distribution.by_type))
                        ? stats.ranking_distribution.by_type
                        : (stats.by_type || []);

                    (sourceByType || []).forEach(s => {
                        byType[s.alert_type] = s.total;
                    });

                    document.getElementById('statExcellent').textContent = byType.excellent || 0;
                    document.getElementById('statGood').textContent = byType.good || 0;
                    document.getElementById('statWarning').textContent = byType.warning || 0;
                    document.getElementById('statDanger').textContent = byType.danger || 0;

                    // Update distribution chart
                    charts.distribution.data.datasets[0].data = [
                        byType.excellent || 0,
                        byType.good || 0,
                        byType.warning || 0,
                        byType.danger || 0
                    ];
                    charts.distribution.update();
                }
            } catch (error) {
                console.error('Error loading alert stats:', error);
            }
        }

        // Load price history
        async function loadPriceHistory() {
            if (!selectedItemId) {
                // Sem item selecionado: não mostrar dados fictícios
                clearCharts();
                return;
            }

            try {
                const response = await fetch(`${API_BASE}/history/${selectedItemId}?days=${selectedPeriod}`);
                const data = await response.json();

                const history = data.history || [];
                if (data.success && Array.isArray(history) && history.length > 0) {
                    const labels = history.map(h => h.date);
                    const prices = history.map(h => h.price);
                    const avgPrices = history.map(h => h.avg_price ?? null);
                    const minPrices = history.map(h => h.min_price ?? null);
                    const margins = history.map(h => h.margin || 0);
                    const rankings = history.map(h => h.position_percentage || 50);

                    // Update price history chart
                    charts.priceHistory.data.labels = labels;
                    charts.priceHistory.data.datasets[0].data = prices;
                    charts.priceHistory.data.datasets[1].data = avgPrices;
                    charts.priceHistory.data.datasets[2].data = minPrices;
                    charts.priceHistory.update();

                    // Update margin chart
                    charts.margin.data.labels = labels;
                    charts.margin.data.datasets[0].data = margins;
                    charts.margin.update();

                    // Update ranking chart
                    charts.ranking.data.labels = labels;
                    charts.ranking.data.datasets[0].data = rankings;
                    charts.ranking.update();

                    // Update changes table
                    renderPriceChangesTable(data.historico || [], selectedItemId);
                } else {
                    clearCharts();
                    renderPriceChangesTable([], selectedItemId);
                }
            } catch (error) {
                console.error('Error loading price history:', error);
                clearCharts();
                renderPriceChangesTable([], selectedItemId);
            }
        }

        function clearCharts() {
            charts.priceHistory.data.labels = [];
            charts.priceHistory.data.datasets.forEach(ds => ds.data = []);
            charts.priceHistory.update();

            charts.margin.data.labels = [];
            charts.margin.data.datasets[0].data = [];
            charts.margin.update();

            charts.ranking.data.labels = [];
            charts.ranking.data.datasets[0].data = [];
            charts.ranking.update();
        }

        function renderPriceChangesTable(historico, itemId) {
            const body = document.getElementById('priceChangesBody');
            if (!Array.isArray(historico) || historico.length === 0) {
                body.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            Nenhum histórico encontrado${itemId ? ` para ${escapeHtml(itemId)}` : ''}.
                        </td>
                    </tr>
                `;
                return;
            }

            body.innerHTML = historico.slice(0, 50).map(row => {
                const dt = row.data_mudanca ? new Date(row.data_mudanca) : null;
                const dateLabel = dt && !isNaN(dt) ? dt.toLocaleString('pt-BR') : (row.data_mudanca || '-');
                const prev = row.preco_anterior !== undefined ? Number(row.preco_anterior) : null;
                const next = row.preco_novo !== undefined ? Number(row.preco_novo) : null;
                const varPct = row.percentual_mudanca !== undefined ? Number(row.percentual_mudanca) : null;
                return `
                    <tr>
                        <td>${escapeHtml(dateLabel)}</td>
                        <td>${escapeHtml(itemId || selectedItemId || '')}</td>
                        <td class="text-end">${prev !== null ? 'R$ ' + prev.toFixed(2) : '-'}</td>
                        <td class="text-end">${next !== null ? 'R$ ' + next.toFixed(2) : '-'}</td>
                        <td class="text-end">${varPct !== null ? varPct.toFixed(2) + '%' : '-'}</td>
                        <td>${escapeHtml(row.motivo || row.origem || '-') }</td>
                    </tr>
                `;
            }).join('');
        }

        // Load alerts
        async function loadAlerts() {
            try {
                const response = await fetch(`${API_BASE}/alerts?limit=10`);
                const data = await response.json();

                const timeline = document.getElementById('alertTimeline');
                const pendingBadge = document.getElementById('pendingAlertsCount');

                let alerts = data.alerts;
                if (!Array.isArray(alerts) && Array.isArray(data.alertas)) {
                    alerts = data.alertas.map(a => {
                        const nivel = a.nivel;
                        const alertType = nivel === 'vermelho' ? 'danger' : (nivel === 'amarelo' ? 'warning' : 'excellent');
                        const title = a.tipo_alerta || 'Alerta';
                        return {
                            alert_type: alertType,
                            title,
                            alert_message: a.mensagem,
                            created_at: a.criado_em,
                            suggested_price: a.preco_recomendado,
                            is_resolved: !!a.resolvido,
                        };
                    });
                }
                alerts = Array.isArray(alerts) ? alerts : [];

                if (data.success && alerts.length > 0) {
                    const pendingCount = alerts.filter(a => !a.is_resolved).length;
                    pendingBadge.textContent = pendingCount;

                    timeline.innerHTML = alerts.slice(0, 10).map(alert => `
                        <div class="alert-item ${alert.alert_type}">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong class="d-block">${escapeHtml(alert.title?.substring(0, 40))}...</strong>
                                    <small class="text-muted">${alert.alert_message?.substring(0, 60)}...</small>
                                </div>
                                <small class="text-muted text-nowrap ms-2">
                                    ${formatRelativeTime(alert.created_at)}
                                </small>
                            </div>
                            ${alert.suggested_price ? `
                                <div class="mt-1">
                                    <small class="text-success">
                                        Sugestão: R$ ${parseFloat(alert.suggested_price).toFixed(2)}
                                    </small>
                                </div>
                            ` : ''}
                        </div>
                    `).join('');
                } else {
                    pendingBadge.textContent = '0';
                    timeline.innerHTML = `
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-check-circle fs-2 d-block mb-2 text-success"></i>
                            Nenhum alerta pendente
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading alerts:', error);
            }
        }

        // Search items
        async function searchItems(query) {
            if (query.length < 3) return;

            const datalist = document.getElementById('itemSearchList');
            try {
                const response = await fetch(`${API_BASE}/items?q=${encodeURIComponent(query)}&limit=10`);
                const data = await response.json();

                if (!data.success || !Array.isArray(data.items)) {
                    return;
                }

                datalist.innerHTML = data.items.map(item => {
                    const id = (item.id || '').toString();
                    const title = (item.titulo || '').toString();
                    return `<option value="${escapeHtml(id)}">${escapeHtml(title)}</option>`;
                }).join('');
            } catch (error) {
                console.error('Error searching items:', error);
            }
        }

        // Refresh data
        function refreshData() {
            loadDashboardData();
        }

        // Export data
        function exportData() {
            const url = `${API_BASE}/export/history?days=${selectedPeriod}`;
            window.open(url, '_blank');
        }

        // Utility functions
        function showLoading(show) {
            document.getElementById('loadingOverlay').classList.toggle('active', show);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        function formatRelativeTime(dateStr) {
            const date = new Date(dateStr);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000);

            if (diff < 60) return 'agora';
            if (diff < 3600) return Math.floor(diff / 60) + 'min';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h';
            return Math.floor(diff / 86400) + 'd';
        }

        function showToast(message, type = 'info') {
            // Simple toast notification
            const toast = document.createElement('div');
            toast.className = `alert alert-${type} position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 250px;';
            toast.innerHTML = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
    </script>
</body>

</html>
