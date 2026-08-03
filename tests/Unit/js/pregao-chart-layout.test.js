#!/usr/bin/env node
'use strict';

const assert = require('assert');
const path = require('path');
const {
    clamp,
    computeCandleLayout,
    candleCenterX
} = require(path.resolve(__dirname, '../../../public/js/pregao-chart-layout.js'));

const PLOT = 600;

function assertLayout(count, expectations) {
    const layout = computeCandleLayout(PLOT, count);
    assert.ok(layout.candleWidth <= 14, `count=${count}: candleWidth<=14 got ${layout.candleWidth}`);
    assert.ok(layout.candleWidth >= 2, `count=${count}: candleWidth>=2 got ${layout.candleWidth}`);
    if (count < 10) {
        assert.strictEqual(layout.slots, 10, `count=${count}: slots=10`);
        assert.ok(layout.offsetX > 0, `count=${count}: offsetX>0 (âncora direita)`);
        const lastX = candleCenterX(layout, count - 1);
        assert.ok(lastX > PLOT * 0.5, `count=${count}: último candle na metade direita (${lastX})`);
    } else {
        assert.strictEqual(layout.slots, count);
        assert.strictEqual(layout.offsetX, 0);
    }
    if (expectations) {
        for (const [k, v] of Object.entries(expectations)) {
            assert.ok(Math.abs(layout[k] - v) < 0.01, `${k}: expected ~${v} got ${layout[k]}`);
        }
    }
    return layout;
}

assert.strictEqual(clamp(100, 2, 14), 14);
assert.strictEqual(clamp(1, 2, 14), 2);
assert.strictEqual(clamp(7, 2, 14), 7);

const empty = computeCandleLayout(PLOT, 0);
assert.strictEqual(empty.candleWidth, 0);
assert.strictEqual(empty.slots, 0);

const snap1 = assertLayout(1);
const snap2 = assertLayout(2);
const snap10 = assertLayout(10);
const snap60 = assertLayout(60);

// Com 1 candle, largura não pode ocupar o gráfico inteiro
assert.ok(snap1.candleWidth <= 14);
assert.ok(snap1.candleWidth < PLOT * 0.2, '1 candle não ocupa o gráfico');

// Com 60, slot mais estreito que com 10; body = clamp(slot*0.55, 2, 14)
assert.ok(snap60.slotWidth < snap10.slotWidth);
assert.strictEqual(snap60.candleWidth, 5.5);

// Snapshot estrutural (regressão)
const snapshot = {
    1: { slots: snap1.slots, candleWidth: +snap1.candleWidth.toFixed(4), offsetX: +snap1.offsetX.toFixed(4) },
    2: { slots: snap2.slots, candleWidth: +snap2.candleWidth.toFixed(4), offsetX: +snap2.offsetX.toFixed(4) },
    10: { slots: snap10.slots, candleWidth: +snap10.candleWidth.toFixed(4), offsetX: +snap10.offsetX.toFixed(4) },
    60: { slots: snap60.slots, candleWidth: +snap60.candleWidth.toFixed(4), offsetX: +snap60.offsetX.toFixed(4) }
};

assert.deepStrictEqual(snapshot, {
    1: { slots: 10, candleWidth: 14, offsetX: 540 },
    2: { slots: 10, candleWidth: 14, offsetX: 480 },
    10: { slots: 10, candleWidth: 14, offsetX: 0 },
    60: { slots: 60, candleWidth: 5.5, offsetX: 0 }
});

console.log('pregao-chart-layout OK', JSON.stringify(snapshot));
