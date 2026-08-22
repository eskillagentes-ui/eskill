<?php

declare(strict_types=1);

$title = 'Métricas Detalhadas';
include __DIR__ . '/../layouts/modern/partials/page-header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="card-title">Visão Geral de Desempenho</h5>
                <hr>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Esta página exibe métricas detalhadas de suas contas.
                </div>
                
                <!-- Metrics Grid -->
                <div class="row text-center">
                     <div class="col-md-3 mb-3">
                        <div class="p-3 bg-light rounded">
                            <h6>Vendas Hoje</h6>
                            <h3 id="m-sales-today">--</h3>
                        </div>
                     </div>
                     <div class="col-md-3 mb-3">
                        <div class="p-3 bg-light rounded">
                            <h6>Receita Hoje</h6>
                            <h3 id="m-revenue-today">--</h3>
                        </div>
                     </div>
                     <div class="col-md-3 mb-3">
                        <div class="p-3 bg-light rounded">
                            <h6>Ticket Médio</h6>
                            <h3 id="m-avg-ticket">--</h3>
                        </div>
                     </div>
                     <div class="col-md-3 mb-3">
                        <div class="p-3 bg-light rounded">
                            <h6>Anúncios Ativos</h6>
                            <h3 id="m-active-ads">--</h3>
                        </div>
                     </div>
                </div>

                <!-- Detailed Charts Placeholder -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <canvas id="sales-chart" height="300"></canvas>
                    </div>
                     <div class="col-md-6">
                        <canvas id="visits-chart" height="300"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script nonce="<?= CSP_NONCE ?>">
    document.addEventListener('DOMContentLoaded', function() {
        fetchMetrics();
    });

    function fetchMetrics() {
        const headers = new Headers();
        headers.append('Accept', 'application/json');
        headers.append('X-Requested-With', 'XMLHttpRequest');

        fetch('/dashboard/metrics', { headers: headers })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                updateCards(data);
                renderCharts(data);
            })
            .catch(error => {
                console.error('Error fetching metrics:', error);
                Toast.notify('Erro ao carregar métricas', 'error');
            });
    }

    function updateCards(data) {
        document.getElementById('m-sales-today').textContent = data.recent_orders_count || '0';
        
        const revenue = parseFloat(data.total_revenue || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        document.getElementById('m-revenue-today').textContent = revenue;
        
        const count = parseInt(data.recent_orders_count || 0);
        const total = parseFloat(data.total_revenue || 0);
        const avg = count > 0 ? (total / count) : 0;
        
        document.getElementById('m-avg-ticket').textContent = avg.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        document.getElementById('m-active-ads').textContent = data.active_items || '0';
    }

    function renderCharts(data) {
        // Prepare Data for Sales Chart (Revenue vs Profit)
        const labels = data.sales_over_time.map(item => {
            const date = new Date(item.date);
            return date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
        });
        const revenueData = data.sales_over_time.map(item => item.total);
        const profitData = data.sales_over_time.map(item => (
            item.profit === null || item.profit === undefined || item.cogs_status === 'sem_cmv'
                ? null
                : item.profit
        ));
        const profitNd = profitData.every((v) => v === null);

        const ctxSales = document.getElementById('sales-chart').getContext('2d');
        new Chart(ctxSales, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Receita (R$)',
                        data: revenueData,
                        borderColor: '#0d6efd',
                        backgroundColor: 'rgba(13, 110, 253, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: profitNd ? 'Lucro n/d (sem CMV)' : 'Lucro (R$)',
                        data: profitData,
                        borderColor: '#198754',
                        backgroundColor: 'rgba(25, 135, 84, 0.1)',
                        fill: true,
                        tension: 0.4,
                        spanGaps: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: { display: true, text: 'Evolução de Vendas (30 Dias)' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                if (context.parsed.y === null || context.parsed.y === undefined) {
                                    return context.dataset.label + ': n/d';
                                }
                                return context.dataset.label + ': ' + context.parsed.y.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                            }
                        }
                    }
                }
            }
        });

        // Prepare Data for Orders by Status
        const statusLabels = data.orders_by_status.map(item => item.status);
        const statusData = data.orders_by_status.map(item => item.count);
        const colors = [
            '#ffc107', // pending
            '#0dcaf0', // paid
            '#198754', // delivered
            '#dc3545', // cancelled
            '#6c757d'  // other
        ];

        const ctxVisits = document.getElementById('visits-chart').getContext('2d');
        new Chart(ctxVisits, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusData,
                    backgroundColor: colors,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: { display: true, text: 'Pedidos por Status' }
                }
            }
        });
    }
</script>
