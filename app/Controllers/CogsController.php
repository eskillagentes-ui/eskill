<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\SessionHelper;
use App\Services\Financial\CogsService;

/**
 * API de CMV — cadastro local (sku_custos). Zero escrita no Mercado Livre.
 */
final class CogsController extends BaseController
{
    private CogsService $service;
    private ?int $accountId;

    public function __construct()
    {
        parent::__construct();
        $accountId = $this->request->get('account_id') ?? SessionHelper::getActiveAccountId();
        $this->accountId = $accountId ? (int) $accountId : null;
        $this->service = new CogsService();
    }

    /** GET /api/cogs/audit */
    public function audit(): void
    {
        if (!$this->accountId) {
            $this->json(['success' => false, 'error' => 'Conta ML não selecionada'], 400);
            return;
        }

        $days = $this->request->getInt('days', 90);
        $data = $this->service->auditSoldItems($this->accountId, $days);
        $this->json(['success' => true, 'data' => $data]);
    }

    /** PUT /api/cogs/{mlbId} */
    public function upsert(string $mlbId): void
    {
        if (!$this->accountId) {
            $this->json(['success' => false, 'error' => 'Conta ML não selecionada'], 400);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['unit_cost'])) {
            $this->json(['success' => false, 'error' => 'unit_cost obrigatório'], 400);
            return;
        }

        $note = isset($body['note']) ? (string) $body['note'] : null;
        $result = $this->service->upsertUnitCost(
            $this->accountId,
            $mlbId,
            (float) $body['unit_cost'],
            $note
        );

        $this->json(
            ['success' => $result['success'], 'data' => $result, 'error' => $result['message'] ?? null],
            $result['success'] ? 200 : 422
        );
    }

    /** POST /api/cogs/import */
    public function importCsv(): void
    {
        if (!$this->accountId) {
            $this->json(['success' => false, 'error' => 'Conta ML não selecionada'], 400);
            return;
        }

        $csv = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $csv = (string) file_get_contents($_FILES['file']['tmp_name']);
        } else {
            $body = json_decode((string) file_get_contents('php://input'), true);
            $csv = is_array($body) ? (string) ($body['csv'] ?? '') : '';
        }

        if (trim($csv) === '') {
            $this->json(['success' => false, 'error' => 'CSV vazio'], 400);
            return;
        }

        $result = $this->service->importCsv($this->accountId, $csv);
        $this->json(['success' => $result['success'], 'data' => $result]);
    }
}
