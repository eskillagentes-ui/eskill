<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\AI\SEO\AttributeKiller;
use PDO;

/**
 * Sugestões evidência-first para lacunas Hidden SEO (LINE, MPN, HANDLE_RISER, …).
 * Nunca inventa valor sem evidência no título, atributos do item ou catálogo local.
 */
class TechSheetHiddenSuggestionService
{
    public const SOURCE_HIDDEN_SEO = 'hidden_seo';

    /** Ordem de prioridade observada na conta 1335. */
    public const PRIORITY_ATTRIBUTE_IDS = [
        'LINE',
        'MPN',
        'HANDLE_RISER',
        'HANDLE_RISER_SIZE',
        'POSITION',
        'FIXING_SYSTEM',
        'MAX_WEIGHT_SUPPORTED',
        'REFLECTOR_COLOR',
        'MATERIAL',
    ];

    /** Atributos tratados por outros serviços / operacionais — não sugerir aqui. */
    public const SKIP_ATTRIBUTE_IDS = [
        'MODEL',
        'COMPATIBLE_VEHICLE_MODELS',
        'VEHICLE_MODEL',
        'MOTO_MODEL',
        'ALPHANUMERIC_MODEL',
        'GTIN',
        'EMPTY_GTIN_REASON',
        'PRODUCT_DATA_SOURCE',
        'IS_KIT',
        'AGID',
        'SELLER_SKU',
        'SELLER_PACKAGE_WIDTH',
        'SELLER_PACKAGE_LENGTH',
        'SELLER_PACKAGE_HEIGHT',
        'SELLER_PACKAGE_WEIGHT',
        'SELLER_PACKAGE_DATA_SOURCE',
        'HAS_COMPATIBILITIES',
        'PACKAGE_HEIGHT',
        'PACKAGE_WIDTH',
        'PACKAGE_LENGTH',
        'PACKAGE_WEIGHT',
        'PACKAGE_DATA_SOURCE',
    ];

    private const LINE_SIGNALS = [
        'titan', 'fan', 'start', 'biz', 'pop', 'bros', 'factor', 'fazer',
        'pcx', 'nmax', 'lander', 'crosser', 'twister', 'sahara',
        'cg', 'cb', 'xre', 'nxr', 'ybr', 'mt', 'ninja',
    ];

    private PDO $db;
    private int $accountId;
    private TechSheetService $techSheet;
    private AttributeKiller $attributeKiller;

    public function __construct(
        int $accountId,
        ?TechSheetService $techSheet = null,
        ?AttributeKiller $attributeKiller = null
    ) {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->techSheet = $techSheet ?? new TechSheetService($accountId);
        $this->attributeKiller = $attributeKiller ?? new AttributeKiller($accountId);
    }

    /**
     * @param list<string> $skipAttributeIds já processados no generate
     * @return array{
     *   success:bool,
     *   created:int,
     *   skipped:int,
     *   suggestions:list<array>,
     *   skips:list<array{attribute_id:string,reason:string}>,
     *   dry_run?:bool,
     *   error?:string
     * }
     */
    public function suggestForItem(
        string $itemId,
        array $categoryAttributes,
        array $itemData,
        string $categoryId,
        string $title = '',
        array $skipAttributeIds = [],
        bool $dryRun = false
    ): array {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return ['success' => false, 'error' => 'item_id inválido', 'created' => 0, 'skipped' => 0, 'suggestions' => [], 'skips' => []];
        }

        $gapsResult = $this->attributeKiller->analyzeGaps($itemId, $categoryId, $itemData);
        $hiddenGaps = $gapsResult['gaps']['hidden'] ?? [];
        if (!is_array($hiddenGaps) || $hiddenGaps === []) {
            return [
                'success' => true,
                'created' => 0,
                'skipped' => 0,
                'suggestions' => [],
                'skips' => [['attribute_id' => '*', 'reason' => 'no_hidden_gaps']],
                'dry_run' => $dryRun,
            ];
        }

        $skipSet = array_fill_keys(array_map('strval', $skipAttributeIds), true);
        $orderedGaps = $this->orderGaps($hiddenGaps);

        $created = 0;
        $skipped = 0;
        $suggestions = [];
        $skips = [];

        foreach ($orderedGaps as $gap) {
            $attributeId = (string)($gap['id'] ?? '');
            if ($attributeId === '' || isset($skipSet[$attributeId])) {
                continue;
            }
            if (in_array($attributeId, self::SKIP_ATTRIBUTE_IDS, true)) {
                $skipped++;
                $skips[] = ['attribute_id' => $attributeId, 'reason' => 'handled_elsewhere_or_ops'];
                continue;
            }
            if (!$this->attributeKiller->isHiddenSeoAttribute($attributeId)) {
                $skipped++;
                $skips[] = ['attribute_id' => $attributeId, 'reason' => 'not_hidden_seo'];
                continue;
            }

            $catAttr = $this->findCategoryAttribute($categoryAttributes, $attributeId);
            if ($catAttr === null) {
                $skipped++;
                $skips[] = ['attribute_id' => $attributeId, 'reason' => 'attr_not_in_category'];
                continue;
            }

            $tags = $catAttr['tags'] ?? [];
            if (!$this->attributeKiller->isSellerFillable(is_array($tags) ? $tags : [])) {
                $skipped++;
                $skips[] = ['attribute_id' => $attributeId, 'reason' => 'not_seller_fillable'];
                continue;
            }

            if ($this->isVariationOnlyAndItemHasVariations($catAttr, $itemData)) {
                $skipped++;
                $skips[] = ['attribute_id' => $attributeId, 'reason' => 'variation_attribute_with_variations'];
                continue;
            }

            $existing = $this->findOpenSuggestion($itemId, $attributeId);
            if ($existing !== null) {
                $skipped++;
                $skips[] = ['attribute_id' => $attributeId, 'reason' => 'idempotent_existing'];
                continue;
            }

            $candidate = $this->resolveCandidate($attributeId, $title, $itemData, $catAttr, $categoryId, $gap);
            if ($candidate === null) {
                $skipped++;
                $skips[] = ['attribute_id' => $attributeId, 'reason' => 'no_evidence'];
                continue;
            }

            if (($candidate['confidence'] ?? 0) < 60) {
                $skipped++;
                $skips[] = ['attribute_id' => $attributeId, 'reason' => 'low_confidence'];
                continue;
            }

            $payload = [
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'category_id' => $categoryId,
                'attribute_id' => $attributeId,
                'attribute_name' => (string)($gap['name'] ?? $catAttr['name'] ?? $attributeId),
                'suggested_value' => $candidate['value'],
                'source' => self::SOURCE_HIDDEN_SEO,
                'confidence' => (int)$candidate['confidence'],
                'status' => 'pending',
                'meta' => [
                    'policy' => 'hidden_seo_evidence',
                    'evidence_source' => $candidate['evidence_source'],
                    'evidence' => $candidate['evidence'] ?? null,
                    // Doc ML: boolean exige value_id; list/number_unit tipados
                    // https://developers.mercadolivre.com.br/pt_br/atributos
                    'value_type' => (string)($catAttr['value_type'] ?? ($gap['value_type'] ?? '')),
                    'value_id' => $this->resolveValueIdFromCategoryAttr($catAttr, $candidate['value']),
                    'ml_doc' => 'https://developers.mercadolivre.com.br/pt_br/atributos',
                ],
            ];

            $valueType = (string)($payload['meta']['value_type'] ?? '');
            if ($valueType === 'boolean' && empty($payload['meta']['value_id'])) {
                $skipped++;
                $skips[] = ['attribute_id' => $attributeId, 'reason' => 'boolean_without_value_id'];
                continue;
            }

            if ($dryRun) {
                $suggestions[] = $payload;
                $created++;
                $skipSet[$attributeId] = true;
                continue;
            }

            $ok = $this->techSheet->persistSuggestion($payload);
            if ($ok) {
                $suggestions[] = $payload;
                $created++;
                $skipSet[$attributeId] = true;
            } else {
                $skipped++;
                $skips[] = ['attribute_id' => $attributeId, 'reason' => 'persist_failed'];
            }
        }

        return [
            'success' => true,
            'created' => $created,
            'skipped' => $skipped,
            'suggestions' => $suggestions,
            'skips' => $skips,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param list<array> $gaps
     * @return list<array>
     */
    private function orderGaps(array $gaps): array
    {
        $priorityRank = array_flip(self::PRIORITY_ATTRIBUTE_IDS);
        usort($gaps, static function (array $a, array $b) use ($priorityRank): int {
            $idA = (string)($a['id'] ?? '');
            $idB = (string)($b['id'] ?? '');
            $rankA = $priorityRank[$idA] ?? 1000;
            $rankB = $priorityRank[$idB] ?? 1000;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            return strcmp($idA, $idB);
        });
        return $gaps;
    }

    /**
     * @return array{value:string,confidence:int,evidence_source:string,evidence?:string}|null
     */
    private function resolveCandidate(
        string $attributeId,
        string $title,
        array $itemData,
        array $catAttr,
        string $categoryId,
        array $gap
    ): ?array {
        $fromTitle = $this->extractFromTitle($attributeId, $title, $catAttr, $gap);
        if ($fromTitle !== null) {
            return $fromTitle;
        }

        $fromItem = $this->extractFromSameItem($attributeId, $itemData, $catAttr);
        if ($fromItem !== null) {
            return $fromItem;
        }

        $fromCatalog = $this->extractFromLocalCatalog($attributeId, $categoryId, $title, $catAttr);
        if ($fromCatalog !== null) {
            return $fromCatalog;
        }

        return null;
    }

    /**
     * @return array{value:string,confidence:int,evidence_source:string,evidence?:string}|null
     */
    private function extractFromTitle(string $attributeId, string $title, array $catAttr, array $gap): ?array
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }

        $allowed = $this->allowedValueNames($catAttr, $gap);

        return match ($attributeId) {
            'LINE' => $this->extractLineFromTitle($title),
            'MPN' => $this->extractMpnFromTitle($title),
            'HANDLE_RISER' => $this->extractHandleRiserFromTitle($title, $allowed),
            'HANDLE_RISER_SIZE' => $this->extractHandleRiserSizeFromTitle($title),
            'POSITION', 'FIXING_SYSTEM', 'REFLECTOR_COLOR', 'MATERIAL' => $this->matchAllowedInTitle($title, $allowed),
            'MAX_WEIGHT_SUPPORTED' => $this->extractWeightFromTitle($title),
            default => $this->matchAllowedInTitle($title, $allowed),
        };
    }

    /**
     * @return array{value:string,confidence:int,evidence_source:string,evidence?:string}|null
     */
    private function extractLineFromTitle(string $title): ?array
    {
        $found = [];
        $titleLower = mb_strtolower($title);
        foreach (self::LINE_SIGNALS as $line) {
            if (preg_match('/\b' . preg_quote($line, '/') . '\b/u', $titleLower)) {
                $found[] = $line;
            }
        }
        $found = array_values(array_unique($found));
        if (count($found) !== 1) {
            return null;
        }

        $raw = $found[0];
        $canonical = mb_strtoupper(mb_substr($raw, 0, 1)) . mb_strtolower(mb_substr($raw, 1));
        $acronyms = ['cg', 'cb', 'mt', 'pcx', 'nxr', 'xre', 'ybr'];
        if (in_array($raw, $acronyms, true)) {
            $canonical = mb_strtoupper($raw);
        }

        return [
            'value' => $canonical,
            'confidence' => 88,
            'evidence_source' => 'title',
            'evidence' => $raw,
        ];
    }

    /**
     * @return array{value:string,confidence:int,evidence_source:string,evidence?:string}|null
     */
    private function extractMpnFromTitle(string $title): ?array
    {
        // Preferir rótulo explícito (MPN:/REF:/CÓD:)
        if (preg_match('/\b(?:PN|MPN|CÓD|COD|CODIGO|CÓDIGO|REF)[:\s#\-]*([A-Z0-9][\w\-\/]{3,35})\b/iu', $title, $m)) {
            $code = strtoupper(trim($m[1]));
            if ($this->looksLikeMpn($code)) {
                return [
                    'value' => $code,
                    'confidence' => 90,
                    'evidence_source' => 'title',
                    'evidence' => $m[0],
                ];
            }
        }

        // Códigos tipo ABC-1234 / STG-MPN-15-FAN125 (até 4 segmentos)
        if (preg_match('/\b([A-Z]{1,8}(?:[\-\/][A-Z0-9]{1,12}){1,4})\b/u', $title, $m)) {
            $code = strtoupper($m[1]);
            if ($this->looksLikeMpn($code)) {
                return [
                    'value' => $code,
                    'confidence' => 85,
                    'evidence_source' => 'title',
                    'evidence' => $m[1],
                ];
            }
        }
        return null;
    }

    /**
     * @param list<string> $allowed
     * @return array{value:string,confidence:int,evidence_source:string,evidence?:string}|null
     */
    private function extractHandleRiserFromTitle(string $title, array $allowed): ?array
    {
        $lower = mb_strtolower($title);
        $no = preg_match('/\b(sem\s+riser|sem\s+alongador|sem\s+eleva[cç][aã]o)\b/u', $lower) === 1;
        $yes = !$no && preg_match('/\b(riser|alongador(?:\s+de\s+guid[aã]o)?|guid[aã]o\s+alto)\b/u', $lower) === 1;

        if ($yes) {
            $value = $this->pickBooleanLabel($allowed, true);
            return [
                'value' => $value,
                'confidence' => 86,
                'evidence_source' => 'title',
                'evidence' => 'riser_positive',
            ];
        }
        if ($no) {
            $value = $this->pickBooleanLabel($allowed, false);
            return [
                'value' => $value,
                'confidence' => 86,
                'evidence_source' => 'title',
                'evidence' => 'riser_negative',
            ];
        }
        return null;
    }

    /**
     * @return array{value:string,confidence:int,evidence_source:string,evidence?:string}|null
     */
    private function extractHandleRiserSizeFromTitle(string $title): ?array
    {
        if (preg_match('/\b(?:riser|alongador|eleva[cç][aã]o)\b[^0-9]{0,20}(\d{1,3})\s*(mm|cm)?\b/iu', $title, $m)) {
            $n = (int)$m[1];
            $unit = strtolower($m[2] ?? 'mm');
            if ($unit === '') {
                $unit = 'mm';
            }
            if ($n >= 5 && $n <= 300) {
                return [
                    'value' => $n . ' ' . $unit,
                    'confidence' => 82,
                    'evidence_source' => 'title',
                    'evidence' => $m[0],
                ];
            }
        }
        return null;
    }

    /**
     * @return array{value:string,confidence:int,evidence_source:string,evidence?:string}|null
     */
    private function extractWeightFromTitle(string $title): ?array
    {
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(kg|g)\b/iu', $title, $m)) {
            $n = str_replace(',', '.', $m[1]);
            $unit = strtolower($m[2]);
            return [
                'value' => $n . ' ' . $unit,
                'confidence' => 80,
                'evidence_source' => 'title',
                'evidence' => $m[0],
            ];
        }
        return null;
    }

    /**
     * @param list<string> $allowed
     * @return array{value:string,confidence:int,evidence_source:string,evidence?:string}|null
     */
    private function matchAllowedInTitle(string $title, array $allowed): ?array
    {
        if ($allowed === []) {
            return null;
        }
        $matches = [];
        foreach ($allowed as $name) {
            $name = trim($name);
            if ($name === '' || mb_strlen($name) < 2) {
                continue;
            }
            $escaped = preg_quote($name, '/');
            if (preg_match('/\b' . $escaped . '\b/iu', $title)) {
                $matches[] = $name;
            }
        }
        $matches = array_values(array_unique($matches));
        if (count($matches) !== 1) {
            return null;
        }
        return [
            'value' => $matches[0],
            'confidence' => 92,
            'evidence_source' => 'title',
            'evidence' => $matches[0],
        ];
    }

    /**
     * @return array{value:string,confidence:int,evidence_source:string,evidence?:string}|null
     */
    private function extractFromSameItem(string $attributeId, array $itemData, array $catAttr): ?array
    {
        $attrs = $itemData['attributes'] ?? [];
        if (!is_array($attrs)) {
            return null;
        }

        $byId = [];
        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $id = (string)($attr['id'] ?? '');
            $name = trim((string)($attr['value_name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $byId[$id] = $name;
            }
        }

        if ($attributeId === 'MPN') {
            foreach (['SELLER_SKU', 'PART_NUMBER', 'OEM', 'MANUFACTURER_PART_NUMBER'] as $srcId) {
                if (!isset($byId[$srcId])) {
                    continue;
                }
                $code = trim($byId[$srcId]);
                if ($this->looksLikeMpn($code)) {
                    return [
                        'value' => $code,
                        'confidence' => 88,
                        'evidence_source' => 'same_item',
                        'evidence' => $srcId . '=' . $code,
                    ];
                }
            }
        }

        if ($attributeId === 'LINE' && isset($byId['MODEL'])) {
            $model = $byId['MODEL'];
            $line = $this->extractLineFromTitle($model);
            if ($line !== null) {
                $line['evidence_source'] = 'same_item';
                $line['evidence'] = 'MODEL=' . $model;
                $line['confidence'] = min(80, (int)$line['confidence']);
                return $line;
            }
        }

        $allowed = $this->allowedValueNames($catAttr, []);
        if ($allowed !== [] && isset($byId['BRAND'])) {
            // não mapear BRAND → LINE/outros sem evidência específica
        }

        return null;
    }

    /**
     * @return array{value:string,confidence:int,evidence_source:string,evidence?:string}|null
     */
    private function extractFromLocalCatalog(
        string $attributeId,
        string $categoryId,
        string $title,
        array $catAttr
    ): ?array {
        if ($title === '' || $categoryId === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT title, data FROM items
             WHERE account_id = :account_id AND category_id = :category_id AND status = 'active'
             ORDER BY updated_at DESC
             LIMIT 80"
        );
        $stmt->execute([
            ':account_id' => $this->accountId,
            ':category_id' => $categoryId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }

        $titleTokens = $this->tokenize($title);
        $best = null;
        $bestScore = 0.0;

        foreach ($rows as $row) {
            $data = json_decode((string)($row['data'] ?? ''), true);
            if (!is_array($data)) {
                continue;
            }
            $value = $this->readAttributeValue($data, $attributeId);
            if ($value === null || $value === '') {
                continue;
            }
            if ($attributeId === 'MPN' && !$this->looksLikeMpn($value)) {
                continue;
            }

            $otherTokens = $this->tokenize((string)($row['title'] ?? ''));
            $score = $this->jaccard($titleTokens, $otherTokens);
            if ($score < 0.35) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $value;
            }
        }

        if ($best === null) {
            return null;
        }

        $allowed = $this->allowedValueNames($catAttr, []);
        if ($allowed !== [] && !in_array($best, $allowed, true)) {
            // tentar match case-insensitive
            $matched = null;
            foreach ($allowed as $a) {
                if (mb_strtolower($a) === mb_strtolower($best)) {
                    $matched = $a;
                    break;
                }
            }
            if ($matched === null) {
                return null;
            }
            $best = $matched;
        }

        return [
            'value' => $best,
            'confidence' => (int)min(78, 55 + (int)round($bestScore * 30)),
            'evidence_source' => 'local_catalog',
            'evidence' => 'similarity=' . round($bestScore, 2),
        ];
    }

    /**
     * @param list<string> $allowed
     */
    private function pickBooleanLabel(array $allowed, bool $yes): string
    {
        $preferYes = ['sim', 'yes', 'true', '1'];
        $preferNo = ['não', 'nao', 'no', 'false', '0'];
        foreach ($allowed as $name) {
            $l = mb_strtolower(trim($name));
            if ($yes && in_array($l, $preferYes, true)) {
                return $name;
            }
            if (!$yes && in_array($l, $preferNo, true)) {
                return $name;
            }
        }
        return $yes ? 'Sim' : 'Não';
    }

    public function looksLikeMpn(string $value): bool
    {
        $value = trim($value);
        $len = mb_strlen($value);
        if ($len < 4 || $len > 40) {
            return false;
        }
        if (!preg_match('/[A-Za-z]/', $value) || !preg_match('/[0-9]/', $value)) {
            return false;
        }
        $blocked = ['honda', 'yamaha', 'suzuki', 'original', 'universal', 'kit', 'frete'];
        $lower = mb_strtolower($value);
        foreach ($blocked as $b) {
            if ($lower === $b) {
                return false;
            }
        }
        // Evitar títulos longos colados
        if (substr_count($value, ' ') > 2) {
            return false;
        }
        return true;
    }

    private function isVariationOnlyAndItemHasVariations(array $catAttr, array $itemData): bool
    {
        $tags = $catAttr['tags'] ?? [];
        $isVar = is_array($tags) && (
            (!empty($tags['variation_attribute']))
            || in_array('variation_attribute', $tags, true)
        );
        if (!$isVar) {
            return false;
        }
        return !empty($itemData['variations']) && is_array($itemData['variations']);
    }

    /**
     * @return list<string>
     */
    private function allowedValueNames(array $catAttr, array $gap): array
    {
        $fromGap = $gap['allowed_values'] ?? [];
        if (is_array($fromGap) && $fromGap !== []) {
            $out = [];
            foreach ($fromGap as $v) {
                if (is_string($v) && $v !== '') {
                    $out[] = $v;
                } elseif (is_array($v) && !empty($v['name'])) {
                    $out[] = (string)$v['name'];
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        $values = $catAttr['values'] ?? [];
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $v) {
            if (is_array($v) && !empty($v['name'])) {
                $out[] = (string)$v['name'];
            }
        }
        return $out;
    }

    private function findCategoryAttribute(array $categoryAttributes, string $attributeId): ?array
    {
        foreach ($categoryAttributes as $attr) {
            if (($attr['id'] ?? '') === $attributeId) {
                return is_array($attr) ? $attr : null;
            }
        }
        return null;
    }

    /**
     * Resolve value_id a partir dos values da categoria (obrigatório p/ boolean na API ML).
     */
    public function resolveValueIdFromCategoryAttr(array $catAttr, string $valueName): ?string
    {
        $values = $catAttr['values'] ?? [];
        if (!is_array($values)) {
            return null;
        }
        $normalized = mb_strtolower(trim($valueName));
        foreach ($values as $v) {
            if (!is_array($v)) {
                continue;
            }
            if (mb_strtolower(trim((string)($v['name'] ?? ''))) === $normalized) {
                $id = $v['id'] ?? null;
                return $id !== null && $id !== '' ? (string)$id : null;
            }
        }
        return null;
    }

    private function findOpenSuggestion(string $itemId, string $attributeId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, attribute_id, suggested_value, source, confidence, status, meta
             FROM tech_sheet_suggestions
             WHERE account_id = :account_id AND item_id = :item_id
               AND attribute_id = :attribute_id
               AND status IN ('pending', 'approved')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([
            ':account_id' => $this->accountId,
            ':item_id' => $itemId,
            ':attribute_id' => $attributeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function readAttributeValue(array $itemData, string $attributeId): ?string
    {
        foreach (($itemData['attributes'] ?? []) as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            if (($attr['id'] ?? '') !== $attributeId) {
                continue;
            }
            $name = trim((string)($attr['value_name'] ?? ''));
            return $name !== '' ? $name : null;
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if ($p !== '' && mb_strlen($p) >= 2) {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $setA = array_fill_keys($a, true);
        $setB = array_fill_keys($b, true);
        $inter = 0;
        foreach ($setA as $k => $_) {
            if (isset($setB[$k])) {
                $inter++;
            }
        }
        $union = count($setA) + count($setB) - $inter;
        return $union > 0 ? $inter / $union : 0.0;
    }
}
