<link rel="stylesheet" href="/css/pregao.css?v=4">
<link rel="stylesheet" href="/css/sentinela.css?v=2">

<?php
/** @var array $dash */
/** @var int|null $sentinelaAccountId */
$semaforo = (string) ($dash['semaforo'] ?? 'verde');
$risks = is_array($dash['risks'] ?? null) ? $dash['risks'] : [];
$history = is_array($dash['history'] ?? null) ? $dash['history'] : [];
$monitored = (int) ($dash['monitored'] ?? 0);
$total = (int) ($dash['total'] ?? 11);

$statusClass = static function (string $s): string {
    return match ($s) {
        'vermelho' => 'st-red',
        'amarelo' => 'st-yellow',
        'verde' => 'st-green',
        default => 'st-nd',
    };
};

$fmtVal = static function (array $r): string {
    if (($r['status'] ?? '') === 'nd' || ($r['value_text'] ?? null) === null || ($r['value_text'] ?? '') === '') {
        return 'n/d';
    }
    return (string) $r['value_text'];
};

$fmtLimit = static function (array $r): string {
    if (($r['limit_num'] ?? null) === null) {
        return 'n/d';
    }
    $unit = '';
    if (in_array($r['risk_key'] ?? '', ['reclamacoes', 'atrasos', 'cancelamentos', 'catalogo', 'queda_vendas'], true)) {
        $unit = '%';
    }
    return number_format((float) $r['limit_num'], 2, ',', '.') . $unit;
};

$spark = static function (array $series): string {
    if ($series === []) {
        return '<span class="spark-empty">sem histórico</span>';
    }
    $vals = [];
    foreach ($series as $p) {
        $vals[] = $p['pct_of_limit'] !== null ? (float) $p['pct_of_limit'] : ($p['value_num'] !== null ? (float) $p['value_num'] : 0.0);
    }
    $max = max(1.0, max($vals));
    $w = 120;
    $h = 28;
    $n = count($vals);
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = $n === 1 ? 0 : ($i / ($n - 1)) * $w;
        $y = $h - (($v / $max) * ($h - 2));
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true"><polyline fill="none" stroke="currentColor" stroke-width="1.5" points="' . htmlspecialchars(implode(' ', $pts)) . '"/></svg>';
};
?>

<div id="sentinela-root" data-account-id="<?= (int) ($sentinelaAccountId ?? 0) ?>" data-read-only="1">
    <header class="pg-header">
        <div class="brand">
            <div class="dot"></div>
            <h1>ESKILL <span>SENTINELA</span></h1>
        </div>
        <div class="ticker-main">
            <span class="sym"><?= (int) $monitored ?> DE <?= (int) $total ?> MONITORADOS</span>
            <span class="px" id="semaforoLabel"><?= htmlspecialchars(strtoupper($semaforo)) ?></span>
        </div>
        <div class="spacer"></div>
        <div class="sema st-<?= htmlspecialchars($semaforo) ?>">
            <div class="s"></div>
            <span>SEMÁFORO <?= htmlspecialchars(strtoupper($semaforo)) ?></span>
        </div>
        <a class="sn-back" href="/dashboard/pregao">← Pregão</a>
    </header>

    <div class="sn-note">
        Observador read-only · limiar de alerta a 50% do limite ML · zero escrita no Mercado Livre
    </div>

    <div class="sn-gates">
        <?php
        $podeExpandir = (bool) ($dash['pode_expandir'] ?? false);
        $podeReparar = (bool) ($dash['pode_reparar'] ?? true);
        $motivoVeto = (string) ($dash['motivo_veto'] ?? '');
        ?>
        <div class="sn-gate <?= $podeExpandir ? 'ok' : 'blocked' ?>">
            <span class="sn-gate-lb">Expansão</span>
            <span class="sn-gate-vl">
                <?php if ($podeExpandir): ?>
                    LIBERADA
                <?php else: ?>
                    BLOQUEADA<?= $motivoVeto !== '' ? ' (' . htmlspecialchars($motivoVeto) . ')' : '' ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="sn-gate <?= $podeReparar ? 'ok' : 'blocked' ?>">
            <span class="sn-gate-lb">Reparo</span>
            <span class="sn-gate-vl"><?= $podeReparar ? 'LIBERADO' : 'BLOQUEADO (conta suspensa/bloqueada)' ?></span>
        </div>
    </div>

    <div class="sn-grid">
        <div class="sn-table-wrap panel">
            <div class="p-head">GRADE DE RISCOS · ordenada por % do limite</div>
            <table class="sn-table">
                <thead>
                    <tr>
                        <th>Risco</th>
                        <th>Valor</th>
                        <th>Limite</th>
                        <th>% limite</th>
                        <th>Cor</th>
                        <th>Última coleta</th>
                        <th>30d</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($risks as $r): ?>
                    <?php
                    $st = (string) ($r['status'] ?? 'nd');
                    $pct = $r['pct_of_limit'] ?? null;
                    $key = (string) ($r['risk_key'] ?? '');
                    $hist = is_array($history[$key] ?? null) ? $history[$key] : [];
                    ?>
                    <tr class="<?= $statusClass($st) ?>">
                        <td>
                            <div class="sn-risk-name"><?= htmlspecialchars((string) ($r['label'] ?? $key)) ?></div>
                            <div class="sn-risk-reason"><?= htmlspecialchars((string) ($r['reason'] ?? '')) ?></div>
                        </td>
                        <td class="mono"><?= htmlspecialchars($fmtVal($r)) ?></td>
                        <td class="mono"><?= htmlspecialchars($fmtLimit($r)) ?></td>
                        <td class="mono"><?= $pct === null ? 'n/d' : htmlspecialchars(number_format((float) $pct, 1, ',', '.') . '%') ?></td>
                        <td><span class="pill <?= $statusClass($st) ?>"><?= htmlspecialchars($st === 'nd' ? 'n/d' : $st) ?></span></td>
                        <td class="mono mut"><?= htmlspecialchars(format_datetime($r['collected_at'] ?? null, 'd/m/Y H:i:s')) ?></td>
                        <td class="spark-cell"><?= $spark($hist) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <footer class="pg-foot">ESKILL SENTINELA · read-only · NF pendente = n/d até definição do emissor</footer>
</div>
