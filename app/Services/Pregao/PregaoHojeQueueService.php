<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use PDO;
use Throwable;

/**
 * Fila do dia do Pregão — fatos locais da conta ativa.
 *
 * Sem GET ML (rank/search/items). Sem pause, reprice ou escrita ML.
 * Missing table / missing visits / missing CMV → n/d, nunca R$ 0 nem TRAVADA inventada.
 */
final class PregaoHojeQueueService
{
    public const BUCKETS = [
        'visits_no_sales',
        'ficha',
        'cmv',
        'perguntas_sla',
        'ads_sem_cogs',
        'ads_cogs_acos',
        'investigacao',
    ];

    /** Filas dos três agentes 24/7 observe+queue (Ficha / Perguntas / Ads). */
    public const OBSERVE_QUEUE_IDS = [
        'ficha',
        'perguntas_sla',
        'ads_sem_cogs',
        'ads_cogs_acos',
        'investigacao',
    ];

    /** ACOS alto: gasto / receita_atribuída > 30%. Não inventa receita. */
    public const ACOS_HIGH_THRESHOLD_PCT = 30.0;

    public const ADS_LOOKBACK_DAYS = 7;

    public const PERGUNTAS_SLA_SECONDS = 3600;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \App\Database::getInstance();
    }

    /**
     * @return array{
     *   v: int,
     *   read_only: true,
     *   apply_blocked: true,
     *   source: string,
     *   generated_at: string,
     *   open_count: int,
     *   items: list<array<string, mixed>>
     * }
     */
    public function build(int $accountId): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))
            ->format('Y-m-d\TH:i:sP');

        if ($accountId <= 0) {
            return $this->envelope($now, $this->emptyBuckets('conta ausente'));
        }

        $items = $this->loadActiveItems($accountId);
        $investigacao = $this->loadInvestigacao($accountId);
        $queue = [
            $this->bucketVisitsNoSales($accountId, $items),
            $this->bucketFicha($accountId, $items),
            $this->bucketCmv($accountId, $items),
            $this->bucketPerguntasSla($accountId),
            $this->bucketAdsSemCogs($accountId, $items),
            $this->bucketAdsCogsAcos($accountId, $items),
            $this->bucketInvestigacao($investigacao),
        ];

        $open = 0;
        foreach ($queue as $row) {
            if (in_array($row['severity'], ['critico', 'alto', 'medio'], true)) {
                $open++;
            }
        }

        return $this->envelope($now, $queue, $open, $investigacao);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function envelope(string $generatedAt, array $items, int $open = 0, ?array $investigacao = null): array
    {
        if ($investigacao === null) {
            $investigacao = $this->emptyInvestigacao('conta ausente');
        }

        return [
            'v' => PregaoEmitService::VERSION,
            'read_only' => true,
            'apply_blocked' => true,
            'ml_write' => false,
            'source' => 'local',
            'generated_at' => $generatedAt,
            'open_count' => $open,
            'items' => $items,
            'investigacao' => $investigacao,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function emptyBuckets(string $reason): array
    {
        $titles = [
            'visits_no_sales' => 'Visitas sem venda',
            'ficha' => 'Ficha (fotos, frete, catálogo)',
            'cmv' => 'Vendidos sem CMV',
            'perguntas_sla' => 'Perguntas sem resposta ≥1h',
            'ads_sem_cogs' => 'Ads sem CMV',
            'ads_cogs_acos' => 'Ads com CMV e ACOS ruim',
            'investigacao' => 'Investigação (rascunho, não publicado)',
        ];
        $hrefs = [
            'visits_no_sales' => '/dashboard/items',
            'ficha' => '/dashboard/seo-killer#technical-sheet',
            'cmv' => '/dashboard/cogs',
            'perguntas_sla' => '/dashboard/questions',
            'ads_sem_cogs' => '/dashboard/cogs',
            'ads_cogs_acos' => '/dashboard/ads',
            'investigacao' => '/dashboard/pregao',
        ];
        $out = [];
        foreach (self::BUCKETS as $i => $id) {
            $out[] = $this->row(
                $id,
                $i + 1,
                $titles[$id],
                0,
                'nd',
                false,
                $hrefs[$id],
                $reason
            );
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    private function bucketVisitsNoSales(int $accountId, ?array $items): array
    {
        if ($items === null) {
            return $this->row(
                'visits_no_sales',
                1,
                'Visitas sem venda',
                0,
                'nd',
                false,
                '/dashboard/items',
                'items/performance_* indisponível · sem inventar visita 0'
            );
        }

        $count = 0;
        $pendingVisits = 0;
        foreach ($items as $item) {
            if ((string) ($item['status'] ?? '') !== 'active') {
                continue;
            }
            $visits = $this->knownVisits($item);
            if ($visits === null) {
                $pendingVisits++;
                continue;
            }
            if ($visits > 0 && $this->knownSales($item) === 0) {
                $count++;
            }
        }

        $severity = $count > 0 ? 'alto' : 'ok';
        $hint = $count . ' ativos com visitas e 0 vendas · fonte local';
        if ($pendingVisits > 0) {
            $hint .= ' · ' . $pendingVisits . ' visitas n/d (não contados como 0)';
        }

        return $this->row(
            'visits_no_sales',
            1,
            'Visitas sem venda',
            $count,
            $severity,
            true,
            '/dashboard/items',
            $hint
        );
    }

    /**
     * Mesmos gaps oficiais do SEO Killer: photos&lt;3, sem frete grátis, classic vs catalog.
     *
     * @param list<array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    private function bucketFicha(int $accountId, ?array $items): array
    {
        if ($items === null) {
            return $this->row(
                'ficha',
                2,
                'Ficha (fotos, frete, catálogo)',
                0,
                'nd',
                false,
                '/dashboard/seo-killer#technical-sheet',
                'items locais indisponíveis · gaps oficiais do SEO Killer'
            );
        }

        $photos = 0;
        $shipping = 0;
        $catalog = 0;
        $unique = 0;
        foreach ($items as $item) {
            if ((string) ($item['status'] ?? '') !== 'active') {
                continue;
            }
            $flags = $this->officialListingGapFlags($item);
            if ($flags['photos_lt3']) {
                $photos++;
            }
            if ($flags['no_free_shipping']) {
                $shipping++;
            }
            if ($flags['catalog_not_listing']) {
                $catalog++;
            }
            if ($flags['photos_lt3'] || $flags['no_free_shipping'] || $flags['catalog_not_listing']) {
                $unique++;
            }
        }

        $severity = $unique > 0 ? 'alto' : 'ok';

        return $this->row(
            'ficha',
            2,
            'Ficha (fotos, frete, catálogo)',
            $unique,
            $severity,
            true,
            '/dashboard/seo-killer#technical-sheet',
            $photos . ' fotos<3 · ' . $shipping . ' sem frete grátis · ' . $catalog . ' classic vs catálogo · sem apply'
        );
    }

    /**
     * Vendidos (ml_orders ∪ sold_quantity/sales_30d) sem sku_custos.custo_produto>0.
     * Ausência é n/d — nunca CMV 0.
     *
     * @param list<array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    private function bucketCmv(int $accountId, ?array $items): array
    {
        $soldIds = $this->soldItemIds($accountId, $items);
        if ($soldIds === null) {
            return $this->row(
                'cmv',
                3,
                'Vendidos sem CMV',
                0,
                'nd',
                false,
                '/dashboard/cogs',
                'ml_orders/items indisponível · CMV n/d (não 0)'
            );
        }

        $known = $this->knownCogsIds($accountId, $soldIds);
        if ($known === null) {
            return $this->row(
                'cmv',
                3,
                'Vendidos sem CMV',
                0,
                'nd',
                false,
                '/dashboard/cogs',
                'sku_custos indisponível · CMV n/d (não 0)'
            );
        }

        $missing = 0;
        foreach ($soldIds as $mlb) {
            if (!isset($known[$mlb])) {
                $missing++;
            }
        }

        $severity = 'ok';
        if ($missing > 0 && count($soldIds) > 0 && $missing === count($soldIds)) {
            $severity = 'critico';
        } elseif ($missing > 0) {
            $severity = 'alto';
        }

        $covered = count($soldIds) - $missing;

        return $this->row(
            'cmv',
            3,
            'Vendidos sem CMV',
            $missing,
            $severity,
            true,
            '/dashboard/cogs',
            $covered . '/' . count($soldIds) . ' vendidos com CMV conhecido · faltantes n/d não 0'
        );
    }

    /**
     * @param list<array<string, mixed>>|null $items
     * @return list<string>|null
     */
    private function soldItemIds(int $accountId, ?array $items): ?array
    {
        $ids = [];
        $anySource = false;

        if ($items !== null) {
            $anySource = true;
            foreach ($items as $item) {
                if ($this->itemHasSales($item)) {
                    $mlb = strtoupper(trim((string) ($item['ml_item_id'] ?? $item['id'] ?? '')));
                    if ($mlb !== '') {
                        $ids[$mlb] = true;
                    }
                }
            }
        }

        $fromOrders = $this->soldIdsFromOrders($accountId);
        if ($fromOrders !== null) {
            $anySource = true;
            foreach ($fromOrders as $mlb) {
                $ids[$mlb] = true;
            }
        }

        if (!$anySource) {
            return null;
        }

        return array_keys($ids);
    }

    /**
     * @return list<string>|null
     */
    private function soldIdsFromOrders(int $accountId): ?array
    {
        $sqlVariants = [
            'SELECT order_data FROM ml_orders WHERE ml_account_id = ? LIMIT 5000',
            'SELECT order_data FROM ml_orders WHERE account_id = ? LIMIT 5000',
        ];
        $stmt = null;
        foreach ($sqlVariants as $sql) {
            try {
                $prepared = $this->db->prepare($sql);
                $prepared->execute([$accountId]);
                $stmt = $prepared;
                break;
            } catch (Throwable) {
                $stmt = null;
            }
        }
        if ($stmt === null) {
            return null;
        }

        $ids = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $decoded = json_decode((string) ($row['order_data'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $lines = $decoded['order_items'] ?? [];
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $item = $line['item'] ?? [];
                $mlb = strtoupper(trim((string) (is_array($item) ? ($item['id'] ?? '') : '')));
                if ($mlb !== '') {
                    $ids[$mlb] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @param list<string> $mlbIds
     * @return array<string, true>|null
     */
    private function knownCogsIds(int $accountId, array $mlbIds): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT mlb_id, custo_produto FROM sku_custos WHERE account_id = ?'
            );
            $stmt->execute([$accountId]);
        } catch (Throwable) {
            return null;
        }

        $known = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $mlb = strtoupper(trim((string) ($row['mlb_id'] ?? '')));
            if ($mlb === '') {
                continue;
            }
            if ((float) ($row['custo_produto'] ?? 0) > 0) {
                $known[$mlb] = true;
            }
        }

        return $known;
    }

    /**
     * Active+paused local universe. Null = items table missing (fail-soft n/d).
     *
     * @return list<array<string, mixed>>|null
     */
    private function loadActiveItems(int $accountId): ?array
    {
        $sqlVariants = [
            "SELECT ml_item_id, title, status, available_quantity, sold_quantity, catalog_product_id, cost_price, data
             FROM items
             WHERE account_id = ? AND status IN ('active', 'paused')",
            "SELECT ml_item_id, title, status, available_quantity, sold_quantity, catalog_product_id, data
             FROM items
             WHERE account_id = ? AND status IN ('active', 'paused')",
            "SELECT ml_item_id, title, status, available_quantity, sold_quantity, catalog_product_id, data
             FROM ml_items
             WHERE account_id = ? AND status IN ('active', 'paused')",
        ];

        $stmt = null;
        foreach ($sqlVariants as $sql) {
            try {
                $prepared = $this->db->prepare($sql);
                $prepared->execute([$accountId]);
                $stmt = $prepared;
                break;
            } catch (Throwable) {
                $stmt = null;
            }
        }
        if ($stmt === null) {
            return null;
        }

        $items = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $mapped = $this->mapLocalItemRow($row);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function mapLocalItemRow(array $row): ?array
    {
        $data = $row['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($data)) {
            $data = [];
        }

        $id = strtoupper(trim((string) ($row['ml_item_id'] ?? $data['id'] ?? '')));
        if ($id === '') {
            return null;
        }

        $item = $data;
        $item['id'] = $id;
        $item['ml_item_id'] = $id;
        $item['title'] = (string) ($row['title'] ?? $data['title'] ?? '');
        $item['status'] = (string) ($row['status'] ?? $data['status'] ?? '');
        if (isset($row['available_quantity']) && is_numeric($row['available_quantity'])) {
            $item['available_quantity'] = (int) $row['available_quantity'];
        }
        if (isset($row['sold_quantity']) && is_numeric($row['sold_quantity'])) {
            $item['sold_quantity'] = (int) $row['sold_quantity'];
        }
        $catalog = trim((string) ($row['catalog_product_id'] ?? $data['catalog_product_id'] ?? ''));
        $item['catalog_product_id'] = $catalog !== '' ? $catalog : null;
        if (isset($row['cost_price']) && is_numeric($row['cost_price']) && (float) $row['cost_price'] > 0) {
            $item['cost_price'] = (float) $row['cost_price'];
        }
        if (!array_key_exists('catalog_listing', $item)) {
            $item['catalog_listing'] = false;
        }

        foreach (['performance_score', 'performance_level', 'visits_30d', '_visits_30d', 'sales_30d', '_sales_30d'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $item[$key] = $data[$key];
            }
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function knownVisits(array $item): ?int
    {
        foreach (['visits_30d', '_visits_30d'] as $key) {
            if (array_key_exists($key, $item) && is_numeric($item[$key])) {
                return (int) $item[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function knownSales(array $item): int
    {
        foreach (['sales_30d', '_sales_30d'] as $key) {
            if (array_key_exists($key, $item) && is_numeric($item[$key])) {
                return (int) $item[$key];
            }
        }

        return (int) ($item['sold_quantity'] ?? 0);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemHasSales(array $item): bool
    {
        if ($this->knownSales($item) > 0) {
            return true;
        }

        return (int) ($item['sold_quantity'] ?? 0) > 0;
    }

    /**
     * Subset of SEOKillerEngine::officialListingGapFlags (photos, shipping, catalog).
     *
     * @param array<string, mixed> $item
     * @return array{photos_lt3: bool, no_free_shipping: bool, catalog_not_listing: bool}
     */
    private function officialListingGapFlags(array $item): array
    {
        $photos = $item['pictures'] ?? [];
        $photoCount = is_array($photos) ? count($photos) : 0;
        $catalogId = trim((string) ($item['catalog_product_id'] ?? ''));
        $catalogListing = $item['catalog_listing'] ?? false;
        $isCatalogListing = $catalogListing === true || $catalogListing === 1 || $catalogListing === 'true';
        $shipping = $item['shipping'] ?? [];
        $freeShipping = is_array($shipping) && !empty($shipping['free_shipping']);

        return [
            'photos_lt3' => $photoCount < 3,
            'no_free_shipping' => !$freeShipping,
            'catalog_not_listing' => $catalogId !== '' && !$isCatalogListing,
        ];
    }

    /**
     * Unanswered from local ml_questions of the active account. Not the legacy
     * questions table (account mix). Not the API-first Pregão collector.
     *
     * @return array<string, mixed>
     */
    private function bucketPerguntasSla(int $accountId): array
    {
        $cutoff = (new \DateTimeImmutable('now'))
            ->modify('-' . self::PERGUNTAS_SLA_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');

        try {
            $stmt = $this->db->prepare(
                "SELECT
                    SUM(CASE WHEN UPPER(status) = 'UNANSWERED' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN UPPER(status) = 'UNANSWERED'
                              AND date_created IS NOT NULL
                              AND date_created <= ?
                         THEN 1 ELSE 0 END) AS ge_1h
                 FROM ml_questions
                 WHERE account_id = ?"
            );
            $stmt->execute([$cutoff, $accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return $this->row(
                'perguntas_sla',
                4,
                'Perguntas sem resposta ≥1h',
                0,
                'nd',
                false,
                '/dashboard/questions',
                'ml_questions indisponível · sem API-first · POST /answers bloqueado'
            );
        }

        $pending = (int) ($row['pending'] ?? 0);
        $ge1h = (int) ($row['ge_1h'] ?? 0);
        $severity = $ge1h > 0 ? 'alto' : 'ok';

        return $this->row(
            'perguntas_sla',
            4,
            'Perguntas sem resposta ≥1h',
            $ge1h,
            $severity,
            true,
            '/dashboard/questions',
            $ge1h . ' ≥1h / ' . $pending . ' em aberto · fonte ml_questions · sem POST /answers'
        );
    }

    /**
     * SKUs with recent ads spend and no known CMV (sku_custos.custo_produto>0
     * or items.cost_price>0). Missing CMV is n/d, never profit 0.
     *
     * @param list<array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    private function bucketAdsSemCogs(int $accountId, ?array $items): array
    {
        $spend = $this->recentAdsSpendByMlb($accountId);
        if ($spend === null) {
            return $this->row(
                'ads_sem_cogs',
                5,
                'Ads sem CMV',
                0,
                'nd',
                false,
                '/dashboard/cogs',
                'ads_sku_metrics_daily indisponível · CMV n/d (não 0) · sem ligar campanha'
            );
        }

        $known = $this->knownCogsForMlb($accountId, $items, array_keys($spend));
        if ($known === null) {
            return $this->row(
                'ads_sem_cogs',
                5,
                'Ads sem CMV',
                0,
                'nd',
                false,
                '/dashboard/cogs',
                'sku_custos indisponível · CMV n/d (não 0) · sem ligar campanha'
            );
        }

        $missing = 0;
        foreach ($spend as $mlb => $row) {
            if (($row['gasto'] ?? 0) > 0 && !isset($known[$mlb])) {
                $missing++;
            }
        }

        $severity = $missing > 0 ? 'alto' : 'ok';

        return $this->row(
            'ads_sem_cogs',
            5,
            'Ads sem CMV',
            $missing,
            $severity,
            true,
            '/dashboard/cogs',
            $missing . ' SKU com gasto ads recente sem CMV · n/d não 0 · sem campanha on/off'
        );
    }

    /**
     * SKUs that HAVE CMV and recent ads spend with ACOS > 30% (or spend with
     * zero attributed revenue). Does not invent receita.
     *
     * @param list<array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    private function bucketAdsCogsAcos(int $accountId, ?array $items): array
    {
        $spend = $this->recentAdsSpendByMlb($accountId);
        if ($spend === null) {
            return $this->row(
                'ads_cogs_acos',
                6,
                'Ads com CMV e ACOS ruim',
                0,
                'nd',
                false,
                '/dashboard/ads',
                'ads_sku_metrics_daily indisponível · sem inventar receita · sem campanha on/off'
            );
        }

        $known = $this->knownCogsForMlb($accountId, $items, array_keys($spend));
        if ($known === null) {
            return $this->row(
                'ads_cogs_acos',
                6,
                'Ads com CMV e ACOS ruim',
                0,
                'nd',
                false,
                '/dashboard/ads',
                'sku_custos indisponível · ACOS de contribuição n/d · sem campanha on/off'
            );
        }

        $bad = 0;
        foreach ($spend as $mlb => $row) {
            if (!isset($known[$mlb])) {
                continue;
            }
            $gasto = (float) ($row['gasto'] ?? 0);
            if ($gasto <= 0) {
                continue;
            }
            $receita = (float) ($row['receita'] ?? 0);
            if ($receita <= 0) {
                $bad++;
                continue;
            }
            $acos = ($gasto / $receita) * 100;
            if ($acos > self::ACOS_HIGH_THRESHOLD_PCT) {
                $bad++;
            }
        }

        $severity = $bad > 0 ? 'alto' : 'ok';

        return $this->row(
            'ads_cogs_acos',
            6,
            'Ads com CMV e ACOS ruim',
            $bad,
            $severity,
            true,
            '/dashboard/ads',
            $bad . ' SKU com CMV e ACOS>' . (int) self::ACOS_HIGH_THRESHOLD_PCT . '% · sem inventar receita · sem campanha on/off'
        );
    }

    /**
     * Recent ads spend by MLB. Null = table missing. Does not invent receita.
     *
     * @return array<string, array{gasto: float, receita: float}>|null
     */
    private function recentAdsSpendByMlb(int $accountId): ?array
    {
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))
            ->modify('-' . self::ADS_LOOKBACK_DAYS . ' days')
            ->format('Y-m-d');

        $sqlVariants = [
            'SELECT mlb_id, SUM(gasto) AS gasto, SUM(receita_atribuida) AS receita
             FROM ads_sku_metrics_daily
             WHERE account_id = ? AND `date` >= ?
             GROUP BY mlb_id',
            'SELECT mlb_id, SUM(gasto) AS gasto, SUM(receita_atribuida) AS receita
             FROM ads_sku_metrics_daily
             WHERE account_id = ? AND date >= ?
             GROUP BY mlb_id',
        ];

        $stmt = null;
        foreach ($sqlVariants as $sql) {
            try {
                $prepared = $this->db->prepare($sql);
                $prepared->execute([$accountId, $since]);
                $stmt = $prepared;
                break;
            } catch (Throwable) {
                $stmt = null;
            }
        }
        if ($stmt === null) {
            return null;
        }

        $out = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $mlb = strtoupper(trim((string) ($row['mlb_id'] ?? '')));
            if ($mlb === '') {
                continue;
            }
            $gasto = (float) ($row['gasto'] ?? 0);
            if ($gasto <= 0) {
                continue;
            }
            $out[$mlb] = [
                'gasto' => $gasto,
                'receita' => (float) ($row['receita'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Known CMV: sku_custos.custo_produto>0 or items.cost_price>0.
     * Null = cannot know (sku_custos missing). Zero cost is not known CMV.
     *
     * @param list<array<string, mixed>>|null $items
     * @param list<string> $mlbIds
     * @return array<string, true>|null
     */
    private function knownCogsForMlb(int $accountId, ?array $items, array $mlbIds): ?array
    {
        $fromSku = $this->knownCogsIds($accountId, $mlbIds);
        if ($fromSku === null) {
            return null;
        }

        $known = $fromSku;
        if ($items !== null) {
            foreach ($items as $item) {
                $mlb = strtoupper(trim((string) ($item['ml_item_id'] ?? $item['id'] ?? '')));
                if ($mlb === '') {
                    continue;
                }
                $cost = $item['cost_price'] ?? null;
                if (is_numeric($cost) && (float) $cost > 0) {
                    $known[$mlb] = true;
                }
            }
        }

        return $known;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyInvestigacao(string $reason): array
    {
        return [
            'count' => 0,
            'available' => false,
            'apply_blocked' => true,
            'ml_write' => false,
            'published' => false,
            'items' => [],
            'reason' => $reason,
        ];
    }

    /**
     * Open investigations for the active account. Fail-soft if table missing.
     *
     * @return array<string, mixed>
     */
    private function loadInvestigacao(int $accountId): array
    {
        if ($accountId <= 0) {
            return $this->emptyInvestigacao('conta ausente');
        }
        try {
            $countStmt = $this->db->prepare(
                "SELECT COUNT(*) FROM listing_investigations WHERE account_id = ? AND status = 'open'"
            );
            $countStmt->execute([$accountId]);
            $count = (int) $countStmt->fetchColumn();
            $listStmt = $this->db->prepare(
                "SELECT mlb_id, blockers, draft_title, model_used
                 FROM listing_investigations
                 WHERE account_id = ? AND status = 'open'
                 ORDER BY id DESC
                 LIMIT 5"
            );
            $listStmt->execute([$accountId]);
            $items = [];
            while (($row = $listStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $blockers = $row['blockers'] ?? [];
                if (is_string($blockers) && $blockers !== '') {
                    $decoded = json_decode($blockers, true);
                    $blockers = is_array($decoded) ? $decoded : [];
                } elseif (!is_array($blockers)) {
                    $blockers = [];
                }
                $codes = [];
                foreach ($blockers as $b) {
                    if (is_string($b)) {
                        $codes[] = $b;
                    } elseif (is_array($b) && isset($b['code'])) {
                        $codes[] = (string) $b['code'];
                    }
                }
                $items[] = [
                    'mlb' => (string) ($row['mlb_id'] ?? ''),
                    'blockers' => $blockers,
                    'blocker' => $codes[0] ?? '',
                    'draft_title' => (string) ($row['draft_title'] ?? ''),
                    'model_used' => (string) ($row['model_used'] ?? ''),
                    'published' => false,
                    'nao_publicado' => true,
                ];
            }

            return [
                'count' => $count,
                'available' => true,
                'apply_blocked' => true,
                'ml_write' => false,
                'published' => false,
                'items' => $items,
            ];
        } catch (Throwable) {
            return $this->emptyInvestigacao('listing_investigations indisponível');
        }
    }

    /**
     * @param array<string, mixed> $investigacao
     * @return array<string, mixed>
     */
    private function bucketInvestigacao(array $investigacao): array
    {
        $available = (bool) ($investigacao['available'] ?? false);
        $count = (int) ($investigacao['count'] ?? 0);
        $severity = !$available ? 'nd' : ($count > 0 ? 'alto' : 'ok');
        $bits = [];
        foreach ($investigacao['items'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mlb = (string) ($row['mlb'] ?? '');
            $blocker = (string) ($row['blocker'] ?? '');
            if ($mlb !== '') {
                $bits[] = $mlb . ($blocker !== '' ? ('=' . $blocker) : '');
            }
        }
        $hint = !$available
            ? (string) ($investigacao['reason'] ?? 'listing_investigations indisponível') . ' · sem apply'
            : ($count . ' abertas · ' . ($bits !== [] ? implode(' · ', $bits) : 'nenhuma') . ' · não publicado · sem apply');

        return $this->row(
            'investigacao',
            7,
            'Investigação (rascunho, não publicado)',
            $count,
            $severity,
            $available,
            '/dashboard/pregao',
            $hint
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $id,
        int $priority,
        string $title,
        int $count,
        string $severity,
        bool $available,
        string $href,
        string $hint
    ): array {
        return [
            'id' => $id,
            'priority' => $priority,
            'title' => $title,
            'count' => $count,
            'severity' => $severity,
            'available' => $available,
            'href' => $href,
            'apply_blocked' => true,
            'hint' => $hint,
        ];
    }
}
