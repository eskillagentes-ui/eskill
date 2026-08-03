#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const test = require('node:test');
const vm = require('vm');

const sourcePath = path.resolve(__dirname, '../../../public/js/pregao.js');
let source = fs.readFileSync(sourcePath, 'utf8');
const bootMarker = '    /* ---------- BOOT ---------- */';
const bootOffset = source.indexOf(bootMarker);
assert.ok(bootOffset > 0, 'bloco BOOT do Pregão deve existir');
source = source.slice(0, bootOffset)
    + "    window.__pregaoTest = { loadSnapshot: loadSnapshot, handleEvent: handleEvent, state: function () { return { candles: candles, cur: cur, open0: open0 }; } };\n})();\n";

const elements = new Map();
const context2d = new Proxy({}, {
    get(target, property) {
        if (!(property in target)) target[property] = function () {};
        return target[property];
    },
    set(target, property, value) {
        target[property] = value;
        return true;
    }
});
function element(id) {
    if (!elements.has(id)) {
        elements.set(id, {
            id,
            hidden: false,
            textContent: '',
            innerHTML: '',
            className: '',
            style: {},
            classList: { add() {}, remove() {}, toggle() {} },
            clientWidth: 600,
            clientHeight: 380,
            width: 600,
            height: 380,
            appendChild() {},
            setAttribute() {},
            getContext() { return context2d; }
        });
    }
    return elements.get(id);
}

let nextSnapshot = null;
function snapshotData(data) {
    return {
        metrics: null,
        sentinela: null,
        semaforo: null,
        ranks: [],
        rank_tracker_enabled: false,
        qa: null,
        operations: [],
        ...data
    };
}

const sandbox = {
    window: { PREGAO_BOOT: { snapshotUrl: '/snapshot' } },
    document: {
        documentElement: {},
        getElementById: element,
        createElement: () => element('created-' + elements.size)
    },
    matchMedia: () => ({ matches: false }),
    addEventListener() {},
    setInterval() { return 1; },
    setTimeout() { return 1; },
    clearTimeout() {},
    devicePixelRatio: 1,
    getComputedStyle: () => ({ getPropertyValue: () => 'monospace' }),
    fetch: async () => {
        assert.ok(nextSnapshot, 'snapshot do teste deve ser definido antes de loadSnapshot');
        const snapshot = nextSnapshot;
        nextSnapshot = null;
        return { ok: true, json: async () => snapshot };
    },
    location: { protocol: 'https:', host: 'example.test' },
    WebSocket: function () {},
    EventSource: function () {},
    console,
    Intl,
    Date,
    Number,
    Math,
    Set,
    Promise,
    JSON,
    encodeURIComponent
};
sandbox.globalThis = sandbox;
vm.runInNewContext(source, sandbox, { filename: sourcePath });

async function runSnapshot(data) {
    ['px', 'chg', 'fOpen', 'fHigh', 'fLow'].forEach((id) => {
        const el = element(id);
        el.textContent = '';
        el.className = '';
        el.style = {};
    });
    nextSnapshot = { success: true, data: snapshotData(data) };
    await sandbox.window.__pregaoTest.loadSnapshot();
    return {
        state: sandbox.window.__pregaoTest.state(),
        header: {
            price: element('px').textContent,
            change: element('chg').textContent,
            open: element('fOpen').textContent,
            high: element('fHigh').textContent,
            low: element('fLow').textContent
        }
    };
}

test('preserva zero legítimo na abertura e aplica índice live no fechamento', async () => {
    const currentZero = await runSnapshot({
        server_ts: '2026-08-03T12:00:00-03:00',
        index: { value: 1100, open: 0, factors_active: 4, factors_total: 5 },
        candles: [
            { date: '2026-08-01', o: 800, h: 900, l: 700, c: 850 },
            { date: '2026-08-03', o: 0, h: 0, l: 0, c: 0 }
        ]
    });
    const state = currentZero.state;

    assert.strictEqual(state.cur.date, '2026-08-03', 'candle atual deve ser o da data do snapshot');
    assert.strictEqual(state.cur.c, 1100, 'índice live prevalece sobre fechamento persistido stale');
    assert.strictEqual(state.open0, 0, 'abertura diária zero permanece legítima');
    assert.strictEqual(state.candles[0].c, 850, 'candle histórico não deve ser sobrescrito pelo índice live');
    assert.strictEqual(currentZero.header.change, 'n/d', 'divisor diário zero deve falhar fechado na UI');
    assert.strictEqual(currentZero.header.price, '1.100,00', 'header deve mostrar o índice live');
    assert.strictEqual(currentZero.header.high, '1100', 'máxima diária incorpora o live');
    assert.strictEqual(currentZero.header.low, '0', 'mínima diária preserva o low do candle');
});

test('índice live diverge do candle persistido (stale pós-Ft)', async () => {
    const live = await runSnapshot({
        server_ts: '2026-08-03T12:00:00-03:00',
        index: {
            value: 1087.82,
            open: 1000,
            change_pct: 8.78,
            high: 1087.82,
            low: 980,
            factors_active: 5,
            factors_total: 5
        },
        candles: [
            { date: '2026-08-03', o: 1000, h: 1010, l: 990, c: 1005 }
        ]
    });
    assert.strictEqual(live.state.cur.c, 1087.82);
    assert.strictEqual(live.state.candles[0].c, 1087.82);
    assert.ok(live.state.cur.h >= 1087.82);
    assert.strictEqual(live.header.change.includes('8,78') || live.header.change.includes('8.78'), true);
});

test('evento de candle histórico não troca o estado diário atual', async () => {
    const initial = await runSnapshot({
        server_ts: '2026-08-03T12:00:00-03:00',
        index: { value: 0, open: 0, factors_active: 4, factors_total: 5 },
        candles: [
            { date: '2026-08-01', o: 800, h: 900, l: 700, c: 850 },
            { date: '2026-08-03', o: 0, h: 0, l: 0, c: 0 }
        ]
    });
    const headerBefore = { ...initial.header };

    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.candle',
        payload: { date: '2026-08-01', o: 810, h: 920, l: 705, c: 875 }
    });

    const state = sandbox.window.__pregaoTest.state();
    const historical = state.candles.find((candle) => candle.date === '2026-08-01');
    assert.strictEqual(historical.c, 875, 'evento antigo deve atualizar o candle correspondente no histórico');
    assert.strictEqual(state.cur.date, '2026-08-03', 'evento antigo não deve trocar a data atual');
    assert.strictEqual(state.cur.c, 0, 'evento antigo não deve trocar o fechamento atual zero');
    assert.strictEqual(state.open0, 0, 'evento antigo não deve trocar a abertura atual zero');
    assert.deepStrictEqual(
        {
            price: element('px').textContent,
            change: element('chg').textContent,
            open: element('fOpen').textContent,
            high: element('fHigh').textContent,
            low: element('fLow').textContent
        },
        headerBefore,
        'evento antigo não deve alterar o header atual'
    );
});

test('mantém histórico desenhável sem usar candle antigo no header', async () => {
    const historyOnly = await runSnapshot({
        server_ts: '2026-08-03T12:00:00-03:00',
        index: { value: null, open: null, change_pct: null },
        candles: [{ date: '2026-08-01', o: 800, h: 900, l: 700, c: 850 }]
    });
    assert.strictEqual(historyOnly.state.candles.length, 1, 'histórico deve continuar disponível para desenhar o gráfico');
    assert.strictEqual(historyOnly.state.candles[0].c, 850, 'histórico não deve ser alterado sem candle atual');
    assert.deepStrictEqual(
        historyOnly.header,
        { price: 'n/d', change: 'n/d', open: 'n/d', high: 'n/d', low: 'n/d' },
        'header não deve usar o último candle antigo como estado atual'
    );
});

test('exibe preço live sem inventar OHLC ou variação diária', async () => {
    const liveWithoutDailyOpen = await runSnapshot({
        server_ts: '2026-08-03T12:00:00-03:00',
        index: { value: 1100, open: null, change_pct: null },
        candles: [{ date: '2026-08-01', o: 800, h: 900, l: 700, c: 850 }]
    });
    assert.strictEqual(liveWithoutDailyOpen.state.cur.c, 1100, 'preço live deve permanecer visível');
    assert.strictEqual(liveWithoutDailyOpen.state.candles.length, 1, 'preço live sem OHLC diário não deve criar candle sintético');
    assert.strictEqual(liveWithoutDailyOpen.state.candles[0].c, 850, 'preço live não deve sobrescrever o histórico');
    assert.strictEqual(liveWithoutDailyOpen.header.price, '1.100,00', 'header deve exibir o preço live');
    assert.strictEqual(liveWithoutDailyOpen.header.open, 'n/d', 'abertura diária ausente deve falhar fechada');
    assert.strictEqual(liveWithoutDailyOpen.header.change, 'n/d', 'variação diária ausente não deve inventar 0%');
    assert.strictEqual(liveWithoutDailyOpen.header.high, 'n/d', 'máxima diária ausente deve falhar fechada');
    assert.strictEqual(liveWithoutDailyOpen.header.low, 'n/d', 'mínima diária ausente deve falhar fechada');
});

test('tick live sem OHLC diário atualiza só o preço exibido', async () => {
    await runSnapshot({
        server_ts: '2026-08-03T12:00:00-03:00',
        index: { value: 1100, open: null, change_pct: null },
        candles: [{ date: '2026-08-01', o: 800, h: 900, l: 700, c: 850 }]
    });

    sandbox.window.__pregaoTest.handleEvent({ type: 'index.tick', payload: { value: 1200 } });

    const state = sandbox.window.__pregaoTest.state();
    assert.strictEqual(state.cur.c, 1200, 'tick deve atualizar o preço live');
    assert.strictEqual(state.candles.length, 1, 'tick sem OHLC diário não deve criar candle');
    assert.strictEqual(state.candles[0].c, 850, 'tick sem candle atual não deve sobrescrever o último histórico');
    assert.strictEqual(state.cur.o, null, 'tick não deve inventar abertura diária');
    assert.strictEqual(state.cur.h, null, 'tick não deve inventar máxima diária');
    assert.strictEqual(state.cur.l, null, 'tick não deve inventar mínima diária');
    assert.deepStrictEqual(
        {
            price: element('px').textContent,
            change: element('chg').textContent,
            open: element('fOpen').textContent,
            high: element('fHigh').textContent,
            low: element('fLow').textContent
        },
        { price: '1.200,00', change: 'n/d', open: 'n/d', high: 'n/d', low: 'n/d' }
    );
});

test('tick atualiza o candle da data atual, não o último por posição', async () => {
    await runSnapshot({
        server_ts: '2026-08-03T12:00:00-03:00',
        index: { value: 910, open: null, change_pct: null },
        candles: [
            { date: '2026-08-03', o: 900, h: 925, l: 875, c: 910 },
            { date: '2026-08-01', o: 800, h: 900, l: 700, c: 850 }
        ]
    });

    sandbox.window.__pregaoTest.handleEvent({ type: 'index.tick', payload: { value: 950 } });

    const state = sandbox.window.__pregaoTest.state();
    const current = state.candles.find((candle) => candle.date === '2026-08-03');
    const historical = state.candles.find((candle) => candle.date === '2026-08-01');
    assert.strictEqual(current.c, 950, 'tick deve atualizar o fechamento do candle atual por data');
    assert.strictEqual(current.h, 950, 'tick deve atualizar a máxima do candle atual');
    assert.strictEqual(current.l, 875, 'tick deve preservar a mínima do candle atual');
    assert.strictEqual(historical.c, 850, 'tick não deve sobrescrever o último candle apenas por posição');
    assert.strictEqual(state.cur.date, '2026-08-03', 'estado atual deve continuar associado à data do snapshot');
});

test('prefere abertura e variação diárias válidas informadas pelo índice', async () => {
    const liveWithDailyFields = await runSnapshot({
        server_ts: '2026-08-03T12:00:00-03:00',
        index: { value: 1100, open: 1000, change_pct: 7.5 },
        candles: [{ date: '2026-08-01', o: 800, h: 900, l: 700, c: 850 }]
    });
    assert.strictEqual(liveWithDailyFields.state.candles.length, 1, 'campos diários do índice não devem criar candle sintético');
    assert.strictEqual(liveWithDailyFields.header.price, '1.100,00');
    assert.strictEqual(liveWithDailyFields.header.open, '1000');
    assert.strictEqual(liveWithDailyFields.header.change, '▲ +7,50%');
    assert.strictEqual(liveWithDailyFields.header.high, 'n/d');
    assert.strictEqual(liveWithDailyFields.header.low, 'n/d');
});

test('mantém o cache-busting do cliente corrigido', () => {
    const view = fs.readFileSync(path.resolve(__dirname, '../../../app/Views/dashboard/pregao.php'), 'utf8');
    assert.match(view, /\/js\/pregao\.js\?v=10/, 'view deve invalidar o cache do cliente corrigido');
});
