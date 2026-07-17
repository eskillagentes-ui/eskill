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
                        <input type="date" class="form-control" name="end" id="date-end" value="<?= date('Y-m-t') ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-primary w-100" onclick="financialManager.loadData()">
                            <i class="bi bi-funnel"></i> Filtrar
                        </button>
                    </div>
                     <div class="col-md-3">
                        <button type="button" class="btn btn-outline-dark w-100" onclick="financialManager.exportPdf()">
                            <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

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
                <h5 class="mb-0">Evolução do Resultado</h5>
            </div>
            <div class="card-body">
                <canvas id="revenueChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Previsão financeira (REPORT-008) -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h5 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Previsão de Receita</h5>
                <div class="d-flex align-items-center gap-2">
                    <select class="form-select form-select-sm" id="forecast-months" style="width: auto;">
                        <option value="1">1 mês</option>
                        <option value="3" selected>3 meses</option>
                        <option value="6">6 meses</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="financialManager.loadForecast()">
                        <i class="bi bi-arrow-clockwise"></i> Atualizar
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div id="forecast-loading" class="text-center py-4 d-none">
                    <div class="spinner-border text-primary"></div>
                </div>
                <div id="forecast-error" class="alert alert-warning d-none" role="alert"></div>
                <div id="forecast-content" class="d-none">
                    <div class="row g-3 mb-3" id="forecast-summary"></div>
                    <canvas id="forecastChart" height="120"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= defined('CSP_NONCE') ? CSP_NONCE : '' ?>">

    const financialManager = {
        chart: null,
        forecastChart: null,

        agentLog: function(hypothesisId, message, data) {
            fetch('/api/debug/agent-log', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ hypothesisId, location: 'financials.php', message, data: data || {} }),
            }).catch(() => {});
        },

        ensureChartReady: async function(maxWaitMs = 5000) {
            if (typeof Chart !== 'undefined') {
                return;
            }
            const started = Date.now();
            while (typeof Chart === 'undefined' && Date.now() - started < maxWaitMs) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            if (typeof Chart === 'undefined') {
                throw new Error('Chart.js não carregado');
            }
        },

        handleAuthError: function(e) {
            if (e?.code === 'AUTH_EXPIRED' || String(e?.message || '').includes('HTTP 401')) {
                window.location.href = '/login';
                return true;
            }
            return false;
        },

        init: function() {
            // #region agent log
            this.agentLog('I', 'financials_init', { chartLoaded: typeof Chart !== 'undefined' });
            // #endregion
            this.loadData();
            this.loadForecast();
        },

        loadData: async function() {
            const startEl = document.getElementById('date-start');
            const endEl = document.getElementById('date-end');
            if (!startEl || !endEl) {
                return;
            }

            try {
                const data = await requestJson(`/api/financials/pnl?start=${startEl.value}&end=${endEl.value}`);

                if (data.success) {
                    this.renderPnL(data.pnl);
                    this.renderChart(data.chart);
                } else {
                    alert('Erro ao carregar dados: ' + data.error);
                }
            } catch (e) {
                if (this.handleAuthError(e)) {
                    return;
                }
                console.error(e);
            }
        },

        renderPnL: function(pnl) {
            const formatMoney = (val) => {
                return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val);
            };

            const row = (label, value, cssClass = '', isHeader = false) => {
                const fw = isHeader ? 'fw-bold' : '';
                return `<tr>
                    <td class="${fw}">${label}</td>
                    <td class="text-end ${fw} ${cssClass}">${formatMoney(value)}</td>
                </tr>`;
            };

            // Re-calc logic visually if needed, but using backend data directly
            let html = '';
            html += row('Receita Bruta', pnl.gross_revenue, '', true);
            html += row('(-) Impostos', pnl.taxes, 'text-danger');
            html += row('Receita Líquida', pnl.net_revenue, '', true);
            html += '<tr><td colspan="2"><hr class="my-1"></td></tr>';
            html += row('(-) Custo Produtos (CMV)', pnl.cogs, 'text-secondary');
            html += row('(-) Comissões ML', pnl.commissions, 'text-secondary');
            html += row('(-) Taxas Pagamento', pnl.payment_fees, 'text-secondary');
            html += row('(-) Fretes', pnl.shipping_cost, 'text-secondary');
            html += row('(-) Taxas Fixas', pnl.fixed_fees, 'text-secondary');
            html += row('(-) Descontos', pnl.discounts, 'text-secondary');
            html += '<tr><td colspan="2"><hr class="my-1"></td></tr>';

            const profitClass = pnl.net_profit >= 0 ? 'text-success' : 'text-danger';
            html += row('Resultado Operacional', pnl.net_profit, profitClass, true);

            const margin = Number(pnl.avg_margin ?? 0);
            html += `<tr><td colspan="2"><small class="text-muted d-block text-end mt-2">Margem Líquida: ${margin.toFixed(1)}%</small></td></tr>`;

            document.querySelector('#pnl-table tbody').innerHTML = html;
        },

        renderChart: function(dailyData) {
            const ctx = document.getElementById('revenueChart').getContext('2d');

            if (this.chart) this.chart.destroy();

            const labels = dailyData.map(d => new Date(d.date).toLocaleDateString('pt-BR'));
            const revenue = dailyData.map(d => d.revenue);
            const profit = dailyData.map(d => d.profit);

            this.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Receita',
                            data: revenue,
                            borderColor: '#0d6efd',
                            tension: 0.3,
                            fill: false
                        },
                        {
                            label: 'Lucro',
                            data: profit,
                            borderColor: '#198754',
                            tension: 0.3,
                            fill: true,
                            backgroundColor: 'rgba(25, 135, 84, 0.1)'
                        }
                    ]
                },
                options: {
                    responsive: true,
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

        loadForecast: async function() {
            const monthsEl = document.getElementById('forecast-months');
            const loading = document.getElementById('forecast-loading');
            const errorEl = document.getElementById('forecast-error');
            const content = document.getElementById('forecast-content');

            if (!monthsEl || !loading || !errorEl || !content) {
                // #region agent log
                this.agentLog('K', 'forecast_dom_missing', {
                    months: !!monthsEl,
                    loading: !!loading,
                    error: !!errorEl,
                    content: !!content,
                });
                // #endregion
                return;
            }

            const months = monthsEl.value;

            loading.classList.remove('d-none');
            errorEl.classList.add('d-none');
            content.classList.add('d-none');

            try {
                const res = await requestJson(`/api/financials/forecast?months_ahead=${months}`);
                loading.classList.add('d-none');

                // #region agent log
                this.agentLog('E', 'forecast_api_response', {
                    hasRes: !!res,
                    success: !!res?.success,
                    hasError: !!res?.data?.error,
                    proj: res?.data?.projections?.length ?? 0,
                });
                // #endregion

                if (!res || !res.success || res.data?.error) {
                    errorEl.textContent = res?.data?.error || res?.error || 'Não foi possível gerar a previsão.';
                    errorEl.classList.remove('d-none');
                    return;
                }

                content.classList.remove('d-none');
                await this.ensureChartReady();
                await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                try {
                    this.renderForecast(res.data);
                } catch (chartError) {
                    content.classList.add('d-none');
                    throw chartError;
                }
            } catch (e) {
                // #region agent log
                this.agentLog('H', 'forecast_client_error', {
                    name: e?.name,
                    code: e?.code,
                    msg: String(e?.message || e),
                });
                // #endregion
                loading.classList.add('d-none');
                if (this.handleAuthError(e)) {
                    return;
                }
                errorEl.textContent = 'Erro ao carregar previsão financeira: ' + (e?.message || e);
                errorEl.classList.remove('d-none');
                console.error(e);
            }
        },

        renderForecast: function(data) {
            if (typeof Chart === 'undefined') {
                throw new Error('Chart.js não carregado');
            }
            const canvas = document.getElementById('forecastChart');
            // #region agent log
            this.agentLog('F', 'forecast_canvas_before_chart', {
                clientWidth: canvas?.clientWidth ?? 0,
                offsetWidth: canvas?.offsetWidth ?? 0,
                parentHidden: document.getElementById('forecast-content')?.classList.contains('d-none') ?? true,
            });
            // #endregion
            const fmt = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);
            const trendLabel = { growing: 'Em alta', declining: 'Em queda', stable: 'Estável' };
            const summary = data.summary || {};
            const trends = data.trends || {};
            const growthPct = Number(summary.expected_monthly_growth_rate ?? 0);

            document.getElementById('forecast-summary').innerHTML = `
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded text-center">
                        <div class="small text-muted">Receita projetada (${data.projections?.length || 0} meses)</div>
                        <div class="h5 mb-0">${fmt(summary.projected_revenue_total || 0)}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded text-center">
                        <div class="small text-muted">Média histórica/mês</div>
                        <div class="h5 mb-0">${fmt(summary.avg_historical_revenue || 0)}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded text-center">
                        <div class="small text-muted">Crescimento esperado</div>
                        <div class="h5 mb-0">${growthPct.toFixed(1)}%</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded text-center">
                        <div class="small text-muted">Tendência</div>
                        <div class="h5 mb-0">${trendLabel[trends.revenue_trend] || trends.revenue_trend || '—'}</div>
                    </div>
                </div>
            `;

            const hist = (data.historical_data || []).map(h => ({
                label: h.month,
                revenue: parseFloat(h.revenue) || 0,
                type: 'histórico'
            }));
            const proj = (data.projections || []).map(p => ({
                label: p.month,
                revenue: parseFloat(p.projected_revenue) || 0,
                type: 'projeção'
            }));
            const points = [...hist, ...proj];

            const ctx = canvas.getContext('2d');
            if (this.forecastChart) this.forecastChart.destroy();

            this.forecastChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: points.map(p => p.label),
                    datasets: [{
                        label: 'Receita (R$)',
                        data: points.map(p => p.revenue),
                        backgroundColor: points.map(p => p.type === 'histórico' ? 'rgba(13, 110, 253, 0.7)' : 'rgba(25, 135, 84, 0.7)')
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            ticks: {
                                callback: (v) => fmt(v)
                            }
                        }
                    }
                }
            });

            this.forecastChart.resize();
            // #region agent log
            this.agentLog('F', 'forecast_canvas_after_chart', {
                chartWidth: this.forecastChart?.width ?? 0,
                clientWidth: canvas?.clientWidth ?? 0,
            });
            // #endregion
        }
    };

    document.addEventListener('DOMContentLoaded', () => financialManager.init());
</script>
