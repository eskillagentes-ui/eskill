<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Política de MODEL como identificador limpo (não área de keyword SEO).
 * Sinais de mineração (trends/autocomplete) alimentam score — o valor gravado
 * deve ser identificação curta e canônica.
 */
class TechSheetModelSuggestionPolicy
{
    public const MODEL_ATTRIBUTE_IDS = [
        'MODEL',
        'COMPATIBLE_VEHICLE_MODELS',
        'VEHICLE_MODEL',
        'MOTO_MODEL',
        'ALPHANUMERIC_MODEL',
    ];

    /** Fontes de mineração: não viram valor bruto sem passar pela limpeza. */
    public const MINING_SOURCES = [
        'autocomplete',
        'trends',
        'ml_keyword_api',
        'inferred_type',
    ];

    /** Tokens comerciais / stuffing que desqualificam o valor de MODEL. */
    private const BLOCKED_TOKENS = [
        'barato', 'barata', 'original', 'kit', 'envio', 'frete', 'promocao', 'promoção',
        'oferta', 'semi', 'novo', 'nova', 'usado', 'usada', 'completo', 'completa',
        'jogo', 'conjunto', 'qualidade', 'premium', 'importado', 'nacional',
        'atacado', 'varejo', 'melhor', 'preco', 'preço', 'desconto', 'gratis', 'grátis',
        'capacete', 'pecas', 'peças', 'peca', 'peça', 'acessorio', 'acessório',
        'universal', 'compativel', 'compatível', 'para', 'todas', 'diversas',
        'motos', 'moto', 'honda', 'yamaha', 'suzuki', 'kawasaki', // marca sozinha ≠ modelo
    ];

    public function isModelAttribute(string $attributeId): bool
    {
        return in_array($attributeId, self::MODEL_ATTRIBUTE_IDS, true);
    }

    /**
     * Normaliza espaços e capitalização leve (preserva siglas curtas).
     */
    public function normalize(string $raw): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        $value = trim($value, " \t\n\r\0\x0B\"'-");
        if ($value === '') {
            return '';
        }

        // Fan160 / CG160 → Fan 160 / CG 160 (não quebrar F800 / R1200)
        $value = preg_replace('/([A-Za-zÀ-ÿ]{2,})(\d)/u', '$1 $2', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        // Remover tokens comerciais embutidos mantendo o resto
        $parts = preg_split('/\s+/u', $value) ?: [];
        $kept = [];
        foreach ($parts as $part) {
            $lower = mb_strtolower($part);
            $lowerBare = preg_replace('/[^\p{L}\p{N}]+/u', '', $lower) ?? $lower;
            if (in_array($lowerBare, self::BLOCKED_TOKENS, true)) {
                continue;
            }
            $kept[] = $part;
        }
        $value = trim(implode(' ', $kept));

        return $value;
    }

    /**
     * True se o valor parece identificador limpo de modelo (não query de busca).
     */
    public function isCleanIdentifier(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $len = mb_strlen($value);
        if ($len < 2 || $len > 40) {
            return false;
        }

        // Ano isolado (ex. "2025") não é MODEL
        if (preg_match('/^20[0-9]{2}$/', $value)) {
            return false;
        }

        $words = preg_split('/\s+/u', $value) ?: [];
        if (count($words) > 4) {
            return false;
        }

        $lower = mb_strtolower($value);
        if (preg_match('/[|,;\/\\\\]|https?:/u', $lower)) {
            return false;
        }

        foreach ($words as $word) {
            $bare = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($word)) ?? '';
            if ($bare !== '' && in_array($bare, self::BLOCKED_TOKENS, true)) {
                return false;
            }
            // Token que é só ano
            if (preg_match('/^20[0-9]{2}$/', $bare)) {
                return false;
            }
        }

        // Só marca (ex. "Honda") sem linha/cilindrada → ambíguo demais
        $brandOnly = ['honda', 'yamaha', 'suzuki', 'kawasaki', 'bmw', 'ducati', 'triumph', 'harley', 'ktm'];
        if (count($words) === 1 && in_array(mb_strtolower($words[0]), $brandOnly, true)) {
            return false;
        }

        // Precisa ter pelo menos uma letra e preferencialmente dígito OU nome de linha conhecido
        if (!preg_match('/\p{L}/u', $value)) {
            return false;
        }

        return true;
    }

    /**
     * Título lista vários modelos compatíveis → não forçar um único MODEL.
     */
    public function isAmbiguousMultiModel(string $title, int $minDistinct = 3): bool
    {
        return count($this->listModelLineSignals($title)) >= $minDistinct;
    }

    /**
     * @return list<string> linhas/modelos distintos detectados no texto
     */
    public function listModelLineSignals(string $text): array
    {
        $textLower = mb_strtolower($text);
        $lines = [
            'titan', 'fan', 'start', 'biz', 'pop', 'bros', 'factor', 'fazer',
            'pcx', 'nmax', 'lander', 'crosser', 'twister', 'sahara',
            'cg', 'cb', 'xre', 'nxr', 'ybr', 'mt', 'ninja',
        ];
        $found = [];
        foreach ($lines as $line) {
            if (preg_match('/\b' . preg_quote($line, '/') . '\b/u', $textLower)) {
                $found[] = $line;
            }
        }
        return array_values(array_unique($found));
    }

    /**
     * Cilindrada dominante no título (ex. 160, 125).
     */
    public function extractDisplacement(string $title): ?string
    {
        if (preg_match('/\b(1[0-9]{2}|[2-9][0-9]{2}|[1-9][0-9]{3})\b/u', $title, $m)) {
            $n = (int)$m[1];
            // faixas típicas de moto / peças
            if ($n >= 50 && $n <= 1300) {
                return (string)$n;
            }
        }
        return null;
    }

    /**
     * Quando o título lista várias linhas da mesma família, devolve MODEL guarda-chuva limpo.
     * Ex.: "CG 160 Titan Fan Start" → "CG 160".
     *
     * @return array{value:string,family:string,signals:list<string>,displacement:?string}|null
     */
    public function resolveUmbrellaModel(string $title): ?array
    {
        $signals = $this->listModelLineSignals($title);
        if (count($signals) < 2) {
            return null;
        }

        $displacement = $this->extractDisplacement($title);
        $families = [
            'cg' => ['cg', 'titan', 'fan', 'start'],
            'bros' => ['bros', 'nxr'],
            'biz' => ['biz', 'pop'],
            'cb' => ['cb', 'twister'],
        ];

        foreach ($families as $family => $members) {
            $overlap = array_values(array_intersect($signals, $members));
            if (count($overlap) < 2) {
                continue;
            }

            $raw = match ($family) {
                'cg', 'bros', 'biz', 'cb' => $displacement !== null
                    ? (match ($family) {
                        'cg' => 'CG ' . $displacement,
                        'bros' => 'Bros ' . $displacement,
                        'biz' => 'Biz ' . $displacement,
                        'cb' => 'CB ' . $displacement,
                    })
                    : null,
                default => null,
            };
            if ($raw === null) {
                continue;
            }

            // "CG" / "Biz" sozinhos sem cilindrada → ambíguo demais
            $clean = $this->cleanCandidate($raw, $title);
            if ($clean === null) {
                continue;
            }

            return [
                'value' => $clean,
                'family' => $family,
                'signals' => $overlap,
                'displacement' => $displacement,
            ];
        }

        return null;
    }

    /**
     * Limpa candidato; retorna null se rejeitado / ambíguo.
     */
    public function cleanCandidate(string $raw, string $title = ''): ?string
    {
        $normalized = $this->normalize($raw);
        if ($normalized === '') {
            return null;
        }

        if ($this->isCleanIdentifier($normalized)) {
            return $this->canonicalCase($normalized);
        }

        // Tentar extrair identificador curto de um texto longo (query stuffing)
        $extracted = $this->extractIdentifierFromNoise($normalized, $title);
        if ($extracted !== null && $this->isCleanIdentifier($extracted)) {
            return $this->canonicalCase($extracted);
        }

        return null;
    }

    /**
     * Filtra e re-ranqueia candidatos. Fontes de mineração só sobem score;
     * valor final deve ser identificador limpo.
     *
     * @param list<array{value:string,score?:int|float,sources?:list<string>,inferred_signal_score?:int,brand?:string}> $candidates
     * @return list<array{value:string,score:int,sources:list<string>,inferred_signal_score:int,brand?:string,policy:string}>
     */
    public function selectBest(array $candidates, string $title = ''): array
    {
        $titleLower = mb_strtolower($title);
        $bucket = [];

        foreach ($candidates as $candidate) {
            $raw = (string)($candidate['value'] ?? '');
            $sources = array_values(array_unique(array_map('strval', $candidate['sources'] ?? [])));
            $score = (int)($candidate['score'] ?? 0);
            // Sinal derivado (posição em autocomplete/trends), não é volume de busca
            // real — ver TechSheetService::estimatePositionRelevanceScore().
            $inferredSignalScore = (int)($candidate['inferred_signal_score'] ?? 0);

            $clean = $this->cleanCandidate($raw, $title);
            if ($clean === null) {
                continue;
            }

            $onlyMining = $sources !== [] && $this->sourcesAreOnlyMining($sources);
            $inTitle = $titleLower !== '' && (
                str_contains($titleLower, mb_strtolower($clean))
                || str_contains(
                    preg_replace('/[\s\-]+/u', '', $titleLower) ?? '',
                    preg_replace('/[\s\-]+/u', '', mb_strtolower($clean)) ?? ''
                )
            );

            $fromTrusted = in_array('title', $sources, true)
                || in_array('local_catalog', $sources, true)
                || in_array('same_category', $sources, true)
                || in_array('competitors', $sources, true);

            if ($onlyMining && !$inTitle && !$fromTrusted) {
                // Sinal de linguagem do cliente → não grava como MODEL
                continue;
            }

            if ($fromTrusted) {
                $score += 15;
            }
            if ($inTitle) {
                $score += 10;
            }

            $key = mb_strtolower($clean);
            if (!isset($bucket[$key]) || $score > $bucket[$key]['score']) {
                $entry = [
                    'value' => $clean,
                    'score' => $score,
                    'sources' => $sources,
                    'inferred_signal_score' => $inferredSignalScore,
                    'policy' => 'semantic_clean',
                ];
                if (!empty($candidate['brand'])) {
                    $entry['brand'] = (string)$candidate['brand'];
                }
                $bucket[$key] = $entry;
            } else {
                $bucket[$key]['sources'] = array_values(array_unique(array_merge(
                    $bucket[$key]['sources'],
                    $sources
                )));
                $bucket[$key]['inferred_signal_score'] = max($bucket[$key]['inferred_signal_score'], $inferredSignalScore);
            }
        }

        $list = array_values($bucket);
        usort($list, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return $list;
    }

    /**
     * @param list<string> $sources
     */
    public function sourcesAreOnlyMining(array $sources): bool
    {
        if ($sources === []) {
            return false;
        }
        foreach ($sources as $source) {
            if (!in_array($source, self::MINING_SOURCES, true)) {
                return false;
            }
        }
        return true;
    }

    private function canonicalCase(string $value): string
    {
        $acronyms = [
            'cg', 'cb', 'xr', 'nx', 'nxr', 'pcx', 'mt', 'yzf', 'xtz', 'fz', 'ybr',
            'zx', 'gs', 'rr', 'bmw', 'ktm', 'nc',
        ];
        $parts = preg_split('/\s+/u', $value) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $lower = mb_strtolower($part);
            if (preg_match('/^\d/', $part)) {
                $out[] = mb_strtoupper($part);
                continue;
            }
            if (in_array($lower, $acronyms, true)) {
                $out[] = mb_strtoupper($part);
                continue;
            }
            $out[] = mb_strtoupper(mb_substr($part, 0, 1)) . mb_strtolower(mb_substr($part, 1));
        }
        return implode(' ', $out);
    }

    private function extractIdentifierFromNoise(string $text, string $title): ?string
    {
        $patterns = [
            '/\b((?:CG|CB|XR|NX|PCX|BIZ|POP|BROS|FAN|TITAN|START)\s*\d{2,4}(?:\s*(?:Titan|Fan|Start|Cargo))?)\b/iu',
            '/\b((?:MT|YZF|XTZ|FZ|FAZER|YBR|CROSSER|LANDER|NMAX|FACTOR)\s*[-]?\s*\d{2,4})\b/iu',
            '/\b((?:Z|ZX|NINJA|VULCAN|VERSYS)\s*[-]?\s*\d{2,4})\b/iu',
            '/\b([FGRSK]\s*\d{3,4}(?:\s*[A-Z]{1,2})?)\b/iu',
            '/\b((?:Titan|Fan|Biz|Pop|Factor|Fazer|Bros)\s*\d{2,4})\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return trim(preg_replace('/\s+/u', ' ', $m[1]) ?? $m[1]);
            }
        }

        if ($title !== '' && preg_match($patterns[0], $title, $m)) {
            return trim(preg_replace('/\s+/u', ' ', $m[1]) ?? $m[1]);
        }

        return null;
    }
}
