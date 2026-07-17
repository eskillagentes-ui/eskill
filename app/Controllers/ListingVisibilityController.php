<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Helpers\SessionHelper;
use App\Services\MercadoLivre\ListingIrregularityScanService;
use App\Services\MercadoLivre\ListingSearchVisibilityService;
use App\Services\MercadoLivreClient;
use PDO;

/**
 * API + dashboard read-only: irregularidades ML + fila de ativação de busca (SEO oficial /performance).
 *
 * Nunca envia PUT/PATCH de anúncio ao Mercado Livre.
 */
final class ListingVisibilityController extends BaseController
{
    /**
     * GET /dashboard/listing-visibility
     */
    public function index(): void
    {
        $this->renderView('dashboard/listing-visibility', [
            'pageTitle' => 'Visibilidade e Irregularidades',
            'currentPage' => 'listing-visibility',
            'activePage' => 'listing-visibility',
        ]);
    }

    /**
     * GET /api/listings/search-visibility/{itemId}
     */
    public function analyzeItem(string $itemId): void
    {
        try {
            $accountId = $this->resolveOwnedAccountId();
            if ($accountId === null) {
                $this->jsonError('Conta não autorizada ou não selecionada', 403);
                return;
            }

            $client = new MercadoLivreClient($accountId);
            $service = new ListingSearchVisibilityService($client);
            $result = $service->analyzeListing($itemId);

            if (isset($result['error']) && ($result['error'] === 'invalid_item_id')) {
                $this->jsonError((string) ($result['message'] ?? 'item inválido'), 400);
                return;
            }

            $this->jsonSuccess($result);
        } catch (\Throwable $e) {
            log_error('ListingVisibility analyzeItem failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            $this->jsonError('Falha ao analisar visibilidade do anúncio', 500);
        }
    }

    /**
     * GET /api/listings/search-visibility/queue
     */
    public function searchActivationQueue(): void
    {
        try {
            $accountId = $this->resolveOwnedAccountId();
            if ($accountId === null) {
                $this->jsonError('Conta não autorizada ou não selecionada', 403);
                return;
            }

            $limit = $this->request->inputInt('limit', 20);
            $client = new MercadoLivreClient($accountId);
            $service = new ListingSearchVisibilityService($client);
            $result = $service->buildSearchActivationQueue(null, $limit);

            $this->jsonSuccess($result);
        } catch (\Throwable $e) {
            log_error('ListingVisibility queue failed', [
                'error' => $e->getMessage(),
            ]);
            $this->jsonError('Falha ao montar fila de ativação de busca', 500);
        }
    }

    /**
     * GET /api/listings/irregularities
     */
    public function scanIrregularities(): void
    {
        try {
            $accountId = $this->resolveOwnedAccountId();
            if ($accountId === null) {
                $this->jsonError('Conta não autorizada ou não selecionada', 403);
                return;
            }

            $limit = $this->request->inputInt('limit', 30);
            $client = new MercadoLivreClient($accountId);
            $visibility = new ListingSearchVisibilityService($client);
            $scan = new ListingIrregularityScanService($client, $visibility);
            $result = $scan->scan($limit);

            $this->jsonSuccess($result);
        } catch (\Throwable $e) {
            log_error('ListingVisibility irregularities failed', [
                'error' => $e->getMessage(),
            ]);
            $this->jsonError('Falha ao varrer irregularidades', 500);
        }
    }

    /**
     * GET /api/listings/infractions
     */
    public function listInfractions(): void
    {
        try {
            $accountId = $this->resolveOwnedAccountId();
            if ($accountId === null) {
                $this->jsonError('Conta não autorizada ou não selecionada', 403);
                return;
            }

            $params = [
                'limit' => $this->request->inputInt('limit', 20),
                'offset' => $this->request->inputInt('offset', 0),
                'language' => 'PT',
            ];

            $since = $this->request->input('date_created_since');
            if (is_string($since) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) === 1) {
                $params['date_created_since'] = $since;
            }

            $client = new MercadoLivreClient($accountId);
            $visibility = new ListingSearchVisibilityService($client);
            $scan = new ListingIrregularityScanService($client, $visibility);
            $result = $scan->listInfractions($params);

            if (isset($result['error']) && $result['error'] === 'seller_not_found') {
                $this->jsonError('Vendedor ML não encontrado para a conta', 422);
                return;
            }

            $this->jsonSuccess($result);
        } catch (\Throwable $e) {
            log_error('ListingVisibility infractions failed', [
                'error' => $e->getMessage(),
            ]);
            $this->jsonError('Falha ao listar infrações', 500);
        }
    }

    /**
     * POST /api/listings/picture-diagnostic
     *
     * Diagnóstico preventivo oficial (não publica nem altera anúncio).
     */
    public function diagnosePicture(): void
    {
        try {
            $accountId = $this->resolveOwnedAccountId();
            if ($accountId === null) {
                $this->jsonError('Conta não autorizada ou não selecionada', 403);
                return;
            }

            $payload = $this->request->json() ?? [];
            if (!is_array($payload)) {
                $this->jsonError('JSON inválido', 400);
                return;
            }

            $categoryId = isset($payload['category_id']) && is_string($payload['category_id'])
                ? trim($payload['category_id'])
                : '';
            if ($categoryId === '') {
                $this->jsonError('category_id é obrigatório', 400);
                return;
            }

            $pictureUrl = isset($payload['picture_url']) && is_string($payload['picture_url'])
                ? trim($payload['picture_url'])
                : '';
            $pictureId = isset($payload['picture_id']) && is_string($payload['picture_id'])
                ? trim($payload['picture_id'])
                : '';

            if (($pictureUrl === '') === ($pictureId === '')) {
                $this->jsonError('Envie exatamente um de: picture_url ou picture_id', 400);
                return;
            }

            $pictureType = isset($payload['picture_type']) && is_string($payload['picture_type'])
                ? trim($payload['picture_type'])
                : 'thumbnail';
            if (!in_array($pictureType, ['thumbnail', 'variation_thumbnail', 'other'], true)) {
                $this->jsonError('picture_type inválido', 400);
                return;
            }

            $body = [
                'context' => [
                    'category_id' => $categoryId,
                    'picture_type' => $pictureType,
                ],
            ];
            if ($pictureUrl !== '') {
                $body['picture_url'] = $pictureUrl;
            } else {
                $body['picture_id'] = $pictureId;
            }

            $title = isset($payload['title']) && is_string($payload['title']) ? trim($payload['title']) : '';
            if ($title !== '') {
                $body['context']['title'] = mb_substr($title, 0, 200);
            }

            $client = new MercadoLivreClient($accountId);
            $result = $client->diagnosePicture($body);
            $result['write_enabled'] = false;
            $result['message'] = 'Diagnóstico preventivo — nenhuma imagem foi associada ao anúncio';

            if (isset($result['error'])) {
                $this->jsonError(
                    (string) ($result['message'] ?? $result['error']),
                    422,
                    ['diagnostic' => $result]
                );
                return;
            }

            $this->jsonSuccess($result);
        } catch (\Throwable $e) {
            log_error('ListingVisibility picture diagnostic failed', [
                'error' => $e->getMessage(),
            ]);
            $this->jsonError('Falha no diagnóstico de imagem', 500);
        }
    }

    /**
     * Resolve account_id apenas se pertencer ao usuário autenticado.
     * Não confia em account_id cru sem ownership (mitiga IDOR nestes endpoints).
     */
    private function resolveOwnedAccountId(): ?int
    {
        $userId = $this->getUserId();
        if ($userId === null || $userId <= 0) {
            return null;
        }

        $requested = $this->request->inputInt('ml_account_id', 0);
        if ($requested <= 0) {
            $requested = $this->request->inputInt('account_id', 0);
        }

        if ($requested > 0) {
            return $this->assertAccountOwnedByUser($requested, $userId) ? $requested : null;
        }

        $active = SessionHelper::getActiveAccountId();
        if ($active === null || $active <= 0) {
            return null;
        }

        return $this->assertAccountOwnedByUser($active, $userId) ? $active : null;
    }

    private function assertAccountOwnedByUser(int $accountId, int $userId): bool
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT id FROM ml_accounts WHERE id = :id AND user_id = :user_id LIMIT 1'
            );
            $stmt->execute([
                'id' => $accountId,
                'user_id' => $userId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row);
        } catch (\Throwable $e) {
            log_error('ListingVisibility ownership check failed', [
                'account_id' => $accountId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
