<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-gear me-2"></i>Configurações</h4>
        <p class="text-muted mb-0">Personalize o sistema de acordo com suas preferências</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <!-- Contas do Mercado Livre -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-shop me-2"></i>Contas do Mercado Livre</h6>
                <a href="/auth/authorize" class="btn btn-success btn-sm">
                    <i class="bi bi-plus-circle me-1"></i>Conectar Nova Conta
                </a>
            </div>
            <div class="card-body">
                <div id="ml-accounts-loading" class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                    <span class="ms-2">Carregando contas...</span>
                </div>
                <div id="ml-accounts-list" style="display: none;"></div>
                <div id="ml-accounts-empty" class="text-center py-4" style="display: none;">
                    <i class="bi bi-shop text-muted" style="font-size: 2rem;"></i>
                    <p class="text-muted mt-2 mb-0">Nenhuma conta conectada</p>
                    <a href="/auth/authorize" class="btn btn-primary btn-sm mt-2">
                        <i class="bi bi-plus-circle me-1"></i>Conectar Conta
                    </a>
                </div>
            </div>
        </div>

        <!-- Notificações -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-bell me-2"></i>Notificações</h6>
            </div>
            <div class="card-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="notifyNewOrders" checked>
                    <label class="form-check-label" for="notifyNewOrders">Notificar sobre novos pedidos</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="notifyTokenExpiring" checked>
                    <label class="form-check-label" for="notifyTokenExpiring">Alertar quando token estiver expirando</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="notifyPriceChanges">
                    <label class="form-check-label" for="notifyPriceChanges">Notificar sobre mudanças de preço</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="notifyNewCompetitors">
                    <label class="form-check-label" for="notifyNewCompetitors">Alertar sobre novos concorrentes</label>
                </div>
                <button class="btn btn-primary btn-sm mt-3" data-action="savenotifications">
                    <i class="bi bi-save me-1"></i>Salvar Preferências
                </button>
            </div>
        </div>

        <!-- Fiscal / precificação -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-percent me-2"></i>Fiscal e margem</h6>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Usado em Vendas e Pricing quando o pedido não traz imposto da API do ML.
                    Simples Nacional costuma usar ~9%.
                </p>
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small" for="default-tax-rate">Alíquota padrão de imposto (%)</label>
                        <input type="number" class="form-control form-control-sm" id="default-tax-rate" min="0" max="100" step="0.01" value="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="min-margin-percent">Margem mínima (%)</label>
                        <input type="number" class="form-control form-control-sm" id="min-margin-percent" min="0" max="100" step="0.01" value="0">
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-primary btn-sm w-100" id="btn-save-global-settings">
                            <i class="bi bi-save me-1"></i>Salvar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notificações em Tempo Real com Áudio -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-volume-up me-2"></i>Sons de Notificação</h6>
                <span class="badge bg-success" id="rt-notification-status">
                    <i class="bi bi-check-circle me-1"></i>Ativo
                </span>
            </div>
            <div class="card-body">
                <div class="alert alert-info small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Receba alertas sonoros instantâneos quando houver novos pedidos ou perguntas.
                </div>

                <!-- Controles principais -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="soundEnabled" checked>
                            <label class="form-check-label" for="soundEnabled">
                                <i class="bi bi-volume-up-fill me-1"></i>Ativar Sons
                            </label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="desktopEnabled" checked>
                            <label class="form-check-label" for="desktopEnabled">
                                <i class="bi bi-window me-1"></i>Notificações Desktop
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">
                            <i class="bi bi-volume-up me-1"></i>Volume: <span id="volumeValue">80</span>%
                        </label>
                        <input type="range" class="form-range" id="soundVolume" min="0" max="100" value="80">
                    </div>
                </div>

                <!-- Sons por tipo -->
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small text-success">
                            <i class="bi bi-cart-check me-1"></i>Pedido
                        </label>
                        <div class="input-group input-group-sm">
                            <select class="form-select form-select-sm" id="soundOrder">
                                <option value="order_notification">Padrão</option>
                                <option value="cash_register">Caixa</option>
                                <option value="cha_ching">Cha-Ching</option>
                                <option value="bell">Sino</option>
                            </select>
                            <button class="btn btn-outline-success btn-sm" type="button" onclick="testSound('order')">
                                <i class="bi bi-play-fill"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-warning">
                            <i class="bi bi-question-circle me-1"></i>Pergunta
                        </label>
                        <div class="input-group input-group-sm">
                            <select class="form-select form-select-sm" id="soundQuestion">
                                <option value="question_notification">Padrão</option>
                                <option value="chime">Campainha</option>
                                <option value="pop">Pop</option>
                            </select>
                            <button class="btn btn-outline-warning btn-sm" type="button" onclick="testSound('question')">
                                <i class="bi bi-play-fill"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-info">
                            <i class="bi bi-chat-dots me-1"></i>Mensagem
                        </label>
                        <div class="input-group input-group-sm">
                            <select class="form-select form-select-sm" id="soundMessage">
                                <option value="message_notification">Padrão</option>
                                <option value="pop">Pop</option>
                                <option value="notification">Genérico</option>
                            </select>
                            <button class="btn btn-outline-info btn-sm" type="button" onclick="testSound('message')">
                                <i class="bi bi-play-fill"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Intervalo e horário silencioso -->
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small">Verificar a cada</label>
                        <select class="form-select form-select-sm" id="pollingInterval">
                            <option value="15">15 segundos</option>
                            <option value="30" selected>30 segundos</option>
                            <option value="60">1 minuto</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">
                            <i class="bi bi-moon me-1"></i>Silenciar de
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="time" class="form-control form-control-sm" id="quietHoursStart" value="22:00">
                            <span class="input-group-text">até</span>
                            <input type="time" class="form-control form-control-sm" id="quietHoursEnd" value="07:00">
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary btn-sm mt-3" data-action="savertnotificationsettings">
                    <i class="bi bi-save me-1"></i>Salvar Sons
                </button>
            </div>
        </div>

        <!-- Integração Telegram -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-telegram me-2"></i>Integração Telegram</h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info small mb-3">
                    <i class="bi bi-info-circle me-1"></i>Configure o Telegram para receber notificações importantes.
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="telegramBotToken" class="form-label small">Bot Token</label>
                        <input type="text" class="form-control form-control-sm" id="telegramBotToken" placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz">
                        <small class="text-muted">Obtenha em @BotFather</small>
                    </div>
                    <div class="col-md-6">
                        <label for="telegramChatId" class="form-label small">Chat ID</label>
                        <input type="text" class="form-control form-control-sm" id="telegramChatId" placeholder="123456789">
                        <small class="text-muted">Seu ID de chat</small>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm mt-3" data-action="savetelegram">
                    <i class="bi bi-save me-1"></i>Salvar Configuração
                </button>
            </div>
        </div>

        <!-- Sincronização -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Sincronização Automática</h6>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="autoSyncOrders" checked>
                            <label class="form-check-label" for="autoSyncOrders">Sincronizar automaticamente</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="syncInterval" class="form-label small">Intervalo</label>
                        <select class="form-select form-select-sm" id="syncInterval">
                            <option value="15">15 minutos</option>
                            <option value="30" selected>30 minutos</option>
                            <option value="60">1 hora</option>
                            <option value="120">2 horas</option>
                        </select>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm mt-3" data-action="savesyncsettings">
                    <i class="bi bi-save me-1"></i>Salvar Configuração
                </button>
            </div>
        </div>

        <!-- Tema -->
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-palette me-2"></i>Aparência</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small">Tema</label>
                        <select class="form-select form-select-sm" id="themeSelect" onchange="changeTheme(this.value)">
                            <option value="light">Claro</option>
                            <option value="dark">Escuro</option>
                            <option value="auto">Automático (sistema)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Sidebar</label>
                        <select class="form-select form-select-sm" id="sidebarMode">
                            <option value="expanded">Expandida</option>
                            <option value="collapsed">Recolhida</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Ações Rápidas -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-lightning me-2"></i>Ações Rápidas</h6>
            </div>
            <div class="card-body d-grid gap-2">
                <button class="btn btn-outline-primary" data-action="syncnow">
                    <i class="bi bi-arrow-clockwise me-1"></i>Sincronizar Agora
                </button>
                <button class="btn btn-outline-success" data-action="export-data">
                    <i class="bi bi-download me-1"></i>Exportar Dados
                </button>
                <a href="/dashboard/monitoring" class="btn btn-outline-info">
                    <i class="bi bi-graph-up me-1"></i>Ver Monitoramento
                </a>
                <button class="btn btn-outline-warning" data-action="clearcache">
                    <i class="bi bi-trash me-1"></i>Limpar Cache
                </button>
            </div>
        </div>

        <!-- Informações do Sistema -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Sistema</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Versão:</td>
                        <td>2.0.0</td>
                    </tr>
                    <tr>
                        <td class="text-muted">PHP:</td>
                        <td><?= phpversion() ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Atualização:</td>
                        <td><?= date('d/m/Y H:i') ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Links Úteis -->
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-link-45deg me-2"></i>Links Úteis</h6>
            </div>
            <div class="list-group list-group-flush">
                <a href="/dashboard/help" class="list-group-item list-group-item-action">
                    <i class="bi bi-question-circle me-2"></i>Central de Ajuda
                </a>
                <a href="/dashboard/audit" class="list-group-item list-group-item-action">
                    <i class="bi bi-shield-check me-2"></i>Logs de Auditoria
                </a>
                <a href="/dashboard/backups" class="list-group-item list-group-item-action">
                    <i class="bi bi-cloud-arrow-up me-2"></i>Backups
                </a>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
    function saveNotifications() {
        const settings = {
            newOrders: document.getElementById('notifyNewOrders').checked,
            tokenExpiring: document.getElementById('notifyTokenExpiring').checked,
            priceChanges: document.getElementById('notifyPriceChanges').checked,
            newCompetitors: document.getElementById('notifyNewCompetitors').checked
        };

        requestJson('/api/settings/notifications', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(settings)
        }).then(data => {
            alert(data.success ? 'Preferências salvas!' : 'Erro ao salvar');
        }).catch(() => alert('Erro ao salvar'));
    }

    function saveTelegram() {
        const token = document.getElementById('telegramBotToken').value;
        const chatId = document.getElementById('telegramChatId').value;

        requestJson('/api/settings/telegram', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                token,
                chatId
            })
        }).then(data => {
            alert(data.success ? 'Configuração salva!' : 'Erro ao salvar');
        }).catch(() => alert('Erro ao salvar'));
    }

    function saveSyncSettings() {
        const autoSync = document.getElementById('autoSyncOrders').checked;
        const interval = document.getElementById('syncInterval').value;

        requestJson('/api/settings/sync', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                autoSync,
                interval
            })
        }).then(data => {
            alert(data.success ? 'Configurações salvas!' : 'Erro');
        }).catch(() => alert('Erro'));
    }

    function syncNow() {
        if (!confirm('Sincronizar agora?')) return;
        requestJson('/api/polling/all', {
                method: 'POST'
            })
            .then(() => alert('Sincronização iniciada!'))
            .catch(() => alert('Erro ao sincronizar'));
    }

    function exportData() {
        window.open('/api/export/analysis/json', '_blank');
    }

    function clearCache() {
        if (!confirm('Limpar o cache?')) return;
        requestJson('/api/cache/clear', {
                method: 'POST'
            })
            .then(() => alert('Cache limpo!'))
            .catch(() => alert('Erro ao limpar cache'));
    }

    function changeTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme === 'auto' ?
            (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : theme);
        localStorage.setItem('theme', theme);
    }

    // =====================================
    // Notificações em Tempo Real com Áudio
    // =====================================

    // Carregar configurações de notificação em tempo real
    async function loadRTNotificationSettings() {
        try {
            const data = await requestJson('/api/notifications/realtime/settings');

            if (data.success && data.settings) {
                const s = data.settings;
                document.getElementById('soundEnabled').checked = s.sound_enabled;
                document.getElementById('desktopEnabled').checked = s.desktop_enabled;
                document.getElementById('soundVolume').value = s.sound_volume || 80;
                document.getElementById('volumeValue').textContent = s.sound_volume || 80;
                document.getElementById('soundOrder').value = s.sound_order || 'order_notification';
                document.getElementById('soundQuestion').value = s.sound_question || 'question_notification';
                document.getElementById('soundMessage').value = s.sound_message || 'message_notification';
                document.getElementById('pollingInterval').value = s.polling_interval || 30;

                if (s.quiet_hours_start) document.getElementById('quietHoursStart').value = s.quiet_hours_start;
                if (s.quiet_hours_end) document.getElementById('quietHoursEnd').value = s.quiet_hours_end;
            }
        } catch (error) {
            console.error('Erro ao carregar configurações de som:', error);
        }
    }

    // Salvar configurações de notificação em tempo real
    async function saveRTNotificationSettings() {
        const settings = {
            sound_enabled: document.getElementById('soundEnabled').checked,
            desktop_enabled: document.getElementById('desktopEnabled').checked,
            sound_volume: parseInt(document.getElementById('soundVolume').value),
            sound_order: document.getElementById('soundOrder').value,
            sound_question: document.getElementById('soundQuestion').value,
            sound_message: document.getElementById('soundMessage').value,
            polling_interval: parseInt(document.getElementById('pollingInterval').value),
            quiet_hours_start: document.getElementById('quietHoursStart').value || null,
            quiet_hours_end: document.getElementById('quietHoursEnd').value || null
        };

        try {
            const data = await requestJson('/api/notifications/realtime/settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(settings)
            });

            if (data.success) {
                alert('✅ Configurações de som salvas!');

                // Atualizar instância global de notificações
                if (window.realTimeNotifications) {
                    window.realTimeNotifications.updateConfig({
                        soundEnabled: settings.sound_enabled,
                        desktopEnabled: settings.desktop_enabled,
                        soundVolume: settings.sound_volume / 100,
                        pollingInterval: settings.polling_interval * 1000
                    });
                }
            } else {
                alert('❌ Erro ao salvar configurações');
            }
        } catch (error) {
            console.error('Erro:', error);
            alert('❌ Erro ao salvar configurações');
        }
    }

    // Testar som de notificação
    function testSound(type) {
        // Usar sistema de notificações global se disponível
        if (window.realTimeNotifications) {
            const volume = parseInt(document.getElementById('soundVolume').value) / 100;
            window.realTimeNotifications.config.soundVolume = volume;
            window.realTimeNotifications.config.soundOrder = document.getElementById('soundOrder').value;
            window.realTimeNotifications.config.soundQuestion = document.getElementById('soundQuestion').value;
            window.realTimeNotifications.config.soundMessage = document.getElementById('soundMessage').value;
            window.realTimeNotifications.testSound(type);
        } else {
            // Fallback com Web Audio API
            playTestSound(type);
        }
    }

    // Som de teste com Web Audio API (fallback)
    function playTestSound(type) {
        try {
            const audioContext = new(window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);

            const frequencies = {
                order: [880, 1100, 1320],
                question: [660, 880],
                message: [523]
            };

            const freqs = frequencies[type] || [700];
            const volume = parseInt(document.getElementById('soundVolume').value) / 100;

            gainNode.gain.setValueAtTime(volume * 0.3, audioContext.currentTime);

            let time = audioContext.currentTime;
            for (const freq of freqs) {
                oscillator.frequency.setValueAtTime(freq, time);
                time += 0.15;
            }

            gainNode.gain.exponentialRampToValueAtTime(0.01, time + 0.2);

            oscillator.start(audioContext.currentTime);
            oscillator.stop(time + 0.3);
        } catch (error) {
            console.warn('Erro ao tocar som:', error);
        }
    }

    // Atualizar valor do volume em tempo real
    document.getElementById('soundVolume')?.addEventListener('input', function() {
        document.getElementById('volumeValue').textContent = this.value;
    });

    // Load saved theme
    document.addEventListener('DOMContentLoaded', function() {
        const saved = localStorage.getItem('theme') || 'light';
        document.getElementById('themeSelect').value = saved;

        // Carregar configurações de notificação em tempo real
        loadRTNotificationSettings();

        // Carregar contas do Mercado Livre
        loadMLAccounts();

        loadGlobalSettings();
        document.getElementById('btn-save-global-settings')?.addEventListener('click', saveGlobalSettings);
    });

    async function loadGlobalSettings() {
        try {
            const data = await requestJson('/api/settings/global');
            const settings = data.settings || {};
            const taxEl = document.getElementById('default-tax-rate');
            const marginEl = document.getElementById('min-margin-percent');
            if (taxEl) taxEl.value = settings.default_tax_rate ?? 0;
            if (marginEl) marginEl.value = settings.min_margin_percent ?? 0;
        } catch (e) {
            console.error('Erro ao carregar settings globais', e);
        }
    }

    async function saveGlobalSettings() {
        const btn = document.getElementById('btn-save-global-settings');
        if (btn) btn.disabled = true;
        try {
            const payload = {
                default_tax_rate: parseFloat(document.getElementById('default-tax-rate')?.value || '0'),
                min_margin_percent: parseFloat(document.getElementById('min-margin-percent')?.value || '0'),
            };
            const data = await requestJson('/api/settings/global', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            alert(data.success ? 'Configurações fiscais salvas!' : (data.error || 'Erro ao salvar'));
        } catch (e) {
            console.error(e);
            alert('Erro ao salvar configurações fiscais');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // =====================================
    // Gerenciamento de Contas ML
    // =====================================

    async function loadMLAccounts() {
        const loadingEl = document.getElementById('ml-accounts-loading');
        const listEl = document.getElementById('ml-accounts-list');
        const emptyEl = document.getElementById('ml-accounts-empty');

        try {
            const data = await requestJson('/api/auth/accounts');

            // API retorna { accounts: [...], total: N }
            const accounts = Array.isArray(data) ? data : (data.accounts || []);

            loadingEl.style.display = 'none';

            if (!accounts || accounts.length === 0) {
                emptyEl.style.display = 'block';
                return;
            }

            listEl.style.display = 'block';
            listEl.innerHTML = accounts.map(account => renderMLAccount(account)).join('');

        } catch (error) {
            console.error('Erro ao carregar contas:', error);
            loadingEl.innerHTML = '<div class="alert alert-danger">Erro ao carregar contas</div>';
        }
    }

    function renderMLAccount(account) {
        const isExpired = account.status === 'expired' ||
            (account.token_expires_at && new Date(account.token_expires_at) < new Date());
        const statusClass = isExpired ? 'danger' : (account.status === 'active' ? 'success' : 'warning');
        const statusText = isExpired ? 'Token Expirado' : (account.status === 'active' ? 'Ativo' : 'Inativo');
        const statusIcon = isExpired ? 'exclamation-triangle' : (account.status === 'active' ? 'check-circle' : 'pause-circle');

        const expiresAt = account.token_expires_at ? new Date(account.token_expires_at).toLocaleString('pt-BR') : 'N/A';

        return `
        <div class="d-flex justify-content-between align-items-center p-3 border rounded mb-2" id="ml-account-${account.id}">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <i class="bi bi-shop fs-4 text-primary"></i>
                </div>
                <div>
                    <div class="fw-bold">${account.nickname || 'Conta ML'}</div>
                    <small class="text-muted">${account.email || 'ID: ' + account.ml_user_id}</small>
                    <div class="mt-1">
                        <span class="badge bg-${statusClass}">
                            <i class="bi bi-${statusIcon} me-1"></i>${statusText}
                        </span>
                        ${isExpired ? '' : `<small class="text-muted ms-2">Expira: ${expiresAt}</small>`}
                    </div>
                </div>
            </div>
            <div class="btn-group">
                ${isExpired ? `
                    <a href="/auth/authorize" class="btn btn-warning btn-sm" title="Reconectar">
                        <i class="bi bi-arrow-clockwise me-1"></i>Reconectar
                    </a>
                ` : `
                    <button class="btn btn-outline-primary btn-sm" onclick="refreshMLToken(${account.id})" title="Atualizar Token">
                        <i class="bi bi-arrow-repeat"></i>
                    </button>
                    <button class="btn btn-outline-info btn-sm" onclick="diagnoseMLAccount(${account.id})" title="Diagnóstico">
                        <i class="bi bi-info-circle"></i>
                    </button>
                `}
                <button class="btn btn-outline-danger btn-sm" onclick="disconnectMLAccount(${account.id}, '${account.nickname}')" title="Desconectar">
                    <i class="bi bi-x-circle"></i>
                </button>
            </div>
        </div>
    `;
    }

    async function refreshMLToken(accountId) {
        const btn = event.target.closest('button');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        btn.disabled = true;

        try {
            const data = await requestJson('/api/settings/ml-refresh', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    account_id: accountId
                })
            });

            if (data.success) {
                alert('✅ Token renovado com sucesso!');
                loadMLAccounts(); // Recarregar lista
            } else {
                if (data.action_required === 'reauthorize') {
                    if (confirm('❌ O token expirou e não pode ser renovado automaticamente.\n\nDeseja reconectar a conta agora?')) {
                        window.location.href = '/auth/authorize';
                    }
                } else {
                    alert('❌ Falha ao renovar token: ' + (data.message || 'Erro desconhecido'));
                }
            }
        } catch (error) {
            console.error('Erro:', error);
            alert('❌ Erro ao renovar token');
        } finally {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
    }

    async function diagnoseMLAccount(accountId) {
        try {
            const data = await requestJson(`/api/settings/ml-diagnostico?account_id=${accountId}`);

            let message = `Diagnóstico da Conta #${accountId}\n\n`;

            if (data.diagnostics && data.diagnostics[accountId]) {
                const diag = data.diagnostics[accountId];
                message += `Status: ${diag.status || 'desconhecido'}\n`;
                message += `Nickname: ${diag.nickname || 'N/A'}\n`;
                message += `Token válido: ${diag.token_valid ? 'Sim' : 'Não'}\n`;
                message += `Expira em: ${diag.expires_in || 'N/A'}\n`;

                if (diag.api_test) {
                    message += `\nTeste API: ${diag.api_test.success ? '✅ OK' : '❌ Falhou'}\n`;
                    if (diag.api_test.error) {
                        message += `Erro: ${diag.api_test.error}\n`;
                    }
                }
            } else {
                message += 'Não foi possível obter diagnóstico detalhado.\n';
                message += JSON.stringify(data, null, 2);
            }

            alert(message);

        } catch (error) {
            console.error('Erro:', error);
            alert('❌ Erro ao obter diagnóstico');
        }
    }

    async function disconnectMLAccount(accountId, nickname) {
        if (!confirm(`Deseja realmente desconectar a conta "${nickname}"?\n\nVocê poderá reconectar a qualquer momento.`)) {
            return;
        }

        try {
            const data = await requestJson(`/auth/disconnect/${accountId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (data.success) {
                alert('✅ Conta desconectada com sucesso!');
                loadMLAccounts(); // Recarregar lista
            } else {
                alert('❌ Erro ao desconectar: ' + (data.error || 'Erro desconhecido'));
            }
        } catch (error) {
            console.error('Erro:', error);
            alert('❌ Erro ao desconectar conta');
        }
    }
    // CSP Event Delegation
    document.addEventListener('click', e => {
        const t = e.target.closest('[data-action]');
        if (!t) return;
        const action = t.dataset.action;
        const fn = window[action] || window[action.replace(/-([a-z])/g, (m, c) => c.toUpperCase())];
        if (fn) {
            e.preventDefault();
            fn(t.dataset.param || t.dataset.id);
        }
    });
</script>
