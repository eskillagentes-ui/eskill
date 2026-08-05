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
    + "    let __testEventMs = Date.now(); window.__pregaoTest = { loadSnapshot: loadSnapshot, handleEvent: function (event) { __testEventMs += 1000; handleEvent({ v: 2, ts: new Date(__testEventMs).toISOString(), source: 'live', account_id: accountId, ...event }); }, refreshAgentFreshness: refreshAgentFreshness, resetState: function () { candles = []; currentDate = null; open0 = null; cur = { o: null, c: null, h: null, l: null }; indexWatermarkMs = null; candleWatermarks.clear(); }, state: function () { return { candles: candles, cur: cur, open0: open0, currentDate: currentDate, indexWatermarkMs: indexWatermarkMs }; } };\n})();\n";

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
            children: [],
            appendChild(child) { this.children.push(child); },
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
        agents: null,
        operations: [],
        ...data
    };
}

const sandbox = {
    window: { PREGAO_BOOT: { snapshotUrl: '/snapshot', accountId: 1335 } },
    document: {
        documentElement: {},
        getElementById: element,
        createElement: () => element('created-' + elements.size),
        createTextNode: (text) => ({ textContent: String(text) })
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

async function runSnapshot(data, preserveState, keepMissingWatermarks) {
    if (preserveState !== true) sandbox.window.__pregaoTest.resetState();
    ['px', 'chg', 'fOpen', 'fHigh', 'fLow'].forEach((id) => {
        const el = element(id);
        el.textContent = '';
        el.className = '';
        el.style = {};
    });
    const snapshotInput = keepMissingWatermarks === true ? data : {
        ...data,
        index: data.index ? {
            updated_at: Object.prototype.hasOwnProperty.call(data.index, 'updated_at')
                ? data.index.updated_at : data.server_ts,
            ...data.index
        } : data.index,
        candles: (data.candles || []).map((candle) => ({
            updated_at: Object.prototype.hasOwnProperty.call(candle, 'updated_at')
                ? candle.updated_at : data.server_ts,
            ...candle
        }))
    };
    nextSnapshot = { success: true, data: snapshotData(snapshotInput) };
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

test('tick live sem OHLC diário preserva histórico sem inventar candle atual', async () => {
    await runSnapshot({
        server_ts: '2026-08-03T12:00:00-03:00',
        index: { value: 1100, open: null, change_pct: null },
        candles: [{ date: '2026-08-01', o: 800, h: 900, l: 700, c: 850 }]
    });

    sandbox.window.__pregaoTest.handleEvent({ type: 'index.tick', payload: { value: 1200 } });

    const state = sandbox.window.__pregaoTest.state();
    assert.strictEqual(state.cur.c, 1200, 'tick deve atualizar o preço live');
    assert.strictEqual(state.candles.length, 1, 'tick não deve criar candle sem OHLC diário confiável');
    assert.strictEqual(state.candles[0].c, 850, 'tick não deve sobrescrever o histórico');
    assert.deepStrictEqual(
        { o: state.cur.o, h: state.cur.h, l: state.cur.l, c: state.cur.c },
        { o: null, h: null, l: null, c: 1200 },
        'tick deve atualizar somente o preço live'
    );
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

    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.tick',
        ts: '2026-08-03T12:05:00-03:00',
        payload: { value: 950 }
    });

    const state = sandbox.window.__pregaoTest.state();
    const current = state.candles.find((candle) => candle.date === '2026-08-03');
    const historical = state.candles.find((candle) => candle.date === '2026-08-01');
    assert.strictEqual(current.c, 950, 'tick deve atualizar o fechamento do candle atual por data');
    assert.strictEqual(current.h, 950, 'tick deve atualizar a máxima do candle atual');
    assert.strictEqual(current.l, 875, 'tick deve preservar a mínima do candle atual');
    assert.strictEqual(historical.c, 850, 'tick não deve sobrescrever o último candle histórico por posição');
    assert.strictEqual(state.cur.date, '2026-08-03', 'estado atual deve continuar associado à data do snapshot');

    for (const invalidValue of [null, '1200', true]) {
        sandbox.window.__pregaoTest.handleEvent({
            type: 'index.tick', payload: { value: invalidValue }
        });
    }
    const afterInvalidTicks = sandbox.window.__pregaoTest.state();
    assert.strictEqual(afterInvalidTicks.cur.c, 950, 'tick não numérico não pode sintetizar preço');
    assert.strictEqual(
        afterInvalidTicks.candles.find((candle) => candle.date === '2026-08-03').c,
        950,
        'tick não numérico não pode alterar candle'
    );
});

test('rollover diário avança monotonicamente sem corromper candle anterior', async () => {
    await runSnapshot({
        server_ts: '2026-08-03T23:59:00-03:00',
        index: { value: 110, open: 100, change_pct: 10 },
        candles: [{ date: '2026-08-03', o: 100, h: 115, l: 95, c: 110 }]
    });

    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.candle',
        ts: '2026-08-04T00:00:00-03:00',
        payload: { date: '2026-08-04', o: 200, h: 215, l: 195, c: 210 }
    });
    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.tick', ts: '2026-08-04T00:01:00-03:00', payload: { value: 220 }
    });
    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.candle', payload: { date: '2026-08-02', o: 50, h: 55, l: 45, c: 52 }
    });
    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.candle', payload: { date: '2026-08-06', o: null, h: null, l: null, c: null }
    });

    const state = sandbox.window.__pregaoTest.state();
    assert.strictEqual(state.currentDate, '2026-08-04');
    assert.strictEqual(state.candles.find((candle) => candle.date === '2026-08-03').c, 110);
    assert.strictEqual(state.candles.find((candle) => candle.date === '2026-08-04').c, 220);
    assert.strictEqual(state.candles.some((candle) => candle.date === '2026-08-06'), false);
    assert.deepStrictEqual(
        Array.from(state.candles, (candle) => candle.date),
        ['2026-08-02', '2026-08-03', '2026-08-04'],
        'candles atrasados devem permanecer ordenados por data'
    );
});

test('snapshot antigo após realtime não regride preço nem candle', async () => {
    const snapshot = {
        server_ts: '2026-08-04T12:00:00-03:00',
        index: { value: 100, open: 90, updated_at: '2026-08-04T12:00:00-03:00' },
        candles: [
            { date: '2026-08-04', o: 90, h: 105, l: 85, c: 100, updated_at: '2026-08-04T12:00:00-03:00' }
        ]
    };
    await runSnapshot(snapshot);
    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.tick',
        account_id: 9999,
        ts: '2026-08-04T12:04:00-03:00',
        payload: { value: 777 }
    });
    assert.strictEqual(sandbox.window.__pregaoTest.state().cur.c, 100, 'evento de outra conta deve ser ignorado');
    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.tick',
        ts: '2026-08-04T12:05:00-03:00',
        payload: { value: 120 }
    });
    const reconnected = await runSnapshot({
        ...snapshot,
        server_ts: '2026-08-04T12:10:00-03:00',
        index: { value: 100, open: 90 },
        candles: [{ date: '2026-08-04', o: 90, h: 105, l: 85, c: 100 }]
    }, true, true);

    assert.strictEqual(reconnected.state.cur.c, 120);
    assert.strictEqual(reconnected.state.candles[0].c, 120);
});

test('candle anterior ao último tick não regride fechamento atual', async () => {
    await runSnapshot({
        server_ts: '2026-08-04T12:00:00-03:00',
        index: { value: 100, open: 90, updated_at: '2026-08-04T12:00:00-03:00' },
        candles: []
    });
    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.tick',
        ts: '2026-08-04T12:05:00-03:00',
        payload: { value: 120 }
    });
    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.candle',
        ts: '2026-08-04T12:04:00-03:00',
        payload: { date: '2026-08-04', o: 90, h: 110, l: 85, c: 100 }
    });

    const state = sandbox.window.__pregaoTest.state();
    assert.strictEqual(state.cur.c, 120);
    assert.strictEqual(state.candles.find((candle) => candle.date === '2026-08-04').c, 120);
});

test('tick pós-meia-noite avança o dia antes de candle atrasado do rollover', async () => {
    await runSnapshot({
        server_ts: '2026-08-03T23:59:00-03:00',
        index: { value: 110, open: 100, updated_at: '2026-08-03T23:59:00-03:00' },
        candles: [{
            date: '2026-08-03', o: 100, h: 115, l: 95, c: 110,
            updated_at: '2026-08-03T23:59:00-03:00'
        }]
    });
    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.tick',
        ts: '2026-08-04T00:05:00-03:00',
        payload: { value: 120 }
    });
    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.candle',
        ts: '2026-08-04T00:04:00-03:00',
        payload: { date: '2026-08-04', o: 100, h: 115, l: 95, c: 100 }
    });

    const state = sandbox.window.__pregaoTest.state();
    assert.strictEqual(state.currentDate, '2026-08-04');
    assert.strictEqual(state.cur.c, 120);
    assert.strictEqual(state.candles.find((candle) => candle.date === '2026-08-03').c, 110);
    assert.strictEqual(state.candles.find((candle) => candle.date === '2026-08-04').c, 120);
});

test('snapshot reconectado não regride candle após tick do novo dia', async () => {
    await runSnapshot({
        server_ts: '2026-08-03T23:59:00-03:00',
        index: { value: 110, open: 100, updated_at: '2026-08-03T23:59:00-03:00' },
        candles: [{
            date: '2026-08-03', o: 100, h: 115, l: 95, c: 110,
            updated_at: '2026-08-03T23:59:00-03:00'
        }]
    });
    sandbox.window.__pregaoTest.handleEvent({
        type: 'index.tick',
        ts: '2026-08-04T00:05:00-03:00',
        payload: { value: 120 }
    });
    const reconnected = await runSnapshot({
        server_ts: '2026-08-04T00:04:00-03:00',
        index: { value: 100, open: 100, updated_at: '2026-08-04T00:04:00-03:00' },
        candles: [{
            date: '2026-08-04', o: 100, h: 115, l: 95, c: 100,
            updated_at: '2026-08-04T00:04:00-03:00'
        }]
    }, true);

    const candle = reconnected.state.candles.find((item) => item.date === '2026-08-04');
    assert.strictEqual(reconnected.state.cur.c, 120);
    assert.strictEqual(candle.c, 120);
    assert.strictEqual(candle.h, 120);
    assert.strictEqual(candle.l, 95);
});

test('primeiro snapshot sem watermarks próprios falha fechado', async () => {
    const rejected = await runSnapshot({
        server_ts: '2026-08-04T12:00:00-03:00',
        index: { value: 120, open: 100 },
        candles: [{ date: '2026-08-04', o: 100, h: 125, l: 95, c: 120 }]
    }, false, true);

    assert.strictEqual(rejected.state.cur.c, null);
    assert.strictEqual(rejected.state.candles.length, 0);
});

test('snapshot com watermarks futuros falha fechado para índice e candles', async () => {
    const rejected = await runSnapshot({
        server_ts: '2026-08-04T12:00:00-03:00',
        index: { value: 777, open: 700, updated_at: '2099-01-01T00:00:00-03:00' },
        candles: [{
            date: '2026-08-04', o: 700, h: 800, l: 650, c: 777,
            updated_at: '2099-01-01T00:00:00-03:00'
        }]
    });

    assert.strictEqual(rejected.state.cur.c, null);
    assert.strictEqual(rejected.state.candles.length, 0);
});

test('snapshot rejeita números coercíveis e keyword.rank malicioso', async () => {
    const tape = element('tape');
    tape.children = [];
    tape.innerHTML = '';
    const result = await runSnapshot({
        server_ts: '2026-08-04T12:00:00-03:00',
        index: { value: '1200', open: '90', change_pct: false, high: '9999', low: true },
        candles: [{ date: '2026-08-04', o: 90, h: 105, l: 85, c: 100 }],
        rank_tracker_enabled: true,
        ranks: [{ kw: 'bagageiro', pos: '<img src=x onerror=alert(1)>', delta: '<svg onload=alert(1)>' }]
    });
    assert.strictEqual(result.state.cur.c, 100);
    assert.strictEqual(result.state.cur.h, 105);
    assert.strictEqual(result.state.cur.l, 85);

    assert.doesNotMatch(tape.innerHTML, /<img|<svg/i, 'rank hostil do snapshot não deve alcançar HTML');
    const before = tape.children.length;
    sandbox.window.__pregaoTest.handleEvent({
        type: 'keyword.rank',
        payload: { kw: 'bagageiro', pos: '<img src=x onerror=alert(1)>', delta: 1 }
    });
    assert.strictEqual(tape.children.length, before, 'rank com posição não numérica deve ser rejeitado');
});

test('evento sale nunca altera preço ou candles', async () => {
    await runSnapshot({
        server_ts: '2026-08-04T12:00:00-03:00',
        index: { value: null, open: null, change_pct: null },
        candles: []
    });
    const before = sandbox.window.__pregaoTest.state();

    sandbox.window.__pregaoTest.handleEvent({ type: 'sale', payload: { order_id: 'safe-read-only' } });

    const after = sandbox.window.__pregaoTest.state();
    assert.deepStrictEqual(after.cur, before.cur);
    assert.deepStrictEqual(after.candles, before.candles);
    assert.strictEqual(element('px').textContent, 'n/d');
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

test('renderiza agentes 24/7 e atualiza status por evento realtime', async () => {
    await runSnapshot({
        server_ts: '2026-08-04T12:05:00-03:00',
        index: { value: null, open: null, change_pct: null },
        candles: [],
        agents: {
            summary: { overall: 'attention', total: 5, reporting: 2, healthy: 1, attention: 1, stale: 1 },
            items: [
                {
                    agent: 'sentinela', status: 'success', reason: 'legacy_read_complete', correlation_id: 'agent24x7-20260804T150000Z-aaaaaaaa:1335',
                    attempts: 1, state_changed: false, ml_write_automation: false,
                    updated_at: '2026-08-04T12:00:00-03:00', stale: false
                },
                {
                    agent: 'financeiro', status: 'failed', reason: 'financeiro_unavailable', correlation_id: 'agent24x7-20260804T144000Z-bbbbbbbb:1335',
                    attempts: 1, state_changed: false, ml_write_automation: false,
                    updated_at: '2026-08-04T11:40:00-03:00', stale: true
                }
            ]
        }
    });

    assert.strictEqual(element('agentStatus-sentinela').textContent, 'OK');
    assert.strictEqual(element('agentStatus-financeiro').textContent, 'ATRASADO');
    assert.match(element('agentsSummary').textContent, /2\/5 reportando/);
    assert.match(element('agentCard-financeiro').className, /is-attention/);

    const acceptedTs = new Date().toISOString();
    const olderTs = new Date(Date.parse(acceptedTs) - 60 * 1000).toISOString();
    const mutableTs = new Date(Date.parse(acceptedTs) + 30 * 1000).toISOString();
    const semanticTs = new Date(Date.parse(acceptedTs) + 45 * 1000).toISOString();
    sandbox.window.__pregaoTest.handleEvent({
        v: 2,
        type: 'agent.status',
        ts: acceptedTs,
        source: 'live',
        account_id: 1335,
        payload: {
            agent: 'financeiro', status: 'success', reason: 'legacy_read_complete', correlation_id: 'agent24x7-20260804T150530Z-cccccccc:1335',
            attempts: 1, state_changed: false, ml_write_automation: false
        }
    });

    assert.strictEqual(element('agentStatus-financeiro').textContent, 'OK');
    assert.doesNotMatch(element('agentCard-financeiro').className, /is-attention/);
    const acceptedAgentTime = element('agentTime-financeiro').textContent;

    sandbox.window.__pregaoTest.handleEvent({
        v: 2,
        type: 'agent.status',
        ts: olderTs,
        source: 'live',
        account_id: 1335,
        payload: {
            agent: 'financeiro', status: 'failed', reason: 'financeiro_unavailable', correlation_id: 'agent24x7-20260804T150400Z-dddddddd:1335',
            attempts: 1, state_changed: false, ml_write_automation: false
        }
    });
    assert.strictEqual(element('agentStatus-financeiro').textContent, 'OK', 'evento antigo não pode sobrescrever o novo');
    assert.strictEqual(element('agentReason-financeiro').textContent, 'legacy read complete');

    sandbox.window.__pregaoTest.handleEvent({
        v: 2,
        type: 'agent.status',
        ts: mutableTs,
        source: 'live',
        account_id: 1335,
        payload: {
            agent: 'financeiro', status: 'success', reason: 'legacy_read_complete',
            correlation_id: 'agent24x7-20260804T150600Z-eeeeeeee:1335', attempts: 1,
            state_changed: true, ml_write_automation: false
        }
    });
    assert.strictEqual(element('agentReason-financeiro').textContent, 'legacy read complete', 'evento mutante deve ser ignorado');
    assert.strictEqual(element('agentTime-financeiro').textContent, acceptedAgentTime);

    sandbox.window.__pregaoTest.handleEvent({
        v: 2,
        type: 'agent.status',
        ts: semanticTs,
        source: 'live',
        account_id: 1335,
        payload: {
            agent: 'financeiro', status: 'success', reason: 'read_only_violation',
            correlation_id: 'agent24x7-20260804T150630Z-ffffffff:1335', attempts: 1,
            state_changed: false, ml_write_automation: false
        }
    });
    assert.strictEqual(element('agentTime-financeiro').textContent, acceptedAgentTime, 'status/reason incoerentes devem ser ignorados');

    sandbox.window.__pregaoTest.handleEvent({
        v: 2,
        type: 'agent.status',
        ts: '2099-01-01T00:00:00Z',
        source: 'live',
        account_id: 1335,
        payload: {
            agent: 'financeiro', status: 'failed', reason: 'financeiro_unavailable',
            correlation_id: 'agent24x7-20990101T000000Z-12345678:1335', attempts: 1,
            state_changed: false, ml_write_automation: false
        }
    });
    assert.strictEqual(element('agentTime-financeiro').textContent, acceptedAgentTime, 'timestamp futuro deve ser ignorado');

    sandbox.window.__pregaoTest.handleEvent({
        v: 2,
        type: 'agent.status',
        ts: '2026-02-30T12:00:00Z',
        source: 'live',
        account_id: 1335,
        payload: {
            agent: 'collector', status: 'failed', reason: 'collector_unavailable',
            correlation_id: 'agent24x7-20260230T120000Z-abcdef12:1335', attempts: 1,
            state_changed: false, ml_write_automation: false
        }
    });
    assert.strictEqual(element('agentStatus-collector').textContent, 'AGUARDANDO', 'data impossível deve ser ignorada');

    const staleTs = new Date(Date.now() - 11 * 60 * 1000).toISOString();
    sandbox.window.__pregaoTest.handleEvent({
        v: 2,
        type: 'agent.status',
        ts: staleTs,
        source: 'live',
        account_id: 1335,
        payload: {
            agent: 'otimizador', status: 'failed', reason: 'invalid_optimizer_observation_snapshot',
            correlation_id: 'agent24x7-20260804T120000Z-87654321:1335', attempts: 1,
            state_changed: false, ml_write_automation: false
        }
    });
    assert.strictEqual(element('agentStatus-otimizador').textContent, 'ATRASADO');

    await runSnapshot({
        server_ts: '2026-08-04T12:07:00-03:00',
        index: { value: null, open: null, change_pct: null },
        candles: [],
        agents: {
            items: [{
                agent: 'financeiro', status: 'failed', reason: 'financeiro_unavailable',
                correlation_id: 'agent24x7-20260804T150400Z-dddddddd:1335', attempts: 1,
                state_changed: false, ml_write_automation: false,
                updated_at: olderTs, stale: false
            }]
        }
    });
    assert.strictEqual(element('agentStatus-financeiro').textContent, 'OK', 'snapshot antigo não pode regredir realtime novo');
    assert.strictEqual(element('agentTime-financeiro').textContent, acceptedAgentTime);

    sandbox.window.__pregaoTest.refreshAgentFreshness(Date.parse(acceptedTs) + 11 * 60 * 1000);
    assert.strictEqual(element('agentStatus-financeiro').textContent, 'ATRASADO');
    assert.match(element('agentCard-financeiro').className, /is-attention/);
});

test('view expõe os cinco cards de agentes 24/7', () => {
    const view = fs.readFileSync(path.resolve(__dirname, '../../../app/Views/dashboard/pregao.php'), 'utf8');
    for (const agent of ['sentinela', 'collector', 'financeiro', 'otimizador', 'orquestrador']) {
        assert.match(view, new RegExp('id="agentCard-' + agent + '"'));
        assert.match(view, new RegExp('id="agentStatus-' + agent + '"'));
    }
    assert.match(view, /id="agentsSummary"/);
});

test('roster parcial de agentes nunca aparece saudável', async () => {
    await runSnapshot({
        server_ts: '2026-08-04T12:05:00-03:00',
        index: { value: null, open: null, change_pct: null },
        candles: [],
        agents: {
            items: [{
                agent: 'sentinela', status: 'success', reason: 'legacy_read_complete',
                correlation_id: 'agent24x7-20260804T120000Z-0123abcd:1335', attempts: 1,
                state_changed: false, ml_write_automation: false,
                updated_at: '2026-08-04T12:00:00-03:00', stale: false
            }]
        }
    });

    assert.match(element('agentsSummary').className, /is-attention/);
    assert.match(element('agentsSummary').textContent, /[1-9][0-9]* atenção/);
});

test('cinco agentes com correlações diferentes nunca aparecem saudáveis', async () => {
    const agents = ['sentinela', 'collector', 'financeiro', 'otimizador', 'orquestrador'];
    await runSnapshot({
        server_ts: '2026-08-04T12:05:00-03:00',
        index: { value: null, open: null, change_pct: null },
        candles: [],
        agents: {
            items: agents.map((agent, index) => ({
                agent, status: 'success', reason: agent === 'orquestrador' ? 'aggregated' : 'legacy_read_complete',
                correlation_id: 'agent24x7-20260804T120000Z-' + (index === 0 ? 'aaaaaaaa' : 'bbbbbbbb') + ':1335',
                attempts: 1, state_changed: false, ml_write_automation: false,
                updated_at: '2026-08-04T12:00:00-03:00', stale: false
            }))
        }
    });

    assert.match(element('agentsSummary').className, /is-attention/);
});

test('mantém o cache-busting do cliente corrigido', () => {
    const view = fs.readFileSync(path.resolve(__dirname, '../../../app/Views/dashboard/pregao.php'), 'utf8');
    assert.match(source, /iconNode\.textContent = String\(icon\)/, 'ícone do feed deve usar textContent');
    assert.doesNotMatch(source, /el\.innerHTML = tp \+ tp/, 'fita de ranks não deve usar HTML dinâmico');
    assert.match(view, /\/js\/pregao\.js\?v=39/, 'view deve invalidar o cache do cliente corrigido');
});

test('fonte disponível sem observed_at mostra horário indisponível, nunca zero', async () => {
    const listEl = element('dataSources');
    const before = listEl.children.length;
    await runSnapshot({
        server_ts: '2026-08-04T12:00:00-03:00',
        index: { value: null, open: null, change_pct: null },
        candles: [],
        observability: {
            read_only: true,
            consolidated_at: '2026-08-04T12:00:00-03:00',
            age_seconds: 0,
            items: [
                {
                    key: 'sales', label: 'Vendas e receita', available: true,
                    source: 'ml_orders', observed_at: null, reason: null, count: null
                },
                {
                    key: 'health', label: 'Saúde dos anúncios', available: true,
                    source: 'account_health_history', observed_at: '2026-08-04T11:59:00-03:00',
                    reason: null, count: null
                }
            ]
        }
    });

    const cards = listEl.children.slice(before);
    assert.strictEqual(cards.length, 2, 'as duas fontes devem virar cards');
    const salesDetail = cards[0].children[1].textContent;
    assert.match(salesDetail, /horário indisponível/, 'timestamp ausente deve ser explícito');
    assert.doesNotMatch(salesDetail, /1969|1970|00\/00|31\/12/, 'timestamp ausente nunca vira época zero');
    const healthDetail = cards[1].children[1].textContent;
    assert.doesNotMatch(healthDetail, /horário indisponível/, 'timestamp real deve continuar sendo exibido');
    assert.match(healthDetail, /\d{2}\/\d{2},? \d{2}:\d{2}/, 'timestamp real deve aparecer formatado');
});

test('view expõe fontes, freshness e transporte read-only', () => {
    const view = fs.readFileSync(path.resolve(__dirname, '../../../app/Views/dashboard/pregao.php'), 'utf8');

    for (const id of ['sourcePanel', 'sourceFreshness', 'dataSources', 'sourceTransport', 'sourceLastEvent']) {
        assert.match(view, new RegExp('id="' + id + '"'));
    }
    assert.match(view, /SOMENTE LEITURA/);
    assert.match(source, /renderObservability\(d\.observability\)/);
    assert.doesNotMatch(source, /dataSources[\s\S]{0,200}innerHTML\s*=/);
});
