<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\SessionHelper;
use App\Services\AccountXRayService;
use App\Services\AccountRecoveryApplierService;
use App\Services\UserService;
use App\Database;
use PDO;

/**
 * AccountXRayController — Raio X de Conta Mercado Livre
 *
 * Endpoints:
 *  GET  /dashboard/raio-x                    → dashboard HTML
 *  POST /api/xray/run                         → iniciar análise
 *  GET  /api/xray/results/{id}                → buscar resultado
 *  GET  /api/xray/list                        → listar relatórios
 *  GET  /api/xray/accounts                    → contas conectadas
 *  GET  /api/xray/item-scores/{report_id}     → scores por item
 */
class AccountXRayController
{
    private UserService $userService;

    public function __construct()
    {
        $this->userService = new UserService();
    }

    // ─────────────────────────────────────────────────────────
    // WEB: dashboard
    // ─────────────────────────────────────────────────────────

    public function index(): void
    {
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $pageTitle   = 'Raio X — Diagnóstico de Conta';
        $currentPage = 'raio-x';

        ob_start();
        require __DIR__ . '/../Views/dashboard/account-xray.php';
        $content = ob_get_clean();

        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    // ─────────────────────────────────────────────────────────
    // API: listar contas conectadas
    // ─────────────────────────────────────────────────────────

    /**
     * GET /api/xray/accounts
     *
     * @return void
     */
    public function accounts(): void
    {
        $this->requireAuth();
        $this->jsonHeader();

        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                "SELECT a.id, a.ml_user_id AS seller_id, a.nickname, a.email,
                        a.status, a.last_synced_at, a.created_at,
                        r.id AS last_report_id,
                        r.score_overall AS last_score,
                        r.account_status AS last_account_status,
                        r.created_at AS last_report_at
                 FROM ml_accounts a
                 LEFT JOIN account_xray_reports r
                     ON r.account_id = a.id
                     AND r.id = (
                         SELECT id FROM account_xray_reports
                         WHERE account_id = a.id AND status = 'completed'
                         ORDER BY created_at DESC, id DESC LIMIT 1
                     )
                 WHERE a.user_id = :user_id
                   AND a.status IN ('active', 'inactive', 'expired')
                 ORDER BY a.nickname ASC"
            );
            $stmt->execute(['user_id' => $this->userId()]);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'  => true,
                'accounts' => $accounts,
                'count'    => count($accounts),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->error500($e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // API: iniciar Raio X
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/xray/run
     *
     * Body: {
     *   "account_id": 1,
     *   "max_items": 400,
     *   "include_paused": true,
     *   "deep_seo": false,
     *   "include_financial": true
     * }
     */
    public function run(): void
    {
        $this->requireAuth();
        $this->jsonHeader();

        try {
            $input     = $this->jsonInput();
            $accountId = (int) ($input['account_id'] ?? 0);

            if ($accountId <= 0) {
                $this->error400('account_id obrigatório');
                return;
            }

            // Verificar se conta pertence ao usuário
            if (!$this->userOwnsAccount($accountId)) {
                $this->error403('Conta não encontrada ou sem permissão');
                return;
            }

            $options = $this->sanitizeQueueOptions($input);

            $service = new AccountXRayService($accountId);
            $result  = $service->run($options);

            if (!$result['success']) {
                http_response_code(422);
                echo json_encode([
                    'success'   => false,
                    'error'     => $result['error'] ?? 'Falha na análise',
                    'report_id' => $result['report_id'] ?? null,
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Retornar resumo (não o relatório completo — pode ser muito grande)
            $report = $result['report'];
            echo json_encode([
                'success'    => true,
                'report_id'  => $result['report_id'],
                'summary'    => [
                    'score_overall'    => $report['score_overall'],
                    'account_status'   => $report['account_status'],
                    'nickname'         => $report['meta']['nickname'] ?? null,
                    'items_fetched'    => $report['meta']['items_fetched'],
                    'items_analyzed'   => $report['meta']['items_analyzed'],
                    'critical_issues'  => $report['diagnosis']['critical_count'] ?? 0,
                    'main_bottleneck'  => $report['diagnosis']['main_bottleneck'] ?? null,
                    'elapsed_ms'       => $report['meta']['elapsed_ms'],
                ],
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->error500($e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // API: buscar resultado completo
    // ─────────────────────────────────────────────────────────

    /**
     * GET /api/xray/results/{id}
     */
    public function results(int $id): void
    {
        $this->requireAuth();
        $this->jsonHeader();

        try {
            $accountId = $this->activeAccountId();
            if (!$accountId) {
                $this->error400('Nenhuma conta ML ativa na sessão');
                return;
            }

            $service = new AccountXRayService($accountId);
            $row     = $service->loadReport($id);

            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Relatório não encontrado'], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode(['success' => true, 'report' => $row], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->error500($e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // API: listar relatórios
    // ─────────────────────────────────────────────────────────

    /**
     * GET /api/xray/list?account_id=1&limit=10
     */
    public function list(): void
    {
        $this->requireAuth();
        $this->jsonHeader();

        try {
            $accountId = (int) ($_GET['account_id'] ?? $this->activeAccountId() ?? 0);
            $limit     = min((int) ($_GET['limit'] ?? 10), 50);

            if ($accountId <= 0 || !$this->userOwnsAccount($accountId)) {
                $this->error400('account_id inválido');
                return;
            }

            $service = new AccountXRayService($accountId);
            $reports = $service->listReports($limit);

            echo json_encode([
                'success' => true,
                'reports' => $reports,
                'count'   => count($reports),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->error500($e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // API: scores por item de um relatório
    // ─────────────────────────────────────────────────────────

    /**
     * GET /api/xray/item-scores/{report_id}?sort=seo_score&order=asc&classification=FRACO
     */
    public function itemScores(int $reportId): void
    {
        $this->requireAuth();
        $this->jsonHeader();

        try {
            $db   = Database::getInstance();

            // Verificar que o relatório pertence ao usuário
            $stmt = $db->prepare(
                'SELECT r.id, r.account_id FROM account_xray_reports r
                 JOIN ml_accounts a ON r.account_id = a.id
                 WHERE r.id = :rid AND a.user_id = :uid LIMIT 1'
            );
            $stmt->execute(['rid' => $reportId, 'uid' => $this->userId()]);

            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Relatório não encontrado'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $allowedSorts = [
                'seo_score' => 'seo_score',
                'score_overall' => 'score_overall',
                'classification' => 'classification',
                'visits_30d' => 'visits_30d',
            ];
            $sortKey = (string) ($_GET['sort'] ?? 'seo_score');
            $sortColumn = $allowedSorts[$sortKey] ?? 'seo_score';
            $order = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $classification = trim((string) ($_GET['classification'] ?? ''));

            $sql = "SELECT * FROM xray_item_scores WHERE report_id = :rid";
            $params = ['rid' => $reportId];
            if ($classification !== '') {
                $sql .= " AND classification = :cls";
                $params['cls'] = mb_substr($classification, 0, 32);
            }
            $sql .= " ORDER BY {$sortColumn} {$order} LIMIT 200";

            $stmt2 = $db->prepare($sql);
            $stmt2->execute($params);
            $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSON fields
            foreach ($items as &$item) {
                $item['missing_keywords'] = json_decode($item['missing_keywords_json'] ?? '[]', true) ?? [];
                $item['gap_keywords']     = json_decode($item['gap_keywords_json'] ?? '[]', true) ?? [];
                $item['actions']          = json_decode($item['actions_json'] ?? '[]', true) ?? [];
                unset($item['missing_keywords_json'], $item['gap_keywords_json'], $item['actions_json']);
            }
            unset($item);

            echo json_encode([
                'success' => true,
                'items'   => $items,
                'count'   => count($items),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->error500($e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // PRIVATE helpers
    // ─────────────────────────────────────────────────────────

    private function requireAuth(): void
    {
        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    private function jsonHeader(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
    }

    private function userId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    private function activeAccountId(): ?int
    {
        $id = SessionHelper::getActiveAccountId();
        return ($id !== null && $id > 0) ? $id : null;
    }

    private function userOwnsAccount(int $accountId): bool
    {
        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT id FROM ml_accounts WHERE id = :id AND user_id = :uid LIMIT 1'
            );
            $stmt->execute(['id' => $accountId, 'uid' => $this->userId()]);
            return (bool) $stmt->fetch();
        } catch (\Throwable) {
            return false;
        }
    }

    private function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }
        return json_decode($raw, true) ?? [];
    }

    private function error400(string $message): void
    {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    }

    private function error403(string $message): void
    {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    }

    // ─────────────────────────────────────────────────────────
    // API: Aplicar plano de recuperação
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/xray/apply/{reportId}
     * Body JSON: { "dry_run": true|false, "only_actions": ["PAUSAR","OTIMIZAR_TITULO"] }
     */
    public function applyRecovery(int $reportId): void
    {
        $this->requireAuth();
        $this->jsonHeader();

        if ($reportId <= 0) {
            $this->error400('reportId inválido');
            return;
        }

        // Verificar ownership do relatório
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT r.id, r.account_id, r.status FROM account_xray_reports r
             JOIN ml_accounts a ON r.account_id = a.id
             WHERE r.id = :rid AND a.user_id = :uid LIMIT 1'
        );
        $stmt->execute(['rid' => $reportId, 'uid' => $this->userId()]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Relatório não encontrado'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (($report['status'] ?? '') !== 'completed') {
            $this->error400('Relatório ainda não concluído. Aguarde o término do diagnóstico.');
            return;
        }

        $body       = $this->jsonInput();
        $dryRun     = (bool) ($body['dry_run'] ?? true);
        $onlyActions = array_filter((array) ($body['only_actions'] ?? []), 'is_string');

        try {
            $applier = new AccountRecoveryApplierService();
            $result  = $applier->applyRecoveryPlan($reportId, $dryRun, array_values($onlyActions));

            echo json_encode([
                'success' => true,
                'data'    => $result,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->error500($e->getMessage());
        }
    }

    /**
     * POST /api/xray/queue
     * Enfileira análise assíncrona via xray_job_queue
     * Body JSON: { "account_id": 3 }
     */
    public function queueAnalysis(): void
    {
        $this->requireAuth();
        $this->jsonHeader();

        $body      = $this->jsonInput();
        $accountId = (int) ($body['account_id'] ?? 0);

        if ($accountId <= 0) {
            $this->error400('account_id obrigatório');
            return;
        }

        if (!$this->userOwnsAccount($accountId)) {
            $this->error403('Acesso negado a essa conta');
            return;
        }

        try {
            $db = Database::getInstance();

            // Garantir que a tabela de fila existe
            $db->exec(
                "CREATE TABLE IF NOT EXISTS xray_job_queue (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    status ENUM('queued','processing','completed','failed') DEFAULT 'queued',
                    options_json TEXT,
                    report_id INT DEFAULT NULL,
                    error_message TEXT,
                    pid INT DEFAULT NULL,
                    queued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    started_at TIMESTAMP NULL,
                    completed_at TIMESTAMP NULL,
                    INDEX idx_status (status),
                    INDEX idx_account (account_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            // Verificar job já na fila
            $existStmt = $db->prepare(
                "SELECT id FROM xray_job_queue WHERE account_id = :aid AND status IN ('queued','processing') LIMIT 1"
            );
            $existStmt->execute(['aid' => $accountId]);
            $existingJobId = $existStmt->fetchColumn();

            if ($existingJobId) {
                echo json_encode([
                    'success'  => true,
                    'job_id'   => (int) $existingJobId,
                    'message'  => 'Análise já está na fila',
                    'queued'   => false,
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $rawOptions = $body['options'] ?? [];
            if (!is_array($rawOptions)) {
                $rawOptions = [];
            }
            $safeOptions = $this->sanitizeQueueOptions($rawOptions);
            $safeOptionsJson = json_encode($safeOptions, JSON_UNESCAPED_UNICODE);
            if ($safeOptionsJson === false) {
                $safeOptionsJson = '{}';
            }

            $ins = $db->prepare(
                "INSERT INTO xray_job_queue (account_id, status, options_json) VALUES (:aid, 'queued', :opts)"
            );
            $ins->execute([
                'aid'  => $accountId,
                'opts' => $safeOptionsJson,
            ]);
            $jobId = (int) $db->lastInsertId();

            echo json_encode([
                'success' => true,
                'job_id'  => $jobId,
                'message' => 'Análise enfileirada com sucesso',
                'worker_required' => true,
                'next_step' => 'Execute: php bin/xray-worker.php --queue',
                'queued'  => true,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->error500($e->getMessage());
        }
    }

    /**
     * GET /api/xray/job-status/{jobId}
     * Retorna status de um job assíncrono
     */
    public function jobStatus(int $jobId): void
    {
        $this->requireAuth();
        $this->jsonHeader();

        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT j.id, j.account_id, j.status, j.report_id, j.error_message,
                        j.queued_at, j.started_at, j.completed_at
                 FROM xray_job_queue j
                 JOIN ml_accounts a ON j.account_id = a.id
                 WHERE j.id = :jid AND a.user_id = :uid
                 LIMIT 1'
            );
            $stmt->execute(['jid' => $jobId, 'uid' => $this->userId()]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Job não encontrado'], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode(['success' => true, 'job' => $job], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->error500($e->getMessage());
        }
    }

    /**
     * GET /api/xray/recovery-history/{accountId}
     * Histórico de planos aplicados
     */
    public function recoveryHistory(int $accountId): void
    {
        $this->requireAuth();
        $this->jsonHeader();

        if (!$this->userOwnsAccount($accountId)) {
            $this->error403('Acesso negado');
            return;
        }

        try {
            $applier = new AccountRecoveryApplierService();
            $history = $applier->getApplicationHistory($accountId);

            foreach ($history as &$row) {
                $row['result'] = json_decode($row['result_json'] ?? '{}', true);
                unset($row['result_json']);
            }
            unset($row);

            echo json_encode([
                'success' => true,
                'history' => $history,
                'count'   => count($history),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->error500($e->getMessage());
        }
    }

    private function error500(string $message): void
    {
        try {
            log_error('Account X-Ray internal error', [
                'controller' => __CLASS__,
                'error' => $message,
                'path' => $_SERVER['REQUEST_URI'] ?? null,
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            ]);
        } catch (\Throwable) {
            // Never break response flow on logging failures.
        }

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Erro interno',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function sanitizeQueueOptions(array $options): array
    {
        return [
            'max_items' => max(1, min((int)($options['max_items'] ?? 400), 500)),
            'include_paused' => (bool)($options['include_paused'] ?? true),
            'deep_seo' => (bool)($options['deep_seo'] ?? false),
            'include_financial' => (bool)($options['include_financial'] ?? true),
        ];
    }

    /**
     * GET /api/xray/unlock-plan?account_id=
     * Retorna o plano de destravamento persistido (ou reconstrói a partir do último report).
     */
    public function unlockPlan(): void
    {
        $this->requireAuth();
        $this->jsonHeader();

        try {
            // Este controller não estende BaseController — usar $_GET como nos demais endpoints.
            $accountId = (int) ($_GET['account_id'] ?? $this->activeAccountId() ?? 0);
            if ($accountId <= 0) {
                $this->error400('account_id obrigatório');
                return;
            }
            if (!$this->userOwnsAccount($accountId)) {
                $this->error403('Conta não encontrada ou sem permissão');
                return;
            }

            $service = new \App\Services\AccountUnlockPlanService($accountId);
            $rebuild = (string) ($_GET['rebuild'] ?? '') === '1';

            if ($rebuild) {
                // Rebuild a partir do último relatório Raio X (governance no JSON)
                $stmt = \App\Database::getInstance()->prepare(
                    'SELECT id, report_json FROM account_xray_reports
                     WHERE account_id = ? ORDER BY created_at DESC, id DESC LIMIT 1'
                );
                $stmt->execute([$accountId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$row) {
                    echo json_encode(['success' => false, 'error' => 'Nenhum Raio X disponível — rode um scan primeiro'], JSON_UNESCAPED_UNICODE);
                    return;
                }
                $report = json_decode((string) $row['report_json'], true);
                $gov = is_array($report['governance'] ?? null) ? $report['governance'] : [];
                if ($gov === []) {
                    echo json_encode(['success' => false, 'error' => 'Relatório sem governance'], JSON_UNESCAPED_UNICODE);
                    return;
                }
                $plan = $service->buildAndPersist($gov, (string) $row['id']);
                echo json_encode(['success' => true, 'data' => $plan], JSON_UNESCAPED_UNICODE);
                return;
            }

            $data = $service->loadLatest($accountId);
            echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->error500($e->getMessage());
        }
    }

    /**
     * POST /api/xray/unlock-plan/{mlbId}/resolve?account_id=
     * Marca item como resolvido manualmente (usuário executou no painel ML).
     */
    public function resolveUnlockItem(string $mlbId): void
    {
        $this->requireAuth();
        $this->jsonHeader();

        try {
            $body = $this->jsonInput();
            $accountId = (int) ($_GET['account_id'] ?? $body['account_id'] ?? $this->activeAccountId() ?? 0);
            if ($accountId <= 0) {
                $this->error400('account_id obrigatório');
                return;
            }
            if (!$this->userOwnsAccount($accountId)) {
                $this->error403('Conta não encontrada ou sem permissão');
                return;
            }

            $ok = (new \App\Services\AccountUnlockPlanService($accountId))->markResolved($accountId, $mlbId);
            echo json_encode(['success' => $ok, 'mlb_id' => strtoupper($mlbId)], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->error500($e->getMessage());
        }
    }
}
