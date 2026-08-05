<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Database;
use App\Services\Pregao\PregaoAccountAuthorizer;
use App\Services\Pregao\PregaoEventExplorerService;
use App\Services\Pregao\PregaoSnapshotService;
use App\Services\Pregao\PregaoStreamService;
use App\Services\UserService;
use InvalidArgumentException;
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

        $accountId = $this->resolveAccountId();
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

        $accountId = $this->resolveAccountId();
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
     * GET /api/pregao/events — histórico paginado e sanitizado.
     */
    public function events(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        if (!$this->requireAuthJson()) {
            return;
        }

        $accountId = $this->resolveAccountId();
        if ($accountId === null) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Conta indisponível',
                'message' => 'Não foi possível autorizar a conta solicitada',
                'data' => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $filters = [];
        foreach (['page', 'per_page', 'type', 'source', 'from', 'to'] as $filter) {
            $value = $this->request->get($filter);
            if ($value !== null && $value !== '') {
                $filters[$filter] = $value;
            }
        }

        try {
            $config = require dirname(__DIR__, 2) . '/config/pregao.php';
            $service = new PregaoEventExplorerService(
                Database::getInstance(),
                (bool) ($config['seed_enabled'] ?? false)
            );
            echo json_encode([
                'success' => true,
                'error' => null,
                'message' => 'Eventos carregados',
                'data' => $service->listForAccount($accountId, $filters),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (InvalidArgumentException) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error' => 'Filtros inválidos',
                'message' => 'Revise os filtros do histórico',
                'data' => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            log_error('Pregao event explorer failed', ['reason' => 'event_explorer_exception']);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Histórico indisponível',
                'message' => 'Não foi possível consultar os eventos',
                'data' => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

        $accountId = $this->resolveAccountId();
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

        $accountId = $this->resolveAccountId();
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
        $fromQuery = $this->request->get('account_id');
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

    /**
     * Fallback: primeira conta ML ativa do usuário autenticado.
     * Evita snapshot 400 + gráfico vazio quando a sessão não tem active_ml_account_id.
     */
    private function resolveFallbackAccountId(): ?int
    {
        $userId = $this->getUserId();
        if ($userId === null || $userId <= 0) {
            return null;
        }

        try {
            $db = \App\Database::getInstance();
            $stmt = $db->prepare(
                "SELECT id FROM ml_accounts
                 WHERE user_id = ?
                   AND (status IN ('active', 'connected') OR status IS NULL)
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute([$userId]);
            $id = $stmt->fetchColumn();
            if ($id === false || (int) $id <= 0) {
                return null;
            }
            $accountId = (int) $id;
            $_SESSION['active_ml_account_id'] = $accountId;
            $_SESSION['account_id'] = $accountId;
            return $accountId;
        } catch (Throwable $e) {
            return null;
        }
    }
}
