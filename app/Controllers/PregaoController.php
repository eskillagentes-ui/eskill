<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Services\Pregao\PregaoAccountAuthorizer;
use App\Services\Pregao\PregaoEventExplorerService;
use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaRunService;
use App\Services\Pregao\PregaoSnapshotService;
use App\Services\Pregao\PregaoStreamService;
use App\Services\UserService;
use Throwable;

/**
 * Pregão — painel read-only em tempo real.
 *
 * Nenhum endpoint desta classe escreve no Mercado Livre.
 */
class PregaoController extends BaseController
{
    private ?UserService $userService = null;
    private PregaoAccountAuthorizer $accountAuthorizer;

    public function __construct()
    {
        parent::__construct();
        $this->accountAuthorizer = new PregaoAccountAuthorizer();
        try {
            $this->userService = new UserService();
        } catch (Throwable $e) {
            $this->userService = null;
        }
    }

    /**
     * GET /dashboard/pregao
     */
    public function index(): void
    {
        if ($this->userService === null || !$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $accountId = $this->resolveAccountIdBoundary(false);
        if ($accountId === false) {
            return;
        }
        // Sem conta ML: ainda renderiza o shell read-only com empty-state
        // (evita 403 vazio no staging / TestSprite — TC008).
        $pageTitle = 'Pregão';
        $currentPage = 'pregao';
        $pregaoAccountId = $accountId ?? 0;

        ob_start();
        require __DIR__ . '/../Views/dashboard/pregao.php';
        $content = ob_get_clean();

        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    /**
     * GET /api/pregao/snapshot
     */
    public function snapshot(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        if (!$this->requireAuthJson()) {
            return;
        }

        $accountId = $this->resolveAccountIdBoundary();
        if ($accountId === false) {
            return;
        }
        if ($accountId === null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Conta indisponível', 'data' => null]);
            return;
        }

        $started = microtime(true);
        try {
            $service = new PregaoSnapshotService();
            $data = $service->getSnapshot($accountId);
            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            echo json_encode([
                'success' => true,
                'data' => $data,
                'meta' => ['elapsed_ms' => $elapsedMs, 'read_only' => true],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            log_error('Pregao snapshot failed', ['reason' => 'snapshot_exception']);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Falha ao montar snapshot', 'data' => null]);
        }
    }

    /**
     * GET /api/pregao/events — Event Explorer read-only (paginado, filtrável).
     */
    public function events(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        if (!$this->requireAuthJson()) {
            return;
        }

        try {
            $accountId = $this->resolveAccountId();
            if ($accountId === null) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Conta indisponível', 'data' => null]);
                return;
            }

            $service = new PregaoEventExplorerService();
            $data = $service->list($accountId, [
                'type' => $this->request->getScalar('type'),
                'source' => $this->request->getScalar('source'),
                'from' => $this->request->getScalar('from'),
                'to' => $this->request->getScalar('to'),
                'page' => $this->request->getScalar('page'),
                'per_page' => $this->request->getScalar('per_page'),
            ]);
            echo json_encode(
                ['success' => true, 'data' => $data, 'meta' => ['read_only' => true]],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (\InvalidArgumentException) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Filtro inválido', 'data' => null]);
        } catch (Throwable) {
            log_error('Pregao events failed', ['reason' => 'events_exception']);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Falha ao listar eventos', 'data' => null]);
        }
    }

    /**
     * GET /api/pregao/stream — SSE fallback (Redis Pub/Sub).
     */
    public function stream(): void
    {
        if (!$this->requireAuthJson(false)) {
            return;
        }

        $accountId = $this->resolveAccountIdBoundary(false);
        if ($accountId === false) {
            return;
        }
        if ($accountId === null) {
            http_response_code(403);
            return;
        }
        $service = new PregaoStreamService();
        $service->streamSse($accountId);
    }

    /**
     * GET /api/pregao/ticket — ticket curto para /ws/pregao
     */
    public function ticket(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (!$this->requireAuthJson()) {
            return;
        }

        $accountId = $this->resolveAccountIdBoundary();
        if ($accountId === false) {
            return;
        }
        if ($accountId === null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Conta indisponível']);
            return;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0 && $this->userService !== null) {
            try {
                $user = $this->userService->getCurrentUser();
                $userId = (int) ($user['id'] ?? 0);
            } catch (Throwable $e) {
                $userId = 0;
            }
        }

        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Usuário inválido']);
            return;
        }

        try {
            $service = new PregaoStreamService();
            $ticket = $service->issueTicket($userId, $accountId);
            echo json_encode([
                'success' => true,
                'data' => [
                    'ticket' => $ticket,
                    'ws_path' => '/ws/pregao',
                    'ttl_seconds' => 120,
                    'account_id' => $accountId,
                ],
            ]);
        } catch (Throwable $e) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'error' => 'Ticket WS indisponível — use SSE /api/pregao/stream',
                'fallback' => '/api/pregao/stream',
            ]);
        }
    }

    /** POST /api/pregao/qa/run — CSRF é aplicado globalmente em public/index.php. */
    public function qaRun(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        if (!$this->requireAuthJson()) {
            return;
        }
        $accountId = $this->resolveAccountIdBoundary();
        if ($accountId === false) {
            return;
        }
        if ($accountId === null) {
            $this->jsonError('Conta indisponível', 403);
            return;
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->jsonError('Usuário inválido', 401);
            return;
        }
        try {
            $proof = PregaoQaProof::fromEnvironment();
            if ($proof === null) {
                throw new \RuntimeException('qa_signing_unavailable');
            }
            $service = new PregaoQaRunService(PregaoQaRunService::connectRedis(), $proof);
            $run = $service->startRun($accountId, $userId);
            $this->json(['success' => true, 'data' => [
                'trusted' => true,
                'run_id' => $run['run_id'],
                'status' => 'queued',
                'step' => null,
                'elapsed_ms' => 0,
                'result' => null,
            ]], 202);
        } catch (\DomainException) {
            $this->jsonError('Já existe QA em execução para esta conta', 409);
        } catch (Throwable) {
            log_error('Pregao QA run failed', ['reason' => 'qa_run_unavailable']);
            $this->jsonError('QA live indisponível', 503);
        }
    }

    /** GET /qa/live/{runId} */
    public function qaLive(string $runId): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if (!$this->requireAuthJson(false)) {
            return;
        }
        $accountId = $this->resolveAccountIdBoundary(false);
        if ($accountId === false) {
            return;
        }
        if ($accountId === null || preg_match(PregaoQaRunService::RUN_ID_PATTERN, $runId) !== 1) {
            http_response_code(404);
            return;
        }
        try {
            $proof = PregaoQaProof::fromEnvironment();
            if ($proof === null) {
                throw new \RuntimeException('qa_signing_unavailable');
            }
            $service = new PregaoQaRunService(PregaoQaRunService::connectRedis(), $proof);
            if (!$service->isMediaAuthorized($runId, $accountId)) {
                http_response_code(404);
                return;
            }
            $nonce = defined('CSP_NONCE') ? (string) CSP_NONCE : '';
            if ($nonce === '') {
                throw new \RuntimeException('csp_nonce_unavailable');
            }
            $frameUrl = json_encode('/qa/frame/' . $runId, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $safeNonce = htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>QA ao vivo</title></head>'
                . '<body><img id="qa-live-frame" alt="Frame real do QA Playwright">'
                . '<script nonce="' . $safeNonce . '">(function(){const img=document.getElementById("qa-live-frame");'
                . 'const base=' . $frameUrl . ';function refresh(){img.src=base+"?v="+Date.now();}'
                . 'refresh();setInterval(refresh,1000);}());</script></body></html>';
        } catch (Throwable) {
            http_response_code(404);
        }
    }

    /** GET /qa/frame/{runId} */
    public function qaFrame(string $runId): void
    {
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if (!$this->requireAuthJson(false)) {
            return;
        }
        $accountId = $this->resolveAccountIdBoundary(false);
        if ($accountId === false) {
            return;
        }
        if ($accountId === null || preg_match(PregaoQaRunService::RUN_ID_PATTERN, $runId) !== 1) {
            http_response_code(404);
            return;
        }
        try {
            $proof = PregaoQaProof::fromEnvironment();
            if ($proof === null) {
                throw new \RuntimeException('qa_signing_unavailable');
            }
            $service = new PregaoQaRunService(PregaoQaRunService::connectRedis(), $proof);
            if (!$service->isMediaAuthorized($runId, $accountId)) {
                http_response_code(404);
                return;
            }
            $root = dirname(__DIR__, 2) . '/storage/private/pregao-qa';
            $frame = PregaoQaRunService::readLatestFrame($root, $runId);
            if ($frame === null) {
                http_response_code(404);
                return;
            }
            $info = @getimagesizefromstring($frame);
            if (!is_array($info) || ($info[2] ?? null) !== IMAGETYPE_PNG) {
                http_response_code(404);
                return;
            }
            header('Content-Type: image/png');
            header('Content-Length: ' . (string) strlen($frame));
            echo $frame;
        } catch (Throwable) {
            http_response_code(404);
        }
    }

    private function requireAuthJson(bool $sendJson = true): bool
    {
        if ($this->userService === null || !$this->userService->isAuthenticated()) {
            http_response_code(401);
            if ($sendJson) {
                echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            }
            return false;
        }
        return true;
    }

    private function resolveAccountId(): ?int
    {
        $fromQuery = $this->request->getScalar('account_id');
        $requestedId = null;
        if ($fromQuery !== null) {
            if ((!is_int($fromQuery) && !is_string($fromQuery))
                || filter_var($fromQuery, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            ) {
                return null;
            }
            $requestedId = (int) $fromQuery;
        }

        if ($this->userService === null) {
            return null;
        }

        try {
            $accounts = $this->userService->getUserAccounts();
        } catch (Throwable) {
            log_warning('Pregao account scope failed', ['reason' => 'account_list_unavailable']);
            return null;
        }

        return $this->accountAuthorizer->resolve(
            $requestedId,
            $this->getActiveAccountId(),
            $accounts
        );
    }

    private function resolveAccountIdBoundary(bool $sendJson = true): int|false|null
    {
        try {
            return $this->resolveAccountId();
        } catch (\InvalidArgumentException) {
            http_response_code(400);
            if ($sendJson) {
                echo json_encode(['success' => false, 'error' => 'Parâmetro account_id inválido']);
            }
            return false;
        }
    }

    /** GET /api/pregao/watchlist */
    public function watchlistList(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requireAuthJson()) {
            return;
        }
        $accountId = $this->resolveAccountIdBoundary();
        if ($accountId === false || $accountId === null) {
            if ($accountId === null) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Conta indisponível']);
            }
            return;
        }

        $items = (new \App\Services\Pregao\PregaoWatchlistCollector())->listActive($accountId);
        echo json_encode([
            'success' => true,
            'data' => ['items' => $items, 'count' => count($items)],
            'meta' => ['read_only' => false, 'ml_write' => false],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** POST /api/pregao/watchlist  body: {mlb_id, apelido?, keyword?} */
    public function watchlistAdd(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requireAuthJson()) {
            return;
        }
        $accountId = $this->resolveAccountIdBoundary();
        if ($accountId === false || $accountId === null) {
            if ($accountId === null) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Conta indisponível']);
            }
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body['mlb_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'mlb_id obrigatório']);
            return;
        }

        try {
            $id = (new \App\Services\Pregao\PregaoWatchlistCollector())->upsert($accountId, [
                'mlb_id' => (string) $body['mlb_id'],
                'apelido' => (string) ($body['apelido'] ?? $body['mlb_id']),
                'keyword_alvo' => isset($body['keyword']) ? (string) $body['keyword'] : null,
            ]);
            echo json_encode(['success' => true, 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Falha ao adicionar']);
        }
    }

    /**
     * POST /api/pregao/watchlist/seed
     * body: {keywords?: string[], per_keyword?: int, insert?: bool}
     * Default keywords: categorias FACILYTY (bagageiro, guidão, cavalete).
     */
    public function watchlistSeed(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requireAuthJson()) {
            return;
        }
        $accountId = $this->resolveAccountIdBoundary();
        if ($accountId === false || $accountId === null) {
            if ($accountId === null) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Conta indisponível']);
            }
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : [];
        $keywords = $body['keywords'] ?? [
            'bagageiro cg 160',
            'guidão cg 160',
            'cavalete lateral biz',
        ];
        if (!is_array($keywords)) {
            $keywords = [];
        }
        $per = (int) ($body['per_keyword'] ?? 5);
        $insert = array_key_exists('insert', $body) ? (bool) $body['insert'] : true;

        $result = (new \App\Services\Pregao\PregaoWatchlistCollector())
            ->seedFromKeywords($accountId, $keywords, $per, $insert);

        echo json_encode([
            'success' => true,
            'data' => $result,
            'meta' => ['ml_write' => false, 'read_only_api' => true],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** DELETE /api/pregao/watchlist/{mlbId} */
    public function watchlistRemove(string $mlbId): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requireAuthJson()) {
            return;
        }
        $accountId = $this->resolveAccountIdBoundary();
        if ($accountId === false || $accountId === null) {
            if ($accountId === null) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Conta indisponível']);
            }
            return;
        }

        $ok = (new \App\Services\Pregao\PregaoWatchlistCollector())->deactivate($accountId, $mlbId);
        echo json_encode(['success' => $ok, 'data' => ['mlb_id' => strtoupper($mlbId)]]);
    }

    /** POST /api/pregao/watchlist/collect — dispara coleta local (API read-only ML) */
    public function watchlistCollect(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requireAuthJson()) {
            return;
        }
        $accountId = $this->resolveAccountIdBoundary();
        if ($accountId === false || $accountId === null) {
            if ($accountId === null) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Conta indisponível']);
            }
            return;
        }

        $result = (new \App\Services\Pregao\PregaoWatchlistCollector())->collect($accountId);
        echo json_encode([
            'success' => true,
            'data' => $result,
            'meta' => ['ml_write' => false],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/pregao/listing-apply/simulate
     * Dry-run only. Body {mlb}. Ignores account_id da request e apply=true.
     */
    public function listingApplySimulate(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        if (!$this->requireAuthJson()) {
            return;
        }
        $accountId = $this->getActiveAccountId();
        if ($accountId === null || $accountId <= 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Conta indisponível', 'ml_write' => false]);
            return;
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        $mlb = is_array($body) ? (string) ($body['mlb'] ?? $body['mlb_id'] ?? '') : '';
        try {
            $svc = new \App\Services\ListingApply\ListingApplyJobService(\App\Database::getInstance());
            $row = $svc->run($accountId, $mlb, false);
            echo json_encode([
                'success' => $row['status'] === \App\Services\ListingApply\ListingApplyJobService::STATUS_DRY_RUN,
                'data' => $row,
                'meta' => ['ml_write' => false, 'api_called' => false, 'dry_run' => true],
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Falha ao simular', 'ml_write' => false]);
        }
    }

}
