<?php

declare(strict_types=1);

$title = 'Gestão de Reclamações';
$subtitle = 'Resolução de disputas e mediações';
include __DIR__ . '/../../layouts/modern/partials/page-header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Reclamações em Aberto</h5>
                <button class="btn btn-sm btn-outline-primary" onclick="claimsManager.loadClaims()">
                    <i class="bi bi-arrow-clockwise"></i> Atualizar
                </button>
            </div>
            <div class="card-body p-0">
                <div id="claims-error" class="alert alert-danger d-flex justify-content-between align-items-center m-3" style="display:none;">
                    <span id="claims-error-message"></span>
                    <button class="btn btn-sm btn-outline-danger ms-3" onclick="claimsManager.loadClaims()">
                        <i class="bi bi-arrow-clockwise"></i> Tentar novamente
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Reclamação</th>
                                <th>Pedido/Recurso Relacionado</th>
                                <th>Motivo</th>
                                <th>Fase</th>
                                <th>Tipo</th>
                                <th>Data</th>
                                <th class="text-end pe-4">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="claims-list">
                            <tr><td colspan="7" class="text-center py-4"><div class="spinner-border text-primary"></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Responder -->
<div class="modal fade" id="replyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Responder Reclamação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modal-claim-id">
                <div class="mb-3">
                    <label class="form-label">Mensagem para o comprador</label>
                    <textarea class="form-control" id="modal-message" rows="4" placeholder="Escreva sua mensagem..."></textarea>
                </div>
                <div class="alert alert-info small">
                    <i class="bi bi-info-circle"></i> Mantenha a cordialidade para aumentar as chances de mediação positiva.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="claimsManager.sendReply()">Enviar Resposta</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">

    const claimsManager = {
        init: function() {
            this.loadClaims();
        },

        // Nunca renderiza undefined/Invalid Date: campo ausente vira '—'.
        safe: function(val, fallback) {
            return (val === null || val === undefined || val === '') ? (fallback ?? '—') : String(val);
        },

        fmtDate: function(val) {
            if (!val) return '—';
            const d = new Date(val);
            if (Number.isNaN(d.getTime())) return '—';
            return d.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' });
        },

        showError: function(message) {
            const box = document.getElementById('claims-error');
            const msg = document.getElementById('claims-error-message');
            if (msg) msg.textContent = message || 'Erro ao carregar reclamações. Tente novamente.';
            if (box) box.style.display = 'flex';
            document.getElementById('claims-list').innerHTML =
                '<tr><td colspan="7" class="text-center text-muted py-4">Não foi possível carregar as reclamações.</td></tr>';
        },

        hideError: function() {
            const box = document.getElementById('claims-error');
            if (box) box.style.display = 'none';
        },

        loadClaims: async function() {
            this.hideError();
            document.getElementById('claims-list').innerHTML =
                '<tr><td colspan="7" class="text-center py-4"><div class="spinner-border text-primary"></div></td></tr>';
            try {
                const data = await requestJson('/api/claims/list');

                if (!data.success) {
                    if (data.requires_reconnect) {
                        this.showError((data.message || 'Conta desconectada.') + ' Reconecte a conta do Mercado Livre em Configurações.');
                    } else {
                        this.showError(data.message || 'Erro ao carregar reclamações.');
                    }
                    return;
                }

                const claims = Array.isArray(data.claims) ? data.claims : [];
                this.render(claims);
            } catch (e) {
                console.error(e);
                this.showError('Erro de conexão ao carregar reclamações.');
            }
        },

        render: function(claims) {
            const container = document.getElementById('claims-list');
            if (claims.length === 0) {
                container.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Nenhuma reclamação em aberto.</td></tr>';
                return;
            }

            let html = '';
            claims.forEach(c => {
                const stageBadge = c.stage === 'mediation'
                    ? '<span class="badge bg-danger">Mediação</span>'
                    : (c.stage === 'dispute'
                        ? '<span class="badge bg-warning text-dark">Disputa</span>'
                        : `<span class="badge bg-secondary">${this.safe(c.stage)}</span>`);

                html += `
                    <tr>
                        <td class="ps-4 fw-bold">#${this.safe(c.id)}</td>
                        <td>${this.safe(c.resource_id ?? c.order_id)}</td>
                        <td>${this.safe(c.reason_id)}</td>
                        <td>${stageBadge}</td>
                        <td class="small text-uppercase text-muted">${this.safe(c.type)}</td>
                        <td>${this.fmtDate(c.date_created)}</td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-primary" onclick="claimsManager.openReply('${this.safe(c.id, '')}')">
                                <i class="bi bi-chat-left-text"></i> Responder
                            </button>
                        </td>
                    </tr>
                `;
            });
            container.innerHTML = html;
        },
        
        openReply: function(id) {
            document.getElementById('modal-claim-id').value = id;
            document.getElementById('modal-message').value = '';
            new bootstrap.Modal(document.getElementById('replyModal')).show();
        },
        
        sendReply: async function() {
            const id = document.getElementById('modal-claim-id').value;
            const message = document.getElementById('modal-message').value;
            
            if (!message) return alert('Digite uma mensagem');
            
            try {
                const result = await requestJson('/api/claims/send-message', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ claim_id: id, message: message })
                });
                
                if (result.success) {
                    bootstrap.Modal.getInstance(document.getElementById('replyModal')).hide();
                    Toast.success('Mensagem enviada com sucesso!');
                } else {
                    Toast.error('Erro ao enviar mensagem.');
                }
            } catch (error) {
                Toast.error('Erro de conexão.');
            }
        }
    };

    document.addEventListener('DOMContentLoaded', () => claimsManager.init());
</script>
