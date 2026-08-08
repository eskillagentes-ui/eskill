<!-- Dashboard Opportunities View -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Oportunidades</h4>
        <p class="text-muted mb-0">Descubra produtos com alto potencial de vendas</p>
    </div>
    <button class="btn btn-primary btn-sm" onclick="scanOpportunities()">
        <i class="bi bi-search"></i> Buscar Oportunidades
    </button>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-gem me-2"></i>Oportunidades Encontradas</h6>
            </div>
            <div class="card-body" id="opportunitiesList">
                <div class="text-center py-5 text-muted" data-opportunities-state="idle">
                    <i class="bi bi-gem fs-1"></i>
                    <p class="mt-2 mb-1">Clique em "Buscar Oportunidades" para começar</p>
                    <p class="mb-0 small">Sugestões de preço e alertas aparecem após a busca (requer categorias / conta ML).</p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-filter me-2"></i>Filtros</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label small">Categoria</label>
                    <select class="form-select form-select-sm" id="categoryFilter">
                        <option value="">Selecione uma categoria</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Margem Mínima</label>
                    <input type="number" class="form-control form-control-sm" id="minMargin" value="20" min="0" max="100">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Vendas Mínimas/Mês</label>
                    <input type="number" class="form-control form-control-sm" id="minSales" value="10" min="0">
                </div>
                <button class="btn btn-primary w-100" onclick="scanOpportunities()">Aplicar Filtros</button>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-lightbulb me-2"></i>Dicas</h6>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0 small">
                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Busque produtos com alta demanda</li>
                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Analise a concorrência antes de investir</li>
                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Verifique a margem de lucro</li>
                    <li><i class="bi bi-check-circle text-success me-2"></i>Considere o tempo de entrega</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">

async function scanOpportunities() {
    const container = document.getElementById('opportunitiesList');
    container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2 mb-0">Buscando oportunidades...</p></div>';

    try {
        const category = document.getElementById('categoryFilter').value;
        const minMargin = document.getElementById('minMargin').value;
        const minSales = document.getElementById('minSales').value;

        if (!category) {
            container.innerHTML = '<div class="text-center py-5 text-muted" data-opportunities-state="empty">'
                + '<i class="bi bi-funnel fs-1"></i>'
                + '<p class="mt-2 mb-1 fw-semibold">Selecione uma categoria</p>'
                + '<p class="mb-0 small">Escolha uma categoria no filtro à direita para buscar oportunidades.</p>'
                + '</div>';
            return;
        }

        const data = await requestJson(`/api/opportunities/scan?category=${encodeURIComponent(category)}&min_margin=${encodeURIComponent(minMargin)}&min_sales=${encodeURIComponent(minSales)}`);

        if (data.opportunities && data.opportunities.length > 0) {
            container.innerHTML = data.opportunities.map(opp => `
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1">${opp.title}</h6>
                            <p class="text-muted small mb-2">${opp.category_name}</p>
                            <div class="d-flex gap-3 small">
                                <span class="text-success"><i class="bi bi-graph-up"></i> ${opp.estimated_sales}/mês</span>
                                <span class="text-primary"><i class="bi bi-cash"></i> Margem: ${opp.margin}%</span>
                                <span class="text-warning"><i class="bi bi-people"></i> ${opp.competitors} concorrentes</span>
                            </div>
                        </div>
                        <a href="/research?q=${encodeURIComponent(opp.title)}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-search"></i> Pesquisar
                        </a>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = '<div class="text-center py-5 text-muted" data-opportunities-state="empty">'
                + '<i class="bi bi-inbox fs-1"></i>'
                + '<p class="mt-2 mb-1 fw-semibold">Nenhuma oportunidade / sugestão de preço encontrada</p>'
                + '<p class="mb-0 small">Selecione uma categoria válida ou conecte uma conta ML ativa.</p>'
                + '</div>';
        }
    } catch (e) {
        container.innerHTML = '<div class="text-center py-5 text-muted" data-opportunities-state="error">'
            + '<i class="bi bi-exclamation-triangle fs-1 text-warning"></i>'
            + '<p class="mt-2 mb-1 fw-semibold">Não foi possível buscar oportunidades</p>'
            + '<p class="mb-0 small">Verifique se há conta Mercado Livre conectada e tente novamente.</p>'
            + '</div>';
    }
}

// Load categories (envelope {categories:[]} — fallback local se ML PolicyAgent bloquear)
requestJson('/api/categories').then(data => {
    const select = document.getElementById('categoryFilter');
    if (!select) return;
    let cats = [];
    if (Array.isArray(data)) {
        cats = data;
    } else if (Array.isArray(data?.categories)) {
        cats = data.categories;
    } else if (Array.isArray(data?.data?.categories)) {
        cats = data.data.categories;
    } else if (Array.isArray(data?.data)) {
        cats = data.data;
    }
    if (!cats.length) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'Nenhuma categoria disponível (conecte conta ML)';
        select.innerHTML = '';
        select.appendChild(option);
        return;
    }
    select.innerHTML = '<option value="">Selecione uma categoria</option>';
    cats.forEach(cat => {
        if (!cat || cat.id == null) return;
        const option = document.createElement('option');
        option.value = cat.id;
        option.textContent = cat.name || cat.id;
        select.appendChild(option);
    });
}).catch(() => {
    const select = document.getElementById('categoryFilter');
    if (!select) return;
    select.innerHTML = '<option value="">Nenhuma categoria disponível (conecte conta ML)</option>';
});
</script>
