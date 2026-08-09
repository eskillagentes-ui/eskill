<style>
    .kpi-card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.08); }
    .kpi-value { font-size: 1.35rem; font-weight: 700; }
    .kpi-label { color: #64748b; font-size: .8rem; }
    .filters-card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.08); }
    .filter-btn.active { background: #3483FA; color: #fff; border-color: #3483FA; }
    .sale-card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.06); margin-bottom: 1rem; }
    .sale-card-header { display: flex; flex-wrap: wrap; gap: .75rem 1.25rem; align-items: center; padding: .85rem 1.1rem; border-bottom: 1px solid #f1f5f9; }
    .sale-status-dot { width: 10px; height: 10px; border-radius: 50%; background: #22c55e; display: inline-block; }
    .sale-status-dot.warn { background: #f59e0b; }
    .sale-status-dot.danger { background: #ef4444; }
    .sale-thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; background: #e2e8f0; }
    .sale-table { width: 100%; font-size: .82rem; margin: 0; }
    .sale-table th { color: #64748b; font-weight: 600; font-size: .7rem; text-transform: uppercase; letter-spacing: .03em; border: 0; padding: .55rem .75rem; white-space: nowrap; }
    .sale-table td { vertical-align: middle; border-color: #f1f5f9; padding: .7rem .75rem; }
    .margin-pill { display: inline-block; padding: .2rem .65rem; border-radius: 999px; font-weight: 700; font-size: .78rem; }
    .margin-good { background: #dcfce7; color: #166534; }
    .margin-mid { background: #fef3c7; color: #92400e; }
    .margin-low { background: #fee2e2; color: #991b1b; }
    .unlinked-banner { background: #fff7ed; color: #9a3412; border-radius: 10px; padding: .75rem 1rem; }
    .sale-expand { border: 0; background: transparent; color: #64748b; width: 100%; padding: .35rem; }
    .sale-detail { background: #f8fafc; border-top: 1px solid #f1f5f9; padding: 1rem 1.1rem; display: none; }
    .sale-detail.open { display: block; }
    .product-title { font-weight: 600; color: #0f172a; }
    .product-sku { color: #64748b; font-size: .75rem; }
</style>

<?php
$title = 'Vendas';
$subtitle = 'Lucro, margem e custos por pedido — estilo operacional de marketplace';
include __DIR__ . '/../layouts/modern/partials/page-header.php';
?>

<!-- KPIs -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">Faturamento</div>
            <div class="kpi-value" id="kpi-revenue">R$ 0</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">Lucro</div>
            <div class="kpi-value text-success" id="kpi-profit">R$ 0</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">Margem média</div>
            <div class="kpi-value" id="kpi-margin">0%</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">Vendas</div>
            <div class="kpi-value" id="kpi-total">0</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">Líquido marketplace</div>
            <div class="kpi-value" id="kpi-net">R$ 0</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">Anúncios sem CMV</div>
            <div class="kpi-value text-warning" id="kpi-unlinked">0</div>
        </div></div>
    </div>
</div>

<div id="unlinked-alert" class="unlinked-banner mb-3" style="display:none;">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong id="unlinked-alert-text">0</strong> linhas de venda sem CMV
    (<span id="unlinked-alert-unique">0</span> anúncios) — o lucro dessas linhas ignora custo do produto.
    <a href="/dashboard/pricing" class="ms-2 fw-semibold">Cadastrar custos</a>
</div>
<div id="tax-alert" class="unlinked-banner mb-3" style="display:none; background:#eff6ff; color:#1e40af;">
    <i class="bi bi-info-circle-fill me-2"></i>
    Imposto zerado no período — a API do ML retorna taxes_amount=0, então lucro/margem ficam inflados.
    Impacto estimado do Simples 9% neste período: ~<strong id="tax-impact-estimate">—</strong> a menos no lucro.
    <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="btn-set-tax-9">Aplicar 9%</button>
</div>

<!-- Filters -->
<div class="card filters-card mb-4">
    <div class="card-body">
        <div class="row align-items-end g-2">
            <div class="col-md-2">
                <label class="form-label small">Status</label>
                <select class="form-select form-select-sm" id="filter-status">
                    <option value="">Todos (ativos)</option>
                    <option value="paid">Pago</option>
                    <option value="delivered">Entregue</option>
                    <option value="shipped">Enviado</option>
                    <option value="cancelled">Cancelado</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Data Inicial</label>
                <input type="date" class="form-control form-control-sm" id="filter-date-from">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Data Final</label>
                <input type="date" class="form-control form-control-sm" id="filter-date-to">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Buscar</label>
                <input type="text" class="form-control form-control-sm" id="filter-search" placeholder="ID, SKU, título ou comprador...">
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-primary btn-sm w-100" id="btn-filter-sales">
                    <i class="bi bi-funnel me-1"></i>Filtrar
                </button>
            </div>
        </div>
        <div class="mt-2">
            <span class="me-2 small text-muted">Período:</span>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1 filter-btn" data-days="7">7 dias</button>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1 filter-btn active" data-days="30">30 dias</button>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1 filter-btn" data-days="90">90 dias</button>
            <button type="button" class="btn btn-sm btn-outline-secondary filter-btn" data-days="365">1 ano</button>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="mb-0">Listagem de vendas</h6>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-export-sales" title="Exportar CSV do período">
            <i class="bi bi-download me-1"></i>CSV
        </button>
        <span class="badge bg-primary" id="orders-count">0 vendas</span>
    </div>
</div>

<div id="sales-cards">
    <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
</div>

<div class="d-flex justify-content-between align-items-center mt-2 mb-4">
    <div class="text-muted small">Mostrando <span id="showing-from">0</span>-<span id="showing-to">0</span> de <span id="total-orders">0</span></div>
    <nav><ul class="pagination pagination-sm mb-0" id="pagination"></ul></nav>
</div>

<script nonce="<?= CSP_NONCE ?>">
(() => {
    let pageSales = [];
    let summary = { unlinked_items: 0, unlinked_unique_items: 0, total_profit: 0, total_revenue: 0, avg_margin: 0, marketplace_net: 0 };
    let paging = { total: 0, offset: 0, limit: 20 };
    let currentPage = 1;
    const perPage = 20;

    const money = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v || 0));
    const escapeHtml = (str) => String(str ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
    const marginClass = (pct) => {
        const n = Number(pct || 0);
        if (n >= 30) return 'margin-good';
        if (n >= 15) return 'margin-mid';
        return 'margin-low';
    };
    const statusDot = (status) => {
        const s = String(status || '').toLowerCase();
        if (['cancelled', 'canceled'].includes(s)) return 'danger';
        if (['pending', 'payment_required'].includes(s)) return 'warn';
        return '';
    };
    const fmtDate = (val) => {
        if (!val) return '—';
        // Timezone único (America/Sao_Paulo): valores "YYYY-MM-DD HH:mm:ss" sem
        // offset já vêm em wall-clock do fuso da aplicação — exibe os números
        // como estão, sem reinterpretar via Date() (evita que o navegador
        // aplique seu próprio timezone local, causando o mesmo pedido exibir
        // horas diferentes em telas distintas).
        const naive = String(val).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?\s*$/);
        if (naive) {
            const [, y, mo, d, h, mi] = naive;
            return `${d}/${mo}/${y} ${h}:${mi}`;
        }
        // Strings com offset explícito (ex.: API do ML) são convertidas de
        // forma inequívoca e sempre exibidas no fuso único da aplicação.
        const dt = new Date(val);
        if (Number.isNaN(dt.getTime())) return escapeHtml(val);
        return dt.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' }) + ' ' +
            dt.toLocaleTimeString('pt-BR', { timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    };

    function initDates(days = 30) {
        const end = new Date();
        const start = new Date();
        start.setDate(end.getDate() - days);
        document.getElementById('filter-date-from').value = start.toISOString().slice(0, 10);
        document.getElementById('filter-date-to').value = end.toISOString().slice(0, 10);
    }

    async function loadSales(page = 1) {
        currentPage = Math.max(1, page);
        const start = document.getElementById('filter-date-from').value;
        const end = document.getElementById('filter-date-to').value;
        const status = document.getElementById('filter-status').value;
        const search = (document.getElementById('filter-search').value || '').trim().toLowerCase();
        const cards = document.getElementById('sales-cards');
        cards.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><div class="mt-2 text-muted">Carregando vendas...</div></div>';

        try {
            const qs = new URLSearchParams({
                start,
                end,
                limit: String(perPage),
                offset: String((currentPage - 1) * perPage),
                source: 'local',
            });
            if (status) qs.set('status', status);
            if (search) qs.set('q', search);

            const result = await requestJson(`/api/financials/orders?${qs}`);
            const payload = result.data || result;
            if (!result.success && result.error) {
                cards.innerHTML = `<div class="alert alert-warning">${escapeHtml(result.error || payload.error || 'Falha ao carregar')}</div>`;
                return;
            }

            pageSales = payload.results || [];
            summary = payload.summary || summary;
            paging = payload.paging || { total: pageSales.length, offset: 0, limit: perPage };

            updateKpis();
            renderPage();
        } catch (e) {
            console.error(e);
            cards.innerHTML = '<div class="alert alert-danger">Erro ao carregar vendas.</div>';
        }
    }

    function updateKpis() {
        const revenue = Number(summary.total_revenue || 0);
        const profit = Number(summary.total_profit || 0);
        const net = Number(summary.marketplace_net || 0);
        const margin = Number(summary.avg_margin || 0);
        const unlinked = Number(summary.unlinked_items || 0);
        const hasUnique = Object.prototype.hasOwnProperty.call(summary, 'unlinked_unique_items');
        const unlinkedUnique = hasUnique ? Number(summary.unlinked_unique_items || 0) : null;
        const total = Number(paging.total || pageSales.length);

        document.getElementById('kpi-revenue').textContent = money(revenue);
        document.getElementById('kpi-profit').textContent = money(profit);
        document.getElementById('kpi-margin').textContent = `${margin.toFixed(1)}%`;
        document.getElementById('kpi-total').textContent = String(total);
        document.getElementById('kpi-net').textContent = money(net);
        document.getElementById('kpi-unlinked').textContent = String(unlinkedUnique !== null ? unlinkedUnique : unlinked);

        const alert = document.getElementById('unlinked-alert');
        if (unlinked > 0) {
            alert.style.display = '';
            document.getElementById('unlinked-alert-text').textContent = String(unlinked);
            const uniqueEl = document.getElementById('unlinked-alert-unique');
            if (uniqueEl) uniqueEl.textContent = String(unlinkedUnique > 0 ? unlinkedUnique : unlinked);
        } else {
            alert.style.display = 'none';
        }
        document.getElementById('orders-count').textContent = `${total} vendas`;

        const taxAlert = document.getElementById('tax-alert');
        if (taxAlert) {
            const showTax = total > 0 && summary.tax_configured === false;
            taxAlert.style.display = showTax ? '' : 'none';
            if (showTax) {
                const impactEl = document.getElementById('tax-impact-estimate');
                if (impactEl) impactEl.textContent = money(revenue > 0 ? revenue * 0.09 : 0);
            }
        }
    }

    function renderPage() {
        const cards = document.getElementById('sales-cards');
        const total = Number(paging.total || 0);
        const offset = Number(paging.offset || 0);

        if (!pageSales.length) {
            cards.innerHTML = '<div class="text-center py-5 text-muted"><i class="bi bi-inbox" style="font-size:2.5rem"></i><p class="mt-2 mb-0">Nenhuma venda no período</p></div>';
            document.getElementById('showing-from').textContent = '0';
            document.getElementById('showing-to').textContent = '0';
            document.getElementById('total-orders').textContent = '0';
            document.getElementById('pagination').innerHTML = '';
            return;
        }

        cards.innerHTML = pageSales.map((sale, idx) => renderSaleCard(sale, offset + idx)).join('');
        document.getElementById('showing-from').textContent = String(offset + 1);
        document.getElementById('showing-to').textContent = String(offset + pageSales.length);
        document.getElementById('total-orders').textContent = String(total);
        renderPagination();
    }

    function renderSaleCard(sale, globalIdx) {
        const items = sale.items || [];
        const rows = items.map((item) => {
            const thumb = item.thumbnail
                ? `<img src="${escapeHtml(item.thumbnail)}" class="sale-thumb me-2" alt="">`
                : `<div class="sale-thumb me-2 d-inline-flex align-items-center justify-content-center"><i class="bi bi-image text-muted"></i></div>`;
            return `<tr>
                <td>
                    <div class="d-flex align-items-center">
                        ${thumb}
                        <div>
                            <div class="product-title">${escapeHtml(item.title || item.item_id || 'Item')}</div>
                            <div class="product-sku">SKU Externo: ${escapeHtml(item.sku || '—')}${item.linked_product ? '' : ' · <span class="text-warning">sem custo</span>'}</div>
                        </div>
                    </div>
                </td>
                <td class="text-end">${item.quantity ?? 1}</td>
                <td class="text-end">${money(item.line_total)}</td>
                <td class="text-end">${money(item.unit_price)}</td>
                <td class="text-end">${money(item.marketplace_net)}</td>
                <td class="text-end">${money(item.tax)}</td>
                <td class="text-end">${money(item.product_cost)}</td>
                <td class="text-end">${money(item.extra_cost)}</td>
                <td class="text-end fw-semibold ${Number(item.profit || 0) >= 0 ? 'text-success' : 'text-danger'}">${money(item.profit)}</td>
                <td class="text-end"><span class="margin-pill ${marginClass(item.margin_pct)}">${Number(item.margin_pct || 0).toFixed(2).replace('.', ',')}%</span></td>
            </tr>`;
        }).join('');

        const detailId = `sale-detail-${globalIdx}`;
        return `<div class="card sale-card">
            <div class="sale-card-header">
                <span class="sale-status-dot ${statusDot(sale.status)}"></span>
                <span class="fw-semibold">${fmtDate(sale.date_created)}</span>
                <span class="text-muted"><i class="bi bi-truck me-1"></i>${escapeHtml(sale.shipping_label || 'Logística ML')}</span>
                <span class="ms-auto text-muted"><i class="bi bi-shop me-1"></i>${escapeHtml(sale.account_nickname || 'Conta ML')}</span>
                <span class="badge bg-light text-dark">#${escapeHtml(sale.order_id)}</span>
            </div>
            <div class="table-responsive">
                <table class="sale-table table mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-end">Qtd</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Preço unit.</th>
                            <th class="text-end">Líquido marketplace</th>
                            <th class="text-end">Imposto</th>
                            <th class="text-end">Custo produto</th>
                            <th class="text-end">Custo extra</th>
                            <th class="text-end">Lucro</th>
                            <th class="text-end">Margem</th>
                        </tr>
                    </thead>
                    <tbody>${rows || '<tr><td colspan="10" class="text-center text-muted py-3">Sem itens</td></tr>'}</tbody>
                </table>
            </div>
            <button type="button" class="sale-expand" data-detail="${detailId}" data-order-id="${escapeHtml(sale.order_id)}" aria-expanded="false">
                <i class="bi bi-chevron-down"></i>
            </button>
            <div class="sale-detail" id="${detailId}">
                <div class="row g-3 small">
                    <div class="col-md-3"><div class="text-muted">Receita bruta</div><div class="fw-semibold">${money(sale.sale_revenue ?? sale.total_amount)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Comissão ML</div><div class="fw-semibold">${money(sale.ml_fee)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Taxa pagamento</div><div class="fw-semibold">${money(sale.payment_fee)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Frete seller</div><div class="fw-semibold">${money(sale.shipping_cost)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Cupom / desconto</div><div class="fw-semibold">${money(sale.coupon_amount)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Reembolso debitado</div><div class="fw-semibold">${money(sale.refund_net || 0)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Reembolso coberto (BPP)</div><div class="fw-semibold text-info">${money(sale.refund_covered || 0)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Proteção / ressarcimento</div><div class="fw-semibold">${money(sale.protection_net || 0)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Imposto</div><div class="fw-semibold">${money(sale.taxes)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Líquido marketplace</div><div class="fw-semibold">${money(sale.marketplace_net)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Liberado (caixa)</div><div class="fw-semibold text-success">${money((sale.ledger_summary && sale.ledger_summary.released_amount) || 0)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Pendente liberação</div><div class="fw-semibold text-warning">${money((sale.ledger_summary && sale.ledger_summary.pending_release_amount) || 0)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Custo produto (CMV)</div><div class="fw-semibold">${money(sale.product_cost)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Custo operacional</div><div class="fw-semibold">${money(sale.extra_cost)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Lucro operacional</div><div class="fw-semibold ${Number(sale.profit||0)>=0?'text-success':'text-danger'}">${money(sale.profit)}</div></div>
                    <div class="col-md-3"><div class="text-muted">Margem</div><div class="fw-semibold">${Number(sale.margin_pct||0).toFixed(2)}%</div></div>
                    <div class="col-md-3"><div class="text-muted">Fonte</div><div class="fw-semibold">${escapeHtml(sale.ledger_source === 'ledger' ? 'Livro financeiro' : 'Pedido (fallback)')}${sale.ledger_entries_count ? ` · ${sale.ledger_entries_count} lanç.` : ''}</div></div>
                    <div class="col-md-3"><div class="text-muted">Pagamento</div><div class="fw-semibold">${escapeHtml(sale.payment_method_id || sale.payment_method || '—')}${sale.installments > 1 ? ` · ${sale.installments}x` : ''}</div></div>
                    <div class="col-md-3"><div class="text-muted">Comprador</div><div class="fw-semibold">${escapeHtml(sale.buyer_nickname || '—')}</div></div>
                    <div class="col-md-3"><div class="text-muted">Status</div><div class="fw-semibold">${escapeHtml(sale.status)}</div></div>
                </div>
                ${renderLedgerEntries(sale)}
                ${renderDiscrepancies(sale)}
                <div class="ledger-mount"></div>
            </div>
        </div>`;
    }

    function renderLedgerEntries(sale) {
        const entries = Array.isArray(sale.ledger_entries) ? sale.ledger_entries : [];
        if (!entries.length) return '';
        // Linha do tempo: ordena por occurred_at ASC (evento financeiro cronológico)
        const sorted = entries.slice().sort((a, b) => {
            const ta = Date.parse(a.occurred_at || a.created_at || '') || 0;
            const tb = Date.parse(b.occurred_at || b.created_at || '') || 0;
            return ta - tb;
        });
        const rows = sorted.map((e, idx) => {
            const rawId = `ledger-raw-${escapeHtml(sale.order_id || 'x')}-${idx}`;
            const hasRaw = e.raw_data && typeof e.raw_data === 'object';
            const rawBtn = hasRaw
                ? `<button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="collapse" data-bs-target="#${rawId}">JSON</button>`
                : '<span class="text-muted">—</span>';
            const rawBlock = hasRaw
                ? `<tr class="collapse" id="${rawId}"><td colspan="8"><pre class="small bg-light border rounded p-2 mb-0" style="max-height:220px;overflow:auto">${escapeHtml(JSON.stringify(e.raw_data, null, 2))}${e.raw_data_redacted ? '\n\n/* campos sensíveis redigidos */' : ''}</pre></td></tr>`
                : '';
            const when = escapeHtml((e.occurred_at || e.released_at || e.created_at || '').toString().replace('T', ' ').slice(0, 19));
            return `
            <tr>
                <td class="small text-muted text-nowrap">${when}</td>
                <td><code class="small">${escapeHtml(e.entry_type || '')}</code></td>
                <td>${escapeHtml(e.direction || '')}</td>
                <td class="text-end">${money(e.signed_amount)}</td>
                <td><span class="badge bg-${e.status === 'covered' ? 'info' : (e.status === 'posted' ? 'success' : (e.status === 'pending' ? 'warning' : 'secondary'))}">${escapeHtml(e.status || '')}</span></td>
                <td class="small text-muted">${escapeHtml(e.description || '')}</td>
                <td class="small"><span class="text-muted">${escapeHtml(e.source_system || '')}/${escapeHtml(e.source_type || '')}</span><br><code class="small">${escapeHtml(e.source_id || '')}${e.source_detail_id ? ':' + escapeHtml(e.source_detail_id) : ''}</code></td>
                <td>${rawBtn}</td>
            </tr>${rawBlock}`;
        }).join('');
        const summary = sale.ledger_summary || {};
        const cashLine = (summary.released_amount || summary.pending_release_amount)
            ? `<div class="small text-muted mb-2">Caixa: liberado ${money(summary.released_amount || 0)} · pendente ${money(summary.pending_release_amount || 0)}</div>`
            : '';
        return `
            <div class="mt-3">
                <div class="fw-semibold mb-1">Linha do tempo financeira (ledger)</div>
                ${cashLine}
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr>
                            <th>Quando</th><th>Tipo</th><th>Dir.</th><th class="text-end">Valor</th><th>Status</th><th>Descrição</th><th>Origem</th><th>Raw</th>
                        </tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </div>`;
    }

    function renderDiscrepancies(sale) {
        const discs = Array.isArray(sale.discrepancies) ? sale.discrepancies : [];
        if (!discs.length) return '';
        const items = discs.map((d) => {
            const sev = d.severity || 'info';
            const cls = sev === 'critical' ? 'danger' : (sev === 'warning' ? 'warning' : 'info');
            return `<div class="alert alert-${cls} py-2 px-3 mb-2 small mb-1">
                <strong>${escapeHtml(d.discrepancy_type || '')}</strong>
                <span class="text-muted"> · ${escapeHtml(sev)}</span><br>${escapeHtml(d.explanation || '')}
            </div>`;
        }).join('');
        return `<div class="mt-3"><div class="fw-semibold mb-1">Divergências</div>${items}</div>`;
    }

    function renderPagination() {
        const total = Number(paging.total || 0);
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        const el = document.getElementById('pagination');
        if (totalPages <= 1) { el.innerHTML = ''; return; }
        let html = `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage - 1}">‹</a></li>`;
        const maxButtons = 7;
        let from = Math.max(1, currentPage - Math.floor(maxButtons / 2));
        let to = Math.min(totalPages, from + maxButtons - 1);
        from = Math.max(1, to - maxButtons + 1);
        for (let i = from; i <= to; i++) {
            html += `<li class="page-item ${currentPage === i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }
        html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage + 1}">›</a></li>`;
        el.innerHTML = html;
    }

    async function fetchAllSalesForExport() {
        const start = document.getElementById('filter-date-from').value;
        const end = document.getElementById('filter-date-to').value;
        const status = document.getElementById('filter-status').value;
        const search = (document.getElementById('filter-search').value || '').trim();
        const limit = 100;
        let offset = 0;
        let total = Infinity;
        const rows = [];
        while (offset < total && rows.length < 2000) {
            const qs = new URLSearchParams({
                start, end, limit: String(limit), offset: String(offset), source: 'local',
            });
            if (status) qs.set('status', status);
            if (search) qs.set('q', search);
            const result = await requestJson(`/api/financials/orders?${qs}`);
            const payload = result.data || result;
            const batch = payload.results || [];
            total = Number(payload.paging?.total ?? batch.length);
            rows.push(...batch);
            if (!batch.length) break;
            offset += limit;
        }
        return rows;
    }

    function csvEscape(val) {
        const s = String(val ?? '');
        if (/[;"\n]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
        return s;
    }

    async function exportSalesCsv() {
        const btn = document.getElementById('btn-export-sales');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        try {
            const sales = await fetchAllSalesForExport();
            const headers = [
                'order_id', 'date', 'status', 'shipping', 'buyer', 'item_id', 'title', 'sku',
                'qty', 'unit_price', 'line_total', 'marketplace_net', 'tax', 'product_cost',
                'extra_cost', 'profit', 'margin_pct', 'linked_product', 'ml_fee', 'payment_fee',
            ];
            const lines = [headers.join(';')];
            sales.forEach((sale) => {
                (sale.items || [{}]).forEach((item) => {
                    lines.push([
                        sale.order_id, sale.date_created, sale.status, sale.shipping_label, sale.buyer_nickname,
                        item.item_id, item.title, item.sku, item.quantity, item.unit_price, item.line_total,
                        item.marketplace_net, item.tax, item.product_cost, item.extra_cost, item.profit,
                        item.margin_pct, item.linked_product ? 1 : 0, sale.ml_fee, sale.payment_fee,
                    ].map(csvEscape).join(';'));
                });
            });
            const blob = new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `vendas_${document.getElementById('filter-date-from').value}_${document.getElementById('filter-date-to').value}.csv`;
            a.click();
            URL.revokeObjectURL(a.href);
        } catch (e) {
            console.error(e);
            alert('Falha ao exportar CSV');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-download me-1"></i>CSV';
        }
    }

    async function applyDefaultTax9() {
        const btn = document.getElementById('btn-set-tax-9');
        btn.disabled = true;
        try {
            const result = await requestJson('/api/settings/global', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ default_tax_rate: 9 }),
            });
            if (!result.success) {
                alert(result.error || 'Não foi possível salvar a alíquota');
                return;
            }
            await loadSales(1);
        } catch (e) {
            console.error(e);
            alert('Erro ao salvar alíquota');
        } finally {
            btn.disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initDates(30);
        document.getElementById('btn-filter-sales')?.addEventListener('click', () => loadSales(1));
        document.getElementById('btn-export-sales')?.addEventListener('click', exportSalesCsv);
        document.getElementById('btn-set-tax-9')?.addEventListener('click', applyDefaultTax9);
        document.getElementById('filter-search')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') loadSales(1);
        });
        document.querySelectorAll('.filter-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.filter-btn').forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');
                initDates(Number(btn.dataset.days || 30));
                loadSales(1);
            });
        });
        document.getElementById('sales-cards')?.addEventListener('click', async (e) => {
            const btn = e.target.closest('.sale-expand');
            if (!btn) return;
            const detail = document.getElementById(btn.dataset.detail);
            if (!detail) return;
            const opening = !detail.classList.contains('open');
            detail.classList.toggle('open');
            btn.setAttribute('aria-expanded', detail.classList.contains('open') ? 'true' : 'false');
            btn.querySelector('i')?.classList.toggle('bi-chevron-up', detail.classList.contains('open'));
            btn.querySelector('i')?.classList.toggle('bi-chevron-down', !detail.classList.contains('open'));
            if (opening && !detail.dataset.ledgerLoaded && btn.dataset.orderId) {
                detail.dataset.ledgerLoaded = '1';
                try {
                    const result = await requestJson(`/api/financials/orders/${encodeURIComponent(btn.dataset.orderId)}`);
                    const sale = result.data || result;
                    if (sale && !sale.error) {
                        const ledgerHtml = renderLedgerEntries(sale);
                        const discHtml = renderDiscrepancies(sale);
                        const mount = detail.querySelector('.ledger-mount') || detail;
                        if (!detail.querySelector('.ledger-mount')) {
                            const wrap = document.createElement('div');
                            wrap.className = 'ledger-mount';
                            detail.appendChild(wrap);
                            wrap.innerHTML = ledgerHtml + discHtml;
                        } else {
                            detail.querySelector('.ledger-mount').innerHTML = ledgerHtml + discHtml;
                        }
                        // Atualiza KPIs do detalhe se o ledger trouxe valores
                        if (sale.ledger_source === 'ledger') {
                            const cells = detail.querySelectorAll('.row.g-3.small .col-md-3');
                            // best-effort: não reescreve layout inteiro
                        }
                    }
                } catch (err) {
                    console.warn('ledger detail load failed', err);
                }
            }
        });
        document.getElementById('pagination')?.addEventListener('click', (e) => {
            const a = e.target.closest('a[data-page]');
            if (!a) return;
            e.preventDefault();
            const page = Number(a.dataset.page);
            const totalPages = Math.max(1, Math.ceil(Number(paging.total || 0) / perPage));
            if (page < 1 || page > totalPages) return;
            loadSales(page);
            window.scrollTo({ top: document.getElementById('sales-cards').offsetTop - 80, behavior: 'smooth' });
        });
        loadSales(1);
    });
})();
</script>
