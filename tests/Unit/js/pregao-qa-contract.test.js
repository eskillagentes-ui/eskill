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
