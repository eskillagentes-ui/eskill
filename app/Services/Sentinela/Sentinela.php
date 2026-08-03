<?php

declare(strict_types=1);

namespace App\Services\Sentinela;

use App\Database;
use App\Services\MercadoLivreClient;
use App\Services\Pregao\PregaoEmitService;
use PDO;
use Redis;
use Throwable;

/**
 * Sentinela — observador read-only dos riscos de bloqueio de conta.
 *
 * Nunca escreve no Mercado Livre. Emite `op` só em transição de estado.
 */
final class Sentinela
{
    public const ROBOT = 'SENTINELA';

    public const RISK_KEYS = [
        'reputacao',
        'reclamacoes',
        'atrasos',
        'cancelamentos',
        'moderacao',
        'catalogo',
        'chargeback',
        'oauth',
        'rate_limit',
        'nf_pendente',
        'queda_vendas',
    ];

    private PDO $db;
    private PregaoEmitService $emitter;
    private ?Redis $redis = null;
    private bool $redisTried = false;

    /** @var array<string, array<string, mixed>> */
    private array $defs;

    public function __construct(?PDO $db = null, ?PregaoEmitService $emitter = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->emitter = $emitter ?? new PregaoEmitService($this->db);
        $path = dirname(__DIR__, 3) . '/config/sentinela.php';
        /** @var array<string, array<string, mixed>> $defs */
        $defs = is_file($path) ? require $path : [];
        $this->defs = $defs;
    }

    /**
     * Poder de veto para Criador/Otimizador (ainda não consumido por outros robôs).
     */
    public function podeExpandir(int $accountId): bool
    {
        return $this->evaluateSemaforo($this->listRisks($accountId)) === 'verde';
    }

    public function motivoVeto(int $accountId): ?string
    {
        $risks = $this->listRisks($accountId);
        if ($this->evaluateSemaforo($risks) === 'verde') {
            return null;
        }
        $bloqueadores = array_values(array_filter(
            $risks,
            static fn (array $r): bool => in_array($r['status'], ['amarelo', 'vermelho'], true)
        ));
        usort($bloqueadores, static function (array $a, array $b): int {
            $rank = ['vermelho' => 2, 'amarelo' => 1];
            $cmp = ($rank[$b['status']] ?? 0) <=> ($rank[$a['status']] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((float) ($b['pct_of_limit'] ?? 0)) <=> ((float) ($a['pct_of_limit'] ?? 0));
        });
        if ($bloqueadores === []) {
            return 'Semáforo Sentinela não-verde';
        }
        $top = $bloqueadores[0];
        return sprintf(
            'Veto Sentinela (%s): %s — %s',
            (string) $top['status'],
            (string) $top['label'],
            (string) ($top['reason'] ?? 'limiar atingido')
        );
    }

    /**
     * @return 'verde'|'amarelo'|'vermelho'
     */
    public function semaforoGlobal(int $accountId): string
    {
        return $this->evaluateSemaforo($this->listRisks($accountId));
    }

    /**
     * Avalia semáforo a partir da grade de riscos (puro — testável sem DB).
     *
     * @param list<array<string, mixed>> $risks
     * @return 'verde'|'amarelo'|'vermelho'
     */
    public function evaluateSemaforo(array $risks): string
    {
        $worst = 'verde';
        foreach ($risks as $r) {
            $status = (string) ($r['status'] ?? 'nd');
            if ($status === 'nd') {
                continue;
            }
            $pct = $r['pct_of_limit'] ?? null;
            if ($status === 'vermelho' || ($pct !== null && (float) $pct > 80.0)) {
                return 'vermelho';
            }
            if ($status === 'amarelo' || ($pct !== null && (float) $pct >= 50.0)) {
                $worst = 'amarelo';
            }
        }
        return $worst;
    }

    /**
     * Coleta todos os riscos (read-only) e persiste estado + diário.
     *
     * @return array{ok: bool, risks: list<array<string, mixed>>, semaforo: string, monitored: int}
     */
    public function collect(int $accountId): array
    {
        $results = [];
        $results[] = $this->collectReputacao($accountId);
        $results[] = $this->collectPctRisk($accountId, 'reclamacoes', 'reclamacoes_pct');
        $results[] = $this->collectPctRisk($accountId, 'atrasos', 'atrasos_pct');
        $results[] = $this->collectPctRisk($accountId, 'cancelamentos', 'cancelamentos_pct');
        $results[] = $this->collectModeracao($accountId);
        $results[] = $this->collectCatalogo($accountId);
        $results[] = $this->collectChargeback($accountId);
        $results[] = $this->collectOauth($accountId);
        $results[] = $this->collectRateLimit($accountId);
        $results[] = $this->collectNfPendente($accountId);
        $results[] = $this->collectQuedaVendas($accountId);

        foreach ($results as $row) {
            $this->persistRisk($accountId, $row);
            $this->emitForRisk($accountId, $row);
        }

        $semaforo = $this->semaforoGlobal($accountId);
        $monitored = count(array_filter(
            $results,
            static fn (array $r): bool => ($r['status'] ?? 'nd') !== 'nd' || ($r['risk_key'] ?? '') !== 'nf_pendente'
        ));
        // monitorados = riscos com coletor ativo (todos exceto NF que é placeholder honesto)
        $monitored = 10;

        $this->emitter->emit('metric.update', [
            'key' => 'sentinela',
            'value' => [
                'semaforo' => $semaforo,
                'monitored' => $monitored,
                'total' => 11,
                'pode_expandir' => $semaforo === 'verde',
            ],
            'flash' => $semaforo === 'vermelho' ? 'yellow' : ($semaforo === 'amarelo' ? 'yellow' : 'green'),
        ], $accountId, 'live');

        $this->emitter->emitOpOnTransition(
            'sentinela.semaforo',
            [
                'robot' => self::ROBOT,
                'level' => $semaforo === 'verde' ? 'info' : 'alert',
                'icon' => $semaforo === 'verde' ? '🟢' : ($semaforo === 'amarelo' ? '🟡' : '🔴'),
                'msg' => sprintf('SENTINELA — semáforo %s · %d de 11 monitorados', $semaforo, $monitored),
            ],
            ['semaforo' => $semaforo],
            $accountId,
            'live'
        );

        return [
            'ok' => true,
            'risks' => $results,
            'semaforo' => $semaforo,
            'monitored' => $monitored,
        ];
    }

    /**
     * Reage a webhook (items/claims/orders_v2) com recoleta leve — sem escrita ML.
     */
    public function onWebhook(int $accountId, string $topic): void
    {
        $topic = strtolower(trim($topic));
        if (!in_array($topic, ['items', 'claims', 'orders_v2', 'orders'], true)) {
            return;
        }
        try {
            if ($topic === 'items') {
                $this->persistRisk($accountId, $this->collectModeracao($accountId));
                $this->persistRisk($accountId, $this->collectCatalogo($accountId));
            } elseif ($topic === 'claims') {
                $this->persistRisk($accountId, $this->collectChargeback($accountId));
                $this->persistRisk($accountId, $this->collectPctRisk($accountId, 'reclamacoes', 'reclamacoes_pct'));
            } else {
                $this->persistRisk($accountId, $this->collectQuedaVendas($accountId));
            }
        } catch (Throwable $e) {
            // observador: falha de webhook não derruba inbox
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRisks(int $accountId): array
    {
        if (!$this->tableExists('sentinela_risk_state')) {
            return $this->emptyRiskGrid();
        }
        $stmt = $this->db->prepare(
            'SELECT risk_key, label, value_num, value_text, limit_num, pct_of_limit, status, reason, source, meta, collected_at
             FROM sentinela_risk_state WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $byKey = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byKey[(string) $row['risk_key']] = $this->normalizeRow($row);
        }
        $out = [];
        foreach (self::RISK_KEYS as $key) {
            $out[] = $byKey[$key] ?? $this->placeholderRisk($key);
        }
        usort($out, static function (array $a, array $b): int {
            $pa = $a['pct_of_limit'];
            $pb = $b['pct_of_limit'];
            if ($pa === null && $pb === null) {
                $rank = ['vermelho' => 3, 'amarelo' => 2, 'verde' => 1, 'nd' => 0];
                return ($rank[$b['status']] ?? 0) <=> ($rank[$a['status']] ?? 0);
            }
            if ($pa === null) {
                return 1;
            }
            if ($pb === null) {
                return -1;
            }
            return (float) $pb <=> (float) $pa;
        });
        return $out;
    }

    /**
     * @return array{
     *   semaforo: string,
     *   monitored: int,
     *   total: int,
     *   pode_expandir: bool,
     *   motivo_veto: ?string,
     *   risks: list<array<string, mixed>>,
     *   history: array<string, list<array{date: string, value_num: ?float, pct_of_limit: ?float, status: string}>>
     * }
     */
    public function getDashboard(int $accountId): array
    {
        $risks = $this->listRisks($accountId);
        $monitored = 0;
        foreach ($risks as $r) {
            if (($r['risk_key'] ?? '') === 'nf_pendente') {
                continue;
            }
            if (($r['status'] ?? 'nd') !== 'nd' || ($r['collected_at'] ?? null) !== null) {
                $monitored++;
            }
        }
        if ($monitored === 0) {
            // ainda sem coleta: 10 têm coletor, NF é n/d
            $monitored = 0;
        }
        $semaforo = $this->semaforoGlobal($accountId);
        return [
            'semaforo' => $semaforo,
            'monitored' => min(10, max($monitored, $this->countCollected($accountId))),
            'total' => 11,
            'pode_expandir' => $semaforo === 'verde',
            'motivo_veto' => $this->motivoVeto($accountId),
            'risks' => $risks,
            'history' => $this->history30d($accountId),
        ];
    }

    /** Resumo para card do Pregão. */
    public function getSummaryCard(int $accountId): array
    {
        $dash = $this->getDashboard($accountId);
        return [
            'available' => $this->tableExists('sentinela_risk_state') && $this->countCollected($accountId) > 0,
            'semaforo' => $dash['semaforo'],
            'monitored' => $dash['monitored'],
            'total' => 11,
            'label' => sprintf(
                'SENTINELA — %d de 11 monitorados · semáforo %s',
                $dash['monitored'],
                $dash['semaforo']
            ),
            'href' => '/dashboard/sentinela',
            'pode_expandir' => $dash['pode_expandir'],
        ];
    }

    // ─── Coletores ───────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function collectReputacao(int $accountId): array
    {
        $def = $this->defs['reputacao'];
        $stmt = $this->db->prepare('SELECT reputacao_cor FROM account_index_metrics WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $cor = $stmt->fetchColumn();
        if ($cor === false || $cor === null || $cor === '') {
            return $this->riskResult('reputacao', null, null, 'nd', 'sem dado de reputação', 'account_index_metrics');
        }
        $cor = (string) $cor;
        $status = 'verde';
        $reason = 'reputação ' . $cor;
        $pct = 0.0;
        if (str_contains($cor, 'amarelo') || str_contains($cor, 'yellow') || str_contains($cor, 'laranja')) {
            $status = 'amarelo';
            $pct = 60.0;
            $reason = 'queda de nível: ' . $cor;
        }
        if (str_contains($cor, 'vermelho') || str_contains($cor, 'red') || $cor === 'naranja_real') {
            $status = 'vermelho';
            $pct = 100.0;
            $reason = 'reputação crítica: ' . $cor;
        }
        return $this->riskResult('reputacao', null, $cor, $status, $reason, 'seller_reputation', null, $pct);
    }

    /** @return array<string, mixed> */
    private function collectPctRisk(int $accountId, string $riskKey, string $column): array
    {
        $def = $this->defs[$riskKey];
        $limit = isset($def['limit']) ? (float) $def['limit'] : null;
        $stmt = $this->db->prepare("SELECT {$column} FROM account_index_metrics WHERE account_id = ?");
        $stmt->execute([$accountId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null) {
            return $this->riskResult($riskKey, null, null, 'nd', 'sem dado — coletor de reputação ainda não rodou', 'account_index_metrics', $limit);
        }
        $value = (float) $raw;
        [$status, $pct, $reason] = $this->statusFromPct($riskKey, $value, $limit);
        return $this->riskResult($riskKey, $value, $this->fmtPct($value), $status, $reason, 'seller_reputation.metrics', $limit, $pct);
    }

    /** @return array<string, mixed> */
    private function collectModeracao(int $accountId): array
    {
        $def = $this->defs['moderacao'];
        $limit = (float) ($def['limit'] ?? 3);
        $stmt = $this->db->prepare(
            "SELECT ml_item_id, status, permalink, sold_quantity,
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.sub_status')) AS sub_status
             FROM ml_items
             WHERE account_id = ?
               AND status = 'under_review'
             ORDER BY sold_quantity DESC
             LIMIT 20"
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($rows);
        $top = $rows[0] ?? null;
        $meta = ['items' => array_map(static function (array $r): array {
            return [
                'mlb' => $r['ml_item_id'],
                'status' => $r['status'],
                'permalink' => $r['permalink'],
                'sold_quantity' => (int) $r['sold_quantity'],
            ];
        }, $rows)];

        if ($count === 0) {
            return $this->riskResult('moderacao', 0.0, '0 itens', 'verde', 'nenhum anúncio em moderação punitiva', 'ml_items', $limit, 0.0, $meta);
        }
        $status = $count >= 3 || ((int) ($top['sold_quantity'] ?? 0) > 0 && $count >= 1) ? 'vermelho' : 'amarelo';
        if ($count === 1 && (int) ($top['sold_quantity'] ?? 0) === 0) {
            $status = 'amarelo';
        }
        $pct = min(100.0, ($count / max(1.0, $limit)) * 100.0);
        if ($status === 'vermelho') {
            $pct = max($pct, 85.0);
        } elseif ($status === 'amarelo') {
            $pct = max($pct, 55.0);
        }
        $link = (string) ($top['permalink'] ?? '');
        $reason = sprintf(
            '%d anúncio(s) sob moderação%s',
            $count,
            $link !== '' ? ' · ' . ($top['ml_item_id'] ?? '') : ''
        );
        return $this->riskResult('moderacao', (float) $count, (string) $count, $status, $reason, 'ml_items+webhook items', $limit, $pct, $meta);
    }

    /** @return array<string, mixed> */
    private function collectCatalogo(int $accountId): array
    {
        $def = $this->defs['catalogo'];
        $limit = (float) ($def['limit'] ?? 5);
        $stmtTotal = $this->db->prepare(
            'SELECT COUNT(*) FROM ml_items WHERE account_id = ? AND catalog_product_id IS NOT NULL AND catalog_product_id != \'\''
        );
        $stmtTotal->execute([$accountId]);
        $totalCat = (int) $stmtTotal->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT ml_item_id, status, permalink, sold_quantity, catalog_product_id
             FROM ml_items
             WHERE account_id = ?
               AND status IN ('active', 'paused', 'inactive', 'under_review')
               AND (
                 data LIKE '%waiting_for_patch%'
                 OR data LIKE '%OPT_OBEY%'
               )
             ORDER BY sold_quantity DESC
             LIMIT 30"
        );
        $stmt->execute([$accountId]);
        $affected = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($affected);
        $pctItems = $totalCat > 0 ? round(($count / $totalCat) * 100, 2) : ($count > 0 ? 100.0 : 0.0);

        $topSold = (int) ($affected[0]['sold_quantity'] ?? 0);
        $meta = [
            'affected_count' => $count,
            'catalog_total' => $totalCat,
            'pct_catalog' => $pctItems,
            'visitas_dia_em_jogo' => null,
            'sold_quantity_top' => $topSold,
            'items' => array_slice(array_map(static fn (array $r): array => [
                'mlb' => $r['ml_item_id'],
                'status' => $r['status'],
                'permalink' => $r['permalink'],
                'sold_quantity' => (int) $r['sold_quantity'],
            ], $affected), 0, 5),
            'note' => 'visitas/dia por item indisponíveis localmente — sold_quantity como proxy de volume',
        ];

        if ($count === 0) {
            return $this->riskResult('catalogo', 0.0, '0%', 'verde', 'sem bloqueio/waiting_for_patch detectado', 'ml_items', $limit, 0.0, $meta);
        }

        $status = 'amarelo';
        $pctOfLimit = 55.0;
        if ($pctItems > 5.0 || $topSold > 10) {
            $status = 'vermelho';
            $pctOfLimit = 90.0;
        }
        $reason = sprintf(
            '%d item(ns) de catálogo afetado(s) (%.1f%%) · top sold_qty=%d · visitas/dia n/d',
            $count,
            $pctItems,
            $topSold
        );
        return $this->riskResult('catalogo', $pctItems, $this->fmtPct($pctItems), $status, $reason, 'ml_items tags/status', $limit, $pctOfLimit, $meta);
    }

    /** @return array<string, mixed> */
    private function collectChargeback(int $accountId): array
    {
        $def = $this->defs['chargeback'];
        $limit = (float) ($def['limit'] ?? 1);
        $open = 0;
        $source = 'post-purchase/claims';
        $meta = [];

        // 1) local
        if ($this->tableExists('ml_claims')) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) FROM ml_claims
                     WHERE account_id = ?
                       AND (status IN ('opened','open') OR stage IN ('dispute','claim'))"
                );
                $stmt->execute([$accountId]);
                $open = (int) $stmt->fetchColumn();
                $source = 'ml_claims';
            } catch (Throwable $e) {
                $open = 0;
            }
        }

        // 2) API read-only (sem escrita)
        if ($open === 0) {
            try {
                $client = new MercadoLivreClient($accountId);
                $res = $client->get('/post-purchase/v1/claims/search', [
                    'status' => 'opened',
                    'stage' => 'dispute',
                    'limit' => 20,
                ]);
                if (isset($res['error']) || (isset($res['status']) && (int) $res['status'] >= 400)) {
                    return $this->riskResult(
                        'chargeback',
                        null,
                        null,
                        'nd',
                        'API claims/dispute indisponível ou sem grant — ' . (string) ($res['message'] ?? $res['error'] ?? 'erro'),
                        'ml_api',
                        $limit,
                        null,
                        ['api' => $res]
                    );
                }
                $results = is_array($res['data'] ?? null) ? ($res['data']['results'] ?? $res['data']) : ($res['results'] ?? []);
                if (!is_array($results)) {
                    $results = [];
                }
                $open = count($results);
                $meta['api_count'] = $open;
                $source = 'ml_api claims/search';
            } catch (Throwable $e) {
                return $this->riskResult(
                    'chargeback',
                    null,
                    null,
                    'nd',
                    'falha ao consultar disputes: ' . $e->getMessage(),
                    'ml_api',
                    $limit
                );
            }
        }

        if ($open === 0) {
            return $this->riskResult('chargeback', 0.0, '0 abertas', 'verde', 'nenhuma disputa/chargeback aberta', $source, $limit, 0.0, $meta);
        }
        return $this->riskResult(
            'chargeback',
            (float) $open,
            (string) $open . ' abertas',
            'amarelo',
            sprintf('%d disputa(s) aberta(s) — investigar prazo', $open),
            $source,
            $limit,
            60.0,
            $meta
        );
    }

    /** @return array<string, mixed> */
    private function collectOauth(int $accountId): array
    {
        $def = $this->defs['oauth'];
        $limit = (float) ($def['limit'] ?? 2);
        $stmt = $this->db->prepare(
            'SELECT status, token_expires_at, refresh_failure_count, last_refresh_error,
                    (refresh_token IS NOT NULL AND refresh_token != \'\') AS has_refresh
             FROM ml_accounts WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($acc === false) {
            return $this->riskResult('oauth', null, null, 'nd', 'conta não encontrada', 'ml_accounts', $limit);
        }
        $failures = (int) ($acc['refresh_failure_count'] ?? 0);
        $statusAcc = strtolower((string) ($acc['status'] ?? ''));
        $expiresAt = $acc['token_expires_at'] ?? null;
        $hoursLeft = null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            $hoursLeft = (strtotime($expiresAt) - time()) / 3600;
        }

        $status = 'verde';
        $reason = 'token saudável';
        $pct = 0.0;
        $value = (float) $failures;

        if ($hoursLeft !== null && $hoursLeft < 2 && $hoursLeft > 0) {
            $status = 'amarelo';
            $pct = 55.0;
            $reason = sprintf('token expira em %.1fh', $hoursLeft);
        }
        if ($failures === 1) {
            $status = 'amarelo';
            $pct = max($pct, 55.0);
            $reason = '1 falha de refresh';
        }
        if ($failures >= 2 || in_array($statusAcc, ['disconnected', 'expired', 'error'], true)) {
            $status = 'vermelho';
            $pct = 100.0;
            $reason = sprintf('OAuth crítico: status=%s falhas=%d', $statusAcc, $failures);
        }
        if ($hoursLeft !== null && $hoursLeft <= 0 && (int) ($acc['has_refresh'] ?? 0) === 0) {
            $status = 'vermelho';
            $pct = 100.0;
            $reason = 'token expirado sem refresh token';
        }
        $err = (string) ($acc['last_refresh_error'] ?? '');
        if (str_contains(strtolower($err), 'invalid_grant')) {
            $status = 'vermelho';
            $pct = 100.0;
            $reason = 'invalid_grant — reautorização manual necessária';
        }

        return $this->riskResult(
            'oauth',
            $value,
            sprintf('falhas=%d · status=%s', $failures, $statusAcc),
            $status,
            $reason,
            'ml_accounts',
            $limit,
            $pct,
            ['hours_left' => $hoursLeft, 'last_error' => $err !== '' ? '[redacted]' : null]
        );
    }

    /** @return array<string, mixed> */
    private function collectRateLimit(int $accountId): array
    {
        $def = $this->defs['rate_limit'];
        $limit = (float) ($def['limit'] ?? 3);
        $count1h = 0;
        $count5m = 0;
        $module = 'unknown';

        if ($this->tableExists('ml_api_logs')) {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM ml_api_logs
                 WHERE ml_account_id = ? AND response_status = 429
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
            );
            $stmt->execute([$accountId]);
            $count1h = (int) $stmt->fetchColumn();

            $stmt5 = $this->db->prepare(
                'SELECT COUNT(*) FROM ml_api_logs
                 WHERE ml_account_id = ? AND response_status = 429
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
            );
            $stmt5->execute([$accountId]);
            $count5m = (int) $stmt5->fetchColumn();

            $modStmt = $this->db->prepare(
                'SELECT endpoint FROM ml_api_logs
                 WHERE ml_account_id = ? AND response_status = 429
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 ORDER BY created_at DESC LIMIT 1'
            );
            $modStmt->execute([$accountId]);
            $ep = $modStmt->fetchColumn();
            if (is_string($ep) && $ep !== '') {
                $module = $ep;
            }
        }

        // Redis window (se o cliente HTTP passar a incrementar)
        $redisCount = $this->redisIncrGet('sentinela:429:' . $accountId);
        if ($redisCount > $count1h) {
            $count1h = $redisCount;
        }

        $status = 'verde';
        $pct = 0.0;
        $reason = '0 respostas 429 na última hora';
        if ($count5m >= 1 || $count1h >= 1) {
            $status = 'amarelo';
            $pct = 55.0;
            $reason = sprintf('%d× 429/1h · módulo %s', $count1h, $module);
        }
        if ($count1h >= 3) {
            $status = 'vermelho';
            $pct = 100.0;
            $reason = sprintf('%d× 429 em 1h · módulo %s', $count1h, $module);
        }

        return $this->riskResult(
            'rate_limit',
            (float) $count1h,
            (string) $count1h . '/1h',
            $status,
            $reason,
            'ml_api_logs',
            $limit,
            $pct,
            ['count_5m' => $count5m, 'module' => $module]
        );
    }

    /** @return array<string, mixed> */
    private function collectNfPendente(int $accountId): array
    {
        return $this->riskResult(
            'nf_pendente',
            null,
            'n/d',
            'nd',
            'n/d — aguardando definição do emissor',
            'none',
            null,
            null,
            ['criterion' => $this->defs['nf_pendente']['criterion'] ?? '']
        );
    }

    /** @return array<string, mixed> */
    private function collectQuedaVendas(int $accountId): array
    {
        $def = $this->defs['queda_vendas'];
        $limit = (float) ($def['limit'] ?? 50);

        $sql = 'SELECT DATE(date_created) AS d, COUNT(*) AS c
                FROM ml_orders
                WHERE ml_account_id = ?
                  AND status NOT IN (\'cancelled\')
                  AND date_created >= DATE_SUB(CURDATE(), INTERVAL 35 DAY)
                GROUP BY DATE(date_created)
                ORDER BY d ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return $this->riskResult('queda_vendas', null, null, 'nd', 'sem pedidos locais para baseline', 'ml_orders', $limit);
        }

        $byDate = [];
        foreach ($rows as $r) {
            $byDate[(string) $r['d']] = (int) $r['c'];
        }

        $today = date('Y-m-d');
        $hour = (int) date('G');
        // Dia ainda em andamento: usar ontem como referência do “dia atual” até 18h
        $refDay = $hour >= 18 ? $today : date('Y-m-d', strtotime('-1 day'));
        $refCount = $byDate[$refDay] ?? 0;

        // média 7d (exclui hoje) vs média 28d anterior (dias -35..-8)
        $sum7 = 0;
        $n7 = 0;
        $sum28 = 0;
        $n28 = 0;
        for ($i = 1; $i <= 7; $i++) {
            $d = date('Y-m-d', strtotime("-{$i} day"));
            if (isset($byDate[$d])) {
                $sum7 += $byDate[$d];
                $n7++;
            }
        }
        for ($i = 8; $i <= 35; $i++) {
            $d = date('Y-m-d', strtotime("-{$i} day"));
            if (isset($byDate[$d])) {
                $sum28 += $byDate[$d];
                $n28++;
            }
        }
        $avg7 = $n7 > 0 ? $sum7 / $n7 : 0.0;
        $avg28 = $n28 > 0 ? $sum28 / $n28 : 0.0;

        $drop7vs28 = 0.0;
        if ($avg28 > 0) {
            $drop7vs28 = max(0.0, (1.0 - ($avg7 / $avg28)) * 100.0);
        }

        $dropRef = 0.0;
        if ($avg7 > 0) {
            $dropRef = max(0.0, (1.0 - ($refCount / $avg7)) * 100.0);
        }

        $status = 'verde';
        $pct = 0.0;
        $reason = sprintf('média 7d %.1f vs baseline 28d %.1f (queda %.0f%%)', $avg7, $avg28, $drop7vs28);

        if ($drop7vs28 >= 25.0) {
            $status = 'amarelo';
            $pct = max(50.0, min(79.0, $drop7vs28));
            $reason = sprintf('vendas/dia em queda (−%.0f%% vs baseline 28d)', $drop7vs28);
        }
        if ($drop7vs28 >= 50.0 || ($avg7 > 0 && $dropRef >= 40.0 && $refCount === 0 && $n7 >= 3)) {
            $status = 'vermelho';
            $pct = max(85.0, min(100.0, max($drop7vs28, $dropRef)));
            $reason = sprintf(
                'queda brusca de vendas (−%.0f%% 7d / dia %s = %d vs média %.1f) — investigar',
                $drop7vs28,
                $refDay,
                $refCount,
                $avg7
            );
        }

        return $this->riskResult(
            'queda_vendas',
            round($drop7vs28, 2),
            sprintf('−%.0f%%', $drop7vs28),
            $status,
            $reason,
            'ml_orders',
            $limit,
            $pct,
            [
                'avg_7d' => round($avg7, 2),
                'avg_28d' => round($avg28, 2),
                'ref_day' => $refDay,
                'ref_count' => $refCount,
                'drop_ref_pct' => round($dropRef, 2),
            ]
        );
    }

    // ─── Persistência / emissão ──────────────────────────────────

    /** @param array<string, mixed> $row */
    private function persistRisk(int $accountId, array $row): void
    {
        if (!$this->tableExists('sentinela_risk_state')) {
            return;
        }
        $metaJson = isset($row['meta']) ? json_encode($row['meta'], JSON_UNESCAPED_UNICODE) : null;
        $this->db->prepare(
            'INSERT INTO sentinela_risk_state
               (account_id, risk_key, label, value_num, value_text, limit_num, pct_of_limit, status, reason, source, meta, collected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               label = VALUES(label),
               value_num = VALUES(value_num),
               value_text = VALUES(value_text),
               limit_num = VALUES(limit_num),
               pct_of_limit = VALUES(pct_of_limit),
               status = VALUES(status),
               reason = VALUES(reason),
               source = VALUES(source),
               meta = VALUES(meta),
               collected_at = VALUES(collected_at)'
        )->execute([
            $accountId,
            $row['risk_key'],
            $row['label'],
            $row['value_num'],
            $row['value_text'],
            $row['limit_num'],
            $row['pct_of_limit'],
            $row['status'],
            $row['reason'],
            $row['source'],
            $metaJson,
        ]);

        if ($this->tableExists('sentinela_risk_daily') && ($row['status'] ?? '') !== 'nd') {
            $this->db->prepare(
                'INSERT INTO sentinela_risk_daily
                   (account_id, risk_key, date, value_num, pct_of_limit, status)
                 VALUES (?, ?, CURDATE(), ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   value_num = VALUES(value_num),
                   pct_of_limit = VALUES(pct_of_limit),
                   status = VALUES(status)'
            )->execute([
                $accountId,
                $row['risk_key'],
                $row['value_num'],
                $row['pct_of_limit'],
                $row['status'],
            ]);
        }
    }

    /** @param array<string, mixed> $row */
    private function emitForRisk(int $accountId, array $row): void
    {
        $key = (string) $row['risk_key'];
        $this->emitter->emit('metric.update', [
            'key' => 'sentinela.' . $key,
            'value' => [
                'status' => $row['status'],
                'value_num' => $row['value_num'],
                'value_text' => $row['value_text'],
                'pct_of_limit' => $row['pct_of_limit'],
                'reason' => $row['reason'],
            ],
            'flash' => ($row['status'] === 'verde') ? 'green' : 'yellow',
        ], $accountId, 'live');

        if (($row['status'] ?? 'nd') === 'nd' || ($row['status'] ?? '') === 'verde') {
            // transição para verde também emite (recuperação), via fingerprint
        }

        $level = match ($row['status'] ?? 'nd') {
            'vermelho', 'amarelo' => 'alert',
            default => 'info',
        };

        $this->emitter->emitOpOnTransition(
            'sentinela.risk.' . $key,
            [
                'robot' => self::ROBOT,
                'level' => $level,
                'icon' => match ($row['status'] ?? 'nd') {
                    'vermelho' => '🔴',
                    'amarelo' => '🟡',
                    'verde' => '🟢',
                    default => '⚪',
                },
                'msg' => sprintf('%s — %s', (string) $row['label'], (string) ($row['reason'] ?? '')),
                'risk_key' => $key,
                'status' => $row['status'],
            ],
            ['status' => $row['status'], 'bucket' => $this->statusBucket($row)],
            $accountId,
            'live'
        );
    }

    /** @param array<string, mixed> $row */
    private function statusBucket(array $row): string
    {
        return (string) ($row['status'] ?? 'nd');
    }

    /**
     * @return array{0: string, 1: float, 2: string}
     */
    private function statusFromPct(string $riskKey, float $value, ?float $limit): array
    {
        $def = $this->defs[$riskKey] ?? [];
        $yellowAt = isset($def['yellow_at']) ? (float) $def['yellow_at'] : ($limit !== null ? $limit * 0.5 : null);
        $redAt = isset($def['red_at']) ? (float) $def['red_at'] : ($limit !== null ? $limit * 0.8 : null);

        $pct = ($limit !== null && $limit > 0) ? round(($value / $limit) * 100, 2) : 0.0;
        $status = 'verde';
        $reason = sprintf('%s = %s (limite %s)', $def['label'] ?? $riskKey, $this->fmtPct($value), $limit !== null ? $this->fmtPct($limit) : 'n/d');

        if ($yellowAt !== null && $value >= $yellowAt) {
            $status = 'amarelo';
            $reason = sprintf('%s em %s — ≥50%% do limite ML', $def['label'] ?? $riskKey, $this->fmtPct($value));
        }
        if ($redAt !== null && $value >= $redAt) {
            $status = 'vermelho';
            $reason = sprintf('%s em %s — ≥80%% do limite ML', $def['label'] ?? $riskKey, $this->fmtPct($value));
        }
        return [$status, $pct, $reason];
    }

    /**
     * @param array<string, mixed>|null $meta
     * @return array<string, mixed>
     */
    private function riskResult(
        string $key,
        ?float $valueNum,
        ?string $valueText,
        string $status,
        string $reason,
        string $source,
        ?float $limit = null,
        ?float $pct = null,
        ?array $meta = null
    ): array {
        $def = $this->defs[$key] ?? ['label' => $key];
        return [
            'risk_key' => $key,
            'label' => (string) ($def['label'] ?? $key),
            'value_num' => $valueNum,
            'value_text' => $valueText,
            'limit_num' => $limit ?? (isset($def['limit']) ? (float) $def['limit'] : null),
            'pct_of_limit' => $pct,
            'status' => $status,
            'reason' => $reason,
            'source' => $source,
            'meta' => $meta,
            'collected_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string, mixed> $row */
    private function normalizeRow(array $row): array
    {
        $meta = $row['meta'] ?? null;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : null;
        }
        return [
            'risk_key' => (string) $row['risk_key'],
            'label' => (string) $row['label'],
            'value_num' => $row['value_num'] !== null ? (float) $row['value_num'] : null,
            'value_text' => $row['value_text'] !== null ? (string) $row['value_text'] : null,
            'limit_num' => $row['limit_num'] !== null ? (float) $row['limit_num'] : null,
            'pct_of_limit' => $row['pct_of_limit'] !== null ? (float) $row['pct_of_limit'] : null,
            'status' => (string) $row['status'],
            'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
            'source' => (string) ($row['source'] ?? ''),
            'meta' => $meta,
            'collected_at' => $row['collected_at'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function emptyRiskGrid(): array
    {
        $out = [];
        foreach (self::RISK_KEYS as $key) {
            $out[] = $this->placeholderRisk($key);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function placeholderRisk(string $key): array
    {
        if ($key === 'nf_pendente') {
            return $this->riskResult(
                'nf_pendente',
                null,
                'n/d',
                'nd',
                'n/d — aguardando definição do emissor',
                'none'
            );
        }
        $def = $this->defs[$key] ?? ['label' => $key];
        return [
            'risk_key' => $key,
            'label' => (string) ($def['label'] ?? $key),
            'value_num' => null,
            'value_text' => 'n/d',
            'limit_num' => isset($def['limit']) ? (float) $def['limit'] : null,
            'pct_of_limit' => null,
            'status' => 'nd',
            'reason' => 'aguardando primeira coleta',
            'source' => 'none',
            'meta' => null,
            'collected_at' => null,
        ];
    }

    /** @return array<string, list<array{date: string, value_num: ?float, pct_of_limit: ?float, status: string}>> */
    private function history30d(int $accountId): array
    {
        $out = [];
        foreach (self::RISK_KEYS as $key) {
            $out[$key] = [];
        }
        if (!$this->tableExists('sentinela_risk_daily')) {
            return $out;
        }
        $stmt = $this->db->prepare(
            'SELECT risk_key, date, value_num, pct_of_limit, status
             FROM sentinela_risk_daily
             WHERE account_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             ORDER BY date ASC'
        );
        $stmt->execute([$accountId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $k = (string) $row['risk_key'];
            if (!isset($out[$k])) {
                $out[$k] = [];
            }
            $out[$k][] = [
                'date' => (string) $row['date'],
                'value_num' => $row['value_num'] !== null ? (float) $row['value_num'] : null,
                'pct_of_limit' => $row['pct_of_limit'] !== null ? (float) $row['pct_of_limit'] : null,
                'status' => (string) $row['status'],
            ];
        }
        return $out;
    }

    private function countCollected(int $accountId): int
    {
        if (!$this->tableExists('sentinela_risk_state')) {
            return 0;
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM sentinela_risk_state WHERE account_id = ? AND risk_key != 'nf_pendente'"
        );
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }

    private function fmtPct(float $v): string
    {
        return number_format($v, 2, ',', '.') . '%';
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        // MySQL não aceita placeholder em SHOW TABLES LIKE
        $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $table);
        $stmt = $this->db->query("SHOW TABLES LIKE " . $this->db->quote($safe));
        $cache[$table] = (bool) ($stmt && $stmt->fetchColumn());
        return $cache[$table];
    }

    private function redisIncrGet(string $key): int
    {
        $r = $this->redis();
        if ($r === null) {
            return 0;
        }
        try {
            $v = $r->get($key);
            return is_numeric($v) ? (int) $v : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function redis(): ?Redis
    {
        if ($this->redisTried) {
            return $this->redis;
        }
        $this->redisTried = true;
        try {
            if (!class_exists(Redis::class)) {
                return null;
            }
            $r = new Redis();
            $host = $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int) ($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379);
            if (!$r->connect((string) $host, $port, 1.0)) {
                return null;
            }
            $this->redis = $r;
        } catch (Throwable $e) {
            $this->redis = null;
        }
        return $this->redis;
    }
}
