<?php
/** @var string $content — injected by layout */
$cspNonce = defined('CSP_NONCE') ? CSP_NONCE : ($_SESSION['csp_nonce'] ?? '');
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="/dashboard">Dashboard</a></li>
        <li class="breadcrumb-item">Marca e Posicionamento</li>
        <li class="breadcrumb-item active">Pesquisa de vendedores</li>
    </ol>
</nav>

<!-- Page Header -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h1 class="page-title mb-1">Pesquisa de vendedores por marca</h1>
        <p class="text-muted mb-0">Mapeie todas as lojas que anunciam AWA no Mercado Livre</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" id="btn-export" disabled>
            <i class="bi bi-download me-1"></i>CSV
        </button>
        <button type="button" class="btn" id="btn-search"
                onclick="bsStartSearch(); return false;"
                style="background:#1D9E75;color:#fff;border-color:#1D9E75">
            <i class="bi bi-search me-1"></i>Buscar
        </button>
    </div>
</div>

<!-- Brand Search App -->
<div id="brand-search-app"
     data-default-brand-id="7297804"
     data-default-brand-name="AWA"
     data-site-id="MLB">

    <!-- Search Bar -->
    <div class="card mb-3" style="border:0.5px solid #e5e5e5;border-radius:12px">
        <div class="card-body py-3 px-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Marca</label>
                    <input type="text" class="form-control form-control-sm" id="inp-brand" value="AWA">
                </div>
                <div class="col" style="max-width:120px">
                    <label class="form-label small fw-semibold mb-1">ID da marca</label>
                    <input type="text" class="form-control form-control-sm" id="inp-brand-id" value="7297804">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Categoria</label>
                    <select class="form-select form-select-sm" id="sel-cat">
                        <option value="">Todas as categorias</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Ordenar por</label>
                    <select class="form-select form-select-sm" id="sel-sort">
                        <option value="total_items_brand">Mais anúncios</option>
                        <option value="reputation_score">Melhor reputação</option>
                        <option value="avg_price">Menor preço</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Progress Bar (oculta por padrão) -->
    <div id="progress-wrap" style="display:none" class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <small id="progress-text" class="text-muted">Aguardando...</small>
            <small id="progress-pct" class="fw-semibold">0%</small>
        </div>
        <div style="height:6px;background:#e9ecef;border-radius:3px;overflow:hidden">
            <div id="progress-fill"
                 style="height:100%;width:0%;background:#1D9E75;transition:width 0.4s ease;border-radius:3px">
            </div>
        </div>
    </div>

    <div id="bs-error-banner" class="alert alert-danger mb-3" style="display:none" role="alert"></div>

    <!-- Metrics Cards -->
    <div id="stats-row" class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="rounded p-3" style="background:#f5f5f3">
                <div id="stat-sellers" class="fs-4 fw-bold stat-value">—</div>
                <div class="small text-muted">vendedores ativos com AWA</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="rounded p-3" style="background:#f5f5f3">
                <div id="stat-items" class="fs-4 fw-bold stat-value">—</div>
                <div class="small text-muted">itens ativos encontrados</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="rounded p-3" style="background:#f5f5f3">
                <div id="stat-price" class="fs-4 fw-bold stat-value">—</div>
                <div class="small text-muted">média entre vendedores</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="rounded p-3" style="background:#f5f5f3">
                <div id="stat-leaders" class="fs-4 fw-bold stat-value">—</div>
                <div class="small text-muted">gold + platinum</div>
            </div>
        </div>
    </div>

    <!-- Quick Filters -->
    <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
        <span class="small text-muted me-1">Reputação:</span>
        <button class="filter-chip active" data-reputation="all">Todos</button>
        <button class="filter-chip" data-reputation="platinum">Platinum</button>
        <button class="filter-chip" data-reputation="gold">Gold</button>
        <button class="filter-chip" data-reputation="silver">Silver</button>
        <button class="filter-chip" data-reputation="new">Novos</button>
        <span class="text-muted mx-1">|</span>
        <span class="small text-muted me-1">Anúncios:</span>
        <button class="filter-chip active" data-min-items="0">Todos</button>
        <button class="filter-chip" data-min-items="100">+100</button>
        <button class="filter-chip" data-min-items="500">+500</button>
        <button class="filter-chip" data-min-items="1000">+1000</button>
    </div>

    <!-- Sellers Table -->
    <div class="card" style="border:0.5px solid #e5e5e5;border-radius:12px">
        <div class="card-body p-0">
            <div class="px-4 py-3 border-bottom d-flex align-items-center justify-content-between">
                <span id="table-title" class="text-muted small">Nenhuma busca realizada</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0" style="table-layout:fixed">
                    <thead>
                        <tr class="table-light">
                            <th style="width:36px">#</th>
                            <th style="width:220px">Loja / Vendedor</th>
                            <th style="width:90px">Anúncios</th>
                            <th style="width:110px">Reputação</th>
                            <th style="width:100px">Preço médio</th>
                            <th style="width:90px">Tipo</th>
                            <th style="width:80px">Tendência</th>
                            <th style="width:80px">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="sellers-tbody">
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="bi bi-search me-2"></i>Clique em Buscar para iniciar a pesquisa
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div id="pagination-wrap" class="d-flex align-items-center justify-content-between mt-3">
        <span id="pagination-info" class="small text-muted"></span>
        <nav><ul class="pagination pagination-sm mb-0" id="pagination-list"></ul></nav>
    </div>

</div><!-- #brand-search-app -->

<style nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES) ?>">
.filter-chip{font-size:12px;padding:4px 10px;border-radius:20px;border:0.5px solid #d3d1c7;background:#fff;color:#888780;cursor:pointer}
.filter-chip.active{background:#E1F5EE;border-color:#5DCAA5;color:#085041;font-weight:500}
.seller-cell{display:flex;align-items:center;gap:8px;overflow:hidden}
.avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0}
.seller-name{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px}
.seller-id{font-family:monospace;font-size:11px;color:#888780}
.badge-rep{font-size:11px;padding:3px 8px;border-radius:4px;display:inline-block}
.rep-bar{display:flex;align-items:center;gap:6px}
.rep-track{flex:1;height:4px;background:#e9ecef;border-radius:2px;overflow:hidden}
.rep-fill{height:100%;border-radius:2px}
.rep-val{font-size:11px;color:#888780}
.icon-btn{background:none;border:none;padding:3px 5px;cursor:pointer;color:#888780;border-radius:4px}
.icon-btn:hover{background:#f0f0f0;color:#333}
.action-cell{display:flex;gap:2px}
</style>

<script src="/js/brand-search.js?v=<?= @filemtime(__DIR__ . '/../../../public/js/brand-search.js') ?: time() ?>" nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES) ?>"></script>
