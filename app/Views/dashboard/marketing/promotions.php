<?php

declare(strict_types=1);

$title = 'Central de Promoções';
$subtitle = 'Gerencie suas ofertas, cupons e campanhas do Mercado Livre';
include __DIR__ . '/../../layouts/modern/partials/page-header.php';
?>

<!-- Nav Tabs -->
<ul class="nav nav-tabs mb-4" id="promoTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-promotions" type="button">
            <i class="bi bi-tag-fill me-1"></i> Promoções
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-coupons" type="button">
            <i class="bi bi-ticket-perforated me-1"></i> Cupons
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-performance" type="button">
            <i class="bi bi-graph-up me-1"></i> Análise
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-suggested" type="button">
            <i class="bi bi-lightbulb me-1"></i> Sugestões IA
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- TAB: Promoções -->
    <div class="tab-pane fade show active" id="tab-promotions">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary"><i class="bi bi-tag-fill me-1"></i> Promoções Disponíveis</h5>
                <button class="btn btn-sm btn-outline-secondary" onclick="promoManager.loadPromotions()">
                    <i class="bi bi-arrow-clockwise"></i> Atualizar
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Nome da Campanha</th>
                                <th>Tipo</th>
                                <th>Prazo de Inscrição</th>
                                <th>Itens Elegíveis</th>
                                <th class="text-end pe-4">Ação</th>
                            </tr>
                        </thead>
                        <tbody id="promo-list">
                            <tr><td colspan="5" class="text-center py-4"><div class="spinner-border text-primary"></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB: Cupons -->
    <div class="tab-pane fade" id="tab-coupons">
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between">
                        <h6 class="mb-0 fw-semibold"><i class="bi bi-ticket-perforated me-1 text-warning"></i> Meus Cupons</h6>
                        <button class="btn btn-sm btn-outline-secondary" onclick="couponManager.loadList()">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div id="coupon-list" class="list-group list-group-flush">
                            <div class="list-group-item text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-semibold"><i class="bi bi-plus-circle me-1 text-success"></i> Criar Novo Cupom</h6>
                    </div>
                    <div class="card-body">
                        <form id="createCouponForm" onsubmit="couponManager.create(event)">
                            <div class="mb-3">
                                <label class="form-label small text-muted">Desconto (%)</label>
                                <input type="number" name="discount_percentage" class="form-control" min="5" max="50" step="1" required placeholder="Ex: 10">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small text-muted">Vigência</label>
                                <div class="d-flex gap-2">
                                    <input type="date" name="start_date" class="form-control" required>
                                    <input type="date" name="end_date" class="form-control" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small text-muted">Uso máximo</label>
                                <input type="number" name="max_uses" class="form-control" min="1" placeholder="Ilimitado">
                            </div>
                            <div id="couponFormFeedback" class="d-none alert py-2"></div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-plus-circle me-1"></i> Criar Cupom
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB: Análise de Performance -->
    <div class="tab-pane fade" id="tab-performance">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 fw-semibold">Performance de Promoções</h6>
            <div class="d-flex gap-2">
                <select class="form-select form-select-sm" id="performancePeriod" onchange="performanceManager.load()">
                    <option value="7">7 dias</option>
                    <option value="30" selected>30 dias</option>
                    <option value="90">90 dias</option>
                </select>
                <button class="btn btn-sm btn-outline-secondary" onclick="performanceManager.load()">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
        </div>
        <div id="performance-container">
            <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
        </div>
    </div>

    <!-- TAB: Sugestões IA -->
    <div class="tab-pane fade" id="tab-suggested">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h6 class="mb-0 fw-semibold">Itens Sugeridos para Promoção</h6>
                <small class="text-muted">Recomendações com base em estoque, margem e histórico de vendas</small>
            </div>
            <button class="btn btn-sm btn-outline-primary" onclick="suggestManager.load()">
                <i class="bi bi-cpu me-1"></i> Atualizar Sugestões
            </button>
        </div>
        <div id="suggested-container">
            <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
        </div>
    </div>

</div>

<!-- Modal: Items da Promoção -->
<div class="modal fade" id="itemsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Itens Elegíveis</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Item</th>
                                <th>Preço Atual</th>
                                <th class="text-success">Preço Sugerido</th>
                                <th class="text-end pe-4">Ação</th>
                            </tr>
                        </thead>
                        <tbody id="promo-items-list"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
const money = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val || 0);

function escHtml(v) {
    const d = document.createElement('div');
    d.textContent = String(v ?? '');
    return d.innerHTML;
}

// ========================
// PROMOÇÕES
// ========================
const promoManager = {
    currentPromoId: null,

    init() { this.loadPromotions(); },

    async loadPromotions() {
        try {
            const data = await requestJson('/api/marketing/promotions');
            if (data.success) this.render(data.promotions || []);
        } catch (e) {
            document.getElementById('promo-list').innerHTML =
                '<tr><td colspan="5" class="text-center text-danger">Erro ao carregar promoções</td></tr>';
        }
    },

    render(promotions) {
        const container = document.getElementById('promo-list');
        if (!promotions.length) {
            container.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Nenhuma promoção disponível no momento.</td></tr>';
            return;
        }
        container.innerHTML = promotions.map(promo => {
            const deadline = promo.deadline_date
                ? new Date(promo.deadline_date).toLocaleString('pt-BR', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' })
                : '—';
            const colors = { DEAL_OF_THE_DAY:'bg-warning text-dark', LIGHTNING:'bg-danger', MARKETPLACE_CAMPAIGN:'bg-primary' };
            const badge = colors[promo.type] || 'bg-secondary';
            return `<tr>
                <td class="ps-4"><div class="fw-bold">${escHtml(promo.name)}</div><small class="text-muted">ID: ${escHtml(promo.id)}</small></td>
                <td><span class="badge ${badge}">${escHtml(promo.type)}</span></td>
                <td>${escHtml(deadline)}</td>
                <td>
                    <span class="badge bg-light text-dark border">${promo.items_eligible_count ?? 0} elegíveis</span>
                    <span class="badge bg-success bg-opacity-10 text-success">${promo.items_joined_count ?? 0} participando</span>
                </td>
                <td class="text-end pe-4">
                    <button class="btn btn-sm btn-outline-primary" onclick="promoManager.viewItems('${escHtml(promo.id)}')">Ver Itens</button>
                </td>
            </tr>`;
        }).join('');
    },

    async viewItems(promoId) {
        this.currentPromoId = promoId;
        const container = document.getElementById('promo-items-list');
        container.innerHTML = '<tr><td colspan="4" class="text-center py-4"><div class="spinner-border text-primary"></div></td></tr>';
        new bootstrap.Modal(document.getElementById('itemsModal')).show();
        try {
            const data = await requestJson('/api/marketing/promotions/items?id=' + encodeURIComponent(promoId));
            if (data.success) this.renderItems(data.items || []);
        } catch (e) {
            container.innerHTML = '<tr><td colspan="4" class="text-center text-danger">Erro ao carregar itens.</td></tr>';
        }
    },

    renderItems(items) {
        const container = document.getElementById('promo-items-list');
        if (!items.length) {
            container.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Nenhum item elegível.</td></tr>';
            return;
        }
        container.innerHTML = items.map(item => `
            <tr>
                <td class="ps-4"><div class="fw-bold">${escHtml(item.title)}</div><small class="text-muted">${escHtml(item.id)}</small></td>
                <td><span class="text-muted text-decoration-line-through">${money(item.price)}</span></td>
                <td class="text-success fw-bold">${money(item.suggested_price || item.promotion_price)}</td>
                <td class="text-end pe-4">
                    ${item.status === 'joined'
                        ? '<span class="badge bg-success">Participando</span>'
                        : `<button class="btn btn-sm btn-success" onclick="promoManager.join('${escHtml(item.id)}', ${Number(item.suggested_price) || 0})">Participar</button>`
                    }
                </td>
            </tr>`).join('');
    },

    async join(itemId, price) {
        if (!confirm('Confirmar participação com o preço sugerido?')) return;
        try {
            const result = await requestJson('/api/marketing/promotions/join', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ promotion_id: this.currentPromoId, items: [{ item_id: itemId, price }] })
            });
            if (result.success) {
                this.viewItems(this.currentPromoId);
            } else {
                alert('Erro: ' + (result.error || 'Falha ao participar'));
            }
        } catch (e) {
            alert('Erro ao participar da promoção');
        }
    }
};

// ========================
// CUPONS
// ========================
const couponManager = {
    async loadList() {
        const container = document.getElementById('coupon-list');
        container.innerHTML = '<div class="list-group-item text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>';
        try {
            const data = await requestJson('/api/marketing/coupons');
            const coupons = data.coupons || data.data || [];
            if (!coupons.length) {
                container.innerHTML = '<div class="list-group-item text-center text-muted py-4">Nenhum cupom criado.</div>';
                return;
            }
            container.innerHTML = coupons.map(c => {
                const statusColors = { active: 'success', paused: 'warning', finished: 'secondary' };
                const sc = statusColors[c.status] || 'secondary';
                return `<div class="list-group-item list-group-item-action">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold">${escHtml(c.code || c.id)}</div>
                            <small class="text-muted">${escHtml(c.discount_percentage ?? c.discount ?? 0)}% desconto</small>
                        </div>
                        <div class="d-flex gap-1 align-items-center">
                            <span class="badge bg-${sc}">${escHtml(c.status)}</span>
                            ${c.status === 'active'
                                ? `<button class="btn btn-xs btn-outline-warning py-0 px-1" onclick="couponManager.setStatus('${escHtml(c.id)}','paused')" title="Pausar"><i class="bi bi-pause"></i></button>`
                                : `<button class="btn btn-xs btn-outline-success py-0 px-1" onclick="couponManager.setStatus('${escHtml(c.id)}','active')" title="Ativar"><i class="bi bi-play"></i></button>`
                            }
                        </div>
                    </div>
                    <small class="text-muted">Usos: ${c.used_count ?? 0}/${c.max_uses ?? '∞'} · Válido até: ${c.end_date ? new Date(c.end_date).toLocaleDateString('pt-BR') : '—'}</small>
                </div>`;
            }).join('');
        } catch (e) {
            container.innerHTML = '<div class="list-group-item text-danger">Erro ao carregar cupons.</div>';
        }
    },

    async create(event) {
        event.preventDefault();
        const form = event.target;
        const feedback = document.getElementById('couponFormFeedback');
        const btn = form.querySelector('button[type=submit]');
        const fd = new FormData(form);

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Criando...';
        feedback.classList.add('d-none');

        const payload = {
            discount_percentage: parseFloat(fd.get('discount_percentage')),
            start_date: fd.get('start_date'),
            end_date: fd.get('end_date'),
            max_uses: fd.get('max_uses') ? parseInt(fd.get('max_uses'), 10) : null,
        };

        try {
            const result = await requestJson('/api/marketing/coupons', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (result.success !== false) {
                feedback.className = 'alert alert-success py-2';
                feedback.textContent = 'Cupom criado: ' + (result.code || result.coupon?.code || 'OK');
                feedback.classList.remove('d-none');
                form.reset();
                this.loadList();
            } else {
                feedback.className = 'alert alert-danger py-2';
                feedback.textContent = result.error || 'Erro ao criar cupom';
                feedback.classList.remove('d-none');
            }
        } catch (e) {
            feedback.className = 'alert alert-danger py-2';
            feedback.textContent = 'Erro ao criar cupom.';
            feedback.classList.remove('d-none');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plus-circle me-1"></i> Criar Cupom';
        }
    },

    async setStatus(couponId, status) {
        try {
            await requestJson(`/api/marketing/coupons/${couponId}/status`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status }),
            });
            this.loadList();
        } catch (e) {
            alert('Erro ao atualizar status do cupom');
        }
    }
};

// ========================
// ANÁLISE DE PERFORMANCE
// ========================
const performanceManager = {
    async load() {
        const period = document.getElementById('performancePeriod')?.value || 30;
        const container = document.getElementById('performance-container');
        container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
        try {
            const data = await requestJson(`/api/marketing/promotions/performance?period=${period}`);
            const d = data.data || data;
            container.innerHTML = `
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm text-center p-3">
                            <div class="text-muted small">Receita com Promoções</div>
                            <div class="h4 fw-bold text-primary mt-1">${money(d.revenue_from_promotions)}</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm text-center p-3">
                            <div class="text-muted small">Vendas Promovidas</div>
                            <div class="h4 fw-bold text-success mt-1">${d.sales_with_promotions ?? 0}</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm text-center p-3">
                            <div class="text-muted small">Desconto Aplicado</div>
                            <div class="h4 fw-bold text-warning mt-1">${money(d.total_discount_given)}</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm text-center p-3">
                            <div class="text-muted small">ROI Estimado</div>
                            <div class="h4 fw-bold text-info mt-1">${d.estimated_roi ?? '—'}%</div>
                        </div>
                    </div>
                </div>
                ${d.top_promotions?.length ? `
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold">Top Promoções</h6></div>
                    <div class="card-body p-0">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th class="ps-3">Promoção</th><th>Vendas</th><th>Receita</th><th>ROI</th></tr></thead>
                            <tbody>${(d.top_promotions || []).map(p => `
                                <tr>
                                    <td class="ps-3">${escHtml(p.name || p.promotion_id)}</td>
                                    <td>${p.sales ?? 0}</td>
                                    <td>${money(p.revenue)}</td>
                                    <td><span class="badge ${(p.roi ?? 0) >= 0 ? 'bg-success' : 'bg-danger'}">${p.roi ?? 0}%</span></td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>` : '<p class="text-muted text-center py-4">Sem dados de performance para o período selecionado.</p>'}
            `;
        } catch (e) {
            container.innerHTML = '<p class="text-center text-danger py-5">Erro ao carregar análise de performance.</p>';
        }
    }
};

// ========================
// SUGESTÕES IA
// ========================
const suggestManager = {
    async load() {
        const container = document.getElementById('suggested-container');
        container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
        try {
            const data = await requestJson('/api/marketing/promotions/suggested-items?limit=20');
            const items = data.items || data.data || [];
            if (!items.length) {
                container.innerHTML = '<p class="text-center text-muted py-5">Nenhuma sugestão disponível no momento.</p>';
                return;
            }
            container.innerHTML = `
                <div class="row g-3">
                    ${items.map(item => `
                    <div class="col-md-4 col-xl-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="fw-semibold small mb-1">${escHtml(item.title || item.id)}</div>
                                <div class="text-muted small mb-2">${escHtml(item.category_name || item.category_id || '—')}</div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-primary">${money(item.price)}</span>
                                    <span class="badge bg-light text-dark border">Estoque: ${item.available_quantity ?? '—'}</span>
                                </div>
                                ${item.suggested_discount ? `<div class="mt-1 small text-success"><i class="bi bi-arrow-down me-1"></i>Desconto sugerido: ${item.suggested_discount}%</div>` : ''}
                            </div>
                            <div class="card-footer bg-transparent border-0 pt-0">
                                <button class="btn btn-sm btn-outline-primary w-100" onclick="promoManager.viewItems('${escHtml(item.id ?? '')}')">Ver Promoções</button>
                            </div>
                        </div>
                    </div>`).join('')}
                </div>`;
        } catch (e) {
            container.innerHTML = '<p class="text-center text-danger py-5">Erro ao carregar sugestões.</p>';
        }
    }
};

// Init
document.addEventListener('DOMContentLoaded', () => {
    promoManager.init();

    document.querySelector('[data-bs-target="#tab-coupons"]').addEventListener('shown.bs.tab', () => {
        couponManager.loadList();
    });
    document.querySelector('[data-bs-target="#tab-performance"]').addEventListener('shown.bs.tab', () => {
        performanceManager.load();
    });
    document.querySelector('[data-bs-target="#tab-suggested"]').addEventListener('shown.bs.tab', () => {
        suggestManager.load();
    });
});
</script>
