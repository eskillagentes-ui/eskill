<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Security\AccountAccessException;
use App\Security\AccountContextResolver;
use App\Services\MercadoLivreClient;
use App\Services\AI\SEO\Strategies\CompatibilityService;

/**
 * Bulk Compatibility Manager Controller
 *
 * Endpoints para gerenciamento em massa de compatibilidades
 * de peças de moto com modelos COMPATIBLE_MODELS do Mercado Livre.
 */
class BulkCompatibilityController extends BaseController
{
    private MercadoLivreClient $mlClient;
    private CompatibilityService $compatibilityService;
    private int $accountId;

    public function __construct()
    {
        parent::__construct();

        // SEC-001 + Facility: compatibilidades na mesma prioridade da fila de conversão
        $accountId = 0;
        try {
            $context = (new AccountContextResolver())->authorizeForCurrentActor('compatibility.bulk');
            $accountId = $context->accountId();
            $this->mlClient = MercadoLivreClient::fromAuthorizedContext($context);
        } catch (AccountAccessException $e) {
            $this->mlClient = new MercadoLivreClient(null);
        }
        $this->accountId = $accountId;
        $this->compatibilityService = new CompatibilityService($accountId ?: null);
    }

    /**
     * GET /api/compatibility/bulk/missing
     *
     * Lista itens ativos do vendedor que NÃO possuem o atributo
     * COMPATIBLE_MODELS preenchido.
     *
     * Query params:
     *   - limit  (int, default 50, max 100)
     *   - offset (int, default 0)
     */
    public function listMissing(): void
    {
        header('Content-Type: application/json');

        if (!$this->accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Conta ML não selecionada']);
            return;
        }

        $limit  = min($this->request->getInt('limit', 50), 100);
        $offset = max($this->request->getInt('offset', 0), 0);

        try {
            // Busca IDs de itens ativos do vendedor
            $sellerId = $this->mlClient->getSellerId();
            if (!$sellerId) {
                http_response_code(502);
                echo json_encode(['error' => 'Não foi possível obter seller_id']);
                return;
            }

            $searchData = $this->mlClient->get(
                "/users/{$sellerId}/items/search",
                ['status' => 'active', 'limit' => $limit, 'offset' => $offset]
            );

            $itemIds = $searchData['results'] ?? [];
            $paging  = $searchData['paging'] ?? ['total' => 0, 'offset' => $offset, 'limit' => $limit];

            if (empty($itemIds)) {
                echo json_encode(['items' => [], 'paging' => $paging, 'missing_count' => 0]);
                return;
            }

            // Busca detalhes incluindo attributes
            $details = $this->mlClient->get('/items', [
                'ids'        => implode(',', $itemIds),
                'attributes' => 'id,title,price,thumbnail,category_id,sold_quantity,attributes',
            ], 60, false);

            $missingItems = [];
            foreach ($details as $entry) {
                $body = $entry['body'] ?? $entry;
                $code = $entry['code'] ?? 200;

                if ($code !== 200 || !isset($body['id'])) {
                    continue;
                }

                // Verificar se COMPATIBLE_MODELS está preenchido
                $hasCompatible = false;
                foreach ($body['attributes'] ?? [] as $attr) {
                    if (($attr['id'] ?? '') === 'COMPATIBLE_MODELS') {
                        $vals = $attr['values'] ?? [];
                        if (!empty($vals)) {
                            $hasCompatible = true;
                            break;
                        }
                    }
                }

                if (!$hasCompatible) {
                    $missingItems[] = [
                        'id'             => $body['id'],
                        'title'          => $body['title'] ?? '',
                        'price'          => $body['price'] ?? 0,
                        'thumbnail'      => $body['thumbnail'] ?? '',
                        'category_id'    => $body['category_id'] ?? '',
                        'sold_quantity'  => $body['sold_quantity'] ?? 0,
                    ];
                }
            }

            echo json_encode([
                'items'         => $missingItems,
                'paging'        => $paging,
                'missing_count' => count($missingItems),
            ]);
        } catch (\Exception $e) {
            log_error('BulkCompatibilityController::listMissing error', [
                'account_id' => $this->accountId,
                'error'      => $e->getMessage(),
            ]);
            http_response_code(502);
            echo json_encode(['error' => 'Erro ao buscar itens: ' . $e->getMessage()]);
        }
    }

    /**
     * POST /api/compatibility/bulk/suggest-by-title
     *
     * Dado um array de { id, title, category_id }, retorna sugestões
     * de modelos compatíveis para cada item usando CompatibilityService.
     *
     * Body JSON:
     *   { "items": [{ "id": "MLB123", "title": "Bagageiro Honda CG 160", "category_id": "MLB..." }] }
     */
    public function suggestForItems(): void
    {
        header('Content-Type: application/json');

        $data = $this->request->json();

        if (!is_array($data) || empty($data['items'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Campo "items" é obrigatório e deve ser array']);
            return;
        }

        $items = $data['items'];
        if (count($items) > 50) {
            $items = array_slice($items, 0, 50);
        }

        $results = [];
        foreach ($items as $item) {
            $itemId     = (string)($item['id'] ?? '');
            $title      = (string)($item['title'] ?? '');
            $categoryId = (string)($item['category_id'] ?? '');

            if ($itemId === '') {
                continue;
            }

            $suggestions = $this->buildSuggestionsFromTitle($title, $categoryId);

            $results[$itemId] = [
                'item_id'     => $itemId,
                'title'       => $title,
                'suggestions' => $suggestions,
            ];
        }

        echo json_encode(['results' => $results]);
    }

    /**
     * POST /api/compatibility/bulk/apply
     *
     * Aplica COMPATIBLE_MODELS em lote para uma lista de itens.
     *
     * Body JSON:
     *   {
     *     "applications": [
     *       {
     *         "item_id": "MLB123",
     *         "models": ["CG 160", "Fazer 250"]
     *       }
     *     ]
     *   }
     */
    public function applyBulk(): void
    {
        header('Content-Type: application/json');

        if (!$this->accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Conta ML não selecionada']);
            return;
        }

        $data = $this->request->json();

        if (!is_array($data) || empty($data['applications'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Campo "applications" é obrigatório']);
            return;
        }

        $applications = $data['applications'];
        if (count($applications) > 50) {
            $applications = array_slice($applications, 0, 50);
        }

        $results = [];

        foreach ($applications as $app) {
            $itemId = (string)($app['item_id'] ?? '');
            $models = is_array($app['models'] ?? null) ? $app['models'] : [];

            if ($itemId === '' || empty($models)) {
                $results[] = [
                    'item_id' => $itemId,
                    'success' => false,
                    'message' => 'item_id ou models inválidos',
                ];
                continue;
            }

            try {
                $attrPayload = $this->formatCompatibleModelsAttribute($models);

                $updateResult = $this->mlClient->updateItem($itemId, [
                    'attributes' => [$attrPayload],
                ]);

                $results[] = [
                    'item_id'         => $itemId,
                    'success'         => $updateResult['success'],
                    'message'         => $updateResult['message'],
                    'models_applied'  => count($models),
                ];

                // Pequena pausa para respeitar rate limit (10 itens/s sugerido)
                usleep(120_000);
            } catch (\Exception $e) {
                log_error('BulkCompatibilityController::applyBulk item error', [
                    'item_id' => $itemId,
                    'error'   => $e->getMessage(),
                ]);
                $results[] = [
                    'item_id' => $itemId,
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $successCount = count(array_filter($results, fn(array $r): bool => $r['success']));

        echo json_encode([
            'results'       => $results,
            'success_count' => $successCount,
            'error_count'   => count($results) - $successCount,
        ]);
    }

    // ===== Helpers privados =====

    /**
     * Infere modelos compatíveis a partir do título do item.
     * Usa o CompatibilityService como base e enriquece com palavras-chave do título.
     *
     * @return list<string>
     */
    private function buildSuggestionsFromTitle(string $title, string $categoryId): array
    {
        $titleLower = mb_strtolower($title);

        // Palavras-chave de modelos extraídas do título
        $detectedModels = $this->extractModelsFromTitle($titleLower);

        if (!empty($detectedModels)) {
            $expansion = $this->compatibilityService->expandCompatibility($detectedModels);
            $expanded  = array_column($expansion['expanded'] ?? [], 'model');
            // Combina detectados + expandidos, remove duplicatas
            $all = array_unique(array_merge($detectedModels, $expanded));
            sort($all);
            return array_values($all);
        }

        // Fallback: usa specs genéricas (produto universal)
        $suggested = $this->compatibilityService->suggestBySpecs([]);
        return $suggested['suggested_models'] ?? ['universal'];
    }

    /**
     * Detecta modelos de motos presentes no título do item.
     *
     * @return list<string>
     */
    private function extractModelsFromTitle(string $titleLower): array
    {
        /** @var array<string, string> */
        $patterns = [
            '/\bcg\s*160\b/i'          => 'CG 160',
            '/\bcg\s*150\b/i'          => 'CG 150',
            '/\bcg\s*125\b/i'          => 'CG 125',
            '/\bbros\s*160\b/i'         => 'Bros 160',
            '/\bnxr.*bros\b/i'          => 'NXR Bros',
            '/\bxre\s*300\b/i'          => 'XRE 300',
            '/\bxre\s*190\b/i'          => 'XRE 190',
            '/\bcb\s*300\b/i'           => 'CB 300',
            '/\bcb\s*500\b/i'           => 'CB 500',
            '/\bpcx\s*150\b/i'          => 'PCX 150',
            '/\belite\s*125\b/i'        => 'Elite 125',
            '/\bpop\s*110\b/i'          => 'Pop 110',
            '/\bfazer\s*250\b/i'        => 'Fazer 250',
            '/\bfactor\s*150\b/i'       => 'Factor 150',
            '/\bnmax\s*160\b/i'         => 'NMAX 160',
            '/\bcrosser\s*150\b/i'      => 'Crosser 150',
            '/\blander\s*250\b/i'       => 'Lander 250',
            '/\bmt[\-\s]*03\b/i'        => 'MT-03',
            '/\br\s*3\b/i'              => 'R3',
            '/\bgsr\s*150\b/i'          => 'GSR 150',
            '/\bintruder\s*125\b/i'     => 'Intruder 125',
            '/\bninja\s*300\b/i'        => 'Ninja 300',
            '/\bversys[\-\s]*x\s*300\b/i' => 'Versys-X 300',
            '/\bg\s*310\b/i'            => 'G 310',
            '/\bf\s*850\b/i'            => 'F 850 GS',
            '/\btitan\b/i'              => 'Titan 160',
            '/\bfan\b/i'                => 'Fan 160',
            '/\bcargo\b/i'              => 'CG 160 Cargo',
        ];

        $found = [];
        foreach ($patterns as $pattern => $model) {
            if (preg_match($pattern, $titleLower)) {
                $found[] = $model;
            }
        }

        return array_unique($found);
    }

    /**
     * Formata o payload de atributo para a ML API.
     *
     * @param list<string> $models
     * @return array<string, mixed>
     */
    private function formatCompatibleModelsAttribute(array $models): array
    {
        $values = [];
        foreach ($models as $model) {
            if (is_string($model) && $model !== '') {
                $values[] = ['name' => $model];
            }
        }

        return [
            'id'     => 'COMPATIBLE_MODELS',
            'values' => $values,
        ];
    }
}
