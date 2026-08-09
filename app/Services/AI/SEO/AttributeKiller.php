<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use App\Services\MercadoLivreClient;
use App\Services\CategoryService;
use App\Services\AI\Core\AIProviderManager;
use PDO;

/**
 * 🔧 ATTRIBUTE KILLER - Preenchimento Total de Lacunas
 *
 * Preenche 100% dos atributos (visíveis + ocultos):
 * - Atributos obrigatórios
 * - Atributos de filtro
 * - Atributos ocultos (SEO hidden)
 * - Atributos de catálogo
 *
 * @author AI Development Team
 * @version 1.0.0
 */
class AttributeKiller
{
    /** Versão da lógica de gaps — invalida summaries antigos no TechSheetService */
    public const ANALYZER_VERSION = 3;

    /**
     * Atributos de modelo usados para indexação / KPI Modelos
     * @var list<string>
     */
    public const MODEL_ATTRIBUTE_IDS = [
        'MODEL',
        'COMPATIBLE_VEHICLE_MODELS',
        'VEHICLE_MODEL',
        'MOTO_MODEL',
        'ALPHANUMERIC_MODEL',
    ];

    /**
     * Tags ML que impedem o vendedor de preencher o atributo via API
     * @see https://developers.mercadolivre.com.br/pt_br/atributos
     * @var list<string>
     */
    private const NON_FILLABLE_TAGS = ['read_only', 'fixed', 'inferred', 'others'];

    /**
     * Atributos hidden operacionais/internos — não contam como Hidden SEO.
     * Continuam em "recommended" quando faltam, para não sumir do radar.
     * @var list<string>
     */
    private const HIDDEN_OPS_ATTRIBUTE_IDS = [
        'PRODUCT_DATA_SOURCE',
        'IS_KIT',
        'AGID',
        'EMPTY_GTIN_REASON',
        'SELLER_SKU',
        'SELLER_PACKAGE_WIDTH',
        'SELLER_PACKAGE_LENGTH',
        'SELLER_PACKAGE_HEIGHT',
        'SELLER_PACKAGE_WEIGHT',
        'SELLER_PACKAGE_DATA_SOURCE',
    ];

    private PDO $db;
    private ?int $accountId;
    private ?MercadoLivreClient $mlClient = null;
    private ?CategoryService $categoryService = null;
    private ?AIProviderManager $aiProvider = null;
    private bool $aiAvailable = true; // Flag to track AI availability

    public function __construct(int $accountId)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->categoryService = new CategoryService($accountId);
        $this->aiProvider = new AIProviderManager();
    }

    /**
     * � Otimizar atributos de um item específico
     */
    public function optimize(string $itemId): array
    {
        try {
            $item = $this->mlClient->get("/items/{$itemId}");
            if (!$item || isset($item['error'])) {
                return [
                    'success' => false,
                    'error' => $item['error'] ?? 'Item não encontrado no Mercado Livre'
                ];
            }

            $categoryId = $item['category_id'] ?? '';
            if (!$categoryId) {
                return ['success' => false, 'error' => 'Categoria do item não identificada'];
            }

            return $this->fillMissingAttributes($itemId, $categoryId, $item);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * �📦 Obtém atributos da categoria
     * Exposição pública para uso em outros serviços
     *
     * @param string $categoryId ID da categoria ML
     * @return array Lista de atributos da categoria
     */
    public function getCategoryAttributes(string $categoryId): array
    {
        $categoryAttrs = $this->categoryService->getCategoryAttributes($categoryId);
        // A API retorna array direto, não ['attributes' => [...]]
        return isset($categoryAttrs['attributes']) ? $categoryAttrs['attributes'] : (is_array($categoryAttrs) ? $categoryAttrs : []);
    }

    /**
     * 🔍 Analisar lacunas de atributos
     */
    public function analyzeGaps(string $itemId, string $categoryId, ?array $itemData = null): array
    {
        $result = [
            'item_id' => $itemId,
            'category_id' => $categoryId,
            'total_available' => 0,
            'filled' => 0,
            'missing' => 0,
            'completeness' => 0,
            'missing_model' => false,
            'analyzer_version' => self::ANALYZER_VERSION,
            'gaps' => [
                'required' => [],
                'filter' => [],
                'recommended' => [],
                'hidden' => [],
            ],
            'priority_actions' => [],
        ];

        try {
            // Get category attributes
            $categoryAttrs = $this->categoryService->getCategoryAttributes($categoryId);
            // A API retorna array direto, não ['attributes' => [...]]
            $allAttrs = isset($categoryAttrs['attributes']) ? $categoryAttrs['attributes'] : (is_array($categoryAttrs) ? $categoryAttrs : []);

            // Get item current attributes
            $item = $itemData;
            if (!$item) {
                $item = $this->mlClient->get("/items/{$itemId}");
            }
            $currentAttrs = $item['attributes'] ?? [];
            $currentAttrsById = [];
            foreach ($currentAttrs as $currentAttr) {
                if (is_array($currentAttr) && isset($currentAttr['id'])) {
                    $currentAttrsById[(string)$currentAttr['id']] = $currentAttr;
                }
            }

            $fillableTotal = 0;
            $fillableFilled = 0;
            $missingModel = false;

            foreach ($allAttrs as $attr) {
                // Garantir que attr é um array válido
                if (!is_array($attr) || !isset($attr['id'])) {
                    continue;
                }

                $tags = is_array($attr['tags'] ?? null) ? $attr['tags'] : [];

                // Ignorar atributos que o vendedor não pode preencher (doc ML: read_only/fixed/inferred/others)
                if (!$this->isSellerFillable($tags)) {
                    continue;
                }

                $fillableTotal++;
                $attrId = (string)$attr['id'];
                $isFilled = $this->isAttributeFilled($currentAttrsById, $attrId);

                if ($isFilled) {
                    $fillableFilled++;
                    continue;
                }

                if (in_array($attrId, self::MODEL_ATTRIBUTE_IDS, true)) {
                    $missingModel = true;
                }

                $gap = [
                    'id' => $attrId,
                    'name' => $attr['name'] ?? $attrId,
                    'value_type' => $attr['value_type'] ?? 'string',
                    'allowed_values' => $this->getAllowedValues($attr),
                    'can_infer' => $this->canInferValue($attr, $item),
                ];

                // Classify gap — tags podem vir como objeto {"required":true} ou lista ["required"]
                $isRequired = $this->hasTag($tags, 'required')
                    || $this->hasTag($tags, 'new_required')
                    || $this->hasTag($tags, 'catalog_listing_required');
                $isCatalogRequired = $this->hasTag($tags, 'catalog_required');
                $isHidden = $this->hasTag($tags, 'hidden');
                $allowsFiltering = $this->hasTag($tags, 'allow_filtering');

                if ($isRequired || $isCatalogRequired) {
                    $gap['priority'] = $isRequired ? 'critical' : 'high';
                    $result['gaps']['required'][] = $gap;
                } elseif ($allowsFiltering) {
                    $gap['priority'] = 'high';
                    $result['gaps']['filter'][] = $gap;
                } elseif ($isHidden && $this->isHiddenSeoAttribute($attrId)) {
                    $gap['priority'] = 'medium';
                    $result['gaps']['hidden'][] = $gap;
                } else {
                    // Inclui hidden operacionais (SKU, IS_KIT, etc.) e atributos visíveis opcionais
                    $gap['priority'] = 'low';
                    $result['gaps']['recommended'][] = $gap;
                }
            }

            $result['total_available'] = $fillableTotal;
            $result['filled'] = $fillableFilled;
            $result['missing_model'] = $missingModel;

            $result['missing'] = count($result['gaps']['required']) +
                                 count($result['gaps']['filter']) +
                                 count($result['gaps']['recommended']) +
                                 count($result['gaps']['hidden']);

            $result['completeness'] = $fillableTotal > 0
                ? round(($fillableFilled / $fillableTotal) * 100, 1)
                : 0;

            $result['priority_actions'] = $this->generatePriorityActions($result['gaps']);

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Tag presente no formato objeto {"hidden": true} ou lista ["hidden"] da API ML.
     */
    public function hasTag(array $tags, string $tag): bool
    {
        if ($tags === []) {
            return false;
        }

        if (array_key_exists($tag, $tags)) {
            return (bool)$tags[$tag];
        }

        return in_array($tag, $tags, true);
    }

    /**
     * Atributo editável pelo vendedor via API (exclui read_only/fixed/inferred/others).
     */
    public function isSellerFillable(array $tags): bool
    {
        foreach (self::NON_FILLABLE_TAGS as $skipTag) {
            if ($this->hasTag($tags, $skipTag)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hidden relevante para SEO/indexação (exclui attrs operacionais internos).
     */
    public function isHiddenSeoAttribute(string $attrId): bool
    {
        return !in_array($attrId, self::HIDDEN_OPS_ATTRIBUTE_IDS, true);
    }

    /**
     * Considera preenchido só se houver value_id, value_name ou values com conteúdo.
     *
     * @param array<string, array<string, mixed>> $currentAttrsById
     */
    public function isAttributeFilled(array $currentAttrsById, string $attrId): bool
    {
        if (!isset($currentAttrsById[$attrId]) || !is_array($currentAttrsById[$attrId])) {
            return false;
        }

        $attr = $currentAttrsById[$attrId];
        $valueId = $attr['value_id'] ?? null;
        if ($valueId !== null && $valueId !== '') {
            return true;
        }

        $valueName = trim((string)($attr['value_name'] ?? ''));
        if ($valueName !== '' && strcasecmp($valueName, 'N/A') !== 0) {
            return true;
        }

        if (!empty($attr['values']) && is_array($attr['values'])) {
            foreach ($attr['values'] as $value) {
                if (!is_array($value)) {
                    continue;
                }
                if (!empty($value['id']) || trim((string)($value['name'] ?? '')) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 🔍 Extrair atributos do título do anúncio
     *
     * Analisa o título e extrai possíveis valores de atributos usando
     * padrões e IA.
     */
    public function extractAttributesFromTitle(string $title, string $categoryId): array
    {
        try {
            $categoryAttrs = $this->getCategoryAttributes($categoryId);
            $extracted = [];
            $suggestions = [];
            $totalConfidence = 0;

            // Padrões mais abrangentes para extração de atributos do título
            $patterns = [
                // Marca - primeira palavra capitalizada ou palavras conhecidas
                'BRAND' => '/^([A-Z][a-zA-Z0-9]{2,})\s/u',
                // Modelo - após "modelo" ou códigos alfanuméricos
                'MODEL' => '/(?:modelo|model|mod\.?)\s*[:\-]?\s*([A-Za-z0-9\-\/]+)|([A-Z]{2,}\-?\d{2,}[A-Za-z0-9]*)/iu',
                // Cores em PT e EN
                'COLOR' => '/\b(preto|branco|azul|vermelho|verde|amarelo|rosa|roxo|cinza|prata|dourado|bege|marrom|laranja|turquesa|grafite|cromado|black|white|blue|red|green|yellow|pink|purple|gray|grey|silver|gold|beige|brown|orange)\b/iu',
                // Tamanhos com unidades
                'SIZE' => '/\b(\d+(?:[,\.]\d+)?)\s*(mm|cm|m|metros?|pol|polegadas|"|\'|inch)/iu',
                // Capacidade/Volume
                'CAPACITY' => '/\b(\d+(?:[,\.]\d+)?)\s*(ml|l|litros?|kg|g|gramas?|oz|gb|tb|mb)\b/iu',
                // Voltagem
                'VOLTAGE' => '/\b(110\s*v|220\s*v|bivolt|bi-?volt|110\/?220\s*v|127\s*v)\b/iu',
                'LINE_VOLTAGE' => '/\b(110\s*v|220\s*v|bivolt|bi-?volt|110\/?220\s*v|127\s*v)\b/iu',
                // Material
                'MATERIAL' => '/\b(algod[aã]o|couro|metal|pl[aá]stico|vidro|madeira|mdf|a[çc]o|alum[ií]nio|silicone|borracha|tecido|l[aã]|nylon|poli[eé]ster|inox|acr[ií]lico|cer[aâ]mica|porcelana|cotton|leather|plastic|glass|wood|steel|aluminum|stainless)\b/iu',
                // Potência
                'POWER' => '/\b(\d+(?:[,\.]\d+)?)\s*(w|watts?|hp|cv)\b/iu',
                // Quantidade
                'UNITS_PER_PACKAGE' => '/\b(?:kit|pack|c\/|com|cx)\s*(\d+)\s*(?:un|und|unid|pcs?|pe[çc]as?)?\b/iu',
                // Gênero
                'GENDER' => '/\b(masculino|feminino|unissex|infantil|adulto|beb[eê]|crian[çc]a|men|women|unisex|kids?)\b/iu',
            ];

            $usedPatterns = [];

            foreach ($categoryAttrs['attributes'] ?? [] as $attr) {
                $attrId = $attr['id'] ?? '';
                $attrName = $attr['name'] ?? '';
                $allowedValues = $this->getAllowedValues($attr);

                // Verificar se já extraímos este atributo
                if (in_array($attrId, $usedPatterns)) {
                    continue;
                }

                // Tentar match por padrão específico
                if (isset($patterns[$attrId])) {
                    if (preg_match($patterns[$attrId], $title, $matches)) {
                        $value = trim($matches[1] ?? $matches[2] ?? $matches[0]);
                        if (!empty($value) && strlen($value) >= 2) {
                            $extracted[] = [
                                'attribute_id' => $attrId,
                                'name' => $attrName,
                                'value' => $value,
                                'confidence' => 85,
                                'source' => 'pattern',
                            ];
                            $usedPatterns[] = $attrId;
                            $totalConfidence += 85;
                            continue;
                        }
                    }
                }

                // Tentar match por valores permitidos (mais preciso)
                if (!empty($allowedValues)) {
                    foreach ($allowedValues as $allowed) {
                        // Match mais preciso - palavra inteira
                        $escapedValue = preg_quote($allowed, '/');
                        if (preg_match("/\\b{$escapedValue}\\b/iu", $title)) {
                            $extracted[] = [
                                'attribute_id' => $attrId,
                                'name' => $attrName,
                                'value' => $allowed,
                                'confidence' => 95,
                                'source' => 'allowed_values',
                            ];
                            $usedPatterns[] = $attrId;
                            $totalConfidence += 95;
                            break;
                        }
                    }
                }
            }

            // Se temos poucos resultados e IA disponível, usar IA para extração avançada
            if (count($extracted) < 3 && $this->isAIEnabled() && $this->aiAvailable) {
                $aiExtracted = $this->extractWithAI($title, $categoryId, $categoryAttrs);
                foreach ($aiExtracted as $aiAttr) {
                    // Evitar duplicatas
                    $exists = false;
                    foreach ($extracted as $e) {
                        if ($e['attribute_id'] === $aiAttr['attribute_id']) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $extracted[] = $aiAttr;
                        $totalConfidence += $aiAttr['confidence'];
                    }
                }
            }

            // Gerar sugestões para aplicação
            foreach ($extracted as $attr) {
                $suggestions[] = [
                    'attribute_id' => $attr['attribute_id'],
                    'attribute_name' => $attr['name'],
                    'suggested_value' => $attr['value'],
                    'confidence' => $attr['confidence'],
                    'source' => 'title',  // Normalizado: era 'title_extraction'
                ];
            }

            $avgConfidence = count($extracted) > 0
                ? round($totalConfidence / count($extracted))
                : 0;

            return [
                'success' => true,
                'attributes' => $extracted,
                'suggestions' => $suggestions,
                'confidence' => $avgConfidence,
                'total_extracted' => count($extracted),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'attributes' => [],
                'suggestions' => [],
                'confidence' => 0,
            ];
        }
    }

    /**
     * Extração de atributos com IA
     */
    private function extractWithAI(string $title, string $categoryId, array $categoryAttrs): array
    {
        // Check if AI is still available in this session
        if (!$this->aiAvailable) {
            return [];
        }

        try {
            $config = require __DIR__ . '/../../../../config/app.php';
            $aiEnabled = $config['ai']['enabled'] ?? false;

            if (!$aiEnabled) {
                return [];
            }

            $prompt = "Extraia atributos do seguinte título de produto do Mercado Livre:\n\n";
            $prompt .= "Título: {$title}\n\n";
            $prompt .= "Atributos disponíveis na categoria:\n";

            $attrList = [];
            foreach (array_slice($categoryAttrs['attributes'] ?? [], 0, 20) as $attr) {
                $attrList[] = "- {$attr['id']}: {$attr['name']}";
            }
            $prompt .= implode("\n", $attrList);
            $prompt .= "\n\nRetorne um JSON com os atributos encontrados no formato:\n";
            $prompt .= '[{"attribute_id": "ID", "name": "Nome", "value": "Valor", "confidence": 0-100}]';

            // Usar AIProviderManager
            $aiManager = new \App\Services\AI\Core\AIProviderManager();
            $response = $aiManager->complete($prompt, [
                'max_tokens' => 500,
                'temperature' => 0.3,
            ]);

            if (isset($response['error'])) {
                // Check if it's a quota/rate limit error
                $errorMsg = $response['message'] ?? '';
                if (stripos($errorMsg, 'quota') !== false ||
                    stripos($errorMsg, '429') !== false ||
                    stripos($errorMsg, 'Too Many') !== false) {
                    $this->aiAvailable = false;
                    log_warning('AttributeKiller: AI desabilitada por quota/rate limit em extractWithAI', [
                        'service' => 'AttributeKiller',
                    ]);
                }
                return [];
            }

            $content = $response['content'] ?? $response['text'] ?? '';

            // Extrair JSON da resposta
            if (preg_match('/\[.*\]/s', $content, $matches)) {
                $parsed = json_decode($matches[0], true);
                if (is_array($parsed)) {
                    return array_map(function($item) {
                        return [
                            'attribute_id' => $item['attribute_id'] ?? '',
                            'name' => $item['name'] ?? '',
                            'value' => $item['value'] ?? '',
                            'confidence' => min(80, (int)($item['confidence'] ?? 70)),
                            'source' => 'ai',
                        ];
                    }, $parsed);
                }
            }

            return [];

        } catch (\Exception $e) {
            // Check if it's a quota/rate limit error
            $errorMsg = $e->getMessage();
            if (stripos($errorMsg, 'quota') !== false ||
                stripos($errorMsg, '429') !== false ||
                stripos($errorMsg, 'Too Many') !== false) {
                $this->aiAvailable = false;
                log_warning('AttributeKiller: AI desabilitada por exceção em extractWithAI', [
                    'service' => 'AttributeKiller',
                    'error' => $errorMsg,
                ]);
            }
            return [];
        }
    }

    /**
     * Verifica se IA está habilitada
     */
    private function isAIEnabled(): bool
    {
        $config = require __DIR__ . '/../../../../config/app.php';
        return ($config['ai']['enabled'] ?? false) === true;
    }

    /**
     * 🧪 Gerar plano de preenchimento (dry-run)
     *
     * Retorna sugestões e a lista de atributos que seriam enviados ao ML,
     * sem executar o PUT.
     */
    public function planMissingAttributes(string $itemId, string $categoryId, array $itemData = []): array
    {
        try {
            $plan = $this->buildFillPlan($itemId, $categoryId, $itemData);
            return [
                'success' => true,
                'item_id' => $itemId,
                'category_id' => $categoryId,
                'attributes_to_fill' => $plan['attributes_to_fill'],
                'filled' => $plan['filled'],
                'skipped' => $plan['skipped'],
                'gaps' => $plan['gaps'],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'item_id' => $itemId,
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 🤖 Preencher atributos faltantes usando IA
     */
    public function fillMissingAttributes(string $itemId, string $categoryId, array $itemData = []): array
    {
        $result = [
            'success' => false,
            'item_id' => $itemId,
            'filled' => [],
            'skipped' => [],
            'errors' => [],
        ];

        try {
            $plan = $this->buildFillPlan($itemId, $categoryId, $itemData);
            $attributesToFill = $plan['attributes_to_fill'];
            $result['filled'] = $plan['filled'];
            $result['skipped'] = $plan['skipped'];
        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
            return $result;
        }

        // Apply attributes to ML
        if (!empty($attributesToFill)) {
            try {
                // Governança: bloqueia escrita em conta blacklist (FORBIDDEN_ACCOUNTS, ex. 1335)
                // independente de como este método foi chamado (endpoint direto ou BulkOptimizer).
                (new \App\Services\HiddenSeo\SafetyGuard())->assertCanApply((int)$this->accountId, false, true);

                $updateResult = $this->mlClient->put("/items/{$itemId}", [
                    'attributes' => $attributesToFill
                ]);

                if (isset($updateResult['id'])) {
                    $result['success'] = true;
                    $result['ml_response'] = $updateResult;
                } else {
                    $result['errors'][] = 'Falha ao atualizar atributos no ML';
                }
            } catch (\Exception $e) {
                $result['errors'][] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Monta um plano de preenchimento para atributos faltantes.
     *
     * @return array{gaps:array,attributes_to_fill:array,filled:array,skipped:array,item:array}
     */
    private function buildFillPlan(string $itemId, string $categoryId, array $itemData = []): array
    {
        // Get item data for context
        if (empty($itemData)) {
            $itemData = $this->mlClient->get("/items/{$itemId}");
        }

        // Analyze gaps (reusing provided itemData to avoid extra request when possible)
        $gaps = $this->analyzeGaps($itemId, $categoryId, $itemData);

        $allGaps = array_merge(
            $gaps['gaps']['required'] ?? [],
            $gaps['gaps']['filter'] ?? [],
            $gaps['gaps']['hidden'] ?? [],
            $gaps['gaps']['recommended'] ?? []
        );

        // Sort by priority
        usort($allGaps, fn($a, $b) =>
            array_search($a['priority'], ['critical', 'high', 'medium', 'low']) <=>
            array_search($b['priority'], ['critical', 'high', 'medium', 'low'])
        );

        $attributesToFill = [];
        $filled = [];
        $skipped = [];

        foreach ($allGaps as $gap) {
            $value = null;
            $method = null;

            // 1) Inferência direta
            $value = $this->inferValueFromItem($gap, $itemData);
            if ($value !== null) {
                $method = 'inference';
            }

            // 2) IA (somente se houver allowed_values)
            if ($value === null && !empty($gap['allowed_values'])) {
                $value = $this->inferWithAI($gap, $itemData);
                if ($value !== null) {
                    $method = 'ai';
                }
            }

            if ($value !== null) {
                $attributesToFill[] = [
                    'id' => $gap['id'],
                    'value_name' => $value,
                ];
                $filled[] = [
                    'id' => $gap['id'],
                    'name' => $gap['name'],
                    'value' => $value,
                    'priority' => $gap['priority'] ?? null,
                    'method' => $method,
                ];
            } else {
                $skipped[] = [
                    'id' => $gap['id'],
                    'name' => $gap['name'],
                    'priority' => $gap['priority'] ?? null,
                    'reason' => 'Não foi possível inferir valor',
                ];
            }
        }

        return [
            'gaps' => $gaps,
            'attributes_to_fill' => $attributesToFill,
            'filled' => $filled,
            'skipped' => $skipped,
            'item' => $itemData,
        ];
    }

    /**
     * 🎯 Obter atributos ocultos (hidden) da categoria
     */
    public function getHiddenAttributes(string $categoryId): array
    {
        try {
            $categoryAttrs = $this->categoryService->getCategoryAttributes($categoryId);
            $allAttrs = isset($categoryAttrs['attributes']) ? $categoryAttrs['attributes'] : (is_array($categoryAttrs) ? $categoryAttrs : []);
            $hidden = [];

            foreach ($allAttrs as $attr) {
                if (!is_array($attr) || !isset($attr['id'])) {
                    continue;
                }
                $tags = is_array($attr['tags'] ?? null) ? $attr['tags'] : [];
                if (!$this->hasTag($tags, 'hidden')) {
                    continue;
                }
                if (!$this->isSellerFillable($tags)) {
                    continue;
                }
                if (!$this->isHiddenSeoAttribute((string)$attr['id'])) {
                    continue;
                }
                $hidden[] = [
                    'id' => $attr['id'],
                    'name' => $attr['name'] ?? $attr['id'],
                    'value_type' => $attr['value_type'] ?? 'string',
                    'allowed_values' => $this->getAllowedValues($attr),
                    'hint' => $attr['hint'] ?? null,
                    'importance' => 'seo_boost', // Hidden attrs improve search ranking
                ];
            }

            return [
                'category_id' => $categoryId,
                'hidden_attributes' => $hidden,
                'count' => count($hidden),
                'tip' => 'Atributos ocultos melhoram ranking mesmo sem aparecer na ficha',
            ];

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 📊 Gerar relatório de completude da conta
     */
    public function generateCompletenessReport(array $itemIds): array
    {
        $report = [
            'total_items' => count($itemIds),
            'analyzed' => 0,
            'avg_completeness' => 0,
            'critical_gaps' => 0,
            'items' => [],
        ];

        $totalCompleteness = 0;

        foreach ($itemIds as $itemId) {
            try {
                $item = $this->mlClient->get("/items/{$itemId}");
                $categoryId = $item['category_id'] ?? '';

                if (!$categoryId) continue;

                $gaps = $this->analyzeGaps($itemId, $categoryId);

                $report['items'][] = [
                    'item_id' => $itemId,
                    'title' => $item['title'] ?? '',
                    'completeness' => $gaps['completeness'],
                    'missing_required' => count($gaps['gaps']['required'] ?? []),
                    'missing_filter' => count($gaps['gaps']['filter'] ?? []),
                    'missing_hidden' => count($gaps['gaps']['hidden'] ?? []),
                ];

                $totalCompleteness += $gaps['completeness'];
                $report['critical_gaps'] += count($gaps['gaps']['required'] ?? []);
                $report['analyzed']++;

            } catch (\Exception $e) {
                continue;
            }
        }

        $report['avg_completeness'] = $report['analyzed'] > 0
            ? round($totalCompleteness / $report['analyzed'], 1)
            : 0;

        // Sort by completeness (worst first)
        usort($report['items'], fn($a, $b) => $a['completeness'] <=> $b['completeness']);

        return $report;
    }

    // Helper methods

    private function getAllowedValues(array $attr): array
    {
        $values = [];

        foreach ($attr['values'] ?? [] as $val) {
            $values[] = [
                'id' => $val['id'] ?? null,
                'name' => $val['name'] ?? '',
            ];
        }

        return $values;
    }

    private function canInferValue(array $attr, array $item): bool
    {
        // Check if value can be inferred from existing data
        $attrId = $attr['id'];

        // Common inferrable attributes
        $inferrable = ['BRAND', 'MODEL', 'COLOR', 'SIZE', 'MATERIAL', 'GTIN', 'MPN'];

        if (in_array($attrId, $inferrable)) {
            return true;
        }

        // Check if title contains potential values
        $title = mb_strtolower($item['title'] ?? '');
        foreach ($attr['values'] ?? [] as $val) {
            if (mb_stripos($title, $val['name'] ?? '') !== false) {
                return true;
            }
        }

        return false;
    }

    private function inferValueFromItem(array $gap, array $itemData): ?string
    {
        $attrId = $gap['id'];
        $title = $itemData['title'] ?? '';
        $allowedValues = $gap['allowed_values'] ?? [];

        // Direct mapping for common attributes
        $mappings = [
            'BRAND' => 'brand',
            'MODEL' => 'model',
            'GTIN' => 'gtin',
            'MPN' => 'seller_custom_field',
        ];

        if (isset($mappings[$attrId]) && !empty($itemData[$mappings[$attrId]])) {
            return $itemData[$mappings[$attrId]];
        }

        // Try to find value in title
        foreach ($allowedValues as $val) {
            $name = $val['name'] ?? '';
            if ($name && mb_stripos($title, $name) !== false) {
                return $name;
            }
        }

        // Condition detection
        if ($attrId === 'ITEM_CONDITION') {
            if (mb_stripos($title, 'novo') !== false || mb_stripos($title, 'lacrado') !== false) {
                return 'Novo';
            }
            if (mb_stripos($title, 'usado') !== false) {
                return 'Usado';
            }
            return 'Novo'; // Default
        }

        return null;
    }

    private function inferWithAI(array $gap, array $itemData): ?string
    {
        // Guardrail 1: Só usar IA se houver valores permitidos
        if (empty($gap['allowed_values'])) {
            return null;
        }

        // Guardrail 2: Verificar se IA está habilitada via config
        $config = require __DIR__ . '/../../../../config/app.php';
        $aiEnabled = ($config['tech_sheet']['ai_enabled'] ?? true) === true;
        if (!$aiEnabled) {
            return null;
        }

        // Guardrail 3: Limitar atributos críticos que podem usar IA
        $aiAllowedAttributes = [
            'COLOR', 'MAIN_COLOR', 'SECONDARY_COLOR',
            'SIZE', 'SCREEN_SIZE', 'MEMORY_SIZE',
            'MATERIAL', 'MAIN_MATERIAL', 'BODY_MATERIAL',
            'VOLTAGE', 'LINE_VOLTAGE',
            'CAPACITY', 'STORAGE_CAPACITY',
            'ITEM_CONDITION', 'PRODUCT_TYPE',
            'GENDER', 'AGE_GROUP',
        ];

        $attrId = $gap['id'] ?? '';
        $isAllowedForAI = false;
        foreach ($aiAllowedAttributes as $allowed) {
            if (stripos($attrId, $allowed) !== false) {
                $isAllowedForAI = true;
                break;
            }
        }

        // Atributos críticos de identificação nunca devem ser inferidos por IA
        $neverUseAI = ['BRAND', 'MODEL', 'GTIN', 'MPN', 'EAN', 'UPC', 'ISBN', 'SELLER_SKU'];
        foreach ($neverUseAI as $forbidden) {
            if (stripos($attrId, $forbidden) !== false) {
                return null;
            }
        }

        if (!$isAllowedForAI) {
            // Permitir outros atributos se tiverem poucos valores possíveis
            if (count($gap['allowed_values']) > 30) {
                return null; // Muitas opções = maior chance de erro
            }
        }

        // Guardrail 4.5: Se IA já falhou nesta sessão, não tentar de novo
        if (!$this->aiAvailable) {
            return null;
        }

        try {
            $provider = $this->aiProvider->getPrimaryProvider();
            if (!$provider) {
                $this->aiAvailable = false;
                return null;
            }

            $allowedNames = array_column($gap['allowed_values'], 'name');

            // Guardrail 4: Limitar valores no prompt para evitar confusão
            $valuesToShow = array_slice($allowedNames, 0, 20);

            $prompt = "Baseado no produto abaixo, qual é o valor mais apropriado para o atributo '{$gap['name']}'?

Produto: {$itemData['title']}

Valores permitidos:
" . implode("\n", $valuesToShow) . "

REGRAS:
- Responda APENAS com o valor exato da lista
- Se não tiver certeza, responda 'NAO_IDENTIFICADO'
- Não invente valores
- Não adicione explicação";

            $response = $provider->chat([
                ['role' => 'system', 'content' => 'Você seleciona atributos de produtos de forma precisa. Responda apenas com o valor exato da lista fornecida. Se não souber, responda NAO_IDENTIFICADO.'],
                ['role' => 'user', 'content' => $prompt]
            ], ['temperature' => 0.1, 'max_tokens' => 50]); // Temperatura ainda mais baixa

            $answer = trim($response['content'] ?? '');

            // Guardrail 5: Rejeitar respostas inválidas
            if (empty($answer) || $answer === 'NAO_IDENTIFICADO' || mb_strlen($answer) > 100) {
                return null;
            }

            // Guardrail 6: Validar resposta está exatamente nos valores permitidos
            foreach ($allowedNames as $name) {
                if (mb_strtolower($answer) === mb_strtolower($name)) {
                    return $name;
                }
            }

            // Guardrail 7: Log de resposta inválida para monitoramento
            log_warning('AttributeKiller AI: resposta inválida', [
                'service' => 'AttributeKiller',
                'attribute_id' => $attrId,
                'answer' => $answer,
            ]);

        } catch (\Exception $e) {
            // Se erro for de quota ou rate limit, desabilitar IA para esta sessão
            $errorMsg = $e->getMessage();
            if (stripos($errorMsg, 'quota') !== false ||
                stripos($errorMsg, '429') !== false ||
                stripos($errorMsg, 'Too Many') !== false ||
                stripos($errorMsg, 'rate limit') !== false) {
                $this->aiAvailable = false;
                log_warning('AttributeKiller: AI desabilitada por quota/rate limit', [
                    'service' => 'AttributeKiller',
                    'error' => $errorMsg,
                ]);
            }
            log_warning('AttributeKiller AI: erro na inferência', [
                'service' => 'AttributeKiller',
                'error' => $errorMsg,
            ]);
        }

        return null;
    }

    private function generatePriorityActions(array $gaps): array
    {
        $actions = [];

        $requiredCount = count($gaps['required'] ?? []);
        $filterCount = count($gaps['filter'] ?? []);
        $hiddenCount = count($gaps['hidden'] ?? []);

        if ($requiredCount > 0) {
            $actions[] = [
                'priority' => 1,
                'action' => "Preencher {$requiredCount} atributos OBRIGATÓRIOS",
                'impact' => 'Crítico - item pode ser penalizado',
                'type' => 'required',
            ];
        }

        if ($filterCount > 0) {
            $actions[] = [
                'priority' => 2,
                'action' => "Preencher {$filterCount} atributos de FILTRO",
                'impact' => 'Alto - aparecer em buscas filtradas',
                'type' => 'filter',
            ];
        }

        if ($hiddenCount > 0) {
            $actions[] = [
                'priority' => 3,
                'action' => "Preencher {$hiddenCount} atributos OCULTOS",
                'impact' => 'Médio - melhorar ranking SEO interno',
                'type' => 'hidden',
            ];
        }

        return $actions;
    }
}
