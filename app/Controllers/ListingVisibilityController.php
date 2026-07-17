<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Security\AccountAccessException;
use App\Security\AccountContextResolver;
use App\Security\AuthorizedAccountContext;
use App\Services\MercadoLivre\IrregularitySyncService;
use App\Services\MercadoLivre\ListingIrregularityScanService;
use App\Services\MercadoLivre\ListingSearchVisibilityService;
use App\Services\MercadoLivre\SalesBlockerStore;
use App\Services\MercadoLivreClient;

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
            $context = $this->resolveAuthorizedContext();
            if ($context === null) {
                return;
            }

            $client = MercadoLivreClient::fromAuthorizedContext($context);
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
            $context = $this->resolveAuthorizedContext();
            if ($context === null) {
                return;
            }

            $limit = $this->request->inputInt('limit', 20);
            $client = MercadoLivreClient::fromAuthorizedContext($context);
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
            $context = $this->resolveAuthorizedContext();
            if ($context === null) {
                return;
            }

            $limit = $this->request->inputInt('limit', 30);
            $client = MercadoLivreClient::fromAuthorizedContext($context);
            $visibility = new ListingSearchVisibilityService($client);
            $scan = new ListingIrregularityScanService($client, $visibility);
            $result = $scan->scan($limit);

            $persist = filter_var($this->request->input('persist', false), FILTER_VALIDATE_BOOLEAN);
            if ($persist) {
                $blocked = is_array($result['blocked'] ?? null) ? $result['blocked'] : [];
                $storeResult = (new SalesBlockerStore())->upsertBlocked($context, $blocked, 'urgent');
                $result['store'] = [
                    'urgent_upserted' => (int) ($storeResult['upserted'] ?? 0),
                    'counts' => (new SalesBlockerStore())->countsByQueue($context->accountId()),
                ];
            }

            $this->jsonSuccess($result);
        } catch (\Throwable $e) {
            log_error('ListingVisibility irregularities failed', [
                'error' => $e->getMessage(),
            ]);
            $this->jsonError('Falha ao varrer irregularidades', 500);
        }
    }

    /**
     * POST /api/listings/irregularities/sync
     * Sync read-only → SalesBlockerStore (nunca escreve no ML).
     */
    public function syncIrregularities(): void
    {
        try {
            $context = $this->resolveAuthorizedContext();
            if ($context === null) {
                return;
            }

            $limit = $this->request->inputInt('limit', 30);
            $client = MercadoLivreClient::fromAuthorizedContext($context);
            $result = IrregularitySyncService::forClient($client)->syncAccount($context, $limit);
            $this->jsonSuccess($result);
        } catch (\Throwable $e) {
            log_error('ListingVisibility sync failed', [
                'error' => $e->getMessage(),
            ]);
            $this->jsonError('Falha ao sincronizar irregularidades', 500);
        }
    }

    /**
     * GET /api/listings/sales-blockers
     */
    public function listSalesBlockers(): void
    {
        try {
            $context = $this->resolveAuthorizedContext();
            if ($context === null) {
                return;
            }

            $queue = (string) ($this->request->input('queue') ?? 'urgent');
            if (!in_array($queue, ['urgent', 'exposure', 'account'], true)) {
                $this->jsonError('queue inválida', 400);
                return;
            }

            $store = new SalesBlockerStore();
            $this->jsonSuccess([
                'queue' => $queue,
                'items' => $store->listOpen($context->accountId(), $queue, $this->request->inputInt('limit', 50)),
                'counts' => $store->countsByQueue($context->accountId()),
                'write_enabled' => false,
            ]);
        } catch (\Throwable $e) {
            log_error('ListingVisibility sales-blockers failed', [
                'error' => $e->getMessage(),
            ]);
            $this->jsonError('Falha ao listar fila de bloqueios', 500);
        }
    }

    /**
     * GET /api/listings/infractions
     */
    public function listInfractions(): void
    {
        try {
            $context = $this->resolveAuthorizedContext();
            if ($context === null) {
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

            $client = MercadoLivreClient::fromAuthorizedContext($context);
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
            $context = $this->resolveAuthorizedContext();
            if ($context === null) {
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

            $client = MercadoLivreClient::fromAuthorizedContext($context);
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
     * SEC-001: AccountAccessPolicy — header/GET/POST não contornam ownership.
     */
    private function resolveAuthorizedContext(): ?AuthorizedAccountContext
    {
        try {
            $explicit = $this->request->inputInt('ml_account_id', 0);
            if ($explicit <= 0) {
                $explicit = $this->request->inputInt('account_id', 0);
            }

            return (new AccountContextResolver())->authorizeForCurrentActor(
                'listings.read',
                $explicit > 0 ? $explicit : null
            );
        } catch (AccountAccessException $e) {
            $this->jsonError($e->getMessage(), $e->httpStatus(), [
                'error_code' => $e->errorCode(),
            ]);
            return null;
        }
    }
}
