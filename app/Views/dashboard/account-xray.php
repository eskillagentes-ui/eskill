<?php

declare(strict_types=1);

/**
 * View: Dashboard Raio X — Diagnóstico Sistemático de Conta ML
 *
 * @var string $pageTitle
 * @var string $currentPage
 */
?>
<div class="xray-page" id="xray-app">

  <!-- ─── HEADER ──────────────────────────────────────────────── -->
  <div class="page-header d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="h3 mb-1 fw-bold">
        <i class="bi bi-radioactive text-danger me-2"></i>
        Raio X da Conta
      </h1>
      <p class="text-muted mb-0">Diagnóstico sistemático completo — saúde, SEO, lacunas ocultas e plano de recuperação</p>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-outline-secondary btn-sm" data-action="load-history">
        <i class="bi bi-clock-history me-1"></i> Histórico
      </button>
      <a class="btn btn-outline-success btn-sm d-none" id="btn-export-pdf"
        href="#" data-action="export-pdf"
        title="Exportar relatório atual como PDF">
        <i class="bi bi-file-earmark-pdf me-1"></i> Exportar PDF
      </a>
      <button class="btn btn-primary btn-sm" data-action="open-run-modal" id="btn-start-xray">
        <i class="bi bi-play-fill me-1"></i> Iniciar Raio X
      </button>
    </div>
  </div>

  <!-- ─── CONTA SELECTOR ─────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-4" id="account-selector-card">
    <div class="card-body">
      <h6 class="card-title text-muted fw-semibold mb-3">
        <i class="bi bi-person-badge me-1"></i> CONTAS CONECTADAS
      </h6>
      <div id="accounts-grid" class="row g-3">
        <div class="col-12 text-center py-4 text-muted" id="accounts-loading" data-accounts-state="loading">
          <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
          <span>Carregando contas...</span>
        </div>
      </div>
    </div>
  </div>

  <!-- ─── RUNNING STATE ──────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-4 d-none" id="running-card">
    <div class="card-body text-center py-5">
      <div class="mb-3">
        <div class="spinner-border text-danger" style="width:3rem;height:3rem;" role="status"></div>
      </div>
      <h5 class="fw-bold mb-1" id="running-phase">Iniciando diagnóstico...</h5>
      <p class="text-muted mb-3" id="running-detail">Conectando à API do Mercado Livre</p>
      <div class="progress mx-auto" style="max-width:400px;height:8px;">
        <div class="progress-bar progress-bar-striped progress-bar-animated bg-danger"
          role="progressbar" style="width:100%" id="running-progress"></div>
      </div>
      <small class="text-muted d-block mt-2" id="running-elapsed">Isso pode levar 1-3 minutos...</small>
    </div>
  </div>

  <!-- ─── RESULT DASHBOARD ───────────────────────────────────── -->
  <div id="result-dashboard" class="d-none">

    <!-- SCORE GERAL + STATUS -->
    <div class="row g-3 mb-4" id="summary-cards">
      <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body text-center py-4">
            <div class="score-circle mx-auto mb-2" id="score-circle">
              <span id="score-value">--</span>
            </div>
            <div class="fw-bold mb-1">Score Geral</div>
            <small class="text-muted">0 = crítico · 100 = excelente</small>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body d-flex flex-column justify-content-center">
            <div class="text-muted small mb-1">STATUS DA CONTA</div>
            <div class="h5 fw-bold mb-2" id="account-status-badge">--</div>
            <div class="text-muted small" id="main-bottleneck">Analisando...</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body">
            <div class="text-muted small mb-2">ANÚNCIOS ANALISADOS</div>
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="small text-muted">Total</span>
              <strong id="stat-total">--</strong>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="small text-muted">SEO abaixo de 50</span>
              <strong class="text-danger" id="stat-below50">--</strong>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <span class="small text-muted">Issues críticas</span>
              <strong class="text-danger" id="stat-critical">--</strong>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body">
            <div class="text-muted small mb-2">RECUPERAÇÃO ESTIMADA</div>
            <div class="h4 fw-bold mb-1" id="stat-recovery">--</div>
            <div class="text-muted small">com plano executado</div>
            <div class="mt-2">
              <span class="badge bg-success-subtle text-success" id="stat-strengths">0 pontos fortes</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- DIAGNÓSTICO: PROBLEMAS -->
    <div class="row g-3 mb-4">
      <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header border-0 bg-transparent d-flex align-items-center justify-content-between">
            <h6 class="fw-bold mb-0"><i class="bi bi-exclamation-triangle text-warning me-1"></i> Problemas Identificados</h6>
            <span class="badge bg-danger" id="problems-count">0</span>
          </div>
          <div class="card-body p-0">
            <div id="problems-list" class="list-group list-group-flush">
              <div class="list-group-item text-center text-muted py-4">Nenhum problema ainda</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header border-0 bg-transparent">
            <h6 class="fw-bold mb-0"><i class="bi bi-graph-up text-success me-1"></i> Pontos Fortes</h6>
          </div>
          <div class="card-body p-0">
            <div id="strengths-list" class="list-group list-group-flush">
              <div class="list-group-item text-center text-muted py-4">—</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- TABS: SEO / LACUNAS / FINANCEIRO / PLANO -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header border-0 bg-transparent pt-3 pb-0">
        <ul class="nav nav-tabs card-header-tabs" id="xray-tabs">
          <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#tab-seo">
              <i class="bi bi-search me-1"></i> SEO por Anúncio
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-gaps">
              <i class="bi bi-eye-slash me-1"></i> Lacunas Ocultas
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-longtail">
              <i class="bi bi-tag me-1"></i> Cauda Longa
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-financial">
              <i class="bi bi-cash-coin me-1"></i> Mercado Pago
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-plan">
              <i class="bi bi-calendar-check me-1"></i> Plano de Ação
            </a>
          </li>
        </ul>
      </div>
      <div class="card-body tab-content pt-3" id="xray-tab-content">

        <!-- TAB: SEO -->
        <div class="tab-pane fade show active" id="tab-seo">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
              <strong>Score SEO Médio: </strong>
              <span class="badge" id="avg-seo-badge">--</span>
            </div>
            <div class="d-flex gap-2">
              <select class="form-select form-select-sm" style="width:auto;" id="seo-filter-class" data-changeaction="filter-seo-items">
                <option value="">Todos</option>
                <option value="TOXICO">🔴 Tóxico</option>
                <option value="POLUIDOR">🟠 Poluidor</option>
                <option value="MORTO">⚫ Morto</option>
                <option value="FRACO">🟡 Fraco</option>
                <option value="EM_RISCO">🟡 Em Risco</option>
                <option value="SAUDAVEL">🟢 Saudável</option>
                <option value="ANCHOR">⭐ Anchor</option>
              </select>
              <select class="form-select form-select-sm" style="width:auto;" id="seo-sort" data-changeaction="filter-seo-items">
                <option value="seo_score_asc">SEO Score ↑</option>
                <option value="seo_score_desc">SEO Score ↓</option>
                <option value="visits_desc">Visitas ↓</option>
              </select>
            </div>
          </div>
          <div id="seo-items-table">
            <p class="text-center text-muted py-4">Execute o Raio X para ver os resultados</p>
          </div>
        </div>

        <!-- TAB: LACUNAS OCULTAS -->
        <div class="tab-pane fade" id="tab-gaps">
          <div class="row g-3">
            <div class="col-md-6">
              <h6 class="fw-semibold mb-3">
                <i class="bi bi-eye-slash text-danger me-1"></i>
                Lacunas Ocultas — Concorrentes usam, você não usa
              </h6>
              <div id="hidden-gaps-list">
                <p class="text-muted">Execute o Raio X para ver lacunas</p>
              </div>
            </div>
            <div class="col-md-6">
              <h6 class="fw-semibold mb-3">
                <i class="bi bi-lightning text-warning me-1"></i>
                Oportunidades de Keywords
              </h6>
              <div id="opportunity-kws-list">
                <p class="text-muted">—</p>
              </div>
            </div>
          </div>
        </div>

        <!-- TAB: CAUDA LONGA -->
        <div class="tab-pane fade" id="tab-longtail">
          <h6 class="fw-semibold mb-3">
            <i class="bi bi-tag text-primary me-1"></i>
            Keywords de Cauda Longa — Baixa concorrência, alta conversão
          </h6>
          <div class="alert alert-info border-0 py-2 mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Use estas keywords nos <strong>títulos</strong>, descrições e fichas técnicas. Elas convertem mais porque o usuário já sabe o que quer.
          </div>
          <div id="longtail-list" class="row g-2">
            <p class="text-muted col-12">Execute o Raio X para ver keywords</p>
          </div>
        </div>

        <!-- TAB: FINANCEIRO -->
        <div class="tab-pane fade" id="tab-financial">
          <div id="financial-content">
            <div class="row g-3" id="financial-cards">
              <div class="col-12 text-center py-4 text-muted">
                <i class="bi bi-bank2 fs-2 mb-2 d-block"></i>
                Dados do Mercado Pago aparecerão aqui após o Raio X
              </div>
            </div>
          </div>
        </div>

        <!-- TAB: PLANO DE AÇÃO -->
        <div class="tab-pane fade" id="tab-plan">
          <div id="plan-content">
            <p class="text-muted text-center py-4">Execute o Raio X para ver o plano</p>
          </div>
        </div>

      </div><!-- /tab-content -->
    </div><!-- /card -->

  </div><!-- /result-dashboard -->

  <!-- ─── HISTÓRICO ───────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm d-none" id="history-card">
    <div class="card-header border-0 bg-transparent d-flex align-items-center justify-content-between">
      <h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-1"></i> Histórico de Relatórios</h6>
      <button class="btn btn-sm btn-outline-secondary" data-action="close-history-card">
        <i class="bi bi-x"></i>
      </button>
    </div>
    <div class="card-body p-0">
      <div id="history-table"></div>
    </div>
  </div>

</div><!-- /xray-page -->

<!-- ─── MODAL: configurar e iniciar Raio X ─────────────────────── -->
<div class="modal fade" id="xray-run-modal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold">
          <i class="bi bi-radioactive text-danger me-2"></i>
          Configurar Raio X
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <div class="mb-3">
          <label class="form-label fw-semibold">Conta a analisar</label>
          <select class="form-select" id="modal-account-select">
            <option value="">Carregando contas...</option>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">Máximo de anúncios</label>
          <select class="form-select" id="modal-max-items">
            <option value="50">50 anúncios (rápido ~30s)</option>
            <option value="100">100 anúncios (~1min)</option>
            <option value="200" selected>200 anúncios (~2min) — Recomendado</option>
            <option value="500">500 anúncios (~5min)</option>
          </select>
        </div>

        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" id="modal-include-paused" checked>
          <label class="form-check-label" for="modal-include-paused">
            Incluir anúncios pausados
          </label>
        </div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" id="modal-deep-seo">
          <label class="form-check-label" for="modal-deep-seo">
            Análise semântica profunda (IA) — mais lento
          </label>
        </div>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" id="modal-include-financial" checked>
          <label class="form-check-label" for="modal-include-financial">
            Incluir análise Mercado Pago
          </label>
        </div>

        <div class="alert alert-warning border-0 py-2 small">
          <i class="bi bi-clock me-1"></i>
          A análise consome chamadas de API do Mercado Livre. Use com moderação.
        </div>
      </div>
      <div class="modal-footer border-0">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger px-4 fw-semibold" data-action="start-xray" id="btn-modal-start">
          <i class="bi bi-play-fill me-1"></i> Iniciar Diagnóstico
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ─── MODAL: Aplicar Plano de Recuperação ───────────────────── -->
<div class="modal fade" id="apply-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content shadow-lg border-0">
      <div class="modal-header bg-success bg-opacity-10 border-0">
        <h5 class="modal-title fw-bold">
          <i class="bi bi-lightning-fill me-2 text-success"></i>
          <span id="apply-modal-title">Aplicar Plano de Recuperação</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <div id="apply-dry-run-warning" class="alert alert-info d-none">
          <i class="bi bi-eye me-2"></i>
          <strong>Modo Simulação (Dry Run)</strong> — Nenhuma alteração real será feita.
          Você verá exatamente o que seria executado.
        </div>
        <div id="apply-real-warning" class="alert alert-warning d-none">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <strong>Atenção!</strong> Esta ação irá pausar itens tóxicos e atualizar títulos
          diretamente no Mercado Livre via API. Confirme antes de prosseguir.
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold small">Ações a aplicar</label>
          <div class="d-flex flex-wrap gap-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="apply-action-pause" value="PAUSAR" checked>
              <label class="form-check-label small" for="apply-action-pause">
                <span class="badge bg-danger me-1">PAUSAR</span> Itens tóxicos/poluidores/mortos
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="apply-action-title" value="OTIMIZAR_TITULO" checked>
              <label class="form-check-label small" for="apply-action-title">
                <span class="badge bg-primary me-1">TÍTULO</span> Otimizar com keywords faltantes
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="apply-action-stock" value="REPOR_ESTOQUE" checked>
              <label class="form-check-label small" for="apply-action-stock">
                <span class="badge bg-warning text-dark me-1">ESTOQUE</span> Alertas de reposição
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="apply-action-price" value="OTIMIZAR_PRECO" checked>
              <label class="form-check-label small" for="apply-action-price">
                <span class="badge bg-secondary me-1">PREÇO</span> Alertas de conversão baixa
              </label>
            </div>
          </div>
        </div>

        <div id="apply-result" class="d-none mt-3">
          <hr>
          <h6 class="fw-bold mb-3" id="apply-result-title">Resultado</h6>
          <div id="apply-result-summary" class="p-3 bg-light rounded mb-3 text-pre-wrap small font-monospace"></div>

          <div id="apply-paused-list" class="d-none">
            <h6 class="small fw-bold text-danger mb-2"><i class="bi bi-pause-circle me-1"></i>Itens pausados</h6>
            <div id="apply-paused-items" class="list-group list-group-flush mb-3"></div>
          </div>

          <div id="apply-titles-list" class="d-none">
            <h6 class="small fw-bold text-primary mb-2"><i class="bi bi-pencil me-1"></i>Títulos otimizados</h6>
            <div id="apply-title-items" class="list-group list-group-flush mb-3"></div>
          </div>

          <div id="apply-alerts-list" class="d-none">
            <h6 class="small fw-bold text-warning mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Alertas</h6>
            <div id="apply-alert-items"></div>
          </div>
        </div>

      </div>
      <div class="modal-footer border-0">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal" id="apply-cancel-btn">Cancelar</button>
        <button class="btn btn-success px-4 fw-semibold" data-action="apply-plan" id="btn-apply-confirm">
          <i class="bi bi-lightning-fill me-1"></i>
          <span id="btn-apply-label">Confirmar</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ─── STYLES ─────────────────────────────────────────────────── -->
<style>
  .score-circle {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    font-weight: 700;
    border: 4px solid #dee2e6;
    color: #6c757d;
  }

  .score-circle.excellent {
    border-color: #198754;
    color: #198754;
    background: #d1e7dd;
  }

  .score-circle.good {
    border-color: #0d6efd;
    color: #0d6efd;
    background: #cfe2ff;
  }

  .score-circle.medium {
    border-color: #ffc107;
    color: #856404;
    background: #fff3cd;
  }

  .score-circle.bad {
    border-color: #dc3545;
    color: #dc3545;
    background: #f8d7da;
  }

  .score-circle.critical {
    border-color: #6f1014;
    color: #fff;
    background: #dc3545;
  }

  .xray-item-row {
    transition: background 0.15s;
  }

  .xray-item-row:hover {
    background: #f8f9fa;
  }

  .seo-bar {
    height: 6px;
    border-radius: 3px;
    background: #dee2e6;
    display: inline-block;
    width: 80px;
    vertical-align: middle;
    overflow: hidden;
  }

  .seo-bar-fill {
    height: 100%;
    border-radius: 3px;
  }

  .kw-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.78rem;
    margin: 2px;
    background: #e9ecef;
    color: #495057;
    cursor: default;
  }

  .kw-badge.gap {
    background: #fff3cd;
    color: #856404;
  }

  .kw-badge.opp {
    background: #cff4fc;
    color: #055160;
  }

  .kw-badge.lt {
    background: #d1e7dd;
    color: #0f5132;
  }

  .severity-CRITICO {
    border-left: 4px solid #dc3545 !important;
  }

  .severity-ALTO {
    border-left: 4px solid #fd7e14 !important;
  }

  .severity-MEDIO {
    border-left: 4px solid #ffc107 !important;
  }

  .severity-BAIXO {
    border-left: 4px solid #0dcaf0 !important;
  }

  .account-card {
    cursor: pointer;
    transition: all 0.15s;
    border: 2px solid transparent !important;
  }

  .account-card:hover {
    border-color: #0d6efd !important;
  }

  .account-card.selected {
    border-color: #0d6efd !important;
    background: #f0f5ff;
  }

  .classification-ANCHOR {
    color: #f59e0b;
    font-weight: 700;
  }

  .classification-SAUDAVEL {
    color: #059669;
  }

  .classification-EM_RISCO {
    color: #d97706;
  }

  .classification-FRACO {
    color: #6b7280;
  }

  .classification-MORTO {
    color: #374151;
  }

  .classification-TOXICO {
    color: #dc2626;
    font-weight: 700;
  }

  .classification-POLUIDOR {
    color: #ea580c;
  }

  .classification-SEM_ESTOQUE {
    color: #7c3aed;
  }
</style>

<!-- ─── JAVASCRIPT ─────────────────────────────────────────────── -->
<script nonce="<?= CSP_NONCE ?>">
  const XRay = (() => {
    let currentAccountId = null;
    let currentReport = null;
    let currentReportId = null;
    let allAccounts = [];
    let allSEOItems = [];
    let applyDryRun = true;
    let uiEventsBound = false;

    // ── inicialização ──────────────────────────────────────────
    function init() {
      bindUIActions();
      loadAccounts();
    }

    function getRunModalElement() {
      return document.getElementById('xray-run-modal');
    }

    function getRunModalInstance() {
      if (typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
      const modalEl = getRunModalElement();
      if (!modalEl) return null;
      return bootstrap.Modal.getOrCreateInstance(modalEl);
    }

    function bindUIActions() {
      if (uiEventsBound) return;
      uiEventsBound = true;

      document.addEventListener('click', function(e) {
        const target = e.target.closest('[data-action]');
        if (!target) return;

        const action = target.dataset.action;
        if (!action) return;

        if (target.tagName === 'A') {
          e.preventDefault();
        }

        switch (action) {
          case 'load-history':
            e.preventDefault();
            loadHistory();
            break;
          case 'export-pdf':
            e.preventDefault();
            exportPdf();
            break;
          case 'open-run-modal':
            e.preventDefault();
            openRunModal();
            break;
          case 'close-history-card':
            e.preventDefault();
            document.getElementById('history-card')?.classList.add('d-none');
            break;
          case 'start-xray':
            e.preventDefault();
            start();
            break;
          case 'apply-plan':
            e.preventDefault();
            applyPlan();
            break;
          case 'select-account': {
            e.preventDefault();
            const accountId = Number(target.dataset.accountId || 0);
            if (accountId > 0) selectAccount(accountId);
            break;
          }
          case 'open-apply-modal':
            e.preventDefault();
            openApplyModal(target.dataset.dryRun === 'true');
            break;
          case 'load-report-by-id': {
            e.preventDefault();
            const reportId = Number(target.dataset.reportId || 0);
            const accountId = Number(target.dataset.accountId || currentAccountId || 0);
            if (reportId > 0) loadReportById(reportId, accountId);
            break;
          }
        }
      });

      document.addEventListener('change', function(e) {
        const target = e.target.closest('[data-changeaction]');
        if (!target) return;

        if (target.dataset.changeaction === 'filter-seo-items') {
          filterSEOItems();
        }
      });

      document.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const target = e.target.closest('[data-keyaction]');
        if (!target) return;
        if (target.dataset.keyaction !== 'select-account') return;
        e.preventDefault();
        const accountId = Number(target.dataset.accountId || 0);
        if (accountId > 0) selectAccount(accountId);
      });
    }

    function accountsEmptyHtml() {
      return '<div class="col-12 text-center text-muted py-4" data-accounts-state="empty" id="accounts-empty">'
        + '<i class="bi bi-person-x fs-2 d-block mb-2"></i>'
        + 'Nenhuma conta ML conectada. <a href="/settings">Conectar conta</a>'
        + '</div>';
    }

    function accountsErrorHtml(message) {
      return `<div class="col-12 text-center text-danger py-4" data-accounts-state="error">Erro ao carregar contas: ${escHtml(message || 'falha desconhecida')}</div>`;
    }

    async function loadAccounts() {
      const grid = document.getElementById('accounts-grid');
      if (!grid) return;

      try {
        const res = await fetch('/api/xray/accounts', {
          headers: { 'Accept': 'application/json' },
          signal: AbortSignal.timeout(15000),
        });
        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }
        const data = await res.json();
        if (data && data.success === false) {
          throw new Error(data.error || data.message || 'Falha ao listar contas');
        }
        allAccounts = Array.isArray(data.accounts) ? data.accounts : [];

        if (!allAccounts.length) {
          grid.innerHTML = accountsEmptyHtml();
          populateModalSelect();
          return;
        }

        grid.innerHTML = allAccounts.map(a => `
        <div class="col-md-4">
          <div class="card account-card border shadow-sm p-3" data-id="${a.id}" data-action="select-account" data-keyaction="select-account" data-account-id="${a.id}" role="button" tabindex="0">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-primary-subtle rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;min-width:48px">
                <i class="bi bi-person-fill text-primary fs-5"></i>
              </div>
              <div class="flex-grow-1 overflow-hidden">
                <div class="fw-semibold text-truncate">${escHtml(a.nickname || a.seller_id || 'Conta #' + a.id)}</div>
                <div class="text-muted small">${escHtml(a.email || '')}</div>
                <div class="d-flex gap-2 mt-1">
                  ${statusBadge(a.status)}
                  ${a.last_score != null ? `<span class="badge bg-secondary">Score: ${a.last_score}</span>` : ''}
                  ${a.last_account_status ? `<span class="badge ${statusColor(a.last_account_status)}">${escHtml(a.last_account_status)}</span>` : ''}
                </div>
              </div>
            </div>
            ${a.last_report_at ? `<div class="text-muted small mt-2 border-top pt-2"><i class="bi bi-clock me-1"></i>Último raio x: ${relDate(a.last_report_at)}</div>` : ''}
          </div>
        </div>
      `).join('');

        // Auto-selecionar primeira conta ativa
        const active = allAccounts.find(a => a.status === 'active');
        if (active) selectAccount(active.id);

        // Preencher select do modal
        populateModalSelect();
      } catch (e) {
        // Timeout / rede: preferir empty-state claro a spinner eterno (TC025)
        const timedOut = e && (e.name === 'TimeoutError' || e.name === 'AbortError');
        if (timedOut || (e && String(e.message || '').includes('Failed to fetch'))) {
          grid.innerHTML = accountsEmptyHtml();
          return;
        }
        grid.innerHTML = accountsErrorHtml(e.message || 'falha desconhecida');
      }
    }

    function selectAccount(id) {
      currentAccountId = id;
      document.querySelectorAll('.account-card').forEach(c => {
        c.classList.toggle('selected', parseInt(c.dataset.id) === id);
      });
      // Carregar último relatório se existir
      loadLastReport(id);
    }

    async function loadLastReport(accountId) {
      try {
        const res = await fetch(`/api/xray/list?account_id=${accountId}&limit=1`);
        const data = await res.json();
        const reports = data.reports || [];
        if (reports.length && reports[0].status === 'completed') {
          const r = await fetch(`/api/xray/results/${reports[0].id}`);
          const d = await r.json();
          if (d.success && d.report?.report) {
            renderReport(d.report.report);
          }
        }
      } catch (_) {}
    }

    function populateModalSelect() {
      const sel = document.getElementById('modal-account-select');
      if (!sel) return;
      if (!allAccounts.length) {
        sel.innerHTML = '<option value="">Nenhuma conta ML conectada</option>';
        return;
      }
      sel.innerHTML = allAccounts.map(a =>
        `<option value="${a.id}">${escHtml(a.nickname || 'Conta #' + a.id)} — ${escHtml(a.status)}</option>`
      ).join('');
      if (currentAccountId) sel.value = currentAccountId;
    }

    // ── abrir modal ─────────────────────────────────────────────
    function openRunModal() {
      populateModalSelect();
      if (currentAccountId) {
        document.getElementById('modal-account-select').value = currentAccountId;
      }

      const modal = getRunModalInstance();
      modal?.show();
    }

    // ── iniciar análise ─────────────────────────────────────────
    async function start() {
      const accountId = parseInt(document.getElementById('modal-account-select').value);
      if (!accountId) {
        alert('Selecione uma conta');
        return;
      }

      const options = {
        account_id: accountId,
        max_items: parseInt(document.getElementById('modal-max-items').value),
        include_paused: document.getElementById('modal-include-paused').checked,
        deep_seo: document.getElementById('modal-deep-seo').checked,
        include_financial: document.getElementById('modal-include-financial').checked,
      };

      // Evita warning de aria-hidden com foco retido no botão dentro do modal
      const activeEl = document.activeElement;
      if (activeEl && typeof activeEl.blur === 'function') {
        activeEl.blur();
      }

      const modalEl = getRunModalElement();
      const modalInstance = getRunModalInstance();
      if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function onHidden() {
          document.getElementById('btn-start-xray')?.focus();
          modalEl.removeEventListener('hidden.bs.modal', onHidden);
        });
      }
      modalInstance?.hide();

      // Mostrar estado de execução
      document.getElementById('result-dashboard').classList.add('d-none');
      document.getElementById('running-card').classList.remove('d-none');

      const phases = [
        [5, 'Conectando à API do Mercado Livre...'],
        [10, 'Buscando dados do vendedor e reputação...'],
        [20, 'Listando anúncios da conta...'],
        [35, 'Coletando métricas de visitas e conversão...'],
        [50, 'Executando diagnóstico de governança...'],
        [65, 'Auditando qualidade SEO dos títulos...'],
        [75, 'Analisando lacunas vs concorrentes...'],
        [85, 'Consultando Mercado Pago...'],
        [95, 'Calculando score e gerando plano de recuperação...'],
      ];

      let phaseIdx = 0;
      const phaseTimer = setInterval(() => {
        if (phaseIdx < phases.length) {
          document.getElementById('running-phase').textContent = phases[phaseIdx][1];
          document.getElementById('running-progress').style.width = phases[phaseIdx][0] + '%';
          phaseIdx++;
        }
      }, 15000);

      const startTs = Date.now();
      const elapsedTimer = setInterval(() => {
        const s = Math.round((Date.now() - startTs) / 1000);
        document.getElementById('running-elapsed').textContent = `${s}s em execução...`;
      }, 1000);

      try {
        const res = await fetch('/api/xray/run', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(options),
        });
        const data = await res.json();

        clearInterval(phaseTimer);
        clearInterval(elapsedTimer);
        document.getElementById('running-card').classList.add('d-none');

        if (!data.success) {
          alert('Erro: ' + (data.error || 'Falha desconhecida'));
          return;
        }

        // Buscar relatório completo
        const repRes = await fetch(`/api/xray/results/${data.report_id}`);
        const repData = await repRes.json();
        if (repData.success && repData.report?.report) {
          renderReport(repData.report.report);
        }

        // Recarregar cards de conta
        loadAccounts();
      } catch (e) {
        clearInterval(phaseTimer);
        clearInterval(elapsedTimer);
        document.getElementById('running-card').classList.add('d-none');
        alert('Erro ao executar Raio X: ' + e.message);
      }
    }

    // ── renderizar relatório ─────────────────────────────────────
    function renderReport(report) {
      currentReport = report;
      currentReportId = report.report_id || report.id || null;
      document.getElementById('result-dashboard').classList.remove('d-none');

      // Mostrar botão de exportar PDF quando há relatório carregado
      const btnPdf = document.getElementById('btn-export-pdf');
      if (btnPdf && currentReportId) {
        btnPdf.classList.remove('d-none');
      }

      renderScoreCard(report);
      renderSummaryStats(report);
      renderDiagnosis(report);
      renderSEOItems(report);
      renderGaps(report);
      renderLongTail(report);
      renderFinancial(report);
      renderPlan(report);
    }

    function renderScoreCard(r) {
      const score = r.score_overall ?? 0;
      const circle = document.getElementById('score-circle');
      circle.textContent = score;
      circle.className = 'score-circle mx-auto mb-2 ' + (
        score >= 80 ? 'excellent' : score >= 60 ? 'good' : score >= 40 ? 'medium' : score >= 20 ? 'bad' : 'critical'
      );

      const statusEl = document.getElementById('account-status-badge');
      statusEl.textContent = r.account_status || '--';
      statusEl.className = 'h5 fw-bold mb-2 ' + statusColor(r.account_status);

      document.getElementById('main-bottleneck').textContent =
        r.diagnosis?.main_bottleneck || '—';
    }

    function renderSummaryStats(r) {
      const seo = r.seo_audit || {};
      const plan = r.recovery_plan || {};
      const diag = r.diagnosis || {};
      const meta = r.meta || {};

      document.getElementById('stat-total').textContent = meta.items_fetched || 0;
      document.getElementById('stat-below50').textContent = seo.items_below_50 || 0;
      document.getElementById('stat-critical').textContent = diag.critical_count || 0;
      document.getElementById('stat-recovery').textContent =
        (plan.estimated_recovery_days || '--') + ' dias';

      const stLen = (diag.strengths || []).length;
      document.getElementById('stat-strengths').textContent = stLen + ' ponto' + (stLen !== 1 ? 's' : '') + ' forte' + (stLen !== 1 ? 's' : '');
    }

    function renderDiagnosis(r) {
      const problems = r.diagnosis?.problems || [];
      const strengths = r.diagnosis?.strengths || [];

      // Problems
      const pList = document.getElementById('problems-list');
      document.getElementById('problems-count').textContent = problems.length;
      if (!problems.length) {
        pList.innerHTML = '<div class="list-group-item text-center text-success py-3"><i class="bi bi-check-circle fs-4 d-block mb-1"></i>Nenhum problema crítico!</div>';
      } else {
        pList.innerHTML = problems.map(p => `
        <div class="list-group-item border-0 severity-${escHtml(p.severity)}">
          <div class="d-flex gap-2">
            <span class="badge bg-${severityColor(p.severity)} mt-1 flex-shrink-0">${escHtml(p.severity)}</span>
            <div>
              <div class="fw-semibold small">${escHtml(p.message)}</div>
              <div class="text-muted small mt-1"><i class="bi bi-arrow-right-circle me-1"></i>${escHtml(p.action || '')}</div>
            </div>
          </div>
        </div>
      `).join('');
      }

      // Strengths
      const sList = document.getElementById('strengths-list');
      if (!strengths.length) {
        sList.innerHTML = '<div class="list-group-item text-center text-muted py-3">Nenhum ponto forte ainda</div>';
      } else {
        sList.innerHTML = strengths.map(s => `
        <div class="list-group-item border-0">
          <i class="bi bi-check-circle text-success me-2"></i>${escHtml(s)}
        </div>
      `).join('');
      }
    }

    function renderSEOItems(r) {
      allSEOItems = r.seo_audit?.items || [];
      const avgSeo = r.seo_audit?.avg_seo_score || 0;

      const badge = document.getElementById('avg-seo-badge');
      badge.textContent = avgSeo + '/100';
      badge.className = 'badge ' + (avgSeo >= 70 ? 'bg-success' : avgSeo >= 50 ? 'bg-warning text-dark' : 'bg-danger');

      filterSEOItems();
    }

    function filterSEOItems() {
      const cls = document.getElementById('seo-filter-class')?.value || '';
      const sort = document.getElementById('seo-sort')?.value || 'seo_score_asc';

      let items = [...allSEOItems];
      if (cls) items = items.filter(i => i.classification === cls);

      items.sort((a, b) => {
        if (sort === 'seo_score_asc') return a.seo_score - b.seo_score;
        if (sort === 'seo_score_desc') return b.seo_score - a.seo_score;
        if (sort === 'visits_desc') return (b.visits_30d || 0) - (a.visits_30d || 0);
        return 0;
      });

      const container = document.getElementById('seo-items-table');
      if (!items.length) {
        container.innerHTML = '<p class="text-center text-muted py-4">Nenhum anúncio com esse filtro</p>';
        return;
      }

      container.innerHTML = `
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 small">
          <thead class="table-light">
            <tr>
              <th style="min-width:280px">Título</th>
              <th>Classif.</th>
              <th>SEO</th>
              <th>Visitas</th>
              <th>Vendas</th>
              <th>Conv.</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
            ${items.slice(0, 100).map(item => `
              <tr class="xray-item-row">
                <td>
                  <div class="text-truncate" style="max-width:280px" title="${escHtml(item.title)}">${escHtml(item.title)}</div>
                  ${item.missing_keywords?.length ? `<div class="mt-1">${item.missing_keywords.slice(0,3).map(k => `<span class="kw-badge gap">+${escHtml(k)}</span>`).join('')}</div>` : ''}
                </td>
                <td><span class="classification-${escHtml(item.classification || '')}">${classIcon(item.classification)} ${escHtml(item.classification || '?')}</span></td>
                <td>
                  <div class="d-flex align-items-center gap-1">
                    <span class="fw-bold">${item.seo_score}</span>
                    <span class="seo-bar"><span class="seo-bar-fill" style="width:${item.seo_score}%;background:${seoColor(item.seo_score)}"></span></span>
                  </div>
                </td>
                <td>${item.visits_30d || 0}</td>
                <td>${item.sales_30d || 0}</td>
                <td>${item.conversion_rate ? (item.conversion_rate * 100).toFixed(1) + '%' : '0%'}</td>
                <td>
                  ${(item.actions || []).slice(0,1).map(a => `<span class="badge bg-${urgencyColor(a.urgency)} text-truncate" style="max-width:120px" title="${escHtml(a.detail)}">${escHtml(a.type)}</span>`).join('')}
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
      ${items.length > 100 ? `<p class="text-muted text-center small py-2">Mostrando 100 de ${items.length}</p>` : ''}
    `;
    }

    function renderGaps(r) {
      const comp = r.competitive || {};
      const hidden = comp.hidden_gaps || [];
      const opps = comp.opportunity_keywords || [];

      const gList = document.getElementById('hidden-gaps-list');
      gList.innerHTML = hidden.length ?
        hidden.map(k => `<span class="kw-badge gap">${escHtml(k)}</span>`).join('') :
        '<p class="text-muted small">Nenhuma lacuna oculta encontrada (ótimo!)</p>';

      const oList = document.getElementById('opportunity-kws-list');
      oList.innerHTML = opps.length ?
        opps.map(k => `<span class="kw-badge opp">${escHtml(k)}</span>`).join('') :
        '<p class="text-muted small">—</p>';
    }

    function renderLongTail(r) {
      const ltSummary = r.seo_audit?.long_tail_summary || [];
      const container = document.getElementById('longtail-list');
      container.innerHTML = ltSummary.length ?
        ltSummary.map(k => `<div class="col-auto"><span class="kw-badge lt">${escHtml(k)}</span></div>`).join('') :
        '<div class="col-12"><p class="text-muted">Nenhuma sugestão de cauda longa gerada</p></div>';
    }

    function renderFinancial(r) {
      const fin = r.financial || {};
      if (!fin.configured) {
        document.getElementById('financial-cards').innerHTML = `
        <div class="col-12 text-center py-4">
          <i class="bi bi-bank2 fs-2 mb-2 d-block text-muted"></i>
          <p class="text-muted">${escHtml(fin.message || 'Mercado Pago não configurado')}</p>
          <a href="/settings" class="btn btn-primary btn-sm">Configurar Mercado Pago</a>
        </div>
      `;
        return;
      }

      const bal = fin.balance || {};
      const cb = fin.chargebacks || {};
      const dis = fin.disputes || {};
      const fh = fin.financial_health || {};

      document.getElementById('financial-cards').innerHTML = `
      <div class="col-md-4">
        <div class="card border-0 bg-light h-100">
          <div class="card-body">
            <div class="text-muted small mb-2">SALDO DISPONÍVEL</div>
            <div class="h4 fw-bold text-success mb-1">R$ ${fmt(bal.available)}</div>
            <div class="small text-muted">Bloqueado: R$ ${fmt(bal.blocked)}</div>
            <div class="small text-muted">Pendente: R$ ${fmt(bal.pending)}</div>
            ${bal.blocked_ratio > 0.1 ? `<div class="alert alert-warning py-1 px-2 mt-2 small"><i class="bi bi-exclamation-triangle me-1"></i>${(bal.blocked_ratio*100).toFixed(0)}% bloqueado</div>` : ''}
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 bg-light h-100">
          <div class="card-body">
            <div class="text-muted small mb-2">CHARGEBACKS (30 dias)</div>
            <div class="h4 fw-bold ${cb.total > 2 ? 'text-danger' : 'text-success'} mb-1">${cb.total || 0}</div>
            <div class="small text-muted">Abertos: ${cb.open || 0} · Perdidos: ${cb.lost || 0}</div>
            <span class="badge bg-${cb.risk === 'HIGH' ? 'danger' : cb.risk === 'MEDIUM' ? 'warning text-dark' : 'success'}">${cb.risk || 'LOW'}</span>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 bg-light h-100">
          <div class="card-body">
            <div class="text-muted small mb-2">SAÚDE FINANCEIRA</div>
            <div class="h4 fw-bold mb-1">${fh.score || 0}/100 <span class="fs-6 fw-normal">${fh.grade || 'F'}</span></div>
            <div class="small text-muted">Risco: <strong>${fin.risk_level || 'UNKNOWN'}</strong></div>
            ${(fh.negative_indicators || []).slice(0,2).map(i => `<div class="text-danger small mt-1"><i class="bi bi-x-circle me-1"></i>${escHtml(i)}</div>`).join('')}
            ${(fh.positive_indicators || []).slice(0,2).map(i => `<div class="text-success small mt-1"><i class="bi bi-check-circle me-1"></i>${escHtml(i)}</div>`).join('')}
          </div>
        </div>
      </div>
    `;
    }

    function renderPlan(r) {
      const plan = r.recovery_plan || {};
      const container = document.getElementById('plan-content');

      const criticalActions = plan.critical || [];
      const highActions = plan.high || [];
      const seoActions = plan.seo_actions || [];
      const weekPlan = plan.week_plan || [];

      container.innerHTML = `
      <div class="row g-4">
        <div class="col-md-8">
          ${criticalActions.length ? `
            <h6 class="fw-bold text-danger mb-3"><i class="bi bi-exclamation-octagon me-1"></i> Ações Críticas — Fazer Agora</h6>
            <div class="list-group mb-4">
              ${criticalActions.slice(0,5).map(a => `
                <div class="list-group-item border-0 severity-CRITICO">
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <span class="badge bg-danger me-2">${escHtml(a.type || a.action)}</span>
                      <span class="small">${escHtml(a.description || a.detail || '')}</span>
                    </div>
                    <span class="badge bg-outline-danger border border-danger text-danger ms-2 flex-shrink-0">${escHtml(a.priority || 'CRITICA')}</span>
                  </div>
                </div>
              `).join('')}
            </div>
          ` : ''}

          ${seoActions.length ? `
            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-search me-1"></i> Ações SEO</h6>
            <div class="list-group mb-4">
              ${seoActions.map(a => `
                <div class="list-group-item border-0">
                  <div class="fw-semibold small mb-1">${escHtml(a.description || '')}</div>
                  ${a.keywords?.length ? `<div>${a.keywords.slice(0,6).map(k => `<span class="kw-badge gap">${escHtml(k)}</span>`).join('')}</div>` : ''}
                  ${a.keywords_to_add?.length ? `<div>${a.keywords_to_add.slice(0,6).map(k => `<span class="kw-badge opp">${escHtml(k)}</span>`).join('')}</div>` : ''}
                </div>
              `).join('')}
            </div>
          ` : ''}
        </div>

        <div class="col-md-4">
          ${weekPlan.length ? `
            <h6 class="fw-bold mb-3"><i class="bi bi-calendar3 me-1"></i> Plano 7 Dias</h6>
            <div class="list-group list-group-flush">
              ${weekPlan.slice(0, 7).map((day, i) => `
                <div class="list-group-item px-0 border-0 border-bottom">
                  <div class="fw-semibold small text-primary mb-1">Dia ${i+1}</div>
                  ${(Array.isArray(day) ? day : (day.tasks || [day.task || day])).slice(0,3).map(t =>
                    `<div class="text-muted small"><i class="bi bi-check2 me-1"></i>${escHtml(typeof t === 'string' ? t : (t.description || JSON.stringify(t)))}</div>`
                  ).join('')}
                </div>
              `).join('')}
            </div>
          ` : ''}
          <div class="mt-3 p-3 bg-light rounded">
            <div class="text-muted small mb-1">Recuperação estimada</div>
            <div class="h5 fw-bold mb-0">${plan.estimated_recovery_days || '--'} dias</div>
            <div class="text-muted small">com plano executado</div>
          </div>

          <div class="mt-3 d-grid gap-2">
            <button class="btn btn-outline-primary btn-sm" data-action="open-apply-modal" data-dry-run="true">
              <i class="bi bi-eye me-1"></i> Simular Aplicação (Dry Run)
            </button>
            <button class="btn btn-success btn-sm" data-action="open-apply-modal" data-dry-run="false">
              <i class="bi bi-lightning-fill me-1"></i> Aplicar Plano Agora
            </button>
          </div>
        </div>
      </div>
    `;
    }

    // ── histórico ─────────────────────────────────────────────────
    async function loadHistory() {
      const accountId = currentAccountId;
      if (!accountId) {
        alert('Selecione uma conta primeiro');
        return;
      }

      const card = document.getElementById('history-card');
      card.classList.remove('d-none');
      document.getElementById('history-table').innerHTML = '<p class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></p>';

      try {
        const res = await fetch(`/api/xray/list?account_id=${accountId}&limit=20`);
        const data = await res.json();
        const reports = data.reports || [];

        if (!reports.length) {
          document.getElementById('history-table').innerHTML = '<p class="text-center text-muted py-4">Nenhum relatório para essa conta</p>';
          return;
        }

        document.getElementById('history-table').innerHTML = `
        <table class="table table-hover small mb-0">
          <thead class="table-light"><tr><th>Data</th><th>Status</th><th>Score</th><th>Conta Status</th><th>Itens</th><th>Issues</th><th></th></tr></thead>
          <tbody>
            ${reports.map(r => `
              <tr>
                <td>${relDate(r.created_at)}</td>
                <td><span class="badge bg-${r.status==='completed'?'success':r.status==='failed'?'danger':'secondary'}">${escHtml(r.status)}</span></td>
                <td>${r.score_overall != null ? r.score_overall : '—'}</td>
                <td>${r.account_status ? `<span class="${statusColor(r.account_status)}">${escHtml(r.account_status)}</span>` : '—'}</td>
                <td>${r.items_analyzed || 0}/${r.items_total || 0}</td>
                <td class="${r.critical_issues > 0 ? 'text-danger fw-bold' : ''}">${r.critical_issues || 0}</td>
                <td><button class="btn btn-sm btn-outline-primary py-0" data-action="load-report-by-id" data-report-id="${r.id}" data-account-id="${r.account_id || currentAccountId}">Ver</button></td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
      } catch (e) {
        document.getElementById('history-table').innerHTML = `<p class="text-center text-danger py-3">${escHtml(e.message)}</p>`;
      }
    }

    async function loadReportById(reportId, accountId) {
      document.getElementById('history-card').classList.add('d-none');
      document.getElementById('result-dashboard').classList.add('d-none');
      document.getElementById('running-card').classList.remove('d-none');
      document.getElementById('running-phase').textContent = 'Carregando relatório...';

      try {
        const res = await fetch(`/api/xray/results/${reportId}`);
        const data = await res.json();
        document.getElementById('running-card').classList.add('d-none');
        if (data.success && data.report?.report) {
          renderReport(data.report.report);
        } else {
          alert('Erro ao carregar relatório');
        }
      } catch (e) {
        document.getElementById('running-card').classList.add('d-none');
        alert('Erro: ' + e.message);
      }
    }

    // ── utilitários ───────────────────────────────────────────────
    function escHtml(s) {
      return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fmt(n) {
      return Number(n || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    function parseAppDate(d) {
      // Timezone único (America/Sao_Paulo, -03:00 fixo, sem horário de verão):
      // strings sem offset explícito (ex.: vindas de coluna DATETIME) já são
      // wall-clock nesse fuso — ancora o offset antes de criar o Date() para
      // não deixar o navegador reinterpretar no seu próprio timezone local.
      const s = String(d).trim();
      const hasOffset = /Z$|[+-]\d{2}:?\d{2}$/.test(s);
      const iso = s.replace(' ', 'T');
      return new Date(hasOffset ? iso : iso + '-03:00');
    }

    function relDate(d) {
      if (!d) return '—';
      const parsed = parseAppDate(d);
      if (Number.isNaN(parsed.getTime())) return '—';
      const diff = (Date.now() - parsed.getTime()) / 60000;
      if (diff < 2) return 'agora';
      if (diff < 60) return Math.round(diff) + 'min atrás';
      if (diff < 1440) return Math.round(diff / 60) + 'h atrás';
      return Math.round(diff / 1440) + ' dias atrás';
    }

    function statusBadge(s) {
      const m = {
        active: 'success',
        inactive: 'secondary',
        expired: 'warning',
        disconnected: 'danger'
      };
      return `<span class="badge bg-${m[s]||'secondary'}">${s}</span>`;
    }

    function statusColor(s) {
      return {
        FORTE: 'text-success',
        ESTAVEL: 'text-primary',
        EM_RECUPERACAO: 'text-warning',
        PENALIZADA: 'text-orange',
        TRAVADA: 'text-danger',
        UNKNOWN: 'text-muted'
      } [s] || 'text-secondary';
    }

    function severityColor(s) {
      return {
        CRITICO: 'danger',
        ALTO: 'warning',
        MEDIO: 'info',
        BAIXO: 'secondary'
      } [s] || 'secondary';
    }

    function urgencyColor(s) {
      return {
        CRITICA: 'danger',
        ALTA: 'warning',
        MEDIA: 'info',
        BAIXA: 'secondary'
      } [s] || 'secondary';
    }

    function seoColor(score) {
      return score >= 70 ? '#198754' : score >= 50 ? '#ffc107' : '#dc3545';
    }

    function classIcon(cls) {
      return {
        ANCHOR: '⭐',
        SAUDAVEL: '🟢',
        EM_RISCO: '🟡',
        FRACO: '🔵',
        MORTO: '⚫',
        TOXICO: '🔴',
        POLUIDOR: '🟠',
        SEM_ESTOQUE: '🟣'
      } [cls] || '?';
    }

    // ── exportar PDF ──────────────────────────────────────────────

    function exportPdf() {
      if (!currentReportId) {
        alert('Execute o Raio X primeiro para gerar um relatório.');
        return;
      }
      window.location.href = '/api/xray/export/pdf/' + currentReportId;
    }

    // ── aplicar plano de recuperação ────────────────────────────

    function openApplyModal(dryRun) {
      if (!currentReportId) {
        alert('Execute o Raio X primeiro para gerar um relatório.');
        return;
      }
      applyDryRun = dryRun;

      // Reset UI
      document.getElementById('apply-result').classList.add('d-none');
      document.getElementById('apply-paused-list').classList.add('d-none');
      document.getElementById('apply-titles-list').classList.add('d-none');
      document.getElementById('apply-alerts-list').classList.add('d-none');
      document.getElementById('apply-result-summary').textContent = '';

      const dryWarn = document.getElementById('apply-dry-run-warning');
      const realWarn = document.getElementById('apply-real-warning');
      const title = document.getElementById('apply-modal-title');
      const btnLabel = document.getElementById('btn-apply-label');
      const btn = document.getElementById('btn-apply-confirm');

      if (dryRun) {
        dryWarn.classList.remove('d-none');
        realWarn.classList.add('d-none');
        title.textContent = 'Simular Plano de Recuperação (Dry Run)';
        btnLabel.textContent = 'Simular Agora';
        btn.className = 'btn btn-primary px-4 fw-semibold';
      } else {
        dryWarn.classList.add('d-none');
        realWarn.classList.remove('d-none');
        title.textContent = '⚡ Aplicar Plano de Recuperação';
        btnLabel.textContent = 'Aplicar Agora';
        btn.className = 'btn btn-success px-4 fw-semibold';
      }

      new bootstrap.Modal(document.getElementById('apply-modal')).show();
    }

    async function applyPlan() {
      if (!currentReportId) return;

      const btn = document.getElementById('btn-apply-confirm');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processando...';

      const onlyActions = ['apply-action-pause', 'apply-action-title', 'apply-action-stock', 'apply-action-price']
        .filter(id => document.getElementById(id)?.checked)
        .map(id => document.getElementById(id).value);

      try {
        const res = await fetch(`/api/xray/apply/${currentReportId}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            dry_run: applyDryRun,
            only_actions: onlyActions
          }),
        });
        const json = await res.json();

        if (!json.success) throw new Error(json.error || 'Erro desconhecido');

        const data = json.data;
        renderApplyResult(data);
      } catch (err) {
        alert('Erro ao aplicar plano: ' + err.message);
      } finally {
        btn.disabled = false;
        btn.innerHTML = `<i class="bi bi-lightning-fill me-1"></i> ${applyDryRun ? 'Simular Novamente' : 'Aplicar Novamente'}`;
      }
    }

    function renderApplyResult(data) {
      const resultDiv = document.getElementById('apply-result');
      resultDiv.classList.remove('d-none');

      const titleEl = document.getElementById('apply-result-title');
      titleEl.textContent = data.dry_run ? '🔍 Resultado da Simulação' : '✅ Resultado da Aplicação';

      document.getElementById('apply-result-summary').textContent = data.summary || '';

      // Itens pausados
      const pausedItems = data.paused_items || [];
      if (pausedItems.length > 0) {
        document.getElementById('apply-paused-list').classList.remove('d-none');
        document.getElementById('apply-paused-items').innerHTML = pausedItems.map(item => `
        <div class="list-group-item border-0 py-1 small">
          <span class="me-2">${item.applied ? '✅' : (data.dry_run ? '🔍' : '❌')}</span>
          <strong>${escHtml(item.item_id)}</strong>
          <span class="badge bg-danger ms-1">${escHtml(item.classification || '')}</span>
          <span class="text-muted ms-1">${escHtml(item.title || '')}</span>
          <span class="ms-1 text-muted">(score: ${item.score || 0})</span>
        </div>
      `).join('');
      }

      // Títulos otimizados
      const titleItems = data.optimized_titles || [];
      if (titleItems.length > 0) {
        document.getElementById('apply-titles-list').classList.remove('d-none');
        document.getElementById('apply-title-items').innerHTML = titleItems.map(item => `
        <div class="list-group-item border-0 py-2 small">
          <div class="mb-1">
            <span class="me-2">${item.applied ? '✅' : (data.dry_run ? '🔍' : '❌')}</span>
            <strong>${escHtml(item.item_id)}</strong>
            <span class="badge bg-secondary ms-1">SEO ${item.seo_score_before || 0}</span>
          </div>
          <div class="text-muted ps-4">Antes: ${escHtml(item.original_title || '')}</div>
          <div class="text-success ps-4 fw-semibold">Depois: ${escHtml(item.optimized_title || '')}</div>
        </div>
      `).join('');
      }

      // Alertas
      const alerts = [...(data.stock_alerts || []), ...(data.price_alerts || [])];
      if (alerts.length > 0) {
        document.getElementById('apply-alerts-list').classList.remove('d-none');
        document.getElementById('apply-alert-items').innerHTML = alerts.map(a => `
        <div class="alert alert-warning py-2 small mb-1">
          <strong>${escHtml(a.item_id)}</strong> — ${escHtml(a.message || '')}
        </div>
      `).join('');
      }

      // Cancelar → Fechar
      document.getElementById('apply-cancel-btn').textContent = 'Fechar';
    }

    // API pública do módulo
    return {
      init,
      openRunModal,
      start,
      selectAccount,
      filterSEOItems,
      loadHistory,
      loadReportById,
      openApplyModal,
      applyPlan,
      exportPdf
    };
  })();

  document.addEventListener('DOMContentLoaded', () => XRay.init());
</script>
