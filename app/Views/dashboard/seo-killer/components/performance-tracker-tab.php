<!-- Performance Tracker Tab -->
<div class="tab-pane fade" id="performance-tracker" role="tabpanel" aria-labelledby="performance-tracker-tab">
    <div class="container-fluid py-4">

        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">
                            <i class="bi bi-graph-up-arrow text-success me-2"></i>
                            Performance Tracker
                        </h2>
                        <p class="text-muted mb-0">Acompanhe o impacto real das otimizações SEO</p>
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-primary" id="refreshPerformanceBtn">
                            <i class="bi bi-arrow-clockwise"></i> Atualizar
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="exportPerformanceBtn">
                            <i class="bi bi-download"></i> Exportar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 1: Overview Geral -->
        <div class="row mb-4">
            <div class="col-12">
                <h4 class="mb-3">
                    <i class="bi bi-speedometer2 text-primary me-2"></i>
                    Overview Geral
                </h4>
            </div>

            <!-- Metric Cards -->
            <div class="col-md-6 col-xl-3 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <p class="text-muted mb-1 small">Otimizações Realizadas</p>
                                <h3 class="mb-0" id="totalOptimizations">-</h3>
                            </div>
                            <div class="bg-primary bg-opacity-10 rounded-3 p-2">
                                <i class="bi bi-rocket-takeoff fs-4 text-primary"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
                            <small class="text-success me-1" id="optimizationsChange">
                                <i class="bi bi-arrow-up"></i> 0%
                            </small>
                            <small class="text-muted">últimos 30 dias</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-3 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <p class="text-muted mb-1 small">Score Médio</p>
                                <h3 class="mb-0">
                                    <span id="averageScoreBefore" class="text-muted small">-</span>
                                    <i class="bi bi-arrow-right mx-1 small"></i>
                                    <span id="averageScoreAfter" class="text-success">-</span>
                                </h3>
                            </div>
                            <div class="bg-success bg-opacity-10 rounded-3 p-2">
                                <i class="bi bi-award fs-4 text-success"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
                            <small class="text-success me-1" id="scoreImprovement">
                                <i class="bi bi-arrow-up"></i> +0pts
                            </small>
                            <small class="text-muted">melhoria</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-3 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <p class="text-muted mb-1 small">ROI Estimado</p>
                                <h3 class="mb-0" id="estimatedROI">R$ -</h3>
                            </div>
                            <div class="bg-warning bg-opacity-10 rounded-3 p-2">
                                <i class="bi bi-currency-dollar fs-4 text-warning"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
                            <small class="text-success me-1" id="roiChange">
                                <i class="bi bi-arrow-up"></i> +0%
                            </small>
                            <small class="text-muted">vs período anterior</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-3 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <p class="text-muted mb-1 small">Conversões</p>
                                <h3 class="mb-0" id="conversionsIncrease">+0%</h3>
                            </div>
                            <div class="bg-info bg-opacity-10 rounded-3 p-2">
                                <i class="bi bi-cart-check fs-4 text-info"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
                            <small class="text-success me-1" id="viewsIncrease">
                                <i class="bi bi-eye"></i> +0% views
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Evolution Chart -->
            <div class="col-12 mt-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title mb-3">Evolução do Score Médio (Últimos 30 Dias)</h5>
                        <div id="scoreEvolutionChart" style="height: 300px;">
                            <!-- Chart will be rendered here -->
                            <div class="d-flex justify-content-center align-items-center h-100">
                                <div class="text-center">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Carregando...</span>
                                    </div>
                                    <p class="text-muted mt-2 mb-0">Carregando dados...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Top Performers -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <h4 class="mb-0">
                            <i class="bi bi-trophy-fill text-warning me-2"></i>
                            Top 10 Performers
                        </h4>
                        <p class="text-muted mb-0 small">Produtos com maior melhoria após otimização</p>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="topPerformersTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th style="width: 80px;">Imagem</th>
                                        <th>Produto</th>
                                        <th style="width: 150px;">Score</th>
                                        <th style="width: 120px;">Vendas</th>
                                        <th style="width: 120px;">Otimizado em</th>
                                        <th style="width: 100px;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="topPerformersBody">
                                    <!-- Loading state -->
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Carregando...</span>
                                            </div>
                                            <p class="text-muted mt-2 mb-0">Carregando top performers...</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 3: Análise Individual -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <h4 class="mb-0">
                            <i class="bi bi-bar-chart-line text-info me-2"></i>
                            Análise Individual
                        </h4>
                        <p class="text-muted mb-0 small">Análise detalhada de um produto específico</p>
                    </div>
                    <div class="card-body">
                        <!-- Product Selection -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <label for="individualProductSelect" class="form-label">
                                    Selecione um produto:
                                </label>
                                <select class="form-select" id="individualProductSelect">
                                    <option value="">Carregando produtos...</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="button" class="btn btn-primary w-100" id="analyzeProductBtn" disabled>
                                    <i class="bi bi-search me-2"></i>Analisar
                                </button>
                            </div>
                        </div>

                        <!-- Analysis Results (hidden by default) -->
                        <div id="individualAnalysisResults" style="display: none;">
                            <!-- Comparison Chart -->
                            <div class="row mb-4">
                                <div class="col-12">
                                    <h5>Comparação Antes vs Depois</h5>
                                    <div id="comparisonChart" style="height: 300px;">
                                        <!-- Chart will be rendered here -->
                                    </div>
                                </div>
                            </div>

                            <!-- Metrics Cards -->
                            <div class="row mb-4">
                                <div class="col-md-3 mb-3">
                                    <div class="card border-primary">
                                        <div class="card-body text-center">
                                            <i class="bi bi-eye-fill text-primary fs-3 mb-2"></i>
                                            <p class="text-muted mb-1 small">Visualizações</p>
                                            <h4 class="mb-0" id="individualViews">-</h4>
                                            <small class="text-success" id="individualViewsChange">
                                                <i class="bi bi-arrow-up"></i> +0%
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card border-info">
                                        <div class="card-body text-center">
                                            <i class="bi bi-cursor-fill text-info fs-3 mb-2"></i>
                                            <p class="text-muted mb-1 small">Cliques</p>
                                            <h4 class="mb-0" id="individualClicks">-</h4>
                                            <small class="text-success" id="individualClicksChange">
                                                <i class="bi bi-arrow-up"></i> +0%
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card border-success">
                                        <div class="card-body text-center">
                                            <i class="bi bi-cart-check-fill text-success fs-3 mb-2"></i>
                                            <p class="text-muted mb-1 small">Conversões</p>
                                            <h4 class="mb-0" id="individualConversions">-</h4>
                                            <small class="text-success" id="individualConversionsChange">
                                                <i class="bi bi-arrow-up"></i> +0%
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card border-warning">
                                        <div class="card-body text-center">
                                            <i class="bi bi-hash text-warning fs-3 mb-2"></i>
                                            <p class="text-muted mb-1 small">Posição Estimada</p>
                                            <h4 class="mb-0" id="individualPosition">-</h4>
                                            <small class="text-success" id="individualPositionChange">
                                                <i class="bi bi-arrow-down"></i> -0 posições
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Timeline of Optimizations -->
                            <div class="row">
                                <div class="col-12">
                                    <h5 class="mb-3">Timeline de Otimizações</h5>
                                    <div id="optimizationTimeline" class="position-relative">
                                        <!-- Timeline will be rendered here -->
                                    </div>
                                </div>
                            </div>

                            <!-- Re-optimize Button -->
                            <div class="row mt-4">
                                <div class="col-12 text-center">
                                    <button type="button" class="btn btn-primary btn-lg" id="reOptimizeBtn">
                                        <i class="bi bi-arrow-repeat me-2"></i>
                                        Re-otimizar Este Produto
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Empty State -->
                        <div id="individualAnalysisEmpty" class="text-center py-5">
                            <i class="bi bi-graph-up text-muted" style="font-size: 4rem;"></i>
                            <p class="text-muted mt-3">Selecione um produto para ver análise detalhada</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 4: Histórico do AutoPilot -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-0">
                                    <i class="bi bi-robot text-primary me-2"></i>
                                    Histórico do AutoPilot
                                </h4>
                                <p class="text-muted mb-0 small">Execuções automáticas anteriores</p>
                            </div>
                            <div>
                                <select class="form-select form-select-sm" id="historyFilter">
                                    <option value="7">Últimos 7 dias</option>
                                    <option value="30" selected>Últimos 30 dias</option>
                                    <option value="90">Últimos 90 dias</option>
                                    <option value="all">Todos</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="autopilotHistoryTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Data/Hora</th>
                                        <th style="width: 120px;">Itens Processados</th>
                                        <th style="width: 150px;">Otimizações</th>
                                        <th style="width: 120px;">Score Médio</th>
                                        <th style="width: 100px;">Duração</th>
                                        <th style="width: 100px;">Status</th>
                                        <th style="width: 100px;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="autopilotHistoryBody">
                                    <!-- Loading state -->
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Carregando...</span>
                                            </div>
                                            <p class="text-muted mt-2 mb-0">Carregando histórico...</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- AutoPilot Run Details Modal -->
<div class="modal fade" id="autopilotRunDetailsModal" tabindex="-1" aria-labelledby="autopilotRunDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="autopilotRunDetailsModalLabel">
                    <i class="bi bi-robot me-2"></i>
                    Detalhes da Execução AutoPilot
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="autopilotRunDetailsBody">
                <!-- Content will be dynamically loaded -->
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Performance Tracker JavaScript -->
<script nonce="<?= CSP_NONCE ?>">
    function normalizeExternalUrl(url) {
        if (!url || typeof url !== 'string') return '';
        const trimmed = url.trim();
        if (!trimmed) return '';
        if (trimmed.startsWith('data:') || trimmed.startsWith('blob:') || trimmed.startsWith('#')) return trimmed;
        if (trimmed.startsWith('//')) return `${window.location.protocol}${trimmed}`;
        if (trimmed.startsWith('http://')) return `https://${trimmed.slice('http://'.length)}`;
        return trimmed;
    }

    async function requestBlob(url, options = {}) {
        if (window.ApiClient && typeof window.ApiClient.fetch === 'function') {
            return window.ApiClient.fetch(url, options);
        }

        return fetch(url, options);
    }

    // Local: ApiClient.request devolve body cru; este tab espera {response, data}
    async function requestJson(url, options = {}) {
        if (window.ApiClient && typeof window.ApiClient.json === 'function') {
            return window.ApiClient.json(url, options);
        }
        const response = await fetch(url, {
            credentials: 'include',
            ...options
        });
        const data = await response.json().catch(() => null);
        return {
            response,
            data
        };
    }

    const PerformanceTracker = {
        charts: {
            evolution: null,
            comparison: null
        },

        currentProduct: null,

        init() {
            console.log('Initializing Performance Tracker...');
            this.loadDashboardData();
            this.loadTopPerformers();
            this.loadProductsList();
            this.loadAutopilotHistory();
            this.setupEventListeners();
        },

        setupEventListeners() {
            // Refresh button
            document.getElementById('refreshPerformanceBtn')?.addEventListener('click', () => {
                this.loadDashboardData();
                this.loadTopPerformers();
                this.loadAutopilotHistory();
            });

            // Export button
            document.getElementById('exportPerformanceBtn')?.addEventListener('click', () => {
                this.exportReport();
            });

            // Individual analysis
            document.getElementById('individualProductSelect')?.addEventListener('change', (e) => {
                const analyzeBtn = document.getElementById('analyzeProductBtn');
                if (analyzeBtn) {
                    analyzeBtn.disabled = !e.target.value;
                }
            });

            document.getElementById('analyzeProductBtn')?.addEventListener('click', () => {
                this.analyzeProduct();
            });

            // Re-optimize button
            document.getElementById('reOptimizeBtn')?.addEventListener('click', () => {
                this.reOptimizeProduct();
            });

            // History filter
            document.getElementById('historyFilter')?.addEventListener('change', (e) => {
                this.loadAutopilotHistory(e.target.value);
            });
        },

        async loadDashboardData() {
            try {
                const {
                    response,
                    data
                } = await requestJson('/api/seo-killer/performance/dashboard');
                if (!response.ok) throw new Error('Failed to load dashboard data');

                // Update metric cards
                this.updateMetricCard('totalOptimizations', data.total_optimizations, data.optimizations_change);
                this.updateScoreCard(data.average_score_before, data.average_score_after, data.score_improvement);
                this.updateMetricCard('estimatedROI', `R$ ${this.formatNumber(data.estimated_roi)}`, data.roi_change);
                this.updateConversionsCard(data.conversions_increase, data.views_increase);

                // Render evolution chart
                this.renderEvolutionChart(data.score_evolution);

            } catch (error) {
                console.error('Error loading dashboard data:', error);
                this.showError('Erro ao carregar dados do dashboard');
            }
        },

        updateMetricCard(id, value, change) {
            const element = document.getElementById(id);
            if (element) element.textContent = value;

            const changeElement = document.getElementById(id + 'Change');
            if (changeElement && change !== undefined) {
                const isPositive = change >= 0;
                changeElement.innerHTML = `
                <i class="bi bi-arrow-${isPositive ? 'up' : 'down'}"></i>
                ${isPositive ? '+' : ''}${change}%
            `;
                changeElement.className = isPositive ? 'text-success me-1' : 'text-danger me-1';
            }
        },

        updateScoreCard(before, after, improvement) {
            const beforeEl = document.getElementById('averageScoreBefore');
            const afterEl = document.getElementById('averageScoreAfter');
            const improvementEl = document.getElementById('scoreImprovement');

            if (beforeEl) beforeEl.textContent = before;
            if (afterEl) afterEl.textContent = after;
            if (improvementEl) {
                improvementEl.innerHTML = `
                <i class="bi bi-arrow-up"></i> +${improvement}pts
            `;
            }
        },

        updateConversionsCard(conversions, views) {
            const conversionsEl = document.getElementById('conversionsIncrease');
            const viewsEl = document.getElementById('viewsIncrease');

            if (conversionsEl) conversionsEl.textContent = `+${conversions}%`;
            if (viewsEl) {
                viewsEl.innerHTML = `<i class="bi bi-eye"></i> +${views}% views`;
            }
        },

        renderEvolutionChart(data) {
            const container = document.getElementById('scoreEvolutionChart');
            if (!container) return;

            const points = Array.isArray(data) ? data : [];
            if (points.length === 0) {
                container.innerHTML = '<div class="text-muted text-center py-5">Sem histórico de score nos últimos 30 dias</div>';
                return;
            }

            // Clear loading state
            container.innerHTML = '<canvas id="evolutionCanvas"></canvas>';

            const ctx = document.getElementById('evolutionCanvas').getContext('2d');

            if (this.charts.evolution) {
                this.charts.evolution.destroy();
            }

            const labels = points.map(d => d.date || d.score_date || '');
            const scores = points.map(d => Number(d.score ?? d.avg_score ?? 0));

            this.charts.evolution = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Score Médio',
                        data: scores,
                        borderColor: '#0066FF',
                        backgroundColor: 'rgba(0, 102, 255, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            min: 0,
                            max: 100
                        }
                    }
                }
            });
        },

        async loadTopPerformers() {
            try {
                const {
                    response,
                    data
                } = await requestJson('/api/seo-killer/performance/top');
                if (!response.ok) throw new Error('Failed to load top performers');
                const tbody = document.getElementById('topPerformersBody');

                if (!tbody) return;

                const items = Array.isArray(data) ? data : (data?.items || []);

                if (items.length === 0) {
                    tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            Nenhum produto com métricas ainda
                        </td>
                    </tr>
                `;
                    return;
                }

                tbody.innerHTML = items.map((item, index) => `
                <tr>
                    <td>
                        ${index === 0 ? '<span class="badge bg-warning">🏆 1º</span>' :
                          index === 1 ? '<span class="badge bg-light text-dark">🥈 2º</span>' :
                          index === 2 ? '<span class="badge bg-light text-dark">🥉 3º</span>' :
                          `<span class="text-muted">${index + 1}º</span>`}
                    </td>
                    <td>
                            <img src="${normalizeExternalUrl(item.thumbnail) || '/assets/placeholder.png'}"
                             alt="${this.escapeHtml(item.title)}"
                             class="img-thumbnail"
                             style="width: 60px; height: 60px; object-fit: cover;">
                    </td>
                    <td>
                        <div class="text-truncate" style="max-width: 300px;" title="${this.escapeHtml(item.title)}">
                            ${this.escapeHtml(item.title)}
                        </div>
                        <small class="text-muted">ID: ${item.item_id}</small>
                    </td>
                    <td>
                        <div class="d-flex align-items-center">
                            <span class="badge bg-danger me-2">${item.score_before}</span>
                            <i class="bi bi-arrow-right text-muted me-2"></i>
                            <span class="badge bg-success">${item.score_after}</span>
                        </div>
                        <small class="text-success">
                            <i class="bi bi-arrow-up"></i> +${item.score_improvement} pts
                        </small>
                    </td>
                    <td>
                        <strong>${item.sales_increase > 0 ? '+' : ''}${item.sales_increase}%</strong>
                        <br>
                        <small class="text-muted">${item.total_sales} vendas</small>
                    </td>
                    <td>
                        <small>${item.optimized_at ? this.formatDate(item.optimized_at) : '—'}</small>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary"
                                onclick="PerformanceTracker.viewProductDetails('${item.item_id}')">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>
            `).join('');

            } catch (error) {
                console.error('Error loading top performers:', error);
                this.showError('Erro ao carregar top performers');
            }
        },

        async loadProductsList() {
            try {
                const {
                    response,
                    data
                } = await requestJson('/api/items?optimized=true&limit=100');
                if (!response.ok) throw new Error('Failed to load products');
                const select = document.getElementById('individualProductSelect');

                if (!select) return;

                select.innerHTML = '<option value="">Selecione um produto...</option>' +
                    data.items.map(item => `
                    <option value="${item.id}">${this.escapeHtml(item.title)} (ID: ${item.ml_item_id})</option>
                `).join('');

            } catch (error) {
                console.error('Error loading products list:', error);
            }
        },

        async analyzeProduct() {
            const select = document.getElementById('individualProductSelect');
            if (!select?.value) return;

            this.currentProduct = select.value;

            const resultsDiv = document.getElementById('individualAnalysisResults');
            const emptyDiv = document.getElementById('individualAnalysisEmpty');

            if (resultsDiv) resultsDiv.style.display = 'block';
            if (emptyDiv) emptyDiv.style.display = 'none';

            try {
                const {
                    response,
                    data
                } = await requestJson(`/api/seo-killer/performance/item/${this.currentProduct}`);
                if (!response.ok) throw new Error('Failed to load product analysis');

                // Update metrics cards
                this.updateIndividualMetric('Views', data.views_before, data.views_after);
                this.updateIndividualMetric('Clicks', data.clicks_before, data.clicks_after);
                this.updateIndividualMetric('Conversions', data.conversions_before, data.conversions_after);
                this.updateIndividualMetric('Position', data.position_before, data.position_after);

                // Render comparison chart
                this.renderComparisonChart(data);

                // Render timeline
                this.renderTimeline(data.optimizations);

            } catch (error) {
                console.error('Error analyzing product:', error);
                this.showError('Erro ao analisar produto');
            }
        },

        updateIndividualMetric(metric, before, after) {
            const valueEl = document.getElementById(`individual${metric}`);
            const changeEl = document.getElementById(`individual${metric}Change`);

            if (!valueEl || !changeEl) return;

            const change = ((after - before) / before * 100).toFixed(1);
            const isPositive = metric === 'Position' ? change < 0 : change > 0;

            valueEl.textContent = after;
            changeEl.innerHTML = `
            <i class="bi bi-arrow-${isPositive ? (metric === 'Position' ? 'down' : 'up') : 'down'}"></i>
            ${metric === 'Position' ? Math.abs(change) : (change > 0 ? '+' : '')}${change}${metric === 'Position' ? ' posições' : '%'}
        `;
            changeEl.className = isPositive ? 'text-success' : 'text-danger';
        },

        renderComparisonChart(data) {
            const container = document.getElementById('comparisonChart');
            if (!container) return;

            container.innerHTML = '<canvas id="comparisonCanvas"></canvas>';

            const ctx = document.getElementById('comparisonCanvas').getContext('2d');

            if (this.charts.comparison) {
                this.charts.comparison.destroy();
            }

            this.charts.comparison = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Visualizações', 'Cliques', 'Conversões', 'Score SEO'],
                    datasets: [{
                            label: 'Antes',
                            data: [data.views_before, data.clicks_before, data.conversions_before, data.score_before],
                            backgroundColor: 'rgba(220, 53, 69, 0.7)',
                            borderColor: 'rgba(220, 53, 69, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Depois',
                            data: [data.views_after, data.clicks_after, data.conversions_after, data.score_after],
                            backgroundColor: 'rgba(25, 135, 84, 0.7)',
                            borderColor: 'rgba(25, 135, 84, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        },

        renderTimeline(optimizations) {
            const container = document.getElementById('optimizationTimeline');
            if (!container || !optimizations?.length) return;

            container.innerHTML = `
            <div class="timeline">
                ${optimizations.map(opt => `
                    <div class="timeline-item mb-4">
                        <div class="timeline-marker bg-primary"></div>
                        <div class="timeline-content">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1">${this.escapeHtml(opt.type)}</h6>
                                    <p class="text-muted small mb-2">${this.escapeHtml(opt.description)}</p>
                                    <small class="text-muted">
                                        <i class="bi bi-clock me-1"></i>${this.formatDate(opt.date)}
                                    </small>
                                </div>
                                <span class="badge bg-success">+${opt.score_impact} pts</span>
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
        },

        async reOptimizeProduct() {
            if (!this.currentProduct) return;

            if (!confirm('Deseja re-otimizar este produto? Isso pode sobrescrever otimizações manuais.')) {
                return;
            }

            const btn = document.getElementById('reOptimizeBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Otimizando...';
            }

            try {
                const {
                    response
                } = await requestJson(`/api/seo-killer/optimize/${this.currentProduct}`, {
                    method: 'POST'
                });

                if (!response.ok) throw new Error('Failed to re-optimize product');

                this.showSuccess('Produto re-otimizado com sucesso!');

                // Reload analysis
                setTimeout(() => this.analyzeProduct(), 2000);

            } catch (error) {
                console.error('Error re-optimizing product:', error);
                this.showError('Erro ao re-otimizar produto');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-repeat me-2"></i>Re-otimizar Este Produto';
                }
            }
        },

        async loadAutopilotHistory(days = '30') {
            try {
                const {
                    response,
                    data
                } = await requestJson(`/api/seo-killer/autopilot/history?days=${days}`);
                if (!response.ok) throw new Error('Failed to load autopilot history');
                const tbody = document.getElementById('autopilotHistoryBody');

                if (!tbody) return;

                if (!data.runs || data.runs.length === 0) {
                    tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-robot fs-1 d-block mb-2"></i>
                            Nenhuma execução do AutoPilot encontrada
                        </td>
                    </tr>
                `;
                    return;
                }

                tbody.innerHTML = data.runs.map(run => `
                <tr>
                    <td>
                        <div>${this.formatDate(run.started_at)}</div>
                        <small class="text-muted">${this.formatTime(run.started_at)}</small>
                    </td>
                    <td class="text-center">
                        <strong>${run.items_processed}</strong>
                    </td>
                    <td>
                        <div class="small">
                            ${run.optimizations.title || 0} títulos<br>
                            ${run.optimizations.description || 0} descrições<br>
                            ${run.optimizations.attributes || 0} atributos
                        </div>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-success">${run.average_score}</span>
                    </td>
                    <td class="text-center">
                        <small>${run.duration}</small>
                    </td>
                    <td>
                        ${this.getStatusBadge(run.status)}
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary"
                                onclick="PerformanceTracker.viewRunDetails('${run.id}')">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>
            `).join('');

            } catch (error) {
                console.error('Error loading autopilot history:', error);
                this.showError('Erro ao carregar histórico do AutoPilot');
            }
        },

        async viewProductDetails(itemId) {
            // Populate select and trigger analysis
            const select = document.getElementById('individualProductSelect');
            if (select) {
                // Find option with this item_id
                for (let option of select.options) {
                    if (option.text.includes(itemId)) {
                        select.value = option.value;
                        break;
                    }
                }
                this.analyzeProduct();
            }
        },

        async viewRunDetails(runId) {
            const modal = new bootstrap.Modal(document.getElementById('autopilotRunDetailsModal'));
            const body = document.getElementById('autopilotRunDetailsBody');

            modal.show();

            if (!body) return;

            try {
                const {
                    response,
                    data
                } = await requestJson(`/api/seo-killer/autopilot/history/${runId}`);
                if (!response.ok) throw new Error('Failed to load run details');

                body.innerHTML = `
                <div class="mb-3">
                    <h6>Informações Gerais</h6>
                    <table class="table table-sm">
                        <tr>
                            <td class="fw-bold">Data/Hora Início:</td>
                            <td>${this.formatDate(data.started_at)} ${this.formatTime(data.started_at)}</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Duração:</td>
                            <td>${data.duration}</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Itens Processados:</td>
                            <td>${data.items_processed}</td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Score Médio Resultante:</td>
                            <td><span class="badge bg-success">${data.average_score}</span></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Status:</td>
                            <td>${this.getStatusBadge(data.status)}</td>
                        </tr>
                    </table>
                </div>

                <div class="mb-3">
                    <h6>Otimizações Realizadas</h6>
                    <ul class="list-group">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Títulos Otimizados
                            <span class="badge bg-primary rounded-pill">${data.optimizations.title || 0}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Descrições Otimizadas
                            <span class="badge bg-primary rounded-pill">${data.optimizations.description || 0}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Atributos Preenchidos
                            <span class="badge bg-primary rounded-pill">${data.optimizations.attributes || 0}</span>
                        </li>
                    </ul>
                </div>

                ${data.errors && data.errors.length > 0 ? `
                    <div class="mb-3">
                        <h6 class="text-danger">Erros Encontrados</h6>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                ${data.errors.map(err => `<li>${this.escapeHtml(err)}</li>`).join('')}
                            </ul>
                        </div>
                    </div>
                ` : ''}
            `;

            } catch (error) {
                console.error('Error loading run details:', error);
                body.innerHTML = `
                <div class="alert alert-danger">
                    Erro ao carregar detalhes da execução
                </div>
            `;
            }
        },

        async exportReport() {
            try {
                const response = await requestBlob('/api/seo-killer/performance/export', {
                    method: 'POST'
                });

                if (!response.ok) throw new Error('Failed to export report');

                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `performance-report-${new Date().toISOString().split('T')[0]}.pdf`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);

                this.showSuccess('Relatório exportado com sucesso!');

            } catch (error) {
                console.error('Error exporting report:', error);
                this.showError('Erro ao exportar relatório');
            }
        },

        // Utility functions
        formatNumber(num) {
            return new Intl.NumberFormat('pt-BR').format(num);
        },

        formatDate(dateString) {
            if (!dateString) return '—';
            const d = new Date(dateString);
            if (Number.isNaN(d.getTime())) return '—';
            return d.toLocaleDateString('pt-BR');
        },

        formatTime(dateString) {
            return new Date(dateString).toLocaleTimeString('pt-BR', {
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        getStatusBadge(status) {
            const badges = {
                'success': '<span class="badge bg-success">Sucesso</span>',
                'running': '<span class="badge bg-primary">Em Execução</span>',
                'error': '<span class="badge bg-danger">Erro</span>',
                'partial': '<span class="badge bg-warning">Parcial</span>'
            };
            return badges[status] || '<span class="badge bg-secondary">Desconhecido</span>';
        },

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        showSuccess(message) {
            if (window.SEOKillerUtils && window.SEOKillerUtils.Notifications) {
                window.SEOKillerUtils.Notifications.success(message);
                return;
            }
            if (window.SEOKiller && typeof window.SEOKiller.showSuccess === 'function') {
                window.SEOKiller.showSuccess(message);
                return;
            }
            alert(message);
        },

        showError(message) {
            if (window.SEOKillerUtils && window.SEOKillerUtils.Notifications) {
                window.SEOKillerUtils.Notifications.error(message);
                return;
            }
            if (window.SEOKiller && typeof window.SEOKiller.showError === 'function') {
                window.SEOKiller.showError(message);
                return;
            }
            alert(message);
        }
    };

    // Initialize when tab is shown
    document.addEventListener('shown.bs.tab', function(e) {
        if (e.target.id === 'performance-tracker-tab') {
            const run = () => PerformanceTracker.init();
            if (window.SEOKiller && typeof window.SEOKiller.ensureChartJs === 'function') {
                window.SEOKiller.ensureChartJs().then(run).catch(run);
            } else {
                run();
            }
        }
    });

    // Deep-link / reload com ?tab=performance-tracker: tab já ativa sem shown.bs.tab
    function bootPerformanceTrackerIfActive() {
        const pane = document.getElementById('performance-tracker');
        const btn = document.getElementById('performance-tracker-tab');
        const active = pane?.classList.contains('active') ||
            pane?.classList.contains('show') ||
            btn?.classList.contains('active') ||
            window.location.hash === '#performance-tracker' ||
            new URLSearchParams(window.location.search).get('tab') === 'performance-tracker';
        if (!active) return;
        const run = () => PerformanceTracker.init();
        if (window.SEOKiller && typeof window.SEOKiller.ensureChartJs === 'function') {
            window.SEOKiller.ensureChartJs().then(run).catch(run);
        } else {
            run();
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootPerformanceTrackerIfActive);
    } else {
        bootPerformanceTrackerIfActive();
    }
</script>

<!-- Additional CSS for Timeline -->
<style>
    .timeline {
        position: relative;
        padding-left: 30px;
    }

    .timeline::before {
        content: '';
        position: absolute;
        left: 8px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #dee2e6;
    }

    .timeline-item {
        position: relative;
    }

    .timeline-marker {
        position: absolute;
        left: -26px;
        top: 5px;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        border: 3px solid #fff;
        box-shadow: 0 0 0 2px #0066FF;
    }

    .timeline-content {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        border: 1px solid #dee2e6;
    }
</style>
