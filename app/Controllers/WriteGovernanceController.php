<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\SessionHelper;
use App\Services\Ads\AdsReadinessService;
use App\Services\ML\MLWriteGateway;
use App\Services\ML\WriteEnablementChecklist;
use App\Services\Rank\RankTrackerService;
use App\Services\SEO\SeoKpiService;

/**
 * Painel de Governança de Escrita + APIs de suporte Onda 4 (read-only / dry-run).
 */
final class WriteGovernanceController extends BaseController
{
    public function index(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }

        $pageTitle = 'Governança de Escrita';
        $accountId = (int) (SessionHelper::getActiveAccountId() ?? 0);
        $gateway = new MLWriteGateway(forceDryRun: true);
        $checklist = (new WriteEnablementChecklist())->evaluate($accountId > 0 ? $accountId : 0);
        $flags = $gateway->actionFlags();
        $killSwitch = $gateway->isWriteAutomationEnabled();
        $allowlist = $accountId > 0 ? $gateway->listAllowlist($accountId) : [];
        $audit = $accountId > 0 ? $gateway->recentAudit($accountId, 40) : [];
        $daily = $accountId > 0 ? $gateway->countWritesToday($accountId) : 0;
        $maxDaily = $gateway->maxWritesPerDay();
        $rankStatus = $accountId > 0 ? (new RankTrackerService())->statusForPregao($accountId) : [];

        ob_start();
        require __DIR__ . '/../Views/dashboard/write-governance.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    /** GET /api/write-governance/checklist */
    public function checklist(): void
    {
        $accountId = (int) ($this->request->get('account_id') ?? SessionHelper::getActiveAccountId() ?? 0);
        if ($accountId <= 0) {
            $this->json(['success' => false, 'error' => 'account_id obrigatório'], 400);
            return;
        }
        $data = (new WriteEnablementChecklist())->evaluate($accountId);
        $this->json(['success' => true, 'data' => $data]);
    }

    /** GET /api/write-governance/audit */
    public function audit(): void
    {
        $accountId = (int) ($this->request->get('account_id') ?? SessionHelper::getActiveAccountId() ?? 0);
        if ($accountId <= 0) {
            $this->json(['success' => false, 'error' => 'account_id obrigatório'], 400);
            return;
        }
        $gw = new MLWriteGateway(forceDryRun: true);
        $this->json([
            'success' => true,
            'data' => [
                'kill_switch' => $gw->isWriteAutomationEnabled(),
                'flags' => $gw->actionFlags(),
                'daily' => $gw->countWritesToday($accountId),
                'max_daily' => $gw->maxWritesPerDay(),
                'allowlist' => $gw->listAllowlist($accountId),
                'audit' => $gw->recentAudit($accountId, 50),
            ],
        ]);
    }

    /** POST /api/write-governance/dry-run — demonstra intenção fail-closed */
    public function dryRunDemo(): void
    {
        $accountId = (int) ($this->request->get('account_id') ?? SessionHelper::getActiveAccountId() ?? 0);
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = [];
        }
        $mlbId = strtoupper((string) ($body['mlb_id'] ?? ''));
        $action = (string) ($body['action'] ?? MLWriteGateway::ACTION_PAUSE);
        if ($accountId <= 0 || $mlbId === '') {
            $this->json(['success' => false, 'error' => 'account_id e mlb_id obrigatórios'], 400);
            return;
        }

        $gw = new MLWriteGateway(forceDryRun: true);
        // Allowlist temporária só para o demo dry-run se pedida
        if (!empty($body['add_allowlist'])) {
            $gw->addToAllowlist($accountId, $mlbId, (int) ($_SESSION['user_id'] ?? 0));
        }

        $result = $gw->execute($action, [
            'mlb_id' => $mlbId,
            'status' => 'paused',
            'reason' => (string) ($body['reason'] ?? 'dry-run Onda 4'),
        ], [
            'account_id' => $accountId,
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'mlb_id' => $mlbId,
            'dry_run' => true,
            'before' => ['status' => 'active'],
            'expected_after' => ['status' => 'paused'],
        ]);

        $this->json(['success' => true, 'data' => $result]);
    }

    /** GET /api/ads/readiness */
    public function adsReadiness(): void
    {
        $accountId = (int) ($this->request->get('account_id') ?? SessionHelper::getActiveAccountId() ?? 0);
        if ($accountId <= 0) {
            $this->json(['success' => false, 'error' => 'account_id obrigatório'], 400);
            return;
        }
        $data = (new AdsReadinessService())->recommendationQueue($accountId);
        $this->json(['success' => true, 'data' => $data]);
    }

    /** GET /api/seo/kpi/interventions */
    public function seoKpiList(): void
    {
        $accountId = (int) ($this->request->get('account_id') ?? SessionHelper::getActiveAccountId() ?? 0);
        if ($accountId <= 0) {
            $this->json(['success' => false, 'error' => 'account_id obrigatório'], 400);
            return;
        }
        $list = (new SeoKpiService())->listInterventions($accountId);
        $this->json(['success' => true, 'data' => ['items' => $list]]);
    }

    /** POST /api/seo/kpi/baseline */
    public function seoKpiBaseline(): void
    {
        $accountId = (int) ($this->request->get('account_id') ?? SessionHelper::getActiveAccountId() ?? 0);
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $mlbId = strtoupper((string) ($body['mlb_id'] ?? ''));
        $tipo = (string) ($body['tipo'] ?? 'hidden_seo');
        if ($accountId <= 0 || $mlbId === '') {
            $this->json(['success' => false, 'error' => 'account_id e mlb_id obrigatórios'], 400);
            return;
        }
        $data = (new SeoKpiService())->captureBaseline($accountId, $mlbId, $tipo, ['source' => 'onda4']);
        $this->json(['success' => true, 'data' => $data]);
    }

    /** GET /api/rank/status */
    public function rankStatus(): void
    {
        $accountId = (int) ($this->request->get('account_id') ?? SessionHelper::getActiveAccountId() ?? 0);
        if ($accountId <= 0) {
            $this->json(['success' => false, 'error' => 'account_id obrigatório'], 400);
            return;
        }
        $svc = new RankTrackerService();
        $this->json([
            'success' => true,
            'data' => [
                'enabled' => $svc->isEnabled(),
                'status' => $svc->statusForPregao($accountId),
                'latest' => $svc->latestCaptures($accountId, 10),
            ],
        ]);
    }

    /** GET /api/rank/history/{mlbId} */
    public function rankHistory(string $mlbId): void
    {
        $accountId = (int) ($this->request->get('account_id') ?? SessionHelper::getActiveAccountId() ?? 0);
        if ($accountId <= 0) {
            $this->json(['success' => false, 'error' => 'account_id obrigatório'], 400);
            return;
        }
        $days = (int) ($this->request->get('days') ?? 30);
        $rows = (new RankTrackerService())->historyForItem($accountId, $mlbId, $days);
        $this->json(['success' => true, 'data' => ['items' => $rows]]);
    }
}
