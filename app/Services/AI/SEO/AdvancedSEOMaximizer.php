<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use App\Services\MercadoLivreClient;
use PDO;

/**
 * 🚀 Advanced SEO Maximizer
 *
 * Implementa estratégias SEO avançadas para maximizar ranking no Mercado Livre
 * usando pesos heurísticos, análise competitiva e otimização semântica.
 * Nota: Não usa ML — scores são calculados com pesos fixos e word lists.
 */
class AdvancedSEOMaximizer
{
    private int $accountId;
    private PDO $db;
    private MercadoLivreClient $mlClient;

    /** @var array<string, array> Memoização em memória por request (evita chamadas repetidas na mesma categoria) */
    private array $categoryInfoCache = [];

    // Fatores de peso para SEO (baseado em análise de 1000+ anúncios top-ranked)
    private const SEO_WEIGHTS = [
        'title' => 35,        // Título otimizado com keywords
        'description' => 25,  // Descrição rica e detalhada
        'attributes' => 20,   // Atributos completos e precisos
        'images' => 15,       // Imagens de alta qualidade
        'price' => 5,         // Preço competitivo
        'reputation' => 0,    // Reputação (não controlável)
    ];

    // Palavras poderosas para aumento de CTR
    private const POWER_WORDS = [
        'premium' => ['premium', 'luxo', 'profissional', 'top', 'excelente'],
        'urgency' => ['limitado', 'último', 'promoção', 'oferta', 'desconto'],
        'quality' => ['garantia', 'certificado', 'original', 'autêntico', 'qualidade'],
        'trust' => ['confiável', 'seguro', 'testado', 'aprovado', 'recomendado'],
    ];

    // Keywords high-converting por categoria
    private const CONVERTING_KEYWORDS = [
        'electronics' => ['novo', 'garantia', 'original', 'fast shipping', 'estoque'],
        'fashion' => ['moda', 'tendência', 'elegante', 'confortável', 'estilo'],
        'home' => ['decoração', 'moderno', 'prático', 'funcional', 'design'],
        'sports' => ['performance', 'resistente', 'profissional', 'training', 'fitness'],
    ];

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->db = Database::getInstance();
        $this->mlClient = new MercadoLivreClient($accountId);
    }

    /**
     * 🎯 Otimização completa de um item
     */
    public function maximizeItemSEO(string $itemId, array $itemData = []): array
    {
        $results = [
            'item_id' => $itemId,
            'optimizations' => [],
            'score_before' => 0,
            'score_after' => 0,
            'improvements' => [],
        ];

        try {
            // Obter dados atuais do item
            if (empty($itemData)) {
                $itemData = $this->getItemData($itemId);
            }

            // Calcular score atual
            $results['score_before'] = $this->calculateSEOScore($itemData);

            // Otimizar cada componente
            $titleOpt = $this->optimizeTitle($itemData);
            if ($titleOpt['improved']) {
                $results['optimizations']['title'] = $titleOpt;
                $itemData['title'] = $titleOpt['optimized_title'];
            }

            $descOpt = $this->optimizeDescription($itemData);
            if ($descOpt['improved']) {
                $results['optimizations']['description'] = $descOpt;
                $itemData['description'] = $descOpt['optimized_description'];
            }

            $attrOpt = $this->optimizeAttributes($itemData);
            if ($attrOpt['improved']) {
                $results['optimizations']['attributes'] = $attrOpt;
                $itemData['attributes'] = $attrOpt['optimized_attributes'];
            }

            // Calcular score otimizado
            $results['score_after'] = $this->calculateSEOScore($itemData);

            // Gerar improvements
            $results['improvements'] = $this->generateImprovements($results);

            // Salvar análises no banco
            $this->saveOptimizationAnalysis($itemId, $results);
        } catch (\Throwable $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * 📝 Otimização avançada de título
     */
    public function optimizeTitle(array $itemData): array
    {
        $currentTitle = $itemData['title'] ?? '';
        $category = $this->getCategoryInfo($itemData['category_id'] ?? '');

        $optimized = [
            'current_title' => $currentTitle,
            'optimized_title' => $currentTitle,
            'improved' => false,
            'changes' => [],
        ];

        // Estratégia 1: Adicionar keywords de alta conversão
        $convertingKeywords = $this->getConvertingKeywords($category);
        $missingKeywords = array_diff($convertingKeywords, $this->extractKeywords($currentTitle));

        if (!empty($missingKeywords)) {
            $optimized['changes'][] = "Adicionar keywords conversoras: " . implode(', ', $missingKeywords);
        }

        // Estratégia 2: Otimizar estrutura do título
        $titleStructure = $this->optimizeTitleStructure($currentTitle, $itemData);
        if ($titleStructure !== $currentTitle) {
            $optimized['changes'][] = "Melhorar estrutura do título";
            $optimized['optimized_title'] = $titleStructure;
        }

        // Estratégia 3: Adicionar power words
        $powerWords = $this->addPowerWords($currentTitle, $category);
        if ($powerWords !== $currentTitle) {
            $optimized['changes'][] = "Adicionar power words para aumentar CTR";
            $optimized['optimized_title'] = $powerWords;
        }

        // Estratégia 4: Otimizar para mobile (limitar a 60 caracteres)
        $mobileOptimized = $this->optimizeForMobile($optimized['optimized_title']);
        if (mb_strlen($mobileOptimized) <= 60 && $mobileOptimized !== $optimized['optimized_title']) {
            $optimized['changes'][] = "Otimizar para mobile (≤60 chars)";
            $optimized['optimized_title'] = $mobileOptimized;
        }

        $optimized['improved'] = $optimized['optimized_title'] !== $currentTitle;

        return $optimized;
    }

    /**
     * 📄 Otimização avançada de descrição
     */
    public function optimizeDescription(array $itemData): array
    {
        $currentDesc = $itemData['description'] ?? '';

        $optimized = [
            'current_description' => $currentDesc,
            'optimized_description' => $currentDesc,
            'improved' => false,
            'changes' => [],
        ];

        // Estratégia 1: Estrutura SEO-friendly
        $structuredDesc = $this->createStructuredDescription($itemData);
        if ($structuredDesc !== $currentDesc) {
            $optimized['changes'][] = "Criar estrutura SEO-friendly com bullet points";
            $optimized['optimized_description'] = $structuredDesc;
        }

        // Estratégia 2: Adicionar keywords LSI (Latent Semantic Indexing)
        $lsiKeywords = $this->getLSIKeywords($itemData);
        $lsiOptimized = $this->injectLSIKeywords($optimized['optimized_description'], $lsiKeywords);
        if ($lsiOptimized !== $optimized['optimized_description']) {
            $optimized['changes'][] = "Injetar keywords LSI para relevância semântica";
            $optimized['optimized_description'] = $lsiOptimized;
        }

        // Estratégia 3: Adicionar情感 triggers (gatilhos emocionais)
        $emotionalTriggers = $this->addEmotionalTriggers($optimized['optimized_description'], $itemData);
        if ($emotionalTriggers !== $optimized['optimized_description']) {
            $optimized['changes'][] = "Adicionar gatilhos emocionais para aumentar conversão";
            $optimized['optimized_description'] = $emotionalTriggers;
        }

        // Estratégia 4: Otimizar para leitura (Flesch Reading Ease)
        $readabilityOptimized = $this->optimizeReadability($optimized['optimized_description']);
        if ($readabilityOptimized !== $optimized['optimized_description']) {
            $optimized['changes'][] = "Melhorar legibilidade e score de leitura";
            $optimized['optimized_description'] = $readabilityOptimized;
        }

        $optimized['improved'] = $optimized['optimized_description'] !== $currentDesc;

        return $optimized;
    }

    /**
     * 🏷️ Otimização avançada de atributos
     */
    public function optimizeAttributes(array $itemData): array
    {
        $currentAttrs = $itemData['attributes'] ?? [];

        $optimized = [
            'current_attributes' => $currentAttrs,
            'optimized_attributes' => $currentAttrs,
            'improved' => false,
            'changes' => [],
        ];

        // Estratégia 1: Completar atributos obrigatórios faltantes
        $missingAttrs = $this->getMissingRequiredAttributes($itemData);
        if (!empty($missingAttrs)) {
            $optimized['changes'][] = "Completar " . count($missingAttrs) . " atributos obrigatórios";
            $optimized['optimized_attributes'] = array_merge($currentAttrs, $missingAttrs);
        }

        // Estratégia 2: Adicionar atributos de filtro populares
        $filterAttrs = $this->getPopularFilterAttributes($itemData['category_id'] ?? '');
        if (!empty($filterAttrs)) {
            $optimized['changes'][] = "Adicionar atributos de filtro populares";
            $optimized['optimized_attributes'] = array_merge($optimized['optimized_attributes'], $filterAttrs);
        }

        // Estratégia 3: Otimizar valores dos atributos
        $valueOptimized = $this->optimizeAttributeValues($optimized['optimized_attributes']);
        if ($valueOptimized !== $optimized['optimized_attributes']) {
            $optimized['changes'][] = "Otimizar valores dos atributos para melhor matching";
            $optimized['optimized_attributes'] = $valueOptimized;
        }

        $optimized['improved'] = count($optimized['optimized_attributes']) > count($currentAttrs);

        return $optimized;
    }

    /**
     * 📊 Cálculo avançado de SEO score
     */
    public function calculateSEOScore(array $itemData): array
    {
        $scores = [
            'overall' => 0,
            'title' => 0,
            'description' => 0,
            'attributes' => 0,
            'images' => 0,
            'price' => 0,
        ];

        // Score do título
        $title = $itemData['title'] ?? '';
        $scores['title'] = $this->scoreTitle($title);

        // Score da descrição
        $desc = $itemData['description'] ?? '';
        $scores['description'] = $this->scoreDescription($desc);

        // Score dos atributos
        $attrs = $itemData['attributes'] ?? [];
        $scores['attributes'] = $this->scoreAttributes($attrs, $itemData['category_id'] ?? '');

        // Score das imagens
        $images = $itemData['pictures'] ?? [];
        $scores['images'] = $this->scoreImages($images);

        // Score do preço
        $price = $itemData['price'] ?? 0;
        $scores['price'] = $this->scorePrice($price, $itemData['category_id'] ?? '');

        // Calcular score geral ponderado
        $scores['overall'] = (
            $scores['title'] * self::SEO_WEIGHTS['title'] +
            $scores['description'] * self::SEO_WEIGHTS['description'] +
            $scores['attributes'] * self::SEO_WEIGHTS['attributes'] +
            $scores['images'] * self::SEO_WEIGHTS['images'] +
            $scores['price'] * self::SEO_WEIGHTS['price']
        ) / 100;

        return $scores;
    }

    /**
     * 🎯 Geração de keywords avançadas
     */
    public function generateAdvancedKeywords(array $itemData): array
    {
        $keywords = [
            'primary' => [],
            'secondary' => [],
            'long_tail' => [],
            'lsi' => [],
            'converting' => [],
        ];

        // Keywords primárias (baseado no título)
        $title = $itemData['title'] ?? '';
        $keywords['primary'] = $this->extractPrimaryKeywords($title);

        // Keywords secundárias (baseado na categoria)
        $category = $this->getCategoryInfo($itemData['category_id'] ?? '');
        $keywords['secondary'] = $this->getSecondaryKeywords($category);

        // Long-tail keywords
        $keywords['long_tail'] = $this->generateLongTailKeywords($itemData);

        // LSI Keywords
        $keywords['lsi'] = $this->getLSIKeywords($itemData);

        // Converting keywords
        $keywords['converting'] = $this->getConvertingKeywords($category);

        return $keywords;
    }

    /**
     * 🤖 Análise competitiva avançada
     */
    public function advancedCompetitorAnalysis(string $itemId, array $itemData): array
    {
        $analysis = [
            'item_id' => $itemId,
            'competitors' => [],
            'opportunities' => [],
            'threats' => [],
            'recommendations' => [],
        ];

        // Buscar competidores diretos
        $competitors = $this->findDirectCompetitors($itemData);

        foreach ($competitors as $competitor) {
            $compAnalysis = [
                // findDirectCompetitors faz SELECT ml_item_id — a coluna é ml_item_id,
                // não 'id'; usar $competitor['id'] devolvia null para todos.
                'item_id' => $competitor['ml_item_id'] ?? $competitor['id'] ?? null,
                'title' => $competitor['title'],
                'price' => $competitor['price'],
                'sales' => $competitor['sold_quantity'] ?? 0,
                'seo_score' => $this->calculateSEOScore($competitor),
                'strengths' => [],
                'weaknesses' => [],
            ];

            // Análise comparativa
            $compAnalysis['strengths'] = $this->identifyCompetitorStrengths($competitor, $itemData);
            $compAnalysis['weaknesses'] = $this->identifyCompetitorWeaknesses($competitor, $itemData);

            $analysis['competitors'][] = $compAnalysis;
        }

        // Gerar oportunidades e ameaças
        $analysis['opportunities'] = $this->identifyOpportunities($analysis['competitors'], $itemData);
        $analysis['threats'] = $this->identifyThreats($analysis['competitors'], $itemData);

        // Gerar recomendações estratégicas
        $analysis['recommendations'] = $this->generateStrategicRecommendations($analysis);

        return $analysis;
    }

    // ========== MÉTODOS PRIVADOS ==========

    private function getItemData(string $itemId): array
    {
        try {
            $client = new \App\Services\MercadoLivreClient($this->accountId);
            $item = $client->get("/items/{$itemId}");
            return $item ?? [];
        } catch (\Throwable $e) {
            // Fallback para dados locais
            $stmt = $this->db->prepare("SELECT * FROM items WHERE id = ? AND account_id = ?");
            $stmt->execute([$itemId, $this->accountId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
    }

    /**
     * Busca dados da categoria na API do ML (nome, path_from_root, etc.).
     * Bug conhecido corrigido: consultava `SELECT * FROM categories`, tabela
     * que nunca existiu no schema — sempre retornava vazio silenciosamente.
     * `MercadoLivreClient::getCategory()` já cacheia 24h (arquivo/Redis);
     * aqui memoizamos só para a duração do request atual.
     */
    private function getCategoryInfo(string $categoryId): array
    {
        if ($categoryId === '') {
            return [];
        }

        if (array_key_exists($categoryId, $this->categoryInfoCache)) {
            return $this->categoryInfoCache[$categoryId];
        }

        $category = $this->mlClient->getCategory($categoryId);
        $this->categoryInfoCache[$categoryId] = $category;

        return $category;
    }

    private function extractKeywords(string $text): array
    {
        $stopWords = [
            'para',
            'com',
            'que',
            'dos',
            'das',
            'nem',
            'mas',
            'por',
            'como',
            'mais',
            'este',
            'esta',
            'esse',
            'essa',
            'pelo',
            'pela',
            'seus',
            'suas',
            'qual',
            'cada',
            'todo',
            'toda',
            'todos',
            'todas',
            'muito',
            'muita',
            'quando',
            'onde',
            'aqui',
            'sobre',
            'entre',
            'depois',
            'antes',
            'desde',
            'ainda',
            'foram',
            'sido',
            'pode',
            'será',
            'tipo',
            'unid',
            'pçs',
            'jogo',
            'peca',
            'peça',
        ];

        $words = preg_split('/[\s,.!?;:()\[\]\/\-]+/', mb_strtolower($text, 'UTF-8'));
        $words = array_filter($words, function (string $word) use ($stopWords): bool {
            return mb_strlen($word) > 2
                && !in_array($word, $stopWords, true)
                && !is_numeric($word);
        });

        // Gerar bigrams para termos compostos
        $wordList = array_values($words);
        $bigrams = [];
        for ($i = 0, $len = count($wordList) - 1; $i < $len; $i++) {
            $bigrams[] = $wordList[$i] . ' ' . $wordList[$i + 1];
        }

        return array_values(array_unique(array_merge($wordList, $bigrams)));
    }

    private function optimizeTitleStructure(string $title, array $itemData): string
    {
        // Estrutura ideal: [Marca] + [Modelo] + [Característica Principal] + [Benefício]
        $attributes = $itemData['attributes'] ?? [];

        $brand = '';
        $model = '';
        foreach ($attributes as $attr) {
            $id = $attr['id'] ?? '';
            $value = $attr['value_name'] ?? '';
            if ($id === 'BRAND' && $value !== '') {
                $brand = $value;
            }
            if (in_array($id, ['MODEL', 'VEHICLE_MODEL'], true) && $value !== '') {
                $model = $value;
            }
        }

        // Se não há marca/modelo extraíveis, não reestruturar
        if ($brand === '' && $model === '') {
            return $title;
        }

        // Remover marca e modelo existentes do título para evitar duplicação
        $cleanTitle = $title;
        if ($brand !== '' && stripos($cleanTitle, $brand) !== false) {
            $cleanTitle = trim(str_ireplace($brand, '', $cleanTitle));
        }
        if ($model !== '' && stripos($cleanTitle, $model) !== false) {
            $cleanTitle = trim(str_ireplace($model, '', $cleanTitle));
        }

        // Limpar espaços/separadores múltiplos
        $cleanTitle = trim(preg_replace('/\s{2,}/', ' ', $cleanTitle));
        $cleanTitle = trim($cleanTitle, ' -–/');

        // Reconstruir: [Marca] [Modelo] [Resto do título]
        $parts = array_filter([$brand, $model, $cleanTitle]);
        $newTitle = implode(' ', $parts);

        // Respeitar limite de 60 caracteres do ML
        if (mb_strlen($newTitle) > 60) {
            $newTitle = mb_substr($newTitle, 0, 57) . '...';
        }

        return $newTitle;
    }

    private function addPowerWords(string $title, array $category): string
    {
        $categoryType = $category['name'] ?? '';
        $relevantWords = [];

        // Selecionar power words relevantes para a categoria
        foreach (self::POWER_WORDS as $type => $words) {
            foreach ($words as $word) {
                if (
                    stripos($title, $word) === false &&
                    $this->isPowerWordRelevant($word, $categoryType)
                ) {
                    $relevantWords[] = $word;
                }
            }
        }

        // Adicionar 1-2 power words sem exceder limite de caracteres
        if (!empty($relevantWords) && mb_strlen($title) < 55) {
            $title .= ' ' . $relevantWords[0];
        }

        return $title;
    }

    private function isPowerWordRelevant(string $word, string $category): bool
    {
        $categoryLower = mb_strtolower($category, 'UTF-8');

        // Mapeamento de relevância: tipo de power word → categorias onde se aplica
        $categoryMap = [
            'premium'  => ['eletrônic', 'celular', 'informátic', 'acessório', 'relógi'],
            'luxo'     => ['moda', 'jóia', 'relógi', 'perfum', 'acessório'],
            'profissional' => ['ferramenta', 'esporte', 'fitness', 'instrument', 'equip'],
            'resistente' => ['esporte', 'outdoor', 'moto', 'auto', 'ferramenta', 'peça'],
            'original' => ['peça', 'auto', 'moto', 'celular', 'eletrônic'],
            'garantia' => ['eletrônic', 'celular', 'informátic', 'eletrodoméstic'],
            'confortável' => ['moda', 'calçado', 'móve', 'colchã'],
            'moderno'  => ['decoraç', 'móve', 'moda', 'eletrônic'],
            'testado'  => ['esporte', 'auto', 'moto', 'saúde'],
            'certificado' => ['eletrônic', 'saúde', 'aliment', 'cosmético'],
        ];

        $wordLower = mb_strtolower($word, 'UTF-8');
        $relevantCategories = $categoryMap[$wordLower] ?? null;

        // Se não temos mapeamento específico, considerar relevante
        if ($relevantCategories === null) {
            return true;
        }

        foreach ($relevantCategories as $fragment) {
            if (mb_strpos($categoryLower, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    private function optimizeForMobile(string $title): string
    {
        // Limitar a 60 caracteres para mobile
        if (mb_strlen($title) > 60) {
            return mb_substr($title, 0, 57) . '...';
        }
        return $title;
    }

    private function createStructuredDescription(array $itemData): string
    {
        $template = "
🔥 **{PRODUCT_NAME}** 🔥

✅ **Características Principais:**
{BULLET_POINTS}

🎯 **Por que escolher este produto?**
{BENEFITS}

🛡️ **Garantia e Segurança:**
{WARRANTY}

📦 **Envio Rápido:** Produto em estoque, envio imediato!
🔒 **Compra Segura:** 100% satisfação ou seu dinheiro de volta
        ";

        // Substituir placeholders com dados do item
        return $this->fillTemplate($template, $itemData);
    }

    private function getLSIKeywords(array $itemData): array
    {
        $categoryId = $itemData['category_id'] ?? '';
        if (empty($categoryId)) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT keyword
                FROM market_keywords
                WHERE category_id = ?
                ORDER BY relevance DESC
                LIMIT 15
            ");
            $stmt->execute([$categoryId]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($rows)) {
                return $rows;
            }
        } catch (\Throwable $e) {
            // Fallback para extração do título
        }

        // Fallback: palavras do título que não são stopwords
        $stopwords = ['de', 'da', 'do', 'para', 'com', 'em', 'e', 'a', 'o', 'os', 'as'];
        $words = preg_split('/\W+/', mb_strtolower($itemData['title'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($words, fn(string $w): bool => mb_strlen($w) > 3 && !in_array($w, $stopwords)));
    }

    private function injectLSIKeywords(string $description, array $lsiKeywords): string
    {
        if (empty($lsiKeywords)) {
            return $description;
        }

        // Filtrar keywords que já estão presentes
        $missing = array_filter($lsiKeywords, fn(string $kw): bool => stripos($description, $kw) === false);
        if (empty($missing)) {
            return $description;
        }

        // Injetar até 3 keywords ausentes em uma frase natural ao final
        $toInject = array_slice(array_values($missing), 0, 3);
        $injected = implode(', ', $toInject);
        $suffix = "\n\n🔍 Palavras-chave relacionadas: {$injected}.";

        return rtrim($description) . $suffix;
    }

    private function addEmotionalTriggers(string $description, array $itemData): string
    {
        // Verificar se já tem CTAs/gatilhos emocionais comuns
        $existingTriggers = ['compre agora', 'aproveite', 'oferta', 'garantia', 'satisfação garantida', 'frete grátis', 'envio imediato'];
        foreach ($existingTriggers as $trigger) {
            if (stripos($description, $trigger) !== false) {
                return $description; // Já tem gatilho
            }
        }

        $categoryId = $itemData['category_id'] ?? '';
        $isMotoPart = str_contains(strtolower($itemData['title'] ?? ''), 'moto')
            || str_starts_with(strtoupper($categoryId), 'MLB');

        $trigger = $isMotoPart
            ? "\n\n⚡ **Compre com confiança!** Produto testado, compatibilidade garantida. Dúvida sobre o modelo? Nos chame antes de comprar — respondemos rápido!"
            : "\n\n⚡ **Compre agora com segurança!** Satisfação garantida ou seu dinheiro de volta. Atendimento ágil e envio imediato!";

        return rtrim($description) . $trigger;
    }

    private function optimizeReadability(string $description): string
    {
        if (empty($description)) {
            return $description;
        }

        // 1. Substituir ponto-e-vírgula por ponto final + nova linha para frases mais curtas
        $result = preg_replace('/;\s+/', ".\n", $description);

        // 2. Quebrar frases muito longas (> 200 chars sem quebra de linha)
        if ($result !== null) {
            $result = preg_replace_callback('/([^\n]{200,}?)([.!?])\s/', function (array $m): string {
                return $m[1] . $m[2] . "\n";
            }, $result);
        }

        $result = $result ?? $description;

        // 3. Garantir que listas com vírgulas sejam convertidas em bullet points,
        //    mas somente dentro de blocos que pareçam listas (3+ itens separados por vírgula)
        $result = preg_replace_callback(
            '/(?::\s*)([\w][^\n.]{0,60}(?:,\s*[\w][^,\n]{0,40}){2,})\.?/',
            function (array $m): string {
                $items = array_map('trim', explode(',', $m[1]));
                if (count($items) < 3) {
                    return $m[0];
                }
                return ":\n" . implode("\n", array_map(fn(string $i): string => '• ' . $i, $items));
            },
            $result
        ) ?? $result;

        return $result;
    }

    private function getMissingRequiredAttributes(array $itemData): array
    {
        $categoryId = $itemData['category_id'] ?? '';
        if (empty($categoryId)) {
            return [];
        }

        $required = $this->getRequiredAttributes($categoryId);
        if (empty($required)) {
            return [];
        }

        // Mapear ids dos atributos existentes no item
        $existingIds = array_map(
            fn(array $a): string => strtoupper($a['id'] ?? ''),
            $itemData['attributes'] ?? []
        );

        // Retornar apenas os obrigatórios que não estão preenchidos
        return array_values(array_filter($required, function (array $req) use ($existingIds): bool {
            return !in_array(strtoupper($req['id'] ?? ''), $existingIds, true);
        }));
    }

    private function getPopularFilterAttributes(string $categoryId): array
    {
        if (empty($categoryId)) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT id, name
                FROM category_attributes
                WHERE category_id = ?
                  AND (is_filter = 1 OR tags LIKE '%filter%')
                ORDER BY id ASC
                LIMIT 10
            ");
            $stmt->execute([$categoryId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function optimizeAttributeValues(array $attributes): array
    {
        // Otimizar valores dos atributos para melhor matching
        return $attributes;
    }

    private function scoreTitle(string $title): int
    {
        $score = 0;

        // Comprimento ideal (45-58 chars)
        $length = mb_strlen($title);
        if ($length >= 45 && $length <= 58) {
            $score += 30;
        } elseif ($length >= 35 && $length <= 68) {
            $score += 20;
        } else {
            $score += 10;
        }

        // Presença de keywords
        $keywords = $this->extractKeywords($title);
        $score += min(count($keywords) * 5, 40);

        // Palavras poderosas
        foreach (self::POWER_WORDS as $type => $words) {
            foreach ($words as $word) {
                if (stripos($title, $word) !== false) {
                    $score += 5;
                }
            }
        }

        return min($score, 100);
    }

    private function scoreDescription(string $description): int
    {
        $score = 0;

        // Comprimento mínimo (500+ chars)
        if (mb_strlen($description) >= 500) {
            $score += 30;
        } elseif (mb_strlen($description) >= 300) {
            $score += 20;
        } else {
            $score += 10;
        }

        // Estrutura com bullet points
        if (strpos($description, '•') !== false || strpos($description, '*') !== false) {
            $score += 20;
        }

        // Keywords
        $keywords = $this->extractKeywords($description);
        $score += min(count($keywords) * 2, 30);

        // Chamadas para ação (CTAs)
        $ctas = ['compre', 'garantia', 'envio', 'desconto', 'promoção'];
        foreach ($ctas as $cta) {
            if (stripos($description, $cta) !== false) {
                $score += 5;
            }
        }

        return min($score, 100);
    }

    private function scoreAttributes(array $attributes, string $categoryId): int
    {
        $score = 0;

        // Número mínimo de atributos
        if (count($attributes) >= 10) {
            $score += 40;
        } elseif (count($attributes) >= 5) {
            $score += 25;
        } else {
            $score += 10;
        }

        // Presença de atributos obrigatórios
        $requiredAttrs = $this->getRequiredAttributes($categoryId);
        $filledRequired = 0;
        foreach ($requiredAttrs as $reqAttr) {
            foreach ($attributes as $attr) {
                if ($attr['id'] === $reqAttr['id'] && !empty($attr['value_name'])) {
                    $filledRequired++;
                    break;
                }
            }
        }

        if (!empty($requiredAttrs)) {
            $score += ($filledRequired / count($requiredAttrs)) * 60;
        }

        return min($score, 100);
    }

    private function scoreImages(array $images): int
    {
        $score = 0;

        // Número mínimo de imagens
        if (count($images) >= 6) {
            $score += 40;
        } elseif (count($images) >= 3) {
            $score += 25;
        } else {
            $score += 10;
        }

        // Qualidade das imagens: verifica campo size/max_size da imagem ML
        // (ex.: "1200x803"). Antes sempre incrementava highRes sem verificar nada,
        // fazendo a pontuação de imagens sempre chegar ao máximo independentemente
        // da qualidade real. Agora usa dados reais quando disponíveis.
        $highRes = 0;
        foreach ($images as $image) {
            $size = (string) ($image['size'] ?? $image['max_size'] ?? '');
            if ($size !== '') {
                $parts = explode('x', strtolower($size));
                $w = (int) ($parts[0] ?? 0);
                $h = (int) ($parts[1] ?? 0);
                $minDim = ($w > 0 && $h > 0) ? min($w, $h) : max($w, $h);
                if ($minDim >= 800) {
                    $highRes++;
                }
            } else {
                // Heurística de URL: imagens ML com '-O.' no path são originais (alta res)
                $url = (string) ($image['secure_url'] ?? $image['url'] ?? '');
                if (str_contains($url, '-O.')) {
                    $highRes++;
                }
            }
        }

        if (!empty($images)) {
            $score += ($highRes / count($images)) * 60;
        }

        return min($score, 100);
    }

    private function scorePrice(float $price, string $categoryId): int
    {
        if ($price <= 0 || empty($categoryId)) {
            return 50;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT AVG(price) AS avg_price, MIN(price) AS min_price
                FROM competitor_items
                WHERE category_id = ?
                  AND status = 'active'
                  AND price > 0
            ");
            $stmt->execute([$categoryId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$stats || (float)($stats['avg_price'] ?? 0) <= 0) {
                return 50; // Sem dados de concorrência
            }

            $ratio = $price / (float)$stats['avg_price'];

            if ($ratio <= 0.85) return 100;
            if ($ratio <= 0.95) return 85;
            if ($ratio <= 1.05) return 70;
            if ($ratio <= 1.20) return 50;
            if ($ratio <= 1.40) return 30;
            return 15;
        } catch (\Throwable $e) {
            return 50;
        }
    }

    private function getRequiredAttributes(string $categoryId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM category_attributes
                WHERE category_id = ? AND required = 1
            ");
            $stmt->execute([$categoryId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function extractPrimaryKeywords(string $title): array
    {
        return $this->extractKeywords($title);
    }

    private function getSecondaryKeywords(array $category): array
    {
        $categoryId = $category['id'] ?? $category['category_id'] ?? '';
        if (empty($categoryId)) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT keyword
                FROM market_keywords
                WHERE category_id = ?
                ORDER BY relevance ASC
                LIMIT 20
            ");
            $stmt->execute([$categoryId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function generateLongTailKeywords(array $itemData): array
    {
        $title = $itemData['title'] ?? '';
        $categoryId = $itemData['category_id'] ?? '';

        if (empty($title)) {
            return [];
        }

        // Extrair palavras-base do título
        $stopwords = ['de', 'da', 'do', 'para', 'com', 'em', 'e', 'a', 'o', 'os', 'as', 'um', 'uma'];
        $words = array_filter(
            preg_split('/\W+/', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?? [],
            fn(string $w): bool => mb_strlen($w) > 3 && !in_array($w, $stopwords)
        );
        $baseWords = array_values($words);

        // Contextos de long-tail para peças de moto (contexto do negócio)
        $contexts = ['original', 'barato', 'melhor preço', 'promoção', 'comprar', 'frete grátis'];

        $longTails = [];
        foreach (array_slice($baseWords, 0, 3) as $word) {
            foreach (array_slice($contexts, 0, 3) as $ctx) {
                $longTails[] = $word . ' ' . $ctx;
            }
        }

        // Buscar long-tails do banco para a categoria
        if (!empty($categoryId)) {
            try {
                $stmt = $this->db->prepare("
                    SELECT keyword
                    FROM market_keywords
                    WHERE category_id = ?
                      AND keyword LIKE '% %'
                    ORDER BY relevance DESC
                    LIMIT 10
                ");
                $stmt->execute([$categoryId]);
                $dbLongTails = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $longTails = array_unique(array_merge($longTails, $dbLongTails));
            } catch (\Throwable $e) {
                // Usa apenas os gerados
            }
        }

        return array_values(array_slice($longTails, 0, 20));
    }

    private function getConvertingKeywords(array $category): array
    {
        $categoryName = strtolower($category['name'] ?? '');

        foreach (self::CONVERTING_KEYWORDS as $type => $keywords) {
            if (strpos($categoryName, $type) !== false) {
                return $keywords;
            }
        }

        return self::CONVERTING_KEYWORDS['electronics']; // Default
    }

    private function findDirectCompetitors(array $itemData): array
    {
        $categoryId = $itemData['category_id'] ?? '';
        $price = (float)($itemData['price'] ?? 0);

        if (empty($categoryId)) {
            return [];
        }

        try {
            $params = ['category_id' => $categoryId];
            $priceClause = '';
            if ($price > 0) {
                $priceClause = 'AND price BETWEEN :price_low AND :price_high';
                $params['price_low']  = $price * 0.6;
                $params['price_high'] = $price * 1.6;
            }

            $stmt = $this->db->prepare("
                SELECT ml_item_id, seller_id, title, price, sold_quantity,
                       available_quantity
                FROM competitor_items
                WHERE category_id = :category_id
                  AND status = 'active'
                  {$priceClause}
                ORDER BY sold_quantity DESC
                LIMIT 10
            ");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function identifyCompetitorStrengths(array $competitor, array $itemData): array
    {
        $strengths = [];
        $myPrice = (float)($itemData['price'] ?? 0);
        $compPrice = (float)($competitor['price'] ?? 0);

        if ($compPrice > 0 && $myPrice > 0 && $compPrice < $myPrice * 0.9) {
            $strengths[] = 'Preço ' . round((1 - $compPrice / $myPrice) * 100) . '% mais barato';
        }
        if ((int)($competitor['sold_quantity'] ?? 0) > 50) {
            $strengths[] = 'Alto volume de vendas (' . $competitor['sold_quantity'] . ' vendidos)';
        }
        if (mb_strlen($competitor['title'] ?? '') >= 45 && mb_strlen($competitor['title'] ?? '') <= 60) {
            $strengths[] = 'Título com comprimento ideal para SEO';
        }

        return $strengths;
    }

    private function identifyCompetitorWeaknesses(array $competitor, array $itemData): array
    {
        $weaknesses = [];
        $myPrice = (float)($itemData['price'] ?? 0);
        $compPrice = (float)($competitor['price'] ?? 0);

        if ($compPrice > 0 && $myPrice > 0 && $compPrice > $myPrice * 1.1) {
            $weaknesses[] = 'Preço ' . round(($compPrice / $myPrice - 1) * 100) . '% mais caro';
        }
        if (mb_strlen($competitor['title'] ?? '') > 60) {
            $weaknesses[] = 'Título muito longo (' . mb_strlen($competitor['title']) . ' chars)';
        }
        if ((int)($competitor['available_quantity'] ?? 0) < 3) {
            $weaknesses[] = 'Estoque reduzido';
        }

        return $weaknesses;
    }

    private function identifyOpportunities(array $competitors, array $itemData): array
    {
        $opportunities = [];
        $myPrice = (float)($itemData['price'] ?? 0);

        if (empty($competitors)) {
            $opportunities[] = 'Poucos concorrentes nesta categoria — alta oportunidade de visibilidade';
            return $opportunities;
        }

        $prices = array_filter(array_column($competitors, 'price'), fn(float|int $p): bool => $p > 0);
        if (!empty($prices) && $myPrice > 0) {
            $avgComp = array_sum($prices) / count($prices);
            if ($myPrice <= $avgComp * 0.95) {
                $opportunities[] = 'Seu preço está abaixo da média da concorrência — destaque no ranking';
            }
        }

        $lowStock = array_filter($competitors, fn(array $c): bool => (int)($c['available_quantity'] ?? 99) < 3);
        if (count($lowStock) >= count($competitors) * 0.5) {
            $opportunities[] = 'Mais de 50% dos concorrentes com estoque baixo — manter estoque gera vantagem';
        }

        return $opportunities;
    }

    private function identifyThreats(array $competitors, array $itemData): array
    {
        $threats = [];
        $myPrice = (float)($itemData['price'] ?? 0);

        $prices = array_filter(array_column($competitors, 'price'), fn(float|int $p): bool => $p > 0);
        if (!empty($prices) && $myPrice > 0) {
            $minComp = min($prices);
            if ($minComp < $myPrice * 0.75) {
                $threats[] = 'Concorrente com preço ' . round((1 - $minComp / $myPrice) * 100) . '% abaixo — risco de perda de buybox';
            }
        }

        $highSales = array_filter($competitors, fn(array $c): bool => (int)($c['sold_quantity'] ?? 0) > 200);
        if (!empty($highSales)) {
            $threats[] = count($highSales) . ' concorrente(s) com 200+ vendas — posição consolidada';
        }

        return $threats;
    }

    private function generateStrategicRecommendations(array $analysis): array
    {
        $recommendations = [];

        if (!empty($analysis['opportunities'])) {
            foreach ($analysis['opportunities'] as $opp) {
                $recommendations[] = ['type' => 'opportunity', 'action' => $opp, 'priority' => 'high'];
            }
        }

        if (!empty($analysis['threats'])) {
            foreach ($analysis['threats'] as $threat) {
                $recommendations[] = ['type' => 'threat', 'action' => 'Monitorar: ' . $threat, 'priority' => 'medium'];
            }
        }

        if (!empty($analysis['weaknesses']) && is_array($analysis['weaknesses'])) {
            $weaknesses = is_array(reset($analysis['weaknesses'])) ? $analysis['weaknesses'] : [$analysis['weaknesses']];
            foreach (array_slice($weaknesses, 0, 2) as $wList) {
                foreach ((array)$wList as $w) {
                    $recommendations[] = ['type' => 'improvement', 'action' => 'Vantagem sobre concorrente: ' . $w, 'priority' => 'low'];
                }
            }
        }

        if (empty($recommendations)) {
            $recommendations[] = ['type' => 'maintain', 'action' => 'Manter posicionamento atual e monitorar variações de preço semanalmente', 'priority' => 'low'];
        }

        return $recommendations;
    }

    private function fillTemplate(string $template, array $itemData): string
    {
        // Extrair marca e modelo dos attributos, se disponível
        $brand = '';
        $model = '';
        foreach ($itemData['attributes'] ?? [] as $attr) {
            $id = strtolower($attr['id'] ?? '');
            if (in_array($id, ['brand', 'marca'])) {
                $brand = $attr['value_name'] ?? '';
            } elseif (in_array($id, ['model', 'modelo'])) {
                $model = $attr['value_name'] ?? '';
            }
        }

        $productName = trim(implode(' ', array_filter([$brand, $model])));
        if (empty($productName)) {
            $productName = $itemData['title'] ?? 'Produto';
        }

        // Montar bullet points das características
        $bulletLines = [];
        foreach ($itemData['attributes'] ?? [] as $attr) {
            if (!empty($attr['value_name'])) {
                $bulletLines[] = '• ' . ($attr['name'] ?? $attr['id']) . ': ' . $attr['value_name'];
            }
        }
        $bullets = !empty($bulletLines)
            ? implode("\n", array_slice($bulletLines, 0, 8))
            : '• Confirma compatibilidade antes de comprar';

        // Benefícios
        $benefits = implode("\n", [
            '• Produto de alta qualidade com acabamento premium',
            '• Fácil instalação, encaixe perfeito',
            '• Compatibilidade verificada — informe o modelo da sua moto',
            '• Atendimento ágil e pós-venda garantido',
        ]);

        // Garantia
        $warrantyAttr = '';
        foreach ($itemData['attributes'] ?? [] as $attr) {
            if (in_array(strtolower($attr['id'] ?? ''), ['warranty_time', 'garantia', 'warranty'])) {
                $warrantyAttr = $attr['value_name'] ?? '';
                break;
            }
        }
        $warranty = !empty($warrantyAttr)
            ? 'Garantia do fabricante: ' . $warrantyAttr
            : 'Garantia de fábrica contra defeitos de fabricação. Consulte condições na descrição.';

        $replacements = [
            '{PRODUCT_NAME}' => $productName,
            '{BULLET_POINTS}' => $bullets,
            '{BENEFITS}'     => $benefits,
            '{WARRANTY}'     => $warranty,
            '{BRAND}'        => $brand ?: 'Não informado',
            '{MODEL}'        => $model ?: 'Consulte compatibilidade',
            '{TITLE}'        => $itemData['title'] ?? '',
            '{CATEGORY}'     => $itemData['category_id'] ?? '',
            '{PRICE}'        => isset($itemData['price']) ? 'R$ ' . number_format((float)$itemData['price'], 2, ',', '.') : '',
        ];

        return strtr($template, $replacements);
    }

    private function generateImprovements(array $results): array
    {
        $improvements = [];

        // score_before/score_after são arrays retornados por calculateSEOScore()
        // (overall/title/description/attributes/images/price); usamos 'overall'
        // como o agregado 0-100 para o texto de melhoria.
        $scoreImprovement = ($results['score_after']['overall'] ?? 0)
            - ($results['score_before']['overall'] ?? 0);
        if ($scoreImprovement > 0) {
            $improvements[] = "SEO Score melhorado em +{$scoreImprovement} pontos";
        }

        foreach ($results['optimizations'] as $component => $opt) {
            if ($opt['improved']) {
                $improvements[] = "Componente {$component} otimizado";
            }
        }

        return $improvements;
    }

    private function saveOptimizationAnalysis(string $itemId, array $results): void
    {
        // Tabela nunca existia no schema (INSERT sempre falhava e era engolido
        // pelo catch(\Throwable) de maximizeItemSEO). score_before/score_after
        // são arrays (calculateSEOScore), então gravamos como JSON.
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS seo_optimization_analysis (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                item_id VARCHAR(32) NOT NULL,
                score_before JSON,
                score_after JSON,
                optimizations JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_account_item (account_id, item_id),
                INDEX idx_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $stmt = $this->db->prepare("
            INSERT INTO seo_optimization_analysis
            (account_id, item_id, score_before, score_after, optimizations, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            score_before = VALUES(score_before),
            score_after = VALUES(score_after),
            optimizations = VALUES(optimizations),
            updated_at = NOW()
        ");

        $stmt->execute([
            $this->accountId,
            $itemId,
            json_encode($results['score_before']),
            json_encode($results['score_after']),
            json_encode($results['optimizations'])
        ]);
    }
}
