/* eslint-env browser */
/**
 * Brand Search — Módulo 20 BRAND-003
 * eskill.com.br / AWA Motos
 */

'use strict';

// ── Estado ────────────────────────────────────────────────────────────────────
const _bsState = {
    searchId: null,
    pollInterval: null,
    pollGeneration: 0,
    currentPage: 1,
    perPage: 20,
    totalSellers: 0,
    filters: { reputation: null, minItems: null },
    sort: 'total_items_brand',
    order: 'desc',
};

// ── Init ──────────────────────────────────────────────────────────────────────
function bsBindUi() {
    const app = document.getElementById('brand-search-app');
    if (!app || app.dataset.bsBound === '1') return;
    app.dataset.bsBound = '1';

    const searchBtn = document.getElementById('btn-search');
    if (searchBtn) {
        searchBtn.addEventListener('click', function (ev) {
            ev.preventDefault();
            bsStartSearch();
        });
    }

    const exportBtn = document.getElementById('btn-export');
    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            if (_bsState.searchId) {
                window.location.href = '/api/brand-search/' + _bsState.searchId + '/export';
            }
        });
    }

    document.querySelectorAll('.filter-chip[data-reputation]').forEach(c => {
        c.addEventListener('click', () => bsSetRepFilter(c.dataset.reputation));
    });

    document.querySelectorAll('.filter-chip[data-min-items]').forEach(c => {
        c.addEventListener('click', () => bsSetMinItemsFilter(parseInt(c.dataset.minItems, 10)));
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bsBindUi);
} else {
    bsBindUi();
}

// ── Busca ─────────────────────────────────────────────────────────────────────
async function bsStartSearch() {
    const brandId = document.getElementById('inp-brand-id').value.trim();
    const brandName = document.getElementById('inp-brand').value.trim();
    const siteId = 'MLB';
    const catEl = document.getElementById('sel-cat');
    const categoryId = catEl && catEl.value ? catEl.value : null;

    if (!brandId) { bsShowError('Preencha o ID da marca.'); return; }
    if (!brandName) { bsShowError('Preencha o nome da marca.'); return; }

    bsClearError();
    bsShowProgress();
    bsUpdateProgress(0, 'Iniciando busca ' + brandName + '...');

    const body = { brand_id: brandId, brand_name: brandName, site_id: siteId };
    if (categoryId) body.category_id = categoryId;

    try {
        const res = await bsApiPost('/api/brand-search/start', body);
        if (!res.success) { throw new Error(res.error || 'Falha ao iniciar busca'); }
        _bsState.searchId = res.search_id;
        document.getElementById('btn-export').disabled = false;
        bsStartPolling(res.search_id);
    } catch (err) {
        bsHideProgress();
        bsShowError('Erro ao iniciar busca: ' + err.message);
    }
}

function bsStartPolling(searchId) {
    bsStopPolling();
    _bsState.pollGeneration = (_bsState.pollGeneration || 0) + 1;
    const generation = _bsState.pollGeneration;

    const tick = async () => {
        if (generation !== _bsState.pollGeneration) {
            return;
        }
        try {
            const data = await bsApiGet('/api/brand-search/' + searchId + '/progress');
            if (generation !== _bsState.pollGeneration) {
                return;
            }
            bsUpdateProgressFromApi(data);

            if (data.status === 'completed') {
                bsStopPolling();
                bsHideProgress();
                bsUpdateStats(data);
                bsLoadSellers(searchId, 1);
                return;
            }
            if (data.status === 'failed') {
                bsStopPolling();
                bsHideProgress();
                bsShowError('Busca falhou: ' + (data.error_message || 'erro desconhecido'));
                return;
            }

            _bsState.pollInterval = setTimeout(tick, 2000);
        } catch (err) {
            if (generation !== _bsState.pollGeneration) {
                return;
            }
            bsStopPolling();
            bsShowError('Erro no polling: ' + err.message);
        }
    };

    // Primeiro tick imediato (não espera 2s); próximos via setTimeout encadeado.
    _bsState.pollInterval = setTimeout(tick, 0);
}

function bsStopPolling() {
    if (_bsState.pollInterval) {
        clearTimeout(_bsState.pollInterval);
        clearInterval(_bsState.pollInterval);
        _bsState.pollInterval = null;
    }
}

// ── Sellers ───────────────────────────────────────────────────────────────────
async function bsLoadSellers(searchId, page) {
    _bsState.currentPage = page;

    const params = new URLSearchParams({
        page: page,
        per_page: _bsState.perPage,
        sort: _bsState.sort,
        order: _bsState.order,
    });

    if (_bsState.filters.reputation) params.set('reputation', _bsState.filters.reputation);
    if (_bsState.filters.minItems) params.set('min_items', _bsState.filters.minItems);

    const data = await bsApiGet('/api/brand-search/' + searchId + '/sellers?' + params);
    _bsState.totalSellers = data.total;

    const sellers = data.data || [];
    bsRenderTable(sellers);
    bsRenderPagination(data.total, data.page, data.last_page);
    document.getElementById('table-title').textContent =
        bsFormatNumber(data.total) + ' vendedores encontrados — marca ' +
        document.getElementById('inp-brand').value;

    // Update price + leaders stats from current page sellers (best effort)
    if (page === 1 && sellers.length) {
        bsUpdateSellerStats(sellers);
    }
}

// ── Render ────────────────────────────────────────────────────────────────────
function bsRenderTable(sellers) {
    const tbody = document.getElementById('sellers-tbody');

    if (!sellers.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Nenhum vendedor encontrado.</td></tr>';
        return;
    }

    tbody.innerHTML = sellers.map((s, i) => {
        const rank = (_bsState.currentPage - 1) * _bsState.perPage + i + 1;
        const score = parseInt(s.reputation_score || 0, 10);
        const repColor = score >= 90 ? '#1D9E75' : score >= 75 ? '#EF9F27' : '#E24B4A';
        const initials = bsGetInitials(s.nickname);
        const avatarBg = bsGetAvatarBg(s.reputation_level);
        const avatarTx = bsGetAvatarColor(s.reputation_level);

        return `<tr>
            <td class="align-middle text-muted small">${rank}</td>
            <td class="align-middle">
                <div class="seller-cell">
                    <div class="avatar" style="background:${avatarBg};color:${avatarTx}">${bsEsc(initials)}</div>
                    <div style="overflow:hidden">
                        <div class="seller-name">${bsEsc(s.nickname)}</div>
                        <div class="seller-id">ML-${bsEsc(String(s.seller_id))}</div>
                    </div>
                </div>
            </td>
            <td class="align-middle">${bsFormatNumber(s.total_items_brand)}</td>
            <td class="align-middle">
                <div class="rep-bar">
                    <div class="rep-track">
                        <div class="rep-fill" style="width:${score}%;background:${repColor}"></div>
                    </div>
                    <span class="rep-val">${score}%</span>
                </div>
            </td>
            <td class="align-middle small">${bsFormatCurrency(s.avg_price)}</td>
            <td class="align-middle">${bsRenderBadge(s.reputation_level)}</td>
            <td class="align-middle small">${bsRenderTrend(s.trend)}</td>
            <td class="align-middle">
                <div class="action-cell">
                    <button class="icon-btn" title="Ver anúncios"
                        onclick="bsViewItems(${parseInt(s.seller_id, 10)})">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </button>
                    <button class="icon-btn" title="Analisar concorrente"
                        onclick="window.location.href='/competitors?seller_id=${parseInt(s.seller_id, 10)}'">
                        <i class="bi bi-graph-up"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

function bsRenderBadge(level) {
    const map = {
        platinum: ['#EEEDFE', '#3C3489', 'Platinum'],
        gold: ['#FAEEDA', '#633806', 'Gold'],
        '5_green': ['#FAEEDA', '#633806', 'Gold'],
        '4_light_green': ['#E1F5EE', '#085041', 'Verde'],
        silver: ['#F1EFE8', '#444441', 'Silver'],
        new: ['#E6F1FB', '#0C447C', 'Novo'],
    };
    const style = map[level] || map['new'];
    return `<span class="badge-rep" style="background:${style[0]};color:${style[1]}">${style[2]}</span>`;
}

function bsRenderTrend(trend) {
    if (trend === 'up') return '<span style="color:#1D9E75">▲ subindo</span>';
    if (trend === 'down') return '<span style="color:#E24B4A">▼ caindo</span>';
    return '<span style="color:#888780">— estável</span>';
}

function bsRenderPagination(total, current, lastPage) {
    const info = document.getElementById('pagination-info');
    const list = document.getElementById('pagination-list');
    if (!info || !list) return;

    const from = (current - 1) * _bsState.perPage + 1;
    const to = Math.min(current * _bsState.perPage, total);
    info.textContent = 'Mostrando ' + from + '–' + to + ' de ' + bsFormatNumber(total);

    list.innerHTML = '';

    const makeItem = (label, page, disabled, active) => {
        const li = document.createElement('li');
        li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
        const a = document.createElement('a');
        a.className = 'page-link';
        a.href = '#';
        a.innerHTML = label;
        if (!disabled && !active) {
            a.addEventListener('click', (e) => {
                e.preventDefault();
                bsLoadSellers(_bsState.searchId, page);
            });
        }
        if (active) {
            a.style.cssText = 'background:#E1F5EE;border-color:#5DCAA5;color:#0F6E56;font-weight:500';
        }
        li.appendChild(a);
        return li;
    };

    list.appendChild(makeItem('‹', current - 1, current <= 1, false));

    const pages = bsPageNumbers(current, lastPage);
    let prev = null;
    for (const p of pages) {
        if (prev !== null && p - prev > 1) {
            list.appendChild(makeItem('…', null, true, false));
        }
        list.appendChild(makeItem(p, p, false, p === current));
        prev = p;
    }

    list.appendChild(makeItem('›', current + 1, current >= lastPage, false));
}

function bsPageNumbers(current, last) {
    const range = [];
    for (let i = Math.max(1, current - 2); i <= Math.min(last, current + 2); i++) {
        range.push(i);
    }
    if (range[0] > 1) range.unshift(1);
    if (range[range.length - 1] < last) range.push(last);
    return range;
}

// ── Filtros ───────────────────────────────────────────────────────────────────
function bsSetRepFilter(value) {
    _bsState.filters.reputation = value === 'all' ? null : value;
    document.querySelectorAll('.filter-chip[data-reputation]').forEach(c => c.classList.remove('active'));
    document.querySelector('.filter-chip[data-reputation="' + value + '"]').classList.add('active');
    if (_bsState.searchId) bsLoadSellers(_bsState.searchId, 1);
}

function bsSetMinItemsFilter(value) {
    _bsState.filters.minItems = value === 0 ? null : value;
    document.querySelectorAll('.filter-chip[data-min-items]').forEach(c => c.classList.remove('active'));
    document.querySelector('.filter-chip[data-min-items="' + value + '"]').classList.add('active');
    if (_bsState.searchId) bsLoadSellers(_bsState.searchId, 1);
}

// ── Progress ──────────────────────────────────────────────────────────────────
function bsShowProgress() { document.getElementById('progress-wrap').style.display = 'block'; }
function bsHideProgress() { document.getElementById('progress-wrap').style.display = 'none'; }

function bsUpdateProgress(pct, text) {
    document.getElementById('progress-fill').style.width = pct + '%';
    document.getElementById('progress-pct').textContent = pct + '%';
    document.getElementById('progress-text').textContent = text;
}

function bsUpdateProgressFromApi(data) {
    const pct = parseInt(data.progress || 0, 10);
    let text;
    if (data.status === 'running') {
        if (pct < 20) text = 'Iniciando busca...';
        else if (pct < 45) text = 'Paginando categorias...';
        else if (pct < 70) text = 'Coletando seller_ids únicos...';
        else if (pct < 90) text = 'Consultando perfis dos vendedores...';
        else text = 'Calculando métricas e reputação...';
    } else if (data.status === 'completed') {
        text = 'Busca concluída!';
    } else if (data.status === 'failed') {
        text = 'Busca falhou.';
    } else {
        text = 'Aguardando worker...';
    }
    bsUpdateProgress(pct, text);
}

function bsUpdateStats(data) {
    document.getElementById('stat-sellers').textContent = bsFormatNumber(data.total_sellers);
    document.getElementById('stat-items').textContent = bsFormatNumber(data.total_items);
}

// Update price + leaders from seller rows (called after first page loads)
function bsUpdateSellerStats(sellers) {
    const leaders = sellers.filter(s => s.reputation_level === 'platinum' || s.reputation_level === 'gold').length;
    if (prices.length) {
        const avg = prices.reduce((a, b) => a + b, 0) / prices.length;
        document.getElementById('stat-price').textContent = bsFormatCurrency(avg);
    }
    if (leaders >= 0) {
        document.getElementById('stat-leaders').textContent = bsFormatNumber(leaders);
    }
}

// ── Actions ───────────────────────────────────────────────────────────────────
function bsViewItems(sellerId) {
    if (_bsState.searchId) {
        window.location.href = '/brand-search/' + _bsState.searchId + '/seller/' + sellerId;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
async function bsApiPost(url, body) {
    const csrf = document.querySelector('meta[name="csrf-token"]');
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrf ? csrf.content : '',
        },
        body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
}

async function bsApiGet(url) {
    const res = await fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
}

function bsFormatNumber(v) {
    return parseInt(v || 0, 10).toLocaleString('pt-BR');
}

function bsFormatCurrency(v) {
    if (v === null || v === undefined || v === '') return '—';
    return 'R$ ' + Math.round(parseFloat(v)).toLocaleString('pt-BR');
}

function bsGetInitials(nickname) {
    return (nickname || '?').split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase();
}

function bsGetAvatarBg(level) {
    const m = { platinum: '#EEEDFE', gold: '#FAEEDA', silver: '#F1EFE8', new: '#E6F1FB' };
    return m[level] || '#F1EFE8';
}

function bsGetAvatarColor(level) {
    const m = { platinum: '#3C3489', gold: '#633806', silver: '#444441', new: '#0C447C' };
    return m[level] || '#444441';
}

function bsEsc(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function bsClearError() {
    const banner = document.getElementById('bs-error-banner');
    if (banner) {
        banner.style.display = 'none';
        banner.textContent = '';
    }
}

function bsShowError(msg) {
    console.error('[BrandSearch]', msg);
    const banner = document.getElementById('bs-error-banner');
    if (banner) {
        banner.textContent = msg;
        banner.style.display = 'block';
    }
    const toast = document.getElementById('toast-error') || document.getElementById('general-toast');
    if (toast && typeof bootstrap !== 'undefined') {
        const body = toast.querySelector('.toast-body');
        if (body) body.textContent = msg;
        bootstrap.Toast.getOrCreateInstance(toast).show();
    }
}
