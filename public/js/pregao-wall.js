(function (root, factory) {
    'use strict';

    const api = factory();
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.PregaoWall = api;
    if (root && root.document) {
        const start = () => api.init(root);
        if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', start, { once: true });
        else start();
    }
}(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';

    const shifts = [
        { x: -3, y: -2 },
        { x: 2, y: -3 },
        { x: 3, y: 2 },
        { x: -2, y: 3 }
    ];

    function burnInOffset(index) {
        const normalized = Math.max(0, Math.floor(Number(index) || 0));
        return shifts[normalized % shifts.length];
    }

    function isWallRequested(search) {
        try {
            return new URLSearchParams(String(search || '')).get('wall') === '1';
        } catch (_) {
            return false;
        }
    }

    function compactAgents(value) {
        const first = String(value || '').split('·')[0].trim();
        return first || '0/5 reportando';
    }

    function deriveState(input) {
        const semaClass = String(input && input.semaClass || '');
        const semaText = String(input && input.semaText || '').toUpperCase();
        const agentsClass = String(input && input.agentsClass || '');
        const qaText = String(input && input.qaText || '').toUpperCase();

        if (semaClass.includes('vermelho') || qaText.includes('FAILED')) return 'critical';
        if (/FALHA|INDISPON|CONECTANDO|N\/D/.test(semaText)) return 'unknown';
        if (semaClass.includes('amarelo') || agentsClass.includes('is-attention') || qaText.includes('BLOCKED')) return 'warning';
        if (semaClass.includes('verde') && agentsClass.includes('is-healthy')) return 'healthy';
        return 'unknown';
    }

    function init(win) {
        const doc = win.document;
        const root = doc.getElementById('pregao-root');
        const anchor = root && root.querySelector('.wall-anchor');
        const toggle = doc.getElementById('wallModeToggle');
        const exit = doc.getElementById('wallModeExit');
        if (!root || !anchor || !toggle || !exit) return null;

        const byId = (id) => doc.getElementById(id);
        const text = (id, fallback) => {
            const element = byId(id);
            const value = element ? String(element.textContent || '').trim() : '';
            return value || fallback;
        };
        let active = false;
        let shiftIndex = 0;

        function sync() {
            const sema = byId('sema');
            const agents = byId('agentsSummary');
            const qa = byId('qaLive');
            const state = deriveState({
                semaClass: sema ? sema.className : '',
                semaText: text('semaText', ''),
                agentsClass: agents ? agents.className : '',
                qaText: qa ? qa.textContent : ''
            });
            const labels = {
                healthy: 'OPERAÇÃO SAUDÁVEL',
                warning: 'ATENÇÃO NECESSÁRIA',
                critical: 'AÇÃO IMEDIATA',
                unknown: 'AGUARDANDO DADOS'
            };

            anchor.dataset.state = state;
            byId('wallState').textContent = labels[state];
            byId('wallHealthDetail').textContent = text('semaText', 'Saúde da conta indisponível');
            byId('wallIndex').textContent = text('px', '—');
            byId('wallAgents').textContent = compactAgents(text('agentsSummary', ''));
            byId('wallQa').textContent = text('qaLive', 'NÃO EXECUTADO');
            byId('wallFreshness').textContent = text('sourceFreshness', 'AGUARDANDO');
            byId('wallClock').textContent = text('clock', '--:--:--');
        }

        function applyNightMode() {
            const hour = new Date().getHours();
            root.classList.toggle('wall-night', active && (hour >= 22 || hour < 6));
        }

        function shiftPixels() {
            if (!active) return;
            const offset = burnInOffset(shiftIndex++);
            root.style.setProperty('--wall-shift-x', offset.x + 'px');
            root.style.setProperty('--wall-shift-y', offset.y + 'px');
        }

        function updateUrl(enabled) {
            const url = new URL(win.location.href);
            if (enabled) url.searchParams.set('wall', '1');
            else url.searchParams.delete('wall');
            win.history.replaceState(null, '', url.pathname + url.search + url.hash);
        }

        function setWallMode(enabled, userInitiated) {
            active = Boolean(enabled);
            doc.body.classList.toggle('pregao-wall-mode', active);
            root.classList.toggle('wall-mode', active);
            toggle.setAttribute('aria-pressed', active ? 'true' : 'false');
            updateUrl(active);
            applyNightMode();
            shiftPixels();
            sync();

            if (userInitiated && active && !doc.fullscreenElement && doc.documentElement.requestFullscreen) {
                doc.documentElement.requestFullscreen().catch(() => {});
            } else if (userInitiated && !active && doc.fullscreenElement && doc.exitFullscreen) {
                doc.exitFullscreen().catch(() => {});
            }
        }

        toggle.addEventListener('click', () => setWallMode(true, true));
        exit.addEventListener('click', () => setWallMode(false, true));

        const sources = ['sema', 'semaText', 'px', 'chg', 'agentsSummary', 'qaLive', 'sourceFreshness', 'clock']
            .map(byId)
            .filter(Boolean);
        if (typeof win.MutationObserver === 'function') {
            const observer = new win.MutationObserver(sync);
            sources.forEach((source) => observer.observe(source, {
                attributes: true,
                childList: true,
                characterData: true,
                subtree: true
            }));
        }

        win.setInterval(sync, 1000);
        win.setInterval(applyNightMode, 60000);
        win.setInterval(shiftPixels, 15 * 60000);
        setWallMode(isWallRequested(win.location.search), false);

        return { setWallMode, sync };
    }

    return { burnInOffset, compactAgents, deriveState, init, isWallRequested };
}));
