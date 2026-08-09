<?php

declare(strict_types=1);

$title = 'Calculadora de Envio (Full)';
$subtitle = 'Planejamento de reposição de estoque Fulfillment';
include __DIR__ . '/../../layouts/modern/partials/page-header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                     <h5 class="mb-0 text-primary"><i class="bi bi-box-seam-fill"></i> Sugestões de Envio</h5>
                     <small class="text-muted">Baseado nas vendas dos últimos 30 dias</small>
                </div>

                <button class="btn btn-sm btn-outline-primary" onclick="fullManager.loadData()">
                    <i class="bi bi-arrow-clockwise"></i> Recalcular
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Produto</th>
                                <th class="text-center">Estoque Atual</th>
                                <th class="text-center">Vendas (30d)</th>
                                <th class="text-center">Cobertura (Dias)</th>
                                <th class="text-center bg-warning bg-opacity-10 text-dark fw-bold">Sugestão de Envio</th>
                                <th class="text-end pe-4">Ação</th>
                            </tr>
                        </thead>
                        <tbody id="restock-list">
                            <tr><td colspan="6" class="text-center py-4"><div class="spinner-border text-primary"></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

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

    const fullManager = {
        init: function() {
            this.loadData();
        },

        loadData: async function() {
            try {
                const data = await requestJson('/api/logistics/full/suggestions');

                if (data.success) {
                    this.render(data.items);
                }
            } catch (e) {
                console.error(e);
            }
        },

        render: function(items) {
            const container = document.getElementById('restock-list');
            if (items.length === 0) {
                container.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Estoque saudável. Nada para enviar agora.</td></tr>';
                return;
            }

            let html = '';
            items.forEach(item => {
                let statusClass = 'bg-success';
                let statusText = 'Saudável';

                if (item.status === 'critical') {
                    statusClass = 'bg-danger';
                } else if (item.status === 'warning') {
                    statusClass = 'bg-warning text-dark';
                }

                html += `
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center">
                                <img src="${normalizeExternalUrl(item.thumbnail)}" class="rounded me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                <div>
                                    <div class="fw-bold text-truncate" style="max-width: 300px;">${item.title}</div>
                                    <small class="text-muted">${item.id}</small>
                                </div>
                            </div>
                        </td>
                        <td class="text-center h5 mb-0">${item.current_stock}</td>
                        <td class="text-center text-muted">${item.sales_last_30d}</td>
                        <td class="text-center">
                            <span class="badge ${statusClass}">${item.days_coverage === null || item.days_coverage === undefined ? '∞' : Math.round(item.days_coverage) + ' dias'}</span>
                        </td>
                        <td class="text-center h4 text-primary fw-bold bg-warning bg-opacity-10">
                            ${item.suggested_send > 0 ? '+' + item.suggested_send : '<i class="bi bi-check-circle-fill text-success" style="font-size: 1rem;"></i>'}
                        </td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-outline-dark" ${item.suggested_send <= 0 ? 'disabled' : ''}>
                                Criar Envio
                            </button>
                        </td>
                    </tr>
                `;
            });
            container.innerHTML = html;
        }
    };

    document.addEventListener('DOMContentLoaded', () => fullManager.init());
</script>
