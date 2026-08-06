'use strict';

const Analytics = {
    charts: {},

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

        const ctx = document.getElementById('ltvChart').getContext('2d');

        if (this.charts.ltv) this.charts.ltv.destroy();

        this.charts.ltv = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: json.data.map(d => d.segment),
                datasets: [{
                    data: json.data.map(d => d.customer_count),
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

        const ctx = document.getElementById('marginChart').getContext('2d');

        if (this.charts.margin) this.charts.margin.destroy();

        this.charts.margin = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: json.data.map(d => d.listing_type || 'N/A'),
                datasets: [{
                    label: 'Margem Média (%)',
                    data: json.data.map(d => parseFloat(d.avg_margin)),
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

        let html = '<table class="table table-sm"><thead><tr><th>Categoria</th><th>Taxa</th></tr></thead><tbody>';
        json.data.forEach(d => {
            html += `<tr><td>ID ${d.category_id}</td><td><span class="badge bg-success">${d.turnover_rate}%</span></td></tr>`;
        });
        html += '</tbody></table>';

        document.getElementById('turnover-table').innerHTML = html;
    },

    async loadForecast() {
        const json = await requestJson('/api/analytics/forecast?days=7');

        const ctx = document.getElementById('forecastChart').getContext('2d');

        if (this.charts.forecast) this.charts.forecast.destroy();

        this.charts.forecast = new Chart(ctx, {
            type: 'line',
            data: {
                labels: json.data.map(d => d.date),
                datasets: [{
                    label: 'Previsão (R$)',
                    data: json.data.map(d => d.predicted_revenue),
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
