<?php

declare(strict_types=1);

namespace App\ValueObjects\HiddenSeo;

/**
 * Fontes auditáveis de sugestão Hidden SEO.
 */
final class SuggestionSource
{
    public const FICHA_TECNICA = 'ficha_tecnica';
    public const SELLER_SKU = 'seller_sku';
    public const TITLE = 'title';
    public const LOCAL_CATALOG = 'local_catalog';
    public const SAME_ITEM = 'same_item';
    public const MANUAL = 'manual';
    public const SKIP = 'skip';
    public const HIDDEN_SEO = 'hidden_seo';

    public const ALL = [
        self::FICHA_TECNICA,
        self::SELLER_SKU,
        self::TITLE,
        self::LOCAL_CATALOG,
        self::SAME_ITEM,
        self::MANUAL,
        self::SKIP,
        self::HIDDEN_SEO,
    ];

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }
}
