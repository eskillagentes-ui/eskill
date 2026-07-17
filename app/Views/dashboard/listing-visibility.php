<?php

declare(strict_types=1);

$title = 'Visibilidade e Irregularidades';
$subtitle = 'Fila oficial ML: bloqueios, score /performance e ações para ativar a busca';
include __DIR__ . '/../layouts/modern/partials/page-header.php';
?>

<div class="alert alert-warning border-0 shadow-sm mb-3">
    <i class="bi bi-shield-check me-1"></i>
    Modo <strong>somente leitura</strong>: detecta irregularidades e oportunidades SEO oficiais.
    Nenhuma alteração é enviada ao Mercado Livre nesta tela.
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Bloqueados</div>
                <div class="fs-3 fw-semibold" id="stat-blocked">—</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Fila busca</div>
                <div class="fs-3 fw-semibold" id="stat-queue">—</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Críticos</div>
                <div class="fs-3 fw-semibold text-danger" id="stat-critical">—</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Melhorar</div>
                <div class="fs-3 fw-semibold text-warning" id="stat-improve">—</div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-tabs" id="lvTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-irr" data-bs-toggle="tab" data-bs-target="#pane-irr" type="button" role="tab">
            Irregularidades
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-seo" data-bs-toggle="tab" data-bs-target="#pane-seo" type="button" role="tab">
            Ativar busca (SEO)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-store" data-bs-toggle="tab" data-bs-target="#pane-store" type="button" role="tab">
            Fila persistida
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-pic" data-bs-toggle="tab" data-bs-target="#pane-pic" type="button" role="tab">
            Diagnóstico de imagem
        </button>
    </li>
</ul>

<div class="tab-content border border-top-0 bg-white shadow-sm p-3" id="lvTabContent">
    <div class="tab-pane fade show active" id="pane-irr" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
            <p class="mb-0 text-muted small">Fonte: items/search + /moderations/last_moderation</p>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-success" id="btn-sync-irr" title="Persiste na fila ml_sales_blockers">
                    <i class="bi bi-cloud-download"></i> Sync + salvar
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btn-refresh-irr">
                    <i class="bi bi-arrow-clockwise"></i> Atualizar
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Anúncio</th>
                        <th>Status origem</th>
                        <th>Severidade</th>
                        <th>Motivo</th>
                        <th>Próximo passo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="irr-body">
                    <tr><td colspan="6" class="text-center text-muted py-4">Carregando…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-seo" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="mb-0 text-muted small">Fonte: /item/{id}/performance — WARNING primeiro, depois OPPORTUNITY de busca</p>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btn-refresh-seo">
                <i class="bi bi-arrow-clockwise"></i> Atualizar
            </button>
        </div>
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Anúncio</th>
                                <th>Score</th>
                                <th>Ativação</th>
                                <th>Top ação</th>
                            </tr>
                        </thead>
                        <tbody id="seo-body">
                            <tr><td colspan="4" class="text-center text-muted py-4">Abra a aba e atualize</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 bg-light h-100">
                    <div class="card-body" id="seo-detail">
                        <p class="text-muted mb-0">Selecione um anúncio na fila para ver as ações SEO oficiais.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-store" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
            <p class="mb-0 text-muted small">SalesBlockerStore — snapshots sem tokens (urgent / exposure)</p>
            <div class="d-flex gap-2 align-items-center">
                <select class="form-select form-select-sm" id="store-queue" style="width:auto">
                    <option value="urgent">urgent</option>
                    <option value="exposure">exposure</option>
                    <option value="account">account</option>
                </select>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btn-refresh-store">
                    <i class="bi bi-arrow-clockwise"></i> Atualizar
                </button>
            </div>
        </div>
        <p class="small text-muted mb-2" id="store-counts">Contagens: —</p>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Anúncio</th>
                        <th>Fila</th>
                        <th>Severidade</th>
                        <th>Motivo</th>
                        <th>Remedy</th>
                        <th>Scanned</th>
                    </tr>
                </thead>
                <tbody id="store-body">
                    <tr><td colspan="6" class="text-center text-muted py-4">Abra a aba e atualize</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-pic" role="tabpanel">
        <p class="text-muted small">API oficial POST /moderations/pictures/diagnostic — valida antes de publicar (não associa a imagem).</p>
        <form id="pic-form" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">category_id</label>
                <input type="text" class="form-control" name="category_id" required placeholder="MLB1234">
            </div>
            <div class="col-md-4">
                <label class="form-label">picture_type</label>
                <select class="form-select" name="picture_type">
                    <option value="thumbnail">thumbnail</option>
                    <option value="variation_thumbnail">variation_thumbnail</option>
                    <option value="other">other</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">title (opcional)</label>
                <input type="text" class="form-control" name="title" maxlength="200">
            </div>
            <div class="col-12">
                <label class="form-label">picture_url (ou picture_id abaixo)</label>
                <input type="url" class="form-control" name="picture_url" placeholder="https://...">
            </div>
            <div class="col-12">
                <label class="form-label">picture_id</label>
                <input type="text" class="form-control" name="picture_id" placeholder="123456-MLB...">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Diagnosticar</button>
            </div>
        </form>
        <pre class="mt-3 bg-light p-3 rounded small" id="pic-result" style="white-space: pre-wrap;">Aguardando…</pre>
    </div>
</div>

<script nonce="<?= defined('CSP_NONCE') ? CSP_NONCE : '' ?>">
(() => {
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));

    const activationBadge = (v) => {
        const map = {
            blocked: 'danger',
            critical: 'danger',
            improve: 'warning',
            healthy: 'success'
        };
        const tone = map[v] || 'secondary';
        return `<span class="badge text-bg-${tone}">${esc(v)}</span>`;
    };

    const state = { seoLoaded: false, storeLoaded: false };

    async function syncAndPersist() {
        try {
            const data = await requestJson('/api/listings/irregularities/sync', { method: 'POST' });
            if (!data.success) {
                alert(data.error || 'Falha no sync');
                return;
            }
            await loadIrregularities();
            state.storeLoaded = false;
            await loadSalesBlockers();
        } catch (e) {
            alert(String(e.message || e));
        }
    }

    async function loadSalesBlockers() {
        const body = document.getElementById('store-body');
        const queue = document.getElementById('store-queue')?.value || 'urgent';
        body.innerHTML = `<tr><td colspan="6" class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></td></tr>`;
        try {
            const data = await requestJson('/api/listings/sales-blockers?queue=' + encodeURIComponent(queue) + '&limit=50');
            if (!data.success) {
                body.innerHTML = `<tr><td colspan="6" class="text-danger text-center py-4">${esc(data.error || 'Falha')}</td></tr>`;
                return;
            }
            const counts = data.counts || {};
            document.getElementById('store-counts').textContent =
                `Contagens: urgent=${counts.urgent ?? 0} · exposure=${counts.exposure ?? 0} · account=${counts.account ?? 0}`;
            const items = data.items || [];
            if (!items.length) {
                body.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">Fila vazia. Use “Sync + salvar”.</td></tr>`;
                return;
            }
            body.innerHTML = items.map((r) => `
                <tr>
                    <td><code>${esc(r.item_id)}</code></td>
                    <td>${esc(r.queue)}</td>
                    <td>${esc(r.severity)}</td>
                    <td class="small">${esc(r.reason || '—')}</td>
                    <td class="small">${esc(r.remedy || '—')}</td>
                    <td class="small">${esc(r.scanned_at || '—')}</td>
                </tr>
            `).join('');
            state.storeLoaded = true;
        } catch (e) {
            body.innerHTML = `<tr><td colspan="6" class="text-danger text-center py-4">${esc(e.message || e)}</td></tr>`;
        }
    }

    async function loadIrregularities() {
        const body = document.getElementById('irr-body');
        body.innerHTML = `<tr><td colspan="6" class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></td></tr>`;
        try {
            const data = await requestJson('/api/listings/irregularities?limit=30');
            if (!data.success) {
                body.innerHTML = `<tr><td colspan="6" class="text-danger text-center py-4">${esc(data.error || 'Falha')}</td></tr>`;
                return;
            }
            const rows = data.blocked || [];
            document.getElementById('stat-blocked').textContent = String(data.totals?.unique ?? rows.length);
            if (!rows.length) {
                body.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">Nenhuma irregularidade detectada nesta amostra.</td></tr>`;
                return;
            }
            body.innerHTML = rows.map((r) => `
                <tr>
                    <td><code>${esc(r.listing_id)}</code></td>
                    <td>${esc(r.source_status)}</td>
                    <td>${activationBadge(r.severity === 'exposure_loss' ? 'improve' : 'blocked')}</td>
                    <td class="small">${esc(r.moderation?.reason || r.moderation?.name || '—')}</td>
                    <td class="small">${esc(r.next_step || '—')}</td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-analyze" data-id="${esc(r.listing_id)}">Analisar SEO</button>
                    </td>
                </tr>
            `).join('');
        } catch (e) {
            body.innerHTML = `<tr><td colspan="6" class="text-danger text-center py-4">${esc(e.message || e)}</td></tr>`;
        }
    }

    async function loadSeoQueue() {
        const body = document.getElementById('seo-body');
        body.innerHTML = `<tr><td colspan="4" class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></td></tr>`;
        try {
            const data = await requestJson('/api/listings/search-visibility/queue?limit=20');
            if (!data.success) {
                body.innerHTML = `<tr><td colspan="4" class="text-danger text-center py-4">${esc(data.error || 'Falha')}</td></tr>`;
                return;
            }
            const queue = data.queue || [];
            document.getElementById('stat-queue').textContent = String(queue.length);
            document.getElementById('stat-critical').textContent = String(queue.filter(q => q.search_activation === 'critical' || q.search_activation === 'blocked').length);
            document.getElementById('stat-improve').textContent = String(queue.filter(q => q.search_activation === 'improve').length);
            if (!queue.length) {
                body.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-4">Fila vazia nesta amostra.</td></tr>`;
                return;
            }
            body.innerHTML = queue.map((q) => `
                <tr class="seo-row" data-id="${esc(q.listing_id)}" style="cursor:pointer">
                    <td><code>${esc(q.listing_id)}</code></td>
                    <td>${esc(q.score)}</td>
                    <td>${activationBadge(q.search_activation)}</td>
                    <td class="small">${esc(q.top_action?.label || q.top_action?.title || '—')}</td>
                </tr>
            `).join('');
            state.seoLoaded = true;
        } catch (e) {
            body.innerHTML = `<tr><td colspan="4" class="text-danger text-center py-4">${esc(e.message || e)}</td></tr>`;
        }
    }

    async function analyzeItem(itemId) {
        const detail = document.getElementById('seo-detail');
        detail.innerHTML = `<div class="spinner-border spinner-border-sm"></div>`;
        try {
            const data = await requestJson('/api/listings/search-visibility/' + encodeURIComponent(itemId));
            if (!data.success) {
                detail.innerHTML = `<p class="text-danger mb-0">${esc(data.error || 'Falha')}</p>`;
                return;
            }
            const actions = (data.seo_actions || []).slice(0, 8).map((a) => `
                <li class="mb-2">
                    <span class="badge text-bg-${a.mode === 'WARNING' ? 'danger' : 'info'}">${esc(a.mode)}</span>
                    <strong>${esc(a.key)}</strong>
                    <div class="small text-muted">${esc(a.title || a.label)}</div>
                </li>
            `).join('');
            detail.innerHTML = `
                <h6 class="mb-2"><code>${esc(data.listing_id)}</code></h6>
                <p class="mb-1">Score: <strong>${esc(data.score)}</strong> · ${esc(data.level_wording)} · ${activationBadge(data.search_activation)}</p>
                <p class="small text-muted">Warnings: ${esc(data.pending_warnings)} · Opportunities: ${esc(data.pending_opportunities)}</p>
                <hr>
                <ol class="ps-3 mb-0">${actions || '<li class="text-muted">Sem ações pendentes</li>'}</ol>
            `;
            const tab = document.getElementById('tab-seo');
            if (tab && window.bootstrap) {
                window.bootstrap.Tab.getOrCreateInstance(tab).show();
            }
        } catch (e) {
            detail.innerHTML = `<p class="text-danger mb-0">${esc(e.message || e)}</p>`;
        }
    }

    document.getElementById('btn-refresh-irr')?.addEventListener('click', loadIrregularities);
    document.getElementById('btn-sync-irr')?.addEventListener('click', syncAndPersist);
    document.getElementById('btn-refresh-seo')?.addEventListener('click', loadSeoQueue);
    document.getElementById('btn-refresh-store')?.addEventListener('click', loadSalesBlockers);
    document.getElementById('store-queue')?.addEventListener('change', loadSalesBlockers);
    document.getElementById('tab-store')?.addEventListener('shown.bs.tab', () => {
        if (!state.storeLoaded) loadSalesBlockers();
    });

    document.getElementById('irr-body')?.addEventListener('click', (ev) => {
        const btn = ev.target.closest('.btn-analyze');
        if (!btn) return;
        analyzeItem(btn.getAttribute('data-id'));
    });

    document.getElementById('seo-body')?.addEventListener('click', (ev) => {
        const row = ev.target.closest('.seo-row');
        if (!row) return;
        analyzeItem(row.getAttribute('data-id'));
    });

    document.getElementById('tab-seo')?.addEventListener('shown.bs.tab', () => {
        if (!state.seoLoaded) loadSeoQueue();
    });

    document.getElementById('pic-form')?.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const fd = new FormData(ev.target);
        const payload = Object.fromEntries(fd.entries());
        Object.keys(payload).forEach((k) => {
            if (payload[k] === '') delete payload[k];
        });
        const out = document.getElementById('pic-result');
        out.textContent = 'Diagnosticando…';
        try {
            const data = await requestJson('/api/listings/picture-diagnostic', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            out.textContent = JSON.stringify(data, null, 2);
        } catch (e) {
            out.textContent = String(e.message || e);
        }
    });

    loadIrregularities();
})();
</script>
