/**
 * SEO Killer - JavaScript Principal
 * Versão: 1.0
 */

const SEOKiller = {
    // Estado global
    state: {
        selectedItems: new Set(),
        currentJob: null,
        bulkResults: {},
        titleSuggestions: [],
        keywordData: null
    },

    _assetPromises: {
        scripts: {},
        styles: {},
    },

    // Cache para reduzir requisições
    _cache: {
        data: {},
        timestamps: {},
        ttl: 5 * 60 * 1000 // 5 minutos
    },

    // Inicialização
    init() {
        if (this._initialized) {
            return;
        }
        this._initialized = true;

        console.log('🔥 SEO Killer initialized');
        this.bindEvents();
        this.initTabs();

        // Delay initial API calls to avoid burst limit
        setTimeout(() => {
            this.loadDashboardData();
            // Top Performers independente do diagnose (antes ficava preso se diagnose falhasse)
            this.loadTopPerformers();
            this.initChatbotWidget();
        }, 100);
    },

    // Bind de eventos globais
    bindEvents() {
        // Event delegation para melhor performance
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-seo-action]')) {
                const action = e.target.dataset.seoAction;
                if (this[action]) {
                    this[action](e.target);
                }
            }
        });
    },

    // Utilitários
    utils: {
        // Fetch com tratamento de erro, retry para 429 e cache
        async fetchAPI(url, options = {}) {
            // Verificar cache para GET requests
            if (!options.method || options.method === 'GET') {
                const cacheKey = url + JSON.stringify(options);
                const cached = SEOKiller._cache.data[cacheKey];
                const timestamp = SEOKiller._cache.timestamps[cacheKey];

                if (cached && timestamp && (Date.now() - timestamp) < SEOKiller._cache.ttl) {
                    return cached;
                }
            }

            const maxRetries = 3;
            let retryCount = 0;

            while (retryCount <= maxRetries) {
                try {
                    const response = await fetch(url, {
                        ...options,
                        headers: {
                            'Content-Type': 'application/json',
                            ...options.headers
                        }
                    });

                    if (response.status === 401) {
                        if (window.AccountSelector && typeof window.AccountSelector.openModal === 'function') {
                            window.AccountSelector.openModal();
                        }
                        throw new Error('Selecione uma conta do Mercado Livre para continuar.');
                    }

                    const data = await response.json().catch(() => null);

                    if (response.status === 429) {
                        retryCount++;
                        if (retryCount > maxRetries) {
                            const retryAfter = data?.retry_after || 10;
                            throw new Error(`Muitas requisições. Tente novamente em ${retryAfter} segundos.`);
                        }

                        // Exponential backoff: 1s, 2s, 4s
                        const delay = Math.pow(2, retryCount - 1) * 1000;
                        await new Promise(resolve => setTimeout(resolve, delay));
                        continue;
                    }

                    if (!response.ok) {
                        const errorMessage = data?.error || `HTTP ${response.status}: ${response.statusText}`;
                        throw new Error(errorMessage);
                    }

                    // Salvar no cache para GET requests bem-sucedidas
                    if (!options.method || options.method === 'GET') {
                        const cacheKey = url + JSON.stringify(options);
                        SEOKiller._cache.data[cacheKey] = data;
                        SEOKiller._cache.timestamps[cacheKey] = Date.now();
                    }

                    return data;
                } catch (error) {
                    if (error.message.includes('Muitas requisições') && retryCount <= maxRetries) {
                        continue;
                    }

                    console.error('API Error:', error);
                    if (typeof SEOKiller !== 'undefined' && typeof SEOKiller.showError === 'function') {
                        SEOKiller.showError(`Erro na API: ${error.message}`);
                    }
                    throw error;
                }
            }
        },

        // Debounce para search inputs
        debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },

        // Formatar números
        formatNumber(num) {
            return new Intl.NumberFormat('pt-BR').format(num);
        },

        // Formatar moeda
        formatCurrency(value) {
            return new Intl.NumberFormat('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            }).format(value);
        },

        // Limpar cache
        clearCache(pattern = null) {
            if (pattern) {
                // Limpar cache que corresponde ao padrão
                for (const key in SEOKiller._cache.data) {
                    if (key.includes(pattern)) {
                        delete SEOKiller._cache.data[key];
                        delete SEOKiller._cache.timestamps[key];
                    }
                }
            } else {
                // Limpar todo o cache
                SEOKiller._cache.data = {};
                SEOKiller._cache.timestamps = {};
            }
        },

        // Formatar data
        formatDate(date) {
            return new Intl.DateTimeFormat('pt-BR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }).format(new Date(date));
        },

        // Calcular cor do score
        getScoreColor(score) {
            if (score >= 80) return 'success';
            if (score >= 50) return 'warning';
            return 'danger';
        },

        // Calcular classe do score badge
        getScoreBadgeClass(score) {
            if (score >= 80) return 'high';
            if (score >= 50) return 'medium';
            return 'low';
        }
    },

    // Notificações
    showSuccess(message, duration = 3000) {
        this.showToast(message, 'success', duration);
    },

    showError(message, duration = 5000) {
        this.showToast(message, 'danger', duration);
    },

    showInfo(message, duration = 3000) {
        this.showToast(message, 'info', duration);
    },

    showToast(message, type = 'info', duration = 3000) {
        // Usar Bootstrap Toast ou criar custom
        const toastHtml = `
            <div class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;

        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            document.body.appendChild(container);
        }

        container.insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = container.lastElementChild;
        const toast = new bootstrap.Toast(toastElement, { delay: duration });
        toast.show();

        // Remove do DOM após esconder
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    },

    // Loading states
    showLoading(element, message = 'Carregando...') {
        if (typeof element === 'string') {
            element = document.getElementById(element);
        }
        if (element) {
            element.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">${message}</p>
                </div>
            `;
        }
    },

    showLoadingSkeleton(element, count = 3) {
        if (typeof element === 'string') {
            element = document.getElementById(element);
        }
        if (element) {
            let skeletonHtml = '';
            for (let i = 0; i < count; i++) {
                skeletonHtml += '<div class="skeleton skeleton-card"></div>';
            }
            element.innerHTML = skeletonHtml;
        }
    },

    // Carregar dados do dashboard
    async loadDashboardData() {
        try {
            // Load stats
            const data = await this.utils.fetchAPI('/api/seo-killer/diagnose');

            if (data.success && data.stats) {
                const setText = (id, value) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = value;
                };
                setText('total-items', this.utils.formatNumber(data.stats.total || 0));
                setText('optimized-items', this.utils.formatNumber(data.stats.optimized || 0));
                setText('pending-items', this.utils.formatNumber(data.stats.pending || 0));
                setText('avg-score', data.stats.avgScore ? data.stats.avgScore.toFixed(1) : '-');
            }

            const updatedEl = document.getElementById('seo-killer-last-updated');
            if (updatedEl) {
                updatedEl.textContent = new Date().toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
            }
        } catch (error) {
            console.error('Erro ao carregar dados do dashboard:', error);
        } finally {
            // Sempre carregar widgets; não depender do sucesso do diagnose nem de DOM stats
            setTimeout(() => this.loadTopPerformers(), 200);
            setTimeout(() => this.loadAutopilotStatus(), 400);
            setTimeout(() => this.loadRecentActivity(), 600);
        }
    },

    // Sincronizar anúncios do Mercado Livre
    async syncItems() {
        const btn = document.getElementById('btn-sync-items');
        const originalHtml = btn ? btn.innerHTML : '';

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sincronizando...';
            }

            const data = await this.utils.fetchAPI('/api/seo-killer/sync', {
                method: 'POST',
                body: JSON.stringify({ limit: 100 })
            });

            if (data.success) {
                const synced = data.synced ?? data.count ?? 0;
                const total = data.total_found ?? synced;
                this.showSuccess(`Sincronização concluída! ${synced} de ${total} anúncios sincronizados.`);
                // Recarregar dashboard com dados atualizados
                await this.loadDashboardData();
            } else {
                throw new Error(data.error || 'Falha ao sincronizar anúncios');
            }
        } catch (error) {
            console.error('Erro ao sincronizar:', error);
            this.showError('Erro ao sincronizar: ' + error.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    },

    initTabs() {
        const resolveTabIdFromLocation = () => {
            const rawHash = (window.location.hash || '').replace('#', '').trim();
            const hash = rawHash.split('?')[0].split('/')[0];

            if (hash) {
                return hash;
            }

            const tabParam = new URLSearchParams(window.location.search).get('tab');
            return tabParam ? tabParam : '';
        };

        const activateTabFromLocation = () => {
            const targetId = resolveTabIdFromLocation();
            if (!targetId) {
                return false;
            }

            const link = document.querySelector(`#seoKillerTabs [data-bs-toggle="tab"][data-bs-target="#${targetId}"]`);
            if (!link || !window.bootstrap?.Tab) {
                return false;
            }

            try {
                const tab = new window.bootstrap.Tab(link);
                tab.show();
                return true;
            } catch (error) {
                console.warn('Não foi possível ativar a aba SEO Killer via deep-link:', error);
                return false;
            }
        };

        activateTabFromLocation();

        document.addEventListener('shown.bs.tab', (event) => {
            const link = event.target;
            const target = link?.getAttribute?.('data-bs-target');
            if (!target || !target.startsWith('#')) return;
            const tabId = target.slice(1);
            const url = new URL(window.location.href);

            if (tabId && tabId !== 'dashboard') {
                url.searchParams.set('tab', tabId);
                url.hash = tabId;
            } else {
                url.searchParams.delete('tab');
                url.hash = '';
            }

            window.history.replaceState({}, '', url.toString());
        });

        window.addEventListener('hashchange', () => {
            activateTabFromLocation();
        });
    },

    ensureChartJs() {
        if (window.Chart) return Promise.resolve(true);
        if (this._chartJsPromise) return this._chartJsPromise;

        this._chartJsPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
            script.async = true;
            script.onload = () => resolve(true);
            script.onerror = () => reject(new Error('Falha ao carregar Chart.js'));
            document.head.appendChild(script);
        });

        return this._chartJsPromise;
    },

    ensureScript(src) {
        const key = String(src || '');
        if (!key) return Promise.resolve(false);
        if (this._assetPromises.scripts[key]) return this._assetPromises.scripts[key];

        const pathname = key.split('?')[0];
        // Também dedupe por pathname sem query (evita carregar chatbot.js duas vezes)
        const existingPromiseKey = Object.keys(this._assetPromises.scripts || {}).find((k) => k.split('?')[0] === pathname);
        if (existingPromiseKey) {
            this._assetPromises.scripts[key] = this._assetPromises.scripts[existingPromiseKey];
            return this._assetPromises.scripts[key];
        }

        this._assetPromises.scripts[key] = new Promise((resolve, reject) => {
            const existing = [...document.querySelectorAll('script[src]')].find((el) => {
                const attr = el.getAttribute('src') || '';
                if (attr === key || attr.split('?')[0] === pathname) return true;
                try {
                    return new URL(el.src, window.location.origin).pathname === pathname;
                } catch (e) {
                    return false;
                }
            });
            if (existing) {
                resolve(true);
                return;
            }
            const script = document.createElement('script');
            script.src = key;
            script.async = true;
            script.onload = () => resolve(true);
            script.onerror = () => reject(new Error(`Falha ao carregar script: ${key}`));
            document.head.appendChild(script);
        });

        return this._assetPromises.scripts[key];
    },

    ensureStyle(href) {
        const key = String(href || '');
        if (!key) return Promise.resolve(false);
        if (this._assetPromises.styles[key]) return this._assetPromises.styles[key];

        const pathname = key.split('?')[0];
        const existingPromiseKey = Object.keys(this._assetPromises.styles || {}).find((k) => k.split('?')[0] === pathname);
        if (existingPromiseKey) {
            this._assetPromises.styles[key] = this._assetPromises.styles[existingPromiseKey];
            return this._assetPromises.styles[key];
        }

        this._assetPromises.styles[key] = new Promise((resolve) => {
            const existing = [...document.querySelectorAll('link[rel="stylesheet"][href]')].find((el) => {
                const attr = el.getAttribute('href') || '';
                if (attr === key || attr.split('?')[0] === pathname) return true;
                try {
                    return new URL(el.href, window.location.origin).pathname === pathname;
                } catch (e) {
                    return false;
                }
            });
            if (existing) {
                resolve(true);
                return;
            }
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = key;
            link.onload = () => resolve(true);
            link.onerror = () => resolve(true);
            document.head.appendChild(link);
        });

        return this._assetPromises.styles[key];
    },

    async initAIInsightsTab() {
        try {
            await Promise.all([
                this.ensureStyle('/assets/css/seo-killer-ai-insights.css'),
                this.ensureScript('/assets/js/seo-killer-ai-insights.js'),
            ]);
            if (typeof window.initAIInsightsDashboard === 'function') {
                window.initAIInsightsDashboard();
            }
        } catch (e) {
            this.showError(e.message);
        }
    },

    initChatbotWidget() {
        // CSS é obrigatório (widget fica position:static sem ele). Sempre garantir.
        const ensureCss = this.ensureStyle('/assets/css/seo-killer-chatbot.css');

        // Página já inclui seo-killer-chatbot.js com handlers CSP-safe
        if (typeof window.toggleChatWidget === 'function'
            && document.getElementById('aiChatbotWidget')?.dataset?.chatEventsBound === '1') {
            ensureCss.catch(() => { });
            return;
        }

        if (!this._ensureChatbotPromise) {
            this._ensureChatbotPromise = Promise.all([
                ensureCss,
                this.ensureScript('/assets/js/seo-killer-chatbot.js'),
            ]);
        }

        const ensure = () => this._ensureChatbotPromise.catch(() => { });

        const stub = (name) => {
            if (typeof window[name] === 'function') return;
            const lazy = (...args) => ensure().then(() => {
                const fn = window[name];
                if (typeof fn === 'function' && fn !== lazy) {
                    return fn(...args);
                }
            });
            window[name] = lazy;
        };

        stub('toggleChatWidget');
        stub('sendChatMessage');
        stub('handleChatKeyPress');
        stub('sendQuickMessage');
        stub('clearChatHistory');
        stub('executeActionFromChat');
        stub('explainMetric');
        stub('getFeatureHelp');

        ensure();
    },

    // 🏆 Load Top Performers
    async loadTopPerformers() {
        const period = document.getElementById('top-performers-period')?.value || '30d';
        const container = document.getElementById('top-performers-list');
        if (!container) return;

        container.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;

        try {
            const data = await this.utils.fetchAPI(`/api/seo-killer/top-performers?limit=5&period=${period}`);

             if (data.success && data.items && data.items.length > 0) {
                let html = `
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Produto</th>
                            <th>Vendas</th>
                            <th class="text-center">Score</th>
                            <th>Grade</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>`;

                data.items.forEach(item => {
                    html += `
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="${Format.escapeHtml(item.thumbnail || '')}" class="rounded me-3" width="48" height="48" style="object-fit: cover;">
                                    <div>
                                        <div class="fw-bold text-truncate" style="max-width: 300px;">${Format.escapeHtml(item.title || '')}</div>
                                        <small class="text-muted">${this.utils.formatCurrency(item.price)}</small>
                                    </div>
                                </div>
                            </td>
                            <td>${this.utils.formatNumber(item.sold_quantity)}</td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border">${Number(item.score) || 0}/100</span>
                            </td>
                            <td>
                                <span class="badge bg-${this.utils.getScoreColor(item.score)}">${Format.escapeHtml(item.grade || '')}</span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" onclick="SEOKiller.quickActions.optimizeItem('${Format.escapeHtml(String(item.item_id || ''))}')">
                                    <i class="bi bi-magic"></i> Analisar
                                </button>
                            </td>
                        </tr>
                    `;
                });
                html += '</tbody></table>';
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                        Nenhum dado encontrado para o período.
                    </div>
                `;
            }
        } catch (error) {
            console.error('Failed to load top performers:', error);
            container.innerHTML = `<div class="alert alert-danger">Erro ao carregar dados: ${error.message}</div>`;
        }
    },

    // 🤖 Load AutoPilot Status
    async loadAutopilotStatus() {
        try {
            const data = await this.utils.fetchAPI('/api/seo-killer/autopilot/status');
            const statusBadge = document.getElementById('autopilot-status');
            const btn = document.getElementById('autopilot-toggle');

            if (data.enabled) {
                if (statusBadge) {
                    statusBadge.textContent = 'Ativo';
                    statusBadge.className = 'badge bg-success text-white';
                }
                if (btn) btn.textContent = 'Pausar AutoPilot';
            } else {
                if (statusBadge) {
                    statusBadge.textContent = 'Desativado';
                    statusBadge.className = 'badge bg-white text-primary';
                }
                if (btn) btn.textContent = 'Ativar AutoPilot';
            }
        } catch (error) {
            console.error('Failed to load autopilot status:', error);
        }
    },

    async toggleAutoPilot() {
        try {
            const status = await this.utils.fetchAPI('/api/seo-killer/autopilot/status');
            const currentlyEnabled = !!status.enabled;
            const endpoint = currentlyEnabled ? '/api/seo-killer/autopilot/disable' : '/api/seo-killer/autopilot/enable';
            const data = await this.utils.fetchAPI(endpoint, { method: 'POST' });

            if (data.success) {
                await this.loadAutopilotStatus();
                if (currentlyEnabled) {
                    this.showSuccess('AutoPilot pausado.');
                } else {
                    this.showSuccess('AutoPilot ativado.');
                }
            } else {
                this.showError(data.error || 'Não foi possível alterar o AutoPilot');
            }
        } catch (error) {
            this.showError(`Erro ao alternar AutoPilot: ${error.message}`);
        }
    },

    configureAutoPilot() {
        const modal = document.getElementById('autopilotConfigModal');
        if (!modal) {
            this.showError('Configuração do AutoPilot indisponível.');
            return;
        }
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    },

    showBulkOptimizer() {
        if (typeof window.showBulkOptimizer === 'function') {
            window.showBulkOptimizer();
            return;
        }
        const modal = document.getElementById('bulkOptimizerModal');
        if (!modal) {
            this.showError('Otimização em lote indisponível.');
            return;
        }
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    },

    openSchemaMarkup() {
        const modal = document.getElementById('schemaMarkupModal');
        if (!modal) {
            this.showError('Schema Markup indisponível.');
            return;
        }
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    },

    openAIInsights() {
        const tabTrigger = document.getElementById('ai-insights-tab');
        if (!tabTrigger) return;
        const tab = new bootstrap.Tab(tabTrigger);
        tab.show();
    },

    openAIPricing() {
        const tabTrigger = document.getElementById('ai-pricing-tab');
        if (!tabTrigger) return;
        const tab = new bootstrap.Tab(tabTrigger);
        tab.show();
    },

    async loadRecentActivity() {
        const container = document.getElementById('recent-activity');
        if (!container) return;
        container.innerHTML = '<p class="text-muted mb-0">Carregando...</p>';

        try {
            const data = await this.utils.fetchAPI('/api/seo-killer/autopilot/history?limit=5');
            const history = data.history || data.runs || [];
            if (data.success && Array.isArray(history) && history.length > 0) {
                container.innerHTML = history.map((item) => {
                    const createdAt = item.created_at || item.completed_at ? this.utils.formatDate(item.created_at || item.completed_at) : '';
                    const description = Format.escapeHtml(item.description || item.status_description || 'Otimização realizada');
                    const status = String(item.status || '').toLowerCase();
                    const badgeType = status === 'completed' || status === 'success' ? 'success' : status === 'running' ? 'primary' : status === 'failed' || status === 'error' ? 'danger' : 'warning';
                    const processed = Number(item.items_processed || item.items_optimized || 0);
                    return `
                        <div class="d-flex justify-content-between align-items-center gap-3 mb-2 pb-2 border-bottom">
                            <div class="flex-grow-1">
                                <small class="text-muted">${Format.escapeHtml(createdAt)}</small>
                                <p class="mb-0">${description}</p>
                            </div>
                            <span class="badge bg-${badgeType}">${processed}</span>
                        </div>
                    `;
                }).join('');
            } else {
                container.innerHTML = '<p class="text-muted mb-0">Nenhuma atividade recente. Rode um diagnóstico para começar.</p>';
            }
        } catch (error) {
            container.innerHTML = '<p class="text-muted mb-0">Não foi possível carregar a atividade recente.</p>';
        }
    },

    // Executar diagnóstico
    async runDiagnosis() {
        const resultsSection = document.getElementById('results-section');
        const resultsContent = document.getElementById('results-content');

        resultsSection.style.display = 'block';
        this.showLoading(resultsContent, 'Executando diagnóstico completo...');

        try {
            const data = await this.utils.fetchAPI('/api/seo-killer/diagnose');

            if (data.success) {
                const updatedEl = document.getElementById('seo-killer-last-updated');
                if (updatedEl) {
                    updatedEl.textContent = new Date().toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
                }

                let problemsHtml = '';
                if (data.diagnosis?.problems?.length) {
                    problemsHtml = `
                        <div class="mt-4">
                            <h6 class="fw-bold mb-3 text-danger"><i class="bi bi-exclamation-octagon me-2"></i>Principais Problemas Detectados</h6>
                            <div class="list-group list-group-flush border rounded-3 shadow-sm overflow-hidden">
                                ${data.diagnosis.problems.map(p => `
                                    <div class="list-group-item p-3">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <span class="badge bg-${p.severity === 'critical' ? 'danger' : 'warning'} mb-2">${p.category.toUpperCase()}</span>
                                                <h6 class="mb-1 fw-bold">${p.issue}</h6>
                                                <p class="mb-1 text-muted small">${p.solution}</p>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-light text-dark border">${p.affected_items} itens</span>
                                                <div class="text-danger small mt-1">Impacto: ${p.impact} pts</div>
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `;
                }

                let opportunitiesHtml = '';
                if (data.diagnosis?.opportunities?.length) {
                    opportunitiesHtml = `
                        <div class="mt-4">
                            <h6 class="fw-bold mb-3 text-success"><i class="bi bi-rocket-takeoff me-2"></i>Oportunidades de Crescimento</h6>
                            <div class="list-group list-group-flush border rounded-3 shadow-sm overflow-hidden">
                                ${data.diagnosis.opportunities.map(o => `
                                    <div class="list-group-item p-3">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <span class="badge bg-success mb-2">${o.category.toUpperCase()}</span>
                                                <h6 class="mb-1 fw-bold">${o.opportunity}</h6>
                                                <p class="mb-1 text-muted small">${o.strategy}</p>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-light text-dark border">${o.affected_items} itens</span>
                                                <div class="text-success small mt-1">Potencial: +${o.potential}%</div>
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `;
                }

                resultsContent.innerHTML = `
                    <div class="alert alert-success fade-in shadow-sm border-0 bg-opacity-10 bg-success pb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0 fw-bold text-success"><i class="bi bi-check-circle me-2"></i>Resumo do Diagnóstico</h5>
                            <span class="badge bg-success text-white">${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <p class="mb-1 text-muted small"><strong>Anúncios:</strong></p>
                                <h3 class="fw-bold mb-0">${this.utils.formatNumber(data.stats?.total || 0)}</h3>
                            </div>
                            <div class="col-md-3">
                                <p class="mb-1 text-muted small"><strong>Pendentes:</strong></p>
                                <h3 class="text-warning fw-bold mb-0">${this.utils.formatNumber(data.stats?.pending || 0)}</h3>
                            </div>
                            <div class="col-md-3">
                                <p class="mb-1 text-muted small"><strong>Score Médio:</strong></p>
                                <h3 class="text-${this.utils.getScoreColor(data.stats?.avgScore || 0)} fw-bold mb-0">
                                    ${data.stats?.avgScore ? data.stats.avgScore.toFixed(1) + '%' : '—'}
                                </h3>
                            </div>
                            <div class="col-md-3">
                                <p class="mb-1 text-muted small"><strong>Potencial:</strong></p>
                                <h3 class="text-info fw-bold mb-0">+${data.stats?.potential || 0}%</h3>
                            </div>
                        </div>
                    </div>

                    ${problemsHtml}
                    ${opportunitiesHtml}

                    ${data.stats?.total === 0 ? `
                        <div class="text-center py-5 mt-4 border rounded-3 bg-light">
                            <div class="mb-3 text-muted fs-1"><i class="bi bi-cloud-slash"></i></div>
                            <h6 class="fw-bold">Nenhum dado encontrado</h6>
                            <p class="text-muted small px-4">Sincronize seus anúncios do Mercado Livre para que o SEO Killer possa analisá-los e propor melhorias.</p>
                            <button class="btn btn-primary px-4 rounded-pill" onclick="SEOKiller.syncItems()">
                                <i class="bi bi-arrow-repeat me-2"></i>Sincronizar Agora
                            </button>
                        </div>
                    ` : ''}

                    <div class="text-center mt-5 mb-4">
                        <button class="btn btn-primary btn-lg px-5 rounded-pill shadow-sm" onclick="document.getElementById('ai-insights-tab').click()">
                            Explorar AI Insights Detalhados <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>
                `;
                this.showSuccess('Diagnóstico concluído com sucesso!');
            } else {
                throw new Error(data.error || 'Erro desconhecido');
            }
        } catch (error) {
            resultsContent.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> ${error.message}
                </div>
            `;
            this.showError('Erro ao executar diagnóstico');
        }
    }
};

// Inicializar quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', () => {
    SEOKiller.init();
});

// Exportar para uso global
window.SEOKiller = SEOKiller;

// ============================================
// TECH SHEET (FICHA TÉCNICA) - UI HELPERS
// ============================================

SEOKiller.techSheet = {
    state: {
        page: 1,
        perPage: 20,
        tab: 'pending',
        sort: 'updated_at',
        q: '',
        loading: false,
        lastResponse: null,
        currentItemId: null,
        currentDetail: null,
        selectedItemIds: new Set(),
        lastJobFailuresRetry: null,
        bulkMinConfidence: 85,
    },

    async fetchJSON(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                ...(options.headers || {})
            }
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = data?.message || data?.error || `HTTP ${response.status}`;
            throw new Error(message);
        }
        return data;
    },

    setQuery(q) {
        this.state.q = (q || '').trim();
        this.state.page = 1;
    },

    setTab(tab, buttonEl) {
        const t = String(tab || 'all');
        this.state.tab = t;
        this.state.page = 1;
        this.state.selectedItemIds = new Set();

        try {
            const nav = document.getElementById('tech-sheet-tab-pills');
            if (nav) {
                const links = nav.querySelectorAll('.nav-link');
                links.forEach((el) => el.classList.remove('active'));
                if (buttonEl && buttonEl.classList) {
                    buttonEl.classList.add('active');
                } else {
                    const found = nav.querySelector(`.nav-link[data-tab="${t}"]`);
                    if (found) found.classList.add('active');
                }
            }
        } catch (e) {
            // ignore
        }

        this.loadList();
    },

    setBulkMinConfidence(value) {
        const v = parseInt(String(value ?? ''), 10);
        this.state.bulkMinConfidence = (Number.isFinite(v) && v >= 0 && v <= 100) ? v : 85;
        if (this.state.lastResponse) {
            this.renderList(this.state.lastResponse);
        }
    },

    setBulkProgress(text) {
        const el = document.getElementById('tech-sheet-bulk-progress');
        if (!el) return;
        el.textContent = text || '';
    },

    getRootEl() {
        return document.getElementById('tech-sheet-root')
            || document.querySelector('#technical-sheet .tech-sheet');
    },

    toggleLargeText() {
        const root = this.getRootEl();
        if (!root) return;
        root.classList.toggle('tech-sheet--large');
    },

    toggleHighContrast() {
        const root = this.getRootEl();
        if (!root) return;
        root.classList.toggle('tech-sheet--hc');
    },

    showJobFailuresModal(details, label, retryEndpoint, retryLabel, retryExtraPayload = null) {
        const modalEl = document.getElementById('techSheetJobResultModal');
        const bodyEl = document.getElementById('tech-sheet-job-result-body');
        const metaEl = document.getElementById('tech-sheet-job-result-meta');
        const retryBtn = document.getElementById('tech-sheet-job-retry-btn');

        const failures = details?.result?.failures;
        if (!Array.isArray(failures) || failures.length === 0) {
            return false;
        }

        const jobId = details?.id ? Number(details.id) : null;
        const failedIds = Array.from(new Set(
            failures.map(f => String(f?.item_id || '')).filter(Boolean)
        ));

        // Se modal não existir (ex.: tab ainda não renderizado), mantém fallback simples
        if (!modalEl || !bodyEl) {
            const want = confirm(`Houve ${failedIds.length} falha(s) em ${label}. Reprocessar apenas as falhas?`);
            if (!want) return true;
            this.state.lastJobFailuresRetry = {
                endpoint: retryEndpoint,
                item_ids: failedIds,
                label: retryLabel || `${label} (retry)`,
                extraPayload: (retryExtraPayload && typeof retryExtraPayload === 'object') ? retryExtraPayload : null,
            };
            this.retryFailuresFromModal();
            return true;
        }

        const rows = failures
            .slice(0, 200)
            .map((f, idx) => {
                const itemId = this.escapeHtml(String(f?.item_id || ''));
                const err = this.escapeHtml(String(f?.error || 'Falha'));
                return `
                    <tr>
                        <td class="text-muted small">${idx + 1}</td>
                        <td class="fw-semibold">${itemId}</td>
                        <td>${err}</td>
                    </tr>
                `;
            })
            .join('');

        bodyEl.innerHTML = `
            <div class="mb-2">
                <div class="fw-semibold">${this.escapeHtml(label)} — falhas</div>
                <div class="text-muted small">Você pode reprocessar apenas os anúncios que falharam.</div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 44px;">#</th>
                            <th style="width: 160px;">Item</th>
                            <th>Erro</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
            </div>
            ${failures.length > 200 ? `<div class="text-muted small mt-2">Mostrando 200 de ${failures.length} falhas.</div>` : ''}
        `;

        if (metaEl) {
            metaEl.textContent = jobId ? `Job #${jobId} • falhas: ${failures.length}` : `Falhas: ${failures.length}`;
        }

        this.state.lastJobFailuresRetry = {
            endpoint: retryEndpoint,
            item_ids: failedIds,
            label: retryLabel || `${label} (retry)`,
            extraPayload: (retryExtraPayload && typeof retryExtraPayload === 'object') ? retryExtraPayload : null,
        };

        if (retryBtn) {
            retryBtn.disabled = failedIds.length === 0;
        }

        try {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        } catch (e) {
            // ignore
        }

        return true;
    },

    async retryFailuresFromModal() {
        const cfg = this.state.lastJobFailuresRetry;
        if (!cfg?.endpoint || !Array.isArray(cfg.item_ids) || !cfg.item_ids.length) {
            SEOKiller.showInfo('Não há falhas para reprocessar.');
            return;
        }

        const retryCount = cfg.item_ids.length;
        if (!confirm(`Reprocessar ${retryCount} falha(s) agora?`)) {
            return;
        }

        try {
            this.setBulkProgress('Criando job (retry)...');
            const payload = {
                item_ids: cfg.item_ids,
                ...(cfg.extraPayload && typeof cfg.extraPayload === 'object' ? cfg.extraPayload : {})
            };
            const retry = await this.fetchJSON(cfg.endpoint, {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            const retryJobId = retry.job_id;
            if (!retryJobId) {
                throw new Error('job_id ausente na resposta de retry');
            }

            SEOKiller.showInfo(`Retry: job #${retryJobId} criado.`);
            await this.pollJob(retryJobId, cfg.label || 'Reprocessando falhas');
            await this.loadList();

            // Fecha modal se estiver aberto
            const modalEl = document.getElementById('techSheetJobResultModal');
            if (modalEl) {
                const inst = bootstrap.Modal.getInstance(modalEl);
                if (inst) inst.hide();
            }
        } catch (e) {
            this.setBulkProgress('');
            SEOKiller.showError(`Erro no retry: ${e.message}`);
        }
    },

    async copyJobFailures() {
        const failures = this.state.lastJobFailuresRetry?.item_ids || [];
        const detailsEl = document.getElementById('tech-sheet-job-result-body');
        if (!failures.length) {
            SEOKiller.showInfo('Sem falhas para copiar.');
            return;
        }

        const text = failures.join('\n');
        try {
            if (navigator?.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
                SEOKiller.showSuccess('Falhas copiadas para a área de transferência.');
                return;
            }
        } catch (e) {
            // fallback abaixo
        }

        // Fallback: cria um textarea temporário
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            SEOKiller.showSuccess('Falhas copiadas.');
        } catch (e) {
            // Último fallback: mostrar no corpo do modal
            if (detailsEl) {
                detailsEl.insertAdjacentHTML('afterbegin', `
                    <div class="alert alert-warning small">Não foi possível copiar automaticamente. Itens com falha:\n<pre class="mb-0">${this.escapeHtml(text)}</pre></div>
                `);
            }
        }
    },

    async loadList() {
        if (this.state.loading) return;
        this.state.loading = true;

        // ao recarregar, a seleção vira rapidamente inconsistente; limpamos para evitar aplicar em item errado
        this.state.selectedItemIds = new Set();
        this.setBulkProgress('');

        const container = document.getElementById('tech-sheet-list');
        if (container) {
            container.innerHTML = '<div class="text-muted py-4">Carregando ficha técnica...</div>';
        }

        try {
            const params = new URLSearchParams({
                page: String(this.state.page),
                per_page: String(this.state.perPage),
                status: 'active',
            });
            if (this.state.q) params.set('q', this.state.q);
            if (this.state.tab && this.state.tab !== 'all') params.set('tab', this.state.tab);
            if (this.state.sort) params.set('sort', this.state.sort);

            // KPIs (não bloqueia o carregamento da lista)
            this.loadStats(params).catch(() => { });

            const data = await this.fetchJSON(`/api/seo/technical-sheet/items?${params.toString()}`);
            this.state.lastResponse = data;
            this.renderList(data);
        } catch (e) {
            if (container) {
                container.innerHTML = `
                    <div class="alert alert-danger">
                        <strong>Erro ao carregar</strong>: ${e.message}
                    </div>
                `;
            }
        } finally {
            this.state.loading = false;
        }
    },

    async loadStats(params) {
        const totalEl = document.getElementById('tech-sheet-kpi-total');
        const criticalEl = document.getElementById('tech-sheet-kpi-critical');
        const hiddenEl = document.getElementById('tech-sheet-kpi-hidden');
        const modelEl = document.getElementById('tech-sheet-kpi-model');
        const pendingEl = document.getElementById('tech-sheet-kpi-pending');
        const avgEl = document.getElementById('tech-sheet-kpi-avg');

        const setLoading = () => {
            if (totalEl) totalEl.textContent = '...';
            if (criticalEl) criticalEl.textContent = '...';
            if (hiddenEl) hiddenEl.textContent = '...';
            if (modelEl) modelEl.textContent = '...';
            if (pendingEl) pendingEl.textContent = '...';
            if (avgEl) avgEl.textContent = '...';
        };

        setLoading();

        // Reaproveita os mesmos filtros do params atual, mas remove paginação
        const p = new URLSearchParams(params ? params.toString() : '');
        p.delete('page');
        p.delete('per_page');
        p.delete('sort');
        if (!p.has('status')) p.set('status', 'active');

        const stats = await this.fetchJSON(`/api/seo/technical-sheet/stats?${p.toString()}`);
        if (!(stats?.success)) {
            return;
        }

        const total = Number(stats.total_items ?? 0);
        const critical = Number(stats.critical_gap_items ?? 0);
        const hidden = Number(stats.hidden_gap_items ?? 0);
        const pendingTotal = Number(stats.pending_suggestions_total ?? 0);
        const avg = stats.avg_completeness_analyzed;
        const modelStats = stats.model_suggestions || {};
        const modelWith = Number(modelStats.items_with_suggestions ?? 0);
        const modelNeed = Number(modelStats.items_needing_model ?? 0);
        this.renderEanBanner(stats.ean_balance || {});
        this.renderHiddenBanner(stats);

        if (totalEl) totalEl.textContent = String(total);
        if (criticalEl) criticalEl.textContent = String(critical);
        if (hiddenEl) hiddenEl.textContent = String(hidden);
        if (modelEl) modelEl.textContent = `${modelWith}/${modelNeed}`;
        if (pendingEl) pendingEl.textContent = String(pendingTotal);
        if (avgEl) {
            avgEl.textContent = (avg === null || avg === undefined) ? '—' : `${Number(avg).toFixed(1)}%`;
        }
    },

    renderEanBanner(eanBalance) {
        const el = document.getElementById('tech-sheet-ean-banner');
        if (!el) return;
        const available = Number(eanBalance.available ?? 0);
        if (!Number.isFinite(available)) {
            el.classList.add('d-none');
            el.innerHTML = '';
            return;
        }
        if (available > 0) {
            el.classList.remove('d-none', 'alert-warning');
            el.classList.add('alert-light');
            el.innerHTML = `Inventário EAN: <strong>${available}</strong> disponíveis — sugestões GTIN usarão o pool (<span class="text-muted">origem: EAN do pool</span>).`;
            return;
        }
        el.classList.remove('d-none', 'alert-light');
        el.classList.add('alert-warning');
        el.innerHTML = `Sem EAN no inventário — sugestões usarão <strong>EMPTY_GTIN_REASON</strong> (motivo sem GTIN). <a href="/dashboard/ean">Abrir EAN</a>`;
    },

    techSheetSourceLabel(source) {
        const map = {
            ean_pool: 'EAN do pool',
            empty_gtin_policy: 'Motivo sem GTIN',
            hidden_seo: 'Hidden SEO',
            title: 'Título',
            benchmark: 'Benchmark',
            ai: 'IA',
            inference: 'Inferência',
            default: 'Padrão',
            manual: 'Manual',
            search_strategy: 'Estratégia de busca',
        };
        return map[source] || source || 'inference';
    },

    techSheetPolicyLabel(meta) {
        const policy = (meta && meta.policy) ? String(meta.policy) : '';
        const map = {
            semantic_field_mapping: 'ID limpo',
            umbrella_multi_compatible: 'Guarda-chuva multi',
            semantic_clean: 'ID limpo',
            hidden_seo_evidence: 'Evidência Hidden',
        };
        return map[policy] || '';
    },

    renderHiddenBanner(stats) {
        const el = document.getElementById('tech-sheet-hidden-banner');
        if (!el) return;
        const hidden = Number(stats?.hidden_gap_items ?? 0);
        if (!Number.isFinite(hidden) || hidden < 10) {
            el.classList.add('d-none');
            el.innerHTML = '';
            return;
        }
        el.classList.remove('d-none');
        el.classList.add('alert-info');
        el.innerHTML = `🔮 <strong>${hidden}</strong> itens com lacunas Hidden SEO — priorize LINE/MPN/HANDLE_RISER com evidência (origem: Hidden SEO). Use generate ou <code>suggest-hidden</code>.`;
    },

    renderList(data) {
        const container = document.getElementById('tech-sheet-list');
        if (!container) return;

        const items = data.items || [];
        const minConfEmpty = (typeof this.state.bulkMinConfidence === 'number' && this.state.bulkMinConfidence >= 0 && this.state.bulkMinConfidence <= 100)
            ? this.state.bulkMinConfidence
            : 85;
        const confOptionsEmpty = [70, 80, 85, 90, 95].map(v => {
            const sel = v === minConfEmpty ? 'selected' : '';
            return `<option value="${v}" ${sel}>${v}%</option>`;
        }).join('');

        if (!items.length) {
            container.innerHTML = `
                <div class="tech-sheet-toolbar d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <div class="text-muted small">0 itens nesta página</div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <div class="input-group input-group-sm" style="width: 210px;">
                            <span class="input-group-text">Auto-aprovar ≥</span>
                            <select class="form-select" data-tech-change="setBulkMinConfidence">
                                ${confOptionsEmpty}
                            </select>
                        </div>
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-outline-warning" type="button" title="Aprova elegíveis mesmo sem linhas na tela" data-tech-action="bulkApprovePendingSelected">Aprovar pendentes ≥ ${minConfEmpty}%</button>
                            <button class="btn btn-outline-primary" type="button" disabled>Gerar sugestões</button>
                            <button class="btn btn-outline-success" type="button" title="Aplica aprovadas elegíveis (escreve no ML)" data-tech-action="bulkApplySelected">Aplicar aprovadas</button>
                        </div>
                    </div>
                </div>
                <div class="tech-sheet-empty border rounded p-3">
                    <div class="fw-semibold mb-1">Nenhum anúncio encontrado</div>
                    <div class="text-muted">Isso normalmente acontece quando o cache local (tabela <code>items</code>) ainda não foi sincronizado.</div>
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-sm btn-primary" type="button" data-tech-action="syncItems">
                            <i class="bi bi-arrow-repeat"></i> Sincronizar anúncios
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-tech-action="loadList">
                            <i class="bi bi-arrow-clockwise"></i> Tentar novamente
                        </button>
                    </div>
                </div>
            `;
            return;
        }

        const selectedCount = (this.state.selectedItemIds && this.state.selectedItemIds.size) ? this.state.selectedItemIds.size : 0;
        const minConf = (typeof this.state.bulkMinConfidence === 'number' && this.state.bulkMinConfidence >= 0 && this.state.bulkMinConfidence <= 100)
            ? this.state.bulkMinConfidence
            : 85;
        const confOptions = [70, 80, 85, 90, 95].map(v => {
            const sel = v === minConf ? 'selected' : '';
            return `<option value="${v}" ${sel}>${v}%</option>`;
        }).join('');

        const pagination = data.pagination || {};
        const page = Number(pagination.page ?? this.state.page ?? 1) || 1;
        const pages = Math.max(1, Number(pagination.pages ?? 1) || 1);
        const totalItems = Number(pagination.total ?? items.length) || 0;
        this.state.page = page;
        const itemLabel = items.length === 1 ? 'item' : 'itens';
        const paginationHtml = pages > 1 ? `
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3" id="tech-sheet-pagination">
                <div class="text-muted small">Página ${page} de ${pages} · ${totalItems} no total</div>
                <div class="btn-group btn-group-sm" role="group" aria-label="Paginação ficha técnica">
                    <button type="button" class="btn btn-outline-secondary" ${page <= 1 ? 'disabled' : ''}
                        data-tech-action="goToPage" data-tech-args="${this.escapeAttr(JSON.stringify([page - 1]))}">Anterior</button>
                    <button type="button" class="btn btn-outline-secondary" disabled>${page}/${pages}</button>
                    <button type="button" class="btn btn-outline-secondary" ${page >= pages ? 'disabled' : ''}
                        data-tech-action="goToPage" data-tech-args="${this.escapeAttr(JSON.stringify([page + 1]))}">Próxima</button>
                </div>
            </div>
        ` : `
            <div class="text-muted small mt-2">${totalItems} ${totalItems === 1 ? 'anúncio' : 'anúncios'} no total</div>
        `;

        const asNumber = (v) => {
            const n = Number(v);
            return Number.isFinite(n) ? n : null;
        };
        const badgeHtml = (n, variant, title) => {
            const t = title ? `title="${this.escapeAttr(title)}"` : '';
            return `<span class="ts-badge ts-badge--${variant}" ${t}>${n}</span>`;
        };
        const maybeBadge = (v, variantWhenPositive, variantWhenZero, title) => {
            const n = asNumber(v);
            if (n === null) return '<span class="text-muted">—</span>';
            const variant = n > 0 ? variantWhenPositive : variantWhenZero;
            return badgeHtml(n, variant, title);
        };

        const rows = items.map((it) => {
            const completeness = asNumber(it.completeness_percent);
            const compSafe = (completeness === null) ? null : Math.max(0, Math.min(100, completeness));
            const compVariant = (compSafe === null)
                ? 'muted'
                : (compSafe < 50 ? 'danger' : (compSafe < 80 ? 'warning' : 'success'));
            const compLabel = (compSafe === null) ? '—' : `${Number(compSafe).toFixed(1)}%`;

            const pendingS = asNumber(it.pending_suggestions_count) ?? 0;
            const approvedS = asNumber(it.approved_suggestions_count) ?? 0;
            const checked = (this.state.selectedItemIds && this.state.selectedItemIds.has(it.item_id)) ? 'checked' : '';

            const missingReqHtml = maybeBadge(it.missing_required, 'danger', 'success', 'Obrigatórios faltando');
            const missingFilterHtml = maybeBadge(it.missing_filter, 'warning', 'success', 'Atributos de filtro faltando');
            const missingHiddenHtml = maybeBadge(it.missing_hidden, 'secondary', 'success', 'Atributos ocultos faltando');

            const suggestionsHtml = `
                <div class="d-flex flex-wrap gap-1">
                    <span class="ts-pill ts-pill--secondary" title="Sugestões pendentes">P ${pendingS}</span>
                    <span class="ts-pill ts-pill--success" title="Sugestões aprovadas">A ${approvedS}</span>
                </div>
            `;

            const compHtml = (compSafe === null)
                ? `<span class="text-muted">—</span>`
                : `
                    <div class="ts-completeness">
                        <div class="ts-completeness__value">${compLabel}</div>
                        <div class="ts-progress ts-progress--${compVariant}" aria-label="Completude ${this.escapeAttr(compLabel)}">
                            <span style="width: ${compSafe}%;"></span>
                        </div>
                    </div>
                `;

            return `
                <tr class="ts-row">
                    <td class="ts-col-check" style="width: 44px;">
                        <input class="form-check-input" type="checkbox" ${checked}
                            aria-label="Selecionar ${this.escapeAttr(it.item_id)}"
                            data-tech-change="toggleSelect" data-tech-args="${this.escapeAttr(JSON.stringify([it.item_id]))}" />
                    </td>
                    <td class="ts-col-actions" style="width: 120px;">
                        <button class="btn btn-sm btn-outline-primary" data-tech-action="openItem" data-tech-args="${this.escapeAttr(JSON.stringify([it.item_id]))}">Ver</button>
                    </td>
                    <td>
                        <div class="ts-title">${this.escapeHtml(it.title || it.item_id)}</div>
                        <div class="ts-meta text-muted">${this.escapeHtml(it.item_id)} · ${this.escapeHtml(it.category_id || '')}</div>
                    </td>
                    <td class="ts-col-completeness">${compHtml}</td>
                    <td class="ts-col-missing text-nowrap">${missingReqHtml}</td>
                    <td class="ts-col-missing text-nowrap">${missingFilterHtml}</td>
                    <td class="ts-col-missing text-nowrap">${missingHiddenHtml}</td>
                    <td class="ts-col-suggestions">${suggestionsHtml}</td>
                </tr>
            `;
        }).join('');

        container.innerHTML = `
            <div class="tech-sheet-toolbar d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <div class="text-muted small">${items.length} ${itemLabel} nesta página</div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <div class="text-muted small">Selecionados: <span class="fw-semibold">${selectedCount}</span></div>
                    <div id="tech-sheet-bulk-progress" class="text-muted small"></div>
                    <div class="input-group input-group-sm" style="width: 210px;">
                        <span class="input-group-text">Auto-aprovar ≥</span>
                        <select class="form-select" data-tech-change="setBulkMinConfidence">
                            ${confOptions}
                        </select>
                    </div>
                    <div class="btn-group btn-group-sm" role="group">
                        <button class="btn btn-outline-warning" type="button" title="Sem seleção: aprova todas elegíveis (active ≥ confiança). Com seleção: só os marcados." data-tech-action="bulkApprovePendingSelected">Aprovar pendentes ≥ ${minConf}%</button>
                        <button class="btn btn-outline-primary" type="button" ${selectedCount ? '' : 'disabled'} data-tech-action="bulkGenerateSelected">Gerar sugestões</button>
                        <button class="btn btn-outline-success" type="button" title="Sem seleção: aplica todas aprovadas em active. Com seleção: só os marcados. ESCREVE NO ML." data-tech-action="bulkApplySelected">Aplicar aprovadas</button>
                        <button class="btn btn-outline-secondary" type="button" ${selectedCount ? '' : 'disabled'} data-tech-action="clearSelection">Limpar</button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle ts-table">
                    <thead>
                        <tr>
                            <th style="width: 44px;" scope="col">
                                <input class="form-check-input" type="checkbox" data-tech-change="toggleSelectAll" />
                            </th>
                            <th style="width: 120px;" scope="col"></th>
                            <th scope="col">Anúncio</th>
                            <th scope="col">Completude</th>
                            <th scope="col">Obrigatórios</th>
                            <th scope="col">Filtro</th>
                            <th scope="col">Ocultos</th>
                            <th scope="col">Sugestões</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
            </div>
            ${paginationHtml}
        `;
    },

    goToPage(page) {
        const p = Math.max(1, parseInt(String(page ?? 1), 10) || 1);
        const maxPages = Number(this.state.lastResponse?.pagination?.pages ?? 0) || 0;
        if (maxPages > 0 && p > maxPages) return;
        if (p === this.state.page) return;
        this.state.page = p;
        this.state.selectedItemIds = new Set();
        this.loadList();
    },

    toggleSelect(itemId, checked) {
        const id = String(itemId || '');
        if (!id) return;

        if (!this.state.selectedItemIds || !(this.state.selectedItemIds instanceof Set)) {
            this.state.selectedItemIds = new Set();
        }

        if (checked) {
            this.state.selectedItemIds.add(id);
        } else {
            this.state.selectedItemIds.delete(id);
        }

        if (this.state.lastResponse) {
            this.renderList(this.state.lastResponse);
        }
    },

    toggleSelectAll(checked) {
        const items = this.state.lastResponse?.items || [];
        if (!this.state.selectedItemIds || !(this.state.selectedItemIds instanceof Set)) {
            this.state.selectedItemIds = new Set();
        }

        if (checked) {
            for (const it of items) {
                if (it?.item_id) this.state.selectedItemIds.add(String(it.item_id));
            }
        } else {
            this.state.selectedItemIds.clear();
        }

        if (this.state.lastResponse) {
            this.renderList(this.state.lastResponse);
        }
    },

    clearSelection() {
        if (this.state.selectedItemIds && this.state.selectedItemIds.clear) {
            this.state.selectedItemIds.clear();
        } else {
            this.state.selectedItemIds = new Set();
        }
        if (this.state.lastResponse) {
            this.renderList(this.state.lastResponse);
        }
    },

    async bulkGenerateSelected() {
        const ids = Array.from(this.state.selectedItemIds || []);
        if (!ids.length) return;

        if (!confirm(`Gerar sugestões para ${ids.length} anúncio(s) selecionado(s)?`)) {
            return;
        }

        try {
            this.setBulkProgress('Gerando sugestões...');
            const data = await this.fetchJSON('/api/seo/technical-sheet/batch/suggestions/generate', {
                method: 'POST',
                body: JSON.stringify({ item_ids: ids })
            });

            if (data?.mode === 'sync' || data?.suggestions_created_total !== undefined) {
                const created = Number(data.suggestions_created_total ?? 0);
                const failed = Number(data.failed_items ?? 0);
                const processed = Number(data.total_items ?? 0);
                this.setBulkProgress('');
                SEOKiller.showSuccess(`Geração concluída: ${created} sugestão(ões) em ${processed} anúncio(s)${failed ? ` · falhas: ${failed}` : ''}`);
                await this.loadList();
                return;
            }

            const jobId = data.job_id;
            if (!jobId) {
                throw new Error('job_id ausente na resposta');
            }

            SEOKiller.showSuccess(`Job #${jobId} criado. Processando em background...`);
            const details = await this.pollJob(jobId, 'Gerando sugestões');
            await this.loadList();

            this.showJobFailuresModal(
                details,
                'Gerando sugestões',
                '/api/seo/technical-sheet/batch/suggestions/generate',
                'Gerando sugestões (retry)'
            );
        } catch (e) {
            this.setBulkProgress('');
            SEOKiller.showError(`Erro ao gerar: ${e.message}`);
        }
    },

    async bulkApprovePendingSelected() {
        const ids = Array.from(this.state.selectedItemIds || []);
        const minConf = (typeof this.state.bulkMinConfidence === 'number' && this.state.bulkMinConfidence >= 0 && this.state.bulkMinConfidence <= 100)
            ? this.state.bulkMinConfidence
            : 85;

        const useEligibleScope = ids.length === 0;
        const confirmMsg = useEligibleScope
            ? `Aprovar TODAS as sugestões pendentes com confiança ≥ ${minConf}% em anúncios active?\n\nIsso NÃO aplica no Mercado Livre — apenas marca como aprovado.`
            : `Aprovar sugestões pendentes (com confiança ≥ ${minConf}%) para ${ids.length} anúncio(s) selecionado(s)?\n\nIsso NÃO aplica no Mercado Livre — apenas marca como aprovado.`;

        if (!confirm(confirmMsg)) {
            return;
        }

        const payload = useEligibleScope
            ? { item_ids: [], scope: 'eligible', min_confidence: minConf }
            : { item_ids: ids, min_confidence: minConf };

        try {
            this.setBulkProgress('Aprovando...');
            const data = await this.fetchJSON('/api/seo/technical-sheet/batch/approve', {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            // Compat: resposta síncrona (mode=sync) ou job legado (job_id)
            if (data?.mode === 'sync' || data?.suggestions_approved_total !== undefined) {
                const approved = Number(data.suggestions_approved_total ?? 0);
                const failed = Number(data.failed_items ?? 0);
                const processed = Number(data.total_items ?? 0);
                this.setBulkProgress('');
                SEOKiller.showSuccess(`Aprovação concluída: ${approved} sugestão(ões) em ${processed} anúncio(s)${failed ? ` · falhas: ${failed}` : ''}`);
                await this.loadList();
                return;
            }

            const jobId = data.job_id;
            if (!jobId) {
                throw new Error('job_id ausente na resposta');
            }

            SEOKiller.showSuccess(`Job #${jobId} criado. Aprovando pendentes em background (${data.total_items ?? '?'} itens)...`);
            const details = await this.pollJob(jobId, `Aprovando pendentes ≥ ${minConf}%`);
            await this.loadList();

            this.showJobFailuresModal(
                details,
                `Aprovando pendentes ≥ ${minConf}%`,
                '/api/seo/technical-sheet/batch/approve',
                `Aprovando pendentes ≥ ${minConf}% (retry)`,
                { min_confidence: minConf, scope: useEligibleScope ? 'eligible' : undefined }
            );
        } catch (e) {
            this.setBulkProgress('');
            SEOKiller.showError(`Erro ao aprovar: ${e.message}`);
        }
    },

    async bulkApplySelected() {
        const ids = Array.from(this.state.selectedItemIds || []);
        const useEligibleScope = ids.length === 0;
        const confirmMsg = useEligibleScope
            ? 'Aplicar TODAS as sugestões aprovadas de anúncios active no Mercado Livre?\n\nIsso ALTERA os anúncios no ML.'
            : `Aplicar sugestões aprovadas para ${ids.length} anúncio(s) selecionado(s) no Mercado Livre?`;

        if (!confirm(confirmMsg)) {
            return;
        }

        const payload = useEligibleScope
            ? { item_ids: [], scope: 'eligible' }
            : { item_ids: ids };

        try {
            this.setBulkProgress('Aplicando no ML...');
            const data = await this.fetchJSON('/api/seo/technical-sheet/batch/apply', {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            if (data?.mode === 'sync' || data?.attributes_applied_total !== undefined) {
                const applied = Number(data.attributes_applied_total ?? 0);
                const failed = Number(data.failed_items ?? 0);
                const processed = Number(data.total_items ?? 0);
                this.setBulkProgress('');
                SEOKiller.showSuccess(`Aplicação concluída: ${applied} atributo(s) em ${processed} anúncio(s)${failed ? ` · falhas: ${failed}` : ''}`);
                await this.loadList();
                return;
            }

            const jobId = data.job_id;
            if (!jobId) {
                throw new Error('job_id ausente na resposta');
            }

            SEOKiller.showSuccess(`Job #${jobId} criado. Aplicando no ML em background...`);
            const details = await this.pollJob(jobId, 'Aplicando aprovadas');
            await this.loadList();

            this.showJobFailuresModal(
                details,
                'Aplicando aprovadas',
                '/api/seo/technical-sheet/batch/apply',
                'Aplicando aprovadas (retry)'
            );
        } catch (e) {
            this.setBulkProgress('');
            SEOKiller.showError(`Erro ao aplicar: ${e.message}`);
        }
    },

    async pollJob(jobId, label) {
        const id = Number(jobId);
        if (!id) return null;

        const maxMs = 8 * 60 * 1000; // 8 min
        const start = Date.now();

        while (Date.now() - start < maxMs) {
            this.setBulkProgress(`${label}... (job #${id})`);

            try {
                const data = await this.fetchJSON('/api/jobs/status', {
                    method: 'POST',
                    body: JSON.stringify({ job_ids: [id] })
                });

                const job = data?.details?.[id] || data?.details?.[String(id)] || null;
                const status = String(job?.status || 'pending');

                if (status === 'completed') {
                    this.setBulkProgress('');

                    // Busca resultado do job para exibir resumo
                    try {
                        const details = await this.fetchJSON(`/api/jobs/${id}`);
                        const r = details?.result || null;
                        if (r && typeof r === 'object') {
                            const ok = r.successful_items ?? r.successful ?? null;
                            const fail = r.failed_items ?? r.failed ?? null;
                            const processed = r.processed_items ?? r.processed ?? null;

                            const created = r.suggestions_created_total ?? null;
                            const applied = r.attributes_applied_total ?? null;
                            const approved = r.suggestions_approved_total ?? null;

                            let msg = `${label}: job #${id} concluído`;
                            if (processed !== null) {
                                msg += ` • itens: ${processed}`;
                            }
                            if (ok !== null || fail !== null) {
                                msg += ` (ok: ${ok ?? 0}, falhas: ${fail ?? 0})`;
                            }
                            if (created !== null) {
                                msg += ` • sugestões criadas: ${created}`;
                            }
                            if (approved !== null) {
                                msg += ` • sugestões aprovadas: ${approved}`;
                            }
                            if (applied !== null) {
                                msg += ` • atributos aplicados: ${applied}`;
                            }
                            SEOKiller.showSuccess(msg);
                        } else {
                            SEOKiller.showSuccess(`${label}: job #${id} concluído`);
                        }

                        return details;
                    } catch (e) {
                        SEOKiller.showSuccess(`${label}: job #${id} concluído`);

                        return { id, status: 'completed', result: null };
                    }
                }
                if (status === 'failed') {
                    this.setBulkProgress('');
                    const msg = job?.error_message ? String(job.error_message) : 'Job falhou';
                    SEOKiller.showError(`${label}: job #${id} falhou: ${msg}`);
                    return { id, status: 'failed', error_message: msg };
                }
            } catch (e) {
                // Não interrompe polling por falha transitória
            }

            await new Promise(r => setTimeout(r, 2000));
        }

        this.setBulkProgress('');
        SEOKiller.showInfo(`${label}: job #${id} ainda em execução (timeout de polling). Você pode recarregar a página.`);

        return { id, status: 'timeout' };
    },

    async openItem(itemId) {
        const modalEl = document.getElementById('techSheetDetailModal');
        const bodyEl = document.getElementById('tech-sheet-detail-body');
        if (!modalEl || !bodyEl) return;

        this.state.currentItemId = itemId;
        this.state.currentDetail = null;

        bodyEl.innerHTML = '<div class="text-muted py-3">Carregando detalhes...</div>';
        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        try {
            const data = await this.fetchJSON(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}`);
            this.state.currentDetail = data;
            bodyEl.innerHTML = this.renderDetail(data);
        } catch (e) {
            bodyEl.innerHTML = `
                <div class="alert alert-danger">Erro ao carregar detalhes: ${e.message}</div>
            `;
        }
    },

    renderDetail(data) {
        const item = data.item || {};
        const summary = data.summary || {};
        const gaps = data.gaps || {};
        const missing = gaps.gaps || {};
        const suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];

        const required = (missing.required || []);
        const filter = (missing.filter || []);
        const hidden = (missing.hidden || []);
        const recommended = (missing.recommended || []);

        const list = (arr) => {
            if (!arr.length) return '<div class="text-muted">Nenhuma lacuna</div>';
            return '<ul class="mb-0">' + arr.slice(0, 20).map(g => `<li>${this.escapeHtml(g.name || g.id)} <span class="text-muted">(${this.escapeHtml(g.id)})</span></li>`).join('') + '</ul>';
        };

        const completeness = (summary.completeness_percent ?? data.completeness_percent ?? gaps.completeness ?? null);
        const compLabel = (completeness === null || completeness === undefined)
            ? '—'
            : `${Number(completeness).toFixed(1)}%`;

        const statusBadge = (status) => {
            const s = String(status || 'pending');
            const map = {
                pending: { cls: 'bg-secondary', label: 'Pendente' },
                approved: { cls: 'bg-success', label: 'Aprovada' },
                rejected: { cls: 'bg-danger', label: 'Rejeitada' },
                applied: { cls: 'bg-primary', label: 'Aplicada' },
            };
            const info = map[s] || { cls: 'bg-secondary', label: this.escapeHtml(s) };
            return `<span class="badge ${info.cls}">${info.label}</span>`;
        };

        const inputIdForAttr = (attributeId) => {
            const safe = String(attributeId || '')
                .replace(/[^a-zA-Z0-9_\-]/g, '_')
                .slice(0, 80);
            return `ts-sugg-value-${safe}`;
        };

        const suggestionsHtml = (() => {
            if (!suggestions.length) {
                return '<div class="text-muted">Nenhuma sugestão gerada ainda.</div>';
            }

            const rows = suggestions.map((s) => {
                const attributeId = s.attribute_id || '';
                const attributeName = s.attribute_name || attributeId;
                const value = s.suggested_value || '';
                const source = s.source || 'inference';
                const sourceLabel = this.techSheetSourceLabel(source);
                const policyLabel = this.techSheetPolicyLabel(s.meta || {});
                const confidence = (s.confidence ?? null);
                const status = s.status || 'pending';

                const disabled = status === 'applied' ? 'disabled' : '';
                const inputId = inputIdForAttr(attributeId);

                const confLabel = (confidence === null || confidence === undefined)
                    ? '—'
                    : `${Number(confidence).toFixed(0)}%`;

                return `
                    <tr data-ts-attribute-id="${this.escapeAttr(attributeId)}">
                        <td>
                            <div class="fw-semibold">${this.escapeHtml(attributeName)}</div>
                            <div class="text-muted small">${this.escapeHtml(attributeId)}</div>
                        </td>
                        <td style="min-width: 260px;">
                            <input id="${this.escapeAttr(inputId)}" class="form-control form-control-sm" value="${this.escapeAttr(value)}" ${disabled} />
                        </td>
                        <td class="text-nowrap">
                            <div class="text-muted small">${this.escapeHtml(sourceLabel)}</div>
                            ${policyLabel ? `<div class="text-muted small">${this.escapeHtml(policyLabel)}</div>` : ''}
                            <div class="small">${confLabel}</div>
                        </td>
                        <td class="text-nowrap">${statusBadge(status)}</td>
                        <td class="text-nowrap" style="width: 220px;">
                            <div class="btn-group btn-group-sm" role="group">
                                <button class="btn btn-outline-success" type="button" ${disabled}
                                    data-tech-action="approveSuggestion" data-tech-args="${this.escapeAttr(JSON.stringify([item.item_id, attributeId, inputId]))}">
                                    Aprovar
                                </button>
                                <button class="btn btn-outline-danger" type="button" ${disabled}
                                    data-tech-action="rejectSuggestion" data-tech-args="${this.escapeAttr(JSON.stringify([item.item_id, attributeId]))}">
                                    Rejeitar
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');

            return `
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Atributo</th>
                                <th>Valor sugerido (editável)</th>
                                <th>Fonte / Confiança</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows}
                        </tbody>
                    </table>
                </div>
            `;
        })();

        return `
            <div class="mb-3">
                <div class="fw-semibold">${this.escapeHtml(item.title || item.item_id || '')}</div>
                <div class="text-muted small">${this.escapeHtml(item.item_id || '')} · ${this.escapeHtml(item.category_id || '')}</div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-4"><div class="border rounded p-2"><div class="text-muted small">Completude</div><div class="fw-semibold">${compLabel}</div></div></div>
                <div class="col-md-4"><div class="border rounded p-2"><div class="text-muted small">Obrigatórios faltando</div><div class="fw-semibold">${required.length}</div></div></div>
                <div class="col-md-4"><div class="border rounded p-2"><div class="text-muted small">Ocultos faltando</div><div class="fw-semibold">${hidden.length}</div></div></div>
            </div>

            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-sm btn-primary" data-tech-action="generateSuggestions" data-tech-args="${this.escapeAttr(JSON.stringify([item.item_id]))}">Gerar sugestões</button>
                <button class="btn btn-sm btn-success" data-tech-action="applyApproved" data-tech-args="${this.escapeAttr(JSON.stringify([item.item_id]))}">Aplicar aprovadas</button>
                <button class="btn btn-sm btn-outline-success" data-tech-action="approveAllPending" data-tech-args="${this.escapeAttr(JSON.stringify([item.item_id, 85]))}">Aprovar pendentes ≥ 85%</button>
            </div>

            <div class="border rounded p-2 mb-3">
                <div class="fw-semibold mb-2">Sugestões</div>
                ${suggestionsHtml}
            </div>

            <div class="accordion" id="techSheetGapsAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header" id="ts-heading-req">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#ts-collapse-req" aria-expanded="true" aria-controls="ts-collapse-req">
                            Obrigatórios (${required.length})
                        </button>
                    </h2>
                    <div id="ts-collapse-req" class="accordion-collapse collapse show" aria-labelledby="ts-heading-req" data-bs-parent="#techSheetGapsAccordion">
                        <div class="accordion-body">${list(required)}</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header" id="ts-heading-filter">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#ts-collapse-filter" aria-expanded="false" aria-controls="ts-collapse-filter">
                            Filtro (${filter.length})
                        </button>
                    </h2>
                    <div id="ts-collapse-filter" class="accordion-collapse collapse" aria-labelledby="ts-heading-filter" data-bs-parent="#techSheetGapsAccordion">
                        <div class="accordion-body">${list(filter)}</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header" id="ts-heading-hidden">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#ts-collapse-hidden" aria-expanded="false" aria-controls="ts-collapse-hidden">
                            Ocultos (${hidden.length})
                        </button>
                    </h2>
                    <div id="ts-collapse-hidden" class="accordion-collapse collapse" aria-labelledby="ts-heading-hidden" data-bs-parent="#techSheetGapsAccordion">
                        <div class="accordion-body">${list(hidden)}</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header" id="ts-heading-rec">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#ts-collapse-rec" aria-expanded="false" aria-controls="ts-collapse-rec">
                            Recomendados (${recommended.length})
                        </button>
                    </h2>
                    <div id="ts-collapse-rec" class="accordion-collapse collapse" aria-labelledby="ts-heading-rec" data-bs-parent="#techSheetGapsAccordion">
                        <div class="accordion-body">${list(recommended)}</div>
                    </div>
                </div>
            </div>
        `;
    },

    async syncItems() {
        if (!confirm('Sincronizar anúncios para o cache local agora?')) return;
        try {
            const data = await this.fetchJSON('/api/items/sync', {
                method: 'POST',
                body: JSON.stringify({ limit: 50 })
            });
            if (data.success) {
                SEOKiller.showSuccess(`Sincronização OK. Itens processados: ${data.synced ?? data.count ?? '—'}`);
            } else {
                throw new Error(data.error || 'Falha ao sincronizar');
            }
            await this.loadList();
        } catch (e) {
            SEOKiller.showError('Erro ao sincronizar: ' + e.message);
        }
    },

    getInputValue(inputId) {
        const el = document.getElementById(inputId);
        if (!el) return null;
        return (el.value || '').trim();
    },

    async saveDecisions(itemId, decisions) {
        const payload = { decisions };
        const data = await this.fetchJSON(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/suggestions/decisions`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        return data;
    },

    async approveSuggestion(itemId, attributeId, inputId) {
        try {
            const value = this.getInputValue(inputId);
            const decisions = [{ attribute_id: attributeId, status: 'approved', value }];
            const data = await this.saveDecisions(itemId, decisions);
            if (data.success) {
                SEOKiller.showSuccess('Sugestão aprovada');
                await this.openItem(itemId);
            } else {
                throw new Error(data.error || 'Falha ao aprovar');
            }
        } catch (e) {
            SEOKiller.showError('Erro ao aprovar: ' + e.message);
        }
    },

    async rejectSuggestion(itemId, attributeId) {
        try {
            const decisions = [{ attribute_id: attributeId, status: 'rejected' }];
            const data = await this.saveDecisions(itemId, decisions);
            if (data.success) {
                SEOKiller.showSuccess('Sugestão rejeitada');
                await this.openItem(itemId);
            } else {
                throw new Error(data.error || 'Falha ao rejeitar');
            }
        } catch (e) {
            SEOKiller.showError('Erro ao rejeitar: ' + e.message);
        }
    },

    async approveAllPending(itemId, minConfidence) {
        const detail = this.state.currentDetail;
        const suggestions = Array.isArray(detail?.suggestions) ? detail.suggestions : [];
        if (!suggestions.length) {
            SEOKiller.showInfo('Nenhuma sugestão para aprovar');
            return;
        }

        const decisions = [];
        for (const s of suggestions) {
            if (s.status !== 'pending') continue;
            const conf = Number(s.confidence ?? 0);
            if (conf < Number(minConfidence ?? 0)) continue;

            const attributeId = s.attribute_id;
            const inputId = (function (attributeId) {
                const safe = String(attributeId || '')
                    .replace(/[^a-zA-Z0-9_\-]/g, '_')
                    .slice(0, 80);
                return `ts-sugg-value-${safe}`;
            })(attributeId);

            const value = this.getInputValue(inputId);
            decisions.push({ attribute_id: attributeId, status: 'approved', value });
        }

        if (!decisions.length) {
            SEOKiller.showInfo('Nenhuma sugestão pendente acima do limite');
            return;
        }

        if (!confirm(`Aprovar ${decisions.length} sugestão(ões) pendentes com confiança ≥ ${minConfidence}%?`)) {
            return;
        }

        try {
            const data = await this.saveDecisions(itemId, decisions);
            if (data.success) {
                SEOKiller.showSuccess(`Decisões salvas: ${data.updated ?? decisions.length}`);
                await this.openItem(itemId);
            } else {
                throw new Error(data.error || 'Falha ao salvar decisões');
            }
        } catch (e) {
            SEOKiller.showError('Erro ao salvar decisões: ' + e.message);
        }
    },

    async generateSuggestions(itemId) {
        try {
            const data = await this.fetchJSON(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/suggestions/generate`, {
                method: 'POST',
                body: JSON.stringify({})
            });
            SEOKiller.showSuccess(`Sugestões geradas: ${data.created || 0}`);
            await this.openItem(itemId);
        } catch (e) {
            SEOKiller.showError('Erro ao gerar sugestões: ' + e.message);
        }
    },

    async applyApproved(itemId) {
        try {
            const data = await this.fetchJSON(`/api/seo/technical-sheet/items/${encodeURIComponent(itemId)}/apply`, {
                method: 'POST',
                body: JSON.stringify({})
            });
            if (data.success) {
                SEOKiller.showSuccess(`Aplicado no ML: ${data.applied || 0} atributos`);
                await this.openItem(itemId);
            } else {
                throw new Error(data.error || 'Falha ao aplicar');
            }
        } catch (e) {
            SEOKiller.showError('Erro ao aplicar: ' + e.message);
        }
    },

    escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    escapeAttr(str) {
        return this.escapeHtml(str).replace(/\s/g, ' ');
    },

    // CSP (nonce + strict-dynamic) bloqueia onclick/onchange inline: toda
    // interação da Ficha Técnica usa delegação via data-tech-action/data-tech-change.
    bindDelegatedEvents() {
        if (this._delegatedEventsBound) return;
        this._delegatedEventsBound = true;

        const parseArgs = (el) => {
            if (!el.dataset.techArgs) return [];
            try {
                const parsed = JSON.parse(el.dataset.techArgs);
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        };

        document.addEventListener('click', (e) => {
            const el = e.target.closest('[data-tech-action]');
            if (!el) return;
            const action = el.dataset.techAction;
            if (typeof this[action] !== 'function') return;
            const args = parseArgs(el);
            if (action === 'setTab') {
                this[action](...args, el);
            } else {
                this[action](...args);
            }
        });

        document.addEventListener('change', (e) => {
            const el = e.target.closest('[data-tech-change]');
            if (!el) return;
            const action = el.dataset.techChange;
            if (typeof this[action] !== 'function') return;
            const args = parseArgs(el);
            const value = (el.type === 'checkbox') ? el.checked : el.value;
            this[action](...args, value);
        });
    }
};

SEOKiller.techSheet.bindDelegatedEvents();

// Auto-carregar lista ao abrir o TAB
document.addEventListener('shown.bs.tab', function (event) {
    const target = event.target;
    if (!target) return;
    if (target.id === 'technical-sheet-tab') {
        SEOKiller.techSheet.loadList();
    }
    if (target.id === 'ai-insights-tab') {
        SEOKiller.initAIInsightsTab();
    }
});

// ============================================
// MODAL HELPER FUNCTIONS
// ============================================

/**
 * Helper functions to open SEO Killer modals
 * These can be called from any component
 */

// Open Title Generator Modal
SEOKiller.openTitleGenerator = function (itemId = null) {
    const modal = document.getElementById('titleGeneratorModal');
    if (!modal) {
        console.warn('Title Generator Modal not found');
        return;
    }

    const bsModal = new bootstrap.Modal(modal);

    if (itemId && typeof TitleGenerator !== 'undefined') {
        // Pre-select the product if provided
        setTimeout(() => {
            const productSelect = document.getElementById('titleProductSelect');
            if (productSelect) {
                productSelect.value = itemId;
                productSelect.dispatchEvent(new Event('change'));
            }
        }, 300);
    }

    bsModal.show();
};

// Open Description Generator Modal
SEOKiller.openDescriptionGenerator = function (itemId = null) {
    const modal = document.getElementById('descriptionGeneratorModal');
    if (!modal) {
        console.warn('Description Generator Modal not found');
        return;
    }

    const bsModal = new bootstrap.Modal(modal);

    if (itemId) {
        setTimeout(() => {
            const productSelect = document.getElementById('descriptionProductSelect');
            if (productSelect) {
                productSelect.value = itemId;
                productSelect.dispatchEvent(new Event('change'));
            }
        }, 300);
    }

    bsModal.show();
};

// Open Attribute Filler Modal
SEOKiller.openAttributeFiller = function (itemId = null) {
    const modal = document.getElementById('attributeFillerModal');
    if (!modal) {
        console.warn('Attribute Filler Modal not found');
        return;
    }

    const bsModal = new bootstrap.Modal(modal);

    if (itemId) {
        setTimeout(() => {
            const productSelect = document.getElementById('attributeProductSelect');
            if (productSelect) {
                productSelect.value = itemId;
                productSelect.dispatchEvent(new Event('change'));
            }
        }, 300);
    }

    bsModal.show();
};

// Open Keyword Research Modal
SEOKiller.openKeywordResearch = function (itemId = null) {
    const modal = document.getElementById('keywordResearchModal');
    if (!modal) {
        console.warn('Keyword Research Modal not found');
        return;
    }

    const bsModal = new bootstrap.Modal(modal);

    if (itemId) {
        setTimeout(() => {
            const productSelect = document.getElementById('keywordProductSelect');
            if (productSelect) {
                productSelect.value = itemId;
                productSelect.dispatchEvent(new Event('change'));
            }
        }, 300);
    }

    bsModal.show();
};

// Open Image Analyzer Modal
SEOKiller.openImageAnalyzer = function (itemId = null) {
    const modal = document.getElementById('imageAnalyzerModal');
    if (!modal) {
        console.warn('Image Analyzer Modal not found');
        return;
    }

    const bsModal = new bootstrap.Modal(modal);

    if (itemId) {
        setTimeout(() => {
            const productSelect = document.getElementById('imageAnalyzerProductSelect');
            if (productSelect) {
                productSelect.value = itemId;
                productSelect.dispatchEvent(new Event('change'));
            }
        }, 300);
    }

    bsModal.show();
};

// Open Competitor Spy Modal
SEOKiller.openCompetitorSpy = function () {
    const modal = document.getElementById('competitorSpyModal');
    if (!modal) {
        // Try switching to tab if modal doesn't exist
        const tab = document.getElementById('competitor-spy-tab');
        if (tab) {
            tab.click();
            return;
        }
        console.warn('Competitor Spy Modal/Tab not found');
        return;
    }

    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
};

// 🕵️ Analyze Competitors for a specific item (E13 Strategy)
SEOKiller.analyzeCompetitors = async function (itemId) {
    if (!itemId) {
        this.showError('ID do item não fornecido');
        return null;
    }

    this.showInfo('Analisando concorrência...');

    try {
        const data = await this.utils.fetchAPI(`/api/seo-killer/competitors/analyze/${itemId}`, {
            method: 'POST'
        });

        if (data && data.analyses && data.analyses.E13_COMPETITOR) {
            const result = data.analyses.E13_COMPETITOR;
            const score = result.score || 0;
            const recommendations = result.recommendations || [];

            // Show success with score
            this.showSuccess(`Análise concluída! Score Competitivo: ${score}/100`);

            // If there's a container for results, populate it
            const resultsContainer = document.getElementById('competitorAnalysisResults');
            if (resultsContainer) {
                this.renderCompetitorAnalysisResults(resultsContainer, result, data);
            }

            return data;
        } else if (data && data.error) {
            this.showError(data.error);
            return null;
        } else {
            this.showInfo('Análise concluída, mas sem dados de concorrência.');
            return data;
        }
    } catch (error) {
        this.showError(`Erro ao analisar concorrência: ${error.message}`);
        return null;
    }
};

// Render competitor analysis results
SEOKiller.renderCompetitorAnalysisResults = function (container, competitorData, fullData) {
    const score = competitorData.score || 0;
    const scoreClass = this.utils.getScoreColor(score);
    const details = competitorData.details || {};
    const recommendations = competitorData.recommendations || [];

    let html = `
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-trophy me-2"></i>Score Competitivo</span>
                <span class="badge bg-${scoreClass} fs-6">${score}/100</span>
            </div>
            <div class="card-body">
    `;

    // Comparison Details
    if (details.comparison) {
        const comp = details.comparison;
        html += `
            <div class="row text-center mb-3">
                <div class="col-md-3">
                    <div class="metric-card p-2">
                        <small class="text-muted">Preço</small>
                        <div><strong>${comp.price?.status || 'N/A'}</strong></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card p-2">
                        <small class="text-muted">Título</small>
                        <div><strong>${comp.title_length?.status || 'N/A'}</strong></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card p-2">
                        <small class="text-muted">Atributos</small>
                        <div><strong>${comp.attributes?.status || 'N/A'}</strong></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card p-2">
                        <small class="text-muted">Imagens</small>
                        <div><strong>${comp.images?.status || 'N/A'}</strong></div>
                    </div>
                </div>
            </div>
        `;
    }

    // Recommendations
    if (recommendations.length > 0) {
        html += `<h6 class="mt-3"><i class="fas fa-lightbulb me-2"></i>Recomendações</h6><ul class="list-group list-group-flush">`;
        recommendations.forEach(rec => {
            html += `<li class="list-group-item"><i class="fas fa-arrow-right text-primary me-2"></i>${rec.action || rec.message}</li>`;
        });
        html += `</ul>`;
    }

    html += `</div></div>`;

    container.innerHTML = html;
};

// Open Bulk Optimizer Modal
SEOKiller.openBulkOptimizer = function () {
    const modal = document.getElementById('bulkOptimizerModal');
    if (!modal) {
        console.warn('Bulk Optimizer Modal not found');
        return;
    }

    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
};

// Open AutoPilot Config Modal
if (typeof SEOKiller.configureAutoPilot !== 'function') {
    SEOKiller.configureAutoPilot = function () {
        const modal = document.getElementById('autopilotConfigModal');
        if (!modal) {
            console.warn('AutoPilot Config Modal not found');
            return;
        }

        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    };
}

// Open Pricing Optimizer (if exists)
SEOKiller.openPricingOptimizer = function (itemId = null) {
    const modal = document.getElementById('pricingOptimizerModal');
    if (!modal) {
        console.warn('Pricing Optimizer Modal not found');
        SEOKiller.showInfo('Otimizador de Preços será disponibilizado em breve!');
        return;
    }

    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
};

// ============================================
// QUICK ACTIONS
// ============================================

SEOKiller.quickActions = {
    // Run quick diagnosis
    async runQuickDiagnosis() {
        SEOKiller.showInfo('Executando diagnóstico...');
        try {
            const data = await SEOKiller.utils.fetchAPI('/api/seo-killer/diagnose');
            if (data.success) {
                SEOKiller.showSuccess(`Diagnóstico: ${data.stats.pending || 0} anúncios precisam de atenção!`);
            }
        } catch (error) {
            SEOKiller.showError('Erro ao executar diagnóstico');
        }
    },

    // Quick optimize single item
    async optimizeItem(itemId) {
        if (!itemId) {
            SEOKiller.showError('ID do item não fornecido');
            return;
        }

        SEOKiller.showInfo('Otimizando anúncio...');
        try {
            const data = await SEOKiller.utils.fetchAPI('/api/seo-killer/optimize', {
                method: 'POST',
                body: JSON.stringify({ item_id: itemId })
            });

            if (data.success) {
                SEOKiller.showSuccess('Anúncio otimizado com sucesso!');
                return data;
            } else {
                throw new Error(data.error || 'Erro desconhecido');
            }
        } catch (error) {
            SEOKiller.showError('Erro ao otimizar: ' + error.message);
            throw error;
        }
    },

    // Navigate to a tab
    goToTab(tabName) {
        const tabId = `${tabName}-tab`;
        const tab = document.getElementById(tabId);
        if (tab) {
            tab.click();
        }
    }
};

// ============================================
// KEYBOARD SHORTCUTS
// ============================================

document.addEventListener('keydown', function (e) {
    // Only trigger if not in an input field
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
        return;
    }

    // Ctrl/Cmd + Shift + ...
    if ((e.ctrlKey || e.metaKey) && e.shiftKey) {
        switch (e.key.toLowerCase()) {
            case 't': // Title Generator
                e.preventDefault();
                SEOKiller.openTitleGenerator();
                break;
            case 'd': // Description Generator
                e.preventDefault();
                SEOKiller.openDescriptionGenerator();
                break;
            case 'k': // Keyword Research
                e.preventDefault();
                SEOKiller.openKeywordResearch();
                break;
            case 'b': // Bulk Optimizer
                e.preventDefault();
                SEOKiller.openBulkOptimizer();
                break;
            case 'a': // AutoPilot
                e.preventDefault();
                SEOKiller.configureAutoPilot();
                break;
        }
    }
});

// ============================================
// A/B TESTING FUNCTIONS
// ============================================

// Create A/B Test for Title with AI-generated variant
SEOKiller.createTitleABTest = async function (itemId) {
    if (!itemId) {
        this.showError('ID do item não fornecido');
        return null;
    }

    this.showInfo('Gerando título otimizado e criando teste A/B...');

    try {
        const data = await this.utils.fetchAPI(`/api/seo-killer/ab-test/title/${itemId}`, {
            method: 'POST'
        });

        if (data && data.success) {
            this.showSuccess(`Teste A/B criado! ID: ${data.test_id}`);
            return data;
        } else if (data && data.error) {
            this.showError(data.error);
            return null;
        }
    } catch (error) {
        this.showError(`Erro ao criar teste A/B: ${error.message}`);
        return null;
    }
};

// List all A/B Tests
SEOKiller.listABTests = async function () {
    try {
        const data = await this.utils.fetchAPI('/api/seo-killer/ab-test');
        return data || [];
    } catch (error) {
        this.showError(`Erro ao listar testes A/B: ${error.message}`);
        return [];
    }
};

// Stop an A/B Test
SEOKiller.stopABTest = async function (testId) {
    if (!testId) return;

    try {
        const data = await this.utils.fetchAPI(`/api/seo-killer/ab-test/stop/${testId}`, {
            method: 'POST'
        });

        if (data && data.success) {
            this.showSuccess('Teste A/B parado.');
            return data;
        }
    } catch (error) {
        this.showError(`Erro ao parar teste: ${error.message}`);
    }
};

// Get A/B Test Analysis
SEOKiller.getABTestAnalysis = async function (testId) {
    try {
        const data = await this.utils.fetchAPI(`/api/seo-killer/ab-test/analysis/${testId}`);
        return data;
    } catch (error) {
        this.showError(`Erro ao obter análise: ${error.message}`);
        return null;
    }
};

// Render A/B Tests List
SEOKiller.renderABTestsList = function (container, tests) {
    if (!tests || tests.length === 0) {
        container.innerHTML = '<div class="alert alert-info">Nenhum teste A/B encontrado.</div>';
        return;
    }

    let html = '<div class="list-group">';
    tests.forEach(test => {
        const statusClass = test.status === 'running' ? 'success' : 'secondary';
        html += `
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <strong>${test.type}</strong> - Item: ${test.item_id}
                    <br><small class="text-muted">Criado: ${test.created_at}</small>
                </div>
                <div>
                    <span class="badge bg-${statusClass} me-2">${test.status}</span>
                    ${test.status === 'running' ?
                `<button class="btn btn-sm btn-danger" onclick="SEOKiller.stopABTest(${test.id})">Parar</button>` :
                `<button class="btn btn-sm btn-info" onclick="SEOKiller.getABTestAnalysis(${test.id})">Ver Análise</button>`
            }
                </div>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
};
