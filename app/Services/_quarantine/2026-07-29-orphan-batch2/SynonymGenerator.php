<?php

declare(strict_types=1);

namespace App\Services\AI\ML;

use App\Database;
use App\Services\LLMService;
use App\Services\ClaudeClient;
use PDO;
use Exception;

/**
 * SynonymGenerator - Gerador de Sinônimos via AI/LLM
 *
 * Responsável por gerar sinônimos e keywords usando IA quando não há dados
 * pré-populados no banco de dados. Parte da arquitetura híbrida de keywords.
 *
 * @package App\Services\AI\ML
 */
class SynonymGenerator
{
    private PDO $db;
    private ?LLMService $llmService = null;
    private ?ClaudeClient $claudeClient = null;
    private ?int $accountId;

    /**
     * Modelo preferido para geração
     */
    private const PREFERRED_MODEL = 'gpt-4o-mini';

    /**
     * Fallback model
     */
    private const FALLBACK_MODEL = 'claude-3-haiku';

    /**
     * Máximo de sinônimos por nível
     */
    private const MAX_SYNONYMS_PER_LEVEL = 15;

    /**
     * Cache de resultados
     */
    private array $cache = [];

    public function __construct(?int $accountId = null)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
    }

    /**
     * Expande sinônimos para um termo base
     *
     * @param string $term Termo para expandir
     * @param string $categoryId ID da categoria
     * @return array Sinônimos organizados por nível
     */
    public function expandSynonyms(string $term, string $categoryId): array
    {
        $cacheKey = "syn_{$categoryId}_" . md5($term);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $prompt = $this->buildSynonymPrompt($term, $categoryId);

        try {
            $response = $this->callLLM($prompt);
            $synonyms = $this->parseSynonymResponse($response);

            $this->cache[$cacheKey] = $synonyms;
            return $synonyms;
        } catch (Exception $e) {
            log_warning('Erro ao expandir sinônimos via IA', [
                'service' => 'SynonymGenerator',
                'error' => $e->getMessage(),
            ]);
            return $this->getEmptyStructure();
        }
    }

    /**
     * Gera sinônimos para uma categoria inteira
     *
     * @param string $categoryName Nome da categoria
     * @param array $categoryPath Caminho da categoria
     * @return array Sinônimos base para a categoria
     */
    public function generateForCategory(string $categoryName, array $categoryPath = []): array
    {
        $prompt = $this->buildCategoryPrompt($categoryName, $categoryPath);

        try {
            $response = $this->callLLM($prompt);
            return $this->parseCategoryResponse($response);
        } catch (Exception $e) {
            log_warning('Erro ao gerar sinônimos para categoria', [
                'service' => 'SynonymGenerator',
                'category_name' => $categoryName,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Gera keywords para um termo
     *
     * @param string $term Termo base
     * @param string $categoryId ID da categoria
     * @return array Lista de keywords
     */
    public function generateKeywords(string $term, string $categoryId): array
    {
        $prompt = $this->buildKeywordPrompt($term, $categoryId);

        try {
            $response = $this->callLLM($prompt);
            return $this->parseKeywordResponse($response);
        } catch (Exception $e) {
            log_warning('Erro ao gerar keywords via AI', [
                'service' => 'SynonymGenerator',
                'term' => $term,
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Classifica uma lista de keywords por tipo
     *
     * @param array $keywords Lista de keywords
     * @param string $context Contexto do produto
     * @return array Keywords classificadas
     */
    public function classifyKeywords(array $keywords, string $context): array
    {
        if (empty($keywords)) {
            return [];
        }

        $prompt = $this->buildClassificationPrompt($keywords, $context);

        try {
            $response = $this->callLLM($prompt);
            return $this->parseClassificationResponse($response, $keywords);
        } catch (Exception $e) {
            log_warning('Erro ao classificar keywords', [
                'service' => 'SynonymGenerator',
                'keywords_count' => count($keywords),
                'error' => $e->getMessage(),
            ]);
            // Fallback: classificar por tamanho
            return $this->fallbackClassification($keywords);
        }
    }

    /**
     * Gera long tail keywords
     *
     * @param string $baseKeyword Keyword base
     * @param string $categoryId ID da categoria
     * @param int $count Quantidade desejada
     * @return array Long tail keywords
     */
    public function generateLongTail(string $baseKeyword, string $categoryId, int $count = 10): array
    {
        $prompt = $this->buildLongTailPrompt($baseKeyword, $categoryId, $count);

        try {
            $response = $this->callLLM($prompt);
            return $this->parseLongTailResponse($response);
        } catch (Exception $e) {
            log_warning('Erro ao gerar long tail keywords', [
                'service' => 'SynonymGenerator',
                'base_keyword' => $baseKeyword,
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Gera contextos de uso para um produto
     *
     * @param string $productDescription Descrição do produto
     * @param string $categoryId ID da categoria
     * @return array Contextos identificados
     */
    public function generateUseContexts(string $productDescription, string $categoryId): array
    {
        $prompt = $this->buildContextPrompt($productDescription, $categoryId);

        try {
            $response = $this->callLLM($prompt);
            return $this->parseContextResponse($response);
        } catch (Exception $e) {
            log_warning('Erro ao gerar contextos de uso', [
                'service' => 'SynonymGenerator',
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // ==================== PROMPTS ====================

    /**
     * Constrói prompt para expansão de sinônimos
     */
    private function buildSynonymPrompt(string $term, string $categoryId): string
    {
        return <<<PROMPT
Você é um especialista em SEO para Mercado Livre. Gere sinônimos para o termo "{$term}"
organizado em 4 níveis hierárquicos:

REGRAS:
1. Nível 1 (Genérico): Termos curtos de 1-2 palavras, mais buscados
2. Nível 2 (Qualificado): Termos de 2-3 palavras, mais específicos
3. Nível 3 (Contexto): Termos de 3-5 palavras com contexto de uso
4. Nível 4 (Long Tail): Frases de 5+ palavras, muito específicas

IMPORTANTE:
- Gere até 10 sinônimos por nível
- Foque em termos realmente usados em buscas no Mercado Livre Brasil
- Evite repetir palavras entre os níveis
- Considere variações regionais (BR)

Responda APENAS em JSON válido neste formato:
{
  "nivel_1": ["termo1", "termo2"],
  "nivel_2": ["termo composto 1", "termo composto 2"],
  "nivel_3": ["termo com contexto de uso"],
  "nivel_4": ["frase longa específica para busca"]
}

Categoria: {$categoryId}
Termo: {$term}
PROMPT;
    }

    /**
     * Constrói prompt para categoria
     */
    private function buildCategoryPrompt(string $categoryName, array $categoryPath): string
    {
        $path = implode(' > ', $categoryPath);

        return <<<PROMPT
Você é um especialista em SEO para Mercado Livre. Gere termos de busca principais
para a categoria "{$categoryName}".

Caminho: {$path}

Gere uma lista de 30-50 termos que compradores usariam para buscar produtos nesta categoria.
Inclua:
- Nomes genéricos do produto
- Variações regionais (Brasil)
- Termos técnicos
- Marcas populares genéricas
- Usos comuns

Responda APENAS em JSON válido:
{
  "keywords": [
    {"word": "termo", "weight": 1.0},
    {"word": "outro termo", "weight": 0.8}
  ]
}

O weight deve ser de 0.1 a 1.0 baseado na relevância estimada.
PROMPT;
    }

    /**
     * Constrói prompt para keywords
     */
    private function buildKeywordPrompt(string $term, string $categoryId): string
    {
        return <<<PROMPT
Gere keywords de busca relacionadas ao termo "{$term}" para Mercado Livre Brasil.

Classifique cada keyword em um dos tipos:
- CORE: Keywords principais, alta relevância
- SUPORTE: Keywords complementares, média relevância
- TECNICA: Especificações técnicas (medidas, materiais, etc)
- CONTEXTO: Contexto de uso (para que serve, onde usar)

Responda em JSON:
{
  "keywords": [
    {"keyword": "termo", "type": "core", "weight": 1.0},
    {"keyword": "termo técnico 50mm", "type": "tecnica", "weight": 0.7}
  ]
}

Gere 20-30 keywords variadas. Categoria: {$categoryId}
PROMPT;
    }

    /**
     * Constrói prompt para classificação
     */
    private function buildClassificationPrompt(array $keywords, string $context): string
    {
        $keywordList = implode(', ', array_map(fn(mixed $k): string => is_array($k) ? $k['keyword'] : $k, $keywords));

        return <<<PROMPT
Classifique as seguintes keywords em categorias:
- CORE: Principal, essencial para o produto
- SUPORTE: Complementar, ajuda na busca
- TECNICA: Especificação técnica
- CONTEXTO: Uso, aplicação, situação

Keywords: {$keywordList}
Contexto: {$context}

Responda em JSON:
{
  "classifications": {
    "keyword1": "core",
    "keyword2": "suporte"
  }
}
PROMPT;
    }

    /**
     * Constrói prompt para long tail
     */
    private function buildLongTailPrompt(string $baseKeyword, string $categoryId, int $count): string
    {
        return <<<PROMPT
Gere {$count} variações long tail da keyword "{$baseKeyword}" para Mercado Livre Brasil.

Long tail são frases de busca específicas com 4-8 palavras.
Exemplos de padrões:
- "[produto] para [uso específico]"
- "[produto] [característica] [tamanho/cor]"
- "[produto] compatível com [marca/modelo]"

Responda em JSON:
{
  "long_tail": [
    {"keyword": "frase longa de busca", "intent": "compra/informação"}
  ]
}

Categoria: {$categoryId}
PROMPT;
    }

    /**
     * Constrói prompt para contextos de uso
     */
    private function buildContextPrompt(string $productDescription, string $categoryId): string
    {
        return <<<PROMPT
Analise o produto e identifique contextos de uso:

Produto: {$productDescription}
Categoria: {$categoryId}

Retorne os contextos aplicáveis:
- profissional: uso comercial, trabalho
- lazer: viagem, passeio, hobby
- urbano: dia a dia, cidade
- carga: transporte, bagagem

Responda em JSON:
{
  "contexts": [
    {
      "type": "profissional",
      "keywords": ["delivery", "motoboy"],
      "relevance": 0.9
    }
  ]
}
PROMPT;
    }

    // ==================== PARSERS ====================

    /**
     * Parseia resposta de sinônimos
     */
    private function parseSynonymResponse(string $response): array
    {
        $data = $this->extractJson($response);

        if (!$data) {
            return $this->getEmptyStructure();
        }

        $result = $this->getEmptyStructure();

        foreach (['nivel_1', 'nivel_2', 'nivel_3', 'nivel_4'] as $level) {
            if (isset($data[$level]) && is_array($data[$level])) {
                $synonyms = array_slice($data[$level], 0, self::MAX_SYNONYMS_PER_LEVEL);
                foreach ($synonyms as $word) {
                    $result[$level][] = [
                        'word' => is_string($word) ? $word : ($word['word'] ?? ''),
                        'weight' => 1.0,
                        'source' => 'ai'
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Parseia resposta de categoria
     */
    private function parseCategoryResponse(string $response): array
    {
        $data = $this->extractJson($response);

        if (!$data || !isset($data['keywords'])) {
            return [];
        }

        return $data['keywords'];
    }

    /**
     * Parseia resposta de keywords
     */
    private function parseKeywordResponse(string $response): array
    {
        $data = $this->extractJson($response);

        if (!$data || !isset($data['keywords'])) {
            return [];
        }

        return $data['keywords'];
    }

    /**
     * Parseia resposta de classificação
     */
    private function parseClassificationResponse(string $response, array $originalKeywords): array
    {
        $data = $this->extractJson($response);

        if (!$data || !isset($data['classifications'])) {
            return $this->fallbackClassification($originalKeywords);
        }

        $result = [];
        foreach ($originalKeywords as $kw) {
            $word = is_array($kw) ? ($kw['keyword'] ?? '') : $kw;
            $type = $data['classifications'][$word] ?? 'core';

            $result[] = [
                'keyword' => $word,
                'type' => strtolower($type),
                'source' => 'ai_classified'
            ];
        }

        return $result;
    }

    /**
     * Parseia resposta de long tail
     */
    private function parseLongTailResponse(string $response): array
    {
        $data = $this->extractJson($response);

        if (!$data || !isset($data['long_tail'])) {
            return [];
        }

        return $data['long_tail'];
    }

    /**
     * Parseia resposta de contextos
     */
    private function parseContextResponse(string $response): array
    {
        $data = $this->extractJson($response);

        if (!$data || !isset($data['contexts'])) {
            return [];
        }

        return $data['contexts'];
    }

    // ==================== HELPERS ====================

    /**
     * Chama o LLM (com fallback)
     */
    private function callLLM(string $prompt): string
    {
        // Tentar LLMService primeiro (usa generate(), não complete())
        try {
            $llm = $this->getLLMService();
            $response = $llm->generate($prompt, '', 'basic');

            if (!empty($response['success']) && !empty($response['content'])) {
                return $response['content'];
            }
            // Se não teve sucesso, tenta fallback
            throw new Exception($response['error'] ?? 'LLMService retornou vazio');
        } catch (Exception $e) {
            log_warning('LLMService falhou, tentando ClaudeClient', [
                'service' => 'SynonymGenerator',
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback para ClaudeClient (este tem complete())
        try {
            $claude = $this->getClaudeClient();
            $response = $claude->complete([
                ['role' => 'user', 'content' => $prompt]
            ], [
                'max_tokens' => 2000
            ]);

            return $response['content'] ?? '';
        } catch (Exception $e) {
            log_error('ClaudeClient também falhou na geração LLM', [
                'service' => 'SynonymGenerator',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Extrai JSON de uma resposta de texto
     */
    private function extractJson(string $response): ?array
    {
        // Tentar decodificar diretamente
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Tentar extrair JSON do meio do texto
        preg_match('/\{[\s\S]*\}/m', $response, $matches);
        if (!empty($matches[0])) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Retorna estrutura vazia de sinônimos
     */
    private function getEmptyStructure(): array
    {
        return [
            'nivel_1' => [],
            'nivel_2' => [],
            'nivel_3' => [],
            'nivel_4' => []
        ];
    }

    /**
     * Classificação fallback por tamanho
     */
    private function fallbackClassification(array $keywords): array
    {
        return array_map(function (mixed $kw): array {
            $word = is_array($kw) ? ($kw['keyword'] ?? '') : $kw;
            $wordCount = str_word_count($word);

            $type = 'core';
            if ($wordCount >= 5) {
                $type = 'contexto';
            } elseif ($wordCount >= 3) {
                $type = 'suporte';
            } elseif (preg_match('/\d/', $word)) {
                $type = 'tecnica';
            }

            return [
                'keyword' => $word,
                'type' => $type,
                'source' => 'fallback'
            ];
        }, $keywords);
    }

    /**
     * Obtém instância do LLMService
     */
    private function getLLMService(): LLMService
    {
        if ($this->llmService === null) {
            $this->llmService = new LLMService();
        }
        return $this->llmService;
    }

    /**
     * Obtém instância do ClaudeClient
     */
    private function getClaudeClient(): ClaudeClient
    {
        if ($this->claudeClient === null) {
            $this->claudeClient = new ClaudeClient();
        }
        return $this->claudeClient;
    }
}
