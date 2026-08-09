<?php

declare(strict_types=1);

/**
 * @deprecated Use /dashboard/seo-killer instead. This view is no longer routed directly.
 * SEO Dashboard - Interface Moderna para Otimização com IA
 * Design profissional integrado ao layout do sistema
 */
$pageTitle = 'SEO com Inteligência Artificial';
$activePage = 'seo';
$useModernLayout = true;
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1 fw-bold">
            <i class="bi bi-stars text-primary me-2"></i>SEO com IA
        </h1>
        <p class="text-muted mb-0">Otimize seus produtos com Inteligência Artificial</p>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div id="statusBadge" class="seo-status-badge">
            <div class="status-dot"></div>
            <span>Verificando...</span>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-robot"></i>
                    </div>
                    <div class="ms-3">
                        <h6 class="text-muted mb-1 small text-uppercase">Provider IA</h6>
                        <h4 class="mb-0 fw-bold" id="providerName">-</h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="ms-3">
                        <h6 class="text-muted mb-1 small text-uppercase">Análises Hoje</h6>
                        <h4 class="mb-0 fw-bold" id="analysisCount">0</h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-lightning-charge"></i>
                    </div>
                    <div class="ms-3">
                        <h6 class="text-muted mb-1 small text-uppercase">Score Médio</h6>
                        <h4 class="mb-0 fw-bold" id="avgScore">-</h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <div class="ms-3">
                        <h6 class="text-muted mb-1 small text-uppercase">Otimizações</h6>
                        <h4 class="mb-0 fw-bold" id="optimizationCount">0</h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Main Content Area -->
    <div class="col-lg-8">
        <!-- Feature Cards Grid -->
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100 feature-card" data-feature="analyze">
                    <div class="card-body p-4">
                        <div class="feature-icon-wrapper bg-primary bg-opacity-10 mb-3">
                            <i class="bi bi-search text-primary"></i>
                        </div>
                        <h5 class="fw-bold mb-2">Analisar SEO</h5>
                        <p class="text-muted small mb-3">Análise completa de otimização do seu produto para melhor posicionamento.</p>
                        <button class="btn btn-primary btn-sm px-4" onclick="openModal('analyze')">
                            <i class="bi bi-arrow-right me-1"></i>Iniciar
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100 feature-card" data-feature="title">
                    <div class="card-body p-4">
                        <div class="feature-icon-wrapper bg-success bg-opacity-10 mb-3">
                            <i class="bi bi-type text-success"></i>
                        </div>
                        <h5 class="fw-bold mb-2">Otimizar Título</h5>
                        <p class="text-muted small mb-3">Gere títulos otimizados com palavras-chave de alta conversão.</p>
                        <button class="btn btn-success btn-sm px-4" onclick="openModal('title')">
                            <i class="bi bi-arrow-right me-1"></i>Iniciar
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100 feature-card" data-feature="description">
                    <div class="card-body p-4">
                        <div class="feature-icon-wrapper bg-warning bg-opacity-10 mb-3">
                            <i class="bi bi-file-earmark-text text-warning"></i>
                        </div>
                        <h5 class="fw-bold mb-2">Gerar Descrição</h5>
                        <p class="text-muted small mb-3">Crie descrições persuasivas e otimizadas para SEO automaticamente.</p>
                        <button class="btn btn-warning btn-sm px-4 text-dark" onclick="openModal('description')">
                            <i class="bi bi-arrow-right me-1"></i>Iniciar
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100 feature-card" data-feature="keywords">
                    <div class="card-body p-4">
                        <div class="feature-icon-wrapper bg-info bg-opacity-10 mb-3">
                            <i class="bi bi-key text-info"></i>
                        </div>
                        <h5 class="fw-bold mb-2">Pesquisar Keywords</h5>
                        <p class="text-muted small mb-3">Descubra as melhores palavras-chave para seu produto.</p>
                        <button class="btn btn-info btn-sm px-4 text-white" onclick="openModal('keywords')">
                            <i class="bi bi-arrow-right me-1"></i>Iniciar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tech Sheet Card (Full Width) -->
        <div class="card border-0 shadow-sm feature-card-lg mb-4" data-feature="techsheet">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center mb-3">
                            <div class="feature-icon-wrapper bg-danger bg-opacity-10 me-3">
                                <i class="bi bi-list-check text-danger"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0">Ficha Técnica com IA</h5>
                                <span class="badge bg-danger bg-opacity-10 text-danger mt-1">PRO</span>
                            </div>
                        </div>
                        <p class="text-muted mb-0">Gere fichas técnicas completas automaticamente com base no título do produto. A IA identifica e extrai todos os atributos relevantes.</p>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <button class="btn btn-danger px-4" onclick="openModal('techsheet')">
                            <i class="bi bi-magic me-2"></i>Gerar Ficha Técnica
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results Area -->
        <div class="card border-0 shadow-sm" id="resultsCard" style="display: none;">
            <div class="card-header bg-transparent border-0 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-bar-chart me-2 text-primary"></i>
                        <span id="resultsTitle">Resultados</span>
                    </h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary" onclick="copyResults()">
                            <i class="bi bi-clipboard"></i>
                        </button>
                        <button class="btn btn-outline-secondary" onclick="downloadResults()">
                            <i class="bi bi-download"></i>
                        </button>
                        <button class="btn btn-outline-secondary" onclick="closeResults()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body" id="resultsContent">
                <!-- Results will be injected here -->
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <!-- Quick Actions -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-0 py-3">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-lightning-charge me-2 text-warning"></i>Ações Rápidas
                </h6>
            </div>
            <div class="card-body pt-0">
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-primary btn-sm text-start" onclick="openModal('analyze')">
                        <i class="bi bi-search me-2"></i>Nova Análise SEO
                    </button>
                    <button class="btn btn-outline-success btn-sm text-start" onclick="openModal('title')">
                        <i class="bi bi-type me-2"></i>Otimizar Título
                    </button>
                    <button class="btn btn-outline-warning btn-sm text-start" onclick="openModal('description')">
                        <i class="bi bi-file-text me-2"></i>Gerar Descrição
                    </button>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-0 py-3">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-clock-history me-2 text-info"></i>Atividade Recente
                </h6>
            </div>
            <div class="card-body pt-0" id="recentActivity">
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-1 opacity-50 d-block mb-2"></i>
                    <span class="small">Nenhuma atividade ainda</span>
                </div>
            </div>
        </div>

        <!-- Tips Card -->
        <div class="card border-0 shadow-sm bg-gradient-primary text-white">
            <div class="card-body p-4">
                <div class="d-flex align-items-start">
                    <div class="flex-shrink-0">
                        <i class="bi bi-lightbulb fs-3"></i>
                    </div>
                    <div class="ms-3">
                        <h6 class="fw-bold mb-2">Dica do dia</h6>
                        <p class="small mb-0 opacity-90" id="tipText">
                            Títulos com 60-80 caracteres têm melhor desempenho no Mercado Livre. Inclua a marca e principais características.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Analyze SEO -->
<div class="modal fade" id="analyzeModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <div class="d-flex align-items-center">
                    <div class="modal-icon bg-primary bg-opacity-10 text-primary me-3">
                        <i class="bi bi-search"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Análise de SEO</h5>
                        <p class="text-muted small mb-0">Análise completa do seu produto</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <form id="analyzeForm" onsubmit="submitAnalyze(event)">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Título do Produto <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg" name="title" required
                               placeholder="Ex: Notebook Dell Inspiron 15 i7 16GB SSD 512GB">
                        <div class="form-text">O título principal do seu anúncio</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Categoria</label>
                            <input type="text" class="form-control" name="category" placeholder="Ex: Notebooks">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Preço (R$)</label>
                            <input type="number" class="form-control" name="price" step="0.01" placeholder="Ex: 3999.00">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Descrição atual</label>
                        <textarea class="form-control" name="description" rows="3" placeholder="Descrição atual do produto (opcional)"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" form="analyzeForm" class="btn btn-primary px-4">
                    <span class="btn-text"><i class="bi bi-search me-2"></i>Analisar</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Analisando...
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Optimize Title -->
<div class="modal fade" id="titleModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <div class="d-flex align-items-center">
                    <div class="modal-icon bg-success bg-opacity-10 text-success me-3">
                        <i class="bi bi-type"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Otimizar Título</h5>
                        <p class="text-muted small mb-0">Gere títulos otimizados com IA</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <form id="titleForm" onsubmit="submitTitle(event)">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Título Atual <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg" name="title" required
                               placeholder="Digite o título atual do produto">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Categoria</label>
                            <input type="text" class="form-control" name="category" placeholder="Ex: Eletrônicos">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Marca</label>
                            <input type="text" class="form-control" name="brand" placeholder="Ex: Dell">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Palavras-chave adicionais</label>
                        <input type="text" class="form-control" name="keywords" placeholder="Ex: gamer, profissional, ssd">
                        <div class="form-text">Separe por vírgula</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" form="titleForm" class="btn btn-success px-4">
                    <span class="btn-text"><i class="bi bi-magic me-2"></i>Otimizar</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Otimizando...
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Generate Description -->
<div class="modal fade" id="descriptionModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <div class="d-flex align-items-center">
                    <div class="modal-icon bg-warning bg-opacity-10 text-warning me-3">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Gerar Descrição</h5>
                        <p class="text-muted small mb-0">Crie descrições persuasivas</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <form id="descriptionForm" onsubmit="submitDescription(event)">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Título do Produto <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg" name="title" required
                               placeholder="Ex: Fone de Ouvido Bluetooth JBL">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Categoria</label>
                            <input type="text" class="form-control" name="category" placeholder="Ex: Áudio">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Marca</label>
                            <input type="text" class="form-control" name="brand" placeholder="Ex: JBL">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Características</label>
                        <textarea class="form-control" name="features" rows="4"
                                  placeholder="Liste as características principais (uma por linha):&#10;Bluetooth 5.0&#10;Bateria 20h&#10;Cancelamento de ruído"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" form="descriptionForm" class="btn btn-warning px-4 text-dark">
                    <span class="btn-text"><i class="bi bi-pencil me-2"></i>Gerar</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Gerando...
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Keywords Research -->
<div class="modal fade" id="keywordsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <div class="d-flex align-items-center">
                    <div class="modal-icon bg-info bg-opacity-10 text-info me-3">
                        <i class="bi bi-key"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Pesquisa de Keywords</h5>
                        <p class="text-muted small mb-0">Descubra as melhores palavras-chave</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <form id="keywordsForm" onsubmit="submitKeywords(event)">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Produto/Termo de Busca <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg" name="product" required
                               placeholder="Ex: Notebook Dell i7">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Categoria</label>
                        <input type="text" class="form-control" name="category" placeholder="Ex: Notebooks">
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" form="keywordsForm" class="btn btn-info px-4 text-white">
                    <span class="btn-text"><i class="bi bi-search me-2"></i>Pesquisar</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Pesquisando...
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Tech Sheet -->
<div class="modal fade" id="techsheetModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <div class="d-flex align-items-center">
                    <div class="modal-icon bg-danger bg-opacity-10 text-danger me-3">
                        <i class="bi bi-list-check"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Ficha Técnica com IA</h5>
                        <p class="text-muted small mb-0">Gere atributos automaticamente</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <form id="techsheetForm" onsubmit="submitTechSheet(event)">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Título do Produto <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg" name="title" required
                               placeholder="Ex: Smartphone Samsung Galaxy S24 256GB 8GB RAM">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Categoria</label>
                            <input type="text" class="form-control" name="category" placeholder="Ex: Celulares">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Marca</label>
                            <input type="text" class="form-control" name="brand" placeholder="Ex: Samsung">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" form="techsheetForm" class="btn btn-danger px-4">
                    <span class="btn-text"><i class="bi bi-magic me-2"></i>Gerar Ficha</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Gerando...
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Status Badge */
.seo-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 50px;
    background: var(--bs-light);
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.3s ease;
}

.seo-status-badge .status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #94a3b8;
    animation: pulse 2s infinite;
}

.seo-status-badge.operational {
    background: rgba(34, 197, 94, 0.1);
    color: #16a34a;
}

.seo-status-badge.operational .status-dot {
    background: #22c55e;
}

.seo-status-badge.error {
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
}

.seo-status-badge.error .status-dot {
    background: #ef4444;
    animation: none;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Stat Cards */
.stat-card {
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 40px rgba(0,0,0,0.1) !important;
}

.stat-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

/* Feature Cards */
.feature-card {
    transition: all 0.3s ease;
    cursor: pointer;
    overflow: hidden;
    position: relative;
}

.feature-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, transparent, transparent);
    transition: all 0.3s ease;
}

.feature-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 40px rgba(0,0,0,0.1) !important;
}

.feature-card[data-feature="analyze"]:hover::before { background: linear-gradient(90deg, #3b82f6, #6366f1); }
.feature-card[data-feature="title"]:hover::before { background: linear-gradient(90deg, #22c55e, #10b981); }
.feature-card[data-feature="description"]:hover::before { background: linear-gradient(90deg, #f59e0b, #eab308); }
.feature-card[data-feature="keywords"]:hover::before { background: linear-gradient(90deg, #06b6d4, #0ea5e9); }
.feature-card[data-feature="techsheet"]:hover::before { background: linear-gradient(90deg, #ef4444, #f43f5e); }

.feature-icon-wrapper {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.feature-card-lg {
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.feature-card-lg::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: transparent;
    transition: all 0.3s ease;
}

.feature-card-lg:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 40px rgba(0,0,0,0.1) !important;
}

.feature-card-lg:hover::before {
    background: linear-gradient(90deg, #ef4444, #f43f5e);
}

/* Modal Icons */
.modal-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

/* Results Card */
#resultsCard {
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Score Display */
.score-circle {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    position: relative;
}

.score-circle .score-value {
    font-size: 2rem;
    line-height: 1;
}

.score-circle .score-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    opacity: 0.8;
}

.score-good {
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(34, 197, 94, 0.1));
    color: #16a34a;
    border: 2px solid #22c55e;
}

.score-medium {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(245, 158, 11, 0.1));
    color: #d97706;
    border: 2px solid #f59e0b;
}

.score-bad {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.1));
    color: #dc2626;
    border: 2px solid #ef4444;
}

/* Activity Item */
.activity-item {
    display: flex;
    align-items: flex-start;
    padding: 12px 0;
    border-bottom: 1px solid var(--bs-border-color);
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
    flex-shrink: 0;
}

/* Results Styling */
.result-item {
    background: var(--bs-light);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 12px;
    transition: all 0.2s ease;
}

.result-item:hover {
    background: var(--bs-gray-200);
}

.keyword-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    border-radius: 50px;
    font-size: 0.875rem;
    font-weight: 500;
    margin: 4px;
    transition: all 0.2s ease;
}

.keyword-badge:hover {
    transform: scale(1.05);
}

.keyword-primary {
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    color: white;
}

.keyword-secondary {
    background: var(--bs-gray-200);
    color: var(--bs-gray-700);
}

/* Gradient Background */
.bg-gradient-primary {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%) !important;
}

/* Loading State */
.btn-loading {
    pointer-events: none;
}

/* Attribute Table */
.attribute-table {
    border-radius: 12px;
    overflow: hidden;
}

.attribute-table th {
    background: var(--bs-primary);
    color: white;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
}

.attribute-table td {
    vertical-align: middle;
}

/* Dark mode adjustments */
[data-theme="dark"] .seo-status-badge {
    background: rgba(255,255,255,0.1);
}

[data-theme="dark"] .seo-status-badge.operational {
    background: rgba(34, 197, 94, 0.2);
}

[data-theme="dark"] .result-item {
    background: rgba(255,255,255,0.05);
}

[data-theme="dark"] .result-item:hover {
    background: rgba(255,255,255,0.1);
}

[data-theme="dark"] .keyword-secondary {
    background: rgba(255,255,255,0.1);
    color: var(--bs-gray-300);
}
</style>

<script nonce="<?= CSP_NONCE ?>">
// API Base URL
const API_BASE = '/api/seo';

// Stats tracking
let stats = {
    analysisCount: 0,
    optimizationCount: 0,
    totalScore: 0,
    scoreCount: 0
};

// Activity history
let activityHistory = [];

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    checkStatus();
    loadStats();
    loadActivity();
    rotateTips();
});

// Check API Status
async function checkStatus() {
    const badge = document.getElementById('statusBadge');

    try {
        const response = await fetch(`${API_BASE}/status`);
        const data = await response.json();

        if (data.success && data.status === 'operational') {
            badge.className = 'seo-status-badge operational';
            badge.innerHTML = '<div class="status-dot"></div><span>Operacional</span>';
            document.getElementById('providerName').textContent = data.ai.provider || 'IA';
        } else {
            badge.className = 'seo-status-badge error';
            badge.innerHTML = '<div class="status-dot"></div><span>Indisponível</span>';
        }
    } catch (error) {
        badge.className = 'seo-status-badge error';
        badge.innerHTML = '<div class="status-dot"></div><span>Erro</span>';
    }
}

// Load Stats from localStorage
function loadStats() {
    const saved = localStorage.getItem('seoStats');
    if (saved) {
        const parsed = JSON.parse(saved);
        // Reset if different day
        if (parsed.date !== new Date().toDateString()) {
            stats = { analysisCount: 0, optimizationCount: 0, totalScore: 0, scoreCount: 0 };
        } else {
            stats = parsed;
        }
    }
    updateStatsDisplay();
}

// Save Stats
function saveStats() {
    stats.date = new Date().toDateString();
    localStorage.setItem('seoStats', JSON.stringify(stats));
    updateStatsDisplay();
}

// Update Stats Display
function updateStatsDisplay() {
    document.getElementById('analysisCount').textContent = stats.analysisCount;
    document.getElementById('optimizationCount').textContent = stats.optimizationCount;
    document.getElementById('avgScore').textContent = stats.scoreCount > 0
        ? Math.round(stats.totalScore / stats.scoreCount)
        : '-';
}

// Load Activity
function loadActivity() {
    const saved = localStorage.getItem('seoActivity');
    if (saved) {
        activityHistory = JSON.parse(saved);
    }
    updateActivityDisplay();
}

// Save Activity
function addActivity(type, title, score = null) {
    activityHistory.unshift({
        type,
        title: title.substring(0, 40) + (title.length > 40 ? '...' : ''),
        score,
        time: new Date().toISOString()
    });

    // Keep only last 10
    activityHistory = activityHistory.slice(0, 10);
    localStorage.setItem('seoActivity', JSON.stringify(activityHistory));
    updateActivityDisplay();
}

// Update Activity Display
function updateActivityDisplay() {
    const container = document.getElementById('recentActivity');

    if (activityHistory.length === 0) {
        container.innerHTML = `
            <div class="text-center py-4 text-muted">
                <i class="bi bi-inbox fs-1 opacity-50 d-block mb-2"></i>
                <span class="small">Nenhuma atividade ainda</span>
            </div>
        `;
        return;
    }

    const icons = {
        analyze: { icon: 'bi-search', color: 'primary' },
        title: { icon: 'bi-type', color: 'success' },
        description: { icon: 'bi-file-text', color: 'warning' },
        keywords: { icon: 'bi-key', color: 'info' },
        techsheet: { icon: 'bi-list-check', color: 'danger' }
    };

    container.innerHTML = activityHistory.map(item => {
        const config = icons[item.type] || icons.analyze;
        const timeAgo = getTimeAgo(new Date(item.time));

        return `
            <div class="activity-item">
                <div class="activity-icon bg-${config.color} bg-opacity-10 text-${config.color} me-3">
                    <i class="bi ${config.icon}"></i>
                </div>
                <div class="flex-grow-1 min-width-0">
                    <div class="small fw-medium text-truncate">${item.title}</div>
                    <div class="text-muted" style="font-size: 0.75rem;">${timeAgo}</div>
                </div>
                ${item.score ? `<span class="badge bg-${item.score >= 70 ? 'success' : item.score >= 50 ? 'warning' : 'danger'}">${item.score}</span>` : ''}
            </div>
        `;
    }).join('');
}

// Time ago helper
function getTimeAgo(date) {
    const seconds = Math.floor((new Date() - date) / 1000);
    if (seconds < 60) return 'Agora mesmo';
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min atrás`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h atrás`;
    return date.toLocaleDateString('pt-BR');
}

// Tips rotation
const tips = [
    'Títulos com 60-80 caracteres têm melhor desempenho no Mercado Livre.',
    'Inclua a marca no início do título para melhor reconhecimento.',
    'Use palavras-chave de alta busca nos primeiros 50 caracteres.',
    'Descrições com bullet points aumentam a taxa de conversão em até 30%.',
    'Fichas técnicas completas melhoram o rankeamento em buscas.',
    'Evite CAPS LOCK - use apenas para siglas como SSD, RAM, etc.',
    'Inclua diferenciais do produto nas primeiras linhas da descrição.'
];

function rotateTips() {
    const tipText = document.getElementById('tipText');
    let currentTip = 0;

    setInterval(() => {
        currentTip = (currentTip + 1) % tips.length;
        tipText.style.opacity = 0;
        setTimeout(() => {
            tipText.textContent = tips[currentTip];
            tipText.style.opacity = 1;
        }, 300);
    }, 10000);
}

// Modal handlers
function openModal(type) {
    const modal = new bootstrap.Modal(document.getElementById(`${type}Modal`));
    modal.show();
}

// API Request helper
async function apiRequest(endpoint, data, btn) {
    btn.querySelector('.btn-text').classList.add('d-none');
    btn.querySelector('.btn-loading').classList.remove('d-none');
    btn.disabled = true;

    try {
        const response = await fetch(`${API_BASE}${endpoint}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        return await response.json();
    } finally {
        btn.querySelector('.btn-text').classList.remove('d-none');
        btn.querySelector('.btn-loading').classList.add('d-none');
        btn.disabled = false;
    }
}

// Submit handlers
async function submitAnalyze(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.closest('.modal-content').querySelector('button[type="submit"]');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    data.price = parseFloat(data.price) || 0;

    const result = await apiRequest('/analyze', data, btn);

    bootstrap.Modal.getInstance(document.getElementById('analyzeModal')).hide();

    if (result.success && result.data) {
        const d = result.data;
        const score = d.score || 0;

        // Update stats
        stats.analysisCount++;
        stats.totalScore += score;
        stats.scoreCount++;
        saveStats();

        // Add activity
        addActivity('analyze', data.title, score);

        // Show results
        showResults('Análise de SEO', `
            <div class="row align-items-center mb-4">
                <div class="col-auto">
                    <div class="score-circle ${score >= 70 ? 'score-good' : score >= 50 ? 'score-medium' : 'score-bad'}">
                        <span class="score-value">${score}</span>
                        <span class="score-label">Score</span>
                    </div>
                </div>
                <div class="col">
                    <h4 class="mb-2">Score SEO: ${score}/100</h4>
                    <p class="text-muted mb-0">${d.estimated_improvement || 'Análise completa realizada'}</p>
                </div>
            </div>

            ${d.suggestions ? `
            <h6 class="fw-bold mb-3"><i class="bi bi-lightbulb me-2 text-warning"></i>Sugestões de Melhoria</h6>
            <div class="mb-4">
                ${d.suggestions.map(s => `
                    <div class="result-item">
                        <div class="d-flex align-items-start">
                            <i class="bi bi-arrow-right-circle text-primary me-2 mt-1"></i>
                            <span>${s}</span>
                        </div>
                    </div>
                `).join('')}
            </div>
            ` : ''}

            <details class="mt-3">
                <summary class="text-muted small cursor-pointer">Ver dados completos</summary>
                <pre class="bg-light p-3 rounded mt-2 small" style="max-height: 200px; overflow: auto;">${JSON.stringify(d, null, 2)}</pre>
            </details>
        `, result);
    } else {
        showError(result.error || 'Erro na análise');
    }
}

async function submitTitle(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.closest('.modal-content').querySelector('button[type="submit"]');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    const result = await apiRequest('/optimize-title', data, btn);

    bootstrap.Modal.getInstance(document.getElementById('titleModal')).hide();

    if (result.success && result.data) {
        const d = result.data;

        stats.optimizationCount++;
        saveStats();
        addActivity('title', data.title);

        showResults('Título Otimizado', `
            <div class="result-item bg-success bg-opacity-10 border border-success mb-4">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="text-success mb-2"><i class="bi bi-check-circle me-2"></i>Título Otimizado</h6>
                        <p class="mb-1 fs-5 fw-medium">${d.optimized_title || ''}</p>
                        <small class="text-muted">${d.character_count || 0} caracteres</small>
                    </div>
                    <button class="btn btn-sm btn-outline-success" onclick="copyText('${(d.optimized_title || '').replace(/'/g, "\\'")}')">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
            </div>

            ${d.alternative_titles && d.alternative_titles.length > 0 ? `
            <h6 class="fw-bold mb-3"><i class="bi bi-list-ul me-2 text-primary"></i>Alternativas</h6>
            ${d.alternative_titles.map((t, i) => `
                <div class="result-item d-flex justify-content-between align-items-center">
                    <span>${t}</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="copyText('${t.replace(/'/g, "\\'")}')">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
            `).join('')}
            ` : ''}
        `, result);
    } else {
        showError(result.error || 'Erro na otimização');
    }
}

async function submitDescription(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.closest('.modal-content').querySelector('button[type="submit"]');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    data.features = data.features ? data.features.split('\n').filter(f => f.trim()) : [];

    const result = await apiRequest('/generate-description', data, btn);

    bootstrap.Modal.getInstance(document.getElementById('descriptionModal')).hide();

    if (result.success && result.data) {
        const d = result.data;

        stats.optimizationCount++;
        saveStats();
        addActivity('description', data.title);

        showResults('Descrição Gerada', `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge bg-warning text-dark">
                    <i class="bi bi-file-text me-1"></i>${d.word_count || 0} palavras
                </span>
                <button class="btn btn-sm btn-outline-primary" onclick="copyText(document.getElementById('generatedDesc').innerText)">
                    <i class="bi bi-clipboard me-1"></i>Copiar
                </button>
            </div>

            <div class="result-item" id="generatedDesc" style="white-space: pre-wrap;">${d.description || ''}</div>

            ${d.bullet_points && d.bullet_points.length > 0 ? `
            <h6 class="fw-bold mt-4 mb-3"><i class="bi bi-list-check me-2 text-success"></i>Bullet Points</h6>
            <ul class="list-unstyled">
                ${d.bullet_points.map(b => `
                    <li class="result-item d-flex align-items-start">
                        <i class="bi bi-check-circle-fill text-success me-2 mt-1"></i>
                        <span>${b}</span>
                    </li>
                `).join('')}
            </ul>
            ` : ''}
        `, result);
    } else {
        showError(result.error || 'Erro na geração');
    }
}

async function submitKeywords(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.closest('.modal-content').querySelector('button[type="submit"]');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    const result = await apiRequest('/keywords', data, btn);

    bootstrap.Modal.getInstance(document.getElementById('keywordsModal')).hide();

    if (result.success && result.data) {
        const d = result.data;

        stats.analysisCount++;
        saveStats();
        addActivity('keywords', data.product);

        showResults('Pesquisa de Keywords', `
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="result-item text-center py-4">
                        <div class="text-muted small mb-2">KEYWORD PRINCIPAL</div>
                        <h4 class="fw-bold text-primary mb-0">${d.main_keyword || '-'}</h4>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="result-item text-center py-4">
                        <div class="text-muted small mb-2">ESTRATÉGIA RECOMENDADA</div>
                        <h4 class="fw-bold text-success mb-0" style="font-size:1rem;">${d.recommended_strategy || '-'}</h4>
                    </div>
                </div>
            </div>

            <h6 class="fw-bold mb-3"><i class="bi bi-tags me-2 text-info"></i>Keywords Secundárias</h6>
            <div class="mb-4">
                ${(d.secondary_keywords || []).map(k => `
                    <span class="keyword-badge keyword-secondary">${k}</span>
                `).join('')}
            </div>

            ${d.long_tail_keywords ? `
            <h6 class="fw-bold mb-3"><i class="bi bi-search me-2 text-warning"></i>Long Tail Keywords</h6>
            <div>
                ${d.long_tail_keywords.map(k => `
                    <span class="keyword-badge keyword-secondary">${k}</span>
                `).join('')}
            </div>
            ` : ''}
        `, result);
    } else {
        showError(result.error || 'Erro na pesquisa');
    }
}

async function submitTechSheet(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.closest('.modal-content').querySelector('button[type="submit"]');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    const result = await apiRequest('/tech-sheet/generate', data, btn);

    bootstrap.Modal.getInstance(document.getElementById('techsheetModal')).hide();

    if (result.success && result.data) {
        const d = result.data;

        stats.optimizationCount++;
        saveStats();
        addActivity('techsheet', data.title);

        showResults('Ficha Técnica', `
            <div class="d-flex justify-content-between align-items-center mb-4">
                <span class="badge bg-danger"><i class="bi bi-list-check me-1"></i>Ficha Técnica Gerada</span>
                <button class="btn btn-sm btn-outline-primary" onclick="copyTable()">
                    <i class="bi bi-clipboard me-1"></i>Copiar
                </button>
            </div>

            ${d.attributes ? `
            <div class="table-responsive">
                <table class="table table-striped attribute-table mb-0" id="attributeTable">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Atributo</th>
                            <th>Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${Object.entries(d.attributes).map(([k, v]) => `
                            <tr>
                                <td class="fw-medium">${k}</td>
                                <td>${v}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            ` : '<p class="text-muted">Nenhum atributo gerado</p>'}
        `, result);
    } else {
        showError(result.error || 'Erro na geração');
    }
}

// Results display
let currentResults = null;

function showResults(title, html, data) {
    currentResults = data;
    document.getElementById('resultsTitle').textContent = title;
    document.getElementById('resultsContent').innerHTML = html;
    document.getElementById('resultsCard').style.display = 'block';
    document.getElementById('resultsCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function showError(message) {
    showResults('Erro', `
        <div class="alert alert-danger d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-4"></i>
            <div>
                <h6 class="mb-1">Ocorreu um erro</h6>
                <p class="mb-0">${message}</p>
            </div>
        </div>
    `, null);
}

function closeResults() {
    document.getElementById('resultsCard').style.display = 'none';
    currentResults = null;
}

function copyResults() {
    if (!currentResults) return;
    navigator.clipboard.writeText(JSON.stringify(currentResults, null, 2));
    Toast.success('Copiado para a área de transferência!');
}

function downloadResults() {
    if (!currentResults) return;
    const blob = new Blob([JSON.stringify(currentResults, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'seo-resultado.json';
    a.click();
    URL.revokeObjectURL(url);
}

function copyText(text) {
    navigator.clipboard.writeText(text);
    Toast.success('Copiado!');
}

function copyTable() {
    const table = document.getElementById('attributeTable');
    if (!table) return;

    let text = '';
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        text += `${cells[0].textContent}: ${cells[1].textContent}\n`;
    });

    navigator.clipboard.writeText(text);
    Toast.success('Tabela copiada!');
}
</script>
