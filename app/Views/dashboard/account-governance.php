<?php

declare(strict_types=1);

/**
 * Governança da Conta - Dashboard de Governança e Recuperação
 * Motor de diagnóstico, classificação de itens, plano semanal e ações
 */
$pageTitle = $pageTitle ?? 'Governança da Conta';
$currentPage = $currentPage ?? 'account-governance';
?>

<style>
    .governance-hero {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
        border-radius: 1.25rem;
        padding: 2.5rem;
        color: white;
        margin-bottom: 2rem;
    }

    .governance-hero h1 {
        font-size: 1.75rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .governance-hero p {
        opacity: 0.8;
        margin-bottom: 0;
    }

    .governance-card {
        border-radius: 1rem;
        border: 1px solid #e2e8f0;
        padding: 1.5rem;
        margin-bottom: 1rem;
        background: white;
    }

    .status-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-travada {
        background: #fee2e2;
        color: #991b1b;
    }

    .status-penalizada {
        background: #fed7aa;
        color: #9a3412;
    }

    .status-recuperacao {
        background: #fef3c7;
        color: #92400e;
    }

    .status-estavel {
        background: #d1fae5;
        color: #065f46;
    }

    .status-forte {
        background: #dbeafe;
        color: #1e40af;
    }

    .classification-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 0.75rem;
    }

    .classification-item {
        padding: 0.75rem;
        border-radius: 0.5rem;
        border: 1px solid #e2e8f0;
        text-align: center;
    }

    .week-plan-day {
        padding: 1rem;
        border-left: 3px solid #3b82f6;
        margin-bottom: 0.75rem;
        background: #f8fafc;
        border-radius: 0 0.5rem 0.5rem 0;
    }

    .action-card {
        padding: 0.75rem;
        border-radius: 0.5rem;
        border: 1px solid #e2e8f0;
        margin-bottom: 0.5rem;
    }

    .action-card.critical {
        border-left: 4px solid #dc2626;
    }

    .action-card.high {
        border-left: 4px solid #ea580c;
    }

    .action-card.medium {
        border-left: 4px solid #ca8a04;
    }

    .action-card.low {
        border-left: 4px solid #16a34a;
    }

    .phase-card {
        padding: 1.25rem;
        border-radius: 0.75rem;
        margin-bottom: 1rem;
    }

    .phase-estancar {
        background: linear-gradient(135deg, #fef2f2, #fee2e2);
        border: 1px solid #fecaca;
    }

    .phase-estabilizar {
        background: linear-gradient(135deg, #fffbeb, #fef3c7);
        border: 1px solid #fde68a;
    }

    .phase-crescer {
        background: linear-gradient(135deg, #f0fdf4, #dcfce7);
        border: 1px solid #bbf7d0;
    }

    .metric-card {
        text-align: center;
        padding: 1.5rem;
        background: #f8fafc;
        border-radius: 0.75rem;
    }

    .metric-value {
        font-size: 2rem;
        font-weight: 700;
        color: #1e293b;
    }

    .metric-label {
        font-size: 0.875rem;
        color: #64748b;
        margin-top: 0.25rem;
    }

    .loading-spinner {
        display: inline-block;
        width: 1.5rem;
        height: 1.5rem;
        border: 2px solid #e2e8f0;
        border-top-color: #3b82f6;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #64748b;
    }

    .causes-list li {
        padding: 0.75rem 0;
        border-bottom: 1px solid #e2e8f0;
    }

    .causes-list li:last-child {
        border-bottom: none;
    }

    /* Evita flash de elementos x-show antes do Alpine inicializar */
    [x-cloak] { display: none !important; }
</style>

<div x-data="governanceApp()" x-init="init()">
    <!-- Hero Section -->
    <div class="governance-hero">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h1>Governança da Conta</h1>
                <p>Motor de diagnóstico, classificação de itens e plano de recuperação</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="useRealDataToggle"
                        x-model="useRealData"
                        style="cursor: pointer;">
                    <label class="form-check-label text-white" for="useRealDataToggle" style="cursor: pointer;">
                        <span x-show="useRealData">Dados Reais (ML API)</span>
                        <span x-show="!useRealData">Dados de Teste</span>
                    </label>
                </div>
                <button
                    @click="runDiagnostic()"
                    :disabled="loading"
                    class="btn btn-light">
                    <span x-show="!loading">Executar Diagnóstico</span>
                    <span x-show="loading" class="loading-spinner"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Loading State -->
    <div x-show="loading" x-cloak class="text-center py-5">
        <div class="loading-spinner" style="width: 3rem; height: 3rem;"></div>
        <p class="mt-3 text-muted">Processando diagnóstico...</p>
    </div>

    <!-- Error State -->
    <div x-show="error" x-cloak class="alert alert-danger" x-text="error"></div>

    <!-- Results -->
    <template x-if="result && !loading">
        <div>
            <!-- Executive Summary -->
            <div class="governance-card">
                <h5 class="mb-3">Resumo Executivo</h5>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span
                        class="status-badge"
                        :class="'status-' + result.account_status.toLowerCase()"
                        x-text="result.account_status"></span>
                    <span class="text-muted" x-text="result.executive_summary.headline"></span>
                </div>

                <div class="row g-3 mt-3">
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value" x-text="result.executive_summary.total_items"></div>
                            <div class="metric-label">Total de Itens</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value text-success" x-text="result.executive_summary.healthy_items"></div>
                            <div class="metric-label">Itens Saudáveis</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value text-danger" x-text="result.executive_summary.problem_items"></div>
                            <div class="metric-label">Itens Problemáticos</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value text-warning" x-text="result.executive_summary.critical_actions"></div>
                            <div class="metric-label">Ações Críticas</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Top Causes -->
                <div class="col-md-6">
                    <div class="governance-card">
                        <h5 class="mb-3">Top 5 Causas</h5>
                        <ul class="causes-list list-unstyled mb-0">
                            <template x-for="cause in result.top_causes" :key="cause.cause">
                                <li>
                                    <strong x-text="cause.cause"></strong>
                                    <small class="d-block text-muted" x-text="cause.description"></small>
                                    <small class="text-primary" x-text="'Correção: ' + cause.fix"></small>
                                </li>
                            </template>
                        </ul>
                        <div x-show="!result.top_causes.length" class="empty-state">
                            Nenhuma causa identificada
                        </div>
                    </div>
                </div>

                <!-- Classification Breakdown -->
                <div class="col-md-6">
                    <div class="governance-card">
                        <h5 class="mb-3">Classificação dos Itens</h5>
                        <div class="classification-grid">
                            <template x-for="(count, classification) in result.executive_summary.classification_breakdown" :key="classification">
                                <div class="classification-item">
                                    <div class="fw-bold" x-text="count"></div>
                                    <small class="text-muted" x-text="classification"></small>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recovery Plan -->
            <div class="governance-card">
                <h5 class="mb-3">Plano de Recuperação</h5>
                <p class="text-muted mb-3">
                    Tempo estimado: <strong x-text="result.recovery_plan.estimated_recovery"></strong>
                </p>

                <template x-for="phase in result.recovery_plan.phases" :key="phase.name">
                    <div
                        class="phase-card"
                        :class="'phase-' + phase.name.toLowerCase()">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="mb-0" x-text="phase.name + ' (' + phase.duration + ')'"></h6>
                            <span class="badge bg-secondary" x-text="phase.items.length + ' itens'"></span>
                        </div>
                        <p class="mb-2 small" x-text="phase.objective"></p>
                        <div class="small text-muted">
                            <strong>KPIs:</strong>
                            <template x-for="kpi in phase.kpis" :key="kpi">
                                <span class="badge bg-light text-dark me-1" x-text="kpi"></span>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Week Plan -->
            <div class="governance-card">
                <h5 class="mb-3">Plano Semanal</h5>
                <template x-for="day in result.week_plan" :key="day.day">
                    <div class="week-plan-day">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong x-text="'Dia ' + day.day + ': ' + day.theme"></strong>
                            <span class="badge bg-primary" x-text="day.actions.length + ' ações'"></span>
                        </div>
                        <small class="text-muted d-block mb-2" x-text="day.focus"></small>
                        <small class="text-info" x-text="day.kpi_check"></small>
                    </div>
                </template>
            </div>

            <!-- Success Criteria -->
            <div class="row">
                <div class="col-md-6">
                    <div class="governance-card">
                        <h5 class="mb-3 text-success">Critérios de Sucesso</h5>
                        <ul class="mb-0">
                            <template x-for="criterion in result.success_criteria" :key="criterion">
                                <li x-text="criterion"></li>
                            </template>
                        </ul>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="governance-card">
                        <h5 class="mb-3 text-danger">Critérios de Rollback</h5>
                        <ul class="mb-0">
                            <template x-for="criterion in result.rollback_criteria" :key="criterion">
                                <li x-text="criterion"></li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Meta Info -->
            <div class="governance-card">
                <div class="d-flex justify-content-between align-items-center text-muted small">
                    <span>
                        Processados: <strong x-text="result.meta.processed_items"></strong> itens
                    </span>
                    <span>
                        Tempo: <strong x-text="result.meta.elapsed_ms + 'ms'"></strong>
                    </span>
                    <span>
                        Engine: <strong x-text="result.meta.engine_version"></strong>
                    </span>
                </div>
            </div>
        </div>
    </template>

    <!-- Empty State -->
    <div x-show="!result && !loading && !error" class="empty-state governance-card">
        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="currentColor" class="mb-3 text-muted" viewBox="0 0 16 16">
            <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z" />
            <path d="M5.255 5.786a.237.237 0 0 0 .241.247h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286zm1.557 5.763c0 .533.425.927 1.01.927.609 0 1.028-.394 1.028-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94z" />
        </svg>
        <h5>Nenhum diagnóstico executado</h5>
        <p>Clique em "Executar Diagnóstico" para analisar sua conta</p>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
    function governanceApp() {
        return {
            loading: false,
            error: null,
            result: null,
            useRealData: localStorage.getItem('gov_use_real_data') === 'true',

            init() {
                // Watch for toggle changes
                this.$watch('useRealData', (value) => {
                    localStorage.setItem('gov_use_real_data', value);
                });
            },

            async runDiagnostic() {
                this.loading = true;
                this.error = null;
                // Usar ApiClient para CSRF automático, retry em 429/503 e tratamento de 401
                const apiFetch = window.ApiClient ? window.ApiClient.fetch : (u, o) => fetch(u, o);

                try {
                    let response;

                    if (this.useRealData) {
                        // Call real ML API endpoint
                        response = await apiFetch('/api/account-governance/diagnostic-ml', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                max_items: 200
                            }),
                        });
                    } else {
                        // Use sample data endpoint
                        const sampleData = this.getSampleData();
                        response = await apiFetch('/api/account-governance/diagnostic', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify(sampleData),
                        });
                    }

                    const data = await response.json();

                    if (!response.ok) {
                        throw new Error(data.error || 'Erro no diagnóstico');
                    }

                    this.result = data.data;
                } catch (err) {
                    this.error = err.message;
                } finally {
                    this.loading = false;
                }
            },

            getSampleData() {
                // Sample data for testing
                return {
                    account_data: {
                        seller_id: 'SELLER123',
                        reputation_level: 'green',
                        total_sales_60d: 150,
                        claims_rate: 0.01,
                        late_shipment_rate: 0.02,
                        cancellation_rate: 0.01,
                    },
                    items: [{
                            id: 'MLB001',
                            title: 'Bagageiro CG 160 Titan',
                            price: 89.90,
                            status: 'active',
                            available_quantity: 15,
                            visits_30d: 500,
                            visits_14d: 250,
                            sales_30d: 20,
                            sales_14d: 10,
                            margin_pct: 0.15,
                            category_id: 'MLB1234',
                        },
                        {
                            id: 'MLB002',
                            title: 'Retrovisor Universal Moto',
                            price: 45.00,
                            status: 'active',
                            available_quantity: 0,
                            visits_30d: 200,
                            visits_14d: 100,
                            sales_30d: 8,
                            sales_14d: 4,
                            margin_pct: 0.12,
                            category_id: 'MLB1234',
                        },
                        {
                            id: 'MLB003',
                            title: 'Protetor de Motor CG',
                            price: 120.00,
                            status: 'active',
                            available_quantity: 5,
                            visits_30d: 50,
                            visits_14d: 20,
                            sales_30d: 0,
                            sales_14d: 0,
                            margin_pct: 0.08,
                            category_id: 'MLB1235',
                        },
                    ],
                    seller_context: {},
                };
            },
        };
    }
</script>
