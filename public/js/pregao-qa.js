/**
 * Pregão QA UI — trigger local e projeção fail-closed do backend.
 */
/* eslint-env browser */
/* global window, document, fetch, location, URL */
(function (root) {
    'use strict';

    const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    const PAYLOAD_KEYS = ['elapsed_ms', 'result', 'run_id', 'status', 'step', 'trusted'];
    const SIGNED_KEYS = [
        'manifest_hash', 'observed_at', 'result', 'run_id', 'running', 'screenshot_url', 'sequence',
        'signature', 'started_at', 'step', 'stream_url', 'suite', 'test', 'video_url'
    ];
    const EMPTY_KEYS = [
        'executed', 'log', 'observed_at', 'result', 'run_id', 'running', 'sequence',
        'step', 'stream_url', 'suite', 'test', 'video_url'
    ];
    const RUNNING_STATUSES = new Set(['queued', 'running']);
    const STATUSES = new Set(['queued', 'running', 'passed', 'failed', 'blocked']);
    const STEPS = new Set(['dashboard', 'snapshot', 'realtime', 'event_explorer', 'console_http']);
    const STATUS_LABELS = {
        queued: 'NA FILA',
        running: 'EM EXECUÇÃO',
        passed: 'APROVADO',
        failed: 'FALHOU',
        blocked: 'BLOQUEADO'
    };
    const STEP_LABELS = {
        dashboard: 'dashboard /pregao',
        snapshot: 'snapshot real',
        realtime: 'WebSocket ou SSE',
        event_explorer: 'Explorador de Eventos',
        console_http: 'erros JS e HTTP'
    };

    function exactKeys(value, keys) {
        return JSON.stringify(Object.keys(value).sort()) === JSON.stringify(keys);
    }

    function validateProjection(value) {
        if (!value || typeof value !== 'object' || Array.isArray(value)
            || !exactKeys(value, PAYLOAD_KEYS)
            || value.trusted !== true
            || typeof value.run_id !== 'string' || !UUID_RE.test(value.run_id)
            || typeof value.status !== 'string' || !STATUSES.has(value.status)
            || !Number.isInteger(value.elapsed_ms) || value.elapsed_ms < 0 || value.elapsed_ms > 604800000) {
            return false;
        }
        if (value.step !== null && (typeof value.step !== 'string' || !STEPS.has(value.step))) return false;
        if (value.status === 'queued' && value.step !== null) return false;
        if (RUNNING_STATUSES.has(value.status) && value.result !== null) return false;
        if (value.status === 'passed' && value.result !== 'passed') return false;
        if (value.status === 'failed' && value.result !== 'failed') return false;
        if (value.status === 'blocked' && value.result !== 'blocked') return false;
        return true;
    }

    function normalizeQaPayload(value) {
        if (validateProjection(value)) return value;
        if (!value || typeof value !== 'object' || Array.isArray(value) || !exactKeys(value, SIGNED_KEYS)) return null;
        if (typeof value.run_id !== 'string' || !UUID_RE.test(value.run_id)
            || typeof value.step !== 'string' || !STEPS.has(value.step)
            || typeof value.result !== 'string' || !STATUSES.has(value.result) || value.result === 'queued'
            || value.running !== (value.result === 'running')
            || value.suite !== 'pregao-live' || value.test !== value.step || value.video_url !== null
            || !Number.isInteger(value.sequence) || value.sequence < 1
            || typeof value.signature !== 'string' || !/^[a-f0-9]{64}$/.test(value.signature)
            || typeof value.manifest_hash !== 'string' || !/^[a-f0-9]{64}$/.test(value.manifest_hash)) return null;
        const expectedLive = '/qa/live/' + value.run_id;
        const expectedFrame = '/qa/frame/' + value.run_id;
        if (![null, expectedLive].includes(value.stream_url) || ![null, expectedFrame].includes(value.screenshot_url)) return null;
        const started = Date.parse(value.started_at);
        const observed = Date.parse(value.observed_at);
        if (!Number.isFinite(started) || !Number.isFinite(observed) || observed < started) return null;
        return {
            trusted: true,
            run_id: value.run_id,
            status: value.result,
            step: value.step,
            elapsed_ms: Math.min(604800000, observed - started),
            result: value.result === 'running' ? null : value.result
        };
    }

    function isTrustedQaPayload(value) {
        return normalizeQaPayload(value) !== null;
    }

    function formatElapsed(milliseconds) {
        if (milliseconds < 1000) return milliseconds + ' ms';
        if (milliseconds < 60000) {
            return (milliseconds / 1000).toLocaleString('pt-BR', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            }) + ' s';
        }
        const minutes = Math.floor(milliseconds / 60000);
        const seconds = Math.floor((milliseconds % 60000) / 1000);
        return minutes + ' min ' + String(seconds).padStart(2, '0') + ' s';
    }

    function create(options) {
        const env = options || {};
        const doc = env.document;
        const boot = env.boot || {};
        const currentLocation = env.location;
        const request = env.fetch;
        const $ = (id) => doc.getElementById(id);
        let submitting = false;
        let initialized = false;

        function clearMedia() {
            const stream = $('qaStream');
            if (stream) {
                stream.hidden = true;
                stream.removeAttribute('src');
            }
        }

        function failClosed(message) {
            clearMedia();
            const button = $('qaRunButton');
            if (button) button.disabled = false;
            const live = $('qaLive');
            if (live) live.textContent = 'INDISPONÍVEL';
            const status = $('qaStatus');
            if (status) status.textContent = 'INDISPONÍVEL';
            const step = $('qaStep');
            if (step) step.textContent = 'não confiável';
            const elapsed = $('qaElapsed');
            if (elapsed) elapsed.textContent = '—';
            const feedback = $('qaFeedback');
            if (feedback) feedback.textContent = message;
            const log = $('qalog');
            if (log) log.textContent = '▶ payload não confiável rejeitado';
        }

        function applyQa(payload) {
            if (payload && typeof payload === 'object' && !Array.isArray(payload)
                && exactKeys(payload, EMPTY_KEYS) && payload.executed === false && payload.running === false
                && Array.isArray(payload.log) && payload.log.length === 0
                && ['observed_at', 'result', 'run_id', 'sequence', 'step', 'stream_url', 'suite', 'test', 'video_url']
                    .every((key) => payload[key] === null)) {
                clearMedia();
                const button = $('qaRunButton');
                if (button) button.disabled = false;
                const live = $('qaLive');
                if (live) live.textContent = 'NÃO EXECUTADO';
                const status = $('qaStatus');
                if (status) status.textContent = 'NÃO EXECUTADO';
                const step = $('qaStep');
                if (step) step.textContent = 'aguardando execução autorizada';
                const elapsed = $('qaElapsed');
                if (elapsed) elapsed.textContent = '—';
                const feedback = $('qaFeedback');
                if (feedback) feedback.textContent = 'Nenhuma execução QA confiável foi registrada.';
                return true;
            }
            const normalized = normalizeQaPayload(payload);
            if (normalized === null) {
                failClosed('Status QA não confiável rejeitado; nenhuma mídia foi aberta.');
                return false;
            }
            payload = normalized;

            const running = RUNNING_STATUSES.has(payload.status);
            const button = $('qaRunButton');
            if (button) button.disabled = running;
            const live = $('qaLive');
            if (live) live.textContent = running ? 'AO VIVO' : STATUS_LABELS[payload.status];
            const status = $('qaStatus');
            if (status) status.textContent = STATUS_LABELS[payload.status];
            const step = $('qaStep');
            if (step) step.textContent = payload.step ? STEP_LABELS[payload.step] : 'aguardando runner';
            const elapsed = $('qaElapsed');
            if (elapsed) elapsed.textContent = formatElapsed(payload.elapsed_ms);
            const feedback = $('qaFeedback');
            if (feedback) {
                feedback.textContent = payload.status === 'blocked'
                    ? 'QA bloqueado com segurança antes de concluir.'
                    : payload.status === 'failed'
                    ? 'QA terminou com falha real. Consulte a etapa indicada.'
                    : payload.status === 'passed'
                        ? 'QA read-only concluído sem erros observados.'
                        : 'Execução read-only em andamento.';
            }
            const log = $('qalog');
            if (log) log.textContent = '▶ ' + (payload.step ? STEP_LABELS[payload.step] : 'na fila') + ' · ' + STATUS_LABELS[payload.status];

            const stream = $('qaStream');
            const idle = $('qaIdle');
            const livePath = '/qa/live/' + payload.run_id;
            if (stream) {
                stream.hidden = false;
                if (stream.getAttribute('src') !== livePath) stream.setAttribute('src', livePath);
            }
            if (idle) idle.hidden = true;
            return true;
        }

        function trustedRunPath() {
            if (typeof boot.qaRunUrl !== 'string' || typeof boot.csrfToken !== 'string' || boot.csrfToken.length < 1) {
                return null;
            }
            let target;
            try {
                target = new URL(boot.qaRunUrl, currentLocation.href);
            } catch (error) {
                return null;
            }
            if (target.origin !== currentLocation.origin
                || target.pathname !== '/api/pregao/qa/run'
                || target.search !== '' || target.hash !== '') {
                return null;
            }
            return target.pathname;
        }

        async function trigger() {
            if (submitting) return false;
            const runPath = trustedRunPath();
            if (!runPath || typeof request !== 'function') {
                const status = $('qaStatus');
                if (status) status.textContent = 'FALHA SEGURA';
                const feedback = $('qaFeedback');
                if (feedback) feedback.textContent = 'QA não iniciado: configuração same-origin/CSRF inválida.';
                clearMedia();
                const button = $('qaRunButton');
                if (button) button.disabled = false;
                return false;
            }

            submitting = true;
            const button = $('qaRunButton');
            if (button) button.disabled = true;
            const status = $('qaStatus');
            if (status) status.textContent = 'SOLICITANDO';
            const feedback = $('qaFeedback');
            if (feedback) feedback.textContent = 'Solicitando execução local read-only…';

            try {
                const response = await request(runPath, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-Token': boot.csrfToken
                    }
                });
                if (!response || response.ok !== true) throw new Error('HTTP inválido');
                const body = await response.json();
                if (!body || body.success !== true || !applyQa(body.data)) throw new Error('payload inválido');
                return true;
            } catch (error) {
                clearMedia();
                if (button) button.disabled = false;
                if (status) status.textContent = 'FALHA SEGURA';
                if (feedback) feedback.textContent = 'QA não iniciado: resposta inválida ou indisponível.';
                const live = $('qaLive');
                if (live) live.textContent = 'INDISPONÍVEL';
                return false;
            } finally {
                submitting = false;
            }
        }

        function init() {
            if (initialized) return;
            initialized = true;
            const button = $('qaRunButton');
            if (button) button.addEventListener('click', trigger);
        }

        return { applyQa, init, trigger };
    }

    let defaultUi = null;
    const api = {
        create,
        isTrustedQaPayload,
        initDefault() {
            if (!defaultUi) {
                defaultUi = create({
                    document: root.document,
                    fetch: root.fetch.bind(root),
                    location: root.location,
                    boot: root.PREGAO_BOOT || {}
                });
            }
            defaultUi.init();
            return defaultUi;
        },
        applyQa(payload) {
            return api.initDefault().applyQa(payload);
        }
    };

    root.PregaoQaUi = api;
    if (root.document && root.fetch && root.location) api.initDefault();
})(window);
