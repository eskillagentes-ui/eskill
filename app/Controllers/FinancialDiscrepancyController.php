<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\SessionHelper;
use App\Services\Financial\FinancialReconciliationService;
use App\Services\FinancialService;

/**
 * Endpoints de divergências e caixa do ledger (PATCH 7/8).
 * Rotas registradas em app/Routes/api/integrations.php (loader não-root).
 */
class FinancialDiscrepancyController extends BaseController
{
    private function accountIdOrFail(): ?int
    {
        $accountId = SessionHelper::getActiveAccountId();
        if (!$accountId) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Conta não selecionada']);
            return null;
        }
        return (int)$accountId;
    }

    private function validateDate(string $date): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        return ($dt && $dt->format('Y-m-d') === $date) ? $date : null;
    }

    /**
     * GET /api/financials/discrepancies
     */
    public function listDiscrepancies(): void
    {
        header('Content-Type: application/json');
        $accountId = $this->accountIdOrFail();
        if ($accountId === null) {
            return;
        }
        try {
            $start = $this->validateDate((string)($_GET['start'] ?? date('Y-m-d', strtotime('-30 days'))))
                ?? date('Y-m-d', strtotime('-30 days'));
            $end = $this->validateDate((string)($_GET['end'] ?? date('Y-m-d'))) ?? date('Y-m-d');
            $status = array_key_exists('status', $_GET) ? (string)$_GET['status'] : 'open';
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));

            $svc = new FinancialReconciliationService($accountId);
            $rows = $svc->listOpen($start, $end, $status === '' ? null : $status, $limit);
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (\Throwable $e) {
            $this->respondInternalError($e, __METHOD__);
        }
    }

    /**
     * POST /api/financials/discrepancies/{id}/resolve
     */
    public function resolveDiscrepancy(int $id): void
    {
        header('Content-Type: application/json');
        $accountId = $this->accountIdOrFail();
        if ($accountId === null) {
            return;
        }
        try {
            $body = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($body)) {
                $body = $_POST;
            }
            $action = (string)($body['action'] ?? '');
            $note = isset($body['note']) ? (string)$body['note'] : null;
            $userId = SessionHelper::getUserId();

            $svc = new FinancialReconciliationService($accountId);
            $result = $svc->resolveDiscrepancy($id, $action, $userId ? (int)$userId : null, $note);
            if (empty($result['success'])) {
                http_response_code(400);
            }
            echo json_encode(['success' => !empty($result['success'])] + $result);
        } catch (\Throwable $e) {
            $this->respondInternalError($e, __METHOD__);
        }
    }

    /**
     * GET /api/financials/cash-ledger?start=&end=
     */
    public function getCashLedgerSummary(): void
    {
        header('Content-Type: application/json');
        $accountId = $this->accountIdOrFail();
        if ($accountId === null) {
            return;
        }
        $start = $this->validateDate((string)($_GET['start'] ?? '')) ?? null;
        $end = $this->validateDate((string)($_GET['end'] ?? '')) ?? null;
        if ($start === null || $end === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Parâmetros start/end inválidos (Y-m-d)']);
            return;
        }
        try {
            $svc = new FinancialService($accountId);
            $pnl = $svc->getPnL($start, $end . ' 23:59:59');
            echo json_encode([
                'success' => true,
                'data' => $pnl['cash'] ?? null,
                'advertising_expenses' => $pnl['advertising_expenses'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            $this->respondInternalError($e, __METHOD__);
        }
    }

    /**
     * GET /api/financials/cash-timeline?start=&end=
     * Linha do tempo diária de caixa (liberado / pendente / sacado / hold / ads).
     */
    public function getCashTimeline(): void
    {
        header('Content-Type: application/json');
        $accountId = $this->accountIdOrFail();
        if ($accountId === null) {
            return;
        }
        $start = $this->validateDate((string)($_GET['start'] ?? '')) ?? null;
        $end = $this->validateDate((string)($_GET['end'] ?? '')) ?? null;
        if ($start === null || $end === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Parâmetros start/end inválidos (Y-m-d)']);
            return;
        }
        try {
            $db = \App\Database::getInstance();
            $stmt = $db->prepare(
                "SELECT DATE(COALESCE(released_at, occurred_at)) AS day,
                        entry_type,
                        status,
                        COUNT(*) AS entries,
                        ROUND(SUM(ABS(signed_amount)), 2) AS amount
                 FROM financial_ledger_entries
                 WHERE account_id = :account_id
                   AND entry_type IN (
                     'settlement_release', 'withdrawal', 'program_hold',
                     'advertising_fee', 'advertising_fee_reversal'
                   )
                   AND COALESCE(released_at, occurred_at) >= :start
                   AND COALESCE(released_at, occurred_at) < DATE_ADD(:end, INTERVAL 1 DAY)
                 GROUP BY day, entry_type, status
                 ORDER BY day ASC, entry_type ASC"
            );
            $stmt->execute([
                ':account_id' => $accountId,
                ':start' => $start . ' 00:00:00',
                ':end' => $end,
            ]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (\Throwable $e) {
            $this->respondInternalError($e, __METHOD__);
        }
    }

    private function respondInternalError(\Throwable $exception, string $context): void
    {
        $this->logError($exception, $context);
        $this->jsonError('Erro interno do servidor', 500);
    }
}
