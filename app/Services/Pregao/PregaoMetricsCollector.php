<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use App\Database;
use App\Services\MercadoLivreClient;
use PDO;
use Throwable;

/**
 * Coleta métricas reais para o Pregão (sem escrita no ML).
 *
 * Ordem: reputação/semáforo → health → perguntas → vendas → visitas (Fe) → [keywords se RANK_TRACKER_ENABLED].
 * TACOS permanece indisponível até o módulo Ads emitir gasto.
 */
final class PregaoMetricsCollector
{
    private PDO $db;
    private AccountIndexCalculator $calculator;
    private PregaoEmitService $emitter;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        ?PDO $db = null,
        ?AccountIndexCalculator $calculator = null,
        ?PregaoEmitService $emitter = null,
        ?array $config = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->calculator = $calculator ?? new AccountIndexCalculator();
        $this->emitter = $emitter ?? new PregaoEmitService($this->db);
        $this->config = $config ?? (require dirname(__DIR__, 3) . '/config/pregao.php');
    }

    /**
     * @param list<string>|null $only Subconjunto: reputation|health|questions|sales|visits|keywords|robots
     * @return array<string, mixed>
     */
    public function collect(int $accountId, ?array $only = null): array
    {
        $want = static function (string $key) use ($only): bool {
            return $only === null || in_array($key, $only, true);
        };

        $meta = $this->loadMeta($accountId);
        $results = [];

        if ($want('reputation')) {
            $results['reputation'] = $this->collectReputation($accountId, $meta);
        }
        if ($want('health')) {
            $results['health'] = $this->collectHealth($accountId, $meta);
        }
        if ($want('questions')) {
            $results['questions'] = $this->collectQuestions($accountId, $meta);
        }
        if ($want('sales')) {
            $results['sales'] = $this->collectSales($accountId, $meta);
        }
        if ($want('visits')) {
            $results['visits'] = $this->collectVisits($accountId, $meta);
        }
        if ($want('keywords')) {
            $results['keywords'] = $this->collectKeywords($accountId, $meta);
        }
        if ($want('robots')) {
            $results['robots'] = $this->collectRobotActions($accountId, $meta);
        }

        // Fp legado não alimenta o índice (substituído por Fe)
        unset($meta['available']['Fp']);

        // TACOS sempre inativo até Ads
        $meta['available']['Ft'] = false;
        $meta['metrics']['tacos'] = [
            'available' => false,
            'reason' => 'ads_pending',
        ];
        // posição média busca só se rank tracker ligado
        if (!($this->config['rank_tracker_enabled'] ?? false)) {
            $meta['metrics']['posicao_media'] = [
                'available' => false,
                'reason' => 'rank_tracker_disabled',
            ];
        }

        $this->persistMeta($accountId, $meta);
        $results['meta'] = $meta;

        return $results;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function collectReputation(int $accountId, array &$meta): array
    {
        try {
            $client = new MercadoLivreClient($accountId);
            $me = $client->getMe();
            $rep = $me['seller_reputation'] ?? [];
            if (!is_array($rep) || $rep === []) {
                throw new \RuntimeException('seller_reputation ausente');
            }

            $levelId = (string) ($rep['level_id'] ?? '');
            $cor = $this->calculator->mapLevelIdToCor($levelId);
            $metrics = is_array($rep['metrics'] ?? null) ? $rep['metrics'] : [];

            $claimsPct = round(((float) ($metrics['claims']['rate'] ?? 0)) * 100, 4);
            $cancelPct = round(((float) ($metrics['cancellations']['rate'] ?? 0)) * 100, 4);
            $delayPct = round(((float) ($metrics['delayed_handling_time']['rate'] ?? 0)) * 100, 4);

            $limites = $this->config['semaforo_limites'] ?? [
                'reclamacoes_pct' => 2.0,
                'atrasos_pct' => 15.0,
                'cancelamentos_pct' => 2.5,
            ];
            $semaforo = $this->calculator->resolveSemaforo(
                [
                    'reclamacoes_pct' => $claimsPct,
                    'atrasos_pct' => $delayPct,
                    'cancelamentos_pct' => $cancelPct,
                ],
                $limites
            );

            $this->db->prepare(
                'INSERT INTO account_index_metrics
                   (account_id, reputacao_cor, reclamacoes_pct, atrasos_pct, cancelamentos_pct, semaforo_status)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   reputacao_cor = VALUES(reputacao_cor),
                   reclamacoes_pct = VALUES(reclamacoes_pct),
                   atrasos_pct = VALUES(atrasos_pct),
                   cancelamentos_pct = VALUES(cancelamentos_pct),
                   semaforo_status = VALUES(semaforo_status)'
            )->execute([$accountId, $cor, $claimsPct, $delayPct, $cancelPct, $semaforo]);

            $meta['available']['Fr'] = true;
            $meta['metrics']['reputacao'] = [
                'available' => true,
                'source' => 'seller_reputation',
                'level_id' => $levelId,
            ];

            $this->emitter->emit('metric.update', [
                'key' => 'reputacao',
                'cor' => $cor,
                'reclamacoes_pct' => $claimsPct,
                'atrasos_pct' => $delayPct,
                'cancelamentos_pct' => $cancelPct,
                'value' => $cor,
                'flash' => $semaforo === 'vermelho' ? 'yellow' : 'green',
            ], $accountId, 'live');

            $this->emitter->emit('account.semaforo', [
                'status' => $semaforo,
                'indicadores' => [
                    'reclamacoes_pct' => $claimsPct,
                    'atrasos_pct' => $delayPct,
                    'cancelamentos_pct' => $cancelPct,
                ],
                'limites' => $limites,
            ], $accountId, 'live');

            // op só em transição (ou heartbeat ≤1×/h) — evita ruído a cada tick
            $repState = [
                'level_id' => $levelId,
                'cor' => $cor,
                'semaforo' => $semaforo,
                'reclamacoes_pct' => $claimsPct,
                'atrasos_pct' => $delayPct,
                'cancelamentos_pct' => $cancelPct,
            ];
            $this->emitter->emitOpOnTransition(
                'REPUTACAO',
                [
                    'robot' => 'REPUTAÇÃO',
                    'level' => 'info',
                    'icon' => '🛡️',
                    'msg' => sprintf(
                        'seller_reputation %s · semáforo %s',
                        $levelId !== '' ? $levelId : $cor,
                        $semaforo
                    ),
                ],
                $repState,
                $accountId,
                'live'
            );

            return [
                'ok' => true,
                'cor' => $cor,
                'level_id' => $levelId,
                'semaforo' => $semaforo,
                'reclamacoes_pct' => $claimsPct,
                'atrasos_pct' => $delayPct,
                'cancelamentos_pct' => $cancelPct,
            ];
        } catch (Throwable $e) {
            log_warning('PregaoMetricsCollector: reputação falhou', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            $meta['available']['Fr'] = false;
            $meta['metrics']['reputacao'] = ['available' => false, 'error' => $e->getMessage()];
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function collectHealth(int $accountId, array &$meta): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT overall_score, created_at
                 FROM account_health_history
                 WHERE account_id = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute([$accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \RuntimeException('account_health_history vazio');
            }

            $overall = (float) $row['overall_score'];
            $health = round(max(0.0, min(1.0, $overall / 100.0)), 4);

            $this->db->prepare(
                'INSERT INTO account_index_metrics (account_id, health_medio)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE health_medio = VALUES(health_medio)'
            )->execute([$accountId, $health]);

            $meta['available']['Fh'] = true;
            $meta['metrics']['health_medio'] = [
                'available' => true,
                'source' => 'account_health_history',
                'overall_score' => $overall,
                'as_of' => (string) $row['created_at'],
            ];

            $this->emitter->emit('metric.update', [
                'key' => 'health_medio',
                'value' => $health,
                'flash' => 'green',
            ], $accountId, 'live');

            return ['ok' => true, 'health_medio' => $health, 'overall_score' => $overall];
        } catch (Throwable $e) {
            log_warning('PregaoMetricsCollector: health falhou', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            $meta['available']['Fh'] = false;
            $meta['metrics']['health_medio'] = ['available' => false, 'error' => $e->getMessage()];
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function collectQuestions(int $accountId, array &$meta): array
    {
        try {
            $todayStmt = $this->db->prepare(
                'SELECT COUNT(*) AS c
                 FROM ml_questions
                 WHERE account_id = ?
                   AND DATE(date_created) = CURDATE()'
            );
            $todayStmt->execute([$accountId]);
            $perguntasHoje = (int) $todayStmt->fetchColumn();

            $avgStmt = $this->db->prepare(
                'SELECT AVG(TIMESTAMPDIFF(SECOND, date_created, answer_date)) AS avg_s
                 FROM ml_questions
                 WHERE account_id = ?
                   AND answer_date IS NOT NULL
                   AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            );
            $avgStmt->execute([$accountId]);
            $avgRaw = $avgStmt->fetchColumn();
            $tempoMedio = $avgRaw !== null && $avgRaw !== false
                ? (int) round((float) $avgRaw)
                : null;

            $this->db->prepare(
                'INSERT INTO account_index_metrics (account_id, perguntas_hoje, tempo_medio_resposta_s)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   perguntas_hoje = VALUES(perguntas_hoje),
                   tempo_medio_resposta_s = VALUES(tempo_medio_resposta_s)'
            )->execute([$accountId, $perguntasHoje, $tempoMedio ?? 0]);

            $meta['metrics']['perguntas_hoje'] = [
                'available' => true,
                'source' => 'ml_questions',
            ];
            $meta['metrics']['tempo_medio_resposta_s'] = [
                'available' => $tempoMedio !== null,
                'source' => 'ml_questions',
                'window' => '30d',
            ];

            $this->emitter->emit('metric.update', [
                'key' => 'perguntas_hoje',
                'value' => $perguntasHoje,
                'flash' => 'green',
            ], $accountId, 'live');

            if ($tempoMedio !== null) {
                $this->emitter->emit('metric.update', [
                    'key' => 'tempo_medio_resposta_s',
                    'value' => $tempoMedio,
                    'flash' => 'green',
                ], $accountId, 'live');
            }

            return [
                'ok' => true,
                'perguntas_hoje' => $perguntasHoje,
                'tempo_medio_resposta_s' => $tempoMedio,
            ];
        } catch (Throwable $e) {
            log_warning('PregaoMetricsCollector: perguntas falhou', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            $meta['metrics']['perguntas_hoje'] = ['available' => false, 'error' => $e->getMessage()];
            $meta['metrics']['tempo_medio_resposta_s'] = ['available' => false];
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function collectSales(int $accountId, array &$meta): array
    {
        try {
            $todayStmt = $this->db->prepare(
                "SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS s
                 FROM ml_orders
                 WHERE ml_account_id = ?
                   AND DATE(date_created) = CURDATE()
                   AND (status IS NULL OR status NOT IN ('cancelled','canceled'))"
            );
            $todayStmt->execute([$accountId]);
            $today = $todayStmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 's' => 0];
            $vendasHoje = (int) $today['c'];
            $receitaHoje = round((float) $today['s'], 2);
            $ticket = $vendasHoje > 0 ? round($receitaHoje / $vendasHoje, 2) : 0.0;

            $weekStmt = $this->db->prepare(
                "SELECT COUNT(*) AS c
                 FROM ml_orders
                 WHERE ml_account_id = ?
                   AND date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND (status IS NULL OR status NOT IN ('cancelled','canceled'))"
            );
            $weekStmt->execute([$accountId]);
            $vendas7d = (float) $weekStmt->fetchColumn();

            $this->db->prepare(
                'INSERT INTO account_index_metrics
                   (account_id, vendas_hoje, receita_hoje, ticket_medio, vendas_7d)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   vendas_hoje = VALUES(vendas_hoje),
                   receita_hoje = VALUES(receita_hoje),
                   ticket_medio = VALUES(ticket_medio),
                   vendas_7d = VALUES(vendas_7d)'
            )->execute([$accountId, $vendasHoje, $receitaHoje, $ticket, $vendas7d]);

            // Baseline mínima: média 7d dos últimos 28d / 4, ou max(vendas7d,1)
            $baseStmt = $this->db->prepare(
                "SELECT COUNT(*) / 4.0 AS avg7
                 FROM ml_orders
                 WHERE ml_account_id = ?
                   AND date_created >= DATE_SUB(NOW(), INTERVAL 28 DAY)
                   AND date_created < DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND (status IS NULL OR status NOT IN ('cancelled','canceled'))"
            );
            $baseStmt->execute([$accountId]);
            $baseRaw = (float) ($baseStmt->fetchColumn() ?: 0);
            $vendasBaseline = max($baseRaw > 0 ? $baseRaw : $vendas7d, 1.0);

            $this->db->prepare(
                'INSERT INTO account_index_baselines (account_id, vendas_7d_baseline, recalculated_at)
                 VALUES (?, ?, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                   vendas_7d_baseline = VALUES(vendas_7d_baseline),
                   recalculated_at = CURRENT_TIMESTAMP'
            )->execute([$accountId, $vendasBaseline]);

            $meta['available']['Fv'] = true;
            $meta['metrics']['vendas_hoje'] = ['available' => true, 'source' => 'ml_orders'];
            $meta['metrics']['receita_hoje'] = ['available' => true, 'source' => 'ml_orders'];
            $meta['metrics']['ticket_medio'] = ['available' => true, 'source' => 'ml_orders'];

            foreach (
                [
                    ['vendas_hoje', $vendasHoje],
                    ['receita_hoje', $receitaHoje],
                    ['ticket_medio', $ticket],
                ] as [$key, $value]
            ) {
                $this->emitter->emit('metric.update', [
                    'key' => $key,
                    'value' => $value,
                    'flash' => 'green',
                ], $accountId, 'live');
            }

            return [
                'ok' => true,
                'vendas_hoje' => $vendasHoje,
                'receita_hoje' => $receitaHoje,
                'ticket_medio' => $ticket,
                'vendas_7d' => $vendas7d,
                'vendas_7d_baseline' => $vendasBaseline,
            ];
        } catch (Throwable $e) {
            log_warning('PregaoMetricsCollector: vendas falhou', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            $meta['available']['Fv'] = false;
            $meta['metrics']['vendas_hoje'] = ['available' => false];
            $meta['metrics']['receita_hoje'] = ['available' => false];
            $meta['metrics']['ticket_medio'] = ['available' => false];
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Exposição (Fe): visitas 7d / baseline semanal dos 28d anteriores.
     * Fonte: GET /users/{id}/items_visits (Visits API da conta).
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function collectVisits(int $accountId, array &$meta): array
    {
        try {
            $acc = $this->db->prepare('SELECT ml_user_id FROM ml_accounts WHERE id = ?');
            $acc->execute([$accountId]);
            $mlUserId = $acc->fetchColumn();
            if ($mlUserId === false || $mlUserId === null || $mlUserId === '') {
                throw new \RuntimeException('ml_user_id não encontrado');
            }

            $client = new MercadoLivreClient($accountId);
            $tz = new \DateTimeZone('America/Sao_Paulo');
            $today = new \DateTimeImmutable('today', $tz);

            $from7 = $today->modify('-7 days')->format('Y-m-d');
            $to7 = $today->format('Y-m-d');
            $from28 = $today->modify('-35 days')->format('Y-m-d');
            $to28 = $today->modify('-7 days')->format('Y-m-d');

            $visits7 = $this->fetchAccountVisits($client, (string) $mlUserId, $from7, $to7);
            $visits28prior = $this->fetchAccountVisits($client, (string) $mlUserId, $from28, $to28);
            $baseline = max(round($visits28prior / 4.0, 2), 1.0);

            if ($this->columnExists('account_index_metrics', 'visitas_7d')) {
                $this->db->prepare(
                    'INSERT INTO account_index_metrics (account_id, visitas_7d)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE visitas_7d = VALUES(visitas_7d)'
                )->execute([$accountId, $visits7]);
            }

            if ($this->columnExists('account_index_baselines', 'visitas_baseline')) {
                $this->db->prepare(
                    'INSERT INTO account_index_baselines (account_id, visitas_baseline, recalculated_at)
                     VALUES (?, ?, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE
                       visitas_baseline = VALUES(visitas_baseline),
                       recalculated_at = CURRENT_TIMESTAMP'
                )->execute([$accountId, $baseline]);
            }

            $meta['available']['Fe'] = true;
            $meta['metrics']['visitas_7d'] = [
                'available' => true,
                'source' => 'items_visits',
                'window' => '7d',
            ];
            $meta['metrics']['exposicao'] = [
                'available' => true,
                'source' => 'items_visits',
                'visitas_7d' => $visits7,
                'visitas_baseline' => $baseline,
            ];

            $this->emitter->emit('metric.update', [
                'key' => 'visitas_7d',
                'value' => $visits7,
                'flash' => 'green',
            ], $accountId, 'live');

            return [
                'ok' => true,
                'visitas_7d' => $visits7,
                'visitas_baseline' => $baseline,
                'visitas_28d_prior' => $visits28prior,
            ];
        } catch (Throwable $e) {
            log_warning('PregaoMetricsCollector: visitas falhou', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            $meta['available']['Fe'] = false;
            $meta['metrics']['visitas_7d'] = ['available' => false, 'error' => $e->getMessage()];
            $meta['metrics']['exposicao'] = ['available' => false];
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function fetchAccountVisits(
        MercadoLivreClient $client,
        string $mlUserId,
        string $dateFrom,
        string $dateTo
    ): float {
        $data = $client->get("/users/{$mlUserId}/items_visits", [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
        if (isset($data['error'])) {
            throw new \RuntimeException((string) ($data['message'] ?? $data['error']));
        }
        $total = $data['total_visits'] ?? ($data['body']['total_visits'] ?? null);
        if ($total === null) {
            throw new \RuntimeException('total_visits ausente na Visits API');
        }
        return (float) $total;
    }

    /**
     * Rank tracker das keywords-alvo (busca pública, read-only).
     * Desligado por padrão: RANK_TRACKER_ENABLED=false.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function collectKeywords(int $accountId, array &$meta): array
    {
        if (!($this->config['rank_tracker_enabled'] ?? false)) {
            $meta['metrics']['posicao_media'] = [
                'available' => false,
                'reason' => 'rank_tracker_disabled',
            ];
            return ['ok' => false, 'skipped' => true, 'error' => 'rank_tracker_disabled'];
        }

        try {
            $acc = $this->db->prepare('SELECT ml_user_id, site_id FROM ml_accounts WHERE id = ?');
            $acc->execute([$accountId]);
            $account = $acc->fetch(PDO::FETCH_ASSOC);
            if (!$account || empty($account['ml_user_id'])) {
                throw new \RuntimeException('ml_user_id não encontrado');
            }
            $sellerId = (string) $account['ml_user_id'];
            $siteId = (string) ($account['site_id'] ?: 'MLB');

            /** @var list<string> $keywords */
            $keywords = $this->config['keywords'] ?? [];
            if ($keywords === []) {
                throw new \RuntimeException('PREGAO_KEYWORDS vazio');
            }

            $client = new MercadoLivreClient($accountId);
            $limit = (int) ($this->config['keyword_search_limit'] ?? 50);
            $pages = (int) ($this->config['keyword_search_pages'] ?? 4);
            $date = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
            $positions = [];
            $searchBlocked = false;
            $lastSearchError = null;

            foreach ($keywords as $kw) {
                $found = $this->findSellerPositionDetailed($client, $siteId, $sellerId, $kw, $limit, $pages);
                if (($found['error'] ?? null) === 'search_forbidden') {
                    $searchBlocked = true;
                    $lastSearchError = (string) ($found['message'] ?? 'sites/search 403');
                    break;
                }
                $pos = $found['pos'] ?? null;
                if ($pos === null) {
                    continue;
                }

                $prevStmt = $this->db->prepare(
                    'SELECT pos FROM keyword_ranks
                     WHERE account_id = ? AND kw = ? AND `date` < ?
                     ORDER BY `date` DESC LIMIT 1'
                );
                $prevStmt->execute([$accountId, $kw, $date]);
                $prev = $prevStmt->fetchColumn();
                $delta = $prev !== false && $prev !== null ? ((int) $prev - $pos) : null;

                $this->emitter->emit('keyword.rank', array_filter([
                    'kw' => $kw,
                    'pos' => $pos,
                    'delta' => $delta,
                ], static fn ($v) => $v !== null), $accountId, 'live');

                $positions[] = $pos;
                usleep(200000);
            }

            if ($searchBlocked) {
                $meta['metrics']['posicao_media'] = [
                    'available' => false,
                    'reason' => 'ml_search_forbidden',
                    'error' => $lastSearchError,
                ];
                return ['ok' => false, 'error' => 'ml_search_forbidden', 'positions' => []];
            }

            if ($positions === []) {
                $meta['metrics']['posicao_media'] = [
                    'available' => false,
                    'reason' => 'seller_not_found_in_search',
                ];
                return ['ok' => false, 'error' => 'nenhuma keyword com posição', 'positions' => []];
            }

            $posMedia = round(array_sum($positions) / count($positions), 4);
            $this->db->prepare(
                'INSERT INTO account_index_metrics (account_id, posicao_media)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE posicao_media = VALUES(posicao_media)'
            )->execute([$accountId, $posMedia]);

            $meta['metrics']['posicao_media'] = [
                'available' => true,
                'source' => 'keyword_ranks',
                'sample' => count($positions),
            ];

            $this->emitter->emit('metric.update', [
                'key' => 'posicao_media',
                'value' => $posMedia,
                'flash' => 'green',
            ], $accountId, 'live');

            return [
                'ok' => true,
                'posicao_media' => $posMedia,
                'positions' => $positions,
                'keywords' => count($positions),
            ];
        } catch (Throwable $e) {
            log_warning('PregaoMetricsCollector: keywords falhou', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            $meta['metrics']['posicao_media'] = ['available' => false, 'error' => $e->getMessage()];
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Conta operações live dos robôs na última hora (fita de ops).
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function collectRobotActions(int $accountId, array &$meta): array
    {
        try {
            $hasSource = $this->columnExists('pregao_events', 'source');
            $sql = $hasSource
                ? "SELECT COUNT(*) FROM pregao_events
                   WHERE type = 'op'
                     AND (account_id = ? OR account_id IS NULL)
                     AND source = 'live'
                     AND ts >= (NOW() - INTERVAL 1 HOUR)"
                : "SELECT COUNT(*) FROM pregao_events
                   WHERE type = 'op'
                     AND (account_id = ? OR account_id IS NULL)
                     AND ts >= (NOW() - INTERVAL 1 HOUR)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$accountId]);
            $count = (int) $stmt->fetchColumn();

            $this->db->prepare(
                'INSERT INTO account_index_metrics (account_id, acoes_hora)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE acoes_hora = VALUES(acoes_hora)'
            )->execute([$accountId, $count]);

            $meta['metrics']['acoes_hora'] = [
                'available' => true,
                'source' => 'pregao_events',
            ];

            $this->emitter->emit('metric.update', [
                'key' => 'acoes_hora',
                'value' => $count,
                'flash' => 'green',
            ], $accountId, 'live');

            return ['ok' => true, 'acoes_hora' => $count];
        } catch (Throwable $e) {
            $meta['metrics']['acoes_hora'] = ['available' => false];
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{pos: int|null, error?: string, message?: string}
     */
    private function findSellerPositionDetailed(
        MercadoLivreClient $client,
        string $siteId,
        string $sellerId,
        string $keyword,
        int $limit,
        int $pages
    ): array {
        $offset = 0;
        for ($page = 0; $page < $pages; $page++) {
            $result = $client->searchItems([
                'site_id' => $siteId,
                'q' => $keyword,
                'limit' => $limit,
                'offset' => $offset,
            ], 0);

            if (isset($result['error']) || (isset($result['status']) && (int) $result['status'] === 403)) {
                return [
                    'pos' => null,
                    'error' => 'search_forbidden',
                    'message' => (string) ($result['message'] ?? $result['error'] ?? 'forbidden'),
                ];
            }

            $items = $result['results'] ?? [];
            if (!is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $idx => $item) {
                $itemSeller = (string) ($item['seller']['id'] ?? $item['seller_id'] ?? '');
                if ($itemSeller === $sellerId) {
                    return ['pos' => $offset + (int) $idx + 1];
                }
            }

            $total = (int) ($result['paging']['total'] ?? 0);
            $offset += $limit;
            if ($offset >= $total) {
                break;
            }
        }

        return ['pos' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMeta(int $accountId): array
    {
        $defaults = [
            'available' => [
                'Fv' => false,
                'Fe' => false,
                'Fh' => false,
                'Fr' => false,
                'Ft' => false,
            ],
            'metrics' => [
                'tacos' => ['available' => false, 'reason' => 'ads_pending'],
            ],
        ];

        if (!$this->columnExists('account_index_metrics', 'metrics_meta')) {
            return $defaults;
        }

        $stmt = $this->db->prepare('SELECT metrics_meta FROM account_index_metrics WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || $raw === '') {
            return $defaults;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded)) {
            return $defaults;
        }

        return array_replace_recursive($defaults, $decoded);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function persistMeta(int $accountId, array $meta): void
    {
        if (!$this->columnExists('account_index_metrics', 'metrics_meta')) {
            return;
        }

        $active = 0;
        foreach ($meta['available'] ?? [] as $on) {
            if ($on) {
                $active++;
            }
        }

        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->prepare(
            'INSERT INTO account_index_metrics (account_id, metrics_meta, factors_active, factors_total)
             VALUES (?, ?, ?, 5)
             ON DUPLICATE KEY UPDATE
               metrics_meta = VALUES(metrics_meta),
               factors_active = VALUES(factors_active),
               factors_total = 5'
        )->execute([$accountId, $json, $active]);
    }

    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}
