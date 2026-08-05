/**
 * Pregão Event Explorer — histórico paginado read-only de pregao_events.
 * Consome somente GET /api/pregao/events; nenhuma escrita no ML.
 */
/* eslint-env browser */
/* global window, document, fetch, URLSearchParams */
(function () {
    'use strict';

    const boot = window.PREGAO_BOOT || {};
    const $ = (id) => document.getElementById(id);
    const accountId = Number(boot.accountId || 0);
    const eventsUrl = boot.eventsUrl || '/api/pregao/events';

    const list = $('eventsList');
    const total = $('eventsTotal');
    const pageInfo = $('evPageInfo');
    const prevBtn = $('evPrev');
    const nextBtn = $('evNext');
    const form = $('eventsFilters');
    if (!list || !form) return;

    const state = { page: 1, perPage: 25 };

    function payloadSummary(payload) {
        if (!payload || typeof payload !== 'object') return 'payload indisponível';
        const parts = [];
        Object.keys(payload).sort().forEach((key) => {
            const value = payload[key];
            if (value === null || value === undefined) return;
            parts.push(key + '=' + String(value));
        });
        if (!parts.length) return 'payload vazio';
        const text = parts.join(' · ');
        return text.length > 160 ? text.slice(0, 157) + '…' : text;
    }

    function renderEvents(events) {
        list.textContent = '';
        if (!Array.isArray(events) || !events.length) {
            const empty = document.createElement('li');
            empty.className = 'events-empty';
            empty.textContent = 'Nenhum evento para os filtros atuais';
            list.appendChild(empty);
            return;
        }
        events.forEach((ev) => {
            if (!ev || typeof ev !== 'object') return;
            const li = document.createElement('li');
            li.className = 'events-row';
            const head = document.createElement('div');
            head.className = 'events-row-head';
            const type = document.createElement('b');
            type.textContent = String(ev.type || '');
            const meta = document.createElement('span');
            const parsed = typeof ev.ts === 'string' ? new Date(ev.ts) : null;
            const when = parsed && !Number.isNaN(parsed.getTime())
                ? parsed.toLocaleString('pt-BR', {
                    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit'
                })
                : 'horário indisponível';
            meta.textContent = when + ' · ' + String(ev.source || 'live');
            head.appendChild(type);
            head.appendChild(meta);
            const detail = document.createElement('div');
            detail.className = 'events-row-detail';
            detail.textContent = payloadSummary(ev.payload);
            li.appendChild(head);
            li.appendChild(detail);
            list.appendChild(li);
        });
    }

    function renderPagination(pagination) {
        const page = pagination && Number.isInteger(pagination.page) ? pagination.page : 1;
        const pages = pagination && Number.isInteger(pagination.pages) ? pagination.pages : 0;
        const count = pagination && Number.isInteger(pagination.total) ? pagination.total : 0;
        if (total) total.textContent = count + ' evento' + (count === 1 ? '' : 's');
        if (pageInfo) pageInfo.textContent = pages > 0 ? ('página ' + page + ' de ' + pages) : '—';
        if (prevBtn) prevBtn.disabled = !(pagination && pagination.has_prev === true);
        if (nextBtn) nextBtn.disabled = !(pagination && pagination.has_next === true);
    }

    function renderError(message) {
        list.textContent = '';
        const li = document.createElement('li');
        li.className = 'events-empty';
        li.textContent = message;
        list.appendChild(li);
        if (total) total.textContent = 'indisponível';
        if (pageInfo) pageInfo.textContent = '—';
        if (prevBtn) prevBtn.disabled = true;
        if (nextBtn) nextBtn.disabled = true;
    }

    async function loadEvents() {
        const params = new URLSearchParams();
        if (accountId) params.set('account_id', String(accountId));
        params.set('page', String(state.page));
        params.set('per_page', String(state.perPage));
        const type = $('evType') ? $('evType').value : '';
        const source = $('evSource') ? $('evSource').value : '';
        const from = $('evFrom') ? $('evFrom').value : '';
        const to = $('evTo') ? $('evTo').value : '';
        if (type) params.set('type', type);
        if (source) params.set('source', source);
        if (from) params.set('from', from);
        if (to) params.set('to', to);

        try {
            const res = await fetch(eventsUrl + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            if (!res.ok) throw new Error('events HTTP ' + res.status);
            const body = await res.json();
            if (!body.success || !body.data) throw new Error(body.error || 'events fail');
            renderEvents(body.data.events);
            renderPagination(body.data.pagination);
        } catch (err) {
            renderError('Falha ao carregar eventos — tente novamente');
        }
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        state.page = 1;
        loadEvents();
    });
    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            if (state.page > 1) {
                state.page -= 1;
                loadEvents();
            }
        });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            state.page += 1;
            loadEvents();
        });
    }

    loadEvents();
})();
