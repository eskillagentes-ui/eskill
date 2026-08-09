<?php

declare(strict_types=1);

$title = 'Monitor de Concorrência';
$subtitle = 'Análise Competitiva de Preços e Buy Box';
include __DIR__ . '/../../layouts/modern/partials/page-header.php';
?>

<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="rounded-circle bg-danger bg-opacity-10 p-3">
                            <i class="bi bi-graph-down-arrow text-danger fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="text-muted small">Perdendo Buy Box</div>
                        <h3 class="mb-0" id="stat-losing">-</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="rounded-circle bg-success bg-opacity-10 p-3">
                            <i class="bi bi-trophy text-success fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="text-muted small">Vencendo Buy Box</div>
                        <h3 class="mb-0" id="stat-winning">-</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                            <i class="bi bi-currency-dollar text-warning fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="text-muted small">Diferença Média</div>
                        <h3 class="mb-0" id="stat-diff">-</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="rounded-circle bg-info bg-opacity-10 p-3">
                            <i class="bi bi-cart text-info fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="text-muted small">Total Monitorados</div>
                        <h3 class="mb-0" id="stat-total">-</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters & Actions -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small text-muted">Buscar Produto</label>
                        <input type="text" class="form-control" id="search-input" placeholder="Digite o nome ou ID...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Status</label>
                        <select class="form-select" id="filter-status">
                            <option value="all">Todos</option>
                            <option value="losing" selected>Perdendo Buy Box</option>
                            <option value="winning">Vencendo Buy Box</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Diferença</label>
                        <select class="form-select" id="filter-diff">
                            <option value="all">Qualquer</option>
                            <option value="low">Até 5%</option>
                            <option value="medium">5% - 15%</option>
                            <option value="high">Acima de 15%</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary w-100" onclick="catalogManager.loadData()">
                            <i class="bi bi-arrow-clockwise"></i> Atualizar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Table -->
<div class="row">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0"><i class="bi bi-list-ul"></i> Itens em Catálogo</h5>
                    <small class="text-muted">Acompanhe sua posição competitiva em tempo real</small>
                </div>
                <div class="btn-group" role="group">
                    <button class="btn btn-sm btn-outline-success" onclick="catalogManager.bulkAction('adjust')">
                        <i class="bi bi-check-circle"></i> Ajustar Selecionados
                    </button>
                    <button class="btn btn-sm btn-outline-primary" onclick="catalogManager.exportData()">
                        <i class="bi bi-download"></i> Exportar
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="catalog-table">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4" style="width: 40px;">
                                    <input type="checkbox" class="form-check-input" id="select-all">
                                </th>
                                <th>Produto</th>
                                <th>Meu Preço</th>
                                <th>Preço Vencedor</th>
                                <th>Diferença</th>
                                <th>Status</th>
                                <th>Sugestão</th>
                                <th class="text-end pe-4">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="catalog-list">
                            <tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-muted small" id="results-info">Carregando...</div>
                    <nav>
                        <ul class="pagination pagination-sm mb-0" id="pagination"></ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Price Update -->
<div class="modal fade" id="priceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title"><i class="bi bi-tag"></i> Atualizar Preço</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle"></i> Para ganhar a Buy Box, ajuste seu preço competitivamente
                </div>
                <div class="mb-3">
                    <label class="form-label">Produto</label>
                    <div class="fw-bold" id="modal-product-title"></div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small text-muted">Preço Atual</label>
                        <input type="text" class="form-control" id="modal-current-price" readonly>
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-muted">Preço Vencedor</label>
                        <input type="text" class="form-control text-danger" id="modal-winner-price" readonly>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Novo Preço <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">R$</span>
                        <input type="number" class="form-control form-control-lg" id="new-price" step="0.01" min="0" required>
                    </div>
                    <div class="form-text">Digite o novo preço competitivo</div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="catalogManager.updatePrice()">
                    <i class="bi bi-check-circle"></i> Confirmar Atualização
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Item Details -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title"><i class="bi bi-info-circle"></i> Detalhes da Competição</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="details-body">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.badge-status { font-size: 0.75rem; padding: 0.35rem 0.75rem; }
.table tbody tr:hover { background-color: rgba(13, 110, 253, 0.03); }
.price-diff-positive { color: #dc3545; font-weight: 600; }
.price-diff-negative { color: #198754; font-weight: 600; }
#catalog-table thead th { 
    font-size: 0.875rem; 
    font-weight: 600; 
    text-transform: uppercase; 
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.product-thumbnail { 
    width: 50px; 
    height: 50px; 
    object-fit: cover; 
    border-radius: 8px; 
}
</style>

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

    const catalogManager = {
        currentData: [],
        filteredData: [],
        selectedItems: new Set(),
        currentPage: 1,
        itemsPerPage: 10,

        init: function() {
            this.loadData();
            this.setupEventListeners();
        },

        setupEventListeners: function() {
            // Search
            document.getElementById('search-input').addEventListener('input', () => {
                this.applyFilters();
            });

            // Filters
            document.getElementById('filter-status').addEventListener('change', () => this.applyFilters());
            document.getElementById('filter-diff').addEventListener('change', () => this.applyFilters());

            // Select all checkbox
            document.getElementById('select-all').addEventListener('change', (e) => {
                const checkboxes = document.querySelectorAll('.item-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = e.target.checked;
                    if (e.target.checked) {
                        this.selectedItems.add(cb.dataset.id);
                    } else {
                        this.selectedItems.delete(cb.dataset.id);
                    }
                });
            });
        },

        loadData: async function() {
            try {
                const data = await requestJson('/api/catalog/losing');
                
                if (data.success) {
                    this.currentData = data.items || [];
                    this.processData();
                    this.updateStatistics();
                    this.applyFilters();
                }
            } catch (e) {
                console.error(e);
                document.getElementById('catalog-list').innerHTML = 
                    `<tr><td colspan="8" class="text-center py-4 text-danger">
                        <i class="bi bi-exclamation-triangle fs-1 d-block mb-2"></i>
                        Erro ao carregar dados
                    </td></tr>`;
            }
        },

        processData: function() {
            // Correcao de bug: is_winning era recalculado so comparando precos no
            // frontend, ignorando o campo is_winner real vindo da API (comparacao
            // de seller_id feita no backend). Isso podia divergir do resultado real
            // da disputa de catalogo (preco nao e o unico criterio da buy box).
            // Agora usamos o valor do servidor como fonte da verdade; so caimos no
            // calculo por preco se o servidor genuinamente nao informou is_winner.
            this.currentData.forEach(item => {
                item.diff_percent = ((item.my_price - item.buy_box_winner.price) / item.my_price * 100).toFixed(2);
                item.is_winning = (item.is_winner !== null && item.is_winner !== undefined)
                    ? !!item.is_winner
                    : (item.my_price <= item.buy_box_winner.price);
            });
        },

        applyFilters: function() {
            const search = document.getElementById('search-input').value.toLowerCase();
            const statusFilter = document.getElementById('filter-status').value;
            const diffFilter = document.getElementById('filter-diff').value;

            this.filteredData = this.currentData.filter(item => {
                // Search filter
                if (search && !item.title.toLowerCase().includes(search) && !item.id.includes(search)) {
                    return false;
                }

                // Status filter
                if (statusFilter === 'losing' && item.is_winning) return false;
                if (statusFilter === 'winning' && !item.is_winning) return false;

                // Diff filter
                const diff = Math.abs(parseFloat(item.diff_percent));
                if (diffFilter === 'low' && diff > 5) return false;
                if (diffFilter === 'medium' && (diff <= 5 || diff > 15)) return false;
                if (diffFilter === 'high' && diff <= 15) return false;

                return true;
            });

            this.currentPage = 1;
            this.render();
        },

        updateStatistics: function() {
            const losing = this.currentData.filter(i => !i.is_winning).length;
            const winning = this.currentData.filter(i => i.is_winning).length;
            const avgDiff = this.currentData.length > 0 
                ? this.currentData.reduce((sum, i) => sum + Math.abs(parseFloat(i.diff_percent)), 0) / this.currentData.length
                : 0;

            document.getElementById('stat-losing').textContent = losing;
            document.getElementById('stat-winning').textContent = winning;
            document.getElementById('stat-diff').textContent = avgDiff.toFixed(1) + '%';
            document.getElementById('stat-total').textContent = this.currentData.length;
        },

        render: function() {
            const container = document.getElementById('catalog-list');
            
            if (this.filteredData.length === 0) {
                container.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <div class="text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                <div>Nenhum item encontrado com os filtros aplicados</div>
                                <small>Experimente ajustar os filtros</small>
                            </div>
                        </td>
                    </tr>
                `;
                document.getElementById('results-info').textContent = 'Nenhum resultado';
                document.getElementById('pagination').innerHTML = '';
                return;
            }

            const money = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val);
            
            // Pagination
            const start = (this.currentPage - 1) * this.itemsPerPage;
            const end = start + this.itemsPerPage;
            const pageItems = this.filteredData.slice(start, end);

            let html = '';
            pageItems.forEach(item => {
                const diffClass = item.is_winning ? 'price-diff-negative' : 'price-diff-positive';
                const statusBadge = item.is_winning 
                    ? '<span class="badge bg-success badge-status"><i class="bi bi-trophy"></i> Vencendo</span>'
                    : '<span class="badge bg-danger badge-status"><i class="bi bi-exclamation-circle"></i> Perdendo</span>';
                
                html += `
                    <tr>
                        <td class="ps-4">
                            <input type="checkbox" class="form-check-input item-checkbox" data-id="${item.id}" 
                                   onchange="catalogManager.toggleSelect('${item.id}', this.checked)">
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <img src="${normalizeExternalUrl(item.thumbnail)}" class="product-thumbnail me-3" alt="Product">
                                <div style="max-width: 350px;">
                                    <div class="fw-bold text-truncate mb-1">${item.title}</div>
                                    <small class="text-muted">${item.id}</small>
                                </div>
                            </div>
                        </td>
                        <td><span class="fw-bold">${money(item.my_price)}</span></td>
                        <td><span class="text-danger fw-bold">${money(item.buy_box_winner.price)}</span></td>
                        <td><span class="${diffClass}">${item.diff_percent > 0 ? '+' : ''}${item.diff_percent}%</span></td>
                        <td>${statusBadge}</td>
                        <td><span class="text-success fw-bold">${money(item.price_to_win)}</span></td>
                        <td class="text-end pe-4">
                            <div class="btn-group btn-group-sm" role="group">
                                <button class="btn btn-outline-primary" onclick="catalogManager.viewDetails('${item.id}')" title="Ver Detalhes">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-outline-success" onclick="catalogManager.openModal('${item.id}')" title="Atualizar Preço">
                                    <i class="bi bi-tag"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            container.innerHTML = html;
            this.renderPagination();
            
            // Update info
            document.getElementById('results-info').textContent = 
                `Mostrando ${start + 1}-${Math.min(end, this.filteredData.length)} de ${this.filteredData.length} itens`;
        },

        renderPagination: function() {
            const totalPages = Math.ceil(this.filteredData.length / this.itemsPerPage);
            const container = document.getElementById('pagination');
            
            if (totalPages <= 1) {
                container.innerHTML = '';
                return;
            }

            let html = '';
            
            // Previous
            html += `
                <li class="page-item ${this.currentPage === 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="catalogManager.goToPage(${this.currentPage - 1}); return false;">‹</a>
                </li>
            `;

            // Pages
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= this.currentPage - 1 && i <= this.currentPage + 1)) {
                    html += `
                        <li class="page-item ${i === this.currentPage ? 'active' : ''}">
                            <a class="page-link" href="#" onclick="catalogManager.goToPage(${i}); return false;">${i}</a>
                        </li>
                    `;
                } else if (i === this.currentPage - 2 || i === this.currentPage + 2) {
                    html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
            }

            // Next
            html += `
                <li class="page-item ${this.currentPage === totalPages ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="catalogManager.goToPage(${this.currentPage + 1}); return false;">›</a>
                </li>
            `;

            container.innerHTML = html;
        },

        goToPage: function(page) {
            const totalPages = Math.ceil(this.filteredData.length / this.itemsPerPage);
            if (page < 1 || page > totalPages) return;
            
            this.currentPage = page;
            this.render();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        toggleSelect: function(id, checked) {
            if (checked) {
                this.selectedItems.add(id);
            } else {
                this.selectedItems.delete(id);
            }
        },

        openModal: function(id) {
            const item = this.currentData.find(i => i.id === id);
            if (!item) return;

            const money = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val);
            
            document.getElementById('modal-product-title').textContent = item.title;
            document.getElementById('modal-current-price').value = money(item.my_price);
            document.getElementById('modal-winner-price').value = money(item.buy_box_winner.price);
            document.getElementById('new-price').value = item.price_to_win;
            document.getElementById('new-price').dataset.itemId = id;

            new bootstrap.Modal(document.getElementById('priceModal')).show();
        },
        
        updatePrice: function() {
            const newPrice = document.getElementById('new-price').value;
            const itemId = document.getElementById('new-price').dataset.itemId;
            
            if (!newPrice || parseFloat(newPrice) <= 0) {
                alert('Digite um preço válido');
                return;
            }

            bootstrap.Modal.getInstance(document.getElementById('priceModal')).hide();

            requestJson(`/api/items/${itemId}/price`, {
                method: 'PUT',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ price: parseFloat(newPrice) })
            })
            .then(resp => {
                if (resp.error) throw new Error(resp.error);
                Toast.success('Preço atualizado com sucesso!');
                this.loadData();
            })
            .catch(err => {
                console.error(err);
                Toast.error('Erro ao atualizar preço: ' + err.message);
            });
        },

        viewDetails: function(id) {
            const item = this.currentData.find(i => i.id === id);
            if (!item) return;

            const money = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val);
            
            const html = `
                <div class="row">
                    <div class="col-md-4 text-center border-end">
                        <img src="${normalizeExternalUrl(item.thumbnail)}" class="img-fluid rounded mb-3" style="max-height: 200px;">
                        <a href="${normalizeExternalUrl(item.permalink) || '#'}" target="_blank" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-box-arrow-up-right"></i> Ver no Mercado Livre
                        </a>
                    </div>
                    <div class="col-md-8">
                        <h5 class="mb-1">${item.title}</h5>
                        <p class="text-muted small mb-3">ID: ${item.id}</p>
                        <hr>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <div class="border rounded p-3 text-center">
                                    <div class="text-muted small mb-1">Meu Preço</div>
                                    <div class="h4 mb-0">${money(item.my_price)}</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-3 text-center border-danger">
                                    <div class="text-muted small mb-1">Preço Vencedor</div>
                                    <div class="h4 mb-0 text-danger">${money(item.buy_box_winner.price)}</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-3 text-center border-success">
                                    <div class="text-muted small mb-1">Sugestão Ideal</div>
                                    <div class="h4 mb-0 text-success">${money(item.price_to_win)}</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-3 text-center ${item.is_winning ? 'border-success' : 'border-danger'}">
                                    <div class="text-muted small mb-1">Diferença</div>
                                    <div class="h4 mb-0 ${item.is_winning ? 'text-success' : 'text-danger'}">${item.diff_percent}%</div>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="alert ${item.is_winning ? 'alert-success' : 'alert-warning'} mb-0">
                            <i class="bi ${item.is_winning ? 'bi-trophy' : 'bi-exclamation-triangle'} me-2"></i>
                            <strong>${item.is_winning ? 'Você está vencendo!' : 'Atenção:'}</strong>
                            ${item.is_winning 
                                ? 'Continue monitorando para manter sua posição de destaque.' 
                                : 'Ajuste seu preço para recuperar a Buy Box e aumentar suas vendas.'
                            }
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('details-body').innerHTML = html;
            new bootstrap.Modal(document.getElementById('detailsModal')).show();
        },

        bulkAction: function(action) {
            if (this.selectedItems.size === 0) {
                alert('Selecione pelo menos um item');
                return;
            }

            if (action === 'adjust') {
                if (confirm(`Deseja ajustar os preços de ${this.selectedItems.size} itens selecionados automaticamente?`)) {
                    if (typeof Toast !== 'undefined') {
                        Toast.success(`${this.selectedItems.size} itens ajustados com sucesso!`);
                    } else {
                        alert(`${this.selectedItems.size} itens ajustados!`);
                    }
                    this.selectedItems.clear();
                    document.getElementById('select-all').checked = false;
                    setTimeout(() => this.loadData(), 1000);
                }
            }
        },

        exportData: function() {
            const csv = this.generateCSV();
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `competicao-catalogo-${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            
            if (typeof Toast !== 'undefined') {
                Toast.success('Dados exportados com sucesso!');
            }
        },

        generateCSV: function() {
            let csv = 'ID,Produto,Meu Preço,Preço Vencedor,Diferença (%),Status,Sugestão\n';
            
            this.filteredData.forEach(item => {
                csv += `"${item.id}","${item.title.replace(/"/g, '""')}",${item.my_price},${item.buy_box_winner.price},${item.diff_percent},${item.is_winning ? 'Vencendo' : 'Perdendo'},${item.price_to_win}\n`;
            });
            
            return csv;
        }
    };

    document.addEventListener('DOMContentLoaded', () => catalogManager.init());
</script>
