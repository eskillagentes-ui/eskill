<?php

declare(strict_types=1);

/**
 * Governança de Escrita ML — fail-closed / dry-run (Onda 4).
 * @var bool $killSwitch
 * @var array<string, bool> $flags
 * @var list<string> $allowlist
 * @var list<array<string, mixed>> $audit
 * @var array<string, mixed> $checklist
 * @var int $daily
 * @var int $maxDaily
 * @var int $accountId
 * @var array<string, mixed> $rankStatus
 */
$title = 'Governança de Escrita';
$subtitle = 'Kill switch, flags por ação, allowlist, auditoria dry-run e checklist 7/7 para habilitação futura.';
include __DIR__ . '/../layouts/modern/partials/page-header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Kill switch (ML_WRITE_AUTOMATION)</div>
            <div class="h4 mb-0 <?= $killSwitch ? 'text-danger' : 'text-success' ?>">
                <?= $killSwitch ? 'ON (perigoso)' : 'OFF' ?>
            </div>
            <div class="form-text">Somente leitura do .env — não editável aqui.</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Dry-run default</div>
            <div class="h4 mb-0 text-primary">ATIVO</div>
            <div class="form-text">Onda 4: nenhuma chamada de escrita à API.</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Escritas hoje (audit)</div>
            <div class="h4 mb-0"><?= (int) $daily ?> / <?= (int) $maxDaily ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Checklist 7/7</div>
            <div class="h4 mb-0"><?= (int) ($checklist['passed'] ?? 0) ?> / <?= (int) ($checklist['total'] ?? 7) ?></div>
            <div class="form-text"><?= htmlspecialchars((string) ($checklist['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Flags por ação</strong> (default false)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($flags as $name => $on): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <code><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></code>
                    <span class="badge <?= $on ? 'bg-danger' : 'bg-secondary' ?>"><?= $on ? 'true' : 'false' ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="card-body small text-muted">
                Toggle protegido: só via .env após checklist 7/7 + confirmação explícita do dono (Onda 5).
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Allowlist de MLBs</strong></div>
            <div class="card-body">
                <?php if ($allowlist === []): ?>
                    <p class="text-muted mb-0">Nenhum MLB liberado — toda escrita seria bloqueada na guarda de allowlist.</p>
                <?php else: ?>
                    <ul class="mb-0">
                        <?php foreach ($allowlist as $mlb): ?>
                            <li><code><?= htmlspecialchars($mlb, ENT_QUOTES, 'UTF-8') ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Checklist de prontidão (Onda 5)</strong>
        <span class="badge bg-<?= (($checklist['can_enable_flags'] ?? false) ? 'success' : 'warning text-dark') ?>">
            <?= (int) ($checklist['passed'] ?? 0) ?>/7
        </span>
    </div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead><tr><th>Critério</th><th>Estado</th><th>Valor</th></tr></thead>
            <tbody>
            <?php foreach (($checklist['items'] ?? []) as $item): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if (!empty($item['pass'])): ?>
                            <span class="badge bg-success">OK</span>
                        <?php else: ?>
                            <span class="badge bg-danger">PENDENTE</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars((string) ($item['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><strong>Rank tracker</strong></div>
    <div class="card-body small">
        <div>Status Pregão: <strong><?= htmlspecialchars((string) ($rankStatus['label'] ?? 'n/d'), ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div class="text-muted"><?= htmlspecialchars((string) ($rankStatus['cause_doc'] ?? $rankStatus['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="mt-2 text-muted">Ver <code>docs/ops/RANK_TRACKER.md</code> — causa da desativação (403 sites/search, não scraping).</div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><strong>Auditoria (dry-run / bloqueios)</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Quando</th><th>Ação</th><th>MLB</th><th>Resultado</th><th>Bloqueio</th><th>API?</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($audit === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Sem entradas ainda — rode um dry-run de demonstração.</td></tr>
            <?php else: ?>
                <?php foreach ($audit as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><code><?= htmlspecialchars((string) ($row['action'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><code><?= htmlspecialchars((string) ($row['mlb_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars((string) ($row['result'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($row['blocked_by'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= !empty($row['api_called']) ? 'sim' : 'não' ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
