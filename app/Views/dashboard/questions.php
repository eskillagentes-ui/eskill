<?php

declare(strict_types=1);

$title = 'Perguntas';
$subtitle = 'Gerencie as perguntas dos seus anúncios';
$actions = '
    <div class="d-flex gap-2 flex-wrap">
        <span class="badge bg-light text-dark border align-self-center" id="activeAccountBadge">Conta ativa</span>
        <select class="form-select form-select-sm" id="questionFilter" style="width: auto;">
            <option value="all">Todas as Perguntas</option>
            <option value="unanswered" selected>Não Respondidas</option>
            <option value="answered">Respondidas</option>
        </select>
        <div class="form-check form-switch d-flex align-items-center gap-2 border px-2 rounded bg-white">
            <input class="form-check-input" type="checkbox" id="sortByUrgency" onchange="loadQuestions()">
            <label class="form-check-label small fw-semibold" for="sortByUrgency">Priorizar Urgentes</label>
        </div>
        <button class="btn btn-outline-success btn-sm" id="syncQuestionsBtn" onclick="syncQuestions()">
            <i class="bi bi-cloud-download me-1"></i> Sincronizar ML
        </button>
        <button class="btn btn-primary btn-sm" onclick="loadQuestions()">
            <i class="bi bi-arrow-clockwise"></i> Atualizar
        </button>
    </div>
';
include __DIR__ . '/../layouts/modern/partials/page-header.php';
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
    }
</style>

<!-- Stats Cards -->
<div class="stats-grid mb-4">
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="bi bi-chat-dots"></i>
        </div>
        <div class="stat-info">
            <h3 id="totalQuestions">-</h3>
            <p>Total de Perguntas</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="bi bi-clock"></i>
        </div>
        <div class="stat-info">
            <h3 id="pendingQuestions">-</h3>
            <p>Aguardando Resposta</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon success">
            <i class="bi bi-check-circle"></i>
        </div>
        <div class="stat-info">
            <h3 id="answeredQuestions">-</h3>
            <p>Respondidas</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon info">
            <i class="bi bi-stopwatch"></i>
        </div>
        <div class="stat-info">
            <h3 id="avgResponseTime">-</h3>
            <p>Tempo Médio</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="bi bi-exclamation-triangle"></i>
        </div>
        <div class="stat-info">
            <h3 id="slaOverdueQuestions">-</h3>
            <p>Não respondidas ≥ 1h</p>
        </div>
    </div>
</div>

<!-- Auto-Resposta Settings Banner -->
<div class="card border-0 shadow-sm mb-4" id="autoAnswerCard">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center" style="width:44px;height:44px">
                    <i class="bi bi-robot text-primary fs-5"></i>
                </div>
                <div>
                    <h6 class="mb-0 fw-semibold">Auto-Resposta com IA</h6>
                    <p class="text-muted mb-0 small">Responde automaticamente perguntas com alta confiança de IA (<span id="autoConfidenceLabel">90</span>%+)</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="d-flex align-items-center gap-2">
                    <label class="small text-muted mb-0">Confiança mínima:</label>
                    <input type="range" class="form-range" id="autoConfidenceRange" min="50" max="99" value="90" style="width:120px" oninput="document.getElementById('autoConfidenceLabel').textContent=this.value">
                    <span class="badge bg-primary" id="autoConfidenceBadge">90%</span>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="autoAnswerToggle" onchange="saveAutoAnswerSettings()">
                    <label class="form-check-label fw-semibold" for="autoAnswerToggle">Ativo</label>
                </div>
                <span class="badge bg-secondary" id="autoAnswerStatsBadge">Carregando...</span>
            </div>
        </div>
    </div>
</div>

<!-- Questions List -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 40%">Pergunta</th>
                        <th>Conta</th>
                        <th>Produto</th>
                        <th>Data</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody id="questionsList">
                    <tr>
                        <td colspan="5" class="text-center py-5">
                            <div class="spinner-border text-primary"></div>
                            <p class="text-muted mt-2 mb-0">Carregando perguntas...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Answer Modal -->
<div class="modal fade" id="answerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-reply me-2"></i>Responder Pergunta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Pergunta:</label>
                    <p id="modalQuestionText" class="bg-light p-3 rounded"></p>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Sua Resposta:</label>
                    <textarea class="form-control" id="answerText" rows="4" placeholder="Digite sua resposta..."></textarea>
                    <div class="form-text">Use respostas profissionais e completas para melhorar sua reputação.</div>
                </div>
                <div class="mb-3">
                <div class="mb-3">
                    <label class="form-label">Respostas Rápidas:</label>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button class="btn btn-sm btn-outline-secondary" onclick="insertQuickAnswer('Olá! Sim, temos disponível em estoque. Pode comprar!')">Disponível</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="insertQuickAnswer('Olá! O prazo de envio é de 1 a 2 dias úteis após confirmação do pagamento.')">Prazo Envio</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="insertQuickAnswer('Olá! Oferecemos garantia de 3 meses contra defeitos de fabricação.')">Garantia</button>
                    </div>
                    <!-- AI Draft Button -->
                    <button class="btn btn-sm btn-outline-primary w-100" onclick="generateDraft()" id="btnDraft">
                        <i class="bi bi-magic me-1"></i> Gerar Resposta com IA
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="submitAnswer()">
                    <i class="bi bi-send me-1"></i>Enviar Resposta
                </button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
let currentQuestionId = null;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

document.addEventListener('DOMContentLoaded', function() {
    loadQuestions();
    loadStats();
});

document.getElementById('questionFilter').addEventListener('change', loadQuestions);

async function loadStats() {
    try {
        const { data } = await ApiClient.json('/api/questions/stats');

        if (data.success) {
            document.getElementById('totalQuestions').textContent = data.total || 0;
            document.getElementById('pendingQuestions').textContent = data.pending || 0;
            document.getElementById('answeredQuestions').textContent = data.answered || 0;
            document.getElementById('avgResponseTime').textContent = data.avg_response_time || '-';
            const slaEl = document.getElementById('slaOverdueQuestions');
            if (slaEl) slaEl.textContent = data.unanswered_ge_1h || 0;
        }
    } catch (e) {
        console.error('Erro ao carregar estatísticas:', e);
    }
}

async function loadQuestions() {
    const filter = document.getElementById('questionFilter').value;
    const tbody = document.getElementById('questionsList');

    const sort = document.getElementById('sortByUrgency')?.checked ? 'urgency_desc' : '';

    try {
        const { data } = await ApiClient.json(`/api/questions?status=${encodeURIComponent(filter)}&sort=${encodeURIComponent(sort)}`);

        if (data.questions && data.questions.length > 0) {
            tbody.innerHTML = data.questions.map(q => {
                // Sentiment Logic
                let sentimentIcon = '';
                if (q.sentiment === 'negative' || q.sentiment === 'angry') sentimentIcon = '<span title="Cliente Insatisfeito">😡</span>';
                else if (q.sentiment === 'positive') sentimentIcon = '<span title="Cliente Feliz">😍</span>';
                else if (q.sentiment === 'neutral') sentimentIcon = '<span title="Neutro">😐</span>';

                // Intent Badge
                let intentBadge = '';
                if (q.intent) {
                    const intentColors = {
                        'shipping': 'text-bg-warning',
                        'technical': 'text-bg-info',
                        'price': 'text-bg-success',
                        'stock': 'text-bg-primary',
                        'warranty': 'text-bg-danger'
                    };
                    const color = intentColors[q.intent] || 'text-bg-secondary';
                    intentBadge = `<span class="badge ${color} ms-1" style="font-size: 0.7em">${q.intent.toUpperCase()}</span>`;
                }

                const waiting = Number(q.waiting_seconds || 0);
                const slaOverdue = Boolean(q.sla_overdue) || (
                    String(q.status || '').toUpperCase() === 'UNANSWERED' && waiting >= 3600
                );
                const isUrgent = (q.urgency > 70);
                const rowClass = slaOverdue ? 'table-danger' : (isUrgent ? 'table-warning' : '');
                const waitLabel = waiting > 0 ? formatWait(waiting) : '';

                return `
                <tr class="${rowClass}">
                    <td>
                        <div class="fw-semibold">
                            ${sentimentIcon} ${escapeHtml(q.text)}
                            ${intentBadge}
                        </div>
                        <small class="text-muted">De: ${q.from_user || 'Anônimo'}</small>
                        ${slaOverdue ? '<span class="badge bg-danger ms-2">SLA ≥ 1h</span>' : ''}
                        ${!slaOverdue && waitLabel && String(q.status || '').toUpperCase() === 'UNANSWERED' ? `<small class="text-muted ms-2">${waitLabel}</small>` : ''}
                        ${isUrgent ? '<small class="text-danger fw-bold ms-2">URGENTE</small>' : ''}
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border">${escapeHtml(q.account?.name || 'Conta ' + q.account_id)}</span>
                    </td>
                    <td>
                        <a href="#" class="text-decoration-none">${escapeHtml(q.item_title || 'Produto')}</a>
                    </td>
                    <td class="text-muted small">${formatDate(q.date_created)}${String(q.status || '').toUpperCase() === 'UNANSWERED' && waitLabel ? `<div class="text-danger">${waitLabel}</div>` : ''}</td>
                    <td>
                        <span class="badge ${q.status === 'ANSWERED' ? 'bg-success' : 'bg-warning'}">
                            ${q.status === 'ANSWERED' ? 'Respondida' : 'Pendente'}
                        </span>
                    </td>
                    <td class="text-end">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <button class="btn btn-sm btn-outline-info" onclick="analyzeQuestion('${q.id}')" title="Analisar com IA">
                                <i class="bi bi-cpu"></i>
                            </button>
                            ${q.status !== 'ANSWERED' ? `
                                <button class="btn btn-sm btn-primary" onclick="openAnswerModal(${q.id}, '${escapeHtml(q.text)}')">
                                    <i class="bi bi-reply"></i> Responder
                                </button>
                            ` : `
                                <button class="btn btn-sm btn-outline-secondary" onclick="viewAnswer(${q.id})">
                                    <i class="bi bi-eye"></i> Ver
                                </button>
                            `}
                        </div>
                    </td>
                </tr>
            `}).join('');
        } else {
            // Onda 2.1 / A1: o filtro padrão da tela é "unanswered" (Não Respondidas).
            // Quando a conta não tem nenhuma pergunta pendente, esse filtro retorna [] mesmo
            // com dezenas de perguntas respondidas — os contadores do topo (que somam TODAS)
            // continuam mostrando o total real. Sem uma mensagem por contexto, isso parecia um
            // bug de "contadores != tabela" quando na verdade é o resultado esperado do filtro.
            const emptyMessages = {
                unanswered: 'Nenhuma pergunta pendente — todas já foram respondidas! 🎉',
                answered: 'Nenhuma pergunta respondida ainda.',
                all: 'Nenhuma pergunta encontrada para esta conta.',
            };
            const emptyMessage = emptyMessages[filter] || emptyMessages.all;
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center py-5 text-muted">
                        <i class="bi bi-chat-dots fs-1"></i>
                        <p class="mt-2 mb-0">${emptyMessage}</p>
                    </td>
                </tr>
            `;
        }
    } catch (e) {
        console.error('Erro ao carregar perguntas:', e);
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger">Erro ao carregar perguntas</td></tr>`;
    }
}

function openAnswerModal(questionId, questionText) {
    currentQuestionId = questionId;
    document.getElementById('modalQuestionText').textContent = questionText;
    document.getElementById('answerText').value = '';
    // Reset AI button state
    const btn = document.getElementById('btnDraft');
    btn.innerHTML = '<i class="bi bi-magic me-1"></i> Gerar Resposta com IA';
    btn.disabled = false;

    new bootstrap.Modal(document.getElementById('answerModal')).show();
}

function insertQuickAnswer(text) {
    document.getElementById('answerText').value = text;
}

async function generateDraft() {
    if (!currentQuestionId) return;

    const btn = document.getElementById('btnDraft');
    const originalText = btn.innerHTML;
    const txtArea = document.getElementById('answerText');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Gerando...';
    txtArea.placeholder = "Aguarde, a IA está escrevendo...";

    try {
        const { data } = await ApiClient.json(`/api/questions/${currentQuestionId}/draft`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            }
        });

        if (data.success && data.draft) {
            txtArea.value = data.draft;
            // Highlight capability
            txtArea.classList.add('border-primary');
            setTimeout(() => txtArea.classList.remove('border-primary'), 2000);
        } else {
            alert('Erro ao gerar rascunho: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (e) {
        console.error(e);
        alert('Falha na comunicação com a IA.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function submitAnswer() {
    const answer = document.getElementById('answerText').value.trim();

    if (!answer) {
        alert('Digite uma resposta');
        return;
    }

    try {
        const { data, response } = await ApiClient.json(`/api/questions/${currentQuestionId}/answer`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ text: answer, answer })
        });

        if (data.apply_blocked || response?.status === 403) {
            alert(`${data.error || 'Apply bloqueado'} Conta 1335 exige GO item a item.`);
            return;
        }

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('answerModal')).hide();
            loadQuestions();
            loadStats();
            // Show toast success
            // Assuming Toast is available or simple alert
            alert('Resposta enviada com sucesso!');
        } else {
            alert('Erro: ' + (data.error || 'Erro ao enviar resposta'));
        }
    } catch (e) {
        const blocked = Boolean(e?.payload?.apply_blocked) || String(e?.message || '').includes('403');
        alert(blocked
            ? `${e.payload?.error || 'Apply bloqueado'} Conta 1335 exige GO item a item.`
            : 'Erro ao enviar resposta');
    }
}

function formatDate(dateString) {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function formatWait(seconds) {
    const s = Math.max(0, Number(seconds) || 0);
    if (s < 60) return s + 's';
    if (s < 3600) return Math.floor(s / 60) + 'min';
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    return h + 'h' + String(m).padStart(2, '0');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function loadAutoAnswerSettings() {
    try {
        const data = await requestJson('/api/questions/auto-answer/settings');
        const enabled = data.enabled ?? false;
        const confidence = data.min_confidence ?? 90;
        document.getElementById('autoAnswerToggle').checked = enabled;
        document.getElementById('autoConfidenceRange').value = confidence;
        document.getElementById('autoConfidenceLabel').textContent = confidence;
        document.getElementById('autoConfidenceBadge').textContent = confidence + '%';
        document.getElementById('autoAnswerStatsBadge').textContent =
            'Hoje: ' + (data.answered_today ?? 0) + ' enviadas';
        document.getElementById('autoAnswerStatsBadge').className =
            'badge ' + (enabled ? 'bg-success' : 'bg-secondary');
    } catch (e) {
        console.error('Erro ao carregar configurações de auto-resposta:', e);
        document.getElementById('autoAnswerStatsBadge').textContent = '—';
        document.getElementById('autoAnswerStatsBadge').className = 'badge bg-secondary';
    }
}

async function saveAutoAnswerSettings() {
    const enabled = document.getElementById('autoAnswerToggle').checked;
    const confidence = parseInt(document.getElementById('autoConfidenceRange').value, 10);
    document.getElementById('autoConfidenceBadge').textContent = confidence + '%';
    try {
        await requestJson('/api/questions/auto-answer/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ enabled, min_confidence: confidence })
        });
        document.getElementById('autoAnswerStatsBadge').className =
            'badge ' + (enabled ? 'bg-success' : 'bg-secondary');
        document.getElementById('autoAnswerStatsBadge').textContent =
            enabled ? 'Ativo' : 'Inativo';
    } catch (e) {
        alert('Erro ao salvar configurações de auto-resposta');
    }
}

document.getElementById('autoConfidenceRange').addEventListener('change', saveAutoAnswerSettings);

loadAutoAnswerSettings();

async function syncQuestions() {
    const btn = document.getElementById('syncQuestionsBtn');
    if (!btn) return;

    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Sincronizando...';

    try {
        const { data } = await ApiClient.json('/api/questions/sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ limit: 50 })
        });

        if (data.success) {
            alert(`Sincronização concluída: ${data.synced} pergunta(s) importada(s).`);
            loadQuestions();
            loadStats();
        } else {
            alert('Erro: ' + (data.error || 'Falha na sincronização'));
        }
    } catch (e) {
        alert('Erro ao sincronizar perguntas do ML.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
}

async function analyzeQuestion(questionId) {
    if (!questionId) return;

    try {
        const { data } = await ApiClient.json(`/api/questions/${questionId}/analyze`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }
        });

        if (data.success !== false) {
            const sentiment = data.sentiment || data.emotion || '?';
            const intent = data.intent || data.category || '?';
            const urgency = data.urgency !== undefined ? data.urgency : '?';
            alert(`Análise IA:\nSentimento: ${sentiment}\nIntenção: ${intent}\nUrgência: ${urgency}`);
            loadQuestions();
        } else {
            alert('Erro na análise: ' + (data.message || data.error || 'Falha'));
        }
    } catch (e) {
        alert('Erro ao analisar pergunta com IA.');
    }
}
</script>
