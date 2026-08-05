#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const test = require('node:test');
const vm = require('vm');

const sourcePath = path.resolve(__dirname, '../../../public/js/pregao-qa.js');
const source = fs.readFileSync(sourcePath, 'utf8');

function makeDocument() {
    const elements = new Map();
    function element(id) {
        if (!elements.has(id)) {
            const attributes = new Map();
            elements.set(id, {
                id,
                hidden: false,
                disabled: false,
                textContent: '',
                className: '',
                listeners: {},
                addEventListener(type, listener) { this.listeners[type] = listener; },
                setAttribute(name, value) { attributes.set(name, String(value)); },
                getAttribute(name) { return attributes.get(name) ?? null; },
                removeAttribute(name) { attributes.delete(name); }
            });
        }
        return elements.get(id);
    }
    return { document: { getElementById: element }, element };
}

function loadApi() {
    const sandbox = { window: {}, URL, console };
    vm.runInNewContext(source, sandbox, { filename: sourcePath });
    return sandbox.window.PregaoQaUi;
}

const RUN_ID = '123e4567-e89b-42d3-a456-426614174000';
function trustedQa(overrides = {}) {
    return {
        trusted: true,
        run_id: RUN_ID,
        status: 'running',
        step: 'snapshot',
        elapsed_ms: 1250,
        result: null,
        ...overrides
    };
}

test('trigger envia somente POST same-origin com CSRF e mantém botão bloqueado em queued', async () => {
    const api = loadApi();
    const { document, element } = makeDocument();
    const calls = [];
    const ui = api.create({
        document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: { qaRunUrl: '/api/pregao/qa/run', csrfToken: 'csrf-test-value' },
        fetch: async (url, options) => {
            calls.push({ url, options });
            return {
                ok: true,
                json: async () => ({ success: true, data: trustedQa({ status: 'queued', step: null, elapsed_ms: 0 }) })
            };
        }
    });

    await ui.trigger();

    assert.strictEqual(calls.length, 1);
    assert.strictEqual(calls[0].url, '/api/pregao/qa/run');
    assert.strictEqual(calls[0].options.method, 'POST');
    assert.strictEqual(calls[0].options.credentials, 'same-origin');
    assert.strictEqual(calls[0].options.headers['X-CSRF-Token'], 'csrf-test-value');
    assert.strictEqual(calls[0].options.body, undefined, 'trigger não envia body ou dados sensíveis');
    assert.strictEqual(element('qaRunButton').disabled, true);
    assert.strictEqual(element('qaStatus').textContent, 'NA FILA');
    assert.strictEqual(element('qaStep').textContent, 'aguardando runner');
});

test('applyQa aceita somente projeção trusted exata e deriva iframe de UUID', () => {
    const api = loadApi();
    const { document, element } = makeDocument();
    const ui = api.create({
        document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: { qaRunUrl: '/api/pregao/qa/run', csrfToken: 'csrf' },
        fetch: async () => { throw new Error('não esperado'); }
    });

    assert.strictEqual(ui.applyQa(trustedQa()), true);
    assert.strictEqual(element('qaStream').hidden, false);
    assert.strictEqual(element('qaStream').getAttribute('src'), '/qa/live/' + RUN_ID);
    assert.strictEqual(element('qaStatus').textContent, 'EM EXECUÇÃO');
    assert.strictEqual(element('qaStep').textContent, 'snapshot real');
    assert.strictEqual(element('qaElapsed').textContent, '1,3 s');
    assert.strictEqual(element('qaRunButton').disabled, true);

    assert.strictEqual(ui.applyQa({ ...trustedQa(), stream_url: 'https://evil.test/live' }), false);
    assert.strictEqual(element('qaStream').getAttribute('src'), null, 'payload com campo extra limpa mídia');
    assert.strictEqual(element('qaStream').hidden, true);
    assert.strictEqual(element('qaStatus').textContent, 'INDISPONÍVEL');
    assert.match(element('qaFeedback').textContent, /não confiável/i);

    assert.strictEqual(ui.applyQa({ ...trustedQa(), trusted: false }), false);
    assert.strictEqual(ui.applyQa({ ...trustedQa(), run_id: '../escape' }), false);
    assert.strictEqual(ui.applyQa({ ...trustedQa(), step: 'arbitrary-script' }), false);
});

test('trigger falha fechado para URL externa, CSRF ausente e resposta não trusted', async () => {
    const api = loadApi();
    for (const scenario of [
        { boot: { qaRunUrl: 'https://evil.test/api/pregao/qa/run', csrfToken: 'csrf' }, response: null },
        { boot: { qaRunUrl: '/api/pregao/qa/run', csrfToken: '' }, response: null },
        { boot: { qaRunUrl: '/api/pregao/qa/run', csrfToken: 'csrf' }, response: { success: true, data: { run_id: RUN_ID } } }
    ]) {
        const { document, element } = makeDocument();
        let requests = 0;
        const ui = api.create({
            document,
            location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
            boot: scenario.boot,
            fetch: async () => {
                requests += 1;
                return { ok: true, json: async () => scenario.response };
            }
        });
        await ui.trigger();
        if (scenario.response === null) assert.strictEqual(requests, 0, 'pré-condição inválida não pode gerar request');
        assert.strictEqual(element('qaRunButton').disabled, false);
        assert.strictEqual(element('qaStatus').textContent, 'FALHA SEGURA');
        assert.match(element('qaFeedback').textContent, /não iniciado/i);
        assert.strictEqual(element('qaStream').getAttribute('src'), null);
    }
});

test('init conecta o botão ao trigger sem permitir disparo concorrente', async () => {
    const api = loadApi();
    const { document, element } = makeDocument();
    let release;
    let requests = 0;
    const pending = new Promise((resolve) => { release = resolve; });
    const ui = api.create({
        document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: { qaRunUrl: '/api/pregao/qa/run', csrfToken: 'csrf' },
        fetch: async () => {
            requests += 1;
            await pending;
            return { ok: true, json: async () => ({ success: true, data: trustedQa({ status: 'queued', step: null, elapsed_ms: 0 }) }) };
        }
    });
    ui.init();
    assert.strictEqual(typeof element('qaRunButton').listeners.click, 'function');
    const first = element('qaRunButton').listeners.click();
    const second = ui.trigger();
    assert.strictEqual(requests, 1);
    release();
    await Promise.all([first, second]);
    assert.strictEqual(requests, 1);
});
