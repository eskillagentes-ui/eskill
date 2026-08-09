<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use PDO;

/**
 * 📊 SEO Score Calculator v2
 * 
 * Calcula o score SEO completo de produtos
 * com fatores ponderados e benchmarks
 * 
 * @author AI Development Team
 * @version 2.0.0
 */
class SEOScoreCalculator
{
    private int $accountId;
    private PDO $db;
    
    // Weight factors (total 100%)
    /**
     * Pesos alinhados com o impacto real no ranking orgânico do ML:
     *
     * - title (25%):       Principal sinal de relevância textual para o motor de busca.
     * - attributes (20%):  Habilita filtros de busca (Category Fit). Atributos obrigatórios
     *                       ausentes excluem o anúncio de qualquer pesquisa filtrada.
     * - images (15%):      Impacto direto na conversão e qualidade de listing.
     * - description (15%): Informativa para o comprador; secundária para indexação.
     * - pricing (10%):     Competitividade de preço é critério para Buy Box e destaque.
     * - keywords (10%):    Cobertura semântica e demanda relativa da categoria.
     * - engagement (5%):   Lagging indicator — reflexo das outras dimensões; não causa raiz.
     */
    const WEIGHTS = [
        'title'       => 25,
        'attributes'  => 20,
        'images'      => 15,
        'description' => 15,
        'pricing'     => 10,
        'keywords'    => 10,
        'engagement'  => 5,
    ];
    
    private ?CompetitorSpy $competitorSpy = null;
    private ?KeywordKiller $keywordKiller = null;
    
    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->db = Database::getInstance();
        $this->ensureTablesExist();
    }
    
    // Lazy load dependencies to avoid overhead
    private function getCompetitorSpy(): CompetitorSpy
    {
        if (!$this->competitorSpy) {
            $this->competitorSpy = new CompetitorSpy($this->accountId);
        }
        return $this->competitorSpy;
    }
    
    private function getKeywordKiller(): KeywordKiller
    {
        if (!$this->keywordKiller) {
            $this->keywordKiller = new KeywordKiller($this->accountId);
        }
        return $this->keywordKiller;
    }
    
    /**
     * 🎯 Calcular score completo de um item
     */
    public function calculateScore(string $itemId, array $itemData = []): array
    {
        $scores = [
            'item_id' => $itemId,
            'overall_score' => 0,
            'breakdown' => [],
            'recommendations' => [],
            'benchmarks' => [],
        ];
        
        try {
            // Get item data if not provided
            if (empty($itemData)) {
                $mlClient = new \App\Services\MercadoLivreClient($this->accountId);
                $itemData = $mlClient->get("/items/{$itemId}");
            }

            if (is_array($itemData) && isset($itemData['error'], $itemData['status']) && (int)$itemData['status'] >= 400) {
                throw new \RuntimeException((string)($itemData['message'] ?? $itemData['error']), (int)$itemData['status']);
            }
            
            // Calculate each component
            $scores['breakdown']['title'] = $this->scoreTitleQuality($itemData);
            $scores['breakdown']['description'] = $this->scoreDescriptionQuality($itemData);
            $scores['breakdown']['images'] = $this->scoreImagesQuality($itemData);
            $scores['breakdown']['attributes'] = $this->scoreAttributesCompleteness($itemData);
            // Use new real scoring methods
            $scores['breakdown']['pricing'] = $this->scorePricingCompetitiveness($itemData);
            $scores['breakdown']['keywords'] = $this->scoreKeywordRelevance($itemData);
            $scores['breakdown']['engagement'] = $this->scoreEngagement($itemData);
            
            // Calculate weighted overall score
            $totalScore = 0;
            foreach ($scores['breakdown'] as $component => $data) {
                $weight = self::WEIGHTS[$component] ?? 0;
                $componentScore = $data['score'] ?? 0;
                $totalScore += ($componentScore * $weight / 100);
            }
            
            $scores['overall_score'] = round($totalScore, 1);
            $scores['grade'] = $this->getGrade($scores['overall_score']);
            
            // Generate recommendations
            $scores['recommendations'] = $this->generateRecommendations($scores['breakdown']);
            
            // Add benchmarks
            $scores['benchmarks'] = $this->getBenchmarks($itemData['category_id'] ?? null);
            
            // Save to database for historical tracking
            $this->saveScoreToDatabase($itemId, $scores);
            
        } catch (\Throwable $e) {
            $scores['error'] = $e->getMessage();
            $scores['status'] = $e->getCode() > 0 ? $e->getCode() : 500;
        }
        
        return $scores;
    }
    
    /**
     * Score leve para dashboard (sem busca publica ML nem fetch de description).
     * Evita storm de 403 PolicyAgent/datacenter no widget Top Performers.
     */
    public function calculateDashboardScore(string|int $itemId, array $itemData): array
    {
        $itemId = (string) $itemId;
        $scores = [
            'item_id' => $itemId,
            'overall_score' => 0,
            'breakdown' => [],
            'recommendations' => [],
            'benchmarks' => [],
            'mode' => 'dashboard_offline',
        ];

        try {
            if (is_array($itemData) && isset($itemData['error'], $itemData['status']) && (int)$itemData['status'] >= 400) {
                throw new \RuntimeException((string)($itemData['message'] ?? $itemData['error']), (int)$itemData['status']);
            }

            $scores['breakdown']['title'] = $this->scoreTitleQuality($itemData);
            $scores['breakdown']['description'] = [
                'score' => 50,
                'length' => 0,
                'issues' => ['Descrição não avaliada no dashboard (modo offline)'],
            ];
            $scores['breakdown']['images'] = $this->scoreImagesQuality($itemData);
            $scores['breakdown']['attributes'] = $this->scoreAttributesCompleteness($itemData);
            $scores['breakdown']['pricing'] = [
                'score' => 50,
                'price' => $itemData['price'] ?? 0,
                'status' => 'offline',
                'issues' => [],
            ];
            $scores['breakdown']['keywords'] = [
                'score' => 50,
                'issues' => [],
            ];
            $scores['breakdown']['engagement'] = $this->scoreEngagement($itemData);

            $totalScore = 0;
            foreach ($scores['breakdown'] as $component => $data) {
                $weight = self::WEIGHTS[$component] ?? 0;
                $componentScore = $data['score'] ?? 0;
                $totalScore += ($componentScore * $weight / 100);
            }

            $scores['overall_score'] = round($totalScore, 1);
            $scores['grade'] = $this->getGrade($scores['overall_score']);
            $scores['recommendations'] = $this->generateRecommendations($scores['breakdown']);
        } catch (\Throwable $e) {
            $scores['error'] = $e->getMessage();
            $scores['status'] = $e->getCode() > 0 ? $e->getCode() : 500;
        }

        return $scores;
    }

/**
     * 📝 Score de qualidade do título
     */
    private function scoreTitleQuality(array $item): array
    {
        $score = 100;
        $issues = [];
        $title = $item['title'] ?? '';
        $titleLen = mb_strlen($title);

        // ML recomenda títulos entre 45-80 chars. Abaixo de 30 é insuficiente; acima de
        // 80 pode ser truncado na exibição mobile e atrapalha a leitura.
        if ($titleLen < 30) {
            $score -= 30;
            $issues[] = 'Título muito curto (ideal: 45-80 caracteres)';
        } elseif ($titleLen < 45) {
            $score -= 10;
            $issues[] = 'Título curto — considere incluir mais especificações (ideal: 45-80 caracteres)';
        } elseif ($titleLen > 80) {
            $score -= 10;
            $issues[] = 'Título muito longo — pode ser cortado em mobile (máximo recomendado: 80 caracteres)';
        }

        // Símbolos proibidos / desaconselhados pelo ML: %, #, !, $, @, &, *, (, )
        // Esses caracteres confundem o motor de busca e podem desqualificar o anúncio.
        if (preg_match('/[%#!$@&*()\[\]{}|\\\\<>]/', $title)) {
            $score -= 15;
            $issues[] = 'Remova símbolos especiais do título (%, #, !, $, etc.)';
        }

        // Caps lock abusivo: mais de 4 caracteres maiúsculos consecutivos fora de siglas
        // conhecidas (ex: ORIGINAL, NOVO) prejudica a leitura e pode sinalizar spam.
        if (preg_match('/[A-Z]{5,}/', $title)) {
            $score -= 10;
            $issues[] = 'Evite CAPS LOCK excessivo — use caixa normal para melhor indexação';
        }

        // Title Architecture check: marca deve aparecer no título (Product Identity)
        $brand = '';
        foreach ($item['attributes'] ?? [] as $attr) {
            if ($attr['id'] === 'BRAND') {
                $brand = (string) ($attr['value_name'] ?? '');
                break;
            }
        }

        if ($brand && stripos($title, $brand) === false) {
            $score -= 15;
            $issues[] = "Marca ({$brand}) não aparece no título — essencial para Product Identity";
        }

        // Model check: modelo é um campo de alta relevância semântica para peças/acessórios.
        // Registrar no título facilita o match com queries de cauda longa (ex.: "CG 160 Titan").
        $model = '';
        foreach ($item['attributes'] ?? [] as $attr) {
            if ($attr['id'] === 'MODEL') {
                $model = (string) ($attr['value_name'] ?? '');
                break;
            }
        }

        if ($model && stripos($title, $model) === false) {
            $score -= 10;
            $issues[] = "Modelo ({$model}) não aparece no título — impacta buscas específicas";
        }

        // Keyword stuffing: detecta repetição de palavras com 4+ caracteres mais de 2 vezes.
        // A regex antiga (`(\b\w+\b).*\1`) era muito ampla e flagueava repetições legítimas
        // como "CG 160 Motor 160" onde "160" é parte natural do nome do produto.
        // Agora exige que a mesma palavra de 4+ chars apareça 3+ vezes.
        $words = preg_split('/\s+/', mb_strtolower($title));
        $wordFreq = array_count_values(
            array_filter($words, fn($w) => mb_strlen($w) >= 4)
        );
        $stuffed = array_filter($wordFreq, fn($c) => $c >= 3);
        if (!empty($stuffed)) {
            $score -= 15;
            $repeated = implode(', ', array_keys($stuffed));
            $issues[] = "Repetição excessiva de palavras: '{$repeated}' — pode ser penalizado pelo ML";
        }

        return [
            'score'  => max(0, $score),
            'title'  => $title,
            'length' => $titleLen,
            'issues' => $issues,
        ];
    }
    
    /**
     * 📄 Score de qualidade da descrição (Content Accuracy)
     *
     * A descrição no ML deve ser útil ao comprador, não um repositório de palavras-chave.
     * Critérios alinhados com as diretrizes ML:
     *  - Extensão suficiente para responder dúvidas do comprador (ideal: 300-2000 chars)
     *  - Conteúdo estruturado (parágrafos, listas, compatibilidade)
     *  - Informações funcionais (para que serve, como instalar, compatibilidade)
     *
     * REMOVIDO INTENCIONALMENTE: "keyword_density" (proporção de palavras do título
     * presentes na descrição). Esse critério incentivava keyword stuffing — inserir
     * as mesmas palavras do título na descrição repetidamente, o que o ML penaliza.
     * A descrição deve ser escrita para o comprador, não para o indexador.
     */
    private function scoreDescriptionQuality(array $item): array
    {
        $score = 100;
        $issues = [];
        $description = '';

        try {
            $itemId = $item['id'] ?? '';
            // Preferir description já carregada no item (ex.: via getItemDetails)
            $description = (string) ($item['description'] ?? '');
            if ($description === '' && $itemId) {
                $mlClient = new \App\Services\MercadoLivreClient($this->accountId);
                $descData  = $mlClient->get("/items/{$itemId}/description");
                $description = $descData['plain_text'] ?? $descData['text'] ?? '';
            }
        } catch (\Exception $e) {
            return [
                'score'         => 50,
                'length'        => 0,
                'has_structure' => false,
                'issues'        => ['Não foi possível analisar a descrição'],
            ];
        }

        $descLen = mb_strlen($description);

        // Extensão: descrição curta não responde às dúvidas do comprador
        if ($descLen === 0) {
            $score -= 50;
            $issues[] = 'Descrição ausente — adicione ao menos 300 caracteres explicando o produto';
        } elseif ($descLen < 150) {
            $score -= 35;
            $issues[] = 'Descrição muito curta — expanda para ao menos 300 caracteres';
        } elseif ($descLen < 300) {
            $score -= 15;
            $issues[] = 'Descrição poderia ser mais detalhada (ideal: 300-2000 caracteres)';
        }

        // Extensão excessiva: muito texto sem estrutura pode confundir o comprador
        if ($descLen > 3000) {
            $score -= 5;
            $issues[] = 'Descrição muito extensa — considere organizar em seções menores';
        }

        // Conteúdo estruturado: parágrafos, listas ou tabelas aumentam legibilidade
        $paragraphs  = substr_count($description, "\n\n");
        $lineBreaks  = substr_count($description, "\n");
        $bulletItems = substr_count($description, '•') + substr_count($description, '- ') + substr_count($description, '* ');
        $hasStructure = $paragraphs >= 2 || ($lineBreaks >= 3) || ($bulletItems >= 2);

        if (!$hasStructure && $descLen > 300) {
            $score -= 10;
            $issues[] = 'Use parágrafos ou listas para melhorar a legibilidade';
        }

        // Indicadores de conteúdo útil: menção a compatibilidade, garantia, instalação
        // Esses termos indicam descrição informativa, não apenas descritiva.
        $utilityKeywords = [
            'compatível', 'compatibilidade', 'modelo', 'ano', 'instalação', 'instalar',
            'original', 'garantia', 'montagem', 'par', 'dianteiro', 'traseiro',
            'serve para', 'indicado para', 'fabricante',
        ];
        $utilityMatches = 0;
        $lowerDesc = mb_strtolower($description);
        foreach ($utilityKeywords as $kw) {
            if (str_contains($lowerDesc, $kw)) {
                $utilityMatches++;
            }
        }
        if ($descLen >= 150 && $utilityMatches < 2) {
            $score -= 10;
            $issues[] = 'Inclua informações de compatibilidade, instalação ou garantia na descrição';
        }

        return [
            'score'         => max(0, $score),
            'length'        => $descLen,
            'has_structure' => $hasStructure,
            'utility_score' => $utilityMatches,
            'issues'        => $issues,
        ];
    }
    
    /**
     * 🖼️ Score de qualidade das imagens (Image Quality)
     *
     * O ML recomenda:
     *  - Mínimo de 5 imagens para maximizar conversão
     *  - Resolução mínima de 900x900 px (ideal 1200x1200)
     *  - Fundo branco na foto principal
     *  - Imagens sem marca d'água ou texto sobreposto
     *
     * O campo `size` ou `max_size` da API ML retorna "LARGURAxALTURA" (ex.: "1200x803").
     * URLs com "-O." no CDN mlstatic.com indicam imagem em resolução original.
     */
    private function scoreImagesQuality(array $item): array
    {
        $score = 100;
        $issues = [];
        $pictures = $item['pictures'] ?? [];
        $pictureCount = count($pictures);

        if ($pictureCount === 0) {
            return [
                'score'     => 0,
                'count'     => 0,
                'high_res'  => 0,
                'issues'    => ['CRÍTICO: Produto sem imagens'],
            ];
        }

        if ($pictureCount < 3) {
            $score -= 35;
            $issues[] = 'Adicione mais imagens — mínimo 3 para aparecer em destaque';
        } elseif ($pictureCount < 5) {
            $score -= 15;
            $issues[] = 'Ideal ter 5+ imagens para aumentar conversão';
        }

        // Verificar resolução de cada imagem
        $highRes = 0;
        $lowRes  = 0;

        foreach ($pictures as $pic) {
            // Prefere max_size (tamanho máximo disponível), cai em size
            $sizeStr = (string) ($pic['max_size'] ?? $pic['size'] ?? '');
            $url     = (string) ($pic['url'] ?? $pic['secure_url'] ?? '');

            $isHighRes = false;
            if ($sizeStr !== '' && preg_match('/^(\d+)[xX](\d+)$/', $sizeStr, $m)) {
                $minDim = min((int) $m[1], (int) $m[2]);
                // Resolução mínima recomendada pelo ML: 900px na menor dimensão
                $isHighRes = $minDim >= 900;
            } elseif (str_contains($url, '-O.') || str_contains($url, '-F.')) {
                // Sufixo -O. = original (alta res), -F. = full
                $isHighRes = true;
            }

            if ($isHighRes) {
                $highRes++;
            } else {
                $lowRes++;
            }
        }

        // Penalidade por baixa resolução: impacta diretamente a conversão e confiança
        if ($highRes === 0 && $pictureCount > 0) {
            $score -= 25;
            $issues[] = 'Imagens em baixa resolução — use imagens com mínimo 900x900 px';
        } elseif ($lowRes > 0) {
            $penalty = min(15, $lowRes * 5);
            $score -= $penalty;
            $issues[] = "{$lowRes} imagem(ns) em resolução insuficiente (< 900px) — substitua por versões maiores";
        }

        return [
            'score'    => max(0, $score),
            'count'    => $pictureCount,
            'high_res' => $highRes,
            'low_res'  => $lowRes,
            'issues'   => $issues,
        ];
    }
    
    /**
     * 🔧 Score de completude dos atributos (Attribute Completeness + Attribute Gap)
     *
     * Usa o schema real da categoria ML para distinguir:
     *  - required: ausência impede indexação em filtros críticos → penalidade alta
     *  - recommended: ausência reduz relevância e visibilidade → penalidade média
     *
     * O score puro por contagem (< 5 → -50%) era enganoso: um item com 12 atributos
     * genéricos mas sem BRAND/MODEL/VEHICLE_YEAR teria score alto e não apareceria
     * nos filtros mais importantes da categoria.
     */
    private function scoreAttributesCompleteness(array $item): array
    {
        $score = 100;
        $issues = [];

        $filledAttrs = [];
        foreach ($item['attributes'] ?? [] as $attr) {
            $filledAttrs[$attr['id']] = $attr['value_name'] ?? $attr['value_id'] ?? null;
        }
        $filledCount = count(array_filter($filledAttrs));

        // Tentar obter schema da categoria da API ML para checar required/recommended
        $categoryId = (string) ($item['category_id'] ?? '');
        $requiredMissing = [];
        $recommendedMissing = [];
        $filterEnablingMissing = [];

        if ($categoryId) {
            try {
                $mlClient = new \App\Services\MercadoLivreClient($this->accountId);
                $categoryAttrs = $mlClient->getCategoryAttributes($categoryId);

                foreach ($categoryAttrs as $attrDef) {
                    $attrId = $attrDef['id'] ?? '';
                    if (!$attrId) {
                        continue;
                    }

                    $isFilled = isset($filledAttrs[$attrId]) && $filledAttrs[$attrId] !== null;

                    // Tags relevantes do schema ML
                    $tags = $attrDef['tags'] ?? [];
                    $isRequired    = in_array('required', $tags, true);
                    $isRecommended = in_array('recommended', $tags, true);

                    // Atributos que habilitam filtros de busca têm tag "filterable" no schema
                    $isFilterable = in_array('filterable', $tags, true)
                        || in_array('catalog_required', $tags, true);

                    if (!$isFilled) {
                        $label = $attrDef['name'] ?? $attrId;
                        if ($isRequired) {
                            $requiredMissing[] = $label;
                        } elseif ($isFilterable) {
                            $filterEnablingMissing[] = $label;
                        } elseif ($isRecommended) {
                            $recommendedMissing[] = $label;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Falhou ao buscar schema: cai no fallback por contagem simples abaixo
                $categoryAttrs = [];
            }
        }

        // Penalidade por atributos obrigatórios ausentes (impede indexação nos filtros)
        $reqCount = count($requiredMissing);
        if ($reqCount > 0) {
            $penalty = min(50, $reqCount * 15);
            $score -= $penalty;
            $issues[] = "Atributos obrigatórios não preenchidos: " . implode(', ', array_slice($requiredMissing, 0, 4))
                . ($reqCount > 4 ? " (e mais " . ($reqCount - 4) . ")" : '');
        }

        // Penalidade por atributos filter-enabling ausentes (limita aparição em filtros)
        $filterCount = count($filterEnablingMissing);
        if ($filterCount > 0) {
            $penalty = min(25, $filterCount * 8);
            $score -= $penalty;
            $issues[] = "Atributos de filtro não preenchidos: " . implode(', ', array_slice($filterEnablingMissing, 0, 3))
                . ($filterCount > 3 ? " (e mais " . ($filterCount - 3) . ")" : '');
        }

        // Penalidade por atributos recommended ausentes (reduz relevância)
        $recCount = count($recommendedMissing);
        if ($recCount > 0) {
            $penalty = min(15, $recCount * 4);
            $score -= $penalty;
            if ($recCount <= 3) {
                $issues[] = "Atributos recomendados não preenchidos: " . implode(', ', $recommendedMissing);
            } else {
                $issues[] = "{$recCount} atributos recomendados não preenchidos — considere completar a ficha técnica";
            }
        }

        // Fallback: quando não foi possível obter o schema, usa contagem simples
        // como sinal de qualidade mínima (não há como saber quais são required).
        if ($categoryId === '' || ($reqCount === 0 && $filterCount === 0 && $recCount === 0)) {
            if ($filledCount < 5) {
                $score -= 40;
                $issues[] = 'Muito poucos atributos preenchidos (ideal: 10+ para aumentar visibilidade)';
            } elseif ($filledCount < 10) {
                $score -= 15;
                $issues[] = 'Preencha mais atributos para melhorar a completude da ficha técnica';
            }
        }

        return [
            'score'             => max(0, $score),
            'filled'            => $filledCount,
            'required_missing'  => $requiredMissing,
            'filter_missing'    => $filterEnablingMissing,
            'recommended_missing' => $recommendedMissing,
            'issues'            => $issues,
        ];
    }
    
    /**
     * 💰 Score de competitividade do preço (Real Analysis)
     */
    private function scorePricingCompetitiveness(array $item): array
    {
        return $this->getCompetitorSpy()->analyzePriceCompetitiveness($item);
    }
    
    /**
     * 🔍 Score de relevância de keywords (Real Analysis)
     */
    private function scoreKeywordRelevance(array $item): array
    {
        return $this->getKeywordKiller()->analyzeKeywordUsage($item);
    }
    
    /**
     * 📈 Score de engajamento (Conversion History + Sales History)
     *
     * Combina histórico de vendas e taxa de conversão (visitas→vendas).
     * Um item com poucas vendas mas boa conversão indica produto correto com
     * baixo tráfego — o problema é de visibilidade, não de listing quality.
     * Um item com muito tráfego e baixa conversão indica problema no listing.
     *
     * Anúncios novos (< 10 visitas) recebem score neutro (50) para não
     * penalizar produtos sem histórico suficiente.
     */
    private function scoreEngagement(array $item): array
    {
        $score = 50; // neutro como base — não penalizar produtos sem histórico
        $issues = [];

        $soldQuantity = (int) ($item['sold_quantity'] ?? 0);
        $visits       = (int) ($item['visits'] ?? $item['visits_30d'] ?? 0);

        // Score de vendas: histórico acumulado do anúncio
        if ($soldQuantity >= 100) {
            $score = 90;
        } elseif ($soldQuantity >= 50) {
            $score = 80;
        } elseif ($soldQuantity >= 20) {
            $score = 70;
        } elseif ($soldQuantity >= 5) {
            $score = 60;
        } elseif ($soldQuantity === 0 && $visits >= 20) {
            // Produto com tráfego mas zero vendas: problema de conversão
            $score = 35;
            $issues[] = 'Anúncio recebe visitas mas não converte — revise preço, fotos e título';
        }

        // Bonus/penalidade por taxa de conversão (30 dias, quando disponível)
        if ($visits >= 10) {
            $conversionRate = $soldQuantity > 0 ? ($soldQuantity / $visits) : 0;

            // Benchmark ML para auto peças: ~2-5% de conversão é normal
            if ($conversionRate >= 0.05) {
                $score = min(100, $score + 10); // Conversão excelente
            } elseif ($conversionRate >= 0.02) {
                // Faixa normal — sem ajuste
            } elseif ($conversionRate > 0 && $conversionRate < 0.01) {
                $score = max(30, $score - 10);
                $rate = round($conversionRate * 100, 1);
                $issues[] = "Taxa de conversão baixa ({$rate}%) — revise preço, imagens e título";
            }
        }

        return [
            'score'           => $score,
            'sold_quantity'   => $soldQuantity,
            'visits_30d'      => $visits,
            'conversion_rate' => $visits >= 10
                ? round(($soldQuantity / max(1, $visits)) * 100, 1)
                : null,
            'issues'          => $issues,
        ];
    }
    
    /**
     * 🏆 Get grade from score
     */
    private function getGrade(float $score): string
    {
        if ($score >= 90) return 'A+';
        if ($score >= 80) return 'A';
        if ($score >= 70) return 'B';
        if ($score >= 60) return 'C';
        if ($score >= 50) return 'D';
        return 'F';
    }
    
    /**
     * 💡 Gera recomendações priorizadas por impacto real no score
     *
     * Ordena por: weight × (100 - componentScore)
     * Isso garante que melhorar um componente de alto peso e baixo score
     * (ex.: título com score 40 e peso 25%) aparece antes de melhorar
     * imagens com score 80 e peso 15% — mesmo que ambos tenham issues.
     */
    private function generateRecommendations(array $breakdown): array
    {
        $recommendations = [];

        foreach ($breakdown as $component => $data) {
            if (empty($data['issues'])) {
                continue;
            }
            $componentScore = (float) ($data['score'] ?? 100);
            $weight         = self::WEIGHTS[$component] ?? 0;
            $impact         = $weight * (100 - $componentScore) / 100;

            foreach ($data['issues'] as $issue) {
                $recommendations[] = [
                    'component' => $component,
                    'priority'  => $componentScore < 50 ? 'high' : 'medium',
                    'issue'     => $issue,
                    '_impact'   => $impact, // usado apenas para ordenação
                ];
            }
        }

        // Ordena pelo impacto potencial no score final (decrescente)
        usort($recommendations, fn($a, $b) => $b['_impact'] <=> $a['_impact']);

        // Remove campo interno de ordenação antes de retornar
        return array_slice(
            array_map(fn($r) => array_diff_key($r, ['_impact' => true]), $recommendations),
            0,
            5
        );
    }
    
    /**
     * 📊 Get benchmarks
     */
    public function getBenchmarks(?string $categoryId): array
    {
        if (!$categoryId) {
            return [
                'category_average' => null,
                'top_10_percent' => null,
                'your_rank' => 'N/A',
            ];
        }
        
        try {
            // Check if we have cached benchmarks
            $stmt = $this->db->prepare("
                SELECT average_score, top_10_percent_score, sample_size, last_updated
                FROM seo_category_benchmarks
                WHERE account_id = ? AND category_id = ?
                AND last_updated > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute([$this->accountId, $categoryId]);
            $cached = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($cached) {
                return [
                    'category_average' => (float)$cached['average_score'],
                    'top_10_percent' => (float)$cached['top_10_percent_score'],
                    'sample_size' => (int)$cached['sample_size'],
                    'last_updated' => $cached['last_updated'],
                    'your_rank' => 'calculating...',
                ];
            }
            
            // Calculate fresh benchmarks from recent scores
            $stmt = $this->db->prepare("
                SELECT 
                    AVG(overall_score) as avg_score,
                    COUNT(*) as sample_size
                FROM seo_scores_history h
                INNER JOIN (
                    SELECT item_id, MAX(created_at) as latest
                    FROM seo_scores_history
                    WHERE account_id = ?
                    GROUP BY item_id
                ) latest ON h.item_id = latest.item_id AND h.created_at = latest.latest
                WHERE h.account_id = ?
            ");
            $stmt->execute([$this->accountId, $this->accountId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            $sampleSize = (int)($stats['sample_size'] ?? 0);
            $top10Offset = max(0, floor($sampleSize * 0.1) - 1);
            $offsetSql = max(0, min(100000, (int)$top10Offset));
            
            // Get top 10% threshold
            $stmt = $this->db->prepare("
                SELECT overall_score
                FROM seo_scores_history h
                INNER JOIN (
                    SELECT item_id, MAX(created_at) as latest
                    FROM seo_scores_history
                    WHERE account_id = ?
                    GROUP BY item_id
                ) latest ON h.item_id = latest.item_id AND h.created_at = latest.latest
                WHERE h.account_id = ?
                ORDER BY overall_score DESC
                LIMIT 1 OFFSET {$offsetSql}
            ");
            $stmt->execute([$this->accountId, $this->accountId]);
            $top10 = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $avgScore = (float)($stats['avg_score'] ?? 72.5);
            $top10Score = (float)($top10['overall_score'] ?? 88.0);
            
            // Cache the results
            $stmt = $this->db->prepare("
                INSERT INTO seo_category_benchmarks 
                (account_id, category_id, average_score, top_10_percent_score, sample_size, last_updated)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    average_score = VALUES(average_score),
                    top_10_percent_score = VALUES(top_10_percent_score),
                    sample_size = VALUES(sample_size),
                    last_updated = NOW()
            ");
            $stmt->execute([$this->accountId, $categoryId, $avgScore, $top10Score, $sampleSize]);
            
            return [
                'category_average' => $avgScore,
                'top_10_percent' => $top10Score,
                'sample_size' => $sampleSize,
                'last_updated' => date('Y-m-d H:i:s'),
                'your_rank' => 'calculating...',
            ];
            
        } catch (\Exception $e) {
            log_warning('Erro no cálculo de benchmark SEO', [
                'service' => 'SEOScoreCalculator',
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            return [
                'category_average' => 72.5,
                'top_10_percent' => 88.0,
                'your_rank' => 'N/A',
            ];
        }
    }
    
    /**
     * 🗄️ Ensure database tables exist
     */
    private function ensureTablesExist(): void
    {
        try {
            // Score history table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS seo_scores_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    item_id VARCHAR(50) NOT NULL,
                    overall_score DECIMAL(5,2) NOT NULL,
                    breakdown_json TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_item_date (item_id, created_at),
                    INDEX idx_account (account_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Category benchmarks table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS seo_category_benchmarks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    category_id VARCHAR(50) NOT NULL,
                    average_score DECIMAL(5,2),
                    top_10_percent_score DECIMAL(5,2),
                    sample_size INT,
                    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_category (account_id, category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Score alerts table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS seo_score_alerts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    item_id VARCHAR(50) NOT NULL,
                    alert_type VARCHAR(50),
                    message TEXT,
                    severity ENUM('low', 'medium', 'high'),
                    is_read BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_account_unread (account_id, is_read)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Exception $e) {
            log_error('Erro ao criar tabelas de score SEO', [
                'service' => 'SEOScoreCalculator',
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 💾 Save score to database for historical tracking
     */
    private function saveScoreToDatabase(string $itemId, array $scores): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO seo_scores_history 
                (account_id, item_id, overall_score, breakdown_json, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->accountId,
                $itemId,
                $scores['overall_score'],
                json_encode($scores['breakdown'])
            ]);
            
            // Check for score degradation
            $alert = $this->checkForDegradation($itemId, $scores['overall_score']);
            if ($alert) {
                $this->saveAlert($itemId, $alert);
            }
        } catch (\Exception $e) {
            log_warning('Erro ao salvar histórico de score SEO', [
                'service' => 'SEOScoreCalculator',
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 📊 Get historical scores for an item
     */
    public function getHistoricalScores(string $itemId, int $days = 30): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT overall_score, breakdown_json, created_at
                FROM seo_scores_history
                WHERE account_id = ? AND item_id = ?
                AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY created_at ASC
            ");
            $stmt->execute([$this->accountId, $itemId, $days]);
            
            $history = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $history[] = [
                    'score' => (float)$row['overall_score'],
                    'breakdown' => json_decode($row['breakdown_json'], true),
                    'date' => $row['created_at']
                ];
            }
            
            return [
                'success' => true,
                'item_id' => $itemId,
                'period_days' => $days,
                'history' => $history,
                'trend' => $this->calculateTrend($history)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 📈 Calculate score trend
     */
    private function calculateTrend(array $history): array
    {
        if (count($history) < 2) {
            return [
                'direction' => 'stable',
                'change' => 0,
                'change_percent' => 0
            ];
        }
        
        $firstScore = $history[0]['score'];
        $lastScore = $history[count($history) - 1]['score'];
        $change = $lastScore - $firstScore;
        $changePercent = $firstScore > 0 ? ($change / $firstScore) * 100 : 0;
        
        $direction = 'stable';
        if ($change > 2) $direction = 'improving';
        elseif ($change < -2) $direction = 'declining';
        
        return [
            'direction' => $direction,
            'change' => round($change, 2),
            'change_percent' => round($changePercent, 2),
            'first_score' => $firstScore,
            'last_score' => $lastScore
        ];
    }
    
    /**
     * ⚠️ Check for score degradation
     */
    private function checkForDegradation(string $itemId, float $currentScore): ?array
    {
        try {
            // Get last score
            $stmt = $this->db->prepare("
                SELECT overall_score
                FROM seo_scores_history
                WHERE account_id = ? AND item_id = ?
                ORDER BY created_at DESC
                LIMIT 1 OFFSET 1
            ");
            $stmt->execute([$this->accountId, $itemId]);
            $last = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$last) {
                return null; // First score, no comparison
            }
            
            $lastScore = (float)$last['overall_score'];
            $drop = $lastScore - $currentScore;
            
            // Alert if dropped more than 10 points
            if ($drop > 10) {
                return [
                    'type' => 'score_degradation',
                    'message' => "Score caiu de {$lastScore} para {$currentScore} (-{$drop} pontos)",
                    'severity' => $drop > 20 ? 'high' : 'medium'
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            log_warning('Erro ao verificar degradação de score', [
                'service' => 'SEOScoreCalculator',
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * 🚨 Save alert to database
     */
    private function saveAlert(string $itemId, array $alert): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO seo_score_alerts 
                (account_id, item_id, alert_type, message, severity, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->accountId,
                $itemId,
                $alert['type'],
                $alert['message'],
                $alert['severity']
            ]);
        } catch (\Exception $e) {
            log_warning('Erro ao salvar alerta de score SEO', [
                'service' => 'SEOScoreCalculator',
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 🔔 Get unread alerts
     */
    public function getUnreadAlerts(int $limit = 10): array
    {
        try {
            $limitSql = max(1, min(200, (int)$limit));
            $stmt = $this->db->prepare("
                SELECT id, item_id, alert_type, message, severity, created_at
                FROM seo_score_alerts
                WHERE account_id = ? AND is_read = FALSE
                ORDER BY created_at DESC
                LIMIT {$limitSql}
            ");
            $stmt->execute([$this->accountId]);
            
            return [
                'success' => true,
                'alerts' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 🆚 Compare item score with category average
     */
    public function compareWithCategoryAverage(string $itemId, string $categoryId): array
    {
        try {
            // Get current item score
            $itemScore = $this->calculateScore($itemId);
            
            // Get category benchmarks
            $benchmarks = $this->getBenchmarks($categoryId);
            
            $currentScore = $itemScore['overall_score'];
            $categoryAvg = $benchmarks['category_average'];
            $top10 = $benchmarks['top_10_percent'];
            
            $vsAverage = $currentScore - $categoryAvg;
            $vsTop10 = $currentScore - $top10;
            
            return [
                'success' => true,
                'your_score' => $currentScore,
                'category_average' => $categoryAvg,
                'top_10_percent' => $top10,
                'vs_average' => round($vsAverage, 2),
                'vs_top_10' => round($vsTop10, 2),
                'rank_estimate' => $this->estimateRank($currentScore, $categoryAvg, $top10)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 🏅 Estimate rank based on score
     */
    private function estimateRank(float $score, float $avg, float $top10): string
    {
        if ($score >= $top10) return 'Top 10%';
        if ($score >= $avg + 5) return 'Above Average';
        if ($score >= $avg - 5) return 'Average';
        return 'Below Average';
    }
}
