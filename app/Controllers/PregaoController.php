<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\BaseController;
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

    public function __construct()
    {
        parent::__construct();
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
            $accountId = $this->resolveFallbackAccountId();
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
            $accountId = $this->resolveFallbackAccountId();
        }
        if ($accountId === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta selecionada', 'data' => null]);
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
        } catch (Throwable $e) {
            log_error('Pregao snapshot failed', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Falha ao montar snapshot', 'data' => null]);
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
            $accountId = $this->resolveFallbackAccountId();
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
            $accountId = $this->resolveFallbackAccountId();
        }
        if ($accountId === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta selecionada']);
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
        if ($fromQuery !== null && (int) $fromQuery > 0) {
            return (int) $fromQuery;
        }
        return $this->getActiveAccountId();
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
