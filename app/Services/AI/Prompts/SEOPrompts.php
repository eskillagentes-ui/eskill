<?php

declare(strict_types=1);

namespace App\Services\AI\Prompts;

class SEOPrompts
{
    public const SYSTEM_OPTIMIZATION = "Você é um especialista em SEO para Mercado Livre. Suas respostas devem ser estritamente em JSON.";

    public static function analyzeTitle(array $context): string
    {
        $forbiddenList = implode(', ', $context['forbidden_words'] ?? []);
        return <<<PROMPT
Analise este título SEO e forneça uma avaliação detalhada:

Título: {$context['title']}
Categoria: {$context['category']}

Critérios de análise:
1. Comprimento (ideal entre 30-60 caracteres)
2. Presença de palavras-chave relevantes
3. Uso de palavras proibidas: {$forbiddenList}
4. Clareza e atratividade
5. Potencial de conversão

Retorne um JSON com a seguinte estrutura:
{
  "title": "título analisado",
  "length": número de caracteres,
  "word_count": número de palavras,
  "keywords_found": ["palavras encontradas"],
  "readability": pontuação de 0-100,
  "score": pontuação de 0-100,
  "issues": ["problemas identificados"],
  "suggestions": ["sugestões de melhoria"]
}
PROMPT;
    }

    public static function analyzeDescription(array $context): string
    {
        return <<<PROMPT
Analise esta descrição SEO e forneça uma avaliação detalhada:

Descrição: {$context['description']}

Critérios de análise:
1. Comprimento (ideal entre 200-5000 chars)
2. Estrutura (presença de bullets, divisão clara de seções)
3. Presença de call-to-action
4. Densidade de palavras-chave
5. Clareza e legibilidade
6. Presença de benefícios e características do produto
7. Potencial de conversão

Retorne um JSON com a seguinte estrutura:
{
  "description_length": número de caracteres,
  "word_count": número de palavras,
  "keyword_density": {"density": percentual},
  "structure_score": pontuação de 0-100,
  "readability_score": pontuação de 0-100,
  "call_to_action": booleano,
  "bullets_found": número de bullets,
  "score": pontuação de 0-100,
  "issues": ["problemas identificados"],
  "suggestions": ["sugestões de melhoria"]
}
PROMPT;
    }

    public static function analyzeKeywords(array $context): string
    {
        $categoryKeywords = implode(', ', $context['category_keywords'] ?? []);
        return <<<PROMPT
Analise este conteúdo e forneça uma análise detalhada de palavras-chave:

Título: {$context['title']}
Descrição: {$context['description']}
Categoria: {$context['category']}

Critérios de análise:
1. Extraia keywords primárias (mais relevantes para o produto)
2. Extraia keywords secundárias (suporte ao produto principal)
3. Extraia keywords long-tail (frases mais específicas)
4. Identifique oportunidades de keywords ausentes
5. Avalie a relevância e potencial de cada keyword
6. Sugira keywords relacionadas baseadas na categoria: {$categoryKeywords}

Retorne um JSON com a seguinte estrutura:
{
  "primary_keywords": ["keyword1", "keyword2"],
  "secondary_keywords": ["keyword3", "keyword4"],
  "long_tail_keywords": ["keyword phrase 1", "keyword phrase 2"],
  "category_keywords": ["category related keywords"],
  "keyword_opportunities": [
    {
      "keyword": "missing keyword",
      "priority": 0.8,
      "estimated_interest": "alto|medio|baixo",
      "competition": "low"
    }
  ],
  "score": pontuação de 0-100
}

IMPORTANTE: você não tem acesso a dados reais de volume de busca do Mercado Livre.
NÃO inclua nenhum campo "search_volume" ou número de buscas mensais — isso seria
inventado. Use apenas "estimated_interest" (juízo qualitativo alto/médio/baixo),
nunca um número absoluto de buscas.
PROMPT;
    }
}
