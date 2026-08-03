<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use App\Database;
use PDO;
use Redis;
use Throwable;

/**
 * Recalcula o índice ESKL11 (tick 30–60s) e consolida candle diário.
 */
final class AccountIndexService
{
    private PDO $db;
    private AccountIndexCalculator $calculator;
    private PregaoEmitService $emitter;

    public function __construct(
        ?PDO $db = null,
        ?AccountIndexCalculator $calculator = null,
        ?PregaoEmitService $emitter = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->calculator = $calculator ?? new AccountIndexCalculator();
        $this->emitter = $emitter ?? new PregaoEmitService($this->db);
    }

    /**
     * @return array{indice: float, factors: array<string, float>, event: array<string, mixed>}
     */
    public function tick(int $accountId): array
    {
        $metrics = $this->ensureMetricsRow($accountId);
        $baselines = $this->ensureBaselinesRow($accountId);

        $result = $this->calculator->calculate([
            'vendas_7d' => (float) $metrics['vendas_7d'],
            'vendas_7d_baseline' => (float) $baselines['vendas_7d_baseline'],
            'pos_media_atual' => (float) $metrics['posicao_media'],
            'pos_baseline' => (float) $baselines['pos_baseline'],
            'health_medio' => (float) $metrics['health_medio'],
            'reputacao' => (string) $metrics['reputacao_cor'],
            'tacos_atual' => (float) $metrics['tacos'],
            'tacos_baseline' => (float) $baselines['tacos_baseline'],
        ]);

        $indice = $result['indice'];
        $this->db->prepare(
            'UPDATE account_index_metrics SET indice_atual = ?, updated_at = CURRENT_TIMESTAMP WHERE account_id = ?'
        )->execute([$indice, $accountId]);

        $this->upsertIntradayCandle($accountId, $indice);

        $event = $this->emitter->emit('index.tick', ['value' => round($indice, 2)], $accountId);

        return [
            'indice' => $indice,
            'factors' => $result['factors'],
            'event' => $event,
        ];
    }

    /**
     * Consolida candle do dia (chamado no fechamento ou sob demanda).
     *
     * @return array<string, mixed>|null
     */
    public function consolidateDailyCandle(int $accountId, ?string $date = null): ?array
    {
        $date = $date ?? (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $stmt = $this->db->prepare(
            'SELECT o, h, l, c FROM account_index_daily WHERE account_id = ? AND `date` = ?'
        );
        $stmt->execute([$accountId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $payload = [
            'date' => $date,
            'o' => (float) $row['o'],
            'h' => (float) $row['h'],
            'l' => (float) $row['l'],
            'c' => (float) $row['c'],
        ];
        $this->emitter->emit('index.candle', $payload, $accountId);
        return $payload;
    }

    /**
     * Recalcula baselines semanalmente (média dos 28d anteriores às vendas 7d).
     */
    public function recalculateBaselines(int $accountId, float $vendas7d, float $posMedia, float $tacos): void
    {
        // Baseline vendas = média dos 28d anteriores ≈ (vendas_7d histórica). Usamos valor informado
        // pelo agregador externo; se zero, mantém 1.
        $vendasBaseline = max($vendas7d > 0 ? $vendas7d : 1.0, 1.0);
        $posBaseline = max($posMedia, 0.1);
        $tacosBaseline = max($tacos, 0.1);

        $this->db->prepare(
            'INSERT INTO account_index_baselines
               (account_id, vendas_7d_baseline, pos_baseline, tacos_baseline, recalculated_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
               vendas_7d_baseline = VALUES(vendas_7d_baseline),
               pos_baseline = VALUES(pos_baseline),
               tacos_baseline = VALUES(tacos_baseline),
               recalculated_at = CURRENT_TIMESTAMP'
        )->execute([$accountId, $vendasBaseline, $posBaseline, $tacosBaseline]);
    }

    private function upsertIntradayCandle(int $accountId, float $indice): void
    {
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $this->db->prepare(
            'INSERT INTO account_index_daily (account_id, `date`, o, h, l, c)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               h = GREATEST(h, VALUES(h)),
               l = LEAST(l, VALUES(l)),
               c = VALUES(c)'
        )->execute([$accountId, $date, $indice, $indice, $indice, $indice]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureMetricsRow(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM account_index_metrics WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $this->db->prepare(
            'INSERT INTO account_index_metrics (account_id) VALUES (?)'
        )->execute([$accountId]);
        $stmt->execute([$accountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['account_id' => $accountId, 'vendas_7d' => 0, 'posicao_media' => 10, 'health_medio' => 0, 'reputacao_cor' => 'verde', 'tacos' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureBaselinesRow(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM account_index_baselines WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $this->db->prepare(
            'INSERT INTO account_index_baselines (account_id) VALUES (?)'
        )->execute([$accountId]);
        return [
            'vendas_7d_baseline' => 1,
            'pos_baseline' => 10,
            'tacos_baseline' => 10,
        ];
    }
}
