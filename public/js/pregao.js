/**
 * Pregão client — snapshot inicial + WS (com fallback SSE) e reconexão exponencial.
 * 100% leitura: nenhum endpoint de escrita no ML.
 */
/* eslint-env browser */
/* global window, document, matchMedia, setInterval, devicePixelRatio, getComputedStyle,
          addEventListener, fetch, setTimeout, clearTimeout, location, WebSocket, EventSource, console */
(function () {
    'use strict';

    const boot = window.PREGAO_BOOT || {};
    const $ = (id) => document.getElementById(id);
    const rm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const accountId = Number(boot.accountId || 0);

    /** @type {Set<string>} */
    const seenOps = new Set();
    let candles = [];
    let currentDate = null;
    let open0 = null;
    let cur = { o: null, c: null, h: null, l: null };
    let indexWatermarkMs = null;
    const candleWatermarks = new Map();
    let reconnectAttempt = 0;
    let es = null;
    let ws = null;
    let intentionalClose = false;
    const agentOrder = ['sentinela', 'collector', 'financeiro', 'otimizador', 'orquestrador'];
    const agentStatuses = ['waiting', 'success', 'skipped', 'blocked', 'failed'];
    const agentSafeReasons = new Set([
        'aggregated', 'agent_blocked', 'agent_exception', 'agent_failed',
        'collector_unavailable', 'cost_validation_blocked', 'financeiro_unavailable',
        'incomplete_legacy_payload', 'invalid_legacy_payload',
        'invalid_optimizer_cost_snapshot', 'invalid_optimizer_observation_snapshot',
        'legacy_error', 'legacy_read_complete', 'read_only_violation',
        'recommendations_ready', 'runtime_exception', 'sentinela_unavailable'
    ]);
    const agentReasonsByStatus = {
        success: ['aggregated', 'legacy_read_complete', 'recommendations_ready'],
        skipped: ['legacy_read_complete'],
        blocked: ['agent_blocked', 'cost_validation_blocked', 'read_only_violation'],
        failed: [
            'agent_exception', 'agent_failed', 'collector_unavailable', 'financeiro_unavailable',
            'incomplete_legacy_payload', 'invalid_legacy_payload',
            'invalid_optimizer_cost_snapshot', 'invalid_optimizer_observation_snapshot',
            'legacy_error', 'read_only_violation', 'runtime_exception', 'sentinela_unavailable'
        ]
    };
    const agentEventKeys = ['account_id', 'payload', 'source', 'ts', 'type', 'v'];
    const realtimeEventTypes = new Set([
        'account.semaforo', 'agent.status', 'index.candle', 'index.tick', 'keyword.rank',
        'metric.update', 'op', 'qa.status', 'sale'
    ]);
    const agentPayloadKeys = [
        'agent', 'attempts', 'correlation_id', 'ml_write_automation',
        'reason', 'state_changed', 'status'
    ];
    const sourceKeys = new Set([
        'sales', 'ads', 'visits', 'health', 'reputation', 'questions', 'ranks', 'watchlist'
    ]);
    const sourceReasons = {
        rank_tracker_disabled: 'desativado por segurança',
        ml_search_forbidden: 'busca bloqueada pelo Mercado Livre',
        seller_not_found_in_search: 'seller não encontrado',
        unavailable: 'indisponível',
        watchlist_empty: 'watchlist vazia'
    };
    let observabilityConsolidatedMs = null;
    const agentStaleAfterMs = 600 * 1000;
    const agentState = Object.create(null);

    setInterval(() => {
        if ($('clock')) $('clock').textContent = new Date().toLocaleTimeString('pt-BR');
        refreshAgentFreshness(Date.now());
        refreshObservabilityFreshness(Date.now());
    }, 1000);

    function fmtMoney(n) {
        if (n === null || n === undefined || Number.isNaN(Number(n))) return 'n/d';
        return 'R$ ' + Math.round(Number(n) || 0).toLocaleString('pt-BR');
    }
    function fmtNum(n, digits) {
        if (n === null || n === undefined || Number.isNaN(Number(n))) return 'n/d';
        return Number(n).toLocaleString('pt-BR', {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits
        });
    }
    /** Formata segundos como duração humana: 51s · 17m · 8h21 · 2d3h */
    function fmtDuration(seconds) {
        if (seconds === null || seconds === undefined || Number.isNaN(Number(seconds))) return 'n/d';
        let s = Math.max(0, Math.round(Number(seconds)));
        if (s < 60) return s + 's';
        if (s < 3600) return Math.floor(s / 60) + 'm';
        const days = Math.floor(s / 86400);
        s -= days * 86400;
        const hours = Math.floor(s / 3600);
        const mins = Math.floor((s % 3600) / 60);
        if (days > 0) {
            return days + 'd' + (hours > 0 ? hours + 'h' : '');
        }
        return hours + 'h' + String(mins).padStart(2, '0');
    }
    function nd(v, formatter) {
        if (v === null || v === undefined) return 'n/d';
        return formatter ? formatter(v) : String(v);
    }
    function setFactorsBadge(index) {
        const el = $('factorsBadge');
        if (!el) return;
        if (index && index.label) {
            el.textContent = index.label;
            return;
        }
        const a = index && index.factors_active != null ? index.factors_active : '—';
        const t = index && index.factors_total != null ? index.factors_total : 5;
        el.textContent = a + ' de ' + t + ' fatores ativos';
    }
    function opKey(ev) {
        return (ev.ts || '') + '|' + (ev.payload && ev.payload.msg ? ev.payload.msg : '') + '|' + (ev.payload && ev.payload.sku ? ev.payload.sku : '');
    }

    /* ---------- CHART ---------- */
    const cv = $('chart');
    const ctx = cv ? cv.getContext('2d') : null;
    const chartEmpty = $('chartEmpty');
    const layoutApi = window.PregaoChartLayout || null;

    function updateHeader() {
        const p = cur.c;
        if (!Number.isFinite(p)) {
            $('px').textContent = 'n/d';
            $('px').style.color = '';
            $('chg').textContent = 'n/d';
            $('chg').className = 'chg';
            $('fOpen').textContent = 'n/d';
            $('fHigh').textContent = 'n/d';
            $('fLow').textContent = 'n/d';
            return;
        }
        const pc = Object.prototype.hasOwnProperty.call(cur, 'change_pct')
            ? (Number.isFinite(cur.change_pct) ? cur.change_pct : null)
            : (Number.isFinite(open0) && open0 > 0 ? (p / open0 - 1) * 100 : null);
        $('px').textContent = fmtNum(p, 2);
        const e = $('chg');
        if (pc === null || !Number.isFinite(pc)) {
            $('px').style.color = '';
            e.textContent = 'n/d';
            e.className = 'chg';
        } else {
            $('px').style.color = pc >= 0 ? 'var(--up)' : 'var(--down)';
            e.textContent = (pc >= 0 ? '▲ +' : '▼ ') + fmtNum(Math.abs(pc), 2) + '%';
            e.className = 'chg ' + (pc >= 0 ? 'up' : 'down');
        }
        $('fOpen').textContent = Number.isFinite(open0) ? open0.toFixed(0) : 'n/d';
        $('fHigh').textContent = Number.isFinite(cur.h) ? cur.h.toFixed(0) : 'n/d';
        $('fLow').textContent = Number.isFinite(cur.l) ? cur.l.toFixed(0) : 'n/d';
    }

    function setChartEmpty(show) {
        if (chartEmpty) chartEmpty.hidden = !show;
        if (cv) cv.style.opacity = show ? '0.25' : '1';
    }


    function draw() {
        if (!ctx || !cv) return;
        const dpr = devicePixelRatio || 1;
        const W = cv.clientWidth;
        const H = cv.clientHeight || 380;
        if (cv.width !== W * dpr) { cv.width = W * dpr; cv.height = H * dpr; }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, W, H);

        if (!candles.length) {
            setChartEmpty(true);
            ctx.fillStyle = '#5B6B8C';
            ctx.font = '13px ' + (getComputedStyle(document.documentElement).getPropertyValue('--mono') || 'monospace');
            ctx.textAlign = 'center';
            ctx.fillText('aguardando primeiro fechamento', W / 2, H / 2);
            ctx.textAlign = 'left';
            return;
        }
        setChartEmpty(false);

        const padR = 62, padT = 18, padB = 14;
        const plotW = Math.max(1, W - padR);
        const layout = layoutApi
            ? layoutApi.computeCandleLayout(plotW, candles.length)
            : (function fallbackLayout() {
                const slots = candles.length < 10 ? 10 : candles.length;
                const slotWidth = plotW / slots;
                const candleWidth = Math.max(2, Math.min(14, slotWidth * 0.55));
                const offsetX = candles.length < 10 ? (slots - candles.length) * slotWidth : 0;
                return { slotWidth: slotWidth, candleWidth: candleWidth, offsetX: offsetX, slots: slots };
            })();

        let hi = -1e9, lo = 1e9;
        candles.forEach((k) => { hi = Math.max(hi, k.h); lo = Math.min(lo, k.l); });
        const sp = (hi - lo) || 1;
        hi += sp * 0.06; lo -= sp * 0.06;
        const y = (v) => padT + (hi - v) / (hi - lo) * (H - padT - padB);

        ctx.strokeStyle = '#16203A';
        ctx.fillStyle = '#5B6B8C';
        ctx.font = '10px ' + (getComputedStyle(document.documentElement).getPropertyValue('--mono') || 'monospace');
        for (let i = 0; i <= 4; i++) {
            const vy = padT + i * (H - padT - padB) / 4;
            const pv = hi - (hi - lo) * i / 4;
            ctx.beginPath(); ctx.moveTo(0, vy); ctx.lineTo(W - padR, vy); ctx.stroke();
            ctx.fillText(pv.toFixed(0), W - padR + 8, vy + 3);
        }
        candles.forEach((k, i) => {
            const x = layoutApi
                ? layoutApi.candleCenterX(layout, i)
                : (layout.offsetX + i * layout.slotWidth + layout.slotWidth / 2);
            const up = k.c >= k.o;
            const col = up ? '#16C784' : '#EA3943';
            ctx.strokeStyle = col;
            ctx.beginPath(); ctx.moveTo(x, y(k.h)); ctx.lineTo(x, y(k.l)); ctx.stroke();
            ctx.fillStyle = col;
            const bw = layout.candleWidth;
            const by = y(Math.max(k.o, k.c));
            const bh = Math.max(1.5, Math.abs(y(k.o) - y(k.c)));
            ctx.fillRect(x - bw / 2, by, bw, bh);
            if (i === candles.length - 1 && !rm) {
                ctx.shadowColor = col; ctx.shadowBlur = 10;
                ctx.fillRect(x - bw / 2, by, bw, bh);
                ctx.shadowBlur = 0;
            }
        });
        const chartPrice = Number.isFinite(cur.c) ? cur.c : null;
        if (chartPrice !== null) {
            const ly = y(chartPrice);
            ctx.setLineDash([4, 4]);
            ctx.strokeStyle = 'rgba(255,230,0,.55)';
            ctx.beginPath(); ctx.moveTo(0, ly); ctx.lineTo(W - padR, ly); ctx.stroke();
            ctx.setLineDash([]);
            ctx.fillStyle = '#FFE600';
            ctx.fillRect(W - padR + 2, ly - 8, padR - 8, 16);
            ctx.fillStyle = '#070B14';
            ctx.font = 'bold 10px monospace';
            ctx.fillText(chartPrice.toFixed(0), W - padR + 10, ly + 3.5);
        }
    }

    addEventListener('resize', draw);

    /* ---------- METRICS / FEED / QA ---------- */
    function flash(id, cls) {
        const e = $(id);
        if (!e) return;
        e.classList.remove('flash-g', 'flash-y');
        void e.offsetWidth;
        e.classList.add(cls);
    }

    function applyMetrics(m) {
        if (!m) return;
        $('vVendas').textContent = nd(m.vendas_hoje);
        $('fSales').textContent = nd(m.vendas_hoje);
        $('vRec').textContent = nd(m.receita_hoje, fmtMoney);
        $('vTicket').textContent = nd(m.ticket_medio, fmtMoney);
        $('pnl').textContent = m.receita_hoje == null ? 'n/d' : ('+ ' + fmtMoney(m.receita_hoje).replace('R$ ', 'R$ '));
        $('vTacos').textContent = m.tacos == null ? 'n/d' : (fmtNum(m.tacos, 1) + '%');
        if ($('sTacos')) {
            if (m.tacos == null) {
                $('sTacos').textContent = 'aguardando módulo Ads';
            } else {
                const acos = m.acos == null ? 'n/d' : (fmtNum(m.acos, 1) + '%');
                const gasto = m.gasto_ads_hoje == null ? 'n/d' : fmtMoney(m.gasto_ads_hoje);
                $('sTacos').textContent = 'ACOS ' + acos + ' · gasto hoje ' + gasto;
            }
        }
        if (m.visitas_7d == null) {
            $('vPos').textContent = 'n/d';
            if ($('sPos')) $('sPos').textContent = 'aguardando coletor';
        } else {
            $('vPos').textContent = fmtNum(m.visitas_7d, 0);
            const base = m.exposicao && m.exposicao.visitas_baseline != null
                ? fmtNum(m.exposicao.visitas_baseline, 0)
                : null;
            if ($('sPos')) {
                $('sPos').textContent = base ? ('baseline 28d/4 · ' + base) : 'visitas 7d (Fe)';
            }
        }
        $('vHealth').textContent = nd(m.health_medio, (v) => fmtNum(v, 2));
        applyPerguntasCard(m);
        $('vAcoes').textContent = nd(m.acoes_hora);
        applySentinelaCard(window.__pregaoLastSentinela || null);

        const rep = m.reputacao;
        if (!rep) {
            $('vRep').textContent = 'n/d';
            $('vRep').style.color = 'var(--mut)';
            $('sRep').textContent = 'sem seller_reputation';
            return;
        }
        const cor = (rep.cor || 'verde').toLowerCase();
        $('vRep').textContent = (cor.indexOf('verde') >= 0 ? '🟢 ' : cor.indexOf('amarelo') >= 0 ? '🟡 ' : '🔴 ') + cor.toUpperCase();
        $('vRep').style.color = cor.indexOf('vermelho') >= 0 ? 'var(--down)' : cor.indexOf('amarelo') >= 0 ? 'var(--ml)' : 'var(--up)';
        $('sRep').textContent = 'reclamações ' + fmtNum(rep.reclamacoes_pct ?? 0, 1) + '% · atrasos ' + fmtNum(rep.atrasos_pct ?? 0, 1) + '%';
    }

    function applyPerguntasCard(m) {
        const card = $('cPerg');
        const p7 = m && m.perguntas_7d;
        if (!p7) {
            if ($('vPerg')) $('vPerg').textContent = 'n/d';
            if ($('sPerg')) $('sPerg').textContent = 'aguardando coletor';
            if (card) card.classList.remove('status-verde', 'status-amarelo', 'status-vermelho');
            renderOpenQuestions([]);
            return;
        }
        const resp = p7.respondidas != null ? p7.respondidas : '—';
        const rec = p7.recebidas != null ? p7.recebidas : '—';
        if ($('vPerg')) $('vPerg').textContent = resp + ' / ' + rec;
        const taxa = p7.taxa != null ? (fmtNum(p7.taxa, 0) + '%') : '—';
        const med = p7.mediana_s != null ? fmtDuration(p7.mediana_s) : '—';
        const ab = p7.abertas != null ? p7.abertas : 0;
        if ($('sPerg')) {
            if (p7.card_reason && (p7.card_status === 'vermelho' || p7.card_status === 'amarelo')) {
                $('sPerg').textContent = p7.card_reason;
            } else {
                $('sPerg').textContent = 'taxa ' + taxa + ' · mediana ' + med + ' · ' + ab + ' em aberto';
            }
        }
        if (card) {
            card.classList.remove('status-verde', 'status-amarelo', 'status-vermelho');
            const st = p7.card_status || 'verde';
            card.classList.add('status-' + st);
        }
        renderOpenQuestions(Array.isArray(p7.lista_abertas) ? p7.lista_abertas : []);
    }

    function applySentinelaCard(sn) {
        const card = $('cSentinela');
        if (!card) return;
        card.classList.remove('status-verde', 'status-amarelo', 'status-vermelho');
        if (!sn || !sn.available) {
            if ($('vSentinela')) $('vSentinela').textContent = 'n/d';
            if ($('sSentinela')) $('sSentinela').textContent = 'aguardando primeira coleta';
            return;
        }
        const sem = sn.semaforo || 'verde';
        if ($('vSentinela')) {
            const icon = sem === 'vermelho' ? '🔴' : (sem === 'amarelo' ? '🟡' : '🟢');
            $('vSentinela').textContent = icon + ' ' + String(sem).toUpperCase();
        }
        if ($('sSentinela')) {
            $('sSentinela').textContent = (sn.monitored || 0) + ' de ' + (sn.total || 11) + ' monitorados · abrir painel';
        }
        card.classList.add('status-' + sem);
    }

    function renderOpenQuestions(list) {
        const ul = $('openQuestions');
        const badge = $('openQCount');
        if (badge) badge.textContent = String(list.length);
        if (!ul) return;
        if (!list.length) {
            ul.innerHTML = '<li class="open-q-empty">Nenhuma pergunta em aberto</li>';
            return;
        }
        let html = '';
        list.forEach((q) => {
            const age = q.open_human || fmtDuration(q.open_seconds || 0);
            const preview = escapeHtml(q.text_preview || q.text || '');
            const item = escapeHtml(q.item_id || '');
            const href = q.ml_url || 'https://www.mercadolivre.com.br/perguntas';
            const alertCls = (q.open_seconds || 0) >= 7200 ? ' hot' : '';
            html += '<li class="open-q' + alertCls + '">'
                + '<div class="oq-age">' + escapeHtml(age) + '</div>'
                + '<div class="oq-body"><b>' + item + '</b> — ' + preview + '</div>'
                + '<a class="oq-link" href="' + escapeHtml(href) + '" target="_blank" rel="noopener noreferrer">Responder no ML</a>'
                + '</li>';
        });
        ul.innerHTML = html;
    }

    function applySemaforo(s) {
        if (!s || s.status == null) {
            const el = $('sema');
            if (el) el.className = 'sema';
            if ($('semaText')) $('semaText').textContent = 'SEMÁFORO n/d';
            return;
        }
        const status = s.status || 'verde';
        const el = $('sema');
        el.className = 'sema ' + status;
        const labels = {
            verde: 'CONTA VERDE · TODOS OS LIMITES <50%',
            amarelo: 'CONTA AMARELA · ATENÇÃO 50–80%',
            vermelho: 'CONTA VERMELHA · ACIMA DE 80% DO LIMITE'
        };
        $('semaText').textContent = labels[status] || status.toUpperCase();
    }

    function renderTape(ranks, rankTrackerEnabled) {
        const el = $('tape');
        if (!el) return;
        el.textContent = '';
        if (rankTrackerEnabled === false) {
            el.textContent = 'rank tracker desativado';
            return;
        }
        const list = Array.isArray(ranks) ? ranks.filter(isValidKeywordRankPayload) : [];
        if (!list.length) {
            el.textContent = 'sem ranks';
            return;
        }
        for (let copy = 0; copy < 2; copy++) {
            list.forEach((rank) => {
                let cls = 'y', label = '·';
                if (rank.delta > 0) { cls = 'u'; label = '▲' + rank.delta; }
                else if (rank.delta < 0) { cls = 'd'; label = '▼' + Math.abs(rank.delta); }
                else if (rank.delta === 0) { label = '='; }
                if (rank.pos === 1) { cls = 'y'; label = 'TOPO'; }
                const item = document.createElement('span');
                const keyword = document.createElement('b');
                const delta = document.createElement('span');
                keyword.textContent = rank.kw;
                delta.className = cls;
                delta.textContent = label;
                item.appendChild(keyword);
                item.appendChild(document.createTextNode(' #' + String(rank.pos) + ' '));
                item.appendChild(delta);
                el.appendChild(item);
            });
        }
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function pushOp(ev, prepend) {
        const key = opKey(ev);
        if (seenOps.has(key)) return;
        seenOps.add(key);
        if (seenOps.size > 500) {
            const first = seenOps.values().next().value;
            seenOps.delete(first);
        }

        const p = ev.payload || {};
        const robot = p.robot || 'SISTEMA';
        const level = p.level || 'info';
        const icon = p.icon || '•';
        const msg = p.msg || '';
        const li = document.createElement('li');
        if (robot === 'VENDA' || level === 'success') li.className = 'sale';
        if (level === 'alert') li.className = 'alert';
        const ts = ev.ts ? new Date(ev.ts).toLocaleTimeString('pt-BR') : new Date().toLocaleTimeString('pt-BR');
        const iconNode = document.createElement('span');
        iconNode.className = 'ic';
        iconNode.textContent = String(icon);
        const textNode = document.createElement('span');
        textNode.className = 'tx';
        const robotNode = document.createElement('b');
        robotNode.textContent = String(robot);
        const timestampNode = document.createElement('span');
        timestampNode.className = 'ts';
        timestampNode.textContent = ts + ' · read-only';
        textNode.appendChild(robotNode);
        textNode.appendChild(document.createTextNode(' — ' + String(msg)));
        textNode.appendChild(timestampNode);
        li.appendChild(iconNode);
        li.appendChild(textNode);
        const feed = $('feed');
        if (prepend !== false) feed.prepend(li);
        else feed.appendChild(li);
        while (feed.children.length > 50) feed.lastChild.remove();
    }

    function applyQa(qa) {
        if (!qa) return;
        const idle = $('qaIdle');
        const stream = $('qaStream');
        const video = $('qaVideo');
        const live = $('qaLive');

        if (qa.running) live.textContent = 'AO VIVO';
        else live.textContent = qa.result ? String(qa.result).toUpperCase() : 'STANDBY';

        if (qa.stream_url) {
            idle.hidden = true;
            video.hidden = true;
            stream.hidden = false;
            if (stream.src !== qa.stream_url) stream.src = qa.stream_url;
        } else if (qa.video_url) {
            idle.hidden = true;
            stream.hidden = true;
            video.hidden = false;
            if (video.src !== qa.video_url) {
                video.src = qa.video_url;
                video.load();
            }
        }

        const logLine = qa.test
            ? `▶ ${qa.suite || 'suite'} · ${qa.test}… ${(qa.result || '').toUpperCase()}`
            : '▶ standby';
        $('qalog').textContent = logLine;
    }

    function eventWatermark(timestamp) {
        return timestampWatermark(timestamp);
    }

    function timestampWatermark(timestamp) {
        if (!isCanonicalAgentTimestamp(timestamp)) return null;
        const parsed = Date.parse(timestamp);
        return Number.isNaN(parsed) ? null : parsed;
    }

    function timestampBusinessDate(timestamp) {
        const parsed = timestampWatermark(timestamp);
        if (parsed == null) return null;
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: 'America/Sao_Paulo',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }).formatToParts(new Date(parsed));
        const values = {};
        parts.forEach((part) => {
            if (part.type !== 'literal') values[part.type] = part.value;
        });
        const date = values.year + '-' + values.month + '-' + values.day;
        return isCalendarDate(date) ? date : null;
    }

    function isCalendarDate(value) {
        if (typeof value !== 'string') return false;
        const match = value.match(/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/);
        if (!match) return false;
        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        const date = new Date(Date.UTC(year, month - 1, day));
        return date.getUTCFullYear() === year
            && date.getUTCMonth() === month - 1
            && date.getUTCDate() === day;
    }

    function sortAndTrimCandles() {
        candles.sort((left, right) => left.date.localeCompare(right.date));
        if (candles.length > 90) candles = candles.slice(-90);
    }

    function applyIndexTick(value, eventTs) {
        if (typeof value !== 'number' || !Number.isFinite(value)) return;
        const v = value;
        const incomingMs = eventWatermark(eventTs);
        const eventDate = timestampBusinessDate(eventTs);
        if (incomingMs == null || eventDate == null) return;
        if (currentDate && eventDate < currentDate) return;
        if (indexWatermarkMs != null && incomingMs <= indexWatermarkMs) return;
        indexWatermarkMs = incomingMs;
        if (!currentDate || eventDate > currentDate) {
            currentDate = eventDate;
            open0 = null;
            cur = { o: null, c: v, h: null, l: null, date: eventDate, change_pct: null };
            updateHeader();
            draw();
            return;
        }
        cur.c = v;
        if (!currentDate) {
            currentDate = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
        }
        let currentIndex = currentDate ? findCandleIndex(currentDate) : -1;
        // Sem candle do dia (snapshot falhou): sintetiza um para o gráfico não ficar vazio
        if (currentIndex < 0 && currentDate) {
            candles.push({ o: v, h: v, l: v, c: v, date: currentDate });
            currentIndex = candles.length - 1;
            if (!Number.isFinite(open0)) open0 = v;
            cur.o = v;
            cur.h = v;
            cur.l = v;
            cur.date = currentDate;
        }
        if (currentIndex >= 0) {
            cur.h = cur.h == null ? v : Math.max(cur.h, v);
            cur.l = cur.l == null ? v : Math.min(cur.l, v);
            candles[currentIndex] = { ...candles[currentIndex], c: v, h: cur.h, l: cur.l };
            candleWatermarks.set(currentDate, incomingMs);
        }
        updateHeader();
        draw();
    }

    function findCandleIndex(date) {
        return candles.findIndex((candle) => candle.date === date);
    }

    function applyCandle(c, eventTs) {
        if (!c || !isCalendarDate(c.date)) return;
        if (![c.o, c.h, c.l, c.c].every((value) => typeof value === 'number' && Number.isFinite(value))) return;
        const currentCandleWatermark = candleWatermarks.get(c.date);
        const incomingMs = eventWatermark(eventTs);
        if (incomingMs == null) return;
        if (currentCandleWatermark != null && incomingMs <= currentCandleWatermark) return;
        const staleForCurrentIndex = c.date === currentDate
            && indexWatermarkMs != null
            && incomingMs <= indexWatermarkMs;
        const row = { o: +c.o, h: +c.h, l: +c.l, c: +c.c, date: c.date };
        if (staleForCurrentIndex && typeof cur.c === 'number' && Number.isFinite(cur.c)) {
            row.c = cur.c;
            row.h = Math.max(row.h, cur.c, Number.isFinite(cur.h) ? cur.h : row.h);
            row.l = Math.min(row.l, cur.c, Number.isFinite(cur.l) ? cur.l : row.l);
        }
        const idx = findCandleIndex(c.date);
        if (idx >= 0) candles[idx] = row;
        else candles.push(row);
        candleWatermarks.set(c.date, staleForCurrentIndex ? indexWatermarkMs : incomingMs);
        sortAndTrimCandles();
        if (!currentDate || c.date > currentDate) {
            currentDate = c.date;
            cur = { ...row };
            open0 = row.o;
            indexWatermarkMs = incomingMs;
        } else if (c.date === currentDate) {
            cur = { ...row };
            open0 = Number.isFinite(row.o) ? row.o : open0;
            if (!staleForCurrentIndex) {
                indexWatermarkMs = indexWatermarkMs == null ? incomingMs : Math.max(indexWatermarkMs, incomingMs);
            }
        }
        updateHeader();
        draw();
    }

    function applyMetricUpdate(p) {
        if (!p || !p.key) return;
        const map = {
            vendas_hoje: ['vVendas', 'cVendas'],
            receita_hoje: ['vRec', 'cRec'],
            ticket_medio: ['vTicket', 'cRec'],
            tacos: ['vTacos', 'cTacos'],
            visitas_7d: ['vPos', 'cPos'],
            posicao_media: ['vPos', 'cPos'],
            health_medio: ['vHealth', 'cHealth'],
            perguntas_hoje: ['vPerg', 'cPerg'],
            tempo_medio_resposta_s: ['vTmed', 'cPerg'],
            acoes_hora: ['vAcoes', 'cAcoes']
        };
        if (p.key === 'reputacao') {
            applyMetrics({ reputacao: p });
            flash('cRep', p.flash === 'yellow' ? 'flash-y' : 'flash-g');
            return;
        }
        if (p.key === 'perguntas_7d' && p.value && typeof p.value === 'object') {
            applyPerguntasCard({
                perguntas_7d: {
                    recebidas: p.value.recebidas,
                    respondidas: p.value.respondidas,
                    taxa: p.value.taxa,
                    mediana_s: p.value.mediana_s,
                    abertas: p.value.abertas,
                    card_status: p.value.card_status,
                    card_reason: p.value.card_reason || '',
                    lista_abertas: p.value.abertas_list || []
                }
            });
            flash('cPerg', p.flash === 'yellow' ? 'flash-y' : 'flash-g');
            return;
        }
        if (p.key === 'sentinela' && p.value && typeof p.value === 'object') {
            window.__pregaoLastSentinela = {
                available: true,
                semaforo: p.value.semaforo,
                monitored: p.value.monitored,
                total: p.value.total || 11,
                pode_expandir: !!p.value.pode_expandir
            };
            applySentinelaCard(window.__pregaoLastSentinela);
            flash('cSentinela', p.flash === 'yellow' ? 'flash-y' : 'flash-g');
            return;
        }
        const ids = map[p.key];
        if (!ids) return;
        const el = $(ids[0]);
        if (!el) return;
        if (p.value === null || p.value === undefined) {
            el.textContent = 'n/d';
        } else if (p.key === 'receita_hoje' || p.key === 'ticket_medio') {
            el.textContent = fmtMoney(p.value);
        } else if (p.key === 'tacos') {
            el.textContent = p.value == null ? 'n/d' : (fmtNum(p.value, 1) + '%');
            if ($('sTacos')) {
                if (p.value == null) {
                    $('sTacos').textContent = p.message || 'nenhuma campanha ativa';
                } else {
                    const acos = p.acos == null ? 'n/d' : (fmtNum(p.acos, 1) + '%');
                    const gasto = p.gasto_hoje == null ? 'n/d' : fmtMoney(p.gasto_hoje);
                    $('sTacos').textContent = 'ACOS ' + acos + ' · gasto hoje ' + gasto;
                }
            }
        } else if (p.key === 'visitas_7d') {
            el.textContent = fmtNum(p.value, 0);
            if ($('sPos')) $('sPos').textContent = 'visitas 7d (Fe)';
        } else if (p.key === 'posicao_media') {
            el.textContent = p.value == null ? 'n/d' : ('#' + fmtNum(p.value, 1));
        } else if (p.key === 'health_medio') {
            el.textContent = fmtNum(p.value, 2);
        } else if (p.key === 'tempo_medio_resposta_s') {
            el.textContent = fmtDuration(p.value);
        } else {
            el.textContent = String(p.value);
        }

        if (p.key === 'vendas_hoje') $('fSales').textContent = nd(p.value);
        if (p.key === 'receita_hoje') {
            $('pnl').textContent = p.value == null ? 'n/d' : ('+ ' + fmtMoney(p.value));
        }

        const flashCls = p.flash === 'yellow' ? 'flash-y' : (p.flash === 'green' ? 'flash-g' : null);
        if (flashCls) flash(ids[1], flashCls);
    }

    function normalizeAgentItem(item, eventTs) {
        const updatedAt = typeof item === 'object' && item !== null && typeof item.updated_at === 'string'
            ? item.updated_at
            : (typeof eventTs === 'string' ? eventTs : null);
        if (!item || typeof item !== 'object'
            || !agentOrder.includes(item.agent)
            || !agentStatuses.includes(item.status)
            || item.status === 'waiting'
            || typeof item.reason !== 'string'
            || (!agentSafeReasons.has(item.reason) && !/^legacy_http_[1345][0-9]{2}$/.test(item.reason))
            || !isAgentStatusReasonCoherent(item.status, item.reason)
            || typeof item.correlation_id !== 'string'
            || !(new RegExp('^agent24x7-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}:' + accountId + '$')).test(item.correlation_id)
            || !Number.isInteger(item.attempts)
            || item.attempts < 1
            || item.attempts > 3
            || item.state_changed !== false
            || item.ml_write_automation !== false
            || (updatedAt !== null && !isCanonicalAgentTimestamp(updatedAt))
        ) return null;
        const realtimeAgeMs = eventTs !== null && updatedAt !== null
            ? Date.now() - Date.parse(updatedAt)
            : 0;
        return {
            agent: item.agent,
            status: item.status,
            reason: item.reason,
            correlation_id: item.correlation_id,
            updated_at: updatedAt,
            stale: item.stale === true || realtimeAgeMs > agentStaleAfterMs
        };
    }

    function isCanonicalAgentTimestamp(timestamp) {
        if (typeof timestamp !== 'string') return false;
        const match = timestamp.match(
            /^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]{1,6})?(?:Z|([+-])([0-9]{2}):([0-9]{2}))$/
        );
        if (!match) return false;
        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        const calendar = new Date(Date.UTC(year, month - 1, day));
        const offsetHour = Number(match[8] || 0);
        const offsetMinute = Number(match[9] || 0);
        if (calendar.getUTCFullYear() !== year
            || calendar.getUTCMonth() !== month - 1
            || calendar.getUTCDate() !== day
            || Number(match[4]) > 23
            || Number(match[5]) > 59
            || Number(match[6]) > 59
            || offsetHour > 14
            || offsetMinute > 59
            || (offsetHour === 14 && offsetMinute !== 0)
        ) return false;
        const valueMs = Date.parse(timestamp);
        return !Number.isNaN(valueMs) && valueMs <= Date.now() + 60 * 1000;
    }

    function isAgentStatusReasonCoherent(status, reason) {
        if (/^legacy_http_[1345][0-9]{2}$/.test(reason)) return status === 'failed';
        return Array.isArray(agentReasonsByStatus[status])
            && agentReasonsByStatus[status].includes(reason);
    }

    function agentStatusLabel(item) {
        if (item.stale && item.updated_at) return 'ATRASADO';
        return {
            success: 'OK',
            skipped: 'SEM MUDANÇA',
            blocked: 'BLOQUEADO',
            failed: 'FALHOU',
            waiting: 'AGUARDANDO'
        }[item.status] || 'AGUARDANDO';
    }

    function agentTone(item) {
        if (item.stale && item.updated_at) return 'is-attention';
        if (item.status === 'success' || item.status === 'skipped') return 'is-healthy';
        if (item.status === 'blocked' || item.status === 'failed') return 'is-attention';
        return 'is-waiting';
    }

    function renderAgent(item) {
        agentState[item.agent] = item;
        const card = $('agentCard-' + item.agent);
        const status = $('agentStatus-' + item.agent);
        const reason = $('agentReason-' + item.agent);
        const time = $('agentTime-' + item.agent);
        if (card) card.className = 'agent-card ' + agentTone(item);
        if (status) status.textContent = agentStatusLabel(item);
        if (reason) reason.textContent = item.reason.replace(/_/g, ' ');
        if (time) {
            if (!item.updated_at) {
                time.textContent = 'sem ciclo registrado';
            } else {
                const parsed = new Date(item.updated_at);
                time.textContent = Number.isNaN(parsed.getTime())
                    ? 'horário indisponível'
                    : 'último ciclo ' + parsed.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
            }
        }
    }

    function refreshAgentSummary() {
        const list = agentOrder.map((agent) => agentState[agent]).filter(Boolean);
        const reporting = list.filter((item) => item.updated_at).length;
        const healthy = list.filter((item) => !item.stale
            && (item.status === 'success' || item.status === 'skipped')).length;
        const unhealthy = list.filter((item) => item.updated_at
            && (item.stale || item.status === 'blocked' || item.status === 'failed')).length;
        const correlations = new Set(list.filter((item) => item.updated_at).map((item) => item.correlation_id));
        const mixedCohort = reporting === agentOrder.length && correlations.size !== 1;
        const attention = reporting > 0
            ? unhealthy + (agentOrder.length - reporting) + (mixedCohort ? 1 : 0)
            : 0;
        const summary = $('agentsSummary');
        if (summary) {
            summary.textContent = reporting + '/' + agentOrder.length + ' reportando · '
                + healthy + ' saudáveis' + (attention ? ' · ' + attention + ' atenção' : '');
            summary.className = 'agents-summary ' + (attention ? 'is-attention' : (reporting ? 'is-healthy' : 'is-waiting'));
        }
    }

    function refreshAgentFreshness(nowMs) {
        const referenceMs = Number.isFinite(Number(nowMs)) ? Number(nowMs) : Date.now();
        agentOrder.forEach((agent) => {
            const item = agentState[agent];
            if (!item || !item.updated_at) return;
            const updatedMs = Date.parse(item.updated_at);
            const ageMs = referenceMs - updatedMs;
            const stale = Number.isNaN(updatedMs)
                || ageMs > agentStaleAfterMs
                || ageMs < -60 * 1000;
            if (item.stale !== stale) renderAgent({ ...item, stale });
        });
        refreshAgentSummary();
    }

    function applyAgents(data) {
        agentOrder.forEach((agent) => {
            if (!agentState[agent]) {
                renderAgent({ agent, status: 'waiting', reason: 'no_data', updated_at: null, stale: true });
            }
        });
        const items = data && Array.isArray(data.items) ? data.items : [];
        items.forEach((item) => {
            const normalized = normalizeAgentItem(item, null);
            if (!normalized || !normalized.updated_at) return;
            const incomingMs = Date.parse(normalized.updated_at);
            if (Number.isNaN(incomingMs)) return;
            const current = agentState[normalized.agent];
            const currentMs = current && current.updated_at ? Date.parse(current.updated_at) : NaN;
            if (!Number.isNaN(currentMs) && incomingMs <= currentMs) return;
            renderAgent(normalized);
        });
        refreshAgentSummary();
    }

    function applyAgentStatus(payload, eventTs) {
        const normalized = normalizeAgentItem({ ...payload, stale: false }, eventTs);
        if (!normalized || !normalized.updated_at) return;
        const incomingMs = Date.parse(normalized.updated_at);
        if (Number.isNaN(incomingMs)) return;
        const current = agentState[normalized.agent];
        const currentMs = current && current.updated_at ? Date.parse(current.updated_at) : NaN;
        if (!Number.isNaN(currentMs) && incomingMs <= currentMs) return;
        renderAgent(normalized);
        refreshAgentSummary();
    }

    function isValidAgentEvent(event) {
        if (!isValidRealtimeEvent(event) || event.type !== 'agent.status') return false;
        const payload = event.payload;
        return payload && typeof payload === 'object'
            && JSON.stringify(Object.keys(payload).sort()) === JSON.stringify(agentPayloadKeys)
            && normalizeAgentItem(payload, event.ts) !== null;
    }

    function isValidRealtimeEvent(event) {
        if (!event || typeof event !== 'object' || Array.isArray(event)) return false;
        return JSON.stringify(Object.keys(event).sort()) === JSON.stringify(agentEventKeys)
            && event.v === 2
            && typeof event.type === 'string'
            && realtimeEventTypes.has(event.type)
            && event.account_id === accountId
            && (event.source === 'live' || event.source === 'seed')
            && isCanonicalAgentTimestamp(event.ts)
            && event.payload && typeof event.payload === 'object'
            && !Array.isArray(event.payload)
            && isValidRealtimePayload(event.type, event.payload);
    }

    function isValidRealtimePayload(type, payload) {
        if (type === 'index.tick') {
            return typeof payload.value === 'number' && Number.isFinite(payload.value);
        }
        if (type === 'index.candle') {
            return isCalendarDate(payload.date)
                && [payload.o, payload.h, payload.l, payload.c].every(
                    (value) => typeof value === 'number' && Number.isFinite(value)
                );
        }
        if (type === 'keyword.rank') return isValidKeywordRankPayload(payload);
        return true;
    }

    function isValidKeywordRankPayload(payload) {
        return payload && typeof payload === 'object'
            && JSON.stringify(Object.keys(payload).sort()) === JSON.stringify(['delta', 'kw', 'pos'])
            && typeof payload.kw === 'string'
            && payload.kw.trim().length > 0
            && payload.kw.length <= 200
            && Number.isInteger(payload.pos)
            && payload.pos >= 1
            && (payload.delta == null || Number.isInteger(payload.delta));
    }

    function handleEvent(ev) {
        if (!isValidRealtimeEvent(ev)) return;
        const lastEvent = $('sourceLastEvent');
        if (lastEvent) {
            const parsed = new Date(ev.ts);
            lastEvent.textContent = Number.isNaN(parsed.getTime())
                ? '—'
                : parsed.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        switch (ev.type) {
            case 'index.tick':
                applyIndexTick(ev.payload && ev.payload.value, ev.ts);
                if (ev.payload) setFactorsBadge(ev.payload);
                break;
            case 'index.candle':
                applyCandle(ev.payload, ev.ts);
                break;
            case 'metric.update':
                applyMetricUpdate(ev.payload || {});
                break;
            case 'op':
                pushOp(ev, true);
                break;
            case 'sale':
                // A cadeia metric/op já vem do backend; venda nunca altera o índice.
                break;
            case 'keyword.rank':
                if (ev.payload) {
                    const k = ev.payload;
                    const node = document.createElement('span');
                    const keyword = document.createElement('b');
                    keyword.textContent = k.kw;
                    node.appendChild(keyword);
                    node.appendChild(document.createTextNode(' #' + String(k.pos)));
                    $('tape').appendChild(node);
                }
                break;
            case 'qa.status':
                applyQa(ev.payload || {});
                break;
            case 'agent.status':
                if (isValidAgentEvent(ev)) applyAgentStatus(ev.payload, ev.ts);
                break;
            case 'account.semaforo':
                applySemaforo(ev.payload || {});
                break;
            default:
                break;
        }
    }

    function setConn(mode) {
        const el = $('conn');
        if (el) {
            el.className = 'conn ' + mode;
            el.textContent = mode === 'ws' ? 'WS' : mode === 'sse' ? 'SSE' : '●';
            el.title = mode;
        }
        const transport = $('sourceTransport');
        if (transport) {
            transport.textContent = mode === 'ws' ? 'WEBSOCKET' : mode === 'sse' ? 'SSE' : 'OFFLINE';
            transport.className = mode === 'ws' ? 'is-ok' : mode === 'sse' ? 'is-warn' : 'is-off';
        }
    }

    function renderObservability(data) {
        const list = $('dataSources');
        const freshness = $('sourceFreshness');
        if (!list || !freshness || !data || typeof data !== 'object' || data.read_only !== true) return;
        const items = Array.isArray(data.items) ? data.items : [];
        list.textContent = '';

        items.forEach((item) => {
            if (!item || typeof item !== 'object' || !sourceKeys.has(item.key)
                || typeof item.label !== 'string' || typeof item.available !== 'boolean') return;
            const card = document.createElement('article');
            card.className = 'source-card ' + (item.available ? 'is-ok' : 'is-off');
            const head = document.createElement('div');
            head.className = 'source-card-head';
            const label = document.createElement('b');
            label.textContent = item.label;
            const status = document.createElement('span');
            status.textContent = item.available ? 'DISPONÍVEL' : 'N/D';
            head.appendChild(label);
            head.appendChild(status);
            const detail = document.createElement('div');
            detail.className = 'source-card-detail';
            const reason = typeof item.reason === 'string' ? sourceReasons[item.reason] : null;
            let detailText = item.available
                ? (typeof item.source === 'string' ? item.source : 'fonte confirmada')
                : (reason || 'indisponível');
            if (Number.isInteger(item.count) && item.count >= 0) {
                detailText += ' · ' + item.count + ' itens';
            }
            const observed = typeof item.observed_at === 'string' ? new Date(item.observed_at) : null;
            if (observed && !Number.isNaN(observed.getTime())) {
                detailText += ' · ' + observed.toLocaleString('pt-BR', {
                    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
                });
            } else if (item.available) {
                // Timestamp ausente nunca vira zero/época — sinaliza explicitamente.
                detailText += ' · horário indisponível';
            }
            detail.textContent = detailText;
            card.appendChild(head);
            card.appendChild(detail);
            list.appendChild(card);
        });

        if (!list.children.length) {
            const empty = document.createElement('div');
            empty.className = 'source-empty';
            empty.textContent = 'Metadados de origem indisponíveis';
            list.appendChild(empty);
        }

        const consolidated = typeof data.consolidated_at === 'string' ? Date.parse(data.consolidated_at) : NaN;
        observabilityConsolidatedMs = Number.isNaN(consolidated) ? null : consolidated;
        refreshObservabilityFreshness(Date.now());
    }

    function refreshObservabilityFreshness(nowMs) {
        const freshness = $('sourceFreshness');
        if (!freshness) return;
        if (observabilityConsolidatedMs === null) {
            freshness.textContent = 'horário indisponível';
            freshness.className = 'source-freshness is-stale';
            return;
        }
        const referenceMs = Number.isFinite(Number(nowMs)) ? Number(nowMs) : Date.now();
        const ageSeconds = Math.max(0, Math.floor((referenceMs - observabilityConsolidatedMs) / 1000));
        const consolidated = new Date(observabilityConsolidatedMs);
        const time = consolidated.toLocaleTimeString('pt-BR', {
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
        freshness.textContent = 'consolidado ' + time + ' · ' + ageSeconds + 's atrás';
        freshness.className = 'source-freshness' + (ageSeconds > 300 ? ' is-stale' : '');
    }

    /* ---------- SNAPSHOT ---------- */
    async function loadSnapshot() {
        const url = (boot.snapshotUrl || '/api/pregao/snapshot') + (accountId ? ('?account_id=' + accountId) : '');
        const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('snapshot HTTP ' + res.status);
        const body = await res.json();
        if (!body.success) throw new Error(body.error || 'snapshot fail');
        const d = body.data;

        const index = d.index || {};
        const snapshotDate = typeof d.server_ts === 'string' ? d.server_ts.slice(0, 10) : null;
        const snapshotWatermarkMs = timestampWatermark(index.updated_at);
        (d.candles || []).forEach((candle) => {
            if (!candle || !isCalendarDate(candle.date)
                || ![candle.o, candle.h, candle.l, candle.c].every(
                    (value) => typeof value === 'number' && Number.isFinite(value)
                )
            ) return;
            const incomingMs = timestampWatermark(candle.updated_at);
            if (incomingMs == null) return;
            const currentMs = candleWatermarks.get(candle.date);
            if (currentMs != null && incomingMs <= currentMs) return;
            const staleForCurrentIndex = candle.date === currentDate
                && indexWatermarkMs != null
                && incomingMs <= indexWatermarkMs;
            const row = {
                o: candle.o, h: candle.h, l: candle.l, c: candle.c, date: candle.date
            };
            if (staleForCurrentIndex && typeof cur.c === 'number' && Number.isFinite(cur.c)) {
                row.c = cur.c;
                row.h = Math.max(row.h, cur.c, Number.isFinite(cur.h) ? cur.h : row.h);
                row.l = Math.min(row.l, cur.c, Number.isFinite(cur.l) ? cur.l : row.l);
            }
            const candleIndex = findCandleIndex(candle.date);
            if (candleIndex >= 0) candles[candleIndex] = row;
            else candles.push(row);
            candleWatermarks.set(candle.date, staleForCurrentIndex ? indexWatermarkMs : incomingMs);
            if (staleForCurrentIndex) {
                cur = { ...row };
                open0 = row.o;
            }
        });
        sortAndTrimCandles();
        const canApplyIndex = snapshotWatermarkMs != null
            && (indexWatermarkMs == null || snapshotWatermarkMs > indexWatermarkMs)
            && (!currentDate || !snapshotDate || snapshotDate >= currentDate);
        const liveValue = typeof index.value === 'number' && Number.isFinite(index.value)
            ? index.value : null;
        const indexOpen = typeof index.open === 'number' && Number.isFinite(index.open)
            ? index.open : null;
        const indexChange = typeof index.change_pct === 'number' && Number.isFinite(index.change_pct)
            ? index.change_pct : null;
        const hasLiveValue = liveValue !== null;
        const hasDailyOpen = indexOpen !== null;
        const hasDailyChange = indexChange !== null;
        if (canApplyIndex) {
            currentDate = snapshotDate || currentDate;
            const currentIndex = currentDate ? findCandleIndex(currentDate) : -1;
            if (currentIndex >= 0) {
            // Candle do dia + índice live prevalece sobre fechamento persistido (stale pós-Ft).
                cur = { ...candles[currentIndex] };
                open0 = hasDailyOpen ? indexOpen : cur.o;
                if (hasLiveValue) {
                    cur.c = liveValue;
                    cur.h = Number.isFinite(cur.h) ? Math.max(cur.h, liveValue) : liveValue;
                    cur.l = Number.isFinite(cur.l) ? Math.min(cur.l, liveValue) : liveValue;
                    candles[currentIndex] = {
                        ...candles[currentIndex], c: cur.c, h: cur.h, l: cur.l
                    };
                }
                const indexHigh = typeof index.high === 'number' && Number.isFinite(index.high)
                    ? index.high : null;
                const indexLow = typeof index.low === 'number' && Number.isFinite(index.low)
                    ? index.low : null;
                if (indexHigh !== null) cur.h = Number.isFinite(cur.h) ? Math.max(cur.h, indexHigh) : indexHigh;
                if (indexLow !== null) cur.l = Number.isFinite(cur.l) ? Math.min(cur.l, indexLow) : indexLow;
                if (hasDailyOpen && hasDailyChange) cur.change_pct = indexChange;
                if (snapshotWatermarkMs != null) candleWatermarks.set(currentDate, snapshotWatermarkMs);
            } else if (hasLiveValue) {
                cur = {
                    o: hasDailyOpen ? indexOpen : null,
                    c: liveValue,
                    h: null,
                    l: null,
                    date: currentDate,
                    change_pct: hasDailyOpen && hasDailyChange ? indexChange : null
                };
                open0 = hasDailyOpen ? indexOpen : null;
            } else {
                cur = { o: null, c: null, h: null, l: null, date: currentDate, change_pct: null };
                open0 = null;
            }
            if (snapshotWatermarkMs != null) indexWatermarkMs = snapshotWatermarkMs;
        }
        setFactorsBadge(d.index || {});
        updateHeader();
        draw();

        applyMetrics(d.metrics);
        window.__pregaoLastSentinela = d.sentinela || null;
        applySentinelaCard(window.__pregaoLastSentinela);
        applySemaforo(d.semaforo);
        renderTape(d.ranks != null ? d.ranks : d.keywords, d.rank_tracker_enabled);
        applyQa(d.qa);
        applyAgents(d.agents);
        renderObservability(d.observability);

        seenOps.clear();
        $('feed').innerHTML = '';
        const ops = (d.operations || []).slice().reverse();
        ops.forEach((ev) => pushOp(ev, true));
        if (!ops.length && $('feed')) {
            const li = document.createElement('li');
            li.innerHTML = '<span class="ic">·</span><span class="tx">fita vazia — sem eventos live<span class="ts">read-only</span></span>';
            $('feed').appendChild(li);
        }
    }

    /* ---------- TRANSPORT ---------- */
    function backoffMs() {
        const base = Math.min(30000, 1000 * Math.pow(2, reconnectAttempt));
        reconnectAttempt += 1;
        return base;
    }

    function scheduleReconnect() {
        setConn('off');
        const wait = backoffMs();
        setTimeout(() => {
            loadSnapshot()
                .catch(() => null)
                .finally(() => connectRealtime());
        }, wait);
    }

    async function connectWs() {
        const ticketRes = await fetch((boot.ticketUrl || '/api/pregao/ticket') + (accountId ? ('?account_id=' + accountId) : ''), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        });
        if (!ticketRes.ok) throw new Error('ticket fail');
        const ticketBody = await ticketRes.json();
        if (!ticketBody.success) throw new Error('ticket denied');
        const ticket = ticketBody.data.ticket;
        const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
        const wsUrl = proto + '//' + location.host + (boot.wsPath || '/ws/pregao') + '?ticket=' + encodeURIComponent(ticket);
        return new Promise((resolve, reject) => {
            const socket = new WebSocket(wsUrl);
            const timer = setTimeout(() => {
                try { socket.close(); } catch (e) { /* ignore */ }
                reject(new Error('ws timeout'));
            }, 4000);
            socket.onopen = () => {
                clearTimeout(timer);
                resolve(socket);
            };
            socket.onerror = () => {
                clearTimeout(timer);
                reject(new Error('ws error'));
            };
        });
    }

    function bindWs(socket) {
        ws = socket;
        setConn('ws');
        reconnectAttempt = 0;
        intentionalClose = false;
        socket.onmessage = (msg) => {
            try {
                const ev = JSON.parse(msg.data);
                if (ev && ev.type && ev.type !== 'connected') handleEvent(ev);
            } catch (e) { /* ignore */ }
        };
        socket.onclose = () => {
            ws = null;
            if (!intentionalClose) scheduleReconnect();
        };
        socket.onerror = () => {
            try { socket.close(); } catch (e) { /* ignore */ }
        };
    }

    function connectSse() {
        const url = (boot.streamUrl || '/api/pregao/stream') + (accountId ? ('?account_id=' + accountId) : '');
        es = new EventSource(url, { withCredentials: true });
        setConn('sse');
        reconnectAttempt = 0;

        const types = ['index.tick', 'index.candle', 'metric.update', 'op', 'sale', 'keyword.rank', 'qa.status', 'agent.status', 'account.semaforo'];
        types.forEach((t) => {
            es.addEventListener(t, (e) => {
                try { handleEvent(JSON.parse(e.data)); } catch (err) { /* ignore */ }
            });
        });
        es.onmessage = (e) => {
            try { handleEvent(JSON.parse(e.data)); } catch (err) { /* ignore */ }
        };
        es.onerror = () => {
            try { es.close(); } catch (e) { /* ignore */ }
            es = null;
            scheduleReconnect();
        };
    }

    async function connectRealtime() {
        intentionalClose = true;
        if (ws) { try { ws.close(); } catch (e) { /* ignore */ } ws = null; }
        if (es) { try { es.close(); } catch (e) { /* ignore */ } es = null; }
        intentionalClose = false;

        try {
            const socket = await connectWs();
            bindWs(socket);
        } catch (e) {
            connectSse();
        }
    }

    /* ---------- BOOT ---------- */
    loadSnapshot()
        .then(() => connectRealtime())
        .catch((err) => {
            console.error('[pregao] snapshot', err);
            open0 = null;
            cur = { o: null, c: null, h: null, l: null, date: currentDate, change_pct: null };
            updateHeader();
            draw();
            $('semaText').textContent = 'FALHA NO SNAPSHOT';
            connectSse();
        });
})();
