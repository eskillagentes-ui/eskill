<!-- Dashboard Competitors View -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Análise de Concorrência</h4>
        <p class="text-muted mb-0">Monitore seus concorrentes e identifique estratégias</p>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCompetitorModal">
        <i class="bi bi-plus-lg"></i> Adicionar Concorrente
    </button>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card h-100 text-center">
            <div class="card-body">
                <i class="bi bi-people fs-1 text-primary"></i>
                <h3 class="mt-2 mb-1" id="totalCompetitors">0</h3>
                <p class="text-muted mb-0">Concorrentes Monitorados</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 text-center">
            <div class="card-body">
                <i class="bi bi-box-seam fs-1 text-success"></i>
                <h3 class="mt-2 mb-1" id="totalProducts">0</h3>
                <p class="text-muted mb-0">Produtos Rastreados</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 text-center">
            <div class="card-body">
                <i class="bi bi-bell fs-1 text-warning"></i>
                <h3 class="mt-2 mb-1" id="priceAlerts">0</h3>
                <p class="text-muted mb-0">Alertas de Preço</p>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm" id="price-suggestions-card">
            <div class="card-header bg-transparent">
                <h6 class="mb-0"><i class="bi bi-tags me-2"></i>Sugestões de Preço</h6>
            </div>
            <div class="card-body" id="priceSuggestions">
                <div class="text-center py-4 text-muted" data-suggestions-state="empty">
                    <i class="bi bi-tag fs-1 d-block mb-2"></i>
                    <p class="mb-1 fw-semibold">Nenhuma sugestão de preço disponível</p>
                    <p class="mb-0 small">Cadastre concorrentes para gerar sugestões e alertas de preço.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-people me-2"></i>Concorrentes</h6>
        <button class="btn btn-sm btn-outline-primary" onclick="loadCompetitors()">
            <i class="bi bi-arrow-clockwise"></i> Atualizar
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Vendedor</th>
                        <th class="text-center">Produtos</th>
                        <th class="text-center">Reputação</th>
                        <th class="text-center">Vendas/Mês</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody id="competitorsTable">
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">Carregando...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Competitor Modal -->
<div class="modal fade" id="addCompetitorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adicionar Concorrente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">ID do Vendedor ou Link do Anúncio</label>
                    <input type="text" class="form-control" id="competitorInput" placeholder="Ex: 123456789 ou https://...">
                    <small class="text-muted">Cole o link de qualquer anúncio do vendedor ou o ID dele</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="addCompetitor()">Adicionar</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
    async function loadCompetitors() {
        try {
            const data = await requestJson('/api/competitors');

            document.getElementById('totalCompetitors').textContent = data.total || 0;
            document.getElementById('totalProducts').textContent = data.total_products || 0;
            document.getElementById('priceAlerts').textContent = data.price_alerts || 0;

            const suggestions = document.getElementById('priceSuggestions');
            const tbody = document.getElementById('competitorsTable');
            if (!data.competitors || data.competitors.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted"><i class="bi bi-people fs-1 d-block mb-2"></i>Nenhum concorrente cadastrado</td></tr>';
                if (suggestions) {
                    suggestions.innerHTML = '<div class="text-center py-4 text-muted" data-suggestions-state="empty">'
                        + '<i class="bi bi-tag fs-1 d-block mb-2"></i>'
                        + '<p class="mb-1 fw-semibold">Nenhuma sugestão de preço disponível</p>'
                        + '<p class="mb-0 small">Cadastre concorrentes para gerar sugestões e alertas de preço.</p>'
                        + '</div>';
                }
                return;
            }

            if (suggestions) {
                const alerts = Number(data.price_alerts || 0);
                suggestions.innerHTML = alerts > 0
                    ? `<div data-suggestions-state="ready"><p class="mb-0">${alerts} alerta(s) de preço ativos para os concorrentes monitorados.</p></div>`
                    : '<div class="text-muted" data-suggestions-state="ready-empty"><p class="mb-0 fw-semibold">Sugestões de Preço</p><p class="mb-0 small">Nenhum alerta de preço no momento — monitoramento ativo sem divergências.</p></div>';
            }

            tbody.innerHTML = data.competitors.map(c => `
            <tr>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-2" style="width:40px;height:40px">
                            ${c.nickname?.charAt(0).toUpperCase() || '?'}
                        </div>
                        <div>
                            <strong>${c.nickname || 'Vendedor'}</strong>
                            <br><small class="text-muted">ID: ${c.seller_id}</small>
                        </div>
                    </div>
                </td>
                <td class="text-center">${c.total_items || 0}</td>
                <td class="text-center">
                    <span class="badge bg-${c.reputation_level === 'platinum' ? 'primary' : c.reputation_level === 'gold' ? 'warning' : 'secondary'}">
                        ${c.reputation_level || 'N/A'}
                    </span>
                </td>
                <td class="text-center">${c.sales_completed || 0}</td>
                <td class="text-end">
                    <div class="btn-group btn-group-sm">
                        <a href="/competitors/${c.seller_id}" class="btn btn-outline-primary" title="Ver detalhes">
                            <i class="bi bi-eye"></i>
                        </a>
                        <button class="btn btn-outline-danger" onclick="removeCompetitor(${c.seller_id})" title="Remover">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
        } catch (e) {
            document.getElementById('competitorsTable').innerHTML = '<tr><td colspan="5" class="text-center py-5 text-danger">Erro ao carregar</td></tr>';
        }
    }

    async function addCompetitor() {
        const input = document.getElementById('competitorInput').value.trim();
        if (!input) return alert('Digite o ID ou link do vendedor');

        try {
            await requestJson('/api/competitors', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    input
                })
            });

            bootstrap.Modal.getInstance(document.getElementById('addCompetitorModal')).hide();
            document.getElementById('competitorInput').value = '';
            loadCompetitors();
        } catch (e) {
            alert('Erro ao adicionar concorrente');
        }
    }

    async function removeCompetitor(sellerId) {
        if (!confirm('Remover este concorrente?')) return;

        try {
            await requestJson(`/api/competitors/${sellerId}`, {
                method: 'DELETE'
            });
            loadCompetitors();
        } catch (e) {
            alert('Erro ao remover');
        }
    }

    loadCompetitors();
</script>
