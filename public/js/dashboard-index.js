'use strict';
document.addEventListener('DOMContentLoaded', function () {
    // Load Dashboard Data
    loadMetrics();
    loadAccounts();
    loadOrders();
    initCharts();
    startAccountPolling();
});

const accountsState = {
    accounts: [],
    activeId: null,
    tokenStatus: {},
    syncStatus: {},
    pendingId: null,
    pollTimer: null,
    pollInterval: 30000
};

// Load Metrics
async function loadMetrics() {
    try {
        const metrics = await requestJson('/api/dashboard/metrics');

        const statsGrid = document.getElementById('stats-grid');
        statsGrid.innerHTML = `
            <div class="metric-card" data-color="primary">
                <div class="metric-header">
                    <div class="metric-icon primary">
                        <i class="bi bi-shop"></i>
                    </div>
                    ${metrics.accounts_growth ? `
                    <span class="metric-change ${metrics.accounts_growth >= 0 ? 'up' : 'down'}">
                        <i class="bi bi-arrow-${metrics.accounts_growth >= 0 ? 'up' : 'down'}"></i>
                        ${Math.abs(metrics.accounts_growth)}%
                    </span>` : ''}
                </div>
                <div class="metric-value">${metrics.active_accounts || 0}</div>
                <div class="metric-label">Contas Ativas</div>
            </div>

            <div class="metric-card" data-color="info">
                <div class="metric-header">
                    <div class="metric-icon info">
                        <i class="bi bi-cart-check"></i>
                    </div>
                    ${metrics.orders_growth ? `
                    <span class="metric-change ${metrics.orders_growth >= 0 ? 'up' : 'down'}">
                        <i class="bi bi-arrow-${metrics.orders_growth >= 0 ? 'up' : 'down'}"></i>
                        ${Math.abs(metrics.orders_growth)}%
                    </span>` : ''}
                </div>
                <div class="metric-value">${metrics.recent_orders || 0}</div>
                <div class="metric-label">Pedidos (30 dias)</div>
            </div>

            <div class="metric-card" data-color="success">
                <div class="metric-header">
                    <div class="metric-icon success">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                    ${metrics.revenue_growth ? `
                    <span class="metric-change ${metrics.revenue_growth >= 0 ? 'up' : 'down'}">
                        <i class="bi bi-arrow-${metrics.revenue_growth >= 0 ? 'up' : 'down'}"></i>
                        ${Math.abs(metrics.revenue_growth)}%
                    </span>` : ''}
                </div>
                <div class="metric-value">R$ ${formatNumber(metrics.total_revenue || 0)}</div>
                <div class="metric-label">Receita Total</div>
            </div>

            <div class="metric-card" data-color="warning">
                <div class="metric-header">
                    <div class="metric-icon warning">
                        <i class="bi bi-cash-coin"></i>
                    </div>
                    ${metrics.profit_growth ? `
                    <span class="metric-change ${metrics.profit_growth >= 0 ? 'up' : 'down'}">
                        <i class="bi bi-arrow-${metrics.profit_growth >= 0 ? 'up' : 'down'}"></i>
                        ${Math.abs(metrics.profit_growth)}%
                    </span>` : ''}
                </div>
                <div class="metric-value">R$ ${formatNumber(metrics.net_profit || 0)}</div>
                <div class="metric-label">Lucro Líquido</div>
            </div>
        `;

        // Update reputation
        if (metrics.reputation_metrics) {
            updateReputation(metrics.reputation_metrics);
        }

        // Update orders chart
        if (metrics.orders_by_status) {
            updateOrdersChart(metrics.orders_by_status);
        }

        // Update revenue chart (Onda 2 / T4: gráfico ficava sempre zerado
        // porque nada nunca chamava updateRevenueChart com dados reais)
        updateRevenueChart(metrics.sales_over_time);

    } catch (error) {
        console.error('Error loading metrics:', error);
        const statsGrid = document.getElementById('stats-grid');
        if (statsGrid) {
            statsGrid.innerHTML = `
                <div class="text-center py-4" style="grid-column: 1 / -1;">
                    <i class="bi bi-exclamation-triangle text-warning" style="font-size: 2rem;"></i>
                    <p class="text-muted mt-2 mb-0">Falha ao carregar métricas. <a href="javascript:location.reload()">Tentar novamente</a></p>
                </div>
            `;
        }
    }
}

// Load Accounts
async function loadAccounts(options = {}) {
    const {
        silent = false
    } = options;
    const grid = document.getElementById('accounts-grid');

    try {
        const data = await requestJson('/api/dashboard/accounts');
        const accounts = Array.isArray(data) ? data : (data.accounts || []);
        accountsState.accounts = accounts;
        accountsState.activeId = data.active_account_id ?? null;

        if (!accounts || accounts.length === 0) {
            grid.innerHTML = `
                <div class="empty-state-card" style="grid-column: 1 / -1;">
                    <div class="empty-state-icon">
                        <i class="bi bi-person-plus"></i>
                    </div>
                    <h3 class="empty-state-title">Nenhuma conta conectada</h3>
                    <p class="empty-state-desc">Conecte sua conta do Mercado Livre para começar a gerenciar suas vendas</p>
                    <a href="/auth/authorize" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-2"></i>
                        Conectar Conta
                    </a>
                </div>
            `;
            return;
        }

        await refreshTokenStatuses();
        await refreshSyncStatuses();

        renderAccounts();
    } catch (error) {
        if (!silent) {
            grid.innerHTML = `
                <div class="empty-state-card" style="grid-column: 1 / -1;">
                    <div class="empty-state-icon">
                        <i class="bi bi-wifi-off"></i>
                    </div>
                    <h3 class="empty-state-title">Erro ao carregar contas</h3>
                    <p class="empty-state-desc">${escapeHtml(error.message || 'Falha de conexão com o servidor')}</p>
                    <button class="btn btn-outline-primary" data-action="retry-load-accounts">
                        <i class="bi bi-arrow-repeat me-2"></i>
                        Tentar novamente
                    </button>
                </div>
            `;
        }
        Toast.error('Não foi possível atualizar as contas');
    }
}

function renderAccounts() {
    const grid = document.getElementById('accounts-grid');
    grid.innerHTML = accountsState.accounts.map(account => {
        const tokenStatus = accountsState.tokenStatus[account.id] || {
            token_status: 'unknown'
        };
        const syncStatus = accountsState.syncStatus[account.id] || {};
        const active = accountsState.activeId === account.id;
        const pending = accountsState.pendingId === account.id;
        const syncCapability = getAccountSyncCapability(account, tokenStatus, syncStatus);
        const syncButtonTitle = escapeHtml(syncCapability.reason || '');
        const syncButtonDisabledAttr = syncCapability.disabled ? 'disabled aria-disabled="true"' : '';

        const connectionMeta = getConnectionMeta(account, tokenStatus);
        const syncMeta = getSyncMeta(syncStatus, account.id);
        const lastSyncLabel = syncStatus.last_synced_at ? formatDate(syncStatus.last_synced_at) : 'N/A';

        return `
            <div class="account-card ${active ? 'active' : ''} ${pending ? 'pending' : ''}" data-action="select-account" data-account-id="${account.id}">
                <div class="account-header">
                    <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(account.nickname || 'U')}&background=667eea&color=fff&size=96"
                         alt="${escapeHtml(account.nickname || '')}"
                         class="account-avatar">
                    <div class="account-info">
                        <div class="account-name">${escapeHtml(account.nickname || 'Sem nome')}</div>
                        <div class="account-email">${escapeHtml(account.email || '')}</div>
                        <div class="account-badges">
                            <span class="account-badge ${connectionMeta.class}">
                                <i class="bi ${connectionMeta.icon}"></i>
                                ${connectionMeta.label}
                            </span>
                            <span class="account-badge ${syncMeta.class}">
                                <i class="bi ${syncMeta.icon}"></i>
                                ${syncMeta.label}
                            </span>
                        </div>
                    </div>
                    <span class="account-status ${active ? 'active' : 'inactive'}">
                        <i class="bi bi-circle-fill" style="font-size: 0.5rem;"></i>
                        ${active ? 'Ativa' : 'Inativa'}
                    </span>
                </div>
                <div class="account-metrics">
                    <div class="account-metric">
                        <div class="account-metric-value">${syncStatus.items_count ?? 0}</div>
                        <div class="account-metric-label">Produtos</div>
                    </div>
                    <div class="account-metric">
                        <div class="account-metric-value">${lastSyncLabel}</div>
                        <div class="account-metric-label">Último Sync</div>
                    </div>
                    <div class="account-metric">
                        <div class="account-metric-value">${connectionMeta.tokenLabel}</div>
                        <div class="account-metric-label">Token</div>
                    </div>
                </div>
                <div class="account-actions">
                    <button class="btn ${syncCapability.buttonClass} btn-sm" data-action="${syncCapability.action}" data-account-id="${account.id}" title="${syncButtonTitle}" ${syncButtonDisabledAttr}>
                        <i class="bi ${syncCapability.icon} me-1"></i>
                        ${syncCapability.label}
                    </button>
                    <button class="btn btn-outline-danger btn-sm" data-action="unlink-account" data-account-id="${account.id}">
                        <i class="bi bi-link-45deg me-1"></i>
                        Desvincular
                    </button>
                </div>
            </div>
        `;
    }).join('');
}

function getAccountSyncCapability(account, tokenStatus, syncStatus) {
    const accountStatus = String(account?.status || '').toLowerCase();
    const tokenState = String(tokenStatus?.token_status || '').toLowerCase();
    const syncTokenState = String(syncStatus?.token_status?.status || '').toLowerCase();

    if (accountsState.pendingId === account.id) {
        return {
            action: 'sync-account',
            label: 'Aguarde',
            icon: 'bi-hourglass-split',
            buttonClass: 'btn-outline-secondary',
            disabled: true,
            reason: 'Troca de conta em andamento.'
        };
    }

    if (['disconnected', 'inactive', 'expired'].includes(accountStatus)) {
        return {
            action: 'reconnect-account',
            label: 'Reconectar',
            icon: 'bi-link-45deg',
            buttonClass: 'btn-outline-warning',
            disabled: false,
            reason: 'Conta desconectada ou inativa. Reconecte antes de sincronizar.'
        };
    }

    if (
        tokenState === 'expired' ||
        tokenState === 'invalid' ||
        syncTokenState === 'expired' ||
        syncStatus?.can_sync === false
    ) {
        return {
            action: 'reconnect-account',
            label: 'Reconectar',
            icon: 'bi-link-45deg',
            buttonClass: 'btn-outline-warning',
            disabled: false,
            reason: 'Token expirado ou inválido. Reconecte a conta.'
        };
    }

    return {
        action: 'sync-account',
        label: 'Sincronizar',
        icon: 'bi-arrow-repeat',
        buttonClass: 'btn-outline-primary',
        disabled: false,
        reason: 'Sincronizar itens, pedidos e perguntas.'
    };
}

function getConnectionMeta(account, tokenStatus) {
    const tokenState = tokenStatus.token_status || 'unknown';
    if (accountsState.pendingId === account.id) {
        return {
            label: 'Ativando',
            class: 'badge-syncing',
            icon: 'bi-arrow-repeat',
            tokenLabel: '...'
        };
    }
    if (tokenState === 'valid') {
        return {
            label: 'Conectada',
            class: 'badge-connected',
            icon: 'bi-check-circle',
            tokenLabel: 'Válido'
        };
    }
    if (tokenState === 'expiring_soon') {
        return {
            label: 'Expirando',
            class: 'badge-expiring',
            icon: 'bi-exclamation-triangle',
            tokenLabel: 'Expirando'
        };
    }
    if (tokenState === 'expired') {
        return {
            label: 'Expirada',
            class: 'badge-disconnected',
            icon: 'bi-x-circle',
            tokenLabel: 'Expirado'
        };
    }
    return {
        label: 'Desconhecida',
        class: 'badge-unknown',
        icon: 'bi-question-circle',
        tokenLabel: 'N/A'
    };
}

function getSyncMeta(syncStatus, accountId) {
    if (accountsState.pendingId && accountsState.pendingId === accountId) {
        return {
            label: 'Verificando',
            class: 'badge-syncing',
            icon: 'bi-arrow-repeat'
        };
    }
    if (syncStatus.needs_sync === false) {
        return {
            label: 'Sincronizada',
            class: 'badge-synced',
            icon: 'bi-check-circle'
        };
    }
    if (syncStatus.needs_sync === true) {
        return {
            label: 'Desincronizada',
            class: 'badge-unsynced',
            icon: 'bi-x-circle'
        };
    }
    return {
        label: 'Sem Sync',
        class: 'badge-unknown',
        icon: 'bi-dash-circle'
    };
}

async function refreshTokenStatuses() {
    try {
        const data = await requestJson('/api/multi-account/tokens/status');
        const statuses = data.accounts || [];
        accountsState.tokenStatus = statuses.reduce((acc, item) => {
            acc[item.id] = item;
            return acc;
        }, {});
    } catch (error) {
        accountsState.tokenStatus = {};
        Toast.warning('Não foi possível validar o status dos tokens');
    }
}

async function refreshSyncStatuses() {
    const syncRequests = accountsState.accounts.map(account => {
        return requestJson(`/api/accounts/${account.id}/sync/status`)
            .then(data => ({
                id: account.id,
                status: data.data || {}
            }))
            .catch(() => ({
                id: account.id,
                status: {}
            }));
    });

    const results = await Promise.all(syncRequests);
    accountsState.syncStatus = results.reduce((acc, item) => {
        acc[item.id] = item.status;
        return acc;
    }, {});
}

function startAccountPolling() {
    if (accountsState.pollTimer) return;
    accountsState.pollTimer = setInterval(() => {
        if (document.hidden) return;
        loadAccounts({
            silent: true
        });
    }, accountsState.pollInterval);
}

async function refreshSingleAccount(accountId) {
    await refreshTokenStatuses();
    const statusData = await requestJson(`/api/accounts/${accountId}/sync/status`);
    accountsState.syncStatus[accountId] = statusData.data || {};
    renderAccounts();
}

// Load Orders
async function loadOrders() {
    const list = document.getElementById('orders-list');
    try {
        const ordersEndpoint = '/api/orders/all?limit=6&allow_local_cache=1';
        let data;
        if (window.ApiClient) {
            const { response, data: body } = await window.ApiClient.json(ordersEndpoint);
            data = body || {};
            if (!response.ok && !data.error) {
                data.error = true;
                data.message = `Falha ao carregar pedidos (HTTP ${response.status})`;
            }
        } else {
            const resp = await fetch(ordersEndpoint, { credentials: 'include' });
            data = await resp.json().catch(() => ({}));
            if (!resp.ok && !data.error) {
                data.error = true;
                data.message = `Falha ao carregar pedidos (HTTP ${resp.status})`;
            }
        }

        // Handle API error responses (422 = missing seller, 401 = token expired)
        if (data.error) {
            list.innerHTML = `
                <div class="text-center py-4">
                    <i class="bi bi-exclamation-triangle text-warning" style="font-size: 2rem;"></i>
                    <p class="text-muted mt-2 mb-0">${data.message || 'Não foi possível carregar pedidos'}</p>
                </div>
            `;
            return;
        }

        if (!data.results || data.results.length === 0) {
            list.innerHTML = `
                <div class="text-center py-4">
                    <i class="bi bi-inbox text-muted" style="font-size: 2rem;"></i>
                    <p class="text-muted mt-2 mb-0">Nenhum pedido encontrado</p>
                </div>
            `;
            return;
        }

        list.innerHTML = data.results.map(order => {
            const status = order.status?.toLowerCase() || 'pending';
            const statusConfig = {
                paid: {
                    icon: 'bi-check-circle',
                    class: 'paid'
                },
                confirmed: {
                    icon: 'bi-check',
                    class: 'paid'
                },
                shipped: {
                    icon: 'bi-truck',
                    class: 'shipped'
                },
                delivered: {
                    icon: 'bi-house-check',
                    class: 'paid'
                },
                cancelled: {
                    icon: 'bi-x-circle',
                    class: 'cancelled'
                },
                pending: {
                    icon: 'bi-clock',
                    class: 'pending'
                }
            }[status] || {
                icon: 'bi-clock',
                class: 'pending'
            };

            return `
                <div class="order-item">
                    <div class="order-icon ${statusConfig.class}">
                        <i class="bi ${statusConfig.icon}"></i>
                    </div>
                    <div class="order-content">
                        <div class="order-id">Pedido #${order.id}</div>
                        <div class="order-date">${formatDate(order.date_created)}</div>
                    </div>
                    <div class="order-amount">R$ ${formatNumber(order.total_amount || 0)}</div>
                </div>
            `;
        }).join('');

    } catch (error) {
        console.error('Error loading orders:', error);
        if (list) {
            list.innerHTML = `
                <div class="text-center py-4">
                    <i class="bi bi-wifi-off text-muted" style="font-size: 2rem;"></i>
                    <p class="text-muted mt-2 mb-0">Falha ao carregar pedidos</p>
                </div>
            `;
        }
    }
}

// Update Reputation
function updateReputation(rep) {
    const scoreEl = document.getElementById('rep-score');
    const badgeEl = document.getElementById('rep-badge');
    const circleEl = document.querySelector('.reputation-circle');

    // Calculate score (0-100)
    const score = Math.round(100 - (rep.claims_rate + rep.cancellations_rate + rep.delayed_rate) * 10);
    const clampedScore = Math.max(0, Math.min(100, score));

    scoreEl.textContent = clampedScore;
    circleEl.style.setProperty('--score-percent', clampedScore + '%');

    // Determine level
    let level = 'Iniciante';
    let color = '#64748b';
    if (clampedScore >= 90) {
        level = 'MercadoLíder Platinum';
        color = '#f59e0b';
    } else if (clampedScore >= 80) {
        level = 'MercadoLíder Gold';
        color = '#eab308';
    } else if (clampedScore >= 70) {
        level = 'MercadoLíder';
        color = '#22c55e';
    } else if (clampedScore >= 50) {
        level = 'Bom';
        color = '#3b82f6';
    }

    badgeEl.innerHTML = `<i class="bi bi-award"></i><span>${level}</span>`;
    badgeEl.style.background = `linear-gradient(135deg, ${color} 0%, ${adjustColor(color, -20)} 100%)`;

    // Update metrics
    document.getElementById('rep-claims').textContent = rep.claims_rate + '%';
    document.getElementById('rep-cancel').textContent = rep.cancellations_rate + '%';
    document.getElementById('rep-delay').textContent = rep.delayed_rate + '%';

    // Update progress bars
    updateProgressBar('bar-claims', rep.claims_rate, 1);
    updateProgressBar('bar-cancel', rep.cancellations_rate, 0.5);
    updateProgressBar('bar-delay', rep.delayed_rate, 4);
}

function updateProgressBar(id, value, maxAllowed) {
    const bar = document.getElementById(id);
    const percent = Math.min(100, (value / maxAllowed) * 100);
    bar.style.width = percent + '%';

    bar.classList.remove('success', 'warning', 'danger');
    if (value > maxAllowed) bar.classList.add('danger');
    else if (value > maxAllowed * 0.7) bar.classList.add('warning');
    else bar.classList.add('success');
}

// Initialize Charts
let ordersChart = null;
let revenueChart = null;

function initCharts() {
    if (typeof Chart === 'undefined') {
        console.warn('[Dashboard] Chart.js não carregado — gráficos indisponíveis.');
        document.querySelectorAll('.chart-body').forEach(el => {
            el.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-exclamation-triangle" style="font-size:1.5rem"></i><p class="mt-2 mb-0">Gráficos indisponíveis (Chart.js não carregou)</p></div>';
        });
        return;
    }

    // Orders Doughnut Chart
    const ordersCtx = document.getElementById('ordersChart')?.getContext('2d');
    if (ordersCtx) {
        ordersChart = new Chart(ordersCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pagos', 'Enviados', 'Pendentes', 'Cancelados'],
                datasets: [{
                    data: [0, 0, 0, 0],
                    backgroundColor: ['#22c55e', '#3b82f6', '#f59e0b', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 16,
                            usePointStyle: true,
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            }
        });
    }

    // Revenue Line Chart
    const revenueCtx = document.getElementById('revenueChart')?.getContext('2d');
    if (revenueCtx) {
        const days = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        const today = new Date().getDay();
        const labels = [];
        for (let i = 6; i >= 0; i--) {
            labels.push(days[(today - i + 7) % 7]);
        }

        revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Receita',
                    data: [0, 0, 0, 0, 0, 0, 0],
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 4,
                    pointBackgroundColor: '#667eea'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        },
                        ticks: {
                            callback: v => 'R$ ' + formatNumber(v)
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
}

function updateOrdersChart(data) {
    if (!ordersChart || !data) return;

    const statusMap = {
        'paid': 0,
        'confirmed': 0,
        'shipped': 1,
        'ready_to_ship': 1,
        'pending': 2,
        'cancelled': 3
    };

    const counts = [0, 0, 0, 0];
    data.forEach(item => {
        const idx = statusMap[item.status?.toLowerCase()] ?? 2;
        counts[idx] += item.count || 0;
    });

    ordersChart.data.datasets[0].data = counts;
    ordersChart.update();
}

function updateRevenueChart(salesOverTime) {
    if (!revenueChart) return;

    // Agrega a receita real (já filtrada pela fonte única de receita do
    // backend — Onda 2 / T1) por data (YYYY-MM-DD).
    const byDate = {};
    (Array.isArray(salesOverTime) ? salesOverTime : []).forEach(row => {
        const d = String(row.date || '').slice(0, 10);
        if (d) byDate[d] = (byDate[d] || 0) + (parseFloat(row.total) || 0);
    });

    // Últimos 7 dias corridos (inclui hoje). As chaves de data usam o
    // fuso do navegador só para montar YYYY-MM-DD; os valores em si vêm
    // do backend em America/Sao_Paulo.
    const labels = [];
    const values = [];
    const today = new Date();
    for (let i = 6; i >= 0; i--) {
        const d = new Date(today);
        d.setDate(d.getDate() - i);
        const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        labels.push(`${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`);
        values.push(byDate[key] || 0);
    }

    revenueChart.data.labels = labels;
    revenueChart.data.datasets[0].data = values;
    revenueChart.update();
}

// Utility Functions
function formatNumber(num) {
    return new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(num);
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    // Timezone único (America/Sao_Paulo) — Onda 1.1/F1: valores
    // "YYYY-MM-DD HH:mm:ss" sem offset (ex.: ml_orders.date_created) já
    // vêm em wall-clock do fuso da aplicação; exibir os números como
    // estão evita que o navegador reinterprete a string via Date() no
    // seu próprio timezone, causando o mesmo pedido exibir horas
    // diferentes entre o widget "Pedidos Recentes" do Dashboard e
    // /dashboard/orders (bug real: delta de +11h observado em produção).
    const naive = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?\s*$/);
    if (naive) {
        const [, y, mo, d, h, mi] = naive;
        return `${d}/${mo}/${y} ${h}:${mi}`;
    }
    // Strings com offset explícito (ex.: API do ML) são convertidas de
    // forma inequívoca e sempre exibidas no fuso único da aplicação.
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return dateStr;
    return date.toLocaleDateString('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function adjustColor(color, amount) {
    return '#' + color.replace(/^#/, '').replace(/../g, c =>
        ('0' + Math.min(255, Math.max(0, parseInt(c, 16) + amount)).toString(16)).substr(-2)
    );
}

function selectAccount(accountId) {
    accountsState.pendingId = accountId;
    accountsState.activeId = accountId;
    renderAccounts();

    requestJson('/api/dashboard/switch-account', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({
            account_id: accountId
        })
    })
        .then(async data => {
            if (data.success) {
                Toast.success('Conta alterada com sucesso');
                await refreshSingleAccount(accountId);
            } else {
                Toast.error(data.error || 'Erro ao trocar conta');
            }
        })
        .catch(() => Toast.error('Erro ao trocar conta'))
        .finally(() => {
            accountsState.pendingId = null;
            renderAccounts();
        });
}

async function syncAccountFromDashboard(event, accountId) {
    event.stopPropagation();
    const numericAccountId = Number(accountId);
    const account = accountsState.accounts.find(acc => Number(acc.id) === numericAccountId);
    const tokenStatus = accountsState.tokenStatus[numericAccountId] || accountsState.tokenStatus[accountId] || {};
    const syncStatus = accountsState.syncStatus[numericAccountId] || accountsState.syncStatus[accountId] || {};
    const syncCapability = getAccountSyncCapability(account || { id: numericAccountId }, tokenStatus, syncStatus);

    if (syncCapability.action === 'reconnect-account') {
        window.location.href = `/auth/authorize?reconnect=${numericAccountId}`;
        return;
    }

    if (syncCapability.disabled) {
        Toast.warning(syncCapability.reason || 'Conta em processamento. Aguarde.');
        return;
    }

    try {
        const data = await requestJson(`/api/accounts/${numericAccountId}/sync`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });
        if (data.success) {
            Toast.success('Sincronização concluída');
            await refreshSingleAccount(numericAccountId);
        } else {
            if (data.needs_reconnect && data.reconnect_url) {
                window.location.href = data.reconnect_url;
                return;
            }
            Toast.error(data.error || 'Falha na sincronização');
        }
    } catch (error) {
        Toast.error('Erro ao sincronizar conta');
    }
}

function reconnectAccountFromDashboard(event, accountId) {
    event.stopPropagation();
    const numericAccountId = Number(accountId);
    window.location.href = `/auth/authorize?reconnect=${numericAccountId}`;
}

async function unlinkAccount(event, accountId) {
    event.stopPropagation();
    const account = accountsState.accounts.find(acc => acc.id == accountId);
    const nickname = account?.nickname || 'Conta';
    const confirmUnlink = window.confirm(`Deseja desvincular a conta ${nickname}?`);
    if (!confirmUnlink) return;

    try {
        const data = await requestJson(`/auth/disconnect/${accountId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });
        if (data.success) {
            Toast.success('Conta desvinculada com sucesso');
            await loadAccounts();
        } else {
            Toast.error(data.error || 'Falha ao desvincular conta');
        }
    } catch (error) {
        Toast.error('Erro ao desvincular conta');
    }
}

// Event delegation for dynamic elements
document.addEventListener('click', function (e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;

    const action = target.dataset.action;
    const accountId = target.dataset.accountId;

    switch (action) {
        case 'retry-load-accounts':
            e.preventDefault();
            loadAccounts();
            break;
        case 'select-account':
            e.preventDefault();
            // Don't select if clicking on action buttons
            if (!e.target.closest('.account-actions')) {
                selectAccount(accountId);
            }
            break;
        case 'sync-account':
            e.preventDefault();
            e.stopPropagation(); // Prevent card selection
            syncAccountFromDashboard(e, accountId);
            break;
        case 'reconnect-account':
            e.preventDefault();
            e.stopPropagation(); // Prevent card selection
            reconnectAccountFromDashboard(e, accountId);
            break;
        case 'unlink-account':
            e.preventDefault();
            e.stopPropagation(); // Prevent card selection
            unlinkAccount(e, accountId);
            break;
    }
});
