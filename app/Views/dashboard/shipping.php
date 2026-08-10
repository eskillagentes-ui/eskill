<?php

declare(strict_types=1);

$title = 'Expedição & Logística';
$subtitle = 'Gerencie separação e envio de pedidos';
include __DIR__ . '/../layouts/modern/partials/page-header.php';
?>

<!-- Picking List Generator Widget -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 text-primary">
                    <i class="bi bi-box-seam"></i> Gerador de Picking List
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i> Selecione os pedidos "Prontos para Envio" abaixo para gerar a lista de separação.
                </div>

                <!-- Filter Controls -->
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="shipping-filter-status">
                            <option value="ready_to_ship" selected>Pronto para Envio</option>
                            <option value="paid">Pago (Aguardando NFe)</option>
                            <option value="shipped">Enviado</option>
                        </select>
                    </div>
                    <div class="col-md-8 d-flex align-items-end">
                        <button class="btn btn-primary" onclick="shippingManager.loadOrders()">
                            <i class="bi bi-filter"></i> Filtrar
                        </button>
                    </div>
                </div>

                <!-- Action Bar -->
                <div id="shipping-actions" class="d-none bg-light p-3 rounded mb-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><span id="selected-count">0</span> pedidos selecionados</span>
                    <div>
                        <button class="btn btn-dark me-2" onclick="shippingManager.generatePickingList()">
                            <i class="bi bi-file-earmark-pdf"></i> Gerar Picking List
                        </button>
                        <button class="btn btn-outline-secondary" disabled title="Em breve">
                            <i class="bi bi-printer"></i> Imprimir Etiquetas
                        </button>
                    </div>
                </div>

                <!-- Orders Table -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th width="40"><input type="checkbox" class="form-check-input" id="select-all"></th>
                                <th>Pedido</th>
                                <th>Itens</th>
                                <th>Data</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="shipping-orders-list">
                            <!-- Populated via JS -->
                            <tr>
                                <td colspan="5" class="text-center py-4">Carregando...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
    const shippingRequestJson = async (url, options = {}) => {
        if (typeof window.requestJson === 'function') {
            return window.requestJson(url, options);
        }

        if (window.ApiClient && typeof window.ApiClient.request === 'function') {
            return window.ApiClient.request(url, options);
        }

        const response = await fetch(url, {
            credentials: 'include',
            ...options,
        });

        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        return response.json();
    };

    const shippingManager = {
        orders: [],
        // Status de logística (shipping_status) vs. status de pagamento (status) —
        // o backend já filtra corretamente na origem (Onda 3.1 / F3); este filtro
        // client-side é apenas defensivo, comparando o campo certo por tipo.
        SHIPPING_LEVEL_STATUSES: ['pending', 'handling', 'ready_to_ship', 'shipped', 'delivered', 'not_delivered'],
        init: function() {
            this.loadOrders();
            this.setupListeners();
        },

        setupListeners: function() {
            document.getElementById('select-all').addEventListener('change', (e) => {
                const checkboxes = document.querySelectorAll('.order-checkbox');
                checkboxes.forEach(cb => cb.checked = e.target.checked);
                this.updateSelection();
            });
        },

        loadOrders: async function() {
            const status = document.getElementById('shipping-filter-status').value;
            const container = document.getElementById('shipping-orders-list');

            container.innerHTML = '<tr><td colspan="5" class="text-center py-4"><div class="spinner-border text-primary"></div></td></tr>';

            try {
                const data = await shippingRequestJson('/api/orders/all?status=' + encodeURIComponent(status));

                if (data && data.error) {
                    throw new Error(data.message || data.error);
                }

                this.orders = data.results || data.orders || [];

                // O backend já filtra na origem (order.status vs shipping.status do ML).
                // A API de busca do ML só devolve `shipping.id` (não o status de envio) por
                // pedido, então não há como re-filtrar por shipping_status no cliente sem uma
                // chamada extra por pedido — confiamos no filtro já aplicado no servidor.
                // Para status de PAGAMENTO ainda re-filtramos (campo `status` vem completo).
                const isShippingLevel = this.SHIPPING_LEVEL_STATUSES.includes(status);
                const filtered = isShippingLevel
                    ? this.orders
                    : this.orders.filter(o => o.status === status);

                this.renderOrders(filtered);
            } catch (error) {
                console.error(error);
                container.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Erro ao carregar pedidos: ' +
                    (error && error.message ? String(error.message) : 'falha na requisição') + '</td></tr>';
            }
        },

        renderOrders: function(orders) {
            const container = document.getElementById('shipping-orders-list');
            if (orders.length === 0) {
                container.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">Nenhum pedido encontrado com este status.</td></tr>';
                return;
            }

            let html = '';
            orders.forEach(order => {
                const parsedDate = order.date_created ? new Date(order.date_created) : null;
                const date = (parsedDate && !Number.isNaN(parsedDate.getTime()))
                    ? parsedDate.toLocaleDateString('pt-BR')
                    : '—';
                let orderItems = [];

                if (Array.isArray(order.order_items)) {
                    orderItems = order.order_items;
                } else if (Array.isArray(order.items)) {
                    orderItems = order.items;
                } else if (typeof order.items === 'string' && order.items !== '') {
                    try {
                        orderItems = JSON.parse(order.items);
                    } catch (e) {
                        orderItems = [];
                    }
                }

                const itemsCount = orderItems.length;

                html += `
                    <tr>
                        <td><input type="checkbox" class="form-check-input order-checkbox" value="${order.id}" onchange="shippingManager.updateSelection()"></td>
                        <td>
                            <strong>#${order.id}</strong><br>
                            <span class="small text-muted">${order.buyer?.nickname || 'Comprador'}</span>
                        </td>
                        <td>${itemsCount} item(s)</td>
                        <td>${date}</td>
                        <td><span class="badge bg-secondary">${order.shipping_status || order.status || '—'}</span></td>
                    </tr>
                `;
            });
            container.innerHTML = html;
            this.updateSelection(); // Reset selection count
        },

        updateSelection: function() {
            const selected = document.querySelectorAll('.order-checkbox:checked').length;
            document.getElementById('selected-count').textContent = selected;

            const actionContainer = document.getElementById('shipping-actions');
            if (selected > 0) {
                actionContainer.classList.remove('d-none');
            } else {
                actionContainer.classList.add('d-none');
            }
        },

        generatePickingList: async function() {
            const selected = Array.from(document.querySelectorAll('.order-checkbox:checked')).map(cb => cb.value);
            if (selected.length === 0) return;

            // Trigger download via POST
            try {
                // ApiClient adiciona CSRF token automaticamente + retry em 429/503
                const apiFetch = window.ApiClient ? window.ApiClient.fetch : (u, o) => fetch(u, o);
                const response = await apiFetch('/api/shipping/picking-list', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        order_ids: selected
                    })
                });

                if (response.ok) {
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `picking_list_${new Date().toISOString().slice(0,10)}.pdf`;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();

                    Toast.success('Picking List gerada com sucesso!');
                } else {
                    Toast.error('Erro ao gerar Picking List');
                }
            } catch (e) {
                console.error(e);
                Toast.error('Erro de conexão');
            }
        }
    };

    // Auto-init
    document.addEventListener('DOMContentLoaded', () => shippingManager.init());
</script>
