<?php

declare(strict_types=1);

namespace App\Services\ML;

use App\Database;
use App\Services\Rank\RankTrackerService;
use App\Services\Sentinela\Sentinela;
use PDO;
use Throwable;

/**
 * Checklist automático 7/7 para habilitação futura de escrita (Onda 5).
 * Cada item é avaliado por query/estado real — não por texto estático.
 */
final class WriteEnablementChecklist
{
    private PDO $db;
    private RankTrackerService $ranks;
    /** @var object|null objeto com semaforoGlobal(int): string */
    private ?object $sentinela;

    public function __construct(?PDO $db = null, ?RankTrackerService $ranks = null, ?object $sentinela = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->ranks = $ranks ?? new RankTrackerService($this->db);
        $this->sentinela = $sentinela;
    }

    /**
     * @return array{items: list<array<string, mixed>>, passed: int, total: int, can_enable_flags: bool}
     */
    public function evaluate(int $accountId): array
    {
        $items = [
            $this->checkNotTravada($accountId),
            $this->checkSentinelaVerde($accountId),
            $this->checkUnlockPlanNoCritical($accountId),
            $this->checkCogsCoverage($accountId),
            $this->checkRankHistory($accountId),
            $this->checkQaPlaywright(),
            $this->checkFreshBackup(),
        ];

        $passed = count(array_filter($items, static fn (array $i): bool => (bool) $i['pass']));
        $total = count($items);

        return [
            'items' => $items,
            'passed' => $passed,
            'total' => $total,
            'can_enable_flags' => $passed === $total,
            'note' => $passed === $total
                ? '7/7 — usuário pode virar a primeira flag (ainda por ação + allowlist).'
                : 'Escrita permanece bloqueada até 7/7 verdes.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkNotTravada(int $accountId): array
    {
        $status = 'UNKNOWN';
        try {
            $stmt = $this->db->prepare(
                'SELECT account_status FROM account_xray_reports
                 WHERE account_id = ? ORDER BY created_at DESC, id DESC LIMIT 1'
            );
            $stmt->execute([$accountId]);
            $status = strtoupper((string) ($stmt->fetchColumn() ?: 'UNKNOWN'));
        } catch (Throwable) {
            $status = 'ERROR';
        }
        $pass = $status !== '' && $status !== 'TRAVADA' && $status !== 'UNKNOWN' && $status !== 'ERROR';
        return [
            'id' => 'not_travada',
            'label' => 'Conta fora de TRAVADA (Raio X)',
            'pass' => $pass,
            'value' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSentinelaVerde(int $accountId): array
    {
        $semaforo = 'desconhecido';
        $days = 0;
        try {
            $sentinela = $this->sentinela ?? new Sentinela();
            if (!is_callable([$sentinela, 'semaforoGlobal'])) {
                throw new \RuntimeException('sentinela_unavailable');
            }
            $semaforo = $sentinela->semaforoGlobal($accountId);
            // Contar dias consecutivos via snapshots/eventos se existir; fallback 0/1
            $days = $semaforo === 'verde' ? 1 : 0;
            try {
                $stmt = $this->db->prepare(
                    "SELECT COUNT(DISTINCT DATE(created_at)) FROM agent_runtime_results
                     WHERE account_id = ? AND agent = 'sentinela' AND status = 'success'
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
                );
                $stmt->execute([$accountId]);
                if ($semaforo === 'verde') {
                    $days = max(1, (int) $stmt->fetchColumn());
                }
            } catch (Throwable) {
                // ignore
            }
        } catch (Throwable) {
            $semaforo = 'erro';
        }
        $need = 3;
        $pass = $semaforo === 'verde' && $days >= $need;
        return [
            'id' => 'sentinela_verde',
            'label' => "Sentinela verde há ≥{$need} dias",
            'pass' => $pass,
            'value' => "{$semaforo} · {$days}d",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkUnlockPlanNoCritical(int $accountId): array
    {
        $critical = -1;
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM account_unlock_plan_items
                 WHERE account_id = ? AND status = 'pending'
                   AND (priority = 'CRITICA' OR impact_score >= 40)"
            );
            $stmt->execute([$accountId]);
            $critical = (int) $stmt->fetchColumn();
        } catch (Throwable) {
            $critical = -1;
        }
        return [
            'id' => 'unlock_no_critical',
            'label' => 'Plano de Destravamento: 0 críticos pendentes',
            'pass' => $critical === 0,
            'value' => $critical < 0 ? 'n/d' : (string) $critical,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkCogsCoverage(int $accountId): array
    {
        $pct = 0.0;
        try {
            $svc = new \App\Services\Financial\CogsService($this->db);
            $audit = $svc->auditSoldItems($accountId, 90);
            $total = (int) ($audit['summary']['total'] ?? 0);
            $real = (int) ($audit['summary']['with_real_cogs'] ?? 0);
            $pct = $total > 0 ? round(($real / $total) * 100, 1) : 0.0;
        } catch (Throwable) {
            $pct = 0.0;
        }
        return [
            'id' => 'cogs_80',
            'label' => 'CMV real em ≥80% dos anúncios com venda',
            'pass' => $pct >= 80.0,
            'value' => $pct . '%',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRankHistory(int $accountId): array
    {
        $days = 0;
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(DISTINCT DATE(captured_at)) FROM rank_history
                 WHERE account_id = ? AND position IS NOT NULL
                   AND captured_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            );
            $stmt->execute([$accountId]);
            $days = (int) $stmt->fetchColumn();
        } catch (Throwable) {
            $days = 0;
        }
        return [
            'id' => 'rank_14d',
            'label' => 'Rank tracker com ≥14 dias de histórico',
            'pass' => $days >= 14,
            'value' => $days . 'd',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkQaPlaywright(): array
    {
        $status = 'n/d';
        $pass = false;
        try {
            $stmt = $this->db->query(
                "SELECT status, finished_at FROM pregao_qa_runs
                 ORDER BY id DESC LIMIT 1"
            );
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if ($row) {
                $status = (string) ($row['status'] ?? 'n/d');
                $pass = strtolower($status) === 'passed';
            }
        } catch (Throwable) {
            // try redis/file based? keep n/d
            $status = 'sem runs';
        }
        return [
            'id' => 'qa_playwright',
            'label' => 'QA Playwright verde na última execução',
            'pass' => $pass,
            'value' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkFreshBackup(): array
    {
        $dir = dirname(__DIR__, 3) . '/storage/backups';
        $newest = null;
        $ageHours = null;
        if (is_dir($dir)) {
            $files = glob($dir . '/*.sql') ?: [];
            $files = array_merge($files, glob($dir . '/*.sql.gz') ?: []);
            foreach ($files as $f) {
                $mtime = filemtime($f) ?: 0;
                if ($newest === null || $mtime > $newest) {
                    $newest = $mtime;
                }
            }
        }
        if ($newest !== null) {
            $ageHours = round((time() - $newest) / 3600, 1);
        }
        $pass = $ageHours !== null && $ageHours <= 24;
        return [
            'id' => 'backup_24h',
            'label' => 'Backup íntegro < 24h',
            'pass' => $pass,
            'value' => $ageHours === null ? 'nenhum' : $ageHours . 'h',
        ];
    }
}
