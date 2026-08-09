<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\AI\SEO\Strategies\SEOStrategiesEngine;
use App\Services\AI\SEO\Strategies\SynonymExpansionService;
use App\Services\AI\SEO\Strategies\HiddenFieldsService;
use App\Services\AI\SEO\Strategies\KeywordInjectorService;
use App\Services\AI\SEO\Strategies\SearchTypeCoverageService;
use App\Services\AI\SEO\Strategies\FieldWeightService;
use App\Services\AI\SEO\Strategies\UseContextService;
use App\Services\AI\SEO\Strategies\LongTailGeneratorService;
use App\Services\AI\SEO\Strategies\SemanticScoreService;
use App\Services\AI\SEO\Strategies\CompatibilityService;
use App\Services\AI\SEO\Strategies\FAQOptimizerService;
use App\Services\AI\SEO\Strategies\KeywordSourceService;
use App\Services\AI\SEO\Strategies\SEOAnalysisCacheService;
use PDO;

/**
 * 🔗 Integração Ficha Técnica + SEO Strategies Engine
 *
 * Conecta o módulo de Ficha Técnica com as 12 estratégias SEO:
 * - Enriquece sugestões de atributos com insights SEO
 * - Gera sugestões de campos ocultos (KEYWORDS, MPN, LINE)
 * - Sugere otimizações de título, descrição e compatibilidade
 * - Calcula score SEO consolidado
 *
 * @package App\Services
 */
class TechSheetSEOIntegrationService
{
    private PDO $db;
    private int $accountId;
    private SEOStrategiesEngine $engine;
    private ?MercadoLivreClient $mlClient;
    private SEOAnalysisCacheService $cache;

    public function __construct(int $accountId)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->engine = new SEOStrategiesEngine($accountId);
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->cache = new SEOAnalysisCacheService($accountId, 60); // 60 min TTL
    }

    /**
     * Análise SEO completa para um item da Ficha Técnica
     * Usa todas as 12 estratégias para análise abrangente
     * Com cache para performance
     */
    public function analyzeSEO(string $itemId): array
    {
        try {
            // Try cache first
            $cached = $this->cache->get($itemId);
            if ($cached !== null) {
                return [
                    'success' => true,
                    'item_id' => $itemId,
                    'analysis' => $cached,
                    'score' => $cached['overall_score'] ?? 0,
                    'from_cache' => true,
                ];
            }

            // Obter dados do item
            $item = $this->mlClient->get("/items/{$itemId}");

            if (!$item || isset($item['error']) || empty($item['id'])) {
                $message = $item['message'] ?? $item['error'] ?? 'Item não encontrado';
                return ['success' => false, 'error' => $message];
            }

            // Executar análise completa via Engine
            $analysis = $this->engine->analyzeItem($itemId);

            // Adicionar sugestões específicas para Ficha Técnica
            $techSheetSuggestions = $this->generateTechSheetSuggestions($item, $analysis);

            // Build cache data
            $cacheData = [
                'overall_score' => $analysis['overall_score'] ?? 0,
                'strategies' => $analysis['strategies'] ?? [],
                'suggestions' => $techSheetSuggestions,
                'category_id' => $item['category_id'] ?? null,
                'item_title' => $item['title'] ?? null,
                'item_price' => $item['price'] ?? null,
            ];

            // Save to cache
            $this->cache->set($itemId, $cacheData);

            return [
                'success' => true,
                'item_id' => $itemId,
                'analysis' => $analysis,
                'score' => $analysis['overall_score'] ?? 0,
                'suggestions' => $techSheetSuggestions,
                'priority_actions' => $this->getPriorityActions($analysis),
                'estimated_improvement' => $this->estimateImprovement($analysis),
                'from_cache' => false,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Gera sugestões SEO para campos da Ficha Técnica
     */
    public function generateSEOSuggestions(string $itemId): array
    {
        try {
            $item = $this->mlClient->get("/items/{$itemId}");
            $description = $this->getItemDescription($itemId);

            $categoryId = $item['category_id'] ?? '';
            $title = $item['title'] ?? '';
            $brand = $this->extractAttribute($item, 'BRAND');
            $model = $this->extractAttribute($item, 'MODEL');

            $suggestions = [];

            // 1. Sugestões de Campos Ocultos (E2)
            $hiddenService = new HiddenFieldsService($this->accountId);
            $hiddenAnalysis = $hiddenService->analyzeItem($itemId);

            if (!empty($hiddenAnalysis['suggestions'])) {
                foreach ($hiddenAnalysis['suggestions'] as $field => $suggestion) {
                    $suggestions[] = $this->createSuggestion(
                        $itemId,
                        $categoryId,
                        $field,
                        $suggestion['value'] ?? '',
                        'hidden_field_seo',
                        85,
                        [
                            'strategy' => 'E2_HIDDEN_FIELDS',
                            'reason' => $suggestion['reason'] ?? 'Otimização de campo oculto'
                        ]
                    );
                }
            }

            // 2. Sugestões de Sinônimos para KEYWORDS (E1)
            $synonymService = new SynonymExpansionService($this->accountId);
            $baseKeyword = $this->extractBaseKeyword($title);
            $synonyms = $categoryId && $baseKeyword !== ''
                ? $synonymService->expand($baseKeyword, $categoryId)
                : ['synonyms' => []];

            if (!empty($synonyms['synonyms'])) {
                $keywordsValue = $this->buildKeywordsValue($synonyms['synonyms'], $title);
                $suggestions[] = $this->createSuggestion(
                    $itemId,
                    $categoryId,
                    'KEYWORDS',
                    $keywordsValue,
                    'synonym_expansion',
                    82,
                    [
                        'strategy' => 'E1_SYNONYMS',
                        'synonyms_used' => count($synonyms['synonyms'])
                    ]
                );
            }

            // 3. Sugestões de Contexto de Uso (E6)
            $contextService = new UseContextService($this->accountId);
            $contextSuggestion = $contextService->suggestContexts([
                'title' => $title,
                'description' => $description,
                'category_id' => $categoryId
            ]);

            if (!empty($contextSuggestion['suggestions'])) {
                $contextKeywords = $contextService->generateContextKeywords(
                    array_column($contextSuggestion['suggestions'], 'context'),
                    $categoryId
                );

                if (!empty($contextKeywords['keywords'])) {
                    $suggestions[] = $this->createSuggestion(
                        $itemId,
                        $categoryId,
                        'KEYWORDS',
                        $this->mergeKeywords($contextKeywords['keywords']),
                        'context_keywords',
                        78,
                        [
                            'strategy' => 'E6_CONTEXTS',
                            'contexts' => array_column($contextSuggestion['suggestions'], 'context')
                        ]
                    );
                }
            }

            // 4. Sugestões de Long Tail (E7)
            $longTailService = new LongTailGeneratorService($this->accountId);
            $longTails = $longTailService->generate($baseKeyword, [
                'brand' => $brand,
                'model' => $model,
                'category_id' => $categoryId,
                'limit' => 10
            ]);

            if (!empty($longTails['long_tails'])) {
                $longTailKeywords = array_map(fn($lt) => $lt['keyword'], $longTails['long_tails']);
                $suggestions[] = $this->createSuggestion(
                    $itemId,
                    $categoryId,
                    'KEYWORDS',
                    implode(', ', array_slice($longTailKeywords, 0, 5)),
                    'long_tail_seo',
                    80,
                    [
                        'strategy' => 'E7_LONG_TAIL',
                        'long_tails_count' => count($longTails['long_tails'])
                    ]
                );
            }

            // 5. Sugestões de Compatibilidade (E10)
            $compatService = new CompatibilityService($this->accountId);
            $compatAnalysis = $compatService->analyzeCompatibility($itemId);

            if (!empty($compatAnalysis['suggested_expansion']['expanded'])) {
                $newModels = array_column($compatAnalysis['suggested_expansion']['expanded'], 'model');
                $suggestions[] = $this->createSuggestion(
                    $itemId,
                    $categoryId,
                    'COMPATIBLE_MODELS',
                    implode(', ', array_slice($newModels, 0, 10)),
                    'compatibility_expansion',
                    75,
                    [
                        'strategy' => 'E10_COMPATIBILITY',
                        'new_models' => count($newModels)
                    ]
                );
            }

            // 6. Sugestões de MPN e LINE (E2)
            if ($brand && $model) {
                $suggestions[] = $this->createSuggestion(
                    $itemId,
                    $categoryId,
                    'MPN',
                    $this->generateMPN($brand, $model, $title),
                    'mpn_generation',
                    88,
                    ['strategy' => 'E2_HIDDEN_FIELDS']
                );

                $suggestions[] = $this->createSuggestion(
                    $itemId,
                    $categoryId,
                    'LINE',
                    $this->generateLine($brand, $model),
                    'line_generation',
                    85,
                    ['strategy' => 'E2_HIDDEN_FIELDS']
                );
            }

            // Consolidar sugestões por atributo (evita sobrescrita no upsert; melhora KEYWORDS)
            $suggestions = $this->consolidateSuggestions($suggestions);

            // Salvar sugestões no banco
            $savedCount = $this->saveSuggestions($suggestions);

            return [
                'success' => true,
                'item_id' => $itemId,
                'suggestions' => $suggestions,
                'saved_count' => $savedCount
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Consolida sugestões duplicadas por atributo.
     *
     * Por padrão, a tabela usa UPSERT (ON DUPLICATE KEY UPDATE) e a última sugestão vence.
     * Aqui consolidamos antes de salvar para não perder valor — principalmente para KEYWORDS.
     *
     * Regras:
     * - KEYWORDS: une valores por vírgula e mantém a maior confiança
     * - Outros atributos: mantém a sugestão de maior confiança e guarda as alternativas em meta
     */
    private function consolidateSuggestions(array $suggestions): array
    {
        $byKey = [];

        foreach ($suggestions as $s) {
            $accountId = (int)($s['account_id'] ?? 0);
            $itemId = (string)($s['item_id'] ?? '');
            $attrId = (string)($s['attribute_id'] ?? '');

            if ($accountId <= 0 || $itemId === '' || $attrId === '') {
                continue;
            }

            $k = $accountId . '|' . $itemId . '|' . $attrId;

            if (!isset($byKey[$k])) {
                $s['meta'] = is_array($s['meta'] ?? null) ? $s['meta'] : [];
                $s['meta']['merged_sources'] = [[
                    'source' => $s['source'] ?? null,
                    'confidence' => $s['confidence'] ?? null,
                ]];
                $byKey[$k] = $s;
                continue;
            }

            $current = $byKey[$k];
            $currentMeta = is_array($current['meta'] ?? null) ? $current['meta'] : [];
            $newMeta = is_array($s['meta'] ?? null) ? $s['meta'] : [];

            $mergedSources = $currentMeta['merged_sources'] ?? [];
            if (!is_array($mergedSources)) {
                $mergedSources = [];
            }
            $mergedSources[] = [
                'source' => $s['source'] ?? null,
                'confidence' => $s['confidence'] ?? null,
            ];

            if ($attrId === 'KEYWORDS') {
                $current['suggested_value'] = $this->mergeKeywordsString(
                    (string)($current['suggested_value'] ?? ''),
                    (string)($s['suggested_value'] ?? '')
                );
                $current['confidence'] = max((int)($current['confidence'] ?? 0), (int)($s['confidence'] ?? 0));
                $currentMeta['merged_sources'] = $mergedSources;
                $currentMeta['merged'] = true;
                $current['meta'] = $this->mergeMeta($currentMeta, $newMeta);
                $byKey[$k] = $current;
                continue;
            }

            // Para outros atributos, manter a maior confiança, mas guardar alternativas
            $curConf = (int)($current['confidence'] ?? 0);
            $newConf = (int)($s['confidence'] ?? 0);

            $alternatives = $currentMeta['alternatives'] ?? [];
            if (!is_array($alternatives)) {
                $alternatives = [];
            }
            $alternatives[] = [
                'suggested_value' => (string)($s['suggested_value'] ?? ''),
                'source' => $s['source'] ?? null,
                'confidence' => $newConf,
                'meta' => $newMeta,
            ];

            // limitar para evitar meta gigante
            if (count($alternatives) > 3) {
                $alternatives = array_slice($alternatives, -3);
            }

            $currentMeta['alternatives'] = $alternatives;
            $currentMeta['merged_sources'] = $mergedSources;
            $currentMeta['merged'] = true;

            if ($newConf > $curConf) {
                // substituir sugestão principal
                $s['meta'] = $this->mergeMeta($currentMeta, $newMeta);
                $byKey[$k] = $s;
            } else {
                $current['meta'] = $this->mergeMeta($currentMeta, $newMeta);
                $byKey[$k] = $current;
            }
        }

        return array_values($byKey);
    }

    private function mergeKeywordsString(string $a, string $b): string
    {
        $parse = static function (string $s): array {
            $parts = preg_split('/\s*,\s*/', $s) ?: [];
            $out = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }
                $out[] = $p;
            }
            return $out;
        };

        $all = array_merge($parse($a), $parse($b));
        $all = array_values(array_unique(array_filter($all)));

        // manter um limite razoável (evita campo gigante)
        return implode(', ', array_slice($all, 0, 30));
    }

    private function mergeMeta(array $a, array $b): array
    {
        // Merge simples e previsível (b vence), mas preserva estruturas importantes
        foreach ($b as $k => $v) {
            if ($k === 'merged_sources' && is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                $a[$k] = array_values(array_merge($a[$k], $v));
                continue;
            }
            if ($k === 'alternatives' && is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                $merged = array_merge($a[$k], $v);
                $a[$k] = array_slice($merged, -3);
                continue;
            }
            $a[$k] = $v;
        }

        return $a;
    }

    /**
     * Otimiza título usando estratégias SEO
     */
    public function optimizeTitle(string $itemId): array
    {
        try {
            $item = $this->mlClient->get("/items/{$itemId}");
            $title = $item['title'] ?? '';
            $categoryId = $item['category_id'] ?? '';

            // Análise de cobertura
            $coverageService = new SearchTypeCoverageService($this->accountId);
            $brand = $this->extractAttribute($item, 'BRAND');
            $model = $this->extractAttribute($item, 'MODEL');
            $coverage = $coverageService->analyzeCoverage([
                'title' => $title,
                'category_id' => $categoryId,
                'brand' => $brand,
                'model' => $model,
            ]);

            // Gerar keywords de cobertura faltantes
            $missingTypes = [];
            foreach ($coverage['coverage'] ?? [] as $type => $data) {
                if (($data['score'] ?? 0) < 50) {
                    $missingTypes[] = $type;
                }
            }

            $coverageKeywords = [];
            $baseKeyword = $this->extractBaseKeyword($title);
            if (!empty($missingTypes) && $baseKeyword !== '') {
                $generated = $coverageService->generateCoverageKeywords($baseKeyword, [
                    'brand' => $brand,
                    'model' => $model,
                ], $categoryId);
                foreach ($missingTypes as $type) {
                    $typeKeywords = $generated['keywords_by_type'][$type] ?? [];
                    foreach ($typeKeywords as $kw) {
                        $coverageKeywords[] = is_array($kw) ? ($kw['keyword'] ?? '') : $kw;
                    }
                }
            }

            // Expandir sinônimos
            $synonymService = new SynonymExpansionService($this->accountId);
            $baseKeyword = $this->extractBaseKeyword($title);
            $synonyms = $categoryId && $title !== ''
                ? $synonymService->selectForField($title, 'title', $categoryId)
                : [];
            $synonymWords = array_column($synonyms, 'word');

            // Injetar keywords
            $injectorService = new KeywordInjectorService($this->accountId);
            $injected = $injectorService->injectInTitle(
                $title,
                array_merge(
                    array_filter($coverageKeywords),
                    $synonymWords
                ),
                $categoryId ?: null
            );

            // Calcular peso
            $weightService = new FieldWeightService($this->accountId);
            $originalWeight = $weightService->calculateTitleWeight($title);
            $optimizedWeight = $weightService->calculateTitleWeight($injected['optimized'] ?? $title);

            return [
                'success' => true,
                'original_title' => $title,
                'optimized_title' => $injected['optimized'] ?? $title,
                'changes' => $injected['changes'] ?? [],
                'coverage_before' => $coverage['coverage_score'] ?? 0,
                'weight_improvement' => [
                    'original' => $originalWeight,
                    'optimized' => $optimizedWeight,
                    'improvement' => $optimizedWeight - $originalWeight
                ],
                'missing_coverage_types' => $missingTypes
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Otimiza descrição com FAQs e keywords
     */
    public function optimizeDescription(string $itemId): array
    {
        try {
            $item = $this->mlClient->get("/items/{$itemId}");
            $description = $this->getItemDescription($itemId);
            $title = $item['title'] ?? '';
            $categoryId = $item['category_id'] ?? '';

            // Gerar FAQs
            $faqService = new FAQOptimizerService($this->accountId);
            $faqs = $faqService->generateFAQs([
                'title' => $title,
                'description' => $description,
                'category_id' => $categoryId
            ], 5);

            // Gerar texto de FAQ para descrição
            $faqText = $faqService->generateDescriptionText($faqs['faqs'] ?? []);

            // Injetar keywords na descrição
            $keywordService = new KeywordSourceService($this->accountId);
            $baseKeyword = $this->extractBaseKeyword($title);
            $keywordsData = $categoryId
                ? $keywordService->getKeywords($categoryId, $baseKeyword ?: $title)
                : ['keywords' => []];
            $keywordList = array_column($keywordsData['keywords'] ?? [], 'keyword');

            // Analisar densidade atual
            $injectorService = new KeywordInjectorService($this->accountId);
            $currentDensity = $injectorService->analyzeDensity($description, $keywordList);

            // Sugerir pontos de injeção
            $injectionPoints = $injectorService->suggestInjectionPoints($description, $keywordList);

            $injected = $injectorService->injectInDescription(
                $description,
                $keywordList,
                $categoryId ?: null
            );

            // Adicionar FAQ ao final
            $optimizedDescription = ($injected['optimized'] ?? $description) . $faqText;

            return [
                'success' => true,
                'original_length' => strlen($description),
                'optimized_length' => strlen($optimizedDescription),
                'faqs_added' => count($faqs['faqs'] ?? []),
                'keywords_injected' => $injected['keywords_added'] ?? 0,
                'density_before' => $currentDensity['density'] ?? 0,
                'optimized_description' => $optimizedDescription,
                'faq_schema' => $faqs['schema'] ?? null
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * ✅ Aplica um título otimizado no Mercado Livre e registra snapshot para rollback.
     */
    public function applyOptimizedTitle(string $itemId, string $title, int $userId, array $meta = []): array
    {
        try {
            $title = $this->normalizeTitle($title);
            if ($title === '') {
                return ['success' => false, 'error' => 'Título é obrigatório.'];
            }
            if (mb_strlen($title) > 60) {
                return ['success' => false, 'error' => 'Título excede o limite de 60 caracteres do Mercado Livre.'];
            }

            $item = $this->mlClient->get("/items/{$itemId}");
            if (!$item || isset($item['error']) || empty($item['id'])) {
                $message = $item['message'] ?? $item['error'] ?? 'Item não encontrado';
                return ['success' => false, 'error' => $message];
            }

            $beforeData = [
                'id' => $item['id'] ?? $itemId,
                'title' => $item['title'] ?? '',
                'category_id' => $item['category_id'] ?? null,
            ];
            $afterData = [
                'title' => $title,
                'meta' => $meta,
            ];

            $changedBy = (string)($meta['changed_by'] ?? 'user');
            if (!in_array($changedBy, ['user', 'ai', 'automation'], true)) {
                $changedBy = 'user';
            }

            $versioning = new \App\Services\SEO\VersioningService($this->accountId);
            $versionId = $versioning->createSnapshot($itemId, 'title', $beforeData, $afterData, $changedBy, $userId);

            // Governança: escrita real na API ML respeita SAFE_MODE/FORBIDDEN_ACCOUNTS
            // (mesmo guard usado em HiddenSeoSuggester/TechSheetService::applyApproved).
            (new \App\Services\HiddenSeo\SafetyGuard())->assertCanApply($this->accountId, false, true);

            $updated = $this->mlClient->put("/items/{$itemId}", [
                'title' => $title,
            ]);

            if (isset($updated['error'])) {
                $this->cleanupSnapshotVersion($versionId);
                return [
                    'success' => false,
                    'error' => $updated['message'] ?? $updated['error'] ?? 'Erro ao aplicar título',
                    'details' => $updated,
                ];
            }

            return [
                'success' => true,
                'item_id' => $itemId,
                'version_id' => $versionId,
                'change_type' => 'title',
                'applied_title' => $title,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * ✅ Aplica uma descrição (plain_text) otimizada no Mercado Livre e registra snapshot para rollback.
     */
    public function applyOptimizedDescription(string $itemId, string $plainText, int $userId, array $meta = []): array
    {
        try {
            $plainText = trim($plainText);
            if ($plainText === '') {
                return ['success' => false, 'error' => 'Descrição (plain_text) é obrigatória.'];
            }

            $item = $this->mlClient->get("/items/{$itemId}");
            if (!$item || isset($item['error']) || empty($item['id'])) {
                $message = $item['message'] ?? $item['error'] ?? 'Item não encontrado';
                return ['success' => false, 'error' => $message];
            }

            $beforeDescription = $this->getItemDescription($itemId);
            $beforeData = [
                'id' => $item['id'] ?? $itemId,
                'title' => $item['title'] ?? '',
                'category_id' => $item['category_id'] ?? null,
                'description_plain_text' => $beforeDescription,
            ];
            $afterData = [
                'description_plain_text' => $plainText,
                'meta' => $meta,
            ];

            $changedBy = (string)($meta['changed_by'] ?? 'user');
            if (!in_array($changedBy, ['user', 'ai', 'automation'], true)) {
                $changedBy = 'user';
            }

            $versioning = new \App\Services\SEO\VersioningService($this->accountId);
            $versionId = $versioning->createSnapshot($itemId, 'description', $beforeData, $afterData, $changedBy, $userId);

            // Governança: escrita real na API ML respeita SAFE_MODE/FORBIDDEN_ACCOUNTS
            // (mesmo guard usado em HiddenSeoSuggester/TechSheetService::applyApproved).
            (new \App\Services\HiddenSeo\SafetyGuard())->assertCanApply($this->accountId, false, true);

            $updated = $this->mlClient->put("/items/{$itemId}/description", [
                'plain_text' => $plainText,
            ]);

            if (isset($updated['error'])) {
                $this->cleanupSnapshotVersion($versionId);
                return [
                    'success' => false,
                    'error' => $updated['message'] ?? $updated['error'] ?? 'Erro ao aplicar descrição',
                    'details' => $updated,
                ];
            }

            return [
                'success' => true,
                'item_id' => $itemId,
                'version_id' => $versionId,
                'change_type' => 'description',
                'applied_description_length' => mb_strlen($plainText),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 🧾 Obtém a descrição atual (plain_text) do anúncio no Mercado Livre.
     * Útil para UI de pré-visualização/edição antes de aplicar otimizações.
     */
    public function getPlainTextDescription(string $itemId): array
    {
        try {
            $plainText = $this->getItemDescription($itemId);

            return [
                'success' => true,
                'item_id' => $itemId,
                'plain_text' => $plainText,
                'length' => mb_strlen($plainText),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
        return $title;
    }

    /**
     * Remove snapshot/version criado quando a aplicação falha (evita histórico “fantasma”).
     */
    private function cleanupSnapshotVersion(int $versionId): void
    {
        try {
            $stmt = $this->db->prepare('SELECT snapshot_path FROM seo_optimization_history WHERE id = :id');
            $stmt->execute(['id' => $versionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $snapshotPath = $row['snapshot_path'] ?? null;
            if (is_string($snapshotPath) && $snapshotPath !== '' && file_exists($snapshotPath)) {
                @unlink($snapshotPath);
            }

            $del = $this->db->prepare('DELETE FROM seo_optimization_history WHERE id = :id');
            $del->execute(['id' => $versionId]);
        } catch (\Exception $e) {
            // Best effort cleanup
        }
    }

    /**
     * Obtém score SEO consolidado para dashboard da Ficha Técnica
     */
    public function getSEOScore(string $itemId): array
    {
        $analysis = $this->engine->analyzeItem($itemId);

        if (isset($analysis['error'])) {
            return ['success' => false, 'error' => $analysis['error']];
        }

        return [
            'success' => true,
            'item_id' => $itemId,
            'consolidated_score' => $analysis['consolidated_score'] ?? 0,
            'quality_level' => $analysis['quality_level'] ?? 'unknown',
            'strategy_scores' => $analysis['strategy_scores'] ?? [],
            'recommendations_count' => count($analysis['recommendations'] ?? []),
            'top_recommendations' => array_slice($analysis['recommendations'] ?? [], 0, 3)
        ];
    }

    /**
     * Gera relatório SEO completo para exportação
     */
    public function generateSEOReport(string $itemId): array
    {
        $analysis = $this->analyzeSEO($itemId);

        if (!($analysis['success'] ?? false)) {
            return $analysis;
        }

        $item = $this->mlClient->get("/items/{$itemId}");

        return [
            'success' => true,
            'report' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'item' => [
                    'id' => $itemId,
                    'title' => $item['title'] ?? '',
                    'category_id' => $item['category_id'] ?? ''
                ],
                'scores' => [
                    'consolidated' => $analysis['seo_analysis']['consolidated_score'] ?? 0,
                    'quality_level' => $analysis['seo_analysis']['quality_level'] ?? 'unknown',
                    'by_strategy' => $analysis['seo_analysis']['strategy_scores'] ?? []
                ],
                'suggestions' => $analysis['tech_sheet_suggestions'] ?? [],
                'priority_actions' => $analysis['priority_actions'] ?? [],
                'estimated_improvement' => $analysis['estimated_improvement'] ?? 0
            ]
        ];
    }

    /**
     * Aplica sugestões SEO em batch
     */
    public function batchApplySEOSuggestions(array $itemIds): array
    {
        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($itemIds as $itemId) {
            $result = $this->generateSEOSuggestions($itemId);

            $results[$itemId] = [
                'success' => $result['success'] ?? false,
                'suggestions_count' => count($result['suggestions'] ?? [])
            ];

            if ($result['success'] ?? false) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        return [
            'success' => true,
            'total' => count($itemIds),
            'total_processed' => count($itemIds),
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'results' => $results
        ];
    }

    /**
     * 🔥 Enriquece sugestões da Ficha Técnica com dados de Trends do Mercado Livre
     *
     * Usa os endpoints oficiais:
     * - GET /trends/sites/{site_id} - Tendências gerais
     * - GET /trends/sites/{site_id}/categories/{category_id} - Tendências por categoria
     *
     * @param string $itemId ID do item
     * @return array Dados de trends + sugestões enriquecidas
     */
    public function enrichWithTrends(string $itemId): array
    {
        try {
            $item = $this->mlClient->get("/items/{$itemId}");

            if (!$item || isset($item['error'])) {
                return ['success' => false, 'error' => 'Item não encontrado'];
            }

            $categoryId = $item['category_id'] ?? '';
            $title = $item['title'] ?? '';
            $siteId = 'MLB'; // Brasil

            // 1. Buscar tendências gerais do site
            $siteTrends = $this->fetchSiteTrends($siteId);

            // 2. Buscar tendências da categoria específica
            $categoryTrends = $categoryId
                ? $this->fetchCategoryTrends($siteId, $categoryId)
                : [];

            // 3. Minerar keywords das tendências
            $trendKeywords = $this->extractTrendKeywords($siteTrends, $categoryTrends);

            // 4. Identificar oportunidades de otimização
            $opportunities = $this->identifyTrendOpportunities($title, $trendKeywords);

            // 5. Gerar sugestões baseadas em tendências
            $trendSuggestions = $this->generateTrendBasedSuggestions(
                $itemId,
                $categoryId,
                $opportunities
            );

            return [
                'success' => true,
                'item_id' => $itemId,
                'category_id' => $categoryId,
                'site_trends' => [
                    'keywords' => array_slice($siteTrends['keywords'] ?? [], 0, 10),
                    'trend_score' => $siteTrends['trend_score'] ?? 0,
                ],
                'category_trends' => [
                    'keywords' => array_slice($categoryTrends['keywords'] ?? [], 0, 10),
                    'top_searches' => $categoryTrends['top_searches'] ?? [],
                    'growing_terms' => $categoryTrends['growing_terms'] ?? [],
                ],
                'opportunities' => $opportunities,
                'suggestions' => $trendSuggestions,
                'recommendation' => $this->getTrendRecommendation($opportunities),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Busca tendências gerais do site (país)
     * GET /trends/sites/{site_id}
     */
    private function fetchSiteTrends(string $siteId): array
    {
        try {
            // Cache por 1 hora
            $cacheKey = "site_trends_{$siteId}";
            $cached = $this->getCachedTrends($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            $response = $this->mlClient->get("/trends/sites/{$siteId}");

            if (!$response || isset($response['error'])) {
                return ['keywords' => [], 'trend_score' => 0];
            }

            $result = [
                'keywords' => $this->parseKeywords($response),
                'categories' => $response['categories'] ?? [],
                'trend_score' => $this->calculateTrendScore($response),
                'fetched_at' => date('Y-m-d H:i:s'),
            ];

            $this->setCachedTrends($cacheKey, $result, 3600);
            return $result;
        } catch (\Exception $e) {
            log_warning('Erro ao buscar site trends', [
                'service' => 'TechSheetSEOIntegrationService',
                'error' => $e->getMessage(),
            ]);
            return ['keywords' => [], 'trend_score' => 0];
        }
    }

    /**
     * Busca tendências da categoria específica
     * GET /trends/sites/{site_id}/categories/{category_id}
     */
    private function fetchCategoryTrends(string $siteId, string $categoryId): array
    {
        try {
            // Cache por 30 minutos (mais volátil que site trends)
            $cacheKey = "cat_trends_{$siteId}_{$categoryId}";
            $cached = $this->getCachedTrends($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            $response = $this->mlClient->get("/trends/sites/{$siteId}/categories/{$categoryId}");

            if (!$response || isset($response['error'])) {
                // Fallback: usar TrendsService existente
                $trendsService = new TrendsService($this->accountId);
                return $trendsService->getCategoryTrends($categoryId, ['site_id' => $siteId]);
            }

            $result = [
                'keywords' => $this->parseKeywords($response),
                'top_searches' => $response['keywords'] ?? [],
                'growing_terms' => $this->identifyGrowingTerms($response),
                'trend_score' => $this->calculateTrendScore($response),
                'fetched_at' => date('Y-m-d H:i:s'),
            ];

            $this->setCachedTrends($cacheKey, $result, 1800);
            return $result;
        } catch (\Exception $e) {
            log_warning('Erro ao buscar category trends', [
                'service' => 'TechSheetSEOIntegrationService',
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            return ['keywords' => [], 'top_searches' => [], 'growing_terms' => []];
        }
    }

    /**
     * Extrai keywords relevantes das tendências
     */
    private function extractTrendKeywords(array $siteTrends, array $categoryTrends): array
    {
        $keywords = [];
        $seen = [];

        // Keywords do site (menor peso, mais genéricas)
        foreach ($siteTrends['keywords'] ?? [] as $kw) {
            $word = is_array($kw) ? ($kw['keyword'] ?? $kw['name'] ?? '') : $kw;
            $wordLower = mb_strtolower($word);
            if ($word && !isset($seen[$wordLower])) {
                $seen[$wordLower] = true;
                $keywords[] = [
                    'keyword' => $word,
                    'source' => 'site_trends',
                    'weight' => 60,
                    'trend' => $kw['trend'] ?? 'stable',
                ];
            }
        }

        // Keywords da categoria (maior peso, mais relevantes)
        foreach ($categoryTrends['keywords'] ?? [] as $kw) {
            $word = is_array($kw) ? ($kw['keyword'] ?? $kw['name'] ?? '') : $kw;
            $wordLower = mb_strtolower($word);
            if ($word && !isset($seen[$wordLower])) {
                $seen[$wordLower] = true;
                $keywords[] = [
                    'keyword' => $word,
                    'source' => 'category_trends',
                    'weight' => 85,
                    'trend' => $kw['trend'] ?? 'stable',
                ];
            } elseif (isset($seen[$wordLower])) {
                // Já existe, aumentar peso
                foreach ($keywords as &$existingKw) {
                    if (mb_strtolower($existingKw['keyword']) === $wordLower) {
                        $existingKw['weight'] = min(100, $existingKw['weight'] + 20);
                        break;
                    }
                }
            }
        }

        // Keywords em crescimento (peso máximo)
        foreach ($categoryTrends['growing_terms'] ?? [] as $term) {
            $word = is_array($term) ? ($term['keyword'] ?? $term['name'] ?? '') : $term;
            $wordLower = mb_strtolower($word);
            if ($word && !isset($seen[$wordLower])) {
                $seen[$wordLower] = true;
                $keywords[] = [
                    'keyword' => $word,
                    'source' => 'growing_trends',
                    'weight' => 95,
                    'trend' => 'growing',
                ];
            }
        }

        // Ordenar por peso
        usort($keywords, fn($a, $b) => $b['weight'] <=> $a['weight']);

        return $keywords;
    }

    /**
     * Identifica oportunidades de otimização baseadas em trends
     */
    private function identifyTrendOpportunities(string $title, array $trendKeywords): array
    {
        $opportunities = [];
        $titleLower = mb_strtolower($title);

        foreach ($trendKeywords as $kw) {
            $keyword = $kw['keyword'] ?? '';
            $keywordLower = mb_strtolower($keyword);

            // Verificar se a keyword em tendência está no título
            if ($keyword && strpos($titleLower, $keywordLower) === false) {
                $opportunities[] = [
                    'keyword' => $keyword,
                    'source' => $kw['source'] ?? 'trends',
                    'weight' => $kw['weight'] ?? 50,
                    'trend' => $kw['trend'] ?? 'stable',
                    'opportunity_type' => 'missing_trend_keyword',
                    'recommendation' => "Considere adicionar '{$keyword}' (em alta nas buscas)",
                ];
            }
        }

        // Limitar a 10 oportunidades
        return array_slice($opportunities, 0, 10);
    }

    /**
     * Gera sugestões para a Ficha Técnica baseadas em tendências
     */
    private function generateTrendBasedSuggestions(
        string $itemId,
        string $categoryId,
        array $opportunities
    ): array {
        $suggestions = [];

        // Agrupar keywords por tipo de oportunidade
        $missingKeywords = array_filter(
            $opportunities,
            fn($o) => ($o['opportunity_type'] ?? '') === 'missing_trend_keyword'
        );

        if (!empty($missingKeywords)) {
            // Sugestão de KEYWORDS com termos em tendência
            $trendingKeywords = array_map(fn($o) => $o['keyword'], array_slice($missingKeywords, 0, 5));

            $suggestions[] = $this->createSuggestion(
                $itemId,
                $categoryId,
                'KEYWORDS',
                implode(', ', $trendingKeywords),
                'ml_trends_api',
                88, // Alta confiança - dados diretos do ML
                [
                    'strategy' => 'ML_TRENDS_INTEGRATION',
                    'reason' => 'Termos em alta nas buscas do Mercado Livre',
                    'keywords_count' => count($trendingKeywords),
                    'source' => 'trends_api',
                ]
            );

            // Sugestões de termos em crescimento para destaque
            $growingTerms = array_filter(
                $missingKeywords,
                fn($o) => ($o['trend'] ?? '') === 'growing'
            );

            if (!empty($growingTerms)) {
                $growingKeywords = array_map(fn($o) => $o['keyword'], array_slice($growingTerms, 0, 3));

                $suggestions[] = $this->createSuggestion(
                    $itemId,
                    $categoryId,
                    'KEYWORDS',
                    implode(', ', $growingKeywords),
                    'ml_growing_trends',
                    92, // Altíssima confiança - tendência em crescimento
                    [
                        'strategy' => 'ML_GROWING_TRENDS',
                        'reason' => 'Termos em CRESCIMENTO nas buscas',
                        'is_growing' => true,
                    ]
                );
            }
        }

        return $suggestions;
    }

    /**
     * Parse keywords da resposta do ML Trends API
     */
    private function parseKeywords(array $response): array
    {
        $keywords = [];

        // Estrutura pode variar dependendo do endpoint
        if (isset($response['keywords']) && is_array($response['keywords'])) {
            foreach ($response['keywords'] as $kw) {
                if (is_string($kw)) {
                    $keywords[] = ['keyword' => $kw, 'trend' => 'stable'];
                } elseif (is_array($kw)) {
                    $keywords[] = [
                        'keyword' => $kw['keyword'] ?? $kw['name'] ?? '',
                        'trend' => $kw['trend'] ?? 'stable',
                        'volume' => $kw['volume'] ?? $kw['search_volume'] ?? 0,
                    ];
                }
            }
        }

        // Também pode vir em "content" ou "results"
        foreach (['content', 'results'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                foreach ($response[$key] as $item) {
                    if (is_array($item) && isset($item['keyword'])) {
                        $keywords[] = $item;
                    }
                }
            }
        }

        return $keywords;
    }

    /**
     * Identifica termos em crescimento
     */
    private function identifyGrowingTerms(array $response): array
    {
        $growing = [];

        // Verificar se há campo específico de trends/growth
        if (isset($response['growth']) && is_array($response['growth'])) {
            return $response['growth'];
        }

        // Filtrar keywords com trend = growing ou growth_rate > 0
        foreach ($response['keywords'] ?? [] as $kw) {
            if (!is_array($kw)) continue;

            $trend = $kw['trend'] ?? '';
            $growthRate = $kw['growth_rate'] ?? $kw['growth'] ?? 0;

            if ($trend === 'growing' || $trend === 'up' || $growthRate > 10) {
                $growing[] = $kw;
            }
        }

        return $growing;
    }

    /**
     * Calcula score de tendência
     */
    private function calculateTrendScore(array $response): int
    {
        if (isset($response['trend_score'])) {
            return (int)$response['trend_score'];
        }

        // Calcular baseado em quantidade e qualidade de keywords
        $keywords = $response['keywords'] ?? [];
        $count = count($keywords);

        if ($count === 0) return 0;
        if ($count >= 20) return 90;
        if ($count >= 10) return 75;
        if ($count >= 5) return 60;

        return 40;
    }

    /**
     * Gera recomendação baseada nas oportunidades
     */
    private function getTrendRecommendation(array $opportunities): string
    {
        $count = count($opportunities);
        $growingCount = count(array_filter($opportunities, fn($o) => ($o['trend'] ?? '') === 'growing'));

        if ($count === 0) {
            return '✅ Seu anúncio já contém as principais keywords em tendência!';
        }

        if ($growingCount > 0) {
            return "🚀 Encontramos {$growingCount} termo(s) em CRESCIMENTO que você pode adicionar para aumentar visibilidade!";
        }

        return "📈 Encontramos {$count} termo(s) em tendência que podem melhorar seu posicionamento.";
    }

    /**
     * Cache helpers para trends
     */
    private function getCachedTrends(string $key): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT data, expires_at
                FROM cache_items
                WHERE cache_key = :key AND expires_at > NOW()
            ");
            $stmt->execute(['key' => "trends_{$key}"]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && $row['data']) {
                return json_decode($row['data'], true);
            }
        } catch (\Exception $e) {
            // Cache miss silencioso
        }
        return null;
    }

    private function setCachedTrends(string $key, array $data, int $ttl): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO cache_items (cache_key, data, expires_at, created_at)
                VALUES (:key, :data, DATE_ADD(NOW(), INTERVAL :ttl SECOND), NOW())
                ON DUPLICATE KEY UPDATE
                    data = VALUES(data),
                    expires_at = VALUES(expires_at)
            ");
            $stmt->execute([
                'key' => "trends_{$key}",
                'data' => json_encode($data),
                'ttl' => $ttl,
            ]);
        } catch (\Exception $e) {
            // Cache write failure silencioso
        }
    }

    // ========================================================================
    // MÉTODOS PRIVADOS
    // ========================================================================

    private function generateTechSheetSuggestions(array $item, array $analysis): array
    {
        $suggestions = [];
        $scores = $analysis['strategy_scores'] ?? [];

        // Sugestões baseadas em scores baixos
        if (($scores['E2_HIDDEN_FIELDS'] ?? 100) < 70) {
            $suggestions[] = [
                'type' => 'hidden_fields',
                'priority' => 'high',
                'message' => 'Preencher campos ocultos (KEYWORDS, MPN, LINE)',
                'action' => 'generate_seo_suggestions'
            ];
        }

        if (($scores['E4_COVERAGE'] ?? 100) < 60) {
            $suggestions[] = [
                'type' => 'coverage',
                'priority' => 'high',
                'message' => 'Aumentar cobertura de tipos de busca',
                'action' => 'optimize_title'
            ];
        }

        if (($scores['E10_COMPATIBILITY'] ?? 100) < 50) {
            $suggestions[] = [
                'type' => 'compatibility',
                'priority' => 'medium',
                'message' => 'Expandir lista de modelos compatíveis',
                'action' => 'expand_compatibility'
            ];
        }

        if (($scores['E11_FAQ'] ?? 100) < 40) {
            $suggestions[] = [
                'type' => 'faq',
                'priority' => 'medium',
                'message' => 'Adicionar FAQ à descrição',
                'action' => 'optimize_description'
            ];
        }

        return $suggestions;
    }

    private function getPriorityActions(array $analysis): array
    {
        $actions = [];
        $scores = $analysis['strategy_scores'] ?? [];

        // Ordenar por score (pior primeiro)
        asort($scores);

        $count = 0;
        foreach ($scores as $strategy => $score) {
            if ($score < 70 && $count < 3) {
                $actions[] = [
                    'strategy' => $strategy,
                    'score' => $score,
                    'action' => $this->getActionForStrategy($strategy)
                ];
                $count++;
            }
        }

        return $actions;
    }

    private function getActionForStrategy(string $strategy): string
    {
        $actions = [
            'E1_SYNONYMS' => 'Expandir sinônimos no título',
            'E2_HIDDEN_FIELDS' => 'Preencher KEYWORDS, MPN, LINE',
            'E3_INJECTION' => 'Otimizar densidade de keywords',
            'E4_COVERAGE' => 'Aumentar cobertura de busca',
            'E5_FIELD_WEIGHT' => 'Redistribuir keywords pelos campos',
            'E6_CONTEXTS' => 'Adicionar contextos de uso',
            'E7_LONG_TAIL' => 'Adicionar keywords long-tail',
            'E9_SEMANTIC' => 'Melhorar relevância semântica',
            'E10_COMPATIBILITY' => 'Expandir compatibilidade',
            'E11_FAQ' => 'Adicionar FAQ na descrição'
        ];

        return $actions[$strategy] ?? 'Otimizar estratégia';
    }

    private function estimateImprovement(array $analysis): float
    {
        $currentScore = $analysis['consolidated_score'] ?? 0;
        $targetScore = 85;

        return max(0, $targetScore - $currentScore);
    }

    private function createSuggestion(
        string $itemId,
        string $categoryId,
        string $attributeId,
        string $value,
        string $source,
        int $confidence,
        array $meta = []
    ): array {
        $normalizedSource = $this->normalizeSuggestionSource($source);
        if ($normalizedSource !== $source) {
            $meta['source_raw'] = $source;
        }

        return [
            'account_id' => $this->accountId,
            'item_id' => $itemId,
            'category_id' => $categoryId,
            'attribute_id' => $attributeId,
            'attribute_name' => $this->getAttributeName($attributeId),
            'suggested_value' => $value,
            'source' => $normalizedSource,
            'confidence' => $confidence,
            'status' => 'pending',
            'meta' => $meta
        ];
    }

    private const TECH_SHEET_ALLOWED_SOURCES = [
        'title_extraction',
        'inference',
        'competitor',
        'ai',
        'default',
        'manual',
    ];

    private function normalizeSuggestionSource(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return 'inference';
        }

        if (in_array($source, self::TECH_SHEET_ALLOWED_SOURCES, true)) {
            return $source;
        }

        $s = mb_strtolower($source);

        if (str_contains($s, 'title')) {
            return 'title_extraction';
        }
        if (str_contains($s, 'competitor')) {
            return 'competitor';
        }
        if (str_contains($s, 'ai')) {
            return 'ai';
        }
        if ($s === 'manual') {
            return 'manual';
        }

        return 'inference';
    }

    private function saveSuggestions(array $suggestions): int
    {
        $saved = 0;

        $stmt = $this->db->prepare("
            INSERT INTO tech_sheet_suggestions
            (account_id, item_id, category_id, attribute_id, attribute_name,
             suggested_value, source, confidence, status, meta, created_at)
            VALUES
            (:account_id, :item_id, :category_id, :attribute_id, :attribute_name,
             :suggested_value, :source, :confidence, :status, :meta, NOW())
            ON DUPLICATE KEY UPDATE
                suggested_value = VALUES(suggested_value),
                source = VALUES(source),
                confidence = VALUES(confidence),
                meta = VALUES(meta),
                updated_at = NOW()
        ");

        foreach ($suggestions as $sugg) {
            try {
                $stmt->execute([
                    'account_id' => $sugg['account_id'],
                    'item_id' => $sugg['item_id'],
                    'category_id' => $sugg['category_id'],
                    'attribute_id' => $sugg['attribute_id'],
                    'attribute_name' => $sugg['attribute_name'],
                    'suggested_value' => $sugg['suggested_value'],
                    'source' => $sugg['source'],
                    'confidence' => $sugg['confidence'],
                    'status' => $sugg['status'],
                    'meta' => json_encode($sugg['meta'] ?? [])
                ]);
                $saved++;
            } catch (\Exception $e) {
                // Log error but continue
            }
        }

        return $saved;
    }

    private function getItemDescription(string $itemId): string
    {
        try {
            $desc = $this->mlClient->get("/items/{$itemId}/description");
            return $desc['plain_text'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    private function extractAttribute(array $item, string $attrId): string
    {
        foreach ($item['attributes'] ?? [] as $attr) {
            if (($attr['id'] ?? '') === $attrId) {
                return $attr['value_name'] ?? '';
            }
        }
        return '';
    }

    private function extractBaseKeyword(string $title): string
    {
        $stopWords = ['para', 'com', 'sem', 'de', 'da', 'do', 'em', 'no', 'na', 'e', 'ou'];
        $words = preg_split('/\s+/', mb_strtolower($title));

        $keywords = [];
        foreach ($words as $word) {
            $word = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            if (strlen($word) > 2 && !in_array($word, $stopWords)) {
                $keywords[] = $word;
                if (count($keywords) >= 2) break;
            }
        }

        return implode(' ', $keywords);
    }

    private function buildKeywordsValue(array $synonyms, string $title): string
    {
        $keywords = [];

        // Adicionar sinônimos
        foreach ($synonyms as $syn) {
            $word = is_array($syn) ? ($syn['word'] ?? '') : $syn;
            if (!empty($word) && stripos($title, $word) === false) {
                $keywords[] = $word;
            }
        }

        return implode(', ', array_slice($keywords, 0, 10));
    }

    private function mergeKeywords(array $keywords): string
    {
        $values = [];
        foreach ($keywords as $kw) {
            $values[] = is_array($kw) ? ($kw['keyword'] ?? '') : $kw;
        }
        return implode(', ', array_filter(array_unique($values)));
    }

    private function generateMPN(string $brand, string $model, string $title): string
    {
        // Gerar MPN a partir de brand + model ou extrair do título
        $mpn = mb_strtoupper(mb_substr($brand, 0, 3)) . '-' . preg_replace('/[^A-Z0-9]/i', '', $model);
        return $mpn;
    }

    private function generateLine(string $brand, string $model): string
    {
        return (mb_strtoupper(mb_substr($brand, 0, 1)) . mb_substr($brand, 1)) . ' ' . (mb_strtoupper(mb_substr($model, 0, 1)) . mb_substr($model, 1));
    }

    private function getAttributeName(string $attrId): string
    {
        $names = [
            'KEYWORDS' => 'Palavras-chave',
            'MPN' => 'Código do Fabricante',
            'LINE' => 'Linha',
            'COMPATIBLE_MODELS' => 'Modelos Compatíveis',
            'GTIN' => 'Código de Barras',
            'ALPHANUMERIC_MODEL' => 'Modelo Alfanumérico'
        ];

        return $names[$attrId] ?? $attrId;
    }
}
