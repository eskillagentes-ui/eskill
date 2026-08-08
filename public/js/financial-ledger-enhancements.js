/**
 * Enhancements do dashboard financeiro (PATCH 6/8/9) — injetado via layout-modern-init.
 * Evita edição de financials.php (root-owned).
 *
 * - Mostra advertising_expenses no DRE
 * - Evita double-count Ads quando net_profit já descontou ledger ads
 * - KPIs de caixa do ledger (liberado / pendente / sacado / hold)
 */
(function () {
  if (!/^\/dashboard\/financials\/?$/.test(window.location.pathname)) {
    return;
  }

  const money = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);

  function ensureCashLedgerCards() {
    if (document.getElementById('cash-ledger-cards')) return;
    const anchor = document.getElementById('cash-mp-cards');
    if (!anchor || !anchor.parentNode) return;
    const row = document.createElement('div');
    row.className = 'row mb-4';
    row.id = 'cash-ledger-cards';
    row.innerHTML = `
      <div class="col-12 mb-2">
        <div class="text-muted small text-uppercase fw-semibold">Caixa do Ledger (livro financeiro)</div>
      </div>
      <div class="col-6 col-lg-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small mb-1">Liberado</div>
        <div class="fs-5 fw-bold text-success" id="kpi-ledger-released">—</div>
      </div></div></div>
      <div class="col-6 col-lg-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small mb-1">Pendente liberação</div>
        <div class="fs-5 fw-bold text-warning" id="kpi-ledger-pending">—</div>
      </div></div></div>
      <div class="col-6 col-lg-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small mb-1">Sacado</div>
        <div class="fs-5 fw-bold" id="kpi-ledger-withdrawn">—</div>
      </div></div></div>
      <div class="col-6 col-lg-3 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small mb-1">Hold / garantia</div>
        <div class="fs-5 fw-bold text-secondary" id="kpi-ledger-hold">—</div>
      </div></div></div>`;
    anchor.parentNode.insertBefore(row, anchor.nextSibling);
  }

  function renderCashFromPnl(pnl) {
    ensureCashLedgerCards();
    const cash = pnl && pnl.cash ? pnl.cash : null;
    const set = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = cash ? money(val) : 'N/D';
    };
    if (!cash) {
      set('kpi-ledger-released', 0);
      set('kpi-ledger-pending', 0);
      set('kpi-ledger-withdrawn', 0);
      set('kpi-ledger-hold', 0);
      return;
    }
    set('kpi-ledger-released', cash.released_amount);
    set('kpi-ledger-pending', cash.pending_release_amount);
    set('kpi-ledger-withdrawn', cash.withdrawn_amount);
    set('kpi-ledger-hold', cash.hold_amount);
  }

  function ensureCashTimelinePanel() {
    if (document.getElementById('cash-ledger-timeline')) return;
    const cards = document.getElementById('cash-ledger-cards');
    if (!cards || !cards.parentNode) return;
    const wrap = document.createElement('div');
    wrap.className = 'card border-0 shadow-sm mb-4';
    wrap.id = 'cash-ledger-timeline';
    wrap.innerHTML = `
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="text-muted small text-uppercase fw-semibold">Linha do tempo de caixa (ledger)</div>
          <button type="button" class="btn btn-link btn-sm p-0" id="cash-timeline-toggle">Recarregar</button>
        </div>
        <div class="table-responsive" style="max-height:280px;overflow:auto">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Dia</th><th>Tipo</th><th>Status</th><th class="text-end">Qtd</th><th class="text-end">Valor</th></tr></thead>
            <tbody id="cash-timeline-body"><tr><td colspan="5" class="text-muted">Carregando…</td></tr></tbody>
          </table>
        </div>
      </div>`;
    cards.parentNode.insertBefore(wrap, cards.nextSibling);
    document.getElementById('cash-timeline-toggle')?.addEventListener('click', () => loadCashTimeline());
  }

  async function loadCashTimeline() {
    ensureCashTimelinePanel();
    const tbody = document.getElementById('cash-timeline-body');
    if (!tbody || typeof requestJson !== 'function') return;
    const start = document.getElementById('date-start')?.value;
    const end = document.getElementById('date-end')?.value;
    if (!start || !end) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-muted">Selecione o período</td></tr>';
      return;
    }
    try {
      const res = await requestJson(`/api/financials/cash-timeline?start=${start}&end=${end}`);
      const rows = res && res.success && Array.isArray(res.data) ? res.data : [];
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-muted">Sem eventos de caixa no período</td></tr>';
        return;
      }
      tbody.innerHTML = rows.map((r) => `
        <tr>
          <td class="text-nowrap">${r.day || ''}</td>
          <td><code class="small">${r.entry_type || ''}</code></td>
          <td>${r.status || ''}</td>
          <td class="text-end">${r.entries || 0}</td>
          <td class="text-end">${money(r.amount)}</td>
        </tr>`).join('');
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="5" class="text-danger">Falha ao carregar timeline</td></tr>`;
      console.warn('cash-timeline', e);
    }
  }

  function patchFinancialManager() {
    const fm = window.financialManager;
    if (!fm || fm.__ledgerPatched) return false;

    const origRenderPnL = fm.renderPnL ? fm.renderPnL.bind(fm) : null;
    const origRenderAds = fm.renderAdsKpis ? fm.renderAdsKpis.bind(fm) : null;
    const origLoad = fm.loadData ? fm.loadData.bind(fm) : null;

    if (origRenderPnL) {
      fm.renderPnL = function (pnl) {
        origRenderPnL(pnl);
        try {
          const tbody = document.querySelector('#pnl-table tbody');
          if (tbody && pnl && typeof pnl.advertising_expenses !== 'undefined') {
            // Insere linha de ads antes do Resultado Operacional, se ainda não existir
            if (!tbody.querySelector('[data-ledger-ads-row]')) {
              const rows = Array.from(tbody.querySelectorAll('tr'));
              const resultRow = rows.find((tr) => /Resultado Operacional/i.test(tr.textContent || ''));
              const tr = document.createElement('tr');
              tr.setAttribute('data-ledger-ads-row', '1');
              const ads = Number(pnl.advertising_expenses || 0);
              tr.innerHTML = `<td>(-) Ads / Product Ads (ledger)</td><td class="text-end text-secondary">${money(ads)}</td>`;
              if (resultRow) {
                tbody.insertBefore(tr, resultRow);
              } else {
                tbody.appendChild(tr);
              }
            } else {
              const existing = tbody.querySelector('[data-ledger-ads-row]');
              const ads = Number(pnl.advertising_expenses || 0);
              if (existing) {
                existing.innerHTML = `<td>(-) Ads / Product Ads (ledger)</td><td class="text-end text-secondary">${money(ads)}</td>`;
              }
            }
            if (pnl.source && !document.getElementById('pnl-ledger-source-badge')) {
              const badge = document.createElement('div');
              badge.id = 'pnl-ledger-source-badge';
              badge.className = 'small text-muted mb-2';
              badge.textContent = pnl.source === 'ledger'
                ? 'DRE operacional: livro financeiro (ledger)'
                : 'DRE operacional: ml_orders (fallback)';
              const table = document.getElementById('pnl-table');
              if (table && table.parentNode) table.parentNode.insertBefore(badge, table);
            } else if (pnl.source) {
              const badge = document.getElementById('pnl-ledger-source-badge');
              if (badge) {
                badge.textContent = pnl.source === 'ledger'
                  ? 'DRE operacional: livro financeiro (ledger)'
                  : 'DRE operacional: ml_orders (fallback)';
              }
            }
          }
          renderCashFromPnl(pnl);
        } catch (e) {
          console.warn('ledger pnl patch', e);
        }
      };
    }

    if (origRenderAds) {
      fm.renderAdsKpis = function (ads, pnl, metrics) {
        // Se o DRE já descontou advertising_expenses do ledger, NÃO subtrair Ads de novo.
        const ledgerAds = Number(pnl && pnl.advertising_expenses ? pnl.advertising_expenses : 0);
        if (ledgerAds > 0 && metrics && typeof metrics.net_profit === 'number') {
          const patchedMetrics = Object.assign({}, metrics);
          // net_profit já está pós-ads do ledger — renderiza KPIs Ads normalmente mas
          // força lucro pós-ads = metrics.net_profit (sem segunda subtração).
          const fakeAds = ads ? Object.assign({}, ads) : { has_campaigns: true, period_metrics: { available: true, gasto: ledgerAds, tacos: null } };
          if (!fakeAds.period_metrics) {
            fakeAds.period_metrics = { available: true, gasto: ledgerAds, tacos: null };
          } else {
            fakeAds.period_metrics = Object.assign({}, fakeAds.period_metrics, { available: true });
          }
          // Temporariamente zera gasto do módulo Ads para o cálculo pós-ads do renderer original,
          // depois ajusta o KPI com o valor correto.
          const gastoOriginal = fakeAds.period_metrics.gasto;
          fakeAds.period_metrics.gasto = 0;
          origRenderAds(fakeAds, pnl, patchedMetrics);
          const el = document.getElementById('kpi-net-profit-post-ads');
          if (el) {
            el.textContent = money(patchedMetrics.net_profit);
            el.className = `fs-5 fw-bold ${patchedMetrics.net_profit >= 0 ? 'text-success' : 'text-danger'}`;
          }
          const spendEl = document.getElementById('kpi-ads-spend');
          if (spendEl) {
            spendEl.textContent = money(gastoOriginal || ledgerAds);
          }
          const label = document.getElementById('kpi-ads-spend-label');
          if (label) label.textContent = 'Valor em Ads (ledger billing)';
          return;
        }
        origRenderAds(ads, pnl, metrics);
      };
    }

    if (origLoad) {
      fm.loadData = async function () {
        await origLoad();
        try {
          const start = document.getElementById('date-start')?.value;
          const end = document.getElementById('date-end')?.value;
          if (start && end && typeof requestJson === 'function') {
            const cash = await requestJson(`/api/financials/cash-ledger?start=${start}&end=${end}`);
            if (cash && cash.success && cash.data) {
              renderCashFromPnl({ cash: cash.data, advertising_expenses: cash.advertising_expenses });
            }
            await loadCashTimeline();
          }
        } catch (e) {
          console.warn('cash-ledger fetch', e);
        }
      };
    }

    fm.__ledgerPatched = true;
    return true;
  }

  function boot() {
    if (patchFinancialManager()) return;
    let tries = 0;
    const t = setInterval(() => {
      tries += 1;
      if (patchFinancialManager() || tries > 40) clearInterval(t);
    }, 250);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
