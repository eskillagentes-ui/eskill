<?php

declare(strict_types=1);

/**
 * Aba Resultados — KPI de intervenções SEO (baseline / uplift).
 * Empty state amigável quando não há intervenções.
 */
?>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h5 class="mb-1">Resultados (KPI Hidden SEO)</h5>
                <p class="text-muted mb-0 small">
                    Baseline capturado na aprovação; medição em janelas 7/14/28 dias após aplicação.
                    Semáforo: improved / neutral / regressed.
                </p>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="seo-kpi-refresh">
                Atualizar
            </button>
        </div>
        <div id="seo-kpi-empty" class="alert alert-light border d-none" role="status">
            Nenhuma intervenção aplicada ainda — o baseline será capturado quando a primeira for aplicada.
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle" id="seo-kpi-table">
                <thead>
                    <tr>
                        <th>MLB</th>
                        <th>Tipo</th>
                        <th>Status</th>
                        <th>Baseline (visitas/d · pos)</th>
                        <th>Resultados</th>
                        <th>Aprovado</th>
                    </tr>
                </thead>
                <tbody id="seo-kpi-tbody">
                    <tr><td colspan="6" class="text-muted">Carregando…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            <h6>Posição orgânica (rank tracker)</h6>
            <p class="small text-muted mb-2">
                Histórico por keyword via API oficial. Motivo histórico da desativação:
                <a href="/docs" class="d-none">docs</a>
                <code>GET /sites/MLB/search</code> retorna 403 neste host (datacenter) — ver
                <code>docs/ops/RANK_TRACKER.md</code>.
            </p>
            <div id="seo-rank-status" class="small text-muted">—</div>
            <div class="table-responsive mt-2">
                <table class="table table-sm">
                    <thead><tr><th>MLB</th><th>Keyword</th><th>Pos</th><th>Pág</th><th>Capturado</th></tr></thead>
                    <tbody id="seo-rank-tbody"><tr><td colspan="5" class="text-muted">—</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script nonce="<?= defined('CSP_NONCE') ? CSP_NONCE : '' ?>">
(function () {
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function semBadge(st) {
        var map = { improved: 'success', neutral: 'secondary', regressed: 'danger', applied: 'primary', baseline_captured: 'info' };
        var c = map[st] || 'secondary';
        return '<span class="badge bg-' + c + '">' + esc(st || '—') + '</span>';
    }
    function loadKpi() {
        fetch('/api/seo/kpi/interventions', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var items = (j && j.data && j.data.items) ? j.data.items : [];
                var empty = document.getElementById('seo-kpi-empty');
                var tbody = document.getElementById('seo-kpi-tbody');
                if (!items.length) {
                    empty.classList.remove('d-none');
                    tbody.innerHTML = '';
                    return;
                }
                empty.classList.add('d-none');
                tbody.innerHTML = items.map(function (it) {
                    var b = it.baseline || {};
                    var base = (b.visits_per_day != null ? b.visits_per_day : '—')
                        + ' · pos ' + (b.organic_position != null ? b.organic_position : 'N/D');
                    var res = '—';
                    if (it.results) {
                        try {
                            res = Object.keys(it.results).map(function (k) {
                                var w = it.results[k];
                                return k + 'd:' + (w.status || '—');
                            }).join(' · ');
                        } catch (e) { res = '—'; }
                    }
                    return '<tr><td><code>' + esc(it.mlb_id) + '</code></td><td>' + esc(it.tipo)
                        + '</td><td>' + semBadge(it.status) + '</td><td>' + esc(base)
                        + '</td><td>' + esc(res) + '</td><td>' + esc(it.approved_at || '—') + '</td></tr>';
                }).join('');
            })
            .catch(function () {
                document.getElementById('seo-kpi-tbody').innerHTML =
                    '<tr><td colspan="6" class="text-danger">Falha ao carregar</td></tr>';
            });
    }
    function loadRank() {
        fetch('/api/rank/status', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var d = (j && j.data) ? j.data : {};
                var st = d.status || {};
                var el = document.getElementById('seo-rank-status');
                el.textContent = (st.available ? 'DISPONÍVEL' : 'INDISPONÍVEL')
                    + ' — ' + (st.label || st.reason || '—')
                    + (st.freshness ? ' · freshness ' + st.freshness : '');
                var latest = d.latest || [];
                var tb = document.getElementById('seo-rank-tbody');
                if (!latest.length) {
                    tb.innerHTML = '<tr><td colspan="5" class="text-muted">Sem capturas com posição</td></tr>';
                    return;
                }
                tb.innerHTML = latest.map(function (row) {
                    return '<tr><td><code>' + esc(row.mlb_id) + '</code></td><td>' + esc(row.keyword)
                        + '</td><td>' + esc(row.position) + '</td><td>' + esc(row.page)
                        + '</td><td>' + esc(row.captured_at) + '</td></tr>';
                }).join('');
            })
            .catch(function () { /* ignore */ });
    }
    var btn = document.getElementById('seo-kpi-refresh');
    if (btn) btn.addEventListener('click', function () { loadKpi(); loadRank(); });
    loadKpi();
    loadRank();
})();
</script>
