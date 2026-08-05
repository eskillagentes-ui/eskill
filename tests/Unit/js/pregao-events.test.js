#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const test = require('node:test');

const viewPath = path.resolve(__dirname, '../../../app/Views/dashboard/pregao.php');
const clientPath = path.resolve(__dirname, '../../../public/js/pregao-events.js');
const view = fs.readFileSync(viewPath, 'utf8');
const client = fs.readFileSync(clientPath, 'utf8');

test('view integra o Event Explorer sem remover snapshot/operations', () => {
    for (const id of ['eventsPanel', 'eventsFilters', 'eventsList', 'evType', 'evSource', 'evFrom', 'evTo', 'evPrev', 'evNext']) {
        assert.match(view, new RegExp('id="' + id + '"'), 'view deve expor #' + id);
    }
    assert.match(view, /eventsUrl: '\/api\/pregao\/events'/, 'boot deve apontar o endpoint de eventos');
    assert.match(view, /\/js\/pregao-events\.js\?v=1/, 'view deve carregar o cliente do explorador');
    assert.match(view, /id="feed"/, 'fita de operações existente deve permanecer');
    assert.match(view, /snapshotUrl: '\/api\/pregao\/snapshot'/, 'snapshot existente deve permanecer');
});

test('cliente do Event Explorer é read-only e renderiza via textContent', () => {
    assert.doesNotMatch(client, /method\s*:/i, 'fetch sem method explícito (GET) — nenhuma escrita');
    assert.doesNotMatch(client, /innerHTML\s*=/, 'render deve usar textContent, nunca innerHTML');
    assert.match(client, /textContent = payloadSummary\(ev\.payload\)/);
    assert.match(client, /'horário indisponível'/, 'timestamp ausente deve virar horário indisponível');
    assert.match(client, /credentials: 'same-origin'/);
});
