'use strict';

const Analytics = {
    charts: {},

    /**
     * Onda 2 / T3: gráficos como LTV, Margens por Tipo e Previsão 7d ficavam
     * silenciosamente vazios (canvas sem dados, sem nenhuma mensagem) quando
     * a API não tinha amostra suficiente. Mostra um estado explícito de
     * "sem dados" ao lado do canvas em vez de deixá-lo mudo.
     * @returns {boolean} true se há dados e o chamador deve renderizar o gráfico.
     */
    toggleNoDataState(canvas, rows, message) {
        if (!canvas) return false;

        let emptyEl = canvas.parentElement?.querySelector('.analytics-empty-state');
        if (rows && rows.length > 0) {
            canvas.style.display = '';
            if (emptyEl) emptyEl.remove();
            return true;
        }

        canvas.style.display = 'none';
        if (!emptyEl) {
            emptyEl = document.createElement('p');
            emptyEl.className = 'analytics-empty-state text-muted text-center py-4 mb-0';
            canvas.parentElement?.appendChild(emptyEl);
        }
        emptyEl.textContent = message;
        return false;
    },

    async safeLoad(label, fn) {
        try {
            await fn();
        } catch (error) {
            console.error(`[Analytics] Falha em ${label}:`, error);
        }
    },

    async init() {
        await this.safeLoad('summary', () => this.loadSummary());
        await this.safeLoad('revenue trend', () => this.loadRevenueTrend());
        await this.safeLoad('customer ltv', () => this.loadCustomerLTV());
        await this.safeLoad('profit margins', () => this.loadProfitMargins());
        await this.safeLoad('inventory turnover', () => this.loadInventoryTurnover());
        await this.safeLoad('forecast', () => this.loadForecast());

        // Auto-refresh every 60 seconds
        setInterval(() => {
            this.safeLoad('summary auto-refresh', () => this.loadSummary());
        }, 60000);
    },

    async loadSummary() {
        const json = await requestJson('/api/analytics/summary');
        const data = json.data;

        document.getElementById('revenue-today').textContent = 'R$ ' + data.revenue_today.toFixed(2);
        document.getElementById('pending-questions').textContent = data.pending_questions;
        document.getElementById('active-items').textContent = data.active_items;

        const growthEl = document.getElementById('growth-rate');
        growthEl.textContent = (data.growth_rate >= 0 ? '+' : '') + data.growth_rate + '%';
        growthEl.className = data.growth_rate >= 0 ? 'trend-up' : 'trend-down';

        // Taxa de conversão = vendas 7d / visitas 7d (Onda 2 / T3). Janela fixa
        // de 7 dias (mesma do painel Pregão "Exposição"), independente do
        // seletor de período do gráfico de receita.
        const conversionEl = document.getElementById('conversion-rate');
        if (conversionEl) {
            if (data.conversion_rate === null || data.conversion_rate === undefined) {
                conversionEl.textContent = '—';
                conversionEl.title = 'Sem dados de visitas coletados ainda';
            } else {
                conversionEl.textContent = data.conversion_rate + '%';
                conversionEl.title = `${data.sales_7d || 0} vendas / ${data.visits_7d || 0} visitas (últimos 7 dias)`;
            }
        }
    },

    async loadRevenueTrend() {
        const selector = document.getElementById('period-selector');
        const days = selector ? selector.value : '30';
        const labelMap = {
            '7': 'Últimos 7 dias',
            '30': 'Últimos 30 dias',
            '90': 'Últimos 90 dias'
        };
        const periodLabel = labelMap[String(days)] || (`Últimos ${days} dias`);
        const banner = document.getElementById('analytics-period-banner');
        const labelEl = document.getElementById('analytics-period-label');
        const hintEl = document.getElementById('analytics-period-empty-hint');
        if (banner) banner.setAttribute('data-period', String(days));
        if (labelEl) labelEl.textContent = periodLabel;

        const start = new Date();
        start.setDate(start.getDate() - Number(days));

        const json = await requestJson(`/api/analytics/revenue-trend?start=${start.toISOString().split('T')[0]}&end=${new Date().toISOString().split('T')[0]}`);
        const rows = Array.isArray(json.data) ? json.data : [];
        const periodRevenue = rows.reduce((sum, d) => sum + (parseFloat(d.revenue) || 0), 0);
        const revenueEl = document.getElementById('revenue-today');
        if (revenueEl) {
            revenueEl.textContent = 'R$ ' + periodRevenue.toFixed(2);
        }
        if (hintEl) {
            hintEl.textContent = periodRevenue > 0
                ? `Receita agregada do gráfico: R$ ${periodRevenue.toFixed(2)}.`
                : `Sem dados de receita para ${periodLabel} — valores podem permanecer zerados.`;
        }

        const ctx = document.getElementById('revenueChart').getContext('2d');

        if (this.charts.revenue) this.charts.revenue.destroy();

        this.charts.revenue = new Chart(ctx, {
            type: 'line',
            data: {
                labels: rows.length ? rows.map(d => d.period) : [periodLabel],
                datasets: [{
                    label: 'Receita (R$)',
                    data: rows.length ? rows.map(d => parseFloat(d.revenue)) : [0],
                    borderColor: '#6f42c1',
                    backgroundColor: 'rgba(111, 66, 193, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    },

    async loadCustomerLTV() {
        const json = await requestJson('/api/analytics/customer-ltv');
        const rows = Array.isArray(json.data) ? json.data : [];

        const canvas = document.getElementById('ltvChart');
        if (!this.toggleNoDataState(canvas, rows, 'Sem dados suficientes de LTV no período.')) {
            return;
        }

        const ctx = canvas.getContext('2d');

        if (this.charts.ltv) this.charts.ltv.destroy();

        this.charts.ltv = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: rows.map(d => d.segment),
                datasets: [{
                    data: rows.map(d => d.customer_count),
                    backgroundColor: ['#6f42c1', '#4CAF50', '#FFC107', '#FF5722']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    },

    async loadProfitMargins() {
        const json = await requestJson('/api/analytics/profit-margins');
        const rows = Array.isArray(json.data) ? json.data : [];

        const canvas = document.getElementById('marginChart');
        if (!this.toggleNoDataState(canvas, rows, 'Sem dados suficientes de margem por tipo de anúncio.')) {
            return;
        }

        const ctx = canvas.getContext('2d');

        if (this.charts.margin) this.charts.margin.destroy();

        this.charts.margin = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: rows.map(d => d.listing_type || 'N/A'),
                datasets: [{
                    label: 'Margem Média (%)',
                    data: rows.map(d => parseFloat(d.avg_margin)),
                    backgroundColor: '#28a745'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    },

    async loadInventoryTurnover() {
        const json = await requestJson('/api/analytics/inventory-turnover');

        const rows = Array.isArray(json.data) ? json.data : [];
        if (!rows.length) {
            document.getElementById('turnover-table').innerHTML =
                '<p class="text-muted text-center py-3 mb-0">Sem dados suficientes de giro de estoque no período.</p>';
            return;
        }

        let html = '<table class="table table-sm"><thead><tr><th>Categoria</th><th>Taxa</th></tr></thead><tbody>';
        rows.forEach(d => {
            const label = d.category_name || `ID ${d.category_id}`;
            html += `<tr><td>${label}</td><td><span class="badge bg-success">${d.turnover_rate}%</span></td></tr>`;
        });
        html += '</tbody></table>';

        document.getElementById('turnover-table').innerHTML = html;
    },

    async loadForecast() {
        const json = await requestJson('/api/analytics/forecast?days=7');
        const rows = Array.isArray(json.data) ? json.data : [];

        const canvas = document.getElementById('forecastChart');
        if (!this.toggleNoDataState(canvas, rows, 'Sem histórico suficiente para prever a receita dos próximos dias.')) {
            return;
        }

        const ctx = canvas.getContext('2d');

        if (this.charts.forecast) this.charts.forecast.destroy();

        this.charts.forecast = new Chart(ctx, {
            type: 'line',
            data: {
                labels: rows.map(d => d.date),
                datasets: [{
                    label: 'Previsão (R$)',
                    data: rows.map(d => d.predicted_revenue),
                    borderColor: '#fff',
                    backgroundColor: 'rgba(255, 255, 255, 0.2)',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#fff'
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            color: '#fff'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#fff'
                        }
                    }
                }
            }
        });
    }
};

// Period selector change
document.getElementById('period-selector').addEventListener('change', () => Analytics.loadRevenueTrend());

// Initialize on load
Analytics.init();
