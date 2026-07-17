<?php

declare(strict_types=1);

$title = 'Clonador de Anúncios em Lote';
$subtitle = 'Clone anúncios de catálogo e não-catálogo entre contas';
$headerButtons = '
    <a href="/dashboard/catalog/clone-wizard" class="btn btn-outline-warning btn-sm">
        <i class="bi bi-magic"></i> Wizard Concorrente
    </a>
    <a href="/dashboard/catalog/clone-metrics" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-graph-up"></i> Métricas
    </a>
';
include __DIR__ . '/../layouts/modern/partials/page-header.php';
?>

<style>
.clone-container { display: flex; gap: 1rem; min-height: calc(100vh - 250px); }
.clone-column { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; display: flex; flex-direction: column; }
.clone-column.brands { flex: 0 0 260px; }
.clone-column.items { flex: 1; min-width: 0; }
.clone-column.selected { flex: 0 0 320px; }
.column-header { padding: 1rem; border-bottom: 1px solid #e9ecef; background: #f8f9fa; }
.column-header h6 { margin: 0; font-weight: 600; }
.column-content { flex: 1; overflow-y: auto; padding: 0; }
.facet-header { padding: 0.5rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.02em; color: #6c757d; background: #fff; border-bottom: 1px solid #f0f0f0; }
.brand-item { padding: 0.75rem 1rem; border-bottom: 1px solid #f0f0f0; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: background 0.15s; position: relative; }
.brand-item .select-all-btn { display: none; position: absolute; right: 50px; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; }
.brand-item:hover .select-all-btn { display: inline-block; }
.brand-item:hover { background: #f8f9fa; }
.brand-item.active { background: #e7f1ff; border-left: 3px solid #0d6efd; }
.brand-count { font-size: 0.75rem; background: #e9ecef; padding: 2px 8px; border-radius: 12px; }
.item-row { padding: 0.75rem 1rem; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; gap: 0.75rem; cursor: pointer; transition: background 0.15s; }
.item-row:hover { background: #f8f9fa; }
.item-row.selected { background: #e7f1ff; }
.item-thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 4px; flex-shrink: 0; }
.item-info { flex: 1; min-width: 0; }
.item-title { font-size: 0.875rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
.item-meta { font-size: 0.75rem; color: #6c757d; display: flex; gap: 0.5rem; flex-wrap: wrap; }
.badge-catalog { background: #198754; color: #fff; font-size: 0.65rem; padding: 2px 6px; border-radius: 3px; }
.badge-non-catalog { background: #ffc107; color: #000; font-size: 0.65rem; padding: 2px 6px; border-radius: 3px; }
.selected-item { padding: 0.5rem 0.75rem; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }
.selected-item .remove-btn { margin-left: auto; color: #dc3545; cursor: pointer; opacity: 0.7; }
.selected-item .remove-btn:hover { opacity: 1; }
.source-input-group { background: #fff; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.stats-row { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
.stat-badge { padding: 0.5rem 1rem; background: #f8f9fa; border-radius: 6px; text-align: center; flex: 1; }
.stat-badge .value { font-size: 1.25rem; font-weight: 600; }
.stat-badge .label { font-size: 0.75rem; color: #6c757d; }
.action-bar { padding: 1rem; border-top: 1px solid #e9ecef; background: #f8f9fa; }
.filter-bar { padding: 0.75rem 1rem; border-bottom: 1px solid #e9ecef; background: #fff; }
.search-input { border: 1px solid #dee2e6; border-radius: 6px; padding: 0.5rem 0.75rem; width: 100%; }
.loading-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.9); display: flex; align-items: center; justify-content: center; z-index: 10; }
.empty-state { padding: 2rem; text-align: center; color: #6c757d; }
.load-more-container { border-top: 1px dashed #dee2e6; margin-top: 0.5rem; }
.validation-warning { background: #fff3cd; border-left: 3px solid #ffc107; padding: 0.5rem; margin-top: 0.25rem; font-size: 0.75rem; }
.validation-error { background: #f8d7da; border-left: 3px solid #dc3545; padding: 0.5rem; margin-top: 0.25rem; font-size: 0.75rem; }
</style>

<!-- Source Input -->
<div class="source-input-group">
    <div class="alert alert-info mb-3" role="alert">
        <i class="bi bi-info-circle me-2"></i>
        <strong>Contas próprias:</strong> use Seller ID ou Conta Origem acima.
        <strong>Concorrentes:</strong> use o
        <a href="/dashboard/catalog/clone-wizard" class="alert-link fw-semibold">Wizard Concorrente</a>
        (filtros por categoria/marca, prévia de preço e clone em massa com modo seguro).
    </div>
    <div class="row align-items-end g-3">
        <div class="col-md-3">
            <label class="form-label fw-semibold">Origem</label>
            <select class="form-select" id="source-type">
                <option value="account" selected>Minha Conta</option>
                <option value="items">Lista de Item IDs</option>
                <option value="seller">Seller ID (Próprio)</option>
            </select>
        </div>
        <div class="col-md-4 d-none" id="seller-input-container">
            <label class="form-label">Seller ID do Mercado Livre</label>
            <div class="input-group">
                <input type="text" class="form-control" id="seller-id" placeholder="Ex: 123456789">
                <button class="btn btn-primary" type="button" id="btn-load-seller">
                    <i class="bi bi-search"></i> Carregar
                </button>
            </div>
            <small class="text-muted">Use o Seller ID de uma conta vinculada</small>
        </div>
        <div class="col-md-4 d-none" id="items-input-container">
            <label class="form-label">IDs dos Anúncios (separados por vírgula)</label>
            <div class="input-group">
                <input type="text" class="form-control" id="item-ids" placeholder="MLB123, MLB456, MLB789...">
                <button class="btn btn-primary" type="button" id="btn-load-items">
                    <i class="bi bi-search"></i> Carregar
                </button>
            </div>
        </div>
        <div class="col-md-4 d-none" id="account-input-container">
            <label class="form-label">Conta Origem</label>
            <select class="form-select" id="source-account">
                <option value="">Selecione uma conta...</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Conta Destino</label>
            <select class="form-select" id="target-account" required>
                <option value="">Selecione...</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">&nbsp;</label>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="show-catalog-only">
                <label class="form-check-label" for="show-catalog-only">Só Catálogo</label>
            </div>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-row" id="stats-row" style="display: none;">
    <div class="stat-badge">
        <div class="value" id="stat-total">0</div>
        <div class="label">Total</div>
    </div>
    <div class="stat-badge">
        <div class="value text-success" id="stat-catalog">0</div>
        <div class="label">Catálogo</div>
    </div>
    <div class="stat-badge">
        <div class="value text-warning" id="stat-non-catalog">0</div>
        <div class="label">Não-Catálogo</div>
    </div>
    <div class="stat-badge">
        <div class="value text-info" id="stat-brands">0</div>
        <div class="label">Marcas</div>
    </div>
    <div class="stat-badge">
        <div class="value text-secondary" id="stat-categories">0</div>
        <div class="label">Categorias</div>
    </div>
    <div class="stat-badge">
        <div class="value text-primary" id="stat-selected">0</div>
        <div class="label">Selecionados</div>
    </div>
</div>

<!-- 3-Column Layout -->
<div class="clone-container">
    <!-- Column 1: Brands/Facets -->
    <div class="clone-column brands">
        <div class="column-header">
            <h6><i class="bi bi-funnel"></i> Filtros</h6>
        </div>
        <div class="column-content">
            <div class="facet-header"><i class="bi bi-diagram-3"></i> Categorias</div>
            <div id="categories-list">
                <div class="empty-state">
                    <i class="bi bi-search fs-1 text-muted"></i>
                    <p class="mt-2 mb-0">Carregue um seller para ver categorias</p>
                </div>
            </div>
            <div class="facet-header"><i class="bi bi-tags"></i> Marcas</div>
            <div id="brands-list">
                <div class="empty-state">
                    <i class="bi bi-search fs-1 text-muted"></i>
                    <p class="mt-2 mb-0">Carregue um seller para ver marcas</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Column 2: Items List -->
    <div class="clone-column items" style="position: relative;">
        <div class="column-header d-flex justify-content-between align-items-center">
            <h6><i class="bi bi-box-seam"></i> Anúncios</h6>
            <button class="btn btn-sm btn-outline-primary" id="btn-select-all" disabled>
                <i class="bi bi-check-all"></i> Selecionar Todos
            </button>
        </div>
        <div class="filter-bar d-flex gap-2">
            <input type="text" class="search-input flex-grow-1" id="item-search" placeholder="Buscar por título ou ID...">
            <select class="form-select form-select-sm" id="filter-category" style="width: 160px;">
                <option value="">Todas Categorias</option>
            </select>
            <select class="form-select form-select-sm" id="filter-brand" style="width: 130px;">
                <option value="">Todas Marcas</option>
            </select>
        </div>
        <div class="column-content" id="items-list">
            <div class="empty-state">
                <i class="bi bi-inbox fs-1 text-muted"></i>
                <p class="mt-2 mb-0">Informe um Seller ID ou lista de IDs para começar</p>
            </div>
        </div>
        <div class="loading-overlay" id="items-loading" style="display: none;">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Carregando...</span>
            </div>
        </div>
    </div>

    <!-- Column 3: Selected Items -->
    <div class="clone-column selected">
        <div class="column-header d-flex justify-content-between align-items-center">
            <h6><i class="bi bi-cart-check"></i> Selecionados (<span id="selected-count">0</span>)</h6>
            <button class="btn btn-sm btn-outline-danger" id="btn-clear-selection" disabled>
                <i class="bi bi-x-lg"></i> Limpar
            </button>
        </div>
        <div class="column-content" id="selected-list">
            <div class="empty-state">
                <i class="bi bi-hand-index fs-1 text-muted"></i>
                <p class="mt-2 mb-0">Clique nos anúncios para selecionar</p>
            </div>
        </div>
        <div class="action-bar">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Template de Clonagem</label>
                <select class="form-select form-select-sm" id="clone-template">
                    <option value="">Sem template (manual)</option>
                </select>
                <small class="text-muted d-none" id="template-description"></small>
            </div>
            <div class="mb-3" id="manual-pricing-section">
                <label class="form-label small fw-semibold">Estratégia de Preço</label>
                <select class="form-select form-select-sm" id="price-strategy">
                    <option value="copy">Mesmo preço</option>
                    <option value="markup_percent">Aumentar %</option>
                    <option value="markdown_percent">Reduzir %</option>
                    <option value="competitive">Competitivo (IA)</option>
                </select>
            </div>
            <div class="mb-3 d-none" id="price-value-container">
                <input type="number" class="form-control form-control-sm" id="price-value" placeholder="%" min="0" max="100" step="0.1">
            </div>
            <div class="mb-3" id="manual-status-section">
                <label class="form-label small fw-semibold">Status Inicial</label>
                <select class="form-select form-select-sm" id="initial-status">
                    <option value="paused">Pausado (Recomendado)</option>
                    <option value="active">Ativo</option>
                </select>
            </div>

            <!-- Guardrails de Segurança -->
            <div class="mb-3">
                <label class="form-label small fw-semibold">Opções de Conteúdo</label>
                <div class="alert alert-warning py-2 px-3 small mb-2">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <strong>Atenção:</strong> Copiar imagens e descrições pode violar direitos autorais.
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="include-pictures">
                    <label class="form-check-label small" for="include-pictures">
                        Copiar imagens <span class="text-muted">(não recomendado)</span>
                    </label>
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="include-description">
                    <label class="form-check-label small" for="include-description">
                        Copiar descrição <span class="text-muted">(não recomendado)</span>
                    </label>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button class="btn btn-outline-primary" id="btn-price-preview" disabled>
                    <i class="bi bi-cash-coin"></i> Preview de Preços
                </button>
                <button class="btn btn-outline-secondary" id="btn-dry-run" disabled>
                    <i class="bi bi-eye"></i> Validar (Dry-run)
                </button>
                <button class="btn btn-primary" id="btn-clone" disabled>
                    <i class="bi bi-files"></i> Clonar Selecionados
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Dry-run Results Modal -->
<div class="modal fade" id="dryRunModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-check"></i> Resultado da Validação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="dry-run-results">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-primary" id="btn-proceed-clone">
                    <i class="bi bi-files"></i> Prosseguir com Clonagem
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Price Preview Modal -->
<div class="modal fade" id="pricePreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin"></i> Preview de Preços</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="price-preview-summary" class="mb-3"></div>
                <div id="price-preview-results"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Job Progress Modal -->
<div class="modal fade" id="jobModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-hourglass-split"></i> Clonagem em Andamento</h5>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <div class="spinner-border text-primary" role="status" id="job-spinner">
                        <span class="visually-hidden">Processando...</span>
                    </div>
                </div>
                <div class="progress mb-3" style="height: 25px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="job-progress" style="width: 0%">0%</div>
                </div>
                <div class="row text-center">
                    <div class="col-4">
                        <div class="fs-4 text-success fw-bold" id="job-success">0</div>
                        <small class="text-muted">Sucesso</small>
                    </div>
                    <div class="col-4">
                        <div class="fs-4 text-danger fw-bold" id="job-failed">0</div>
                        <small class="text-muted">Falhas</small>
                    </div>
                    <div class="col-4">
                        <div class="fs-4 text-warning fw-bold" id="job-skipped">0</div>
                        <small class="text-muted">Ignorados</small>
                    </div>
                </div>
                <div id="job-status-text" class="text-center mt-3 text-muted"></div>
            </div>
            <div class="modal-footer" id="job-footer" style="display: none;">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">

function normalizeExternalUrl(url) {
    if (!url || typeof url !== 'string') return '';
    const trimmed = url.trim();
    if (!trimmed) return '';
    if (/^(data:|blob:)/i.test(trimmed)) return trimmed;
    if (trimmed.startsWith('//')) return window.location.protocol + trimmed;
    if (/^http:\/\//i.test(trimmed)) return trimmed.replace(/^http:\/\//i, 'https://');
    return trimmed;
}

const CloneBatch = {
    items: [],
    selectedItems: new Map(),
    brands: {},
    categories: {},
    activeBrand: null,
    activeCategory: null,
    totalItems: 0,
    catalogCount: 0,
    nonCatalogCount: 0,
    currentJobId: null,
    templates: [],
    selectedTemplate: null,
    // Pagination state
    currentSellerId: null,
    currentOffset: 0,
    pageSize: 50,
    hasMore: false,
    isLoadingMore: false,

    init() {
        this.loadAccounts();
        this.loadTemplates();
        this.bindEvents();

        // Mostrar container de conta por padrão (source-type agora é 'account')
        document.getElementById('seller-input-container').classList.add('d-none');
        document.getElementById('items-input-container').classList.add('d-none');
        document.getElementById('account-input-container').classList.remove('d-none');
    },

    async loadTemplates() {
        try {
            const data = await requestJson('/api/catalog/clone/templates');

            if (data.status === 'success' && data.templates) {
                this.templates = data.templates;
                const select = document.getElementById('clone-template');

                data.templates.forEach(t => {
                    const option = document.createElement('option');
                    option.value = t.slug;
                    option.textContent = t.name + (t.is_system ? ' ⭐' : '');
                    option.dataset.description = t.description || '';
                    select.appendChild(option);
                });
            }
        } catch (err) {
            console.error('Erro ao carregar templates:', err);
        }
    },

    onTemplateChange(slug) {
        const descEl = document.getElementById('template-description');
        const manualPricing = document.getElementById('manual-pricing-section');
        const manualStatus = document.getElementById('manual-status-section');

        if (slug) {
            const template = this.templates.find(t => t.slug === slug);
            if (template) {
                this.selectedTemplate = template;
                descEl.textContent = template.description || '';
                descEl.classList.remove('d-none');
                // Hide manual options when template is selected
                manualPricing.classList.add('d-none');
                manualStatus.classList.add('d-none');
            }
        } else {
            this.selectedTemplate = null;
            descEl.classList.add('d-none');
            manualPricing.classList.remove('d-none');
            manualStatus.classList.remove('d-none');
        }
    },

    bindEvents() {
        // Template change
        document.getElementById('clone-template').addEventListener('change', (e) => {
            this.onTemplateChange(e.target.value);
        });

        // Source type toggle
        document.getElementById('source-type').addEventListener('change', (e) => {
            const type = e.target.value;
            document.getElementById('seller-input-container').classList.toggle('d-none', type !== 'seller');
            document.getElementById('items-input-container').classList.toggle('d-none', type !== 'items');
            document.getElementById('account-input-container').classList.toggle('d-none', type !== 'account');
        });

        // Load buttons
        document.getElementById('btn-load-seller').addEventListener('click', () => this.loadSeller());
        document.getElementById('btn-load-items').addEventListener('click', () => this.loadItemIds());

        // Enter key for inputs
        document.getElementById('seller-id').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.loadSeller();
        });
        document.getElementById('item-ids').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.loadItemIds();
        });

        // Search filter
        document.getElementById('item-search').addEventListener('input', (e) => this.filterItems(e.target.value));

        // Category filter select
        document.getElementById('filter-category').addEventListener('change', (e) => {
            this.activeCategory = e.target.value || null;
            // Sync side list
            const sidebarCategories = document.getElementById('categories-list');
            sidebarCategories.querySelectorAll('.brand-item').forEach(b => {
                b.classList.toggle('active', (b.dataset.category || '') === (this.activeCategory || ''));
            });
            this.renderItems();
        });

        // Brand filter select
        document.getElementById('filter-brand').addEventListener('change', (e) => {
            this.activeBrand = e.target.value || null;
            // Sync side list
            const sidebarBrands = document.getElementById('brands-list');
            sidebarBrands.querySelectorAll('.brand-item').forEach(b => {
                b.classList.toggle('active', (b.dataset.brand || '') === (this.activeBrand || ''));
            });
            this.renderItems();
        });

        // Catalog only filter
        document.getElementById('show-catalog-only').addEventListener('change', () => this.renderItems());

        // Select all
        document.getElementById('btn-select-all').addEventListener('click', () => this.selectAll());

        // Clear selection
        document.getElementById('btn-clear-selection').addEventListener('click', () => this.clearSelection());

        // Price strategy
        document.getElementById('price-strategy').addEventListener('change', (e) => {
            const showValue = ['markup_percent', 'markdown_percent'].includes(e.target.value);
            document.getElementById('price-value-container').classList.toggle('d-none', !showValue);
        });

        // Action buttons
        document.getElementById('btn-price-preview').addEventListener('click', () => this.pricePreview());
        document.getElementById('btn-dry-run').addEventListener('click', () => this.dryRun());
        document.getElementById('btn-clone').addEventListener('click', () => this.startClone());
        document.getElementById('btn-proceed-clone').addEventListener('click', () => {
            bootstrap.Modal.getInstance(document.getElementById('dryRunModal')).hide();
            this.startClone();
        });
    },

    async loadAccounts() {
        try {
const data = await requestJson('/api/auth/accounts');

            if (data.accounts) {
                const targetSelect = document.getElementById('target-account');
                const sourceSelect = document.getElementById('source-account');

                data.accounts.forEach(account => {
                    targetSelect.innerHTML += `<option value="${account.id}" data-seller-id="${account.ml_user_id}">${account.nickname}</option>`;
                    sourceSelect.innerHTML += `<option value="${account.id}" data-seller-id="${account.ml_user_id}">${account.nickname}</option>`;
                });

                // Adicionar evento de mudança para carregar itens da conta
                sourceSelect.addEventListener('change', (e) => {
                    const selected = e.target.options[e.target.selectedIndex];
                    const sellerId = selected?.dataset?.sellerId;
                    if (sellerId) {
                        this.loadAccountItems(sellerId);
                    }
                });
            }
        } catch (e) {
            console.error('Erro ao carregar contas:', e);
        }
    },

    async loadAccountItems(sellerId) {
        this.showLoading(true);
        this.items = [];
        this.brands = {};
        this.categories = {};
        this.selectedItems.clear();
        this.activeBrand = null;
        this.activeCategory = null;
        this.totalItems = 0;
        this.catalogCount = 0;
        this.nonCatalogCount = 0;
        this.currentSellerId = sellerId;
        this.currentOffset = 0;
        this.hasMore = false;

        try {
            // Load summary first
            const summary = await requestJson(`/api/catalog/clone/source/seller/${sellerId}/summary`);

            if (summary.status === 'success') {
                this.brands = summary.brands || {};
                this.categories = summary.categories || {};
                this.totalItems = summary.total_items || 0;
                this.catalogCount = summary.catalog_count || 0;
                this.nonCatalogCount = summary.non_catalog_count || 0;
                this.updateStats({
                    total: summary.total_items,
                    catalog: this.catalogCount,
                    nonCatalog: this.nonCatalogCount,
                    brands: Object.keys(this.brands).length,
                    categories: Object.keys(this.categories).length
                });
                this.renderCategories();
                this.renderBrands();
            }

            // Load items
            const itemsData = await requestJson(`/api/catalog/clone/source/seller/${sellerId}/items?limit=${this.pageSize}&offset=0`);

            if (itemsData.status === 'success') {
                this.items = itemsData.items || [];
                this.currentOffset = this.items.length;
                this.hasMore = this.items.length < this.totalItems;
                if (itemsData.facets?.categories) {
                    this.categories = itemsData.facets.categories;
                    this.renderCategories();
                }
                this.renderItems();
            } else if (itemsData.error) {
                alert(itemsData.message || itemsData.error);
            }

            document.getElementById('stats-row').style.display = 'flex';
            document.getElementById('btn-select-all').disabled = this.items.length === 0;

        } catch (e) {
            console.error('Erro ao carregar conta:', e);
            alert('Erro ao carregar dados da conta');
        } finally {
            this.showLoading(false);
        }
    },

    async loadSeller() {
        const sellerId = document.getElementById('seller-id').value.trim();
        if (!sellerId) {
            alert('Informe o Seller ID');
            return;
        }

        this.showLoading(true);
        this.items = [];
        this.brands = {};
        this.categories = {};
        this.selectedItems.clear();
        this.activeBrand = null;
        this.activeCategory = null;
        this.totalItems = 0;
        this.catalogCount = 0;
        this.nonCatalogCount = 0;
        this.currentSellerId = sellerId;
        this.currentOffset = 0;
        this.hasMore = false;

        try {
            // Load summary first
            const summary = await requestJson(`/api/catalog/clone/source/seller/${sellerId}/summary`);

            if (summary.status === 'success') {
                this.brands = summary.brands || {};
                this.categories = summary.categories || {};
                this.totalItems = summary.total_items || 0;
                this.catalogCount = summary.catalog_count || 0;
                this.nonCatalogCount = summary.non_catalog_count || 0;
                this.updateStats({
                    total: summary.total_items,
                    catalog: this.catalogCount,
                    nonCatalog: this.nonCatalogCount,
                    brands: Object.keys(this.brands).length,
                    categories: Object.keys(this.categories).length
                });
                this.renderCategories();
                this.renderBrands();
            }

            // Load items
            const itemsData = await requestJson(`/api/catalog/clone/source/seller/${sellerId}/items?limit=${this.pageSize}&offset=0`);

            if (itemsData.status === 'success') {
                this.items = itemsData.items || [];
                this.currentOffset = this.items.length;
                this.hasMore = this.items.length < this.totalItems;
                // Preferir facets do endpoint de items, quando existir
                if (itemsData.facets?.categories) {
                    this.categories = itemsData.facets.categories;
                    this.updateStats({
                        total: this.totalItems,
                        catalog: this.catalogCount,
                        nonCatalog: this.nonCatalogCount,
                        brands: Object.keys(this.brands).length,
                        categories: Object.keys(this.categories).length
                    });
                    this.renderCategories();
                }
                this.renderItems();
            }

            document.getElementById('stats-row').style.display = 'flex';
            document.getElementById('btn-select-all').disabled = this.items.length === 0;

        } catch (e) {
            console.error('Erro ao carregar seller:', e);
            alert('Erro ao carregar dados do seller');
        } finally {
            this.showLoading(false);
        }
    },

    async loadItemIds() {
        const input = document.getElementById('item-ids').value.trim();
        if (!input) {
            alert('Informe os IDs dos anúncios');
            return;
        }

        const itemIds = input.split(',').map(id => id.trim()).filter(id => id);
        if (itemIds.length === 0) {
            alert('Nenhum ID válido informado');
            return;
        }

        this.showLoading(true);
        this.items = [];
        this.brands = {};
        this.categories = {};
        this.selectedItems.clear();
        this.activeBrand = null;
        this.activeCategory = null;
        this.totalItems = 0;

        try {
            const data = await requestJson('/api/catalog/clone/source/items', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ item_ids: itemIds })
            });

            if (data.status === 'success') {
                this.items = data.items.filter(item => !item.error);
                this.brands = data.facets?.brands || {};
                this.categories = data.facets?.categories || {};
                this.totalItems = data.total || 0;

                this.updateStats({
                    total: data.total,
                    catalog: data.summary?.catalog || 0,
                    nonCatalog: data.summary?.non_catalog || 0,
                    brands: Object.keys(this.brands).length,
                    categories: Object.keys(this.categories).length
                });

                this.renderCategories();
                this.renderBrands();
                this.renderItems();
            }

            document.getElementById('stats-row').style.display = 'flex';
            document.getElementById('btn-select-all').disabled = this.items.length === 0;

        } catch (e) {
            console.error('Erro ao carregar itens:', e);
            alert('Erro ao carregar itens');
        } finally {
            this.showLoading(false);
        }
    },

    updateStats(stats) {
        document.getElementById('stat-total').textContent = stats.total || 0;
        document.getElementById('stat-catalog').textContent = stats.catalog || 0;
        document.getElementById('stat-non-catalog').textContent = stats.nonCatalog || 0;
        document.getElementById('stat-brands').textContent = stats.brands || 0;
        document.getElementById('stat-categories').textContent = stats.categories || 0;
    },

    renderCategories() {
        const container = document.getElementById('categories-list');
        const filterSelect = document.getElementById('filter-category');

        if (!this.categories || Object.keys(this.categories).length === 0) {
            container.innerHTML = '<div class="empty-state"><p class="mb-0 text-muted">Nenhuma categoria encontrada</p></div>';
            filterSelect.innerHTML = '<option value="">Todas Categorias</option>';
            return;
        }

        const totalLabel = this.totalItems || this.items.length;

        let html = `
            <div class="brand-item ${!this.activeCategory ? 'active' : ''}" data-category="">
                <span>Todas as categorias</span>
                <span class="brand-count">${totalLabel}</span>
            </div>
        `;

        let selectHtml = '<option value="">Todas Categorias</option>';

        for (const [categoryId, data] of Object.entries(this.categories)) {
            const name = (data && typeof data === 'object') ? (data.name || categoryId) : String(categoryId);
            const count = (data && typeof data === 'object') ? (data.count || 0) : 0;
            html += `
                <div class="brand-item ${this.activeCategory === categoryId ? 'active' : ''}" data-category="${categoryId}">
                    <span title="${categoryId}">${name}</span>
                    <button type="button" class="btn btn-outline-primary btn-sm select-all-btn" data-select-category="${categoryId}" title="Selecionar todos desta categoria"><i class="bi bi-check-all"></i></button>
                    <span class="brand-count">${count}</span>
                </div>
            `;
            selectHtml += `<option value="${categoryId}" ${this.activeCategory === categoryId ? 'selected' : ''}>${name} (${count})</option>`;
        }

        container.innerHTML = html;
        filterSelect.innerHTML = selectHtml;

        container.querySelectorAll('.brand-item').forEach(el => {
            el.addEventListener('click', (e) => {
                if (e.target.closest('.select-all-btn')) return;
                this.activeCategory = el.dataset.category || null;
                container.querySelectorAll('.brand-item').forEach(b => b.classList.remove('active'));
                el.classList.add('active');

                // Sync top select
                filterSelect.value = this.activeCategory || '';

                this.renderItems();
            });
        });

        container.querySelectorAll('.select-all-btn[data-select-category]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const categoryId = btn.dataset.selectCategory;
                this.selectByCategory(categoryId);
            });
        });
    },

    selectByCategory(categoryId) {
        const itemsInCategory = this.items.filter(item => item.category_id === categoryId);
        itemsInCategory.forEach(item => {
            this.selectedItems.set(item.id, item);
        });
        this.renderItems();
        this.updateSelectedUI();
    },

    renderBrands() {
        const container = document.getElementById('brands-list');
        const filterSelect = document.getElementById('filter-brand');

        if (Object.keys(this.brands).length === 0) {
            container.innerHTML = '<div class="empty-state"><p class="mb-0 text-muted">Nenhuma marca encontrada</p></div>';
            filterSelect.innerHTML = '<option value="">Todas Marcas</option>';
            return;
        }

        let html = `
            <div class="brand-item ${!this.activeBrand ? 'active' : ''}" data-brand="">
                <span>Todas as marcas</span>
                <span class="brand-count">${this.items.length}</span>
            </div>
        `;

        let selectHtml = '<option value="">Todas Marcas</option>';

        for (const [brand, count] of Object.entries(this.brands)) {
            html += `
                <div class="brand-item ${this.activeBrand === brand ? 'active' : ''}" data-brand="${brand}">
                    <span>${brand}</span>
                    <button type="button" class="btn btn-outline-primary btn-sm select-all-btn" data-select-brand="${brand}" title="Selecionar todos desta marca"><i class="bi bi-check-all"></i></button>
                    <span class="brand-count">${count}</span>
                </div>
            `;
            selectHtml += `<option value="${brand}" ${this.activeBrand === brand ? 'selected' : ''}>${brand} (${count})</option>`;
        }

        container.innerHTML = html;
        filterSelect.innerHTML = selectHtml;

        // Bind click events
        container.querySelectorAll('.brand-item').forEach(el => {
            el.addEventListener('click', (e) => {
                if (e.target.closest('.select-all-btn')) return;
                this.activeBrand = el.dataset.brand || null;
                container.querySelectorAll('.brand-item').forEach(b => b.classList.remove('active'));
                el.classList.add('active');

                // Sync top select
                filterSelect.value = this.activeBrand || '';

                this.renderItems();
            });
        });

        container.querySelectorAll('.select-all-btn[data-select-brand]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const brand = btn.dataset.selectBrand;
                this.selectByBrand(brand);
            });
        });
    },

    selectByBrand(brand) {
        const itemsInBrand = this.items.filter(item => item.brand === brand);
        itemsInBrand.forEach(item => {
            this.selectedItems.set(item.id, item);
        });
        this.renderItems();
        this.updateSelectedUI();
    },

    renderItems() {
        const container = document.getElementById('items-list');
        const searchTerm = document.getElementById('item-search').value.toLowerCase();
        const catalogOnly = document.getElementById('show-catalog-only').checked;

        let filteredItems = this.items;

        // Filter by category
        if (this.activeCategory) {
            filteredItems = filteredItems.filter(item => item.category_id === this.activeCategory);
        }

        // Filter by brand
        if (this.activeBrand) {
            filteredItems = filteredItems.filter(item => item.brand === this.activeBrand);
        }

        // Filter by catalog
        if (catalogOnly) {
            filteredItems = filteredItems.filter(item => item.is_catalog);
        }

        // Filter by search
        if (searchTerm) {
            filteredItems = filteredItems.filter(item =>
                item.title.toLowerCase().includes(searchTerm) ||
                item.id.toLowerCase().includes(searchTerm)
            );
        }

        if (filteredItems.length === 0) {
            container.innerHTML = '<div class="empty-state"><p class="mb-0">Nenhum anúncio encontrado</p></div>';
            return;
        }

        let html = '';
        for (const item of filteredItems) {
            const isSelected = this.selectedItems.has(item.id);
            const catalogBadge = item.is_catalog
                ? '<span class="badge-catalog">Catálogo</span>'
                : '<span class="badge-non-catalog">Não-Catálogo</span>';

            html += `
                <div class="item-row ${isSelected ? 'selected' : ''}" data-id="${item.id}">
                    <input type="checkbox" class="form-check-input" ${isSelected ? 'checked' : ''}>
                    <img src="${normalizeExternalUrl(item.thumbnail) || ''}" class="item-thumb" alt="">
                    <div class="item-info">
                        <div class="item-title">${item.title}</div>
                        <div class="item-meta">
                            <span>${item.id}</span>
                            ${catalogBadge}
                            <span>R$ ${item.price?.toFixed(2) || '0.00'}</span>
                            ${item.brand ? `<span class="text-primary">${item.brand}</span>` : ''}
                        </div>
                    </div>
                </div>
            `;
        }

        // Add "Load More" button if there are more items
        if (this.hasMore && !this.activeCategory && !this.activeBrand && !searchTerm && !catalogOnly) {
            html += `
                <div class="load-more-container text-center py-3">
                    <button type="button" class="btn btn-outline-primary" id="load-more-btn" ${this.isLoadingMore ? 'disabled' : ''}>
                        ${this.isLoadingMore
                            ? '<span class="spinner-border spinner-border-sm me-2"></span>Carregando...'
                            : `<i class="bi bi-arrow-down-circle me-2"></i>Carregar Mais (${this.items.length} de ${this.totalItems})`}
                    </button>
                </div>
            `;
        }

        container.innerHTML = html;

        // Bind click events
        container.querySelectorAll('.item-row').forEach(el => {
            el.addEventListener('click', (e) => {
                if (e.target.type !== 'checkbox') {
                    const checkbox = el.querySelector('input[type="checkbox"]');
                    checkbox.checked = !checkbox.checked;
                }
                this.toggleItem(el.dataset.id);
            });
        });

        // Bind load more button
        const loadMoreBtn = document.getElementById('load-more-btn');
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', () => this.loadMore());
        }
    },

    async loadMore() {
        if (this.isLoadingMore || !this.hasMore || !this.currentSellerId) return;

        this.isLoadingMore = true;
        this.renderItems(); // Re-render to show loading state

        try {
            const data = await requestJson(`/api/catalog/clone/source/seller/${this.currentSellerId}/items?limit=${this.pageSize}&offset=${this.currentOffset}`);

            // Append new items
            this.items = [...this.items, ...data.items];
            this.currentOffset += data.items.length;
            this.hasMore = this.items.length < this.totalItems;

            this.renderItems();
        } catch (error) {
            console.error('Load more error:', error);
            alert('Erro ao carregar mais itens: ' + error.message);
        } finally {
            this.isLoadingMore = false;
        }
    },

    toggleItem(itemId) {
        const item = this.items.find(i => i.id === itemId);
        if (!item) return;

        if (this.selectedItems.has(itemId)) {
            this.selectedItems.delete(itemId);
        } else {
            this.selectedItems.set(itemId, item);
        }

        this.updateSelectedUI();
    },

    selectAll() {
        const searchTerm = document.getElementById('item-search').value.toLowerCase();
        const catalogOnly = document.getElementById('show-catalog-only').checked;

        let filteredItems = this.items;

        if (this.activeCategory) {
            filteredItems = filteredItems.filter(item => item.category_id === this.activeCategory);
        }

        if (this.activeBrand) {
            filteredItems = filteredItems.filter(item => item.brand === this.activeBrand);
        }
        if (catalogOnly) {
            filteredItems = filteredItems.filter(item => item.is_catalog);
        }
        if (searchTerm) {
            filteredItems = filteredItems.filter(item =>
                item.title.toLowerCase().includes(searchTerm) ||
                item.id.toLowerCase().includes(searchTerm)
            );
        }

        filteredItems.forEach(item => {
            this.selectedItems.set(item.id, item);
        });

        this.renderItems();
        this.updateSelectedUI();
    },

    clearSelection() {
        this.selectedItems.clear();
        this.renderItems();
        this.updateSelectedUI();
    },

    updateSelectedUI() {
        const container = document.getElementById('selected-list');
        const count = this.selectedItems.size;

        document.getElementById('selected-count').textContent = count;
        document.getElementById('stat-selected').textContent = count;
        document.getElementById('btn-clear-selection').disabled = count === 0;
        document.getElementById('btn-price-preview').disabled = count === 0;
        document.getElementById('btn-dry-run').disabled = count === 0;
        document.getElementById('btn-clone').disabled = count === 0;

        if (count === 0) {
            container.innerHTML = '<div class="empty-state"><i class="bi bi-hand-index fs-1 text-muted"></i><p class="mt-2 mb-0">Clique nos anúncios para selecionar</p></div>';
            return;
        }

        let html = '';
        for (const [id, item] of this.selectedItems) {
            const badge = item.is_catalog ? 'C' : 'NC';
            const badgeClass = item.is_catalog ? 'bg-success' : 'bg-warning text-dark';

            html += `
                <div class="selected-item" data-id="${id}">
                    <span class="badge ${badgeClass}" style="font-size: 0.65rem;">${badge}</span>
                    <span class="text-truncate" style="max-width: 200px;" title="${item.title}">${item.title}</span>
                    <i class="bi bi-x-circle remove-btn"></i>
                </div>
            `;
        }

        container.innerHTML = html;

        // Bind remove events
        container.querySelectorAll('.remove-btn').forEach(el => {
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = el.closest('.selected-item').dataset.id;
                this.selectedItems.delete(id);
                this.renderItems();
                this.updateSelectedUI();
            });
        });
    },

    filterItems(term) {
        this.renderItems();
    },

    showLoading(show) {
        document.getElementById('items-loading').style.display = show ? 'flex' : 'none';
    },

    async dryRun() {
        const targetAccountId = document.getElementById('target-account').value;
        if (!targetAccountId) {
            alert('Selecione a conta destino');
            return;
        }

        const itemIds = Array.from(this.selectedItems.keys());
        if (itemIds.length === 0) {
            alert('Selecione pelo menos um anúncio');
            return;
        }

        this.showLoading(true);

        try {
            const data = await requestJson('/api/catalog/clone/dry-run', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    item_ids: itemIds,
                    target_account_id: targetAccountId
                })
            });

            this.showDryRunResults(data);

        } catch (e) {
            console.error('Erro no dry-run:', e);
            alert('Erro ao executar validação');
        } finally {
            this.showLoading(false);
        }
    },

    async pricePreview() {
        const targetAccountId = document.getElementById('target-account').value;
        if (!targetAccountId) {
            alert('Selecione a conta destino');
            return;
        }

        const itemIds = Array.from(this.selectedItems.keys());
        if (itemIds.length === 0) {
            alert('Selecione pelo menos um anúncio');
            return;
        }

        const priceStrategy = document.getElementById('price-strategy').value;
        const priceValue = document.getElementById('price-value').value;

        const pricingStrategy = { type: priceStrategy };
        if (['markup_percent', 'markdown_percent'].includes(priceStrategy) && priceValue) {
            pricingStrategy.value = parseFloat(priceValue);
        }

        this.showLoading(true);

        try {
            const data = await requestJson('/api/catalog/clone/price-preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    item_ids: itemIds,
                    target_account_id: targetAccountId,
                    pricing_strategy: pricingStrategy
                })
            });
            this.showPricePreviewResults(data);

        } catch (e) {
            console.error('Erro no preview de preços:', e);
            alert('Erro ao gerar preview de preços');
        } finally {
            this.showLoading(false);
        }
    },

    showPricePreviewResults(data) {
        const summaryEl = document.getElementById('price-preview-summary');
        const resultsEl = document.getElementById('price-preview-results');

        if (!data || data.status !== 'success') {
            summaryEl.innerHTML = `<div class="alert alert-danger">Falha ao gerar preview: ${(data && (data.message || data.error)) || 'Erro desconhecido'}</div>`;
            resultsEl.innerHTML = '';
            new bootstrap.Modal(document.getElementById('pricePreviewModal')).show();
            return;
        }

        const summary = data.summary || {};
        const totalDelta = summary.total_delta ?? 0;
        const deltaClass = totalDelta > 0 ? 'text-danger' : (totalDelta < 0 ? 'text-success' : 'text-muted');

        summaryEl.innerHTML = `
            <div class="alert alert-light border">
                <div class="d-flex flex-wrap gap-3">
                    <div><strong>Itens:</strong> ${summary.items_simulated || 0}/${summary.items_requested || 0}</div>
                    <div><strong>Duplicados:</strong> ${summary.duplicates || 0}</div>
                    <div><strong>Erros:</strong> ${summary.errors || 0}</div>
                    <div><strong>Total Orig.:</strong> R$ ${(summary.total_original ?? 0).toFixed ? (summary.total_original).toFixed(2) : summary.total_original}</div>
                    <div><strong>Total Final:</strong> R$ ${(summary.total_final ?? 0).toFixed ? (summary.total_final).toFixed(2) : summary.total_final}</div>
                    <div><strong>Delta:</strong> <span class="${deltaClass}">R$ ${(summary.total_delta ?? 0).toFixed ? (summary.total_delta).toFixed(2) : summary.total_delta}</span></div>
                </div>
            </div>
        `;

        const rows = (data.results || []).map(r => {
            if (r.status !== 'success') {
                return `
                    <tr>
                        <td><code>${r.id}</code></td>
                        <td colspan="5"><span class="text-danger">${r.message || 'Erro'}</span></td>
                    </tr>
                `;
            }

            const dupBadge = r.is_duplicate
                ? `<span class="badge bg-warning text-dark" title="${r.duplicate_reason || ''}">Duplicado</span>`
                : `<span class="badge bg-success">OK</span>`;

            const d = r.delta ?? 0;
            const dClass = d > 0 ? 'text-danger' : (d < 0 ? 'text-success' : 'text-muted');

            return `
                <tr>
                    <td>
                        <div class="fw-semibold" style="max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${r.title || ''}">${r.title || r.id}</div>
                        <small class="text-muted"><code>${r.id}</code></small>
                    </td>
                    <td>${dupBadge}</td>
                    <td>R$ ${(r.original_price ?? 0).toFixed(2)}</td>
                    <td>R$ ${(r.final_price ?? 0).toFixed(2)}</td>
                    <td class="${dClass}">R$ ${(r.delta ?? 0).toFixed(2)}</td>
                    <td><small class="text-muted">${r.strategy_applied || ''}</small></td>
                </tr>
            `;
        }).join('');

        resultsEl.innerHTML = `
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Status</th>
                            <th>Original</th>
                            <th>Final</th>
                            <th>Delta</th>
                            <th>Estratégia</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows || '<tr><td colspan="6" class="text-muted">Sem resultados</td></tr>'}
                    </tbody>
                </table>
            </div>
        `;

        new bootstrap.Modal(document.getElementById('pricePreviewModal')).show();
    },

    showDryRunResults(data) {
        const container = document.getElementById('dry-run-results');
        const results = data.results || [];
        const summary = data.summary || {};

        let html = `
            <div class="alert ${summary.invalid > 0 ? 'alert-warning' : 'alert-success'}">
                <strong>Resumo:</strong> ${summary.valid} válidos, ${summary.invalid} com problemas de ${summary.total} itens
            </div>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Tipo</th>
                            <th>Status</th>
                            <th>Detalhes</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        for (const result of results) {
            const statusBadge = result.can_clone
                ? '<span class="badge bg-success">OK</span>'
                : '<span class="badge bg-danger">Bloqueado</span>';

            const typeBadge = result.is_catalog
                ? '<span class="badge bg-success">Catálogo</span>'
                : '<span class="badge bg-warning text-dark">Não-Cat.</span>';

            let details = '';
            if (result.errors?.length > 0) {
                details += result.errors.map(e => `<div class="validation-error"><i class="bi bi-x-circle"></i> ${e}</div>`).join('');
            }
            if (result.warnings?.length > 0) {
                details += result.warnings.map(w => `<div class="validation-warning"><i class="bi bi-exclamation-triangle"></i> ${w}</div>`).join('');
            }

            html += `
                <tr>
                    <td>
                        <div class="fw-semibold" style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${result.title}">${result.title || result.id}</div>
                        <small class="text-muted">${result.id}</small>
                    </td>
                    <td>${typeBadge}</td>
                    <td>${statusBadge}</td>
                    <td>${details || '<span class="text-muted">-</span>'}</td>
                </tr>
            `;
        }

        html += '</tbody></table></div>';
        container.innerHTML = html;

        document.getElementById('btn-proceed-clone').disabled = summary.valid === 0;

        new bootstrap.Modal(document.getElementById('dryRunModal')).show();
    },

    async startClone() {
        const targetAccountId = document.getElementById('target-account').value;
        if (!targetAccountId) {
            alert('Selecione a conta destino');
            return;
        }

        const itemIds = Array.from(this.selectedItems.keys());
        if (itemIds.length === 0) {
            alert('Selecione pelo menos um anúncio');
            return;
        }

        const templateSlug = document.getElementById('clone-template').value;
        const priceStrategy = document.getElementById('price-strategy').value;
        const priceValue = document.getElementById('price-value').value;
        const startPaused = document.getElementById('initial-status').value === 'paused';
        const includePictures = document.getElementById('include-pictures').checked;
        const includeDescription = document.getElementById('include-description').checked;

        // Confirmar se vai copiar conteúdo protegido
        if (includePictures || includeDescription) {
            const confirmMsg = 'ATENÇÃO: Você optou por copiar ' +
                (includePictures && includeDescription ? 'imagens e descrição' :
                 includePictures ? 'imagens' : 'descrição') +
                ' do anúncio original.\n\n' +
                'Isso pode violar direitos autorais e as políticas do Mercado Livre.\n\n' +
                'Tem certeza que deseja continuar?';

            if (!confirm(confirmMsg)) {
                return;
            }
        }

        const options = {
            start_paused: startPaused,
            pricing_strategy: { type: priceStrategy },
            include_pictures: includePictures,
            include_description: includeDescription
        };

        if (['markup_percent', 'markdown_percent'].includes(priceStrategy) && priceValue) {
            options.pricing_strategy.value = parseFloat(priceValue);
        }

        // Build request body
        const requestBody = {
            item_ids: itemIds,
            target_account_id: targetAccountId,
            source_type: 'item_ids',
            options: options
        };

        // Add template if selected
        if (templateSlug) {
            requestBody.template_slug = templateSlug;
        }

        try {
            const data = await requestJson('/api/catalog/clone/jobs', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody)
            });

            if (data.status === 'created' && data.job_id) {
                this.currentJobId = data.job_id;
                this.showJobProgress();
                this.pollJobStatus();
            } else {
                alert('Erro ao criar job de clonagem: ' + (data.message || 'Erro desconhecido'));
            }

        } catch (e) {
            console.error('Erro ao iniciar clonagem:', e);
            alert('Erro ao iniciar clonagem');
        }
    },

    showJobProgress() {
        document.getElementById('job-progress').style.width = '0%';
        document.getElementById('job-progress').textContent = '0%';
        document.getElementById('job-success').textContent = '0';
        document.getElementById('job-failed').textContent = '0';
        document.getElementById('job-skipped').textContent = '0';
        document.getElementById('job-spinner').style.display = 'block';
        document.getElementById('job-footer').style.display = 'none';
        document.getElementById('job-status-text').textContent = 'Processando...';

        new bootstrap.Modal(document.getElementById('jobModal')).show();
    },

    async pollJobStatus() {
        if (!this.currentJobId) return;

        try {
            const data = await requestJson(`/api/catalog/clone/jobs/${this.currentJobId}/status`);

            if (data.status === 'success' && data.job) {
                const job = data.job;
                const progress = job.progress_percent || 0;

                document.getElementById('job-progress').style.width = `${progress}%`;
                document.getElementById('job-progress').textContent = `${progress}%`;
                document.getElementById('job-success').textContent = job.successful_items || 0;
                document.getElementById('job-failed').textContent = job.failed_items || 0;
                document.getElementById('job-skipped').textContent = job.skipped_items || 0;

                if (['completed', 'failed'].includes(job.status)) {
                    document.getElementById('job-spinner').style.display = 'none';
                    document.getElementById('job-footer').style.display = 'block';
                    document.getElementById('job-status-text').textContent =
                        job.status === 'completed' ? 'Concluído!' : 'Finalizado com erros';

                    // Clear selection on completion
                    this.clearSelection();
                } else {
                    // Continue polling
                    setTimeout(() => this.pollJobStatus(), 2000);
                }
            }

        } catch (e) {
            console.error('Erro ao verificar status:', e);
            setTimeout(() => this.pollJobStatus(), 5000);
        }
    }
};

document.addEventListener('DOMContentLoaded', () => CloneBatch.init());
</script>
