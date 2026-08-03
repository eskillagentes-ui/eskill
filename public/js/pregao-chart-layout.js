/**
 * Layout puro do gráfico de candles do Pregão (testável sem DOM).
 * Com poucos candles (<10), ancora à direita — grid vazio à esquerda.
 */
(function (root) {
    'use strict';

    function clamp(n, min, max) {
        return Math.max(min, Math.min(max, n));
    }

    /**
     * @param {number} plotWidth largura útil (sem padR)
     * @param {number} count quantidade de candles
     * @returns {{ slotWidth: number, candleWidth: number, offsetX: number, slots: number }}
     */
    function computeCandleLayout(plotWidth, count) {
        if (!(plotWidth > 0) || !(count > 0)) {
            return { slotWidth: 0, candleWidth: 0, offsetX: 0, slots: 0 };
        }
        const slots = count < 10 ? 10 : count;
        const slotWidth = plotWidth / slots;
        const candleWidth = clamp(slotWidth * 0.55, 2, 14);
        const offsetX = count < 10 ? (slots - count) * slotWidth : 0;
        return { slotWidth: slotWidth, candleWidth: candleWidth, offsetX: offsetX, slots: slots };
    }

    /**
     * Centro X do candle i (0-based) no plot.
     */
    function candleCenterX(layout, index) {
        return layout.offsetX + index * layout.slotWidth + layout.slotWidth / 2;
    }

    const api = { clamp: clamp, computeCandleLayout: computeCandleLayout, candleCenterX: candleCenterX };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    root.PregaoChartLayout = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
