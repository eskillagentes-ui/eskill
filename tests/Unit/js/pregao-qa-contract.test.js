#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const test = require('node:test');

const root = path.resolve(__dirname, '../../..');
const view = fs.readFileSync(path.join(root, 'app/Views/dashboard/pregao.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'public/css/pregao.css'), 'utf8');
const client = fs.readFileSync(path.join(root, 'public/js/pregao.js'), 'utf8');
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const wallClientPath = path.join(root, 'public/js/pregao-wall.js');

test('view contém trigger QA read-only, status etapa tempo e iframe live', () => {
    assert.match(view, /id="qaRunButton"[^>]*>\s*Executar QA read-only\s*</);
    for (const id of ['qaStatus', 'qaStep', 'qaElapsed', 'qaFeedback', 'qaStream']) {
        assert.match(view, new RegExp('id="' + id + '"'));
    }
    assert.match(view, /qaRunUrl:\s*['"]\/api\/pregao\/qa\/run['"]/);
    assert.match(view, /csrfToken:/);
    assert.match(view, /\/js\/pregao-qa\.js\?v=/);
    assert.doesNotMatch(view, /id="qaVideo"/, 'live usa iframe, não vídeo gravado');
});

test('cliente principal delega applyQa somente ao módulo sanitizador', () => {
    assert.match(client, /PregaoQaUi/);
    assert.match(client, /isTrustedQaPayload/);
    assert.doesNotMatch(client, /safeQaMediaPath|video_url|stream_url/);
});

test('CSS do card QA é responsivo e inclui feedback fail-closed', () => {
    for (const selector of ['.qa-controls', '.qa-run-button', '.qa-summary', '.qa-feedback']) {
        assert.match(css, new RegExp(selector.replace('.', '\\.') + '\\s*\\{'));
    }
    assert.match(css, /@media\s*\(max-width:\s*720px\)[\s\S]*\.qa-controls/);
});

test('npm test continua estritamente read-only e descobre os novos testes Node', () => {
    assert.strictEqual(pkg.scripts.test, 'npm run test:unit:js && npm run test:e2e:readonly');
    for (const file of ['pregao-qa-ui.test.js', 'pregao-qa-contract.test.js', 'pregao-qa-browser.test.mjs']) {
        assert.match(pkg.scripts['test:unit:js'], new RegExp(file.replace('.', '\\.')));
    }
    assert.doesNotMatch(pkg.scripts.test, /staging|mutation|qa-browser/);
});

test('modo parede expõe âncora operacional legível e acionamento explícito', () => {
    assert.match(view, /id="wallModeToggle"[^>]*aria-pressed="false"/);
    assert.match(view, /class="wall-anchor"[^>]*aria-live="polite"/);
    for (const id of ['wallState', 'wallIndex', 'wallAgents', 'wallQa', 'wallFreshness', 'wallClock']) {
        assert.match(view, new RegExp('id="' + id + '"'));
    }
    assert.match(view, /\/js\/pregao-wall\.js\?v=/);
});

test('modo parede remove chrome, amplia leitura e mitiga burn-in sem esconder estado desconhecido', () => {
    assert.match(css, /body\.pregao-wall-mode[\s\S]*\.sidebar[\s\S]*display:\s*none\s*!important/);
    assert.match(css, /#pregao-root\.wall-mode\s+\.wall-anchor\s*\{/);
    assert.match(css, /--wall-shift-x/);
    assert.match(css, /@media\s*\(prefers-reduced-motion:\s*reduce\)/);
    assert.ok(fs.existsSync(wallClientPath), 'cliente dedicado do modo parede deve existir');

    const wall = require(wallClientPath);
    assert.strictEqual(wall.deriveState({ semaClass: 'sema verde', agentsClass: 'agents-summary is-healthy' }), 'healthy');
    assert.strictEqual(wall.deriveState({ semaClass: 'sema vermelho', agentsClass: 'agents-summary is-healthy' }), 'critical');
    assert.strictEqual(wall.deriveState({ semaClass: 'sema verde', agentsClass: 'agents-summary is-attention' }), 'warning');
    assert.strictEqual(wall.deriveState({ semaClass: 'sema verde', semaText: 'FALHA NO SNAPSHOT', agentsClass: 'agents-summary is-healthy' }), 'unknown');
    assert.strictEqual(wall.deriveState({ semaClass: 'sema', agentsClass: 'agents-summary is-waiting' }), 'unknown');
    assert.strictEqual(wall.isWallRequested('?wall=1'), true);
    assert.strictEqual(wall.isWallRequested('?wall=0'), false);
    assert.deepStrictEqual(wall.burnInOffset(0), { x: -3, y: -2 });
    assert.deepStrictEqual(wall.burnInOffset(4), { x: -3, y: -2 });
});
