<?php

declare(strict_types=1);

/**
 * Cadastro de CMV (custo da mercadoria) — local only, zero escrita ML.
 */
$title = 'CMV — Custo da Mercadoria';
$subtitle = 'Cadastre o custo real dos anúncios com venda. O P&L prioriza CMV real (sku_custos) e marca o restante como estimado.';
$actions = '
    <a href="/dashboard/financials" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-graph-up me-1"></i> Ver P&L
    </a>
';
include __DIR__ . '/../layouts/modern/partials/page-header.php';
?>

<div class="row g-3 mb-4" id="cogsSummary">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <div class="text-muted small">Anúncios com venda (90d)</div>
            <div class="h3 mb-0" id="cogsTotal">—</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <div class="text-muted small">Com CMV real</div>
            <div class="h3 mb-0 text-success" id="cogsReal">—</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <div class="text-muted small">Sem CMV (faltando)</div>
            <div class="h3 mb-0 text-warning" id="cogsMissing">—</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <div class="text-muted small">Importar CSV</div>
            <input type="file" id="cogsCsv" accept=".csv,text/csv" class="form-control form-control-sm mt-1">
            <div class="form-text">Colunas: MLB,custo</div>
        </div></div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Anúncios com venda — CMV</h5>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="cogsRefreshBtn">
            <i class="bi bi-arrow-clockwise"></i> Atualizar
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-3">MLB</th>
                        <th>Título</th>
                        <th class="text-end">Vendas 90d</th>
                        <th class="text-end">Receita 90d</th>
                        <th class="text-end">CMV unitário</th>
                        <th>Fonte</th>
                        <th class="pe-3">Salvar</th>
                    </tr>
                </thead>
                <tbody id="cogsTable">
                    <tr><td colspan="7" class="text-center py-5 text-muted">Carregando…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
async function loadCogsAudit() {
    const tbody = document.getElementById('cogsTable');
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">Carregando…</td></tr>';
    try {
        const client = window.ApiClient;
        if (!client || typeof client.json !== 'function') {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">ApiClient indisponível</td></tr>';
            return;
        }
        const { response, data } = await client.json('/api/cogs/audit?days=90');
        if (!response.ok || !data?.success) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Erro ao carregar auditoria</td></tr>';
            return;
        }
        const payload = data.data || {};
        const summary = payload.summary || {};
        document.getElementById('cogsTotal').textContent = summary.total ?? 0;
        document.getElementById('cogsReal').textContent = summary.with_real_cogs ?? 0;
        document.getElementById('cogsMissing').textContent = summary.missing ?? 0;

        const items = payload.items || [];
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted">Nenhum anúncio com venda no período</td></tr>';
            return;
        }
        tbody.innerHTML = items.map(row => {
            const cost = row.unit_cost != null ? Number(row.unit_cost) : '';
            const badge = row.cogs_badge === 'real'
                ? '<span class="badge bg-success">real</span>'
                : '<span class="badge bg-warning text-dark">estimado</span>';
            const title = escapeHtml(row.title || '—');
            const mlb = escapeHtml(row.mlb_id || '');
            const receita = Number(row.receita_90d || 0).toLocaleString('pt-BR', {style:'currency', currency:'BRL'});
            return `<tr data-mlb="${mlb}">
                <td class="ps-3"><code>${mlb}</code></td>
                <td><div class="text-truncate" style="max-width:320px" title="${title}">${title}</div></td>
                <td class="text-end">${row.vendas_90d ?? 0}</td>
                <td class="text-end">${receita}</td>
                <td class="text-end">
                    <input type="number" min="0" step="0.01" class="form-control form-control-sm text-end cogs-cost-input"
                           data-mlb="${mlb}" value="${cost}" style="width:110px;margin-left:auto">
                </td>
                <td>${badge} <small class="text-muted">${escapeHtml(row.fonte || 'none')}</small></td>
                <td class="pe-3">
                    <button type="button" class="btn btn-sm btn-primary cogs-save-btn" data-mlb="${mlb}">Salvar</button>
                </td>
            </tr>`;
        }).join('');
    } catch (e) {
        console.error('loadCogsAudit', e);
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Erro de rede</td></tr>';
    }
}

async function saveCogs(mlb) {
    const input = document.querySelector('.cogs-cost-input[data-mlb="' + mlb + '"]');
    const unitCost = parseFloat(input?.value || '');
    if (Number.isNaN(unitCost) || unitCost < 0) {
        alert('Informe um custo válido');
        return;
    }
    const { response, data } = await window.ApiClient.json('/api/cogs/' + encodeURIComponent(mlb), {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ unit_cost: unitCost })
    });
    if (response.ok && data?.success) {
        await loadCogsAudit();
    } else {
        alert(data?.error || 'Falha ao salvar');
    }
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

document.getElementById('cogsRefreshBtn')?.addEventListener('click', () => { loadCogsAudit(); });
document.getElementById('cogsTable')?.addEventListener('click', (ev) => {
    const btn = ev.target && ev.target.closest ? ev.target.closest('.cogs-save-btn') : null;
    if (!btn) return;
    const mlb = btn.getAttribute('data-mlb') || '';
    if (mlb) saveCogs(mlb);
});
document.getElementById('cogsCsv')?.addEventListener('change', async (ev) => {
    const file = ev.target.files?.[0];
    if (!file) return;
    const text = await file.text();
    const { response, data } = await window.ApiClient.json('/api/cogs/import', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ csv: text })
    });
    if (response.ok) {
        alert(`Importados: ${data?.data?.imported ?? 0}. Falhas: ${(data?.data?.failed || []).length}`);
        loadCogsAudit();
    } else {
        alert('Falha na importação');
    }
    ev.target.value = '';
});

document.addEventListener('DOMContentLoaded', loadCogsAudit);
</script>
