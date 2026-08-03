/**
 * Pregão client — snapshot inicial + WS (com fallback SSE) e reconexão exponencial.
 * 100% leitura: nenhum endpoint de escrita no ML.
 */
(function () {
    'use strict';

    const boot = window.PREGAO_BOOT || {};
    const $ = (id) => document.getElementById(id);
    const rm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const accountId = Number(boot.accountId || 0);

    /** @type {Set<string>} */
    const seenOps = new Set();
    let candles = [];
    let open0 = 1000;
    let cur = { o: 1000, c: 1000, h: 1000, l: 1000 };
    let reconnectAttempt = 0;
    let transport = null;
    let es = null;
    let ws = null;
    let intentionalClose = false;

    setInterval(() => {
        if ($('clock')) $('clock').textContent = new Date().toLocaleTimeString('pt-BR');
    }, 1000);

    function fmtMoney(n) {
        return 'R$ ' + Math.round(Number(n) || 0).toLocaleString('pt-BR');
    }
    function fmtNum(n, digits) {
        return Number(n).toLocaleString('pt-BR', {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits
        });
    }
    function opKey(ev) {
        return (ev.ts || '') + '|' + (ev.payload && ev.payload.msg ? ev.payload.msg : '') + '|' + (ev.payload && ev.payload.sku ? ev.payload.sku : '');
    }

    /* ---------- CHART ---------- */
    const cv = $('chart');
    const ctx = cv ? cv.getContext('2d') : null;

    function updateHeader() {
        if (!candles.length) return;
        const p = cur.c;
        const pc = (p / open0 - 1) * 100;
        $('px').textContent = fmtNum(p, 2);
        $('px').style.color = pc >= 0 ? 'var(--up)' : 'var(--down)';
        const e = $('chg');
        e.textContent = (pc >= 0 ? '▲ +' : '▼ ') + fmtNum(Math.abs(pc), 2) + '%';
        e.className = 'chg ' + (pc >= 0 ? 'up' : 'down');
        let hi = -1e9, lo = 1e9;
        candles.forEach((k) => { hi = Math.max(hi, k.h); lo = Math.min(lo, k.l); });
        $('fOpen').textContent = open0.toFixed(0);
        $('fHigh').textContent = hi.toFixed(0);
        $('fLow').textContent = lo.toFixed(0);
    }

    function draw() {
        if (!ctx || !cv) return;
        const dpr = devicePixelRatio || 1;
        const W = cv.clientWidth;
        const H = cv.clientHeight || 380;
        if (cv.width !== W * dpr) { cv.width = W * dpr; cv.height = H * dpr; }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, W, H);
        if (!candles.length) return;

        const padR = 62, padT = 18, padB = 14;
        const cw = (W - padR) / candles.length;
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
            const x = i * cw + cw / 2;
            const up = k.c >= k.o;
            const col = up ? '#16C784' : '#EA3943';
            ctx.strokeStyle = col;
            ctx.beginPath(); ctx.moveTo(x, y(k.h)); ctx.lineTo(x, y(k.l)); ctx.stroke();
            ctx.fillStyle = col;
            const bw = Math.max(2, cw * 0.55);
            const by = y(Math.max(k.o, k.c));
            const bh = Math.max(1.5, Math.abs(y(k.o) - y(k.c)));
            ctx.fillRect(x - bw / 2, by, bw, bh);
            if (i === candles.length - 1 && !rm) {
                ctx.shadowColor = col; ctx.shadowBlur = 10;
                ctx.fillRect(x - bw / 2, by, bw, bh);
                ctx.shadowBlur = 0;
            }
        });
        const ly = y(cur.c);
        ctx.setLineDash([4, 4]);
        ctx.strokeStyle = 'rgba(255,230,0,.55)';
        ctx.beginPath(); ctx.moveTo(0, ly); ctx.lineTo(W - padR, ly); ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = '#FFE600';
        ctx.fillRect(W - padR + 2, ly - 8, padR - 8, 16);
        ctx.fillStyle = '#070B14';
        ctx.font = 'bold 10px monospace';
        ctx.fillText(cur.c.toFixed(0), W - padR + 10, ly + 3.5);
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
        $('vVendas').textContent = String(m.vendas_hoje ?? '—');
        $('fSales').textContent = String(m.vendas_hoje ?? '—');
        $('vRec').textContent = fmtMoney(m.receita_hoje);
        $('vTicket').textContent = fmtMoney(m.ticket_medio);
        $('pnl').textContent = '+ ' + fmtMoney(m.receita_hoje).replace('R$ ', 'R$ ');
        $('vTacos').textContent = fmtNum(m.tacos ?? 0, 1) + '%';
        $('vPos').textContent = '#' + fmtNum(m.posicao_media ?? 0, 1);
        $('vHealth').textContent = fmtNum(m.health_medio ?? 0, 2);
        $('vPerg').textContent = String(m.perguntas_hoje ?? '—');
        $('vTmed').textContent = (m.tempo_medio_resposta_s ?? '—') + 's';
        $('vAcoes').textContent = String(m.acoes_hora ?? '—');

        const rep = m.reputacao || {};
        const cor = (rep.cor || 'verde').toLowerCase();
        $('vRep').textContent = (cor.indexOf('verde') >= 0 ? '🟢 ' : cor.indexOf('amarelo') >= 0 ? '🟡 ' : '🔴 ') + cor.toUpperCase();
        $('vRep').style.color = cor.indexOf('vermelho') >= 0 ? 'var(--down)' : cor.indexOf('amarelo') >= 0 ? 'var(--ml)' : 'var(--up)';
        $('sRep').textContent = 'reclamações ' + fmtNum(rep.reclamacoes_pct ?? 0, 1) + '% · atrasos ' + fmtNum(rep.atrasos_pct ?? 0, 1) + '%';
    }

    function applySemaforo(s) {
        if (!s) return;
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

    function renderTape(keywords) {
        const list = Array.isArray(keywords) ? keywords : [];
        if (!list.length) {
            $('tape').innerHTML = '<span>sem ranks de keyword ainda</span><span>sem ranks de keyword ainda</span>';
            return;
        }
        let tp = '';
        list.forEach((k) => {
            const delta = k.delta;
            let cls = 'y', label = '·';
            if (typeof delta === 'number') {
                if (delta > 0) { cls = 'u'; label = '▲' + delta; }
                else if (delta < 0) { cls = 'd'; label = '▼' + Math.abs(delta); }
                else { cls = 'y'; label = '='; }
            }
            if (k.pos === 1) { cls = 'y'; label = 'TOPO'; }
            tp += `<span><b>${escapeHtml(k.kw)}</b> #${k.pos} <span class="${cls}">${label}</span></span>`;
        });
        $('tape').innerHTML = tp + tp;
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
        li.innerHTML = `<span class="ic">${icon}</span><span class="tx"><b>${escapeHtml(robot)}</b> — ${escapeHtml(msg)}<span class="ts">${ts} · read-only</span></span>`;
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

    function applyIndexTick(value) {
        const v = Number(value);
        if (!Number.isFinite(v)) return;
        cur.c = v;
        cur.h = Math.max(cur.h, v);
        cur.l = Math.min(cur.l, v);
        if (candles.length) candles[candles.length - 1] = { ...cur };
        updateHeader();
        draw();
    }

    function applyCandle(c) {
        if (!c || !c.date) return;
        const row = { o: +c.o, h: +c.h, l: +c.l, c: +c.c, date: c.date };
        const idx = candles.findIndex((x) => x.date === c.date);
        if (idx >= 0) candles[idx] = row;
        else candles.push(row);
        if (candles.length > 90) candles = candles.slice(-90);
        cur = { ...row };
        open0 = candles[0].o;
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
        const ids = map[p.key];
        if (!ids) return;
        const el = $(ids[0]);
        if (!el) return;
        if (p.key === 'receita_hoje' || p.key === 'ticket_medio') el.textContent = fmtMoney(p.value);
        else if (p.key === 'tacos') el.textContent = fmtNum(p.value, 1) + '%';
        else if (p.key === 'posicao_media') el.textContent = '#' + fmtNum(p.value, 1);
        else if (p.key === 'health_medio') el.textContent = fmtNum(p.value, 2);
        else if (p.key === 'tempo_medio_resposta_s') el.textContent = p.value + 's';
        else el.textContent = String(p.value);

        if (p.key === 'vendas_hoje') $('fSales').textContent = String(p.value);
        if (p.key === 'receita_hoje') $('pnl').textContent = '+ ' + fmtMoney(p.value);

        const flashCls = p.flash === 'yellow' ? 'flash-y' : (p.flash === 'green' ? 'flash-g' : null);
        if (flashCls) flash(ids[1], flashCls);
    }

    function handleEvent(ev) {
        if (!ev || !ev.type) return;
        switch (ev.type) {
            case 'index.tick':
                applyIndexTick(ev.payload && ev.payload.value);
                break;
            case 'index.candle':
                applyCandle(ev.payload);
                break;
            case 'metric.update':
                applyMetricUpdate(ev.payload || {});
                break;
            case 'op':
                pushOp(ev, true);
                break;
            case 'sale':
                // cadeia metric/op já vem do backend; só bump visual no candle
                applyIndexTick((cur.c || open0) + 3);
                break;
            case 'keyword.rank':
                if (ev.payload) {
                    // merge single rank into tape via re-fetch not needed — append visually
                    const existing = [];
                    // soft update: reload tape from current DOM is hard; keep simple append marker
                    const k = ev.payload;
                    const node = document.createElement('span');
                    node.innerHTML = `<b>${escapeHtml(k.kw)}</b> #${k.pos}`;
                    $('tape').appendChild(node);
                }
                break;
            case 'qa.status':
                applyQa(ev.payload || {});
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
        if (!el) return;
        el.className = 'conn ' + mode;
        el.textContent = mode === 'ws' ? 'WS' : mode === 'sse' ? 'SSE' : '●';
        el.title = mode;
    }

    /* ---------- SNAPSHOT ---------- */
    async function loadSnapshot() {
        const url = (boot.snapshotUrl || '/api/pregao/snapshot') + (accountId ? ('?account_id=' + accountId) : '');
        const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('snapshot HTTP ' + res.status);
        const body = await res.json();
        if (!body.success) throw new Error(body.error || 'snapshot fail');
        const d = body.data;

        candles = (d.candles || []).map((c) => ({
            o: +c.o, h: +c.h, l: +c.l, c: +c.c, date: c.date
        }));
        if (!candles.length) {
            const v = Number(d.index && d.index.value) || 1000;
            candles = [{ o: v, h: v, l: v, c: v, date: new Date().toISOString().slice(0, 10) }];
        }
        cur = { ...candles[candles.length - 1] };
        open0 = candles[0].o;
        updateHeader();
        draw();

        applyMetrics(d.metrics);
        applySemaforo(d.semaforo);
        renderTape(d.keywords);
        applyQa(d.qa);

        seenOps.clear();
        $('feed').innerHTML = '';
        const ops = (d.operations || []).slice().reverse();
        ops.forEach((ev) => pushOp(ev, true));
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
        transport = 'ws';
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
        transport = 'sse';
        setConn('sse');
        reconnectAttempt = 0;

        const types = ['index.tick', 'index.candle', 'metric.update', 'op', 'sale', 'keyword.rank', 'qa.status', 'account.semaforo'];
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
            $('semaText').textContent = 'FALHA NO SNAPSHOT';
            connectSse();
        });
})();
