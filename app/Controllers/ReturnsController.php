<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Security\AccountAccessException;
use App\Security\AccountContextResolver;
use App\Services\ClaimsService;
use App\Services\MercadoLivreClient;
use App\Services\UserService;

/**
 * Controller de Devoluções (RMA)
 *
 * Gerencia o painel de devoluções e logística reversa.
 */
class ReturnsController extends BaseController
{
    private ClaimsService $claimsService;
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        parent::__construct();
        $this->userService = $userService;
        $accountId = null;
        try {
            $accountId = (new AccountContextResolver())
                ->authorizeForCurrentActor('returns.read')
                ->accountId();
        } catch (AccountAccessException $e) {
            $accountId = null;
        }
        $this->claimsService = new ClaimsService($accountId);
    }

    /**
     * GET /dashboard/returns
     * Renderiza o painel de devoluções.
     */
    public function index(): void
    {
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        try {
            $context = (new AccountContextResolver())->authorizeForCurrentActor('returns.read');
            $client = MercadoLivreClient::fromAuthorizedContext($context);
        } catch (AccountAccessException $e) {
            http_response_code($e->httpStatus());
            echo $e->getMessage();
            return;
        }

        // Fetch opened claims (pending triage)
        $pendingRaw = $this->fetchClaims($client, 'opened');
        $pending    = $pendingRaw['data'] ?? ($pendingRaw['results'] ?? []);

        // Fetch closed/resolved claims (history)
        $historyRaw = $this->fetchClaims($client, 'closed');
        $history    = $historyRaw['data'] ?? ($historyRaw['results'] ?? []);

        $pageTitle = 'Devoluções & RMA';
        ob_start();
        require __DIR__ . '/../Views/dashboard/returns/index.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    /**
     * Busca reclamações da API do Mercado Livre por status.
     *
     * @param MercadoLivreClient $client
     * @param string             $status  'opened' ou 'closed'
     * @return array
     */
    private function fetchClaims(MercadoLivreClient $client, string $status): array
    {
        try {
            $response = $client->get('/post-purchase/v1/claims', [
                'limit'  => 50,
                'offset' => 0,
                'status' => $status,
            ]);

            if (isset($response['error'])) {
                return [];
            }

            return $response;
        } catch (\Throwable $e) {
            logger()->warning('ReturnsController: failed to fetch claims', [
                'status' => $status,
                'error'  => $e->getMessage(),
            ]);
            return [];
        }
    }
}
