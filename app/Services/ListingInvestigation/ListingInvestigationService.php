<?php

declare(strict_types=1);

namespace App\Services\ListingInvestigation;

use PDO;
use Throwable;

/**
 * Read-only listing investigation. Drafts stay local.
 * apply_blocked=true, ml_write=false. Never PUT/POST Mercado Livre.
 */
final class ListingInvestigationService
{
    public const APPLY_BLOCKED = true;
    public const STATUS_OPEN = 'open';
    public const STATUS_SOLD = 'sold';
    public const STATUS_BLOCKED = 'blocked';
    public const TITLE_MAX = 60;
    public const DEFAULT_LIMIT = 5;
    public const FACILYTY_ACCOUNT = 1335;
    public const FALCAO_ACCOUNT = 1336;

    public const SYSTEM_PROMPT = <<<'PROMPT'
Você é o Agente Investigação do eskill (Jess Stai, 2026-08-21, implement-all).

Modo: OBSERVE + QUEUE + RASCUNHO. apply_blocked=true. ml_write=false.
Você NÃO publica no Mercado Livre. Você NÃO dá PUT/POST em items, NÃO responde pergunta, NÃO liga ads, NÃO pausa, NÃO clona, NÃO scrapa página de concorrente.

Fonte: JSON local do anúncio (items.data), performance_*, gaps oficiais. Só isso.

Diagnóstico — blockers oficiais (únicos que valem):
- photos_lt3: menos de 3 fotos
- stock_0: estoque 0
- catalog_not_listing: tem catalog_product_id mas catalog_listing falso
- no_free_shipping: sem frete grátis
- not_premium: listing_type_id diferente de gold_pro
- unanswered_questions: perguntas locais sem resposta
- visits_no_sales: visits_30d>0 e sales_30d=0 (caso difícil; não invente visita=0)

"Vender no mesmo dia" = deixar o anúncio sale-ready hoje (fotos≥3, stock>0, catálogo se o id existe, frete grátis, gold_pro, perguntas). NÃO é venda garantida. NÃO invente desconto para “ligar busca”. NÃO invente CMV.

TÍTULO (rascunho, não aplicar): Product + Brand + Model + spec. Máximo 60 caracteres.
MODEL: copie APENAS o value_name do atributo id=MODEL já presente. Nunca reescreva MODEL. Nunca coloque long-tail, kit, jogo, compatível, titan, fan, cg, busca, SEO, nem lista de motos no atributo MODEL.
Peças / auto parts: compatibilidade vai no widget de compatibilities, NÃO numa lista de motos no título.

Responda SOMENTE JSON:
{"blockers":[{"code":"...","label":"..."}],"draft_title":"...","draft_notes":"...","model_attribute":"<modelo real ou vazio>","published":false}
PROMPT;

    private PDO $db;
    private ?DashScopeClient $llm;
    private bool $forceRules;

    public function __construct(PDO $db, ?DashScopeClient $llm = null, bool $forceRules = false)
    {
        $this->db = $db;
        $this->llm = $llm;
        $this->forceRules = $forceRules;
    }

    /**
     * @return array{
     *   apply_blocked: true,
     *   ml_write: false,
     *   account_id: int,
     *   model_key: string,
     *   investigated: list<array<string, mixed>>,
     *   closed_sold: int
     * }
     */
    public function run(int $accountId, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(20, $limit));
        $this->ensureTable();
        $closed = $this->closeSold($accountId);
        $candidates = $this->pickCandidates($accountId, $limit);
        $rows = [];
        foreach ($candidates as $item) {
            $rows[] = $this->investigateAndStore($accountId, $item);
        }

        return [
            'apply_blocked' => true,
            'ml_write' => false,
            'account_id' => $accountId,
            'model_key' => $this->llmEnabled() ? 'dashscope' : 'rules',
            'investigated' => $rows,
            'closed_sold' => $closed,
        ];
    }

    /**
     * Pregão snapshot payload: count + last 5 mlb+blocker. Never published.
     *
     * @return array<string, mixed>
     */
    public function pregaoSnapshot(int $accountId): array
    {
        $empty = [
            'count' => 0,
            'available' => false,
            'apply_blocked' => true,
            'ml_write' => false,
            'published' => false,
            'items' => [],
        ];
        if ($accountId <= 0) {
            return $empty;
        }
        try {
            $countStmt = $this->db->prepare(
                "SELECT COUNT(*) FROM listing_investigations WHERE account_id = ? AND status = ?"
            );
            $countStmt->execute([$accountId, self::STATUS_OPEN]);
            $count = (int) $countStmt->fetchColumn();

            $listStmt = $this->db->prepare(
                "SELECT mlb_id, blockers, draft_title, model_used, created_at
                 FROM listing_investigations
                 WHERE account_id = ? AND status = ?
                 ORDER BY id DESC
                 LIMIT 5"
            );
            $listStmt->execute([$accountId, self::STATUS_OPEN]);
            $items = [];
            while (($row = $listStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $blockers = $this->decodeBlockers($row['blockers'] ?? null);
                $items[] = [
                    'mlb' => (string) ($row['mlb_id'] ?? ''),
                    'blockers' => $blockers,
                    'draft_title' => (string) ($row['draft_title'] ?? ''),
                    'model_used' => (string) ($row['model_used'] ?? ''),
                    'published' => false,
                    'nao_publicado' => true,
                    'created_at' => (string) ($row['created_at'] ?? ''),
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
            return $empty;
        }
    }

    public function ensureTable(): void
    {
        $driver = '';
        try {
            $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable) {
            $driver = '';
        }

        if ($driver === 'sqlite') {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS listing_investigations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL,
                    mlb_id TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT \'open\',
                    blockers TEXT NULL,
                    draft_title TEXT NULL,
                    draft_notes TEXT NULL,
                    model_used TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
            return;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS listing_investigations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                mlb_id VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT \'open\',
                blockers JSON NULL,
                draft_title VARCHAR(512) NULL,
                draft_notes TEXT NULL,
                model_used VARCHAR(64) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_li_account_status (account_id, status),
                KEY idx_li_account_mlb (account_id, mlb_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function llmEnabled(): bool
    {
        return !$this->forceRules && $this->llm !== null && $this->llm->isConfigured();
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function investigateAndStore(int $accountId, array $item): array
    {
        $mlb = (string) ($item['ml_item_id'] ?? $item['id'] ?? '');
        $title = (string) ($item['title'] ?? '');
        $blockers = $this->officialBlockers($item);
        $realModel = $this->realModel($item);
        $hard = $this->isHardCase($item);
        $wantedModel = $hard ? DashScopeClient::MODEL_MAX : DashScopeClient::MODEL_PLUS;
        $modelUsed = 'rules';
        $draft = $this->rulesDraft($item, $blockers, $realModel);
        $notes = $draft['draft_notes'];

        if ($this->llmEnabled()) {
            $llm = $this->askLlm($item, $blockers, $realModel, $wantedModel);
            if ($llm !== null) {
                $modelUsed = (string) ($llm['model_used'] ?? $wantedModel);
                $draft['draft_title'] = $this->clipTitle((string) ($llm['draft_title'] ?: $draft['draft_title']));
                if ((string) ($llm['draft_notes'] ?? '') !== '') {
                    $notes = (string) $llm['draft_notes'];
                }
                $blockers = $this->mergeBlockers($blockers, $llm['blockers'] ?? []);
            } else {
                $why = $this->llm !== null ? (string) $this->llm->lastError() : 'disabled';
                $notes .= ' · llm_fallback=rules';
                if ($why !== '') {
                    $notes .= '(' . $why . ')';
                }
            }
        }

        $notes = $this->appendModelContractNote($notes, $realModel, $item);
        $notes .= ' · não publicado · apply_blocked=true';

        $row = [
            'account_id' => $accountId,
            'mlb_id' => $mlb,
            'status' => self::STATUS_OPEN,
            'blockers' => $blockers,
            'draft_title' => $draft['draft_title'],
            'draft_notes' => $notes,
            'model_used' => $modelUsed,
            'title' => $title,
            'published' => false,
            'apply_blocked' => true,
            'ml_write' => false,
            'hard_case' => $hard,
        ];
        $this->insertRow($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertRow(array $row): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO listing_investigations
                (account_id, mlb_id, status, blockers, draft_title, draft_notes, model_used)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $row['account_id'],
            (string) $row['mlb_id'],
            (string) $row['status'],
            json_encode($row['blockers'], JSON_UNESCAPED_UNICODE),
            (string) $row['draft_title'],
            (string) $row['draft_notes'],
            (string) $row['model_used'],
        ]);
    }

    /**
     * @param array<string, mixed> $item
     * @param list<array{code:string,label:string}> $blockers
     * @return array<string, mixed>|null
     */
    private function askLlm(array $item, array $blockers, ?string $realModel, string $wantedModel): ?array
    {
        if ($this->llm === null) {
            return null;
        }
        $payload = $this->llmPayload($item, $blockers, $realModel);
        $userContent = $this->buildUserContent($payload, $item);
        $result = $this->llm->complete($wantedModel, [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $userContent],
        ]);
        if ($result === null) {
            return null;
        }
        $parsed = $this->parseLlmJson((string) ($result['content'] ?? ''));
        if ($parsed === null) {
            return null;
        }
        $proposedModel = trim((string) ($parsed['model_attribute'] ?? ''));
        if (!$this->modelAttributeAllowed($proposedModel, $realModel)) {
            $parsed['draft_notes'] = trim((string) ($parsed['draft_notes'] ?? '') . ' MODEL não reescrito (proposta descartada).');
        }
        $parsed['model_used'] = (string) ($result['model'] ?? $wantedModel);
        $parsed['published'] = false;

        return $parsed;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $item
     * @return string|list<array<string, mixed>>
     */
    private function buildUserContent(array $payload, array $item)
    {
        $text = "Investigue este anúncio local. Não publique.\n" . json_encode($payload, JSON_UNESCAPED_UNICODE);
        $images = $this->pictureUrls($item, 3);
        if ($images === []) {
            return $text;
        }
        $content = [['type' => 'text', 'text' => $text]];
        foreach ($images as $url) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $item
     * @param list<array{code:string,label:string}> $blockers
     * @return array<string, mixed>
     */
    private function llmPayload(array $item, array $blockers, ?string $realModel): array
    {
        return [
            'mlb' => $item['ml_item_id'] ?? $item['id'] ?? '',
            'title' => $item['title'] ?? '',
            'status' => $item['status'] ?? '',
            'available_quantity' => $item['available_quantity'] ?? null,
            'listing_type_id' => $item['listing_type_id'] ?? null,
            'catalog_product_id' => $item['catalog_product_id'] ?? null,
            'catalog_listing' => $item['catalog_listing'] ?? false,
            'free_shipping' => is_array($item['shipping'] ?? null) ? !empty($item['shipping']['free_shipping']) : null,
            'photo_count' => is_array($item['pictures'] ?? null) ? count($item['pictures']) : 0,
            'brand' => $this->attributeValue($item, 'BRAND'),
            'model_real' => $realModel,
            'part_number' => $this->attributeValue($item, 'PART_NUMBER'),
            'domain_id' => $item['domain_id'] ?? null,
            'visits_30d' => $this->knownVisits($item),
            'sales_30d' => $this->knownSales($item),
            'official_blockers' => $blockers,
            'auto_parts' => $this->isAutoParts($item),
            'apply_blocked' => true,
            'published' => false,
        ];
    }

    private function parseLlmJson(string $content): ?array
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            if (preg_match('/\{.*\}/s', $content, $m) === 1) {
                $decoded = json_decode($m[0], true);
            }
        }
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'blockers' => is_array($decoded['blockers'] ?? null) ? $decoded['blockers'] : [],
            'draft_title' => $this->clipTitle((string) ($decoded['draft_title'] ?? '')),
            'draft_notes' => (string) ($decoded['draft_notes'] ?? ''),
            'model_attribute' => (string) ($decoded['model_attribute'] ?? ''),
            'published' => false,
        ];
    }

    private function modelAttributeAllowed(string $proposed, ?string $realModel): bool
    {
        $proposed = trim($proposed);
        if ($proposed === '') {
            return true;
        }
        $real = trim((string) $realModel);
        if ($real !== '' && mb_strtolower($proposed) === mb_strtolower($real)) {
            return true;
        }
        if ($this->looksLikeLongTail($proposed)) {
            return false;
        }
        // Any rewrite of MODEL that is not the real model string is forbidden.
        return $real !== '' && mb_strtolower($proposed) === mb_strtolower($real);
    }

    public function looksLikeLongTail(string $text): bool
    {
        $t = mb_strtolower($text);
        if (preg_match('/\b(kit|jogo|compativel|compatível|titan|fan|start|today|busca|seo|long-?tail|promo|desconto)\b/u', $t) === 1) {
            return true;
        }
        $words = preg_split('/\s+/', trim($text)) ?: [];

        return count($words) > 4;
    }

    /**
     * @param array<string, mixed> $item
     * @param list<array{code:string,label:string}> $blockers
     * @return array{draft_title:string, draft_notes:string}
     */
    public function rulesDraft(array $item, array $blockers, ?string $realModel): array
    {
        $brand = $this->attributeValue($item, 'BRAND');
        $part = $this->attributeValue($item, 'PART_NUMBER');
        $product = $this->productNoun($item);
        $specParts = [];
        if ($part) {
            $specParts[] = $part;
        }
        foreach (['COLOR', 'VOLTAGE', 'UNITS_PER_PACKAGE'] as $id) {
            $v = $this->attributeValue($item, $id);
            if ($v) {
                $specParts[] = $v;
            }
        }
        $bits = array_filter([$product, $brand, $realModel, implode(' ', $specParts)], static fn ($v) => $v !== null && $v !== '');
        $title = $this->clipTitle(trim(preg_replace('/\s+/', ' ', implode(' ', $bits)) ?: (string) ($item['title'] ?? '')));
        if ($this->isAutoParts($item)) {
            $title = $this->stripBikeList($title, $realModel);
        }
        $codes = array_column($blockers, 'code');
        $notes = 'rules: blockers=' . implode(',', $codes);
        if ($this->isAutoParts($item)) {
            $notes .= ' · compatibilidade no widget, não lista de motos no título';
        }
        $notes .= ' · MODEL=' . ($realModel ?: 'n/d (não inventar)');

        return ['draft_title' => $title, 'draft_notes' => $notes];
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array{code:string,label:string}>
     */
    public function officialBlockers(array $item): array
    {
        $photos = $item['pictures'] ?? [];
        $photoCount = is_array($photos) ? count($photos) : 0;
        $stock = (int) ($item['available_quantity'] ?? 0);
        $catalogId = trim((string) ($item['catalog_product_id'] ?? ''));
        $catalogListing = $item['catalog_listing'] ?? false;
        $isCatalogListing = $catalogListing === true || $catalogListing === 1 || $catalogListing === 'true';
        $shipping = $item['shipping'] ?? [];
        $freeShipping = is_array($shipping) && !empty($shipping['free_shipping']);
        $listingType = (string) ($item['listing_type_id'] ?? '');
        $out = [];
        if ($photoCount < 3) {
            $out[] = ['code' => 'photos_lt3', 'label' => 'menos de 3 fotos'];
        }
        if ($stock <= 0) {
            $out[] = ['code' => 'stock_0', 'label' => 'estoque 0'];
        }
        if ($catalogId !== '' && !$isCatalogListing) {
            $out[] = ['code' => 'catalog_not_listing', 'label' => 'tem catalog_product_id mas anúncio clássico'];
        }
        if (!$freeShipping) {
            $out[] = ['code' => 'no_free_shipping', 'label' => 'sem frete grátis'];
        }
        if ($listingType !== 'gold_pro') {
            $out[] = ['code' => 'not_premium', 'label' => 'não é Premium (gold_pro)'];
        }
        if ($this->hasUnansweredQuestions($item)) {
            $out[] = ['code' => 'unanswered_questions', 'label' => 'perguntas sem resposta'];
        }
        $visits = $this->knownVisits($item);
        $sales = $this->knownSales($item);
        if ($visits !== null && $visits > 0 && $sales === 0) {
            $out[] = ['code' => 'visits_no_sales', 'label' => 'visitas sem venda (30d)'];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pickCandidates(int $accountId, int $limit): array
    {
        $open = $this->openMlbSet($accountId);
        $items = $this->loadActiveItems($accountId);
        if ($items === null) {
            return [];
        }
        $scored = [];
        foreach ($items as $item) {
            if ((string) ($item['status'] ?? '') !== 'active') {
                continue;
            }
            $mlb = strtoupper(trim((string) ($item['ml_item_id'] ?? $item['id'] ?? '')));
            if ($mlb === '' || isset($open[$mlb])) {
                continue;
            }
            $blockers = $this->officialBlockers($item);
            if ($blockers === []) {
                continue;
            }
            $codes = array_column($blockers, 'code');
            $ficha = count(array_intersect($codes, [
                'photos_lt3', 'stock_0', 'catalog_not_listing', 'no_free_shipping', 'not_premium', 'unanswered_questions',
            ]));
            $hard = in_array('visits_no_sales', $codes, true) ? 1 : 0;
            if ($ficha === 0 && $hard === 0) {
                continue;
            }
            $visits = $this->knownVisits($item) ?? 0;
            $scored[] = [
                'score' => ($ficha * 1000) + ($hard * 100) + min(99, $visits),
                'item' => $item,
            ];
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $out = [];
        foreach ($scored as $row) {
            $out[] = $row['item'];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, true>
     */
    private function openMlbSet(int $accountId): array
    {
        $set = [];
        try {
            $stmt = $this->db->prepare(
                "SELECT mlb_id FROM listing_investigations WHERE account_id = ? AND status = ?"
            );
            $stmt->execute([$accountId, self::STATUS_OPEN]);
            while (($mlb = $stmt->fetchColumn()) !== false) {
                $set[strtoupper(trim((string) $mlb))] = true;
            }
        } catch (Throwable) {
            return [];
        }

        return $set;
    }

    private function closeSold(int $accountId): int
    {
        $items = $this->loadActiveItems($accountId);
        if ($items === null) {
            return 0;
        }
        $sold = [];
        foreach ($items as $item) {
            $sales30 = null;
            foreach (['sales_30d', '_sales_30d'] as $key) {
                if (array_key_exists($key, $item) && is_numeric($item[$key])) {
                    $sales30 = (int) $item[$key];
                    break;
                }
            }
            if ($sales30 === null || $sales30 <= 0) {
                continue;
            }
            $mlb = strtoupper(trim((string) ($item['ml_item_id'] ?? $item['id'] ?? '')));
            if ($mlb !== '') {
                $sold[$mlb] = true;
            }
        }
        if ($sold === []) {
            return 0;
        }
        $closed = 0;
        try {
            $stmt = $this->db->prepare(
                "UPDATE listing_investigations SET status = ? WHERE account_id = ? AND status = ? AND mlb_id = ?"
            );
            foreach (array_keys($sold) as $mlb) {
                $stmt->execute([self::STATUS_SOLD, $accountId, self::STATUS_OPEN, $mlb]);
                $closed += $stmt->rowCount();
            }
        } catch (Throwable) {
            return 0;
        }

        return $closed;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function loadActiveItems(int $accountId): ?array
    {
        $sqlVariants = [
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
            $mapped = $this->mapLocalItemRow($row, $accountId);
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
    private function mapLocalItemRow(array $row, int $accountId = 0): ?array
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
        $item['_account_id'] = $accountId;
        if (!array_key_exists('catalog_listing', $item)) {
            $item['catalog_listing'] = false;
        }
        foreach (['performance_score', 'visits_30d', '_visits_30d', 'sales_30d', '_sales_30d', 'listing_type_id', 'domain_id'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $item[$key] = $data[$key];
            }
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     */
    public function isHardCase(array $item): bool
    {
        $visits = $this->knownVisits($item);
        $sales = $this->knownSales($item);

        return $visits !== null && $visits > 0 && $sales === 0;
    }

    /**
     * @param array<string, mixed> $item
     */
    public function knownVisits(array $item): ?int
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
    public function knownSales(array $item): int
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
    public function realModel(array $item): ?string
    {
        return $this->attributeValue($item, 'MODEL');
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
    public function isAutoParts(array $item): bool
    {
        $domain = strtoupper((string) ($item['domain_id'] ?? ''));
        $cat = strtoupper((string) ($item['category_id'] ?? ''));
        $brand = strtoupper((string) ($this->attributeValue($item, 'BRAND') ?? ''));
        $title = strtoupper((string) ($item['title'] ?? ''));
        $blob = $domain . ' ' . $cat . ' ' . $title . ' ' . $brand;

        return str_contains($blob, 'MOTORCYCLE')
            || str_contains($blob, 'AUTO_PART')
            || str_contains($blob, 'VEHICLE')
            || str_contains($blob, 'SPARE')
            || $brand === 'AWA'
            || (bool) preg_match('/\b(TITAN|FAN|START|TODAY|BIZ|POP|CG|FAZER|BROS)\b/u', $title);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function productNoun(array $item): string
    {
        $title = trim((string) ($item['title'] ?? ''));
        $brand = (string) $this->attributeValue($item, 'BRAND');
        $model = (string) $this->realModel($item);
        $words = preg_split('/\s+/', $title) ?: [];
        $keep = [];
        foreach ($words as $w) {
            $lw = mb_strtolower($w);
            if ($brand !== '' && mb_strtolower($brand) === $lw) {
                continue;
            }
            if ($model !== '' && str_contains(mb_strtolower($model), $lw)) {
                continue;
            }
            if ($this->looksLikeLongTail($w)) {
                continue;
            }
            $keep[] = $w;
            if (count($keep) >= 3) {
                break;
            }
        }
        $noun = trim(implode(' ', $keep));

        return $noun !== '' ? $noun : (string) ($words[0] ?? 'Peça');
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

        return $title !== '' ? $title : 'Peça';
    }

    private function clipTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        if (mb_strlen($title) <= self::TITLE_MAX) {
            return $title;
        }

        return rtrim(mb_substr($title, 0, self::TITLE_MAX));
    }

    /**
     * @param array<string, mixed> $item
     * @return list<string>
     */
    private function pictureUrls(array $item, int $max): array
    {
        $out = [];
        foreach ($item['pictures'] ?? [] as $pic) {
            if (is_string($pic) && str_starts_with($pic, 'http')) {
                $out[] = $pic;
            } elseif (is_array($pic)) {
                $url = (string) ($pic['secure_url'] ?? $pic['url'] ?? '');
                if (str_starts_with($url, 'http')) {
                    $out[] = $url;
                }
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function hasUnansweredQuestions(array $item): bool
    {
        $mlb = strtoupper(trim((string) ($item['ml_item_id'] ?? $item['id'] ?? '')));
        $accountId = (int) ($item['_account_id'] ?? 0);
        if ($mlb === '') {
            return false;
        }
        $sqlVariants = [
            "SELECT COUNT(*) FROM ml_questions WHERE account_id = ? AND UPPER(item_id) = ? AND UPPER(status) = 'UNANSWERED'",
            "SELECT COUNT(*) FROM ml_questions WHERE account_id = ? AND UPPER(item_id) = ? AND status = 'UNANSWERED'",
        ];
        if ($accountId <= 0) {
            return false;
        }
        foreach ($sqlVariants as $sql) {
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$accountId, $mlb]);
                return ((int) $stmt->fetchColumn()) > 0;
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * @param list<array{code:string,label:string}> $base
     * @param mixed $extra
     * @return list<array{code:string,label:string}>
     */
    private function mergeBlockers(array $base, $extra): array
    {
        $byCode = [];
        foreach ($base as $b) {
            $byCode[$b['code']] = $b;
        }
        if (!is_array($extra)) {
            return array_values($byCode);
        }
        $allowed = [
            'photos_lt3', 'stock_0', 'catalog_not_listing', 'no_free_shipping',
            'not_premium', 'unanswered_questions', 'visits_no_sales',
        ];
        foreach ($extra as $b) {
            if (is_string($b) && in_array($b, $allowed, true) && !isset($byCode[$b])) {
                $byCode[$b] = ['code' => $b, 'label' => $b];
            } elseif (is_array($b)) {
                $code = (string) ($b['code'] ?? '');
                if (in_array($code, $allowed, true) && !isset($byCode[$code])) {
                    $byCode[$code] = [
                        'code' => $code,
                        'label' => (string) ($b['label'] ?? $code),
                    ];
                }
            }
        }

        return array_values($byCode);
    }

    private function appendModelContractNote(string $notes, ?string $realModel, array $item): string
    {
        $notes = trim($notes);
        if ($realModel) {
            $notes .= ' · MODEL locked=' . $realModel;
        } else {
            $notes .= ' · MODEL ausente no atributo (não inventar)';
        }
        if ($this->isAutoParts($item)) {
            if (!str_contains($notes, 'widget')) {
                $notes .= ' · auto parts: widget, não lista de bike';
            }
        }

        return $notes;
    }

    /**
     * @return list<array{code:string,label:string}>
     */
    private function decodeBlockers($raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
        }
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $b) {
            if (is_string($b)) {
                $out[] = ['code' => $b, 'label' => $b];
            } elseif (is_array($b) && isset($b['code'])) {
                $out[] = ['code' => (string) $b['code'], 'label' => (string) ($b['label'] ?? $b['code'])];
            }
        }

        return $out;
    }
}
