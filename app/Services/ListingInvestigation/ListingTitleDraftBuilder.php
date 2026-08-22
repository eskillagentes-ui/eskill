<?php

declare(strict_types=1);

namespace App\Services\ListingInvestigation;

/**
 * Local title draft from official item attributes only.
 * Never invents brand, never copies stuffed MODEL, never applies to Mercado Livre.
 */
final class ListingTitleDraftBuilder
{
    public const TITLE_MAX = 60;

    private const DOMAIN_PRODUCT = [
        'MLB-MIRRORS' => 'Espelho',
        'MLB-MOTORCYCLE_HANDLEBARS' => 'Guidão',
        'MLB-MOTORCYCLE_LUGGAGE_RACKS' => 'Bagageiro',
        'MLB-MOTORCYCLE_STANDS' => 'Cavalete',
        'MLB-DOOR_AND_STAIR_SAFETY_FENCES' => 'Grade de segurança',
        'MLB-MOTORCYCLE_HEADLIGHTS' => 'Farol',
        'MLB-MOTORCYCLE_REARVIEW_MIRRORS' => 'Retrovisor',
    ];

    private const GENERIC_BRANDS = [
        'espelho', 'generico', 'genérico', 'sem marca', 'marca propria', 'marca própria',
        'nao tem', 'não tem', 'n/a', 'n/d',
    ];

    /**
     * @param array<string, mixed> $item
     * @param list<array{code:string,label:string}> $blockers
     * @return array{draft_title:string, draft_notes:string}
     */
    public function build(array $item, array $blockers, ?string $realModel): array
    {
        $product = $this->productType($item);
        $brand = $this->realBrand($item, $product);
        $model = $this->modelForTitle($item, $realModel, $product, $brand);
        $spec = $this->oneSpec($item, $product, $brand, $model);

        $bits = [];
        foreach ([$product, $brand, $model, $spec] as $bit) {
            $bit = $this->cleanFragment((string) $bit);
            if ($bit === '') {
                continue;
            }
            $bits[] = $bit;
        }
        $title = $this->clipTitle(implode(' ', $bits));
        if ($this->isAutoParts($item)) {
            $title = $this->stripBikeList($title, $model);
        }
        $title = $this->stripPlaceholders($title);
        if ($title === '') {
            $title = $product !== '' ? $product : 'Peça';
        }

        $codes = array_column($blockers, 'code');
        $notes = 'rules: blockers=' . implode(',', $codes);
        if ($this->isAutoParts($item)) {
            $notes .= ' · compatibilidade no widget, não lista de motos no título';
        }
        $notes .= ' · MODEL=' . ($realModel ?: 'n/d (não inventar)');
        $notes .= ' · brand=' . ($brand ?: 'n/d');

        return ['draft_title' => $title, 'draft_notes' => $notes];
    }

    /**
     * @param array<string, mixed> $item
     */
    public function productType(array $item): string
    {
        $domain = strtoupper(trim((string) ($item['domain_id'] ?? '')));
        if ($domain !== '' && isset(self::DOMAIN_PRODUCT[$domain])) {
            return self::DOMAIN_PRODUCT[$domain];
        }
        foreach (['ITEM_TYPE', 'PRODUCT_TYPE', 'ITEM_NAME'] as $id) {
            $v = $this->usableAttribute($item, $id);
            if ($v !== null) {
                return $this->titleCaseWords($v);
            }
        }
        $fromTitle = $this->productFromTitle($item);
        if ($fromTitle !== '') {
            return $fromTitle;
        }

        return $this->isAutoParts($item) ? 'Peça' : 'Produto';
    }

    /**
     * @param array<string, mixed> $item
     */
    public function realBrand(array $item, string $product): ?string
    {
        $brand = $this->usableAttribute($item, 'BRAND');
        if ($brand === null) {
            return null;
        }
        $lower = mb_strtolower($brand);
        if (in_array($lower, self::GENERIC_BRANDS, true)) {
            return null;
        }
        if ($product !== '' && mb_strtolower($product) === $lower) {
            return null;
        }
        if (str_contains($brand, ',') || $this->looksLikeLongTail($brand)) {
            return null;
        }
        if ($product !== '' && str_contains($lower, mb_strtolower($this->firstWord($product)))) {
            return null;
        }

        return $this->preserveAcronym($brand);
    }

    /**
     * @param array<string, mixed> $item
     */
    public function modelForTitle(array $item, ?string $realModel, string $product, ?string $brand): ?string
    {
        $model = $realModel;
        if ($model !== null && $this->usableValue($model) === null) {
            $model = null;
        }
        if ($model !== null && $this->looksLikeLongTail($model)) {
            $model = null;
        }
        if ($model !== null && $product !== '' && mb_strtolower($model) === mb_strtolower($product)) {
            $model = null;
        }
        if ($model !== null) {
            return $this->preserveAcronym($model);
        }

        foreach (['PART_NUMBER', 'OEM', 'LINE'] as $id) {
            $v = $this->usableAttribute($item, $id);
            if ($v === null || $this->isDummyCode($v) || $this->looksLikeLongTail($v)) {
                continue;
            }
            if ($brand !== null && mb_strtolower($v) === mb_strtolower($brand)) {
                continue;
            }

            return $this->preserveAcronym($v);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    public function oneSpec(array $item, string $product, ?string $brand, ?string $model): ?string
    {
        $measure = $this->measureSpec($item);
        if ($measure !== null) {
            return $measure;
        }
        $color = $this->usableAttribute($item, 'COLOR');
        if ($color !== null) {
            $color = $this->firstColorToken($color);
            if ($color !== null && !$this->alreadyPresent($color, $product, $brand, $model)) {
                return $this->titleCaseWords($color);
            }
        }
        foreach (['WIDTH', 'HEIGHT', 'VOLTAGE'] as $id) {
            $v = $this->usableAttribute($item, $id);
            if ($v === null) {
                continue;
            }
            if ($id === 'VOLTAGE' && !$this->looksLikeVoltage($v)) {
                continue;
            }
            if ($this->alreadyPresent($v, $product, $brand, $model)) {
                continue;
            }

            return $v;
        }

        return null;
    }

    public function looksLikeLongTail(string $text): bool
    {
        $t = mb_strtolower($text);
        if (str_contains($text, ',')) {
            return true;
        }
        if (preg_match('/\b(kit|jogo|compativel|compatível|titan|fan|start|today|busca|seo|long-?tail|promo|desconto|texturizado)\b/u', $t) === 1) {
            return true;
        }
        $words = preg_split('/\s+/', trim($text)) ?: [];

        return count($words) > 4;
    }

    public function isPlaceholder(string $text): bool
    {
        $t = trim(mb_strtolower($text));
        if ($t === '' || $t === '-' || $t === '0') {
            return true;
        }
        if (preg_match('/^n\/?[ad]$/u', $t) === 1) {
            return true;
        }
        if (preg_match('/n[aã]o\s+(tem|possui|acompanha|se aplica)/u', $t) === 1) {
            return true;
        }
        if (preg_match('/\bn[aã]o tem\b/u', $t) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    public function isAutoParts(array $item): bool
    {
        $domain = strtoupper((string) ($item['domain_id'] ?? ''));

        return str_contains($domain, 'MOTORCYCLE')
            || str_contains($domain, 'AUTO_PART')
            || str_contains($domain, 'VEHICLE')
            || str_contains($domain, 'SPARE');
    }

    /**
     * @param array<string, mixed> $item
     */
    public function attributeValue(array $item, string $id): ?string
    {
        foreach ($item['attributes'] ?? [] as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            if (strtoupper((string) ($attr['id'] ?? '')) !== strtoupper($id)) {
                continue;
            }
            $v = trim((string) ($attr['value_name'] ?? ''));
            if ($v === '') {
                $v = trim((string) ($attr['value_id'] ?? ''));
            }

            return $v !== '' ? $v : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function usableAttribute(array $item, string $id): ?string
    {
        return $this->usableValue($this->attributeValue($item, $id));
    }

    private function usableValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || $this->isPlaceholder($value)) {
            return null;
        }

        return $value;
    }

    private function isDummyCode(string $value): bool
    {
        $v = trim($value);
        if (preg_match('/^\d{1,2}$/', $v) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function measureSpec(array $item): ?string
    {
        $h = $this->toCentimeters($this->usableAttribute($item, 'HEIGHT'));
        $w = $this->toCentimeters($this->usableAttribute($item, 'WIDTH'));
        if ($h !== null && $w !== null && $h >= 5 && $w >= 5) {
            return $h . 'x' . $w;
        }
        if ($w !== null && $w >= 5) {
            return (string) $w . ' cm';
        }
        if ($h !== null && $h >= 5) {
            return (string) $h . ' cm';
        }

        return null;
    }

    private function toCentimeters(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\s*([\d.,]+)\s*(mm|cm|m)?\s*$/iu', $value, $m) !== 1) {
            return null;
        }
        $n = (float) str_replace(',', '.', $m[1]);
        $unit = mb_strtolower((string) ($m[2] ?? 'cm'));
        if ($unit === '') {
            $unit = 'cm';
        }
        $cm = match ($unit) {
            'mm' => $n / 10,
            'm' => $n * 100,
            default => $n,
        };
        if ($cm < 1) {
            return null;
        }

        return (int) round($cm);
    }

    private function firstColorToken(string $color): ?string
    {
        $color = trim($color);
        if ($this->isPlaceholder($color) || $this->looksLikeLongTail($color)) {
            $words = preg_split('/\s+/', $color) ?: [];
            $first = (string) ($words[0] ?? '');
            if ($first === '' || $this->isPlaceholder($first)) {
                return null;
            }

            return $first;
        }

        return $color;
    }

    private function alreadyPresent(string $bit, string $product, ?string $brand, ?string $model): bool
    {
        $hay = mb_strtolower(trim($product . ' ' . (string) $brand . ' ' . (string) $model));
        $needle = mb_strtolower(trim($bit));

        return $needle !== '' && $hay !== '' && str_contains($hay, $needle);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function productFromTitle(array $item): string
    {
        $title = trim((string) ($item['title'] ?? ''));
        $stop = ['para', 'de', 'da', 'do', 'na', 'no', 'em', 'com', 'a', 'o', 'e'];
        $known = ['espelho', 'bagageiro', 'guidão', 'guidao', 'cavalete', 'grade', 'farol', 'retrovisor', 'churrasqueira'];
        $words = preg_split('/\s+/', $title) ?: [];
        foreach ($words as $w) {
            $lw = mb_strtolower($w);
            if (in_array($lw, $stop, true) || $this->isPlaceholder($w) || $this->looksLikeLongTail($w)) {
                continue;
            }
            if (in_array($lw, $known, true)) {
                return $this->titleCaseWords($w);
            }
        }

        return '';
    }

    private function stripBikeList(string $title, ?string $realModel): string
    {
        $protected = mb_strtolower(trim((string) $realModel));
        $title = preg_replace_callback(
            '/\b(titan|fan|start|today|cargo|bros|nxr)\b/iu',
            static function (array $m) use ($protected): string {
                $tok = mb_strtolower($m[1]);
                if ($protected !== '' && str_contains($protected, $tok)) {
                    return $m[1];
                }

                return '';
            },
            $title
        ) ?? $title;
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);

        return $title;
    }

    private function stripPlaceholders(string $title): string
    {
        $title = preg_replace('/\bn[aã]o\s+tem\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bn[aã]o\s+possui[^\s]*/iu', '', $title) ?? $title;
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);

        return $title;
    }

    private function cleanFragment(string $bit): string
    {
        $bit = trim(preg_replace('/\s+/', ' ', $bit) ?? $bit);
        if ($bit === '' || $this->isPlaceholder($bit)) {
            return '';
        }

        return $bit;
    }

    private function clipTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        if (mb_strlen($title) <= self::TITLE_MAX) {
            return $title;
        }

        return rtrim(mb_substr($title, 0, self::TITLE_MAX));
    }

    private function titleCaseWords(string $text): string
    {
        return mb_convert_case(trim($text), MB_CASE_TITLE, 'UTF-8');
    }

    private function preserveAcronym(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^[A-Z0-9][A-Z0-9._\/-]{1,}$/', $text) === 1) {
            return $text;
        }
        if (mb_strlen($text) <= 4 && mb_strtoupper($text) === $text) {
            return $text;
        }

        return $this->titleCaseWords($text);
    }

    private function firstWord(string $text): string
    {
        $parts = preg_split('/\s+/', trim($text)) ?: [];

        return (string) ($parts[0] ?? $text);
    }

    private function looksLikeVoltage(string $value): bool
    {
        return preg_match('/\d+\s*v/i', $value) === 1;
    }
}
