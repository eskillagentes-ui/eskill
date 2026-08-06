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

function projectedQa(overrides = {}) {
    return {
        elapsed_ms: 3000,
        executed: true,
        log: [],
        observed_at: '2026-08-05T12:00:03.000Z',
        result: 'passed',
        run_id: RUN_ID,
        running: false,
        sequence: 5,
        status: 'passed',
        step: 'console_http',
        stream_url: '/qa/live/' + RUN_ID,
        suite: 'pregao-live',
        test: 'console_http',
        trusted: true,
        video_url: null,
        ...overrides
    };
}

function signedQa(overrides = {}) {
    return {
        cursor: { x: 120, y: 432 },
        manifest_hash: 'a'.repeat(64),
        observed_at: '2026-08-05T12:00:03.000Z',
        result: 'running',
        run_id: RUN_ID,
        running: true,
        screenshot_url: '/qa/frame/' + RUN_ID,
        sequence: 2,
        signature: 'b'.repeat(64),
        started_at: '2026-08-05T12:00:00.000Z',
        step: 'snapshot',
        stream_url: '/qa/live/' + RUN_ID,
        suite: 'pregao-live',
        test: 'snapshot',
        video_url: null,
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

test('reload aceita projeção backend exata e preserva APROVADO FALHOU BLOQUEADO com mídia correta', () => {
    const api = loadApi();
    const labels = { passed: 'APROVADO', failed: 'FALHOU', blocked: 'BLOQUEADO' };

    for (const [result, label] of Object.entries(labels)) {
        const { document, element } = makeDocument();
        const ui = api.create({
            document,
            location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
            boot: {},
            fetch: async () => null
        });
        assert.strictEqual(ui.applyQa(projectedQa({ result, status: result })), true);
        assert.strictEqual(element('qaStatus').textContent, label);
        assert.strictEqual(element('qaLive').textContent, label);
        assert.strictEqual(element('qaStream').hidden, false);
        assert.strictEqual(element('qaStream').getAttribute('src'), '/qa/live/' + RUN_ID);
    }
});

test('projeção backend rejeita campos ausentes ou extras e não sintetiza mídia ausente', () => {
    const api = loadApi();
    const missing = projectedQa();
    delete missing.observed_at;

    for (const payload of [missing, { ...projectedQa(), unexpected: true }]) {
        const fixture = makeDocument();
        const ui = api.create({
            document: fixture.document,
            location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
            boot: {},
            fetch: async () => null
        });
        assert.strictEqual(ui.applyQa(payload), false);
        assert.strictEqual(fixture.element('qaStream').hidden, true);
    }

    const { document, element } = makeDocument();
    const withoutMedia = api.create({
        document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: {},
        fetch: async () => null
    });
    assert.strictEqual(withoutMedia.applyQa(projectedQa({ stream_url: null })), true);
    assert.strictEqual(element('qaStream').hidden, true);
    assert.strictEqual(element('qaStream').getAttribute('src'), null);
});

test('applyQa normaliza evento assinado realtime e mostra blocked sem inventar aprovação', () => {
    const api = loadApi();
    const { document, element } = makeDocument();
    const ui = api.create({
        document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: { qaRunUrl: '/api/pregao/qa/run', csrfToken: 'csrf' },
        fetch: async () => { throw new Error('não esperado'); }
    });

    assert.strictEqual(ui.applyQa(signedQa()), true);
    assert.strictEqual(element('qaStatus').textContent, 'EM EXECUÇÃO');
    assert.strictEqual(element('qaElapsed').textContent, '3,0 s');
    assert.strictEqual(ui.applyQa(signedQa({
        result: 'blocked', running: false, step: 'console_http', test: 'console_http', sequence: 5
    })), true);
    assert.strictEqual(element('qaStatus').textContent, 'BLOQUEADO');
    assert.match(element('qaFeedback').textContent, /bloqueado/i);
    assert.strictEqual(ui.applyQa(signedQa({ signature: '0'.repeat(63) })), false);
});

test('evento HMAC exato com stream ausente não tem contrato afrouxado nem mídia sintetizada', () => {
    const api = loadApi();
    const { document, element } = makeDocument();
    const ui = api.create({
        document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: {},
        fetch: async () => null
    });

    assert.strictEqual(ui.applyQa(signedQa({ stream_url: null, screenshot_url: null })), true);
    assert.strictEqual(element('qaStream').hidden, true);
    assert.strictEqual(element('qaStream').getAttribute('src'), null);
    assert.strictEqual(ui.applyQa({ ...signedQa(), extra: 'rejeitar' }), false);
    assert.strictEqual(ui.applyQa(signedQa({ cursor: { x: 120 } })), false);
    assert.strictEqual(ui.applyQa(signedQa({ cursor: { x: -1, y: 432 } })), false);
    assert.strictEqual(api.isTrustedQaPayload(signedQa({ cursor: null })), true);
});

test('snapshot sem execução confiável permanece NÃO EXECUTADO', () => {
    const api = loadApi();
    const { document, element } = makeDocument();
    const ui = api.create({
        document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: {},
        fetch: async () => null
    });
    assert.strictEqual(ui.applyQa({
        executed: false, running: false, suite: null, test: null, result: null, video_url: null,
        stream_url: null, run_id: null, sequence: null, step: null, observed_at: null, log: []
    }), true);
    assert.strictEqual(element('qaStatus').textContent, 'NÃO EXECUTADO');
    assert.strictEqual(element('qaStream').hidden, true);
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

test('evento assinado respeita janela temporal e coerência de started_at com relógio injetado', () => {
    const api = loadApi();
    const { document } = makeDocument();
    const ui = api.create({
        document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: {},
        fetch: async () => null,
        now: () => Date.parse('2026-08-05T12:00:00.000Z')
    });

    assert.strictEqual(ui.applyQa(signedQa({
        observed_at: '2026-08-04T12:00:00.000Z',
        started_at: '2026-08-04T11:59:59.000Z'
    })), true, 'limite de 24h é aceito');

    const cases = [
        {
            observed_at: '2026-08-04T11:59:59.999Z',
            started_at: '2026-08-04T11:59:59.000Z'
        },
        {
            observed_at: '2026-08-05T12:01:00.001Z',
            started_at: '2026-08-05T12:00:59.000Z'
        },
        {
            observed_at: '2026-08-05T12:00:01.000Z',
            started_at: '2026-08-05T12:00:02.000Z'
        },
        {
            observed_at: '2026-08-05T12:00:01.000Z',
            started_at: '2026-07-29T12:00:00.999Z'
        },
        {
            observed_at: new Date('2026-08-05T12:00:01.000Z'),
            started_at: '2026-08-05T12:00:00.000Z'
        },
        {
            observed_at: '2026-08-05T12:00:01.000Z',
            started_at: new Date('2026-08-05T12:00:00.000Z')
        }
    ];
    for (const payload of cases) {
        const isolated = api.create({
            document: makeDocument().document,
            location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
            boot: {},
            fetch: async () => null,
            now: () => Date.parse('2026-08-05T12:00:00.000Z')
        });
        assert.strictEqual(isolated.applyQa(signedQa(payload)), false);
    }
});

test('mesmo run rejeita sequence repetida ou menor e qualquer evento após terminal', () => {
    const api = loadApi();
    const { document, element } = makeDocument();
    const ui = api.create({
        document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: {},
        fetch: async () => null,
        now: () => Date.parse('2026-08-05T12:10:00.000Z')
    });

    assert.strictEqual(ui.applyQa(signedQa({ sequence: 2 })), true);
    assert.strictEqual(ui.applyQa(signedQa({
        sequence: 2,
        observed_at: '2026-08-05T12:00:04.000Z',
        result: 'failed',
        running: false
    })), false, 'replay da sequence é rejeitado');
    assert.strictEqual(ui.applyQa(signedQa({
        sequence: 1,
        observed_at: '2026-08-05T12:00:05.000Z'
    })), false, 'sequence inferior é rejeitada');
    assert.strictEqual(element('qaStatus').textContent, 'EM EXECUÇÃO');

    assert.strictEqual(ui.applyQa(signedQa({
        sequence: 3,
        observed_at: '2026-08-05T12:00:06.000Z',
        result: 'blocked',
        running: false
    })), true);
    assert.strictEqual(element('qaStatus').textContent, 'BLOQUEADO');
    assert.strictEqual(ui.applyQa(signedQa({
        sequence: 4,
        observed_at: '2026-08-05T12:00:07.000Z'
    })), false, 'terminal fecha o run');
    assert.strictEqual(element('qaStatus').textContent, 'BLOQUEADO', 'rejeição não apaga estado terminal confiável');
});

test('observed_at não regride no mesmo run nem ao trocar de run', () => {
    const api = loadApi();
    const { document, element } = makeDocument();
    const ui = api.create({
        document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: {},
        fetch: async () => null,
        now: () => Date.parse('2026-08-05T12:10:00.000Z')
    });
    const secondRun = '223e4567-e89b-42d3-a456-426614174001';

    assert.strictEqual(ui.applyQa(signedQa({
        sequence: 1,
        observed_at: '2026-08-05T12:05:00.000Z'
    })), true);
    assert.strictEqual(ui.applyQa(signedQa({
        sequence: 2,
        observed_at: '2026-08-05T12:04:59.999Z'
    })), false, 'sequence maior não autoriza regressão temporal no run');
    assert.strictEqual(ui.applyQa(signedQa({
        run_id: secondRun,
        sequence: 1,
        observed_at: '2026-08-05T12:04:59.999Z',
        stream_url: '/qa/live/' + secondRun,
        screenshot_url: '/qa/frame/' + secondRun
    })), false, 'run novo não pode ser mais antigo que o último status confiável');
    assert.strictEqual(element('qaStatus').textContent, 'EM EXECUÇÃO');
    assert.strictEqual(ui.applyQa(signedQa({
        run_id: secondRun,
        sequence: 1,
        observed_at: '2026-08-05T12:05:00.001Z',
        stream_url: '/qa/live/' + secondRun,
        screenshot_url: '/qa/frame/' + secondRun
    })), true);
    assert.strictEqual(element('qaStream').getAttribute('src'), '/qa/live/' + secondRun);
});

test('projeção sem sequence só antecede watermark assinado e nunca reabre run terminal', () => {
    const api = loadApi();
    const firstDocument = makeDocument();
    const beforeSigned = api.create({
        document: firstDocument.document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: {},
        fetch: async () => null,
        now: () => Date.parse('2026-08-05T12:10:00.000Z')
    });

    assert.strictEqual(beforeSigned.applyQa(trustedQa()), true, 'snapshot inicial é aceito');
    assert.strictEqual(beforeSigned.applyQa(signedQa({ sequence: 1 })), true, 'evento assinado pode suceder snapshot');
    assert.strictEqual(beforeSigned.applyQa(trustedQa({
        status: 'failed',
        result: 'failed'
    })), false, 'snapshot não substitui watermark assinado');
    assert.strictEqual(firstDocument.element('qaStatus').textContent, 'EM EXECUÇÃO');

    const terminalDocument = makeDocument();
    const terminalFirst = api.create({
        document: terminalDocument.document,
        location: { href: 'https://app.example.test/dashboard/pregao', origin: 'https://app.example.test' },
        boot: {},
        fetch: async () => null,
        now: () => Date.parse('2026-08-05T12:10:00.000Z')
    });
    assert.strictEqual(terminalFirst.applyQa(trustedQa({
        status: 'passed',
        result: 'passed'
    })), true);
    assert.strictEqual(terminalFirst.applyQa({
        executed: false, running: false, suite: null, test: null, result: null, video_url: null,
        stream_url: null, run_id: null, sequence: null, step: null, observed_at: null, log: []
    }), false, 'estado vazio não apaga status confiável');
    assert.strictEqual(terminalFirst.applyQa(trustedQa()), false, 'snapshot não regride terminal');
    assert.strictEqual(terminalFirst.applyQa(signedQa({ sequence: 1 })), false, 'nenhum evento reabre run terminal');
    assert.strictEqual(terminalDocument.element('qaStatus').textContent, 'APROVADO');
});
