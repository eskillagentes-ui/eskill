(function () {
    const STORAGE_KEY = 'seo_killer_chat_history_v1';
    const SUGGESTIONS_KEY = 'seo_killer_chat_suggested_actions_v1';
    const SUGGESTIONS_DISMISSED_KEY = 'seo_killer_chat_suggested_dismissed_v1';
    const SUGGESTIONS_STATS_KEY = 'seo_killer_chat_suggested_stats_v1';
    const SUGGESTIONS_TTL_MS = 6 * 60 * 60 * 1000; // 6h
    const SUGGESTIONS_STATS_TTL_MS = 30 * 24 * 60 * 60 * 1000; // 30 dias
    const SUGGESTIONS_RETRY_BASE_MS = 5000;
    const SUGGESTIONS_RETRY_MAX_MS = 60000;
    const SUGGESTIONS_RETRY_LIMIT = 3;
    const MAX_HISTORY = 120;

    const safeText = (v) => String(v ?? '');
    const escapeHtml = (str) => safeText(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const normalizeAction = (action) => {
        if (!action) return null;
        if (typeof action === 'string') {
            const label = safeText(action).trim();
            return label ? { label, reason: '', impact: null, category: 'general', item_id: '' } : null;
        }

        const label = safeText(action.label || action.action || action.title || '').trim();
        if (!label) return null;

        const impact = typeof action.impact === 'number'
            ? action.impact
            : (typeof action.score === 'number' ? action.score : null);

        return {
            label,
            reason: safeText(action.reason || action.why || ''),
            impact: impact !== null && !Number.isNaN(impact) ? impact : null,
            category: safeText(action.category || 'general'),
            item_id: safeText(action.item_id || action.itemId || action.id || '')
        };
    };

    const formatImpact = (impact) => {
        if (typeof impact !== 'number' || Number.isNaN(impact) || impact <= 0) return null;
        const pct = Math.round(Math.min(Math.max(impact, 0), 1) * 100);
        return `${pct}% impacto`;
    };

    const formatDateTime = (ts) => {
        if (!ts) return '';
        const d = new Date(ts);
        if (Number.isNaN(d.getTime())) return '';
        const now = new Date();
        const sameDay = d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
        if (sameDay) return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    };

    const nowTime = () => new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

    const notify = {
        info(msg) { window.SEOKiller?.showInfo ? window.SEOKiller.showInfo(msg) : console.log(msg); },
        success(msg) { window.SEOKiller?.showSuccess ? window.SEOKiller.showSuccess(msg) : console.log(msg); },
        error(msg) { window.SEOKiller?.showError ? window.SEOKiller.showError(msg) : console.error(msg); },
    };

    const apiFetch = async (url, options = {}) => {
        const res = await fetch(url, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                ...(options.headers || {})
            }
        });
        const data = await res.json().catch(() => null);
        if (!res.ok) {
            const err = data?.error || data?.message || `HTTP ${res.status}`;
            const e = new Error(err);
            e.status = res.status;
            throw e;
        }
        return data;
    };

    const formatMessageContent = (raw) => {
        let content = escapeHtml(raw);
        content = content.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        content = content.replace(/\*(.+?)\*/g, '<em>$1</em>');
        content = content.replace(/\n/g, '<br>');
        return content;
    };

    const Chatbot = {
        state: {
            isOpen: false,
            conversationId: null,
            messages: [],
            isTyping: false,
            initialized: false,
            suggestedActions: [],
            suggestedSavedAt: 0,
            suggestionsLoading: false,
            lastSuggestionsRefresh: 0,
            suggestionsError: false,
            suggestionsRetryCount: 0,
            suggestionsRetryTimer: null,
            dismissedActions: {},
            suggestionStats: {},
        },

        setSuggestedActions(actions = [], { savedAt = Date.now(), persist = true } = {}) {
            const arr = Array.isArray(actions) ? actions : [];
            const seen = new Set();
            const normalized = [];

            for (const entry of arr) {
                const item = normalizeAction(entry);
                if (!item || !item.label) continue;
                const key = item.label.toLowerCase();
                if (seen.has(key)) continue;
                seen.add(key);
                normalized.push(item);
                if (normalized.length >= 6) break;
            }

            const filtered = this.filterDismissedActions(normalized);
            const ranked = this.rankSuggestedActions(filtered);

            const now = Date.now();
            ranked.forEach((item) => {
                const label = safeText(item?.label || '').toLowerCase();
                if (!label) return;
                const current = this.state.suggestionStats?.[label] || {};
                this.state.suggestionStats[label] = {
                    ...current,
                    lastSeen: now,
                };
            });
            this.pruneSuggestionStats();
            this.persistSuggestionStats();

            this.state.suggestedActions = ranked;
            this.state.suggestedSavedAt = this.state.suggestedActions.length ? savedAt : 0;
            this.state.suggestionsError = false;

            if (persist) {
                if (this.state.suggestedActions.length) {
                    this.persistSuggestions();
                } else {
                    this.clearPersistedSuggestions();
                }
            }

            this.updateBadge(this.state.suggestedActions.length);

            if (this.state.isOpen && this.state.suggestedActions.length) {
                this.showSuggestedActions(this.state.suggestedActions);
            } else if (!this.state.suggestedActions.length) {
                this.showSuggestedActions([]);
            }
        },

        updateBadge(count = 0) {
            const { badge } = this.getEls();
            if (!badge) return;
            if (!count || this.state.isOpen) {
                badge.style.display = 'none';
                return;
            }
            badge.textContent = String(count);
            badge.style.display = 'block';
        },

        init() {
            if (this.state.initialized) return;
            this.state.initialized = true;
            this.bindDomEvents();
            this.restoreHistory();
            this.loadDismissedActions();
            this.loadSuggestionStats();
            // Restaurar sugestões previamente carregadas (persistidas em localStorage)
            const persistedSuggestions = this.restoreSuggestions();
            if (persistedSuggestions && Array.isArray(persistedSuggestions.actions) && persistedSuggestions.actions.length) {
                this.setSuggestedActions(persistedSuggestions.actions, {
                    savedAt: persistedSuggestions.savedAt || Date.now(),
                    persist: false,
                });
            }
            // Delay initial proactive suggestions to avoid burst limit
            setTimeout(() => {
                this.loadProactiveSuggestions();
                setInterval(() => this.loadProactiveSuggestions(), 5 * 60 * 1000);
            }, 800);
        },

        /**
         * CSP (nonce + strict-dynamic) bloqueia handlers onclick= inline.
         * Bind via addEventListener a partir do script com nonce.
         */
        bindDomEvents() {
            const widget = document.getElementById('aiChatbotWidget');
            if (!widget || widget.dataset.chatEventsBound === '1') return;
            widget.dataset.chatEventsBound = '1';

            widget.addEventListener('click', (event) => {
                const target = event.target.closest('[data-chat-action]');
                if (!target || !widget.contains(target)) return;

                const action = target.getAttribute('data-chat-action');

                if (action === 'toggle') {
                    event.preventDefault();
                    this.toggle();
                    return;
                }
                if (action === 'clear') {
                    event.preventDefault();
                    this.clearHistory();
                    return;
                }
                if (action === 'send') {
                    event.preventDefault();
                    this.sendMessage();
                    return;
                }
                if (action === 'quick') {
                    event.preventDefault();
                    const message = target.getAttribute('data-chat-message') || '';
                    if (message) this.sendQuickMessage(message);
                }
            });

            const input = document.getElementById('chatInput');
            if (input && input.dataset.chatKeyBound !== '1') {
                input.dataset.chatKeyBound = '1';
                input.addEventListener('keydown', (event) => {
                    this.handleKeyPress(event);
                });
            }
        },

        getEls() {
            return {
                widget: document.getElementById('aiChatbotWidget'),
                chatWindow: document.getElementById('chatWindow'),
                toggleBtn: document.getElementById('chatToggleBtn'),
                input: document.getElementById('chatInput'),
                sendBtn: document.getElementById('sendChatBtn'),
                messages: document.getElementById('chatMessages'),
                typing: document.getElementById('typingIndicator'),
                suggested: document.getElementById('suggestedActions'),
                badge: document.getElementById('chatNotificationBadge'),
            };
        },

        toggle() {
            const { chatWindow, toggleBtn, input, badge } = this.getEls();
            if (!chatWindow || !toggleBtn) return;
            this.state.isOpen = !this.state.isOpen;

            if (this.state.isOpen) {
                chatWindow.style.display = 'flex';
                toggleBtn.classList.add('active');
                if (badge) badge.style.display = 'none';
                const stale = !this.state.suggestedActions.length || (Date.now() - (this.state.suggestedSavedAt || 0) > SUGGESTIONS_TTL_MS / 2);
                if (stale) {
                    // Buscar sugestões frescas sem bloquear a UI
                    this.loadProactiveSuggestions();
                }
                if (this.state.suggestedActions.length) {
                    this.showSuggestedActions(this.state.suggestedActions);
                }
                if (input) setTimeout(() => input.focus(), 0);
            } else {
                chatWindow.style.display = 'none';
                toggleBtn.classList.remove('active');
                this.updateBadge(this.state.suggestedActions.length);
            }
        },

        handleKeyPress(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                this.sendMessage();
            }
        },

        showTyping() {
            const { typing, sendBtn, messages } = this.getEls();
            this.state.isTyping = true;
            if (typing) typing.style.display = 'flex';
            if (sendBtn) sendBtn.disabled = true;
            if (messages) messages.scrollTop = messages.scrollHeight;
        },

        hideTyping() {
            const { typing, sendBtn } = this.getEls();
            this.state.isTyping = false;
            if (typing) typing.style.display = 'none';
            if (sendBtn) sendBtn.disabled = false;
        },

        addMessage(type, content) {
            const { messages } = this.getEls();
            if (!messages) return;

            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${type}-message`;

            if (type === 'bot') {
                messageDiv.innerHTML = `
                    <div class="message-avatar"><i class="bi bi-robot"></i></div>
                    <div class="message-content">${formatMessageContent(content)}<small class="message-time">${nowTime()}</small></div>
                `;
            } else {
                messageDiv.innerHTML = `
                    <div class="message-content">${formatMessageContent(content)}<small class="message-time">${nowTime()}</small></div>
                `;
            }

            messages.appendChild(messageDiv);
            messages.scrollTop = messages.scrollHeight;

            this.state.messages.push({ type, content, timestamp: new Date().toISOString() });
            if (this.state.messages.length > MAX_HISTORY) {
                this.state.messages = this.state.messages.slice(this.state.messages.length - MAX_HISTORY);
            }
            this.persistHistory();
        },

        persistHistory() {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    conversationId: this.state.conversationId,
                    messages: this.state.messages
                }));
            } catch (e) { }
        },

        persistSuggestions() {
            try {
                localStorage.setItem(SUGGESTIONS_KEY, JSON.stringify({
                    actions: this.state.suggestedActions,
                    savedAt: this.state.suggestedSavedAt || Date.now(),
                }));
            } catch (e) { }
        },

        loadDismissedActions() {
            try {
                const raw = localStorage.getItem(SUGGESTIONS_DISMISSED_KEY);
                if (!raw) return;
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') {
                    this.state.dismissedActions = parsed;
                    this.pruneDismissedActions();
                }
            } catch (e) { }
        },

        loadSuggestionStats() {
            try {
                const raw = localStorage.getItem(SUGGESTIONS_STATS_KEY);
                if (!raw) return;
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') {
                    this.state.suggestionStats = parsed;
                    this.pruneSuggestionStats();
                }
            } catch (e) { }
        },

        persistSuggestionStats() {
            try {
                localStorage.setItem(SUGGESTIONS_STATS_KEY, JSON.stringify(this.state.suggestionStats));
            } catch (e) { }
        },

        pruneSuggestionStats() {
            const cutoff = Date.now() - SUGGESTIONS_STATS_TTL_MS;
            let changed = false;
            Object.entries(this.state.suggestionStats || {}).forEach(([key, value]) => {
                const lastSeen = typeof value?.lastSeen === 'number' ? value.lastSeen : 0;
                if (!lastSeen || lastSeen < cutoff) {
                    delete this.state.suggestionStats[key];
                    changed = true;
                }
            });
            if (changed) this.persistSuggestionStats();
        },

        recordSuggestionStat(label, type) {
            const key = safeText(label).toLowerCase().trim();
            if (!key) return;
            const now = Date.now();
            const current = this.state.suggestionStats[key] || {};
            const stats = {
                clicks: current.clicks || 0,
                dismisses: current.dismisses || 0,
                lastClicked: current.lastClicked || 0,
                lastDismissed: current.lastDismissed || 0,
                lastSeen: current.lastSeen || now,
            };

            stats.lastSeen = now;

            if (type === 'click') {
                stats.clicks += 1;
                stats.lastClicked = now;
            }

            if (type === 'dismiss') {
                stats.dismisses += 1;
                stats.lastDismissed = now;
            }

            this.state.suggestionStats[key] = stats;
            this.pruneSuggestionStats();
            this.persistSuggestionStats();
        },

        rankSuggestedActions(actions) {
            if (!Array.isArray(actions) || !actions.length) return [];
            const now = Date.now();
            let hasStats = false;
            const scored = actions.map((action, index) => {
                const label = safeText(action?.label || '').toLowerCase();
                const stats = label ? this.state.suggestionStats?.[label] : null;
                if (stats && ((stats.clicks || 0) > 0 || (stats.dismisses || 0) > 0)) {
                    hasStats = true;
                }
                const impact = typeof action?.impact === 'number' ? action.impact : 0;
                let score = impact;

                if (stats) {
                    const clicks = Math.min(stats.clicks || 0, 5);
                    const dismisses = Math.min(stats.dismisses || 0, 3);
                    score += clicks * 0.05;
                    score -= dismisses * 0.08;
                    if (stats.lastClicked && (now - stats.lastClicked) < 24 * 60 * 60 * 1000) {
                        score += 0.05;
                    }
                }

                return { action, score, index };
            });

            if (!hasStats) return actions;

            scored.sort((a, b) => {
                if (b.score !== a.score) return b.score - a.score;
                return a.index - b.index;
            });

            return scored.map((item) => item.action);
        },

        persistDismissedActions() {
            try {
                localStorage.setItem(SUGGESTIONS_DISMISSED_KEY, JSON.stringify(this.state.dismissedActions));
            } catch (e) { }
        },

        pruneDismissedActions() {
            const cutoff = Date.now() - SUGGESTIONS_TTL_MS;
            let changed = false;
            Object.entries(this.state.dismissedActions || {}).forEach(([key, value]) => {
                if (typeof value !== 'number' || value < cutoff) {
                    delete this.state.dismissedActions[key];
                    changed = true;
                }
            });
            if (changed) this.persistDismissedActions();
        },

        filterDismissedActions(actions) {
            if (!Array.isArray(actions) || !actions.length) return [];
            this.pruneDismissedActions();
            return actions.filter((item) => {
                const label = safeText(item?.label || '').toLowerCase();
                if (!label) return false;
                const dismissedAt = this.state.dismissedActions?.[label];
                if (!dismissedAt) return true;
                return (Date.now() - dismissedAt) > SUGGESTIONS_TTL_MS;
            });
        },

        dismissSuggestedAction(label) {
            const key = safeText(label).toLowerCase().trim();
            if (!key) return;
            this.recordSuggestionStat(label, 'dismiss');
            this.state.dismissedActions[key] = Date.now();
            this.pruneDismissedActions();
            this.persistDismissedActions();
        },

        clearPersistedSuggestions() {
            try { localStorage.removeItem(SUGGESTIONS_KEY); } catch (e) { }
        },

        restoreHistory() {
            const { messages } = this.getEls();
            if (!messages) return;

            try {
                const raw = localStorage.getItem(STORAGE_KEY);
                if (!raw) return;
                const parsed = JSON.parse(raw);
                const msgs = Array.isArray(parsed?.messages) ? parsed.messages : [];
                if (!msgs.length) return;

                messages.innerHTML = '';
                this.state.conversationId = parsed?.conversationId || null;
                this.state.messages = [];

                for (const m of msgs) {
                    const type = m?.type === 'user' ? 'user' : 'bot';
                    const content = safeText(m?.content || '');
                    this.addMessage(type, content);
                }
            } catch (e) { }
        },

        restoreSuggestions() {
            try {
                const raw = localStorage.getItem(SUGGESTIONS_KEY);
                if (!raw) return [];
                const parsed = JSON.parse(raw);
                const actionsRaw = Array.isArray(parsed?.actions) ? parsed.actions : [];
                const normalized = actionsRaw
                    .map((a) => normalizeAction(a))
                    .filter((a) => a && a.label);
                const savedAt = parsed?.savedAt || 0;
                if (!normalized.length) return [];
                if (Date.now() - savedAt > SUGGESTIONS_TTL_MS) return [];
                return { actions: normalized, savedAt };
            } catch (e) {
                return [];
            }
        },

        getContext() {
            const url = new URL(window.location.href);
            return {
                page: window.location.pathname,
                tab: url.searchParams.get('tab') || null,
                timestamp: new Date().toISOString(),
                feature: window.location.pathname.includes('seo-killer') ? 'seo-killer' : 'dashboard'
            };
        },

        async sendMessage() {
            const { input } = this.getEls();
            const message = input ? input.value.trim() : '';
            if (!message || this.state.isTyping) return;
            if (input) input.value = '';

            this.addMessage('user', message);
            this.showTyping();

            try {
                const response = await apiFetch('/api/ai/chat', {
                    method: 'POST',
                    body: JSON.stringify({
                        message,
                        context: this.getContext()
                    })
                });

                const data = response?.data;
                if (!data) throw new Error('Formato de resposta inválido');
                if (data.conversation_id) this.state.conversationId = data.conversation_id;
                this.hideTyping();
                this.addMessage('bot', safeText(data.message || ''));

                const suggested = Array.isArray(data.suggested_actions_meta)
                    ? data.suggested_actions_meta
                    : (Array.isArray(data.suggested_actions) ? data.suggested_actions : []);

                if (Array.isArray(suggested) && suggested.length) {
                    this.setSuggestedActions(suggested, { savedAt: Date.now() });
                }
            } catch (error) {
                this.hideTyping();
                if (error.status === 401) {
                    this.addMessage('bot', 'Sua conta não está autenticada para usar a IA. Selecione/ative uma conta conectada e tente novamente.');
                    return;
                }
                this.addMessage('bot', 'Desculpe, ocorreu um erro ao consultar a IA. Tente novamente em instantes.');
            } finally {
                this.persistHistory();
            }
        },

        sendQuickMessage(message) {
            const { input } = this.getEls();
            if (input) input.value = safeText(message);
            this.sendMessage();
        },

        showSuggestedActions(actions) {
            const { suggested } = this.getEls();
            if (!suggested) return;

            if (!Array.isArray(actions) || actions.length === 0) {
                suggested.innerHTML = '';
                suggested.style.display = 'none';
                return;
            }

            suggested.innerHTML = '';

            const header = document.createElement('div');
            header.className = 'suggested-actions-label d-flex align-items-center justify-content-between gap-2';
            const lastUpdated = this.state.suggestedSavedAt ? formatDateTime(this.state.suggestedSavedAt) : '';
            const stale = this.state.suggestedSavedAt && (Date.now() - this.state.suggestedSavedAt > SUGGESTIONS_TTL_MS / 2);
            header.innerHTML = `
                <div class="d-flex align-items-center gap-1">
                    <small class="text-muted"><i class="bi bi-lightbulb"></i> Ações sugeridas</small>
                    ${lastUpdated ? `<small class="text-muted" title="Última atualização: ${escapeHtml(lastUpdated)}">· Atualizado ${escapeHtml(lastUpdated)}</small>` : ''}
                    ${stale ? '<small class="text-warning">· Pode estar desatualizado</small>' : ''}
                    ${this.state.suggestionsError ? '<small class="text-danger">· Falha ao atualizar</small>' : ''}
                </div>
                <button type="button" class="btn btn-link btn-sm p-0" aria-label="Recarregar sugestões">
                    ${this.state.suggestionsLoading ? '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>' : '<i class="bi bi-arrow-repeat"></i>'}
                </button>
            `;

            const refreshBtn = header.querySelector('button');
            if (refreshBtn) {
                refreshBtn.disabled = this.state.suggestionsLoading;
                refreshBtn.addEventListener('click', () => {
                    const now = Date.now();
                    if (now - this.state.lastSuggestionsRefresh < 3000) return; // debounce 3s
                    this.state.lastSuggestionsRefresh = now;
                    this.loadProactiveSuggestions({ showSpinner: true });
                });
            }

            suggested.appendChild(header);

            if (this.state.suggestionsError && !this.state.suggestionsLoading) {
                const alert = document.createElement('div');
                alert.className = 'alert alert-warning py-2 px-3 mb-2';
                alert.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Não foi possível atualizar as sugestões agora.</span>
                        <button type="button" class="btn btn-link btn-sm p-0">Tentar novamente</button>
                    </div>
                `;
                const retryBtn = alert.querySelector('button');
                if (retryBtn) {
                    retryBtn.addEventListener('click', () => {
                        this.loadProactiveSuggestions({ showSpinner: true });
                    });
                }
                suggested.appendChild(alert);
            }

            const buttons = document.createElement('div');
            buttons.className = 'action-buttons';

            actions.slice(0, 6).forEach((action) => {
                const parsed = normalizeAction(action);
                if (!parsed) return;
                const impactLabel = formatImpact(parsed.impact);
                const reasonHtml = parsed.reason
                    ? `<div class="action-reason text-muted"><small>${escapeHtml(parsed.reason)}</small></div>`
                    : '';
                const impactHtml = impactLabel
                    ? `<div class="action-impact"><span class="badge bg-primary">${escapeHtml(impactLabel)}</span></div>`
                    : '';

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'action-btn';
                btn.innerHTML = `
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div class="action-content">
                            <div class="action-title"><i class="bi bi-play-circle"></i> ${escapeHtml(parsed.label)}</div>
                            ${reasonHtml}
                            ${impactHtml}
                        </div>
                        <span class="action-dismiss text-muted" role="button" tabindex="0" aria-label="Ocultar sugestão" title="Ocultar sugestão">
                            <i class="bi bi-x"></i>
                        </span>
                    </div>
                `;
                btn.addEventListener('click', () => {
                    this.executeSuggestedAction(parsed);
                    suggested.style.display = 'none';
                });
                const dismissBtn = btn.querySelector('.action-dismiss');
                if (dismissBtn) {
                    const dismiss = (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        this.dismissSuggestedAction(parsed.label);
                        const filtered = this.state.suggestedActions.filter((item) => {
                            const norm = normalizeAction(item);
                            return norm?.label !== parsed.label;
                        });
                        this.setSuggestedActions(filtered, { savedAt: Date.now() });
                    };
                    dismissBtn.addEventListener('click', dismiss);
                    dismissBtn.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            dismiss(event);
                        }
                    });
                }
                buttons.appendChild(btn);
            });

            suggested.appendChild(buttons);
            suggested.style.display = 'block';

            setTimeout(() => {
                suggested.style.display = 'none';
            }, 10000);
        },

        executeSuggestedAction(action) {
            const parsed = normalizeAction(action);
            const label = parsed?.label || safeText(action);
            const a = safeText(label).toLowerCase();
            const category = safeText(parsed?.category || '').toLowerCase();
            const itemId = safeText(parsed?.item_id || '');

            notify.info(`Executando: ${label}`);
            this.recordSuggestionStat(label, 'click');

            // 1. Specific Item Actions
            if (itemId && window.SEOKiller) {
                // Determine the best tool based on category or fallback to Title Generator
                if (category.includes('quality') || category.includes('attribute')) {
                    window.SEOKiller.openAttributeFiller?.(itemId) || window.SEOKiller.openTitleGenerator?.(itemId);
                    return;
                }
                if (category.includes('description')) {
                    window.SEOKiller.openDescriptionGenerator?.(itemId);
                    return;
                }
                // Default specific action
                window.SEOKiller.openTitleGenerator?.(itemId);
                return;
            }

            if (a.includes('diagnóstico') || a.includes('diagnostico')) {
                window.SEOKiller?.runDiagnosis?.();
                return;
            }
            if (a.includes('lote') || a.includes('bulk')) {
                window.SEOKiller?.showBulkOptimizer?.();
                return;
            }
            if (a.includes('título') || a.includes('titulo')) {
                window.SEOKiller?.openTitleGenerator?.();
                return;
            }
            if (a.includes('descrição') || a.includes('descricao')) {
                window.SEOKiller?.openDescriptionGenerator?.();
                return;
            }
            if (a.includes('atributo')) {
                window.SEOKiller?.openAttributeFiller?.();
                return;
            }
            if (a.includes('insights')) {
                window.SEOKiller?.openAIInsights?.();
                return;
            }
            if (a.includes('autopilot') || a.includes('auto pilot') || a.includes('auto-pilot')) {
                window.SEOKiller?.configureAutoPilot?.();
                return;
            }
            if (a.includes('strategies') || a.includes('estratégia') || a.includes('estrategia')) {
                window.location.href = '/dashboard/seo-killer/strategies';
                return;
            }

            if (category) {
                if (category.includes('health')) {
                    window.SEOKiller?.runDiagnosis?.();
                    return;
                }
                if (category.includes('bulk')) {
                    window.SEOKiller?.showBulkOptimizer?.();
                    return;
                }
                if (category.includes('title')) {
                    window.SEOKiller?.openTitleGenerator?.();
                    return;
                }
                if (category.includes('description')) {
                    window.SEOKiller?.openDescriptionGenerator?.();
                    return;
                }
                if (category.includes('quality') || category.includes('attribute')) {
                    window.SEOKiller?.openAttributeFiller?.();
                    return;
                }
                if (category.includes('autopilot')) {
                    window.SEOKiller?.configureAutoPilot?.();
                    return;
                }
                if (category.includes('insights') || category.includes('analysis') || category.includes('monitor')) {
                    window.SEOKiller?.openAIInsights?.();
                    return;
                }
                if (category.includes('strategy')) {
                    window.location.href = '/dashboard/seo-killer/strategies';
                    return;
                }
            }

            // Após executar, remover a ação da lista atual para evitar repetição
            this.dismissSuggestedAction(label);
            const filtered = this.state.suggestedActions.filter((item) => {
                const norm = normalizeAction(item);
                return norm?.label !== label;
            });
            this.setSuggestedActions(filtered, { savedAt: Date.now() });
        },

        async clearHistory() {
            if (!confirm('Limpar histórico de conversa?')) return;
            try {
                await apiFetch('/api/ai/chat/history', { method: 'DELETE' });
            } catch (e) { }

            const { messages } = this.getEls();
            if (messages) {
                messages.innerHTML = `
                    <div class="message bot-message">
                        <div class="message-avatar"><i class="bi bi-robot"></i></div>
                        <div class="message-content"><p>Histórico limpo! Como posso ajudar você?</p></div>
                    </div>
                `;
            }
            this.state.messages = [];
            this.state.conversationId = null;
            try { localStorage.removeItem(STORAGE_KEY); } catch (e) { }
            notify.success('Histórico de conversa limpo');
        },

        async loadProactiveSuggestions({ showSpinner = false } = {}) {
            if (this.state.suggestionsLoading) return;
            this.state.suggestionsLoading = true;
            if (showSpinner && this.state.isOpen) this.showSuggestedActions(this.state.suggestedActions);
            const { badge } = this.getEls();
            try {
                // Add delay to help with rate limiting
                await new Promise(resolve => setTimeout(resolve, 100));

                const res = await fetch('/api/ai/chat/suggest-actions', { headers: { 'Accept': 'application/json' } });
                if (!res.ok) {
                    this.state.suggestionsError = true;
                    this.scheduleSuggestionsRetry();
                    return;
                }
                const result = await res.json().catch(() => null);
                const data = result?.data || {};
                const actions = Array.isArray(data.suggested_actions_meta)
                    ? data.suggested_actions_meta
                    : (Array.isArray(data.suggested_actions) ? data.suggested_actions : []);

                this.setSuggestedActions(actions, { savedAt: Date.now() });
                this.state.suggestionsError = false;
                this.resetSuggestionsRetry();
            } catch (e) {
                this.state.suggestionsError = true;
                this.scheduleSuggestionsRetry();
            }
            finally {
                this.state.suggestionsLoading = false;
                if (showSpinner && this.state.isOpen) this.showSuggestedActions(this.state.suggestedActions);
            }
        },

        scheduleSuggestionsRetry() {
            if (this.state.suggestionsRetryCount >= SUGGESTIONS_RETRY_LIMIT) return;
            if (this.state.suggestionsRetryTimer) return;
            const delay = Math.min(
                SUGGESTIONS_RETRY_BASE_MS * Math.pow(2, this.state.suggestionsRetryCount),
                SUGGESTIONS_RETRY_MAX_MS
            );
            this.state.suggestionsRetryCount += 1;
            this.state.suggestionsRetryTimer = setTimeout(() => {
                this.state.suggestionsRetryTimer = null;
                this.loadProactiveSuggestions({ showSpinner: false });
            }, delay);
        },

        resetSuggestionsRetry() {
            this.state.suggestionsRetryCount = 0;
            if (this.state.suggestionsRetryTimer) {
                clearTimeout(this.state.suggestionsRetryTimer);
                this.state.suggestionsRetryTimer = null;
            }
        },

        async explainMetric(metric, value) {
            if (!this.state.isOpen) this.toggle();
            this.showTyping();
            try {
                const res = await apiFetch('/api/ai/chat/explain-metric', {
                    method: 'POST',
                    body: JSON.stringify({ metric, value })
                });
                const data = res?.data;
                if (data?.message) {
                    this.addMessage('bot', safeText(data.message));
                }
            } catch (e) {
            } finally {
                this.hideTyping();
            }
        },

        async getFeatureHelp(feature) {
            if (!this.state.isOpen) this.toggle();
            this.showTyping();
            try {
                const res = await apiFetch('/api/ai/chat/help-feature', {
                    method: 'POST',
                    body: JSON.stringify({ feature })
                });
                const data = res?.data;
                if (data?.message) {
                    this.addMessage('bot', safeText(data.message));
                }
            } catch (e) {
            } finally {
                this.hideTyping();
            }
        }
    };

    window.toggleChatWidget = () => { Chatbot.toggle(); };
    window.handleChatKeyPress = (event) => { Chatbot.handleKeyPress(event); };
    window.sendChatMessage = () => { Chatbot.sendMessage(); };
    window.sendQuickMessage = (message) => { Chatbot.sendQuickMessage(message); };
    window.clearChatHistory = () => { Chatbot.clearHistory(); };
    window.executeActionFromChat = (action) => { Chatbot.executeSuggestedAction(action); };
    window.explainMetric = (metric, value) => { Chatbot.explainMetric(metric, value); };
    window.getFeatureHelp = (feature) => { Chatbot.getFeatureHelp(feature); };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => Chatbot.init());
    } else {
        Chatbot.init();
    }
})();
