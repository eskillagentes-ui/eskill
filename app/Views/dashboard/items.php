<!-- Dashboard Items View -->
<?php


$title = 'Meus Anúncios';
$subtitle = 'Gerencie todos os seus anúncios no Mercado Livre';
$breadcrumbs = [['label' => 'Anúncios', 'url' => '']];
$actions = '
    <a href="/dashboard/seo-killer" class="btn btn-warning btn-sm text-white">
        <i class="bi bi-fire"></i> SEO Killer
    </a>
    <button class="btn btn-primary btn-sm" data-action="sync-items">
        <i class="bi bi-arrow-clockwise"></i> Sincronizar
    </button>
';
include __DIR__ . '/../layouts/modern/partials/page-header.php';
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .stat-card {
        background: var(--bg-card);
        border-radius: 0.5rem;
        padding: 1rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        transition: transform 0.2s;
    }

    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        color: white;
        flex-shrink: 0;
    }

    .stat-icon.success {
        background: var(--success-color);
    }

    .stat-icon.warning {
        background: var(--warning-color);
    }

    .stat-icon.danger {
        background: var(--danger-color);
    }

    .stat-icon.info {
        background: var(--info-color);
    }

    .stat-icon.primary {
        background: var(--primary-color);
    }

    .stat-info h3 {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
        color: var(--text-main);
    }

    .stat-info p {
        margin: 0.25rem 0 0 0;
        color: var(--text-muted);
        font-size: 0.875rem;
    }

    .item-row {
        transition: background-color 0.2s;
    }

    .item-row:hover {
        background-color: var(--bg-surface-alt);
    }

    .item-row.selected {
        background-color: rgba(90, 14, 176, 0.05);
        border-left: 3px solid var(--primary-color);
    }

    .item-thumbnail {
        width: 60px;
        height: 60px;
        object-fit: contain;
        border-radius: 0.375rem;
        background: #f8f9fa;
    }

    .clickable-stat {
        transition: all 0.2s ease;
        border: 2px solid #e9ecef;
        border-radius: 0.5rem;
    }

    .clickable-stat:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        border-color: var(--primary-color);
    }

    .clickable-stat.active {
        border-color: var(--primary-color);
        background: rgba(90, 14, 176, 0.05);
    }

    .item-title {
        max-width: 300px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: block;
    }

    .table-responsive {
        min-height: 400px;
    }

    @media (max-width: 768px) {
        .table-responsive {
            font-size: 0.875rem;
        }

        .item-thumbnail {
            width: 40px;
            height: 40px;
        }

        .item-title {
            max-width: 200px;
        }

        .table th:nth-child(4),
        .table th:nth-child(5),
        .table th:nth-child(6),
        .table th:nth-child(7),
        .table th:nth-child(8) {
            display: none;
        }

        .table td:nth-child(4),
        .table td:nth-child(5),
        .table td:nth-child(6),
        .table td:nth-child(7),
        .table td:nth-child(8) {
            display: none;
        }
    }

    @media (max-width: 576px) {
        .table-responsive {
            border: none;
        }

        .table thead {
            display: none;
        }

        .table,
        .table tbody,
        .table tr,
        .table td {
            display: block;
            width: 100% !important;
        }

        .table tr {
            margin-bottom: 1rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 0.75rem;
            background: var(--bg-card);
        }

        .table td {
            text-align: right !important;
            padding: 0.5rem 0 !important;
            border: none !important;
            position: relative;
            padding-left: 40% !important;
        }

        .table td:before {
            content: attr(data-label);
            position: absolute;
            left: 0.75rem;
            width: 35%;
            padding-right: 0.75rem;
            text-align: left !important;
            font-weight: 600;
            color: var(--text-muted);
            font-size: 0.75rem;
        }

        .table td:nth-child(1) {
            padding-left: 0.75rem !important;
            text-align: left !important;
            padding-top: 1rem !important;
        }

        .table td:nth-child(1):before {
            content: "";
            display: none;
        }

        .table td:nth-child(2) {
            padding-top: 0.5rem !important;
        }

        .table td:nth-child(2):before {
            content: "Anúncio";
        }

        .table td:nth-child(3):before {
            content: "SKU";
        }

        .table td:nth-child(4):before {
            content: "Status";
        }

        .table td:nth-child(5):before {
            content: "Preço";
        }

        .table td:nth-child(6):before {
            content: "Estoque";
        }

        .table td:nth-child(7):before {
            content: "Visitas";
        }

        .table td:nth-child(8):before {
            content: "Vendas";
        }

        .table td:nth-child(9):before {
            content: "Ações";
        }

        .item-thumbnail {
            width: 50px;
            height: 50px;
            margin-bottom: 0.5rem;
        }

        .item-title {
            max-width: 100%;
            white-space: normal;
            line-height: 1.3;
            font-size: 0.9rem;
        }

        .btn-group {
            flex-direction: column;
            gap: 0.25rem;
        }

        .btn-group .btn {
            width: 100%;
            justify-content: center;
        }

        .item-row.selected {
            background-color: transparent;
            border-left: 3px solid var(--primary-color);
        }
    }

    .lazy-load {
        opacity: 0;
        transition: opacity 0.3s;
    }

    .lazy-load.loaded {
        opacity: 1;
    }

    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
        }

        .stat-card {
            padding: 0.75rem;
            gap: 0.5rem;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            font-size: 1rem;
        }

        .stat-info h3 {
            font-size: 1.25rem;
        }

        .btn-group .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }
    }

    @media (max-width: 576px) {
        .stats-grid {
            grid-template-columns: 1fr 1fr;
        }

        .col-md-6.col-lg-4.col-xl-3 {
            flex: 0 0 50%;
            max-width: 50%;
        }
    }

    .skeleton {
        background: #f0f0f0;
        border-radius: 4px;
        margin-bottom: 8px;
    }

    .skeleton-card {
        border: 1px solid var(--border-color);
        border-radius: 0.5rem;
        padding: 1rem;
    }

    .skeleton-line {
        height: 16px;
    }

    .skeleton-image {
        height: 150px;
        border-radius: 0.5rem;
    }

    .table {
        --bs-table-striped-bg: transparent;
        --bs-table-hover-bg: var(--bg-surface-alt);
    }

    .table th {
        font-weight: 600;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid var(--border-color);
    }

    .table td {
        vertical-align: middle;
        padding: 0.75rem;
        border-bottom: 1px solid var(--border-color);
    }

    .item-row.selected td {
        background-color: rgba(90, 14, 176, 0.05);
    }

    .item-row.selected td:first-child {
        border-left: 3px solid var(--primary-color);
    }
</style>

<!-- Filters -->
<div class="row g-2 mb-3">
    <div class="col-sm-6 col-md-3">
        <select class="form-select form-select-sm" id="statusFilter">
            <option value="">Todos Status</option>
            <option value="active">Ativos</option>
            <option value="paused">Pausados</option>
            <option value="closed">Finalizados</option>
        </select>
    </div>
    <div class="col-sm-6 col-md-4">
        <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Buscar...">
    </div>
    <div class="col-md-3">
        <select class="form-select form-select-sm" id="categoryFilter">
            <option value="">Todas Categorias</option>
        </select>
    </div>
    <div class="col-md-2">
        <select class="form-select form-select-sm" id="orderFilter">
            <option value="">Ordenar por</option>
            <option value="date_created_desc">Mais Recentes</option>
            <option value="date_created_asc">Mais Antigos</option>
            <option value="price_asc">Menor Preço</option>
            <option value="price_desc">Maior Preço</option>
            <option value="title_asc">Título A-Z</option>
            <option value="title_desc">Título Z-A</option>
        </select>
    </div>
    <div class="col-md-2">
        <div class="btn-group w-100 btn-group-sm">
            <button class="btn btn-outline-secondary" data-action="clear-filters">Limpar</button>
            <button class="btn btn-primary" data-action="load-items">Filtrar</button>
            <button class="btn btn-success" data-action="export-items" title="Exportar itens">
                <i class="bi bi-download"></i>
            </button>
        </div>
    </div>
</div>

<!-- View Mode Toggle -->
<div class="row mb-3">
    <div class="col-12">
        <div class="btn-group" role="group">
            <input type="radio" class="btn-check" name="viewMode" id="tableViewMode" autocomplete="off" checked>
            <label class="btn btn-outline-primary" for="tableViewMode">
                <i class="bi bi-table"></i> Tabela
            </label>
            <input type="radio" class="btn-check" name="viewMode" id="cardViewMode" autocomplete="off">
            <label class="btn btn-outline-primary" for="cardViewMode">
                <i class="bi bi-grid-3x3-gap"></i> Cards
            </label>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-2">
        <div class="card text-center p-2 clickable-stat" data-action="filter-status" data-status="active" style="cursor: pointer;" title="Clique para filtrar ativos">
            <div class="text-success fw-bold" id="activeCount">-</div>
            <small class="text-muted">Ativos</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center p-2 clickable-stat" data-action="filter-status" data-status="paused" style="cursor: pointer;" title="Clique para filtrar pausados">
            <div class="text-warning fw-bold" id="pausedCount">-</div>
            <small class="text-muted">Pausados</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center p-2 clickable-stat" data-action="filter-status" data-status="closed" style="cursor: pointer;" title="Clique para filtrar finalizados">
            <div class="text-danger fw-bold" id="closedCount">-</div>
            <small class="text-muted">Finalizados</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center p-2 clickable-stat" data-action="filter-low-stock" style="cursor: pointer;" title="Clique para filtrar estoque baixo">
            <div class="text-info fw-bold" id="totalViews">-</div>
            <small class="text-muted">Visitas</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center p-2 clickable-stat" data-action="filter-high-sales" style="cursor: pointer;" title="Clique para filtrar mais vendidos">
            <div class="text-primary fw-bold" id="totalSold">-</div>
            <small class="text-muted">Vendas</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center p-2 clickable-stat" data-action="filter-low-stock" style="cursor: pointer;" title="Clique para filtrar estoque baixo">
            <div class="text-secondary fw-bold" id="lowStockCount">-</div>
            <small class="text-muted">Estoque Baixo</small>
        </div>
    </div>
</div>

<!-- Bulk Actions Bar -->
<div class="alert alert-info py-2 mb-3" id="bulkActionsBar" style="display: none;">
    <div class="d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <span class="badge bg-primary me-2" id="selectedCount">0</span>
            <span>selecionados</span>
        </div>
        <div class="btn-group btn-group-sm">
            <button class="btn btn-sm btn-success" data-action="bulk-activate" title="Ativar selecionados">
                <i class="bi bi-play"></i>
            </button>
            <button class="btn btn-sm btn-warning" data-action="bulk-pause" title="Pausar selecionados">
                <i class="bi bi-pause"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary" data-action="clear-selection" title="Limpar seleção">
                <i class="bi bi-x"></i>
            </button>
        </div>
    </div>
</div>

<!-- Items List -->
<div class="card">
    <!-- Table View -->
    <div id="tableView" class="table-responsive" style="display: block;">
        <table class="table table-hover align-middle" id="itemsGrid">
            <thead class="table-light">
                <tr>
                    <th width="40">
                        <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll()">
                    </th>
                    <th>Anúncio</th>
                    <th>SKU</th>
                    <th>Status</th>
                    <th>Preço</th>
                    <th>Estoque</th>
                    <th>Visitas</th>
                    <th>Vendas</th>
                    <th width="120">Ações</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="9" class="text-center py-4">
                        <div class="spinner-border spinner-border-sm text-primary"></div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Card View -->
    <div id="cardView" class="row g-3" style="display: none;">
        <!-- Cards will be dynamically inserted here -->
    </div>
</div>

<!-- Pagination -->
<div class="d-flex justify-content-between align-items-center mt-4">
    <div class="text-muted small" id="itemsCount">0 anúncios</div>
    <nav aria-label="Paginação">
        <ul class="pagination pagination-sm mb-0" id="pagination"></ul>
    </nav>
    <div class="text-muted small" id="showingInfo">Página 1</div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Editar Anúncio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editItemId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Título</label>
                        <input type="text" class="form-control" id="editTitle" maxlength="60">
                        <div class="form-text"><span id="titleCount">0</span>/60 caracteres</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">SKU (Identificador Único)</label>
                        <input type="text" class="form-control" id="editSku" placeholder="Ex: IPHONE-13-128-BLK">
                        <div class="form-text text-muted">Use o mesmo SKU em anúncios diferentes para sincronizar o estoque.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Preço (R$)</label>
                        <input type="number" class="form-control" id="editPrice" step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Estoque</label>
                        <input type="number" class="form-control" id="editStock" min="0">
                    </div>
                </div>

                <ul class="nav nav-tabs mt-3 mb-3" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#financials-tab">Custos e Taxas</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#repricing-tab">Smart Pricing 🤖</a>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="financials-tab">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Custo do Produto (R$)</label>
                                <input type="number" class="form-control" id="editCost" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Taxa Imposto (%)</label>
                                <input type="number" class="form-control" id="editTax" step="0.01" placeholder="0.00">
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="repricing-tab">
                        <div class="alert alert-info py-2 small">
                            <i class="bi bi-info-circle"></i> O sistema ajustará o preço automaticamente dentro dos limites definidos.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Estratégia</label>
                            <select class="form-select" id="editStrategy">
                                <option value="">-- Manual --</option>
                                <option value="aggressive">Agressiva (Menor preço)</option>
                                <option value="competitive">Competitiva (Preço médio)</option>
                                <option value="premium">Premium (Acima da média)</option>
                            </select>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Preço Mínimo (R$)</label>
                                <input type="number" class="form-control" id="editMinPrice" step="0.01">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Preço Máximo (R$)</label>
                                <input type="number" class="form-control" id="editMaxPrice" step="0.01">
                            </div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="editAutoReprice">
                            <label class="form-check-label" for="editAutoReprice">Ativar Reprificação Automática</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="editAutoNegotiate">
                            <label class="form-check-label" for="editAutoNegotiate">Ativar Negociação Automática (DealMaker 🤖)</label>
                            <div class="form-text small">O sistema aceitará ofertas acima do Preço Mínimo nas perguntas.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" data-action="save-item">
                    <i class="bi bi-check me-1"></i>Salvar
                </button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= defined('CSP_NONCE') ? CSP_NONCE : '' ?>" src="/js/items-dashboard.js?v=<?= @filemtime(__DIR__ . '/../../../public/js/items-dashboard.js') ?: time() ?>"></script>

<style>
    /* Custom styles removed - using standardized theme.css classes */
</style>
