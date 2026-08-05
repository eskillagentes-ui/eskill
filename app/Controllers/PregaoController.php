<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Services\Pregao\PregaoAccountAuthorizer;
use App\Services\Pregao\PregaoEventExplorerService;
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
        if ($accountId === null) {
            http_response_code(403);
            return;
        }
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

}
