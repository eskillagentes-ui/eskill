<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AwaSellerAlertService;
use App\Services\AwaSellerDiscoveryService;
use App\Services\AwaSellerExportService;
use App\Services\AwaSellerIdentificationService;
use App\Services\AwaSellerRegistryService;
use App\Services\UserService;

class AwaSellerController extends BaseController
{
    private const VIEW_PERMISSION = 'audit';
    private const MANAGE_PERMISSION = 'manager';

    private UserService $userService;

    public function __construct()
    {
        parent::__construct();
        $this->userService = new UserService();
    }

    public function scan(): void
    {
        $this->ensureManagePermission();

        $this->withErrorHandling(function (): void {
            $accountId = $this->requireActiveMlAccountId();
            $discovery = new AwaSellerDiscoveryService($accountId);

            $result = $discovery->runScan($this->buildScanOptions());

            $this->jsonSuccess([
                'data' => $result,
            ], 'Varredura persistente de sellers AWA concluída com sucesso.');
        }, 'AwaSellerController::scan');
    }

    public function getScan(string $scanId): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function () use ($scanId): void {
            $accountId = $this->requireActiveMlAccountId();
            $scanRunId = (int) $scanId;

            if ($scanRunId <= 0) {
                $this->jsonError('ID do scan inválido.', 422);
                return;
            }

            $registry = new AwaSellerRegistryService($accountId);
            $scan = $registry->getScanRun($scanRunId);
            if ($scan === null) {
                $this->jsonError('Scan AWA não encontrado para a conta ativa.', 404);
                return;
            }

            $this->jsonSuccess([
                'data' => $scan,
            ]);
        }, 'AwaSellerController::getScan');
    }

    public function getMetrics(): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function (): void {
            $accountId = $this->requireActiveMlAccountId();
            $registry = new AwaSellerRegistryService($accountId);

            $this->jsonSuccess([
                'data' => $registry->getMetrics(),
            ]);
        }, 'AwaSellerController::getMetrics');
    }

    /**
     * Status Brand Protection Program (membership API + direitos cadastrados).
     * GET /api/brand/awa/sellers/brand-protection/status (quando rota estiver registrada)
     * Também disponível via public/api-awa-bpp-status.php
     */
    public function getBrandProtectionStatus(): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function (): void {
            $accountId = $this->requireActiveMlAccountId();
            $bpp = new \App\Services\AwaBrandProtectionService($accountId);

            $this->jsonSuccess([
                'data' => $bpp->getStatus(),
            ]);
        }, 'AwaSellerController::getBrandProtectionStatus');
    }

    /**
     * Denúncia BPP (dry-run por padrão).
     * POST /api/brand/awa/sellers/brand-protection/denounce (quando rota estiver registrada)
     * Também disponível via public/api-awa-bpp-denounce.php
     */
    public function denounceBrandProtectionItem(): void
    {
        $this->ensureManagePermission();

        $this->withErrorHandling(function (): void {
            $accountId = $this->requireActiveMlAccountId();
            $body = $this->request->json();
            $body = is_array($body) ? $body : [];

            $itemId = (string) ($body['item_id'] ?? '');
            $reason = (string) ($body['report_reason_id'] ?? 'PPPI2');
            $comment = (string) ($body['comment'] ?? '');
            $dryRun = array_key_exists('dry_run', $body)
                ? (bool) filter_var($body['dry_run'], FILTER_VALIDATE_BOOLEAN)
                : true;
            $confirm = (bool) filter_var($body['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $sellerRegistryId = isset($body['seller_registry_id']) ? (int) $body['seller_registry_id'] : null;

            if (!$dryRun && !$confirm) {
                $this->jsonError('Para denúncia real envie dry_run=false e confirm=true.', 422);
                return;
            }

            $bpp = new \App\Services\AwaBrandProtectionService($accountId);
            $result = $bpp->denounceItem(
                $itemId,
                $reason,
                $comment,
                $dryRun,
                $sellerRegistryId > 0 ? $sellerRegistryId : null,
                'controller'
            );

            $this->jsonSuccess([
                'data' => $result,
            ], $dryRun ? 'Dry-run BPP concluído.' : 'Denúncia BPP processada.');
        }, 'AwaSellerController::denounceBrandProtectionItem');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScanOptions(): array
    {
        $body = $this->request->json();
        $queryCategories = $this->request->get('categories');
        $bodyCategories = is_array($body) ? ($body['categories'] ?? null) : null;

        $options = [
            'max_results' => max(1, min(10000, $this->request->inputInt('max_results', 2000))),
            'mode' => 'deep',
        ];

        $body = is_array($body) ? $body : [];
        if (isset($body['mode']) && is_string($body['mode'])) {
            $options['mode'] = strtolower(trim($body['mode']));
        }
        if (isset($body['max_results'])) {
            $options['max_results'] = max(1, min(10000, (int) $body['max_results']));
        }

        $categories = $bodyCategories ?? $queryCategories;
        if (is_array($categories) || is_string($categories)) {
            $options['categories'] = $categories;
        }

        return $options;
    }

    // =========================================================================
    // FASE 2 — UI OPERACIONAL
    // =========================================================================

    /**
     * Renderiza a tela Sellers AWA.
     */
    public function index(): void
    {
        $this->ensureAuthenticated(false);

        $canViewAwaSellers = $this->hasViewPermission();
        $canManageAwaSellers = $this->hasManagePermission();

        if (!$canViewAwaSellers) {
            http_response_code(403);
        }

        $this->renderView('dashboard/awa-sellers/index', [
            'pageTitle' => 'AWA Sellers',
            'pageSubtitle' => 'Monitore lojas que anunciam AWA, consulte evidências e acompanhe a identificação da base local.',
            'canViewAwaSellers' => $canViewAwaSellers,
            'canManageAwaSellers' => $canManageAwaSellers,
            'permissionDeniedMessage' => 'Acesso negado. Permissão de auditoria necessária para acessar o módulo AWA Sellers.',
        ]);
    }

    /**
     * GET api/brand/awa/sellers
     * Lista paginada de sellers com filtros.
     */
    public function listSellers(): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function (): void {
            $accountId = $this->requireActiveMlAccountId();
            $registry  = new AwaSellerRegistryService($accountId);

            $filters = $this->buildRegistryFilters();

            $page    = max(1, (int) ($this->request->get('page') ?? 1));
            $perPage = max(1, min(200, (int) ($this->request->get('per_page') ?? 50)));

            $sellers = $registry->listSellers($filters, $page, $perPage);
            $total   = $registry->countSellers($filters);

            $this->jsonSuccess([
                'data'       => $sellers,
                'filters'    => $filters,
                'pagination' => [
                    'page'       => $page,
                    'per_page'   => $perPage,
                    'total'      => $total,
                    'last_page'  => (int) ceil($total / $perPage),
                ],
            ]);
        }, 'AwaSellerController::listSellers');
    }

    /**
     * GET api/brand/awa/sellers/{id}
     * Detalhes de um seller pelo ID interno.
     */
    public function getSellerDetail(string $id): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function () use ($id): void {
            $accountId  = $this->requireActiveMlAccountId();
            $sellerId   = (int) $id;

            if ($sellerId <= 0) {
                $this->jsonError('ID de seller inválido.', 422);
                return;
            }

            $registry = new AwaSellerRegistryService($accountId);
            $seller   = $registry->getSellerById($sellerId);

            if ($seller === null) {
                $this->jsonError('Seller não encontrado.', 404);
                return;
            }

            $this->jsonSuccess(['data' => $seller]);
        }, 'AwaSellerController::getSellerDetail');
    }

    /**
     * GET api/brand/awa/sellers/{id}/items
     * Itens de um seller específico com paginação.
     */
    public function getSellerItems(string $id): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function () use ($id): void {
            $accountId  = $this->requireActiveMlAccountId();
            $sellerId   = (int) $id;

            if ($sellerId <= 0) {
                $this->jsonError('ID de seller inválido.', 422);
                return;
            }

            $registry = new AwaSellerRegistryService($accountId);

            if ($registry->getSellerById($sellerId) === null) {
                $this->jsonError('Seller não encontrado.', 404);
                return;
            }

            $page    = max(1, (int) ($this->request->get('page') ?? 1));
            $perPage = max(1, min(200, (int) ($this->request->get('per_page') ?? 50)));

            $items = $registry->listSellerItems($sellerId, $page, $perPage);
            $total = $registry->countSellerItems($sellerId);

            $this->jsonSuccess([
                'data'       => $items,
                'pagination' => [
                    'page'      => $page,
                    'per_page'  => $perPage,
                    'total'     => $total,
                    'last_page' => (int) ceil($total / $perPage),
                ],
            ]);
        }, 'AwaSellerController::getSellerItems');
    }

    /**
     * GET api/brand/awa/sellers/filters/options
     * Valores distintos para filtros da UI.
     */
    public function getFiltersOptions(): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function (): void {
            $accountId = $this->requireActiveMlAccountId();
            $registry  = new AwaSellerRegistryService($accountId);

            $this->jsonSuccess(['data' => $registry->getFilterOptions()]);
        }, 'AwaSellerController::getFiltersOptions');
    }

    /**
     * GET api/brand/awa/sellers/{id}/identification
     * Retorna a identificação jurídica de um seller.
     */
    public function getIdentification(string $id): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function () use ($id): void {
            $accountId = $this->requireActiveMlAccountId();
            $sellerId  = (int) $id;

            if ($sellerId <= 0) {
                $this->jsonError('ID de seller inválido.', 422);
                return;
            }

            $service = new AwaSellerIdentificationService($accountId);
            $record  = $service->getByRegistryId($sellerId);

            $this->jsonSuccess(['data' => $record]);
        }, 'AwaSellerController::getIdentification');
    }

    /**
     * GET api/brand/awa/sellers/{id}/identification/history
     * Retorna a trilha de auditoria da identificação jurídica do seller.
     */
    public function getIdentificationHistory(string $id): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function () use ($id): void {
            $accountId = $this->requireActiveMlAccountId();
            $sellerId  = (int) $id;

            if ($sellerId <= 0) {
                $this->jsonError('ID de seller inválido.', 422);
                return;
            }

            $limit   = max(1, min(100, (int) ($this->request->get('limit') ?? 20)));
            $service = new AwaSellerIdentificationService($accountId);
            $history = $service->getAuditHistory($sellerId, $limit);

            $this->jsonSuccess([
                'data' => [
                    'history' => $history,
                    'count' => count($history),
                    'limit' => $limit,
                ],
            ]);
        }, 'AwaSellerController::getIdentificationHistory');
    }

    /**
     * PUT api/brand/awa/sellers/{id}/identification
     * Salva / atualiza a identificação jurídica do seller.
     */
    public function saveIdentification(string $id): void
    {
        $this->ensureManagePermission();

        $this->withErrorHandling(function () use ($id): void {
            $accountId = $this->requireActiveMlAccountId();
            $sellerId  = (int) $id;

            if ($sellerId <= 0) {
                $this->jsonError('ID de seller inválido.', 422);
                return;
            }

            $body = $this->request->json();
            if (!is_array($body)) {
                $this->jsonError('Payload JSON inválido.', 422);
                return;
            }

            $payload = $this->sanitizeIdentificationPayload($body);

            $userId = $this->getUserId();
            if ($userId !== null) {
                $payload['created_by'] = (string) $userId;
                $payload['audit_user_id'] = $userId;
            }
            $payload['audit_actor'] = 'dashboard_awa_sellers';

            $service = new AwaSellerIdentificationService($accountId);
            $service->upsert($sellerId, $payload);

            $registry = new AwaSellerRegistryService($accountId);
            $seller   = $registry->getSellerById($sellerId);

            $this->jsonSuccess(['data' => $seller], 'Identificação salva com sucesso.');
        }, 'AwaSellerController::saveIdentification');
    }

    /**
     * POST api/brand/awa/sellers/{id}/identification/verify
     * Marca identificação do seller como verificada.
     */
    public function verifyIdentification(string $id): void
    {
        $this->ensureManagePermission();

        $this->withErrorHandling(function () use ($id): void {
            $accountId = $this->requireActiveMlAccountId();
            $sellerId  = (int) $id;

            if ($sellerId <= 0) {
                $this->jsonError('ID de seller inválido.', 422);
                return;
            }

            $body       = $this->request->json();
            $verifiedBy = is_array($body) ? ($body['verified_by'] ?? null) : null;
            $userId     = $this->getUserId();

            $service = new AwaSellerIdentificationService($accountId);
            $service->verify($sellerId, is_string($verifiedBy) ? $verifiedBy : null, $userId);

            $registry = new AwaSellerRegistryService($accountId);
            $seller   = $registry->getSellerById($sellerId);

            $this->jsonSuccess(['data' => $seller], 'Identificação verificada com sucesso.');
        }, 'AwaSellerController::verifyIdentification');
    }

    /**
     * GET api/brand/awa/sellers/identification/summary
     * Contagem de sellers por status de identificação + sem identificação.
     */
    public function identificationSummary(): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function (): void {
            $accountId = $this->requireActiveMlAccountId();
            $service   = new AwaSellerIdentificationService($accountId);

            $this->jsonSuccess([
                'data' => [
                    'by_status'    => $service->countByStatus(),
                    'unidentified' => $service->countUnidentified(),
                ],
            ]);
        }, 'AwaSellerController::identificationSummary');
    }

    /**
     * GET api/brand/awa/sellers/export/csv
     * Exporta sellers ativos como CSV.
     */
    public function exportCsv(): void
    {
        $this->ensureManagePermission();

        $accountId  = $this->requireActiveMlAccountId();
        $filters    = $this->buildRegistryFilters();
        $exportSvc  = new AwaSellerExportService($accountId);

        $exportSvc->downloadSellersAsCsv($filters);
    }

    /**
     * GET api/brand/awa/sellers/{id}/export/items/csv
     * Exporta todos os anúncios de um seller específico como CSV.
     */
    public function exportItemsCsv(string $id): void
    {
        $this->ensureManagePermission();

        $accountId = $this->requireActiveMlAccountId();
        $registry  = new AwaSellerRegistryService($accountId);
        $seller    = $registry->getSellerById((int) $id);

        if ($seller === null) {
            $this->jsonError('Seller não encontrado.', 404);
            return;
        }

        $exportSvc = new AwaSellerExportService($accountId, $registry);
        $exportSvc->downloadSellerItemsAsCsv((int) $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRegistryFilters(): array
    {
        $filters = [
            'search' => trim((string) ($this->request->get('search') ?? '')),
            'state' => trim((string) ($this->request->get('state') ?? '')),
            'city' => trim((string) ($this->request->get('city') ?? '')),
            'category_id' => trim((string) ($this->request->get('category_id') ?? '')),
            'reputation_level' => trim((string) ($this->request->get('reputation_level') ?? '')),
            'id_status' => trim((string) ($this->request->get('id_status') ?? '')),
        ];

        $minItems = (int) ($this->request->get('min_items') ?? 0);
        if ($minItems > 0) {
            $filters['min_items'] = $minItems;
        }

        $isActive = $this->request->get('is_active');
        if ($isActive !== null && $isActive !== '') {
            $filters['is_active'] = (int) $isActive === 1;
        }

        return array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== null
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizeIdentificationPayload(array $payload): array
    {
        $allowedSourceTypes = [
            'manual',
            'authorized_ml_account',
            'internal_registry',
            'external_registry',
            'website_review',
            'legal_team_validation',
        ];
        $allowedStatuses = ['verified', 'pending', 'not_available', 'conflict'];

        $sourceType = trim((string) ($payload['source_type'] ?? 'manual'));
        if (!in_array($sourceType, $allowedSourceTypes, true)) {
            $this->jsonError('source_type inválido.', 422);
        }

        $verificationStatus = trim((string) ($payload['verification_status'] ?? 'pending'));
        if (!in_array($verificationStatus, $allowedStatuses, true)) {
            $this->jsonError('verification_status inválido.', 422);
        }

        $confidenceScore = max(0, min(100, (int) ($payload['confidence_score'] ?? 50)));

        return [
            'cnpj' => $this->nullableTrim($payload['cnpj'] ?? null),
            'razao_social' => $this->nullableTrim($payload['razao_social'] ?? null),
            'source_type' => $sourceType,
            'source_reference' => $this->nullableTrim($payload['source_reference'] ?? null),
            'confidence_score' => $confidenceScore,
            'verification_status' => $verificationStatus,
            'notes' => $this->nullableTrim($payload['notes'] ?? null),
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function ensureAuthenticated(bool $jsonResponse = true): void
    {
        if ($this->userService->isAuthenticated()) {
            return;
        }

        if ($jsonResponse) {
            $this->jsonError('Autenticação necessária.', 401);
        }

        header('Location: /login');
        exit;
    }

    private function hasViewPermission(): bool
    {
        return $this->userService->hasPermission(self::VIEW_PERMISSION);
    }

    private function hasManagePermission(): bool
    {
        return $this->userService->hasPermission(self::MANAGE_PERMISSION);
    }

    private function ensureViewPermission(): void
    {
        $this->ensureAuthenticated();

        if ($this->hasViewPermission()) {
            return;
        }

        $this->jsonError(
            'Acesso negado. Permissão de auditoria necessária para acessar AWA Sellers.',
            403,
            ['required_role' => 'admin, manager ou viewer']
        );
    }

    private function ensureManagePermission(): void
    {
        $this->ensureAuthenticated();

        if ($this->hasManagePermission()) {
            return;
        }

        $this->jsonError(
            'Acesso negado. Permissão de gestão necessária para executar ações no módulo AWA Sellers.',
            403,
            ['required_role' => 'admin ou manager']
        );
    }

    private function requireActiveMlAccountId(): int
    {
        $accountId = $this->getActiveAccountId();
        if ($accountId === null || $accountId <= 0) {
            $this->jsonError('Conta Mercado Livre ativa não informada.', 401);
        }

        return $accountId;
    }

    /**
     * GET api/brand/awa/sellers/alerts
     * Retorna alertas AWA para a conta ativa.
     */
    public function getAlerts(): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function (): void {
            $accountId  = $this->requireActiveMlAccountId();
            $limit      = max(1, min(200, (int) ($this->request->get('limit') ?? 50)));
            $unreadOnly = filter_var(
                $this->request->get('unread_only'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? false;

            $alertSvc = new AwaSellerAlertService($accountId);
            $alerts   = $alertSvc->getAwaAlerts($limit, $unreadOnly);

            $this->jsonSuccess([
                'alerts' => $alerts,
                'count' => count($alerts),
                'unread_count' => count(array_filter(
                    $alerts,
                    static fn (array $alert): bool => empty($alert['read_at'])
                )),
                'unread_only' => $unreadOnly,
                'data' => [
                    'alerts' => $alerts,
                    'count' => count($alerts),
                    'unread_count' => count(array_filter(
                        $alerts,
                        static fn (array $alert): bool => empty($alert['read_at'])
                    )),
                    'unread_only' => $unreadOnly,
                ],
            ]);
        }, 'AwaSellerController::getAlerts');
    }

    /**
     * GET api/brand/awa/sellers/history
     * Retorna vendedores novos detectados nos últimos N dias e histórico de scans.
     */
    public function getHistory(): void
    {
        $this->ensureViewPermission();

        $this->withErrorHandling(function (): void {
            $accountId = $this->requireActiveMlAccountId();
            $days      = max(1, min(90, (int) ($this->request->get('days') ?? 7)));
            $registry  = new AwaSellerRegistryService($accountId);

            $this->jsonSuccess([
                'data' => [
                    'new_sellers' => $registry->getNewSellersHistory($days),
                    'scan_runs'   => $registry->listScanRuns(20),
                    'days'        => $days,
                ],
            ]);
        }, 'AwaSellerController::getHistory');
    }

    // =========================================================================
    // RASTREAMENTO MANUAL E AUTO-IDENTIFICAÇÃO
    // =========================================================================

    /**
     * POST api/brand/awa/sellers/track
     * Adiciona manualmente um seller ao registry pelo ML seller_id.
     * Body: { seller_id: <int> }
     */
    public function trackSeller(): void
    {
        $this->ensureManagePermission();

        $this->withErrorHandling(function (): void {
            $accountId = $this->requireActiveMlAccountId();

            $body      = $this->request->json();
            $mlSellerId = (int) (is_array($body) ? ($body['seller_id'] ?? 0) : ($this->request->get('seller_id') ?? 0));

            if ($mlSellerId <= 0) {
                $this->jsonError('seller_id é obrigatório e deve ser um inteiro positivo.', 422);
                return;
            }

            $discovery = new AwaSellerDiscoveryService($accountId);
            $result    = $discovery->trackSellerById($mlSellerId);

            $message = match ($result['status']) {
                'already_tracked' => 'Seller já rastreado na base local.',
                'tracked'         => 'Seller adicionado ao registry com sucesso.',
                default           => 'Operação concluída.',
            };

            $this->jsonSuccess(['data' => $result], $message);
        }, 'AwaSellerController::trackSeller');
    }

    /**
     * POST api/brand/awa/sellers/{id}/identification/auto
     * Tenta auto-identificação de CNPJ via perfil ML e BrasilAPI.
     */
    public function autoIdentify(string $id): void
    {
        $this->ensureManagePermission();

        $this->withErrorHandling(function () use ($id): void {
            $accountId  = $this->requireActiveMlAccountId();
            $registryId = (int) $id;

            if ($registryId <= 0) {
                $this->jsonError('ID de seller inválido.', 422);
                return;
            }

            $registry = new AwaSellerRegistryService($accountId);
            if ($registry->getSellerById($registryId) === null) {
                $this->jsonError('Seller não encontrado.', 404);
                return;
            }

            $mlClient = new \App\Services\MercadoLivreClient($accountId);
            $idSvc    = new AwaSellerIdentificationService($accountId);
            $result   = $idSvc->autoIdentifyFromMLProfile($registryId, $mlClient);

            $message = $result['found']
                ? 'CNPJ identificado automaticamente.'
                : 'CNPJ não disponível no perfil ML.';

            $this->jsonSuccess(['data' => $result], $message);
        }, 'AwaSellerController::autoIdentify');
    }

    /**
     * POST api/brand/awa/sellers/identification/auto-batch
     * Roda auto-identificação em lote para todos os sellers sem CNPJ.
     */
    public function autoIdentifyBatch(): void
    {
        $this->ensureManagePermission();

        $this->withErrorHandling(function (): void {
            $accountId = $this->requireActiveMlAccountId();
            $limit     = max(1, min(200, (int) ($this->request->get('limit') ?? 50)));

            $mlClient = new \App\Services\MercadoLivreClient($accountId);
            $idSvc    = new AwaSellerIdentificationService($accountId);
            $result   = $idSvc->autoIdentifyBatch($mlClient, $limit);

            $this->jsonSuccess(['data' => $result], "Auto-identificação em lote concluída: {$result['found']} CNPJ(s) encontrado(s).");
        }, 'AwaSellerController::autoIdentifyBatch');
    }
}
