'use strict';

let currentPage = 1;
const itemsPerPage = 12;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
let currentViewMode = localStorage.getItem('itemsViewMode') || 'table';

document.addEventListener('DOMContentLoaded', function () {
    // Stagger API calls to avoid rate limiting
    setTimeout(loadItems, 100);
    setTimeout(loadStats, 300);
    setTimeout(loadCategories, 500);

    document.getElementById('editTitle').addEventListener('input', function () {
        document.getElementById('titleCount').textContent = this.value.length;
    });

    // Auto-load on change
    ['statusFilter', 'categoryFilter', 'searchInput', 'orderFilter'].forEach(id => {
        document.getElementById(id).addEventListener('change', loadItems);
        document.getElementById(id).addEventListener('keyup', loadItems);
    });
});

async function loadStats(retryCount = 0) {
    try {
        const { response, data } = await ApiClient.json('/api/items/stats');

        if (response.status === 429) {
            if (retryCount < 3) {
                const delay = Math.pow(2, retryCount) * 1000; // 1s, 2s, 4s
                setTimeout(() => loadStats(retryCount + 1), delay);
                return;
            }
        }

        if (data.success) {
            document.getElementById('activeCount').textContent = data.active || 0;
            document.getElementById('pausedCount').textContent = data.paused || 0;
            document.getElementById('closedCount').textContent = data.closed || 0;
            document.getElementById('totalViews').textContent = formatNumber(data.total_views || 0);
            document.getElementById('totalSold').textContent = data.total_sold || 0;
            document.getElementById('lowStockCount').textContent = data.low_stock || 0;
        }
    } catch (e) {
        console.error('Erro ao carregar estatísticas:', e);
    }
}

async function loadCategories(retryCount = 0) {
    try {
        const { response, data } = await ApiClient.json('/api/items/categories');

        if (response.status === 429) {
            if (retryCount < 3) {
                const delay = Math.pow(2, retryCount) * 1000; // 1s, 2s, 4s
                setTimeout(() => loadCategories(retryCount + 1), delay);
                return;
            }
        }

        const select = document.getElementById('categoryFilter');
        if (data.success && data.categories) {
            data.categories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat.id;
                option.textContent = `${cat.name}${cat.results ? ` (${cat.results})` : ''}`;
                select.appendChild(option);
            });
        }
    } catch (e) { }
}

async function loadItems(retryCount = 0) {
    const status = document.getElementById('statusFilter').value;
    const category = document.getElementById('categoryFilter').value;
    const search = document.getElementById('searchInput').value;
    const order = document.getElementById('orderFilter').value;

    const tbody = document.querySelector('#itemsGrid tbody');
    tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></td></tr>';

    try {
        let url = `/api/items?page=${currentPage}&limit=${itemsPerPage}`;
        if (status) url += `&status=${status}`;
        if (category) url += `&category=${category}`;
        if (search) url += `&search=${encodeURIComponent(search)}`;
        if (order) url += `&order=${order}`;

        const { response, data } = await ApiClient.json(url);

        if (response.status === 429) {
            if (retryCount < 3) {
                const delay = Math.pow(2, retryCount) * 1000; // 1s, 2s, 4s
                setTimeout(() => loadItems(retryCount + 1), delay);
                return;
            }
            tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-danger">Muitas requisições. Tente novamente em alguns segundos.</td></tr>';
            return;
        }

        if (data.success && data.items && data.items.length > 0) {
            const tbody = document.querySelector('#itemsGrid tbody');
            tbody.innerHTML = data.items.map(item => {
                const itemId = item.id || item.ml_item_id;
                const thumbnail = fixImageUrl(item.thumbnail || '/images/no-image.png');
                const statusClass = getStatusClass(item.status);
                const statusLabel = getStatusLabel(item.status);
                const visits = item.visits ?? 0;
                const sold = item.sold_quantity ?? 0;
                const sku = item.sku || '';
                const price = parseFloat(item.price || 0);

                return `
                    <tr class="item-row">
                        <td data-label="Selecionar">
                            <input type="checkbox" class="form-check-input" value="${itemId}" onchange="updateBulkSelection()" data-item-id="${itemId}">
                        </td>
                        <td data-label="Anúncio">
                            <div class="d-flex align-items-center gap-3">
                                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect fill='%23f8f9fa' width='100' height='100'/%3E%3Ctext x='50%25' y='50%25' font-size='12' fill='%23999' text-anchor='middle' dy='.3em'%3EIMG%3C/text%3E%3C/svg%3E"
                                     data-src="${thumbnail}"
                                     class="item-thumbnail lazy-load"
                                     alt="${escapeHtml(item.title)}">
                                <div class="flex-grow-1">
                                    <div class="item-title fw-medium" title="${escapeHtml(item.title)}">${escapeHtml(item.title)}</div>
                                    <div class="text-muted small">${itemId}</div>
                                </div>
                            </div>
                        </td>
                        <td data-label="SKU">
                            ${sku ? `<span class="badge bg-light text-dark">${escapeHtml(sku)}</span>` : '-'}
                        </td>
                        <td data-label="Status">
                            <span class="badge ${statusClass}">${statusLabel}</span>
                        </td>
                        <td data-label="Preço" class="fw-bold text-primary">${formatCurrency(price)}</td>
                        <td data-label="Estoque">
                            <span class="${(item.available_quantity || 0) < 5 ? 'text-danger' : 'text-muted'}">
                                ${item.available_quantity || 0}
                            </span>
                        </td>
                        <td data-label="Visitas" class="text-muted">${visits}</td>
                        <td data-label="Vendas" class="text-muted">${sold}</td>
                        <td data-label="Ações">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" data-action="edit-item" data-item-id="${itemId}" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-outline-warning" data-action="seo-item" data-item-id="${itemId}" title="SEO">
                                    <i class="bi bi-lightning"></i>
                                </button>
                                ${item.status === 'active' ? `
                                    <button class="btn btn-outline-secondary" data-action="pause-item" data-item-id="${itemId}" title="Pausar">
                                        <i class="bi bi-pause"></i>
                                    </button>
                                ` : `
                                    <button class="btn btn-outline-success" data-action="activate-item" data-item-id="${itemId}" title="Ativar">
                                        <i class="bi bi-play"></i>
                                    </button>
                                `}
                                <a href="${normalizeExternalUrl(item.permalink) || '#'}" target="_blank" class="btn btn-outline-info" title="Ver ML">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');

            // Inicializar lazy loading
            initLazyLoading();

            renderPagination(data.total, data.page, data.pages);
            document.getElementById('itemsCount').textContent = `${data.total || 0} anúncios`;
            document.getElementById('showingInfo').textContent = `Página ${data.page} de ${data.pages || 1}`;
        } else {
            const tbody = document.querySelector('#itemsGrid tbody');
            tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">Nenhum anúncio encontrado</td></tr>';
            document.getElementById('pagination').innerHTML = '';
            document.getElementById('itemsCount').textContent = '0 anúncios';
        }
    } catch (e) {
        const tbody = document.querySelector('#itemsGrid tbody');
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-danger">Erro ao carregar</td></tr>';
    }
}

function renderPagination(total, current, pages) {
    const pagination = document.getElementById('pagination');
    if (pages <= 1) {
        pagination.innerHTML = '';
        return;
    }

    let html = '';
    if (current > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" data-action="goto-page" data-page="${current - 1}">«</a></li>`;
    }

    const start = Math.max(1, current - 2);
    const end = Math.min(pages, current + 2);

    for (let i = start; i <= end; i++) {
        html += `<li class="page-item ${i === current ? 'active' : ''}">
                <a class="page-link" href="#" data-action="goto-page" data-page="${i}">${i}</a>
            </li>`;
    }

    if (current < pages) {
        html += `<li class="page-item"><a class="page-link" href="#" data-action="goto-page" data-page="${current + 1}">»</a></li>`;
    }

    pagination.innerHTML = html;
}

function goToPage(page) {
    currentPage = page;
    loadItems();
}

function filterByStatus(status) {
    document.getElementById('statusFilter').value = status;
    document.getElementById('searchInput').value = '';
    currentPage = 1;
    loadItems();

    // Highlight the active filter
    highlightActiveStat(status);
}

function filterByLowStock() {
    document.getElementById('statusFilter').value = '';
    document.getElementById('searchInput').value = '';
    document.getElementById('categoryFilter').value = '';
    currentPage = 1;
    loadLowStockItems();

    // Highlight the active filter
    highlightActiveStat('low-stock');
}

function filterByHighSales() {
    document.getElementById('statusFilter').value = 'active';
    document.getElementById('searchInput').value = '';
    document.getElementById('categoryFilter').value = '';
    currentPage = 1;
    loadHighSalesItems();

    // Highlight the active filter
    highlightActiveStat('high-sales');
}

async function loadLowStockItems() {
    const tbody = document.querySelector('#itemsGrid tbody');
    tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Carregando itens com estoque baixo...</td></tr>';

    try {
        const { response, data } = await ApiClient.json(`/api/items?low_stock=true&page=${currentPage}&limit=${itemsPerPage}`);

        if (response.status === 429) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-warning">Muitas requisições. Aguarde...</td></tr>';
            setTimeout(loadLowStockItems, 2000);
            return;
        }

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-warning">
                    ${data.message || 'Não foi possível carregar itens com estoque baixo. Tente usar os filtros padrão.'}
                </td></tr>`;
            return;
        }

        if (data.success && data.items && data.items.length > 0) {
            const tbody = document.querySelector('#itemsGrid tbody');
            tbody.innerHTML = data.items.map(item => {
                const itemId = item.id || item.ml_item_id;
                const thumbnail = fixImageUrl(item.thumbnail || '/images/no-image.png');
                const statusClass = getStatusClass(item.status);
                const statusLabel = getStatusLabel(item.status);
                const visits = item.visits ?? 0;
                const sold = item.sold_quantity ?? 0;
                const sku = item.sku || '';
                const price = parseFloat(item.price || 0);

                return `
                    <tr class="item-row">
                        <td data-label="Selecionar">
                            <input type="checkbox" class="form-check-input" value="${itemId}" onchange="updateBulkSelection()" data-item-id="${itemId}">
                        </td>
                        <td data-label="Anúncio">
                            <div class="d-flex align-items-center gap-3">
                                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect fill='%23f8f9fa' width='100' height='100'/%3E%3Ctext x='50%25' y='50%25' font-size='12' fill='%23999' text-anchor='middle' dy='.3em'%3EIMG%3C/text%3E%3C/svg%3E"
                                     data-src="${thumbnail}"
                                     class="item-thumbnail lazy-load"
                                     alt="${escapeHtml(item.title)}">
                                <div class="flex-grow-1">
                                    <div class="item-title fw-medium" title="${escapeHtml(item.title)}">${escapeHtml(item.title)}</div>
                                    <div class="text-muted small">${itemId}</div>
                                </div>
                            </div>
                        </td>
                        <td data-label="SKU">
                            ${sku ? `<span class="badge bg-light text-dark">${escapeHtml(sku)}</span>` : '-'}
                        </td>
                        <td data-label="Status">
                            <span class="badge ${statusClass}">${statusLabel}</span>
                        </td>
                        <td data-label="Preço" class="fw-bold text-primary">${formatCurrency(price)}</td>
                        <td data-label="Estoque">
                            <span class="${(item.available_quantity || 0) < 5 ? 'text-danger' : 'text-muted'}">
                                ${item.available_quantity || 0}
                            </span>
                        </td>
                        <td data-label="Visitas" class="text-muted">${visits}</td>
                        <td data-label="Vendas" class="text-muted">${sold}</td>
                        <td data-label="Ações">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" data-action="edit-item" data-item-id="${itemId}" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-outline-warning" data-action="seo-item" data-item-id="${itemId}" title="SEO">
                                    <i class="bi bi-lightning"></i>
                                </button>
                                ${item.status === 'active' ? `
                                    <button class="btn btn-outline-secondary" data-action="pause-item" data-item-id="${itemId}" title="Pausar">
                                        <i class="bi bi-pause"></i>
                                    </button>
                                ` : `
                                    <button class="btn btn-outline-success" data-action="activate-item" data-item-id="${itemId}" title="Ativar">
                                        <i class="bi bi-play"></i>
                                    </button>
                                `}
                                <a href="${normalizeExternalUrl(item.permalink) || '#'}" target="_blank" class="btn btn-outline-info" title="Ver ML">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');

            // Inicializar lazy loading
            initLazyLoading();

            renderPagination(data.total, data.page, data.pages);
            document.getElementById('itemsCount').textContent = `${data.total || 0} anúncios`;
            document.getElementById('showingInfo').textContent = `Página ${data.page} de ${data.pages || 1}`;
        } else {
            const tbody = document.querySelector('#itemsGrid tbody');
            tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">Nenhum anúncio encontrado</td></tr>';
            document.getElementById('pagination').innerHTML = '';
            document.getElementById('itemsCount').textContent = '0 anúncios';
        }
    } catch (e) {
        console.error('Error loading low stock items:', e);
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-danger">Erro ao carregar itens com estoque baixo</td></tr>';
    }
}

async function loadHighSalesItems() {
    const tbody = document.querySelector('#itemsGrid tbody');
    tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Carregando mais vendidos...</td></tr>';

    try {
        const { response, data } = await ApiClient.json(`/api/items?high_sales=true&page=${currentPage}&limit=${itemsPerPage}`);

        if (response.status === 429) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-warning">Muitas requisições. Aguarde...</td></tr>';
            setTimeout(loadHighSalesItems, 2000);
            return;
        }

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-warning">
                    ${data.message || 'Não foi possível carregar mais vendidos. Tente usar os filtros padrão.'}
                </td></tr>`;
            return;
        }

        if (data.success && data.items && data.items.length > 0) {
            const tbody = document.querySelector('#itemsGrid tbody');
            tbody.innerHTML = data.items.map(item => {
                const itemId = item.id || item.ml_item_id;
                const thumbnail = fixImageUrl(item.thumbnail || '/images/no-image.png');
                const statusClass = getStatusClass(item.status);
                const statusLabel = getStatusLabel(item.status);
                const visits = item.visits ?? 0;
                const sold = item.sold_quantity ?? 0;
                const sku = item.sku || '';
                const price = parseFloat(item.price || 0);

                return `
                    <tr class="item-row">
                        <td data-label="Selecionar">
                            <input type="checkbox" class="form-check-input" value="${itemId}" onchange="updateBulkSelection()" data-item-id="${itemId}">
                        </td>
                        <td data-label="Anúncio">
                            <div class="d-flex align-items-center gap-3">
                                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect fill='%23f8f9fa' width='100' height='100'/%3E%3Ctext x='50%25' y='50%25' font-size='12' fill='%23999' text-anchor='middle' dy='.3em'%3EIMG%3C/text%3E%3C/svg%3E"
                                     data-src="${thumbnail}"
                                     class="item-thumbnail lazy-load"
                                     alt="${escapeHtml(item.title)}">
                                <div class="flex-grow-1">
                                    <div class="item-title fw-medium" title="${escapeHtml(item.title)}">${escapeHtml(item.title)}</div>
                                    <div class="text-muted small">${itemId}</div>
                                </div>
                            </div>
                        </td>
                        <td data-label="SKU">
                            ${sku ? `<span class="badge bg-light text-dark">${escapeHtml(sku)}</span>` : '-'}
                        </td>
                        <td data-label="Status">
                            <span class="badge ${statusClass}">${statusLabel}</span>
                        </td>
                        <td data-label="Preço" class="fw-bold text-primary">${formatCurrency(price)}</td>
                        <td data-label="Estoque">
                            <span class="${(item.available_quantity || 0) < 5 ? 'text-danger' : 'text-muted'}">
                                ${item.available_quantity || 0}
                            </span>
                        </td>
                        <td data-label="Visitas" class="text-muted">${visits}</td>
                        <td data-label="Vendas" class="text-muted">${sold}</td>
                        <td data-label="Ações">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" data-action="edit-item" data-item-id="${itemId}" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-outline-warning" data-action="seo-item" data-item-id="${itemId}" title="SEO">
                                    <i class="bi bi-lightning"></i>
                                </button>
                                ${item.status === 'active' ? `
                                    <button class="btn btn-outline-secondary" data-action="pause-item" data-item-id="${itemId}" title="Pausar">
                                        <i class="bi bi-pause"></i>
                                    </button>
                                ` : `
                                    <button class="btn btn-outline-success" data-action="activate-item" data-item-id="${itemId}" title="Ativar">
                                        <i class="bi bi-play"></i>
                                    </button>
                                `}
                                <a href="${normalizeExternalUrl(item.permalink) || '#'}" target="_blank" class="btn btn-outline-info" title="Ver ML">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');

            // Inicializar lazy loading
            initLazyLoading();

            renderPagination(data.total, data.page, data.pages);
            document.getElementById('itemsCount').textContent = `${data.total || 0} anúncios`;
            document.getElementById('showingInfo').textContent = `Página ${data.page} de ${data.pages || 1}`;
        } else {
            const tbody = document.querySelector('#itemsGrid tbody');
            tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">Nenhum anúncio encontrado</td></tr>';
            document.getElementById('pagination').innerHTML = '';
            document.getElementById('itemsCount').textContent = '0 anúncios';
        }
    } catch (e) {
        console.error('Error loading high sales items:', e);
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-danger">Erro ao carregar mais vendidos</td></tr>';
    }
}

function highlightActiveStat(activeType) {
    // Remove all active classes
    document.querySelectorAll('.clickable-stat').forEach(card => {
        card.classList.remove('border-primary', 'border-2');
        card.classList.add('border-light');
    });

    // Add active class to the clicked card
    const activeCard = document.querySelector(`[onclick*="${activeType}"]`) ||
        document.querySelector(`[onclick*="${activeType.replace('-', '')}"]`);
    if (activeCard) {
        activeCard.classList.remove('border-light');
        activeCard.classList.add('border-primary', 'border-2');
    }
}

function clearFilters() {
    document.getElementById('statusFilter').value = '';
    document.getElementById('searchInput').value = '';
    document.getElementById('categoryFilter').value = '';
    document.getElementById('orderFilter').value = '';
    currentPage = 1;
    loadItems();

    // Remove highlighting from all stat cards
    document.querySelectorAll('.clickable-stat').forEach(card => {
        card.classList.remove('border-primary', 'border-2');
        card.classList.add('border-light');
    });
}

// Função de exportação
async function exportItems() {
    const tbody = document.querySelector('#itemsGrid tbody');
    const originalContent = tbody.innerHTML;

    tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Exportando itens...</td></tr>';

    try {
        // Obter todos os itens sem paginação para exportação
        const status = document.getElementById('statusFilter').value;
        const category = document.getElementById('categoryFilter').value;
        const search = document.getElementById('searchInput').value;
        const order = document.getElementById('orderFilter').value;

        let exportUrl = `/api/items?limit=1000`; // Limite alto para exportação
        if (status) exportUrl += `&status=${status}`;
        if (category) exportUrl += `&category=${category}`;
        if (search) exportUrl += `&search=${encodeURIComponent(search)}`;
        if (order) exportUrl += `&order=${order}`;

        const { data } = await ApiClient.json(exportUrl);

        if (!data.success || !data.items || data.items.length === 0) {
            tbody.innerHTML = originalContent;
            alert('Nenhum item para exportar ou erro ao carregar dados.');
            return;
        }

        // Gerar CSV
        const csv = generateItemsCSV(data.items);

        // Fazer download
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const downloadLink = document.createElement('a');
        const downloadUrl = URL.createObjectURL(blob);

        downloadLink.setAttribute('href', downloadUrl);
        downloadLink.setAttribute('download', `itens_${new Date().toISOString().split('T')[0]}.csv`);
        downloadLink.style.visibility = 'hidden';

        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);

        tbody.innerHTML = originalContent;

        // Show success message
        const announcer = document.getElementById('sidebar-announcer');
        if (announcer) {
            announcer.textContent = `${data.items.length} itens exportados com sucesso!`;
            setTimeout(() => announcer.textContent = '', 5000);
        }

    } catch (e) {
        tbody.innerHTML = originalContent;
        console.error('Error exporting items:', e);
        alert('Erro ao exportar itens. Tente novamente.');
    }
}

function generateItemsCSV(items) {
    const headers = [
        'ID', 'Título', 'SKU', 'Status', 'Preço', 'Estoque',
        'Visitas', 'Vendas', 'Categoria', 'Link'
    ];

    const csvContent = [
        headers.join(','),
        ...items.map(item => [
            `"${item.id || ''}"`,
            `"${(item.title || '').replace(/"/g, '""')}"`,
            `"${(item.sku || '').replace(/"/g, '""')}"`,
            `"${item.status || ''}"`,
            `"${(item.price || 0).toFixed(2).replace('.', ',')}"`,
            `"${item.available_quantity || 0}"`,
            `"${item.visits || 0}"`,
            `"${item.sold_quantity || 0}"`,
            `"${(item.category_id || '').replace(/"/g, '""')}"`,
            `"${(item.permalink || '').replace(/"/g, '""')}"`
        ].join(','))
    ].join('\n');

    // Adicionar BOM para Excel reconhecer caracteres especiais
    return '\ufeff' + csvContent;
}

// Funções de alternância de visualização
function switchViewMode(mode) {
    const tableView = document.getElementById('tableView');
    const cardView = document.getElementById('cardView');

    if (mode === 'table') {
        tableView.style.display = 'block';
        cardView.style.display = 'none';
    } else {
        tableView.style.display = 'none';
        cardView.style.display = 'flex';
        // Se houver itens, renderizar em cards
        renderCardView();
    }

    // Salvar preferência
    localStorage.setItem('itemsViewMode', mode);

    // Atualizar botões radio
    document.getElementById('tableViewMode').checked = mode === 'table';
    document.getElementById('cardViewMode').checked = mode === 'cards';
}

function renderCardView() {
    const cardView = document.getElementById('cardView');
    const rows = document.querySelectorAll('#itemsGrid tbody tr');

    if (rows.length === 0 || rows[0].textContent.includes('Nenhum anúncio encontrado')) {
        cardView.innerHTML = '<div class="col-12 text-center py-4 text-muted">Nenhum anúncio encontrado</div>';
        return;
    }

    const cards = Array.from(rows).map(row => {
        const checkbox = row.querySelector('input[type="checkbox"]');
        const itemId = row.querySelector('td[data-label="Anúncio"]').textContent.trim();
        const title = row.querySelector('.item-title')?.textContent || '';
        const thumbnail = row.querySelector('.item-thumbnail')?.getAttribute('data-src') || '';
        const status = row.querySelector('.badge')?.textContent || '';
        const statusClass = row.querySelector('.badge')?.className || '';
        const price = row.querySelector('td[data-label="Preço"]')?.textContent || '';
        const stock = row.querySelector('td[data-label="Estoque"]')?.textContent || '';
        const visits = row.querySelector('td[data-label="Visitas"]')?.textContent || '';
        const sold = row.querySelector('td[data-label="Vendas"]')?.textContent || '';

        return `
                <div class="col-md-6 col-lg-4 col-xl-3 mb-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="card-title text-truncate mb-0">${title}</h6>
                                <span class="badge ${statusClass}">${status}</span>
                            </div>
                            <div class="text-muted small mb-2">${itemId}</div>
                            <div class="row text-center">
                                <div class="col-6">
                                    <small class="text-muted">Preço</small>
                                    <div class="fw-bold text-primary">${price}</div>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Estoque</small>
                                    <div class="${stock.includes('text-danger') ? 'text-danger' : 'text-muted'}">${stock}</div>
                                </div>
                            </div>
                            <div class="row text-center">
                                <div class="col-6">
                                    <small class="text-muted">Visitas</small>
                                    <div>${visits}</div>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Vendas</small>
                                    <div>${sold}</div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <div class="btn-group w-100 btn-group-sm">
                                <button class="btn btn-outline-primary btn-sm" data-action="edit-item" data-item-id="${itemId}">Editar</button>
                                <button class="btn btn-outline-warning btn-sm" data-action="seo-item" data-item-id="${itemId}">SEO</button>
                                <button class="btn btn-outline-success btn-sm" data-action="view-item" data-href="${row.querySelector('a[href]')?.href || '#'}">Ver</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
    }).join('');

    cardView.innerHTML = cards;
}

// Event listeners para alternância de visualização
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('tableViewMode').addEventListener('change', () => switchViewMode('table'));
    document.getElementById('cardViewMode').addEventListener('change', () => switchViewMode('cards'));

    // Aplicar modo salvo
    switchViewMode(currentViewMode);
});

async function editItem(itemId) {
    try {
        const { data } = await ApiClient.json(`/api/items/${itemId}`);
        const item = data.item ?? data;

        if (!item || item.error) {
            throw new Error(item?.message || 'Erro ao carregar item');
        }

        document.getElementById('editItemId').value = itemId;
        document.getElementById('editTitle').value = item.title || '';
        document.getElementById('editSku').value = item.sku || '';
        document.getElementById('editPrice').value = item.price || 0;
        document.getElementById('editStock').value = item.available_quantity || 0;
        document.getElementById('editCost').value = item.cost_price || '';
        document.getElementById('editTax').value = item.tax_rate || '';

        document.getElementById('editStrategy').value = item.pricing_strategy || '';
        document.getElementById('editMinPrice').value = item.min_price || '';
        document.getElementById('editMaxPrice').value = item.max_price || '';
        document.getElementById('editAutoReprice').checked = item.auto_reprice == 1;
        document.getElementById('editAutoNegotiate').checked = item.auto_negotiate == 1;

        document.getElementById('titleCount').textContent = (item.title || '').length;

        new bootstrap.Modal(document.getElementById('editModal')).show();
    } catch (e) {
        alert('Erro ao carregar item');
    }
}

async function saveItem() {
    const itemId = document.getElementById('editItemId').value;
    const title = document.getElementById('editTitle').value;
    const sku = document.getElementById('editSku').value;
    const price = document.getElementById('editPrice').value;
    const stock = document.getElementById('editStock').value;
    const cost_price = document.getElementById('editCost').value;
    const tax_rate = document.getElementById('editTax').value;

    const pricing_strategy = document.getElementById('editStrategy').value;
    const min_price = document.getElementById('editMinPrice').value;
    const max_price = document.getElementById('editMaxPrice').value;
    const auto_reprice = document.getElementById('editAutoReprice').checked ? 1 : 0;
    const auto_negotiate = document.getElementById('editAutoNegotiate').checked ? 1 : 0;

    try {
        const { data } = await ApiClient.json(`/api/items/${itemId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                title, sku, price, available_quantity: stock, cost_price, tax_rate,
                pricing_strategy, min_price, max_price, auto_reprice, auto_negotiate
            }),
            credentials: 'include'
        });

        if (data.success || data.local_update) {
            bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
            loadItems();
        } else {
            alert('Erro: ' + (data.error || 'Erro ao salvar'));
        }
    } catch (e) {
        alert('Erro ao salvar item');
    }
}

async function pauseItem(itemId) {
    if (!confirm('Deseja pausar este anúncio?')) return;
    try {
        await ApiClient.fetch(`/api/items/${itemId}/pause`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            credentials: 'include'
        });
        loadItems();
    } catch (e) { alert('Erro'); }
}

async function activateItem(itemId) {
    try {
        await ApiClient.fetch(`/api/items/${itemId}/activate`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            credentials: 'include'
        });
        loadItems();
    } catch (e) {
        alert('Erro ao ativar anúncio');
    }
}

function getStatusClass(status) {
    const classes = {
        'active': 'bg-success',
        'paused': 'bg-warning',
        'closed': 'bg-secondary'
    };
    return classes[status] || 'bg-secondary';
}

function getStatusLabel(status) {
    const labels = {
        'active': 'Ativo',
        'paused': 'Pausado',
        'closed': 'Finalizado'
    };
    return labels[status] || status;
}

function formatCurrency(value) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(value);
}

function formatNumber(value) {
    return new Intl.NumberFormat('pt-BR').format(value);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function fixImageUrl(url) {
    if (!url) return '/images/no-image.png';
    // Convert HTTP to HTTPS to avoid mixed content issues
    if (url.startsWith('http://')) {
        return url.replace('http://', 'https://');
    }
    return url;
}

function normalizeExternalUrl(url) {
    if (!url || typeof url !== 'string') return '';
    const trimmed = url.trim();
    if (!trimmed) return '';
    if (trimmed.startsWith('data:') || trimmed.startsWith('blob:') || trimmed.startsWith('#')) return trimmed;
    if (trimmed.startsWith('//')) return `${window.location.protocol}${trimmed}`;
    if (trimmed.startsWith('http://')) return `https://${trimmed.slice('http://'.length)}`;
    return trimmed;
}

function initLazyLoading() {
    const images = document.querySelectorAll('.lazy-load');

    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    const src = img.getAttribute('data-src');
                    if (src) {
                        img.src = src;
                        img.removeAttribute('data-src');
                        observer.unobserve(img);
                    }
                }
            });
        });

        images.forEach(img => imageObserver.observe(img));
    } else {
        // Fallback for older browsers
        images.forEach(img => {
            const src = img.getAttribute('data-src');
            if (src) img.src = src;
        });
    }
}

// Funções faltantes - Sincronização
async function syncItems() {
    const tbody = document.querySelector('#itemsGrid tbody');
    const originalContent = tbody.innerHTML;

    tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Sincronizando itens...</td></tr>';

    try {
        const { data } = await ApiClient.json('/api/items/sync', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            credentials: 'include'
        });

        if (data.success) {
            // Recarregar dados após sincronização
            await Promise.all([loadStats(), loadCategories(), loadItems()]);

            // Show success message
            const announcer = document.getElementById('sidebar-announcer');
            if (announcer) {
                announcer.textContent = 'Itens sincronizados com sucesso!';
                setTimeout(() => announcer.textContent = '', 5000);
            }
        } else {
            tbody.innerHTML = originalContent;
            alert(data.message || 'Erro ao sincronizar itens');
        }
    } catch (e) {
        tbody.innerHTML = originalContent;
        console.error('Error syncing items:', e);
        alert('Erro ao sincronizar itens. Tente novamente.');
    }
}

// Funções de seleção em massa
function updateBulkSelection() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"][data-item-id]:checked');
    const selectedCount = checkboxes.length;

    document.getElementById('selectedCount').textContent = selectedCount;
    document.getElementById('bulkActionsBar').style.display = selectedCount > 0 ? 'flex' : 'none';
}

function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const itemCheckboxes = document.querySelectorAll('input[type="checkbox"][data-item-id]');

    itemCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });

    updateBulkSelection();
}

// Funções de ações em massa
async function bulkActivate() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"][data-item-id]:checked');
    const itemIds = Array.from(checkboxes).map(cb => cb.value);

    if (itemIds.length === 0) {
        alert('Selecione pelo menos um item para ativar.');
        return;
    }

    if (!confirm(`Ativar ${itemIds.length} itens selecionados?`)) {
        return;
    }

    try {
        const promises = itemIds.map(id =>
            requestJson(`/api/items/${id}/activate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                }
            })
        );

        await Promise.all(promises);

        // Recarregar lista
        await loadItems();

        // Limpar seleção
        document.getElementById('selectAll').checked = false;
        updateBulkSelection();

        alert(`${itemIds.length} itens ativados com sucesso!`);
    } catch (e) {
        console.error('Error bulk activating:', e);
        alert('Erro ao ativar itens. Tente novamente.');
    }
}

async function bulkPause() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"][data-item-id]:checked');
    const itemIds = Array.from(checkboxes).map(cb => cb.value);

    if (itemIds.length === 0) {
        alert('Selecione pelo menos um item para pausar.');
        return;
    }

    if (!confirm(`Pausar ${itemIds.length} itens selecionados?`)) {
        return;
    }

    try {
        const promises = itemIds.map(id =>
            requestJson(`/api/items/${id}/pause`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                }
            })
        );

        await Promise.all(promises);

        // Recarregar lista
        await loadItems();

        // Limpar seleção
        document.getElementById('selectAll').checked = false;
        updateBulkSelection();

        alert(`${itemIds.length} itens pausados com sucesso!`);
    } catch (e) {
        console.error('Error bulk pausing:', e);
        alert('Erro ao pausar itens. Tente novamente.');
    }
}

function clearFilters() {
    document.getElementById('statusFilter').value = '';
    document.getElementById('categoryFilter').value = '';
    document.getElementById('searchInput').value = '';
    currentPage = 1;
    loadItems();
}

// Bulk Operations
function updateBulkSelection() {
    const checkboxes = document.querySelectorAll('#itemsGrid input[type="checkbox"]:not(#selectAll)');
    const checkedBoxes = document.querySelectorAll('#itemsGrid input[type="checkbox"]:checked:not(#selectAll)');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCount = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAll');

    selectedCount.textContent = checkedBoxes.length;
    bulkActionsBar.style.display = checkedBoxes.length > 0 ? 'block' : 'none';

    // Update select all checkbox state
    selectAllCheckbox.checked = checkedBoxes.length === checkboxes.length && checkboxes.length > 0;
    selectAllCheckbox.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < checkboxes.length;

    // Visual feedback for selected rows
    checkboxes.forEach(checkbox => {
        const row = checkbox.closest('tr');
        if (checkbox.checked) {
            row.classList.add('selected');
        } else {
            row.classList.remove('selected');
        }
    });
}

function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('#itemsGrid input[type="checkbox"]:not(#selectAll)');

    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });

    updateBulkSelection();
}

async function bulkActivate() {
    const items = Array.from(document.querySelectorAll('#itemsGrid input[type="checkbox"]:checked:not(#selectAll)')).map(cb => cb.value);
    if (items.length === 0) return;

    if (!confirm(`Ativar ${items.length} anúncio(s)?`)) return;

    try {
        await Promise.all(items.map(itemId =>
            requestJson(`/api/items/${itemId}/activate`, { method: 'POST', headers: { 'X-CSRF-Token': csrfToken } })
        ));
        loadItems();
    } catch (e) { alert('Erro ao ativar anúncios'); }
}

async function bulkPause() {
    const items = Array.from(document.querySelectorAll('#itemsGrid input[type="checkbox"]:checked:not(#selectAll)')).map(cb => cb.value);
    if (items.length === 0) return;

    if (!confirm(`Pausar ${items.length} anúncio(s)?`)) return;

    try {
        await Promise.all(items.map(itemId =>
            requestJson(`/api/items/${itemId}/pause`, { method: 'POST', headers: { 'X-CSRF-Token': csrfToken } })
        ));
        loadItems();
    } catch (e) { alert('Erro ao pausar anúncios'); }
}

// ========================================
// EVENT DELEGATION FOR CSP COMPLIANCE
// ========================================
document.addEventListener('click', function (e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;

    e.preventDefault();
    const action = target.dataset.action;

    // Map data-action to function calls
    switch (action) {
        case 'sync-items':
            syncItems();
            break;
        case 'clear-filters':
            clearFilters();
            break;
        case 'load-items':
            loadItems();
            break;
        case 'export-items':
            exportItems();
            break;
        case 'filter-status':
            filterByStatus(target.dataset.status);
            break;
        case 'filter-low-stock':
            filterByLowStock();
            break;
        case 'filter-high-sales':
            filterByHighSales();
            break;
        case 'bulk-activate':
            bulkActivate();
            break;
        case 'bulk-pause':
            bulkPause();
            break;
        case 'clear-selection':
            document.getElementById('selectAll')?.click();
            break;
        case 'save-item':
            saveItem();
            break;
        case 'edit-item':
            editItem(target.dataset.itemId);
            break;
        case 'seo-item':
            window.open(`/seo?item=${target.dataset.itemId}`, '_blank');
            break;
        case 'pause-item':
            pauseItem(target.dataset.itemId);
            break;
        case 'activate-item':
            activateItem(target.dataset.itemId);
            break;
        case 'view-item':
            window.open(target.dataset.href, '_blank');
            break;
        case 'goto-page':
            goToPage(parseInt(target.dataset.page));
            break;
    }
});
