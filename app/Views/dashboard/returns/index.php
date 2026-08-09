<?php

declare(strict_types=1);

/**
 * Devoluções (Reclamações ML) — read-only.
 *
 * BUG CORRIGIDO (Onda 1 / T4): esta view antes assumia um modelo local de
 * RMA (ml_order_id, sku, quantity, claim_id, condition_rating,
 * inspector_name) que nunca existiu no backend — os dados reais vêm da API
 * de claims do Mercado Livre (id, resource_id, reason_id, date_created,
 * status...). Acessar campos inexistentes (ex.: strtotime($h['updated_at'])
 * com updated_at ausente) causava TypeError/HTTP 500 em PHP 8.
 *
 * Sem escrita no ML (ML_WRITE_AUTOMATION=false): os botões de "registrar
 * devolução"/"confirmar chegada"/"triagem" de um workflow de logística
 * reversa local foram removidos porque não há rotas/tabela que os
 * sustentem — mantê-los seria uma funcionalidade decorativa que nunca
 * funcionou (formulários apontavam para rotas inexistentes).
 *
 * @var array<int, array<string, mixed>> $pending
 * @var array<int, array<string, mixed>> $history
 * @var array{error: string, message?: string, requires_reconnect?: bool}|null $error
 *
 * Renderizada via ReturnsController::index(), que já faz o próprio
 * ob_start()/require deste arquivo e inclui o layout uma única vez — este
 * arquivo NÃO deve chamar ob_start()/include do layout de novo (bug
 * corrigido: causava layout duplicado/aninhado).
 */

/** Nunca renderiza null/undefined — campo ausente vira '—'. */
$safe = static function ($value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body p-4">
                <h2 class="fw-bold mb-1">Devoluções</h2>
                <p class="mb-0 opacity-75">
                    Reclamações do tipo devolução/disputa vindas da API do Mercado Livre (somente leitura).
                </p>
            </div>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex justify-content-between align-items-center">
        <div>
            <strong>Não foi possível carregar as devoluções.</strong>
            <div class="small"><?= $safe($error['message'] ?? 'Erro desconhecido ao consultar o Mercado Livre.') ?></div>
            <?php if (!empty($error['requires_reconnect'])): ?>
                <div class="small mt-1">
                    <a href="/auth/authorize" class="alert-link">Reconectar conta do Mercado Livre</a>
                </div>
            <?php endif; ?>
        </div>
        <a href="/dashboard/returns" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-arrow-clockwise me-1"></i> Tentar novamente
        </a>
    </div>
<?php endif; ?>

<ul class="nav nav-pills mb-4" id="pills-tab" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" id="pills-pending-tab" data-bs-toggle="pill" data-bs-target="#pills-pending" type="button">
            <i class="bi bi-hourglass-split me-2"></i> Abertas
            <span class="badge bg-danger rounded-pill ms-2"><?= count($pending) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" id="pills-history-tab" data-bs-toggle="pill" data-bs-target="#pills-history" type="button">
            <i class="bi bi-clock-history me-2"></i> Encerradas
            <span class="badge bg-secondary rounded-pill ms-2"><?= count($history) ?></span>
        </button>
    </li>
</ul>

<div class="tab-content" id="pills-tabContent">

    <!-- Abertas -->
    <div class="tab-pane fade show active" id="pills-pending">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Reclamação</th>
                            <th>Pedido/Recurso</th>
                            <th>Motivo</th>
                            <th>Tipo</th>
                            <th>Aberta em</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $r): ?>
                        <tr>
                            <td class="ps-4">
                                <span class="fw-bold text-dark">#<?= $safe($r['id'] ?? null) ?></span>
                            </td>
                            <td><?= $safe($r['resource_id'] ?? ($r['order_id'] ?? null)) ?></td>
                            <td>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                    <?= $safe($r['reason_id'] ?? null) ?>
                                </span>
                            </td>
                            <td class="small text-muted text-uppercase"><?= $safe($r['type'] ?? null) ?></td>
                            <td class="small text-muted"><?= format_datetime($r['date_created'] ?? null) ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($pending) && !$error): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">Nenhuma devolução aberta.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Encerradas -->
    <div class="tab-pane fade" id="pills-history">
        <div class="card border-0 shadow-sm">
             <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Encerrada em</th>
                            <th>Reclamação</th>
                            <th>Pedido/Recurso</th>
                            <th>Motivo</th>
                            <th>Tipo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                        <tr>
                            <td class="ps-4 text-muted small"><?= format_datetime($h['last_updated'] ?? null) ?></td>
                            <td class="fw-bold">#<?= $safe($h['id'] ?? null) ?></td>
                            <td><?= $safe($h['resource_id'] ?? ($h['order_id'] ?? null)) ?></td>
                            <td><?= $safe($h['reason_id'] ?? null) ?></td>
                            <td class="small text-muted text-uppercase"><?= $safe($h['type'] ?? null) ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($history) && !$error): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">Nenhuma devolução encerrada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
             </div>
        </div>
    </div>

</div>
