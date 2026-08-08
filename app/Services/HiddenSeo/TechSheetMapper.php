<?php

declare(strict_types=1);

namespace App\Services\HiddenSeo;

use App\Entity\HiddenSeo\HiddenSeoGap;
use App\Entity\HiddenSeo\Suggestion;
use App\Services\TechSheetHiddenSuggestionService;
use App\ValueObjects\HiddenSeo\Evidence;
use App\ValueObjects\HiddenSeo\Line;
use App\ValueObjects\HiddenSeo\Mpn;
use App\ValueObjects\HiddenSeo\SuggestionSource;

/**
 * Cruza ficha técnica / título / SKU com gaps Hidden SEO.
 * Prioridade: LINE → MPN. Nunca inventa.
 *
 * @see https://developers.mercadolivre.com.br/pt_br/api-docs-pt-br/atributos
 */
class TechSheetMapper
{
    private TechSheetHiddenSuggestionService $extractor;

    public function __construct(?TechSheetHiddenSuggestionService $extractor = null)
    {
        // extractor só para looksLikeMpn / padrões — account irrelevante em unit
        $this->extractor = $extractor ?? $this->buildExtractorStub();
    }

    /**
     * @param array{line?:?string,mpn?:?string,sku?:?string,seller_sku?:?string} $ficha
     * @return list<Suggestion>
     */
    public function map(HiddenSeoGap $gap, array $ficha = []): array
    {
        $out = [];

        if ($gap->needsLine()) {
            $line = $this->resolveLine($gap, $ficha);
            if ($line !== null) {
                $out[] = Suggestion::forLine(
                    $gap->mlItemId(),
                    $line,
                    $this->mapSource($line->evidence()->source()),
                    $gap->currentLine()
                );
            }
        }

        if ($gap->needsMpn()) {
            $mpn = $this->resolveMpn($gap, $ficha);
            if ($mpn !== null) {
                $out[] = Suggestion::forMpn(
                    $gap->mlItemId(),
                    $mpn,
                    $this->mapSource($mpn->evidence()->source()),
                    $gap->currentMpn()
                );
            }
        }

        return $out;
    }

    /**
     * @param array{line?:?string,mpn?:?string,sku?:?string,seller_sku?:?string} $ficha
     */
    private function resolveLine(HiddenSeoGap $gap, array $ficha): ?Line
    {
        $fromFicha = trim((string)($ficha['line'] ?? ''));
        if ($fromFicha !== '') {
            try {
                return new Line(
                    $fromFicha,
                    new Evidence(SuggestionSource::FICHA_TECNICA, 'ficha.line=' . $fromFicha, 95)
                );
            } catch (\InvalidArgumentException) {
                // cai para título
            }
        }

        $title = $gap->title();
        $ref = new \ReflectionMethod(TechSheetHiddenSuggestionService::class, 'extractLineFromTitle');
        $ref->setAccessible(true);
        /** @var array{value:string,confidence:int,evidence_source:string,evidence?:string}|null $raw */
        $raw = $ref->invoke($this->extractor, $title);
        if ($raw === null) {
            return null;
        }
        try {
            return new Line(
                $raw['value'],
                new Evidence(
                    SuggestionSource::TITLE,
                    (string)($raw['evidence'] ?? 'title'),
                    (int)$raw['confidence']
                )
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array{line?:?string,mpn?:?string,sku?:?string,seller_sku?:?string} $ficha
     */
    private function resolveMpn(HiddenSeoGap $gap, array $ficha): ?Mpn
    {
        $fromFicha = trim((string)($ficha['mpn'] ?? ''));
        if ($fromFicha !== '' && $this->extractor->looksLikeMpn($fromFicha)) {
            return new Mpn(
                $fromFicha,
                new Evidence(SuggestionSource::FICHA_TECNICA, 'ficha.mpn=' . $fromFicha, 95)
            );
        }

        $sku = trim((string)($ficha['seller_sku'] ?? $ficha['sku'] ?? ''));
        if ($sku === '') {
            $attrs = $gap->rawAttributes();
            foreach ($attrs as $attr) {
                if (!is_array($attr)) {
                    continue;
                }
                if (($attr['id'] ?? '') === 'SELLER_SKU') {
                    $sku = trim((string)($attr['value_name'] ?? ''));
                    break;
                }
            }
        }
        if ($sku !== '' && $this->extractor->looksLikeMpn($sku)) {
            return new Mpn(
                $sku,
                new Evidence(SuggestionSource::SELLER_SKU, 'SELLER_SKU=' . $sku, 88)
            );
        }

        $ref = new \ReflectionMethod(TechSheetHiddenSuggestionService::class, 'extractMpnFromTitle');
        $ref->setAccessible(true);
        /** @var array{value:string,confidence:int,evidence_source:string,evidence?:string}|null $raw */
        $raw = $ref->invoke($this->extractor, $gap->title());
        if ($raw === null) {
            return null;
        }
        try {
            return new Mpn(
                $raw['value'],
                new Evidence(
                    SuggestionSource::TITLE,
                    (string)($raw['evidence'] ?? 'title'),
                    (int)$raw['confidence']
                )
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function mapSource(string $evidenceSource): string
    {
        return match ($evidenceSource) {
            SuggestionSource::FICHA_TECNICA => SuggestionSource::FICHA_TECNICA,
            SuggestionSource::SELLER_SKU => SuggestionSource::SELLER_SKU,
            SuggestionSource::TITLE, 'title' => SuggestionSource::TITLE,
            SuggestionSource::SAME_ITEM, 'same_item' => SuggestionSource::SAME_ITEM,
            SuggestionSource::LOCAL_CATALOG, 'local_catalog' => SuggestionSource::LOCAL_CATALOG,
            default => SuggestionSource::HIDDEN_SEO,
        };
    }

    private function buildExtractorStub(): TechSheetHiddenSuggestionService
    {
        $ref = new \ReflectionClass(TechSheetHiddenSuggestionService::class);
        /** @var TechSheetHiddenSuggestionService $svc */
        $svc = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('accountId');
        $prop->setAccessible(true);
        $prop->setValue($svc, 0);
        return $svc;
    }
}
