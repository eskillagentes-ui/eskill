<?php

declare(strict_types=1);

$title = $pageTitle ?? 'Divergências Financeiras';
ob_start();
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="fw-bold mb-1">Divergências do Ledger</h2>
                    <p class="mb-0 text-muted">Conciliação automática: esperado (pedido) vs realizado (livro financeiro).</p>
                </div>
                <div class="d-flex gap-2 align-items-end">
                    <div>
                        <label class="form-label small mb-0" for="disc-start">De</label>
                        <input type="date" id="disc-start" class="form-control form-control-sm" value="<?= htmlspecialchars(date('Y-m-d', strtotime('-30 days'))) ?>">
                    </div>
                    <div>
                        <label class="form-label small mb-0" for="disc-end">Até</label>
                        <input type="date" id="disc-end" class="form-control form-control-sm" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                    </div>
                    <div>
                        <label class="form-label small mb-0" for="disc-status">Status</label>
                        <select id="disc-status" class="form-select form-select-sm">
                            <option value="open" selected>Abertas</option>
                            <option value="resolved">Resolvidas</option>
                            <option value="ignored">Ignoradas</option>
                            <option value="">Todas</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" id="disc-reload">Filtrar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4" id="disc-stats">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Total</div><div class="fs-4 fw-bold" id="stat-total">0</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Críticas</div><div class="fs-4 fw-bold text-danger" id="stat-critical">0</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Avisos</div><div class="fs-4 fw-bold text-warning" id="stat-warning">0</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Info</div><div class="fs-4 fw-bold text-info" id="stat-info">0</div></div></div></div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="disc-table">
            <thead class="table-light">
                <tr>
                    <th>Severidade</th>
                    <th>Tipo</th>
                    <th>Pedido</th>
                    <th>Esperado</th>
                    <th>Real</th>
                    <th>Diff</th>
                    <th>Explicação</th>
                    <th>Detectado</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const money = (v) => v == null ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v));
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function load() {
        const start = document.getElementById('disc-start').value;
        const end = document.getElementById('disc-end').value;
        const status = document.getElementById('disc-status').value;
        const qs = new URLSearchParams({ start, end, limit: '200' });
        if (status) qs.set('status', status);
        const res = await fetch('/api/financials/discrepancies?' + qs.toString(), { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) {
            alert(data.error || 'Falha ao carregar divergências');
            return;
        }
        const rows = data.data || [];
        let crit = 0, warn = 0, info = 0;
        rows.forEach((r) => {
            if (r.severity === 'critical') crit++;
            else if (r.severity === 'warning') warn++;
            else info++;
        });
        document.getElementById('stat-total').textContent = String(rows.length);
        document.getElementById('stat-critical').textContent = String(crit);
        document.getElementById('stat-warning').textContent = String(warn);
        document.getElementById('stat-info').textContent = String(info);

        const tbody = document.querySelector('#disc-table tbody');
        tbody.innerHTML = rows.map((r) => {
            const sevClass = r.severity === 'critical' ? 'danger' : (r.severity === 'warning' ? 'warning' : 'info');
            const actions = (r.status === 'open')
                ? `<button class="btn btn-sm btn-outline-success me-1" data-act="resolved" data-id="${r.id}">Resolver</button>
                   <button class="btn btn-sm btn-outline-secondary" data-act="ignored" data-id="${r.id}">Ignorar</button>`
                : `<button class="btn btn-sm btn-outline-primary" data-act="reopened" data-id="${r.id}">Reabrir</button>`;
            return `<tr>
                <td><span class="badge text-bg-${sevClass}">${esc(r.severity)}</span></td>
                <td><code>${esc(r.discrepancy_type)}</code></td>
                <td><a href="/dashboard/orders?order=${esc(r.order_id)}">${esc(r.order_id)}</a></td>
                <td>${money(r.expected_amount)}</td>
                <td>${money(r.actual_amount)}</td>
                <td>${money(r.difference_amount)}</td>
                <td class="small">${esc(r.explanation)}</td>
                <td class="small text-muted">${esc(r.detected_at)}</td>
                <td class="text-nowrap">${actions}</td>
            </tr>`;
        }).join('') || '<tr><td colspan="9" class="text-center text-muted py-4">Nenhuma divergência neste filtro</td></tr>';
    }

    document.getElementById('disc-reload').addEventListener('click', load);
    document.querySelector('#disc-table').addEventListener('click', async (ev) => {
        const btn = ev.target.closest('button[data-act]');
        if (!btn) return;
        const id = btn.getAttribute('data-id');
        const action = btn.getAttribute('data-act');
        const note = action === 'ignored' ? prompt('Motivo (opcional):') : null;
        const res = await fetch('/api/financials/discrepancies/' + id + '/resolve', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, note }),
        });
        const data = await res.json();
        if (!data.success) {
            alert(data.error || 'Falha ao atualizar');
            return;
        }
        load();
    });

    load();
})();
</script>

<?php
$content = ob_get_clean();
// Mesmo layout da conciliação (Views/dashboard/app.php não existe — TC028).
include __DIR__ . '/../../layouts/modern/app.php';
