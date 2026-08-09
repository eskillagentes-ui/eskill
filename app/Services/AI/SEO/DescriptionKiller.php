<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Services\AI\Core\AIProviderManager;
use App\Services\ItemService;

/**
 * 📝 DESCRIPTION KILLER - Descrições que Vendem
 * 
 * Gera descrições persuasivas e otimizadas para SEO:
 * - Estrutura AIDA (Atenção, Interesse, Desejo, Ação)
 * - Keywords semânticas naturais
 * - Emojis estratégicos
 * - Bullet points escaneáveis
 * - SEO interno do ML
 * 
 * @author AI Development Team
 * @version 1.0.0
 */
class DescriptionKiller
{
    private ?AIProviderManager $aiProvider;
    private ?int $accountId;
    private ?ItemService $itemService;
    
    // Templates por categoria
    private const TEMPLATES = [
        'electronics' => [
            'sections' => ['📱 SOBRE O PRODUTO', '⚡ ESPECIFICAÇÕES', '📦 O QUE VEM NA CAIXA', '🛡️ GARANTIA', '🚚 ENVIO'],
            'tone' => 'técnico e confiável',
            'focus' => 'specs, compatibilidade, qualidade',
        ],
        'fashion' => [
            'sections' => ['👗 DESCRIÇÃO', '📏 MEDIDAS', '🧵 MATERIAL', '✨ DIFERENCIAIS', '🚚 ENVIO'],
            'tone' => 'lifestyle e aspiracional',
            'focus' => 'estilo, conforto, tendência',
        ],
        'home' => [
            'sections' => ['🏠 SOBRE O PRODUTO', '📐 DIMENSÕES', '🎨 ACABAMENTO', '💡 DICAS DE USO', '🚚 ENVIO'],
            'tone' => 'prático e inspirador',
            'focus' => 'funcionalidade, design, durabilidade',
        ],
        'default' => [
            'sections' => ['📦 DESCRIÇÃO', '✅ CARACTERÍSTICAS', '⭐ DIFERENCIAIS', '🚚 ENVIO E GARANTIA'],
            'tone' => 'profissional e informativo',
            'focus' => 'qualidade, benefícios',
        ],
    ];
    
    // Power elements
    private const POWER_ELEMENTS = [
        'emojis' => ['✅', '⭐', '🔥', '💯', '🎯', '✨', '🛡️', '📦', '🚚', '💡'],
        'bullet_styles' => ['•', '✓', '→', '►'],
        'section_separators' => ['═══════════════════════════════', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', '───────────────────────────────'],
    ];
    
    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->aiProvider = new AIProviderManager();
        $this->itemService = new ItemService($accountId);
    }
    
    /**
     * 🚀 Otimizar descrição de um item específico
     */
    public function optimize(string $itemId): array
    {
        try {
            $item = $this->itemService->getItem($itemId);
            if (!$item || isset($item['error'])) {
                return [
                    'success' => false,
                    'error' => $item['error'] ?? 'Item não encontrado'
                ];
            }

            $currentDescription = $item['description'] ?? '';
            return $this->optimizeDescription($currentDescription, $item);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 🔥 Gerar descrição matadora
     */
    public function generateKillerDescription(array $productData): array
    {
        $category = $this->detectCategory($productData);
        $template = self::TEMPLATES[$category] ?? self::TEMPLATES['default'];
        $keywords = $this->extractSEOKeywords($productData);
        
        $prompt = $this->buildDescriptionPrompt($productData, $template, $keywords);
        
        try {
            $provider = $this->aiProvider->getPrimaryProvider();
            
            $response = $provider->chat([
                [
                    'role' => 'system',
                    'content' => $this->getSystemPrompt($template)
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ], ['temperature' => 0.8, 'max_tokens' => 2500]);
            
            if (isset($response['error'])) {
                return $this->generateFallbackDescription($productData);
            }
            
            $description = $response['content'];
            
            // Enhance description
            $description = $this->enhanceDescription($description, $keywords);
            
            // Validate
            $validation = $this->validateDescription($description);
            
            return [
                'success' => true,
                'description' => $description,
                'char_count' => mb_strlen($description),
                'word_count' => str_word_count($description),
                'keywords_included' => $this->countKeywordsInText($description, $keywords),
                'seo_score' => $validation['score'],
                'validation' => $validation,
                'sections' => $template['sections'],
                'ai_model' => $response['model'] ?? 'unknown',
            ];
            
        } catch (\Exception $e) {
            return $this->generateFallbackDescription($productData);
        }
    }
    
    /**
     * 📊 Analisar descrição existente
     */
    public function analyzeDescription(string $description): array
    {
        $issues = [];
        $strengths = [];
        $suggestions = [];
        $score = 100;
        
        $len = mb_strlen($description);
        $wordCount = str_word_count($description);
        
        // Length analysis
        if ($len < 200) {
            $issues[] = [
                'type' => 'critical',
                'issue' => 'Descrição muito curta (menos de 200 caracteres)',
                'impact' => -30
            ];
            $score -= 30;
            $suggestions[] = 'Expandir para mínimo 800-1500 caracteres';
        } elseif ($len < 500) {
            $issues[] = [
                'type' => 'warning',
                'issue' => 'Descrição curta (menos de 500 caracteres)',
                'impact' => -15
            ];
            $score -= 15;
            $suggestions[] = 'Adicionar mais detalhes e especificações';
        } elseif ($len >= 1000 && $len <= 2500) {
            $strengths[] = 'Comprimento ideal (1000-2500 caracteres)';
        }
        
        // Structure analysis
        $hasBullets = preg_match('/[•✓→►\-]\s/', $description);
        $hasEmojis = preg_match('/[\x{1F300}-\x{1F9FF}]/u', $description);
        $hasSections = preg_match('/\n\n[A-ZÁÉÍÓÚÂÊÔ📱⚡📦🛡️🚚✨👗📏🧵🏠📐🎨💡✅⭐]/', $description);
        $hasLineBreaks = substr_count($description, "\n\n") >= 2;
        
        if (!$hasBullets) {
            $issues[] = [
                'type' => 'warning',
                'issue' => 'Sem bullet points (dificulta leitura)',
                'impact' => -10
            ];
            $score -= 10;
            $suggestions[] = 'Usar bullet points para listar características';
        } else {
            $strengths[] = 'Usa bullet points';
        }
        
        if (!$hasEmojis) {
            $suggestions[] = 'Adicionar emojis estratégicos para visual atrativo';
            $score -= 5;
        } else {
            $strengths[] = 'Usa emojis';
        }
        
        if (!$hasSections) {
            $issues[] = [
                'type' => 'warning',
                'issue' => 'Falta estrutura de seções',
                'impact' => -10
            ];
            $score -= 10;
            $suggestions[] = 'Organizar em seções claras (DESCRIÇÃO, CARACTERÍSTICAS, etc)';
        } else {
            $strengths[] = 'Bem estruturada em seções';
        }
        
        // Content quality
        $hasSpecs = preg_match('/\d+\s*(cm|mm|kg|g|gb|mb|v|w|mah)/i', $description);
        $hasWarranty = preg_match('/(garantia|warranty)/i', $description);
        $hasShipping = preg_match('/(envio|frete|entrega)/i', $description);
        
        if (!$hasSpecs) {
            $suggestions[] = 'Incluir especificações técnicas com números';
        } else {
            $strengths[] = 'Inclui especificações técnicas';
        }
        
        if (!$hasWarranty) {
            $suggestions[] = 'Mencionar garantia para aumentar confiança';
        }
        
        if (!$hasShipping) {
            $suggestions[] = 'Adicionar informações de envio';
        }
        
        // Check forbidden content
        $hasForbidden = preg_match('/(whatsapp|instagram|facebook|telefone|\d{2}\s*\d{4,5}\s*\d{4})/i', $description);
        if ($hasForbidden) {
            $issues[] = [
                'type' => 'critical',
                'issue' => 'Contém informações de contato proibidas pelo ML',
                'impact' => -25
            ];
            $score -= 25;
        }
        
        return [
            'char_count' => $len,
            'word_count' => $wordCount,
            'score' => max(0, min(100, $score)),
            'grade' => $this->getGrade($score),
            'issues' => $issues,
            'strengths' => $strengths,
            'suggestions' => $suggestions,
            'structure' => [
                'has_bullets' => $hasBullets,
                'has_emojis' => $hasEmojis,
                'has_sections' => $hasSections,
                'has_specs' => $hasSpecs,
            ],
        ];
    }
    
    /**
     * ✨ Otimizar descrição existente
     */
    public function optimizeDescription(string $currentDescription, array $productData): array
    {
        $analysis = $this->analyzeDescription($currentDescription);
        
        // If already good, just enhance
        if ($analysis['score'] >= 85) {
            $enhanced = $this->enhanceDescription($currentDescription, $this->extractSEOKeywords($productData));
            return [
                'success' => true,
                'action' => 'enhanced',
                'description' => $enhanced,
                'improvement' => 5,
            ];
        }
        
        // If poor, regenerate
        if ($analysis['score'] < 50) {
            $newDesc = $this->generateKillerDescription($productData);
            return [
                'success' => $newDesc['success'],
                'action' => 'regenerated',
                'original_score' => $analysis['score'],
                'new_score' => $newDesc['seo_score'] ?? 0,
                'description' => $newDesc['description'] ?? $currentDescription,
            ];
        }
        
        // Medium quality - improve specific aspects
        $improved = $currentDescription;
        
        // Add structure if missing
        if (!$analysis['structure']['has_sections']) {
            $improved = $this->addStructure($improved, $productData);
        }
        
        // Add emojis if missing
        if (!$analysis['structure']['has_emojis']) {
            $improved = $this->addEmojis($improved);
        }
        
        // Add bullets if missing
        if (!$analysis['structure']['has_bullets']) {
            $improved = $this->addBullets($improved);
        }
        
        $newAnalysis = $this->analyzeDescription($improved);
        
        return [
            'success' => true,
            'action' => 'improved',
            'original_score' => $analysis['score'],
            'new_score' => $newAnalysis['score'],
            'improvement' => $newAnalysis['score'] - $analysis['score'],
            'description' => $improved,
        ];
    }
    
    // Private methods
    
    private function buildDescriptionPrompt(array $productData, array $template, array $keywords): string
    {
        $attrs = $this->formatAttributes($productData['attributes'] ?? []);
        
        return "Crie uma descrição MATADORA para vender no Mercado Livre.

PRODUTO:
- Título: {$productData['title']}
- Marca: " . ($productData['brand'] ?? 'N/A') . "
- Preço: R\$ " . number_format($productData['price'] ?? 0, 2, ',', '.') . "

ATRIBUTOS:
{$attrs}

KEYWORDS SEO (usar naturalmente):
" . implode(', ', array_slice($keywords, 0, 10)) . "

SEÇÕES OBRIGATÓRIAS:
" . implode("\n", $template['sections']) . "

TOM DA ESCRITA:
{$template['tone']}

FOCO PRINCIPAL:
{$template['focus']}

REGRAS:
1. Mínimo 1000 caracteres, máximo 2500
2. Usar emojis estratégicos (não exagerar)
3. Bullet points para características
4. Espaçamento entre seções
5. Keywords distribuídas naturalmente
6. Call-to-action no final
7. Mencionar garantia e envio
8. NUNCA incluir telefone, email ou redes sociais
9. Formatação limpa para mobile

Escreva a descrição completa (texto puro, não JSON):";
    }
    
    private function getSystemPrompt(array $template): string
    {
        return "Você é o copywriter #1 do Mercado Livre Brasil.
Você cria descrições que VENDEM - persuasivas, escaneáveis e otimizadas para SEO.
Tom de voz: {$template['tone']}.
Escreva descrições que fazem o cliente comprar AGORA.";
    }
    
    private function enhanceDescription(string $description, array $keywords): string
    {
        // Ensure proper line breaks
        $description = preg_replace('/\n{3,}/', "\n\n", $description);
        
        // Add emoji to section headers if missing
        $sectionPatterns = [
            '/^(DESCRIÇÃO|SOBRE|SOBRE O PRODUTO)/m' => '📦 $1',
            '/^(CARACTERÍST|FEATURES)/m' => '✅ $1',
            '/^(ESPECIFICA)/m' => '⚡ $1',
            '/^(INCLUS|O QUE VEM)/m' => '📦 $1',
            '/^(GARANTIA)/m' => '🛡️ $1',
            '/^(ENVIO|FRETE|ENTREGA)/m' => '🚚 $1',
        ];
        
        foreach ($sectionPatterns as $pattern => $replacement) {
            if (!preg_match('/[📦✅⚡🛡️🚚📱👗🏠✨]/', $description)) {
                $description = preg_replace($pattern, $replacement, $description);
            }
        }
        
        return trim($description);
    }
    
    private function validateDescription(string $description): array
    {
        $validation = $this->analyzeDescription($description);
        $validation['is_valid'] = $validation['score'] >= 60;
        return $validation;
    }
    
    private function extractSEOKeywords(array $productData): array
    {
        $keywords = [];
        
        // From title
        $title = $productData['title'] ?? '';
        $words = preg_split('/\s+/', $title);
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3 && !is_numeric($word)) {
                $keywords[] = mb_strtolower($word);
            }
        }
        
        // From attributes
        foreach ($productData['attributes'] ?? [] as $attr) {
            $value = $attr['value_name'] ?? '';
            if ($value && mb_strlen($value) >= 3) {
                $keywords[] = mb_strtolower($value);
            }
        }
        
        // Brand and model
        if (!empty($productData['brand'])) {
            $keywords[] = mb_strtolower($productData['brand']);
        }
        
        return array_unique($keywords);
    }
    
    private function countKeywordsInText(string $text, array $keywords): int
    {
        $count = 0;
        $textLower = mb_strtolower($text);
        
        foreach ($keywords as $kw) {
            if (mb_strpos($textLower, $kw) !== false) {
                $count++;
            }
        }
        
        return $count;
    }
    
    private function formatAttributes(array $attributes): string
    {
        $lines = [];
        foreach (array_slice($attributes, 0, 10) as $attr) {
            $name = $attr['name'] ?? $attr['id'] ?? '';
            $value = $attr['value_name'] ?? $attr['value'] ?? '';
            if ($name && $value) {
                $lines[] = "- {$name}: {$value}";
            }
        }
        return implode("\n", $lines) ?: "N/A";
    }
    
    private function detectCategory(array $productData): string
    {
        $title = mb_strtolower($productData['title'] ?? '');
        
        $patterns = [
            'electronics' => ['celular', 'fone', 'notebook', 'tv', 'samsung', 'apple'],
            'fashion' => ['camisa', 'calça', 'vestido', 'tênis', 'roupa'],
            'home' => ['sofá', 'mesa', 'cadeira', 'cama', 'decoração'],
        ];
        
        foreach ($patterns as $cat => $kws) {
            foreach ($kws as $kw) {
                if (mb_strpos($title, $kw) !== false) {
                    return $cat;
                }
            }
        }
        
        return 'default';
    }
    
    private function addStructure(string $text, array $productData): string
    {
        $template = self::TEMPLATES[$this->detectCategory($productData)];
        
        // Try to identify natural breaks and add headers
        $paragraphs = preg_split('/\n\n+/', $text);
        $structured = '';
        
        foreach ($paragraphs as $i => $para) {
            if ($i < count($template['sections'])) {
                $structured .= "\n\n" . $template['sections'][$i] . "\n\n";
            }
            $structured .= $para;
        }
        
        return trim($structured);
    }
    
    private function addEmojis(string $text): string
    {
        $replacements = [
            '/^(•|\-)\s/m' => '✅ ',
            '/(garantia)/i' => '🛡️ $1',
            '/(envio|frete)/i' => '🚚 $1',
            '/(qualidade)/i' => '⭐ $1',
        ];
        
        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text, 2);
        }
        
        return $text;
    }
    
    private function addBullets(string $text): string
    {
        // Find lines that look like list items and add bullets
        $lines = explode("\n", $text);
        $result = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            // If line starts with dash or is short characteristic
            if (preg_match('/^[\-\*]\s/', $line)) {
                $result[] = preg_replace('/^[\-\*]\s/', '• ', $line);
            } elseif (preg_match('/^[A-ZÁÉÍÓÚ][a-záéíóú]+:\s/', $line)) {
                $result[] = '• ' . $line;
            } else {
                $result[] = $line;
            }
        }
        
        return implode("\n", $result);
    }
    
    private function getGrade(int $score): string
    {
        if ($score >= 90) return 'A+';
        if ($score >= 80) return 'A';
        if ($score >= 70) return 'B';
        if ($score >= 60) return 'C';
        if ($score >= 50) return 'D';
        return 'F';
    }
    
    private function generateFallbackDescription(array $productData): array
    {
        $title = $productData['title'] ?? 'Produto';
        $brand = $productData['brand'] ?? '';
        
        $desc = "📦 {$title}\n\n";
        $desc .= "✅ CARACTERÍSTICAS:\n";
        
        foreach (array_slice($productData['attributes'] ?? [], 0, 5) as $attr) {
            $desc .= "• {$attr['name']}: {$attr['value_name']}\n";
        }
        
        $desc .= "\n🛡️ GARANTIA:\nProduto com garantia do vendedor.\n\n";
        $desc .= "🚚 ENVIO:\nEnvio rápido para todo Brasil.\n\n";
        $desc .= "✨ Compre agora e receba com agilidade!";
        
        return [
            'success' => true,
            'description' => $desc,
            'char_count' => mb_strlen($desc),
            'seo_score' => 60,
            'method' => 'fallback'
        ];
    }
}
