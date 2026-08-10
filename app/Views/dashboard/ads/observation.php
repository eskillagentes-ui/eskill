<?php

declare(strict_types=1);

/**
 * Painel Ads — observação read-only (Bloco 5).
 * Semáforo: verde ≥ objetivo · amarelo entre breakeven e objetivo · vermelho < breakeven.
 *
 * @var array<string, mixed> $adsObs
 */

$adsObs = $adsObs ?? [
    'read_only' => true,
    'active_campaigns' => 0,
    'has_campaigns' => false,
    'message' => 'nenhuma campanha ativa',
    'tacos' => null,
    'acos' => null,
    'gasto_hoje' => null,
    'sku_custos_count' => 0,
    'campaigns' => [],
    'skus' => [],
    'cpc_health_series' => [],
    'recovery' => [],
];

$nd = static function ($v, ?callable $fmt = null): string {
    if ($v === null || $v === '') {
        return 'n/d';
    }
    return $fmt ? $fmt($v) : (string) $v;
};
$pct = static fn ($v): string => number_format((float) $v, 1, ',', '.') . '%';
$money = static fn ($v): string => 'R$ ' . number_format((float) $v, 2, ',', '.');
$roas = static fn ($v): string => number_format((float) $v, 2, ',', '.') . 'x';

$title = 'Ads · Observação';
$subtitle = 'Read-only — mede TACOS/ACOS/CPC · zero escrita no ML';
$breadcrumbs = [['label' => 'Ads', 'url' => '']];
$actions = '<span class="badge bg-secondary">ML_WRITE_AUTOMATION=false</span>';
include __DIR__ . '/../../layouts/modern/partials/page-header.php';
?>

<style>
.ads-sem { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
.ads-sem.verde { background:#22c55e; }
.ads-sem.amarelo { background:#eab308; }
.ads-sem.vermelho { background:#ef4444; }
.ads-sem.nd { background:#94a3b8; }
.ads-kpi { background:linear-gradient(160deg,#0f172a 0%,#1e293b 60%,#334155 100%); color:#e2e8f0; border-radius:12px; padding:1.1rem 1.25rem; }
.ads-kpi .lb { font-size:.72rem; letter-spacing:.06em; text-transform:uppercase; color:#94a3b8; }
.ads-kpi .vl { font-size:1.55rem; font-weight:700; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.ads-table td, .ads-table th { vertical-align: middle; font-size: .9rem; }
#cpcHealthChart { max-height: 320px; }
</style>

<div class="alert alert-info border-0 mb-4">
    <strong>Modo observação.</strong>
    Este painel não cria, pausa nem altera campanha/orçamento/ROAS.
    Automação de Ads só após ≥2 semanas de observação com alçadas e aprovação do dono.
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="ads-kpi h-100">
            <div class="lb">TACOS</div>
            <div class="vl"><?= htmlspecialchars($nd($adsObs['tacos'] ?? null, $pct)) ?></div>
            <div class="small text-secondary">gasto ÷ receita total</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ads-kpi h-100">
            <div class="lb">ACOS</div>
            <div class="vl"><?= htmlspecialchars($nd($adsObs['acos'] ?? null, $pct)) ?></div>
            <div class="small text-secondary">gasto ÷ receita atribuída</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ads-kpi h-100">
            <div class="lb">Gasto hoje</div>
            <div class="vl"><?= htmlspecialchars($nd($adsObs['gasto_hoje'] ?? null, $money)) ?></div>
            <div class="small text-secondary">campanhas ativas: <?= (int) ($adsObs['active_campaigns'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ads-kpi h-100">
            <div class="lb">SKUs com custo</div>
            <div class="vl"><?= (int) ($adsObs['sku_custos_count'] ?? 0) ?></div>
            <div class="small text-secondary">sem custo → trio ROAS = n/d</div>
        </div>
    </div>
</div>

<?php if (empty($adsObs['active_campaigns'])): ?>
    <div class="alert alert-warning">nenhuma campanha ativa — métricas em n/d</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Campanhas</div>
    <div class="table-responsive">
        <table class="table ads-table mb-0">
            <thead>
                <tr>
                    <th></th>
                    <th>Campanha</th>
                    <th>Status</th>
                    <th>Gasto</th>
                    <th>Impressões</th>
                    <th>Cliques</th>
                    <th>CPC</th>
                    <th>Vendas</th>
                    <th>ACOS</th>
                    <th>ROAS real</th>
                    <th>ROAS obj.</th>
                </tr>
            </thead>
            <tbody>
            <?php if (($adsObs['campaigns'] ?? []) === []): ?>
                <tr><td colspan="11" class="text-muted text-center py-4">Sem dados de campanha</td></tr>
            <?php else: ?>
                <?php foreach ($adsObs['campaigns'] as $c): ?>
                    <tr>
                        <td><span class="ads-sem <?= htmlspecialchars((string) ($c['semaforo'] ?? 'nd')) ?>"></span></td>
                        <td><code><?= htmlspecialchars((string) $c['campaign_id']) ?></code></td>
                        <td><?= htmlspecialchars((string) ($c['status'] ?? 'n/d')) ?></td>
                        <td><?= htmlspecialchars($nd($c['gasto'] ?? null, $money)) ?></td>
                        <td><?= htmlspecialchars($nd($c['impressoes'] ?? null)) ?></td>
                        <td><?= htmlspecialchars($nd($c['cliques'] ?? null)) ?></td>
                        <td><?= htmlspecialchars($nd($c['cpc'] ?? null, $money)) ?></td>
                        <td><?= htmlspecialchars($nd($c['vendas_atribuidas'] ?? null)) ?></td>
                        <td><?= htmlspecialchars($nd($c['acos'] ?? null, $pct)) ?></td>
                        <td><?= htmlspecialchars($nd($c['roas_real'] ?? null, $roas)) ?></td>
                        <td><?= htmlspecialchars($nd($c['roas_objetivo'] ?? null, $roas)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">SKUs (7 dias)</div>
    <div class="table-responsive">
        <table class="table ads-table mb-0">
            <thead>
                <tr>
                    <th></th>
                    <th>SKU</th>
                    <th>Gasto</th>
                    <th>CPC</th>
                    <th>Vendas</th>
                    <th>ACOS</th>
                    <th>ROAS real</th>
                    <th>Breakeven</th>
                    <th>Objetivo</th>
                    <th>Escala</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (($adsObs['skus'] ?? []) === []): ?>
                <tr><td colspan="11" class="text-muted text-center py-4">Sem métricas por SKU (campanha sem items no payload ou sem coleta)</td></tr>
            <?php else: ?>
                <?php foreach ($adsObs['skus'] as $s): ?>
                    <tr>
                        <td><span class="ads-sem <?= htmlspecialchars((string) ($s['semaforo'] ?? 'nd')) ?>"></span></td>
                        <td><code><?= htmlspecialchars((string) $s['mlb_id']) ?></code></td>
                        <td><?= htmlspecialchars($nd($s['gasto'] ?? null, $money)) ?></td>
                        <td><?= htmlspecialchars($nd($s['cpc'] ?? null, $money)) ?></td>
                        <td><?= htmlspecialchars($nd($s['vendas_atribuidas'] ?? null)) ?></td>
                        <td><?= htmlspecialchars($nd($s['acos'] ?? null, $pct)) ?></td>
                        <td><?= htmlspecialchars($nd($s['roas_real'] ?? null, $roas)) ?></td>
                        <td><?= htmlspecialchars($nd($s['roas_breakeven'] ?? null, $roas)) ?></td>
                        <td><?= htmlspecialchars($nd($s['roas_objetivo'] ?? null, $roas)) ?></td>
                        <td><?= htmlspecialchars($nd($s['roas_escala'] ?? null, $roas)) ?></td>
                        <td><?= !empty($s['has_custo']) ? 'custo ok' : 'custo n/d' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Círculo virtuoso — CPC × health (por SKU)</div>
    <div class="card-body">
        <canvas id="cpcHealthChart" height="120"></canvas>
        <p class="small text-muted mb-0 mt-2">Prova visual: qualidade do anúncio derruba CPC. Sem série → n/d.</p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Fila de recomendações (Onda 4 — observação → recomendação)</span>
        <span class="badge bg-secondary">execução desabilitada</span>
    </div>
    <div class="card-body py-2 small text-muted">
        Ordenada por desperdício R$.
        Resumo: <?= (int) ($adsReadiness['summary']['total'] ?? 0) ?> itens ·
        pausar <?= (int) ($adsReadiness['summary']['pausar'] ?? 0) ?> ·
        reduzir <?= (int) ($adsReadiness['summary']['reduzir_lance'] ?? 0) ?> ·
        waste ≈ <?= htmlspecialchars($money($adsReadiness['summary']['waste_brl'] ?? 0), ENT_QUOTES, 'UTF-8') ?>.
    </div>
    <div class="table-responsive">
        <table class="table ads-table mb-0">
            <thead>
                <tr>
                    <th>MLB</th>
                    <th>Ação</th>
                    <th>Waste R$</th>
                    <th>ACOS</th>
                    <th>Margem</th>
                    <th>CMV</th>
                    <th>Justificativa</th>
                    <th>Executar</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $adsReadiness = $adsReadiness ?? ['recommendations' => [], 'summary' => []];
            $recs = $adsReadiness['recommendations'] ?? [];
            ?>
            <?php if ($recs === []): ?>
                <tr><td colspan="8" class="text-muted text-center py-4">Sem recomendações — sem gasto Ads no período agregado.</td></tr>
            <?php else: ?>
                <?php foreach ($recs as $rec): ?>
                    <tr>
                        <td><code><?= htmlspecialchars((string) $rec['mlb_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><strong><?= htmlspecialchars((string) $rec['action'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td><?= htmlspecialchars($money($rec['waste_brl'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($nd($rec['acos'] ?? null, $pct), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($nd($rec['margem_bruta_pct'] ?? null, $pct), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if (!empty($rec['has_real_cogs'])): ?>
                                <span class="badge bg-success">real</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">estimado</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= htmlspecialchars(implode(' · ', $rec['reasons'] ?? []), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                title="<?= htmlspecialchars((string) ($rec['execute_tooltip'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                Executar
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Recuperação em curso — sucessores de catálogo</div>
    <div class="table-responsive">
        <table class="table ads-table mb-0">
            <thead>
                <tr>
                    <th>Sucessor</th>
                    <th>Predecessor</th>
                    <th>Dias desde marco</th>
                    <th>Visitas/dia antes</th>
                    <th>Visitas/dia agora</th>
                    <th>Vendas</th>
                    <th>Gasto acum.</th>
                    <th>ROAS</th>
                </tr>
            </thead>
            <tbody>
            <?php if (($adsObs['recovery'] ?? []) === []): ?>
                <tr><td colspan="8" class="text-muted text-center py-4">Sem marcos registrados</td></tr>
            <?php else: ?>
                <?php foreach ($adsObs['recovery'] as $r): ?>
                    <tr>
                        <td><code><?= htmlspecialchars((string) $r['mlb_id']) ?></code></td>
                        <td><code><?= htmlspecialchars((string) ($r['predecessor_mlb_id'] ?? 'n/d')) ?></code></td>
                        <td><?= htmlspecialchars($nd($r['dias_desde_marco'] ?? null)) ?></td>
                        <td><?= htmlspecialchars($nd($r['visitas_dia_antes'] ?? null)) ?></td>
                        <td><?= htmlspecialchars($nd($r['visitas_dia_agora'] ?? null)) ?></td>
                        <td><?= htmlspecialchars($nd($r['vendas'] ?? null)) ?></td>
                        <td><?= htmlspecialchars($nd($r['gasto_acumulado'] ?? null, $money)) ?></td>
                        <td><?= htmlspecialchars($nd($r['roas'] ?? null, $roas)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const series = <?= json_encode($adsObs['cpc_health_series'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const keys = Object.keys(series || {});
    const ctx = document.getElementById('cpcHealthChart');
    if (!ctx || !keys.length) {
        if (ctx) {
            const p = document.createElement('p');
            p.className = 'text-muted mb-0';
            p.textContent = 'n/d — sem série CPC/health ainda';
            ctx.replaceWith(p);
        }
        return;
    }
    const mlb = keys[0];
    const points = series[mlb] || [];
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: points.map(p => p.date),
            datasets: [
                {
                    label: 'CPC ' + mlb,
                    data: points.map(p => p.cpc),
                    borderColor: '#f97316',
                    yAxisID: 'y',
                    tension: 0.25
                },
                {
                    label: 'Health ' + mlb,
                    data: points.map(p => p.health),
                    borderColor: '#22c55e',
                    yAxisID: 'y1',
                    tension: 0.25
                }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { type: 'linear', position: 'left', title: { display: true, text: 'CPC (R$)' } },
                y1: { type: 'linear', position: 'right', min: 0, max: 1, grid: { drawOnChartArea: false }, title: { display: true, text: 'Health' } }
            }
        }
    });
})();
</script>
