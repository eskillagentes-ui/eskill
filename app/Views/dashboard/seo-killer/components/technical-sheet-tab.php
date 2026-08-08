<?php

declare(strict_types=1);

// TAB: Ficha Técnica (Atualização Inteligente)
?>

<div class="tech-sheet" id="tech-sheet-root">

<div class="action-section">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h4 class="mb-1">🧾 Ficha Técnica</h4>
            <p class="text-muted mb-0">Liste anúncios, veja lacunas e gere sugestões com aprovação antes de aplicar.</p>
        </div>

        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="/dashboard/tech-sheet" title="Abrir painel completo (smart-fill, bulk, export)">
                <i class="bi bi-box-arrow-up-right"></i> Completo
            </a>
            <button class="btn btn-outline-dark" type="button" data-tech-action="toggleLargeText" title="Aumentar/reduzir tamanho do texto">
                <i class="bi bi-type"></i> Texto
            </button>
            <button class="btn btn-outline-dark" type="button" data-tech-action="toggleHighContrast" title="Ativar/desativar alto contraste">
                <i class="bi bi-circle-half"></i> Contraste
            </button>
            <button class="btn btn-outline-primary" type="button" data-tech-action="loadList">
                <i class="bi bi-arrow-clockwise"></i> Atualizar
            </button>
        </div>
    </div>

    <hr>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <ul class="nav nav-pills" id="tech-sheet-tab-pills">
            <li class="nav-item">
                <button class="nav-link active" type="button" data-tab="pending" data-tech-action="setTab" data-tech-args='["pending"]' title="Qualquer lacuna: obrigatórios, filtro ou hidden SEO">Lacunas</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" type="button" data-tab="critical" data-tech-action="setTab" data-tech-args='["critical"]' title="Só obrigatórios/filtro (não inclui hidden-only)">Críticas</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" type="button" data-tab="hidden" data-tech-action="setTab" data-tech-args='["hidden"]' title="Atributos ocultos (SEO) faltando">Hidden SEO</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" type="button" data-tab="review" data-tech-action="setTab" data-tech-args='["review"]' title="Itens com sugestões pendentes de aprovação">Sugestões</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" type="button" data-tab="done" data-tech-action="setTab" data-tech-args='["done"]'>Concluídos</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" type="button" data-tab="all" data-tech-action="setTab" data-tech-args='["all"]'>Todos</button>
            </li>
        </ul>

        <div class="d-flex align-items-end gap-2">
            <div>
                <label class="form-label mb-1">Ordenar</label>
                <select id="tech-sheet-sort" class="form-select">
                    <option value="updated_at" selected>Atualizados recentemente</option>
                    <option value="completeness">Menor completude</option>
                    <option value="missing_required">Mais obrigatórios faltando</option>
                    <option value="pending_suggestions">Mais sugestões pendentes</option>
                </select>
            </div>
        </div>
    </div>

    <div class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label">Buscar</label>
            <input id="tech-sheet-q" class="form-control" placeholder="Título ou termo" />
        </div>
        <div class="col-md-3">
            <label class="form-label">Por página</label>
            <select id="tech-sheet-per-page" class="form-select">
                <option value="10">10</option>
                <option value="20" selected>20</option>
                <option value="50">50</option>
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-primary w-100" type="button" data-tech-action="loadList">
                <i class="bi bi-search"></i> Buscar
            </button>
        </div>
    </div>

    <div id="tech-sheet-ean-banner" class="alert alert-light border mt-3 mb-0 py-2 px-3 small d-none" role="status"></div>
    <div id="tech-sheet-hidden-banner" class="alert alert-info border mt-2 mb-0 py-2 px-3 small d-none" role="status"></div>

    <div class="row g-2 mt-3" id="tech-sheet-kpis">
        <div class="col-md-2 col-6">
            <div class="border rounded p-2">
                <div class="text-muted small">Total (active)</div>
                <div class="fw-semibold" id="tech-sheet-kpi-total">—</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="border rounded p-2">
                <div class="text-muted small">Lacunas críticas</div>
                <div class="fw-semibold" id="tech-sheet-kpi-critical">—</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="border rounded p-2" title="Itens com atributos ocultos faltando">
                <div class="text-muted small">Hidden SEO</div>
                <div class="fw-semibold" id="tech-sheet-kpi-hidden">—</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="border rounded p-2" title="Sugestões de MODEL / itens que precisam">
                <div class="text-muted small">Modelos</div>
                <div class="fw-semibold" id="tech-sheet-kpi-model">—</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="border rounded p-2">
                <div class="text-muted small">Sugestões pendentes</div>
                <div class="fw-semibold" id="tech-sheet-kpi-pending">—</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="border rounded p-2">
                <div class="text-muted small">Completude média</div>
                <div class="fw-semibold" id="tech-sheet-kpi-avg">—</div>
            </div>
        </div>
    </div>
</div>

<div class="action-section">
    <div id="tech-sheet-list"></div>
</div>

<!-- Modal: Detalhe do item -->
<div class="modal fade" id="techSheetDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detalhe — Ficha Técnica</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="tech-sheet-detail-body">
                <div class="text-muted">Carregando...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Resultado de job (batch) -->
<div class="modal fade" id="techSheetJobResultModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Resultado — Batch Ficha Técnica</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="tech-sheet-job-result-body">
                <div class="text-muted">Carregando...</div>
            </div>
            <div class="modal-footer">
                <div class="me-auto small text-muted" id="tech-sheet-job-result-meta"></div>
                <button class="btn btn-outline-secondary" type="button" data-tech-action="copyJobFailures">
                    <i class="bi bi-clipboard"></i> Copiar falhas
                </button>
                <button id="tech-sheet-job-retry-btn" class="btn btn-warning" type="button" data-tech-action="retryFailuresFromModal">
                    <i class="bi bi-arrow-repeat"></i> Reprocessar falhas
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
(function() {
    const q = document.getElementById('tech-sheet-q');
    const perPage = document.getElementById('tech-sheet-per-page');
    const sort = document.getElementById('tech-sheet-sort');

    if (q) {
        let t = null;
        q.addEventListener('input', function() {
            clearTimeout(t);
            t = setTimeout(() => {
                if (window.SEOKiller && SEOKiller.techSheet) {
                    SEOKiller.techSheet.setQuery(q.value);
                }
            }, 250);
        });
    }

    if (perPage) {
        perPage.addEventListener('change', function() {
            if (window.SEOKiller && SEOKiller.techSheet) {
                SEOKiller.techSheet.state.perPage = parseInt(perPage.value, 10) || 20;
                SEOKiller.techSheet.state.page = 1;
                SEOKiller.techSheet.loadList();
            }
        });
    }

    if (sort) {
        sort.addEventListener('change', function() {
            if (window.SEOKiller && SEOKiller.techSheet) {
                SEOKiller.techSheet.state.sort = sort.value || 'updated_at';
                SEOKiller.techSheet.state.page = 1;
                SEOKiller.techSheet.loadList();
            }
        });
    }
})();
</script>

</div>
