<?php

declare(strict_types=1);

namespace App\Services\AI\SEO\Strategies;

use App\Database;

/**
 * 🎯 E6: Use Context Service
 *
 * Gerencia contextos de uso para enriquecer anúncios:
 * - PROFISSIONAL: delivery, motoboy, trabalho, ifood, uber eats
 * - LAZER: viagem, passeio, turismo, trilha
 * - URBANO: cidade, dia a dia, diário
 * - CARGA: capacete, transporte, bagagem, compras
 *
 * Cada contexto adiciona keywords relevantes e aumenta
 * a cobertura de buscas específicas.
 *
 * @package App\Services\AI\SEO\Strategies
 */
class UseContextService
{
    private ?int $accountId;

    /**
     * Definição dos contextos de uso e suas keywords
     */
    private const USE_CONTEXTS = [
        'profissional' => [
            'name' => 'Profissional',
            'description' => 'Uso profissional, trabalho, delivery',
            'weight' => 1.2,
            'keywords' => [
                'delivery' => 1.25,
                'motoboy' => 1.20,
                'entrega' => 1.15,
                'trabalho' => 1.10,
                'ifood' => 1.15,
                'uber eats' => 1.10,
                'rappi' => 1.10,
                '99 food' => 1.05,
                'profissional' => 1.10,
                'comercial' => 1.05
            ],
            'phrases' => [
                'ideal para motoboy',
                'perfeito para delivery',
                'uso profissional diário',
                'resistente para trabalho intenso'
            ]
        ],
        'lazer' => [
            'name' => 'Lazer',
            'description' => 'Viagens, passeios, turismo',
            'weight' => 1.0,
            'keywords' => [
                'viagem' => 1.00,
                'passeio' => 0.95,
                'turismo' => 0.95,
                'trilha' => 0.90,
                'aventura' => 0.90,
                'final de semana' => 0.85,
                'lazer' => 0.95,
                'férias' => 0.90,
                'estrada' => 0.90
            ],
            'phrases' => [
                'perfeito para viagens',
                'ideal para passeios',
                'companheiro de aventuras',
                'conforto nas estradas'
            ]
        ],
        'urbano' => [
            'name' => 'Urbano',
            'description' => 'Uso diário na cidade',
            'weight' => 0.9,
            'keywords' => [
                'cidade' => 0.90,
                'dia a dia' => 0.90,
                'diário' => 0.85,
                'urbano' => 0.85,
                'cotidiano' => 0.80,
                'trânsito' => 0.85,
                'prático' => 0.90,
                'compacto' => 0.85
            ],
            'phrases' => [
                'prático para o dia a dia',
                'ideal para uso urbano',
                'compacto para cidade',
                'facilita seu cotidiano'
            ]
        ],
        'carga' => [
            'name' => 'Carga',
            'description' => 'Transporte de itens específicos',
            'weight' => 1.1,
            'keywords' => [
                'capacete' => 1.15,
                'transporte' => 1.00,
                'bagagem' => 1.00,
                'compras' => 0.95,
                'carga' => 1.00,
                'volume' => 0.95,
                'espaço' => 0.90,
                'organização' => 0.85,
                'armazenamento' => 0.90
            ],
            'phrases' => [
                'cabe capacete',
                'espaço para bagagem',
                'amplo volume interno',
                'organização de carga'
            ]
        ]
    ];

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
    }

    /**
     * Obtém todos os contextos disponíveis
     */
    public function getAvailableContexts(): array
    {
        $contexts = [];

        foreach (self::USE_CONTEXTS as $id => $context) {
            $contexts[$id] = [
                'id' => $id,
                'name' => $context['name'],
                'description' => $context['description'],
                'weight' => $context['weight'],
                'keyword_count' => count($context['keywords']),
                'phrase_count' => count($context['phrases'])
            ];
        }

        return [
            'contexts' => $contexts,
            'total' => count($contexts)
        ];
    }

    /**
     * Obtém contextos para uma categoria específica
     */
    public function getContextsForCategory(string $categoryId): array
    {
        $db = Database::getInstance();

        // Buscar contextos customizados do banco
        $stmt = $db->prepare("
            SELECT context_type, keyword, weight
            FROM seo_use_contexts
            WHERE category_id = :category_id AND is_active = 1
        ");
        $stmt->execute(['category_id' => $categoryId]);
        $customContexts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Se houver customizados, usar esses
        if (!empty($customContexts)) {
            $contexts = [];
            foreach ($customContexts as $row) {
                $type = $row['context_type'];
                if (!isset($contexts[$type])) {
                    $contexts[$type] = [
                        'name' => self::USE_CONTEXTS[$type]['name'] ?? ucfirst($type),
                        'keywords' => []
                    ];
                }
                $contexts[$type]['keywords'][$row['keyword']] = (float) $row['weight'];
            }

            return [
                'category_id' => $categoryId,
                'source' => 'database',
                'contexts' => $contexts
            ];
        }

        // Senão, retornar padrão
        return [
            'category_id' => $categoryId,
            'source' => 'default',
            'contexts' => self::USE_CONTEXTS
        ];
    }

    /**
     * Detecta contextos presentes em um texto
     */
    public function detectContexts(string $text): array
    {
        $text = mb_strtolower($text);
        $detected = [];

        foreach (self::USE_CONTEXTS as $contextId => $context) {
            $matches = [];
            $totalWeight = 0;

            foreach ($context['keywords'] as $keyword => $weight) {
                if (stripos($text, $keyword) !== false) {
                    $matches[] = $keyword;
                    $totalWeight += $weight;
                }
            }

            if (!empty($matches)) {
                $detected[$contextId] = [
                    'context' => $contextId,
                    'name' => $context['name'],
                    'matches' => $matches,
                    'match_count' => count($matches),
                    'total_weight' => round($totalWeight, 2),
                    'confidence' => min(100, count($matches) * 20)
                ];
            }
        }

        // Ordenar por peso total
        uasort($detected, fn($a, $b) => $b['total_weight'] <=> $a['total_weight']);

        return [
            'detected_contexts' => $detected,
            'primary_context' => !empty($detected) ? array_key_first($detected) : null,
            'total_detected' => count($detected)
        ];
    }

    /**
     * Gera keywords de contexto para enriquecer anúncio
     */
    public function generateContextKeywords(
        array $contexts,
        ?string $categoryId = null,
        int $limit = 10
    ): array {
        $keywords = [];

        // Se houver categoria, buscar customizados primeiro
        if ($categoryId) {
            $customData = $this->getContextsForCategory($categoryId);
            if ($customData['source'] === 'database') {
                foreach ($contexts as $contextId) {
                    if (isset($customData['contexts'][$contextId])) {
                        foreach ($customData['contexts'][$contextId]['keywords'] as $kw => $weight) {
                            $keywords[] = [
                                'keyword' => $kw,
                                'weight' => $weight,
                                'context' => $contextId,
                                'source' => 'database'
                            ];
                        }
                    }
                }
            }
        }

        // Complementar com padrão
        foreach ($contexts as $contextId) {
            if (isset(self::USE_CONTEXTS[$contextId])) {
                foreach (self::USE_CONTEXTS[$contextId]['keywords'] as $kw => $weight) {
                    // Evitar duplicatas
                    $exists = array_filter($keywords, fn(array $k): bool => $k['keyword'] === $kw);
                    if (empty($exists)) {
                        $keywords[] = [
                            'keyword' => $kw,
                            'weight' => $weight,
                            'context' => $contextId,
                            'source' => 'default'
                        ];
                    }
                }
            }
        }

        // Ordenar por peso e limitar
        usort($keywords, fn($a, $b) => $b['weight'] <=> $a['weight']);
        $keywords = array_slice($keywords, 0, $limit);

        return [
            'keywords' => $keywords,
            'contexts' => $contexts,
            'total' => count($keywords)
        ];
    }

    /**
     * Gera frases de contexto para descrição
     */
    public function generateContextPhrases(
        array $contexts,
        int $limit = 4
    ): array {
        $phrases = [];

        foreach ($contexts as $contextId) {
            if (isset(self::USE_CONTEXTS[$contextId]['phrases'])) {
                foreach (self::USE_CONTEXTS[$contextId]['phrases'] as $phrase) {
                    $phrases[] = [
                        'phrase' => $phrase,
                        'context' => $contextId
                    ];
                }
            }
        }

        // Limitar e diversificar (uma de cada contexto se possível)
        $selected = [];
        $usedContexts = [];

        foreach ($phrases as $p) {
            if (!in_array($p['context'], $usedContexts)) {
                $selected[] = $p;
                $usedContexts[] = $p['context'];
                if (count($selected) >= $limit) break;
            }
        }

        // Completar se necessário
        while (count($selected) < $limit && count($selected) < count($phrases)) {
            foreach ($phrases as $p) {
                if (!in_array($p, $selected)) {
                    $selected[] = $p;
                    if (count($selected) >= $limit) break;
                }
            }
        }

        return [
            'phrases' => $selected,
            'contexts' => $contexts,
            'total' => count($selected)
        ];
    }

    /**
     * Sugere contextos baseado no produto
     */
    public function suggestContexts(array $productData): array
    {
        $title = $productData['title'] ?? '';
        $description = $productData['description'] ?? '';
        $brand = $productData['brand'] ?? '';
        $categoryId = $productData['category_id'] ?? null;

        // Concatenar texto para análise
        $fullText = implode(' ', [$title, $description, $brand]);

        // Detectar contextos no texto atual
        $detected = $this->detectContexts($fullText);

        // Sugerir contextos não detectados
        $suggestions = [];
        foreach (self::USE_CONTEXTS as $contextId => $context) {
            if (!isset($detected['detected_contexts'][$contextId])) {
                // Calcular relevância potencial
                $relevance = $this->calculateContextRelevance($contextId, $categoryId);

                if ($relevance > 0.3) {
                    $suggestions[] = [
                        'context' => $contextId,
                        'name' => $context['name'],
                        'relevance' => round($relevance, 2),
                        'suggested_keywords' => array_slice(
                            array_keys($context['keywords']),
                            0,
                            3
                        ),
                        'reason' => $this->getSuggestionReason($contextId, $categoryId)
                    ];
                }
            }
        }

        // Ordenar por relevância
        usort($suggestions, fn($a, $b) => $b['relevance'] <=> $a['relevance']);

        return [
            'currently_detected' => $detected['detected_contexts'],
            'suggestions' => $suggestions,
            'primary_suggestion' => !empty($suggestions) ? $suggestions[0]['context'] : null
        ];
    }

    /**
     * Enriquece um anúncio com contextos
     */
    public function enrichWithContexts(
        array $itemData,
        array $contexts,
        ?string $categoryId = null
    ): array {
        $title = $itemData['title'] ?? '';
        $description = $itemData['description'] ?? '';
        $model = $itemData['model'] ?? '';

        // Gerar keywords de contexto
        $contextKeywords = $this->generateContextKeywords($contexts, $categoryId);

        // Gerar frases de contexto
        $contextPhrases = $this->generateContextPhrases($contexts);

        // Enriquecer título (se houver espaço)
        $enrichedTitle = $this->enrichTitle($title, $contextKeywords['keywords']);

        // Enriquecer descrição
        $enrichedDescription = $this->enrichDescription($description, $contextPhrases['phrases']);

        // Enriquecer modelo
        $enrichedModel = $this->enrichModel($model, $contextKeywords['keywords']);

        return [
            'original' => $itemData,
            'enriched' => [
                'title' => $enrichedTitle,
                'description' => $enrichedDescription,
                'model' => $enrichedModel
            ],
            'contexts_applied' => $contexts,
            'keywords_added' => count($contextKeywords['keywords']),
            'phrases_added' => count($contextPhrases['phrases'])
        ];
    }

    /**
     * Salva contextos customizados para categoria
     */
    public function saveContextsForCategory(
        string $categoryId,
        array $contexts
    ): array {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO seo_use_contexts (category_id, context_type, keyword, weight)
            VALUES (:category_id, :context_type, :keyword, :weight)
            ON DUPLICATE KEY UPDATE weight = VALUES(weight)
        ");

        $saved = 0;
        foreach ($contexts as $contextType => $keywords) {
            foreach ($keywords as $keyword => $weight) {
                $stmt->execute([
                    'category_id' => $categoryId,
                    'context_type' => $contextType,
                    'keyword' => $keyword,
                    'weight' => $weight
                ]);
                $saved++;
            }
        }

        return [
            'success' => true,
            'category_id' => $categoryId,
            'saved_keywords' => $saved
        ];
    }

    // ========================================================================
    // MÉTODOS PRIVADOS
    // ========================================================================

    private function calculateContextRelevance(string $contextId, ?string $categoryId): float
    {
        // Mapeamento de categorias para contextos relevantes
        $categoryContextMap = [
            'MLB3530' => ['profissional' => 0.9, 'carga' => 0.8, 'lazer' => 0.6, 'urbano' => 0.7],
            // Adicionar mais categorias conforme necessário
        ];

        if ($categoryId && isset($categoryContextMap[$categoryId][$contextId])) {
            return $categoryContextMap[$categoryId][$contextId];
        }

        // Relevância padrão
        return self::USE_CONTEXTS[$contextId]['weight'] ?? 0.5;
    }

    private function getSuggestionReason(string $contextId, ?string $categoryId): string
    {
        $reasons = [
            'profissional' => 'Alta demanda de buscas por uso profissional/delivery',
            'lazer' => 'Muitos usuários buscam para viagens e passeios',
            'urbano' => 'Uso diário na cidade é muito comum',
            'carga' => 'Capacidade de carga é fator decisivo de compra'
        ];

        return $reasons[$contextId] ?? 'Contexto relevante para esta categoria';
    }

    private function enrichTitle(string $title, array $keywords): array
    {
        $maxLength = 60;
        $enriched = $title;
        $added = [];

        foreach ($keywords as $kw) {
            $keyword = $kw['keyword'];

            // Verificar se keyword já existe
            if (stripos($title, $keyword) !== false) {
                continue;
            }

            $newTitle = $enriched . ' ' . $keyword;
            if (mb_strlen($newTitle) <= $maxLength) {
                $enriched = $newTitle;
                $added[] = $keyword;
            } else {
                break;
            }
        }

        return [
            'original' => $title,
            'enriched' => $enriched,
            'keywords_added' => $added,
            'length' => mb_strlen($enriched)
        ];
    }

    private function enrichDescription(string $description, array $phrases): array
    {
        $enriched = $description;
        $added = [];

        // Adicionar seção de uso se não existir
        if (
            stripos($description, 'uso:') === false &&
            stripos($description, 'ideal para') === false
        ) {

            $useSection = "\n\n✅ **Ideal para:**\n";
            foreach ($phrases as $p) {
                $useSection .= "• " . mb_strtoupper(mb_substr($p['phrase'], 0, 1)) . mb_substr($p['phrase'], 1) . "\n";
                $added[] = $p['phrase'];
            }

            $enriched .= $useSection;
        }

        return [
            'original' => $description,
            'enriched' => $enriched,
            'phrases_added' => $added,
            'length' => mb_strlen($enriched)
        ];
    }

    private function enrichModel(string $model, array $keywords): array
    {
        $maxLength = 255;
        $enriched = $model;
        $added = [];

        // Adicionar keywords de contexto no modelo
        foreach ($keywords as $kw) {
            $keyword = $kw['keyword'];

            if (stripos($model, $keyword) !== false) {
                continue;
            }

            $newModel = $enriched . ' ' . $keyword;
            if (mb_strlen($newModel) <= $maxLength) {
                $enriched = $newModel;
                $added[] = $keyword;

                // Limitar a 3 keywords no modelo
                if (count($added) >= 3) break;
            }
        }

        return [
            'original' => $model,
            'enriched' => $enriched,
            'keywords_added' => $added,
            'length' => mb_strlen($enriched)
        ];
    }
}
