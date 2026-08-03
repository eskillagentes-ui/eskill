<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Sentinela\Sentinela;
use App\Services\UserService;
use Throwable;

/**
 * Painel Sentinela — grade read-only dos 11 riscos de conta.
 */
class SentinelaController extends BaseController
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

    /** GET /dashboard/sentinela */
    public function index(): void
    {
        if ($this->userService === null || !$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $accountId = $this->getActiveAccountId();
        $pageTitle = 'Sentinela';
        $currentPage = 'sentinela';
        $sentinelaAccountId = $accountId;

        $dash = [
            'semaforo' => 'verde',
            'monitored' => 0,
            'total' => 11,
            'pode_expandir' => true,
            'motivo_veto' => null,
            'risks' => [],
            'history' => [],
        ];
        if ($accountId !== null && $accountId > 0) {
            try {
                $dash = (new Sentinela())->getDashboard($accountId);
            } catch (Throwable $e) {
                $dash['motivo_veto'] = 'erro ao carregar: ' . $e->getMessage();
            }
        }

        ob_start();
        require __DIR__ . '/../Views/dashboard/sentinela.php';
        $content = ob_get_clean();

        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    /** GET /api/sentinela/snapshot */
    public function snapshot(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');

        if ($this->userService === null || !$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado', 'data' => null]);
            return;
        }

        $accountId = $this->getActiveAccountId();
        if ($accountId === null || $accountId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta selecionada', 'data' => null]);
            return;
        }

        try {
            $data = (new Sentinela())->getDashboard($accountId);
            echo json_encode(['success' => true, 'data' => $data, 'error' => null]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage(), 'data' => null]);
        }
    }
}
