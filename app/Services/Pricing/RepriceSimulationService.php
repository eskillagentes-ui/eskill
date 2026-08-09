<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Agents\SniperAgent;
use App\Database;
use App\Services\AI\SEO\CompetitorSpy;
use App\Services\HiddenSeo\SafetyGuard;
use Monolog\Logger;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * RepriceSimulationService — Fase 0 do repricing automático (read-only).
 *
 * Para os itens candidatos (items.auto_reprice = 1, status = 'active'), calcula
 * o preço que o SniperAgent sugeriria (regra pura SniperAgent::computeTargetPrice)
 * SEM escrever nada — nem no Mercado Livre, nem no banco.
 *
 * Tetos duros (config via .env, nunca clamp silencioso — violação = skip + motivo):
 *  - REPRICE_MAX_PCT:           |delta %| máximo por execução
 *  - REPRICE_MAX_ITEMS_PER_RUN: máximo de itens avaliados por execução
 *  - REPRICE_MIN_MARGIN_PCT:    preço mínimo = cost_price × (1 + margem/100)
 *
 * Conta em FORBIDDEN_ACCOUNTS (SafetyGuard): a simulação roda (dry-run é sempre
 * permitido), mas seria_aplicado é sempre false, pois o apply real seria bloqueado.
 *
 * Spec: .github/prompts/repricing-automatico.prompt.md
 */
class RepriceSimulationService
{
    public const DEFAULT_MAX_PCT = 5.0;
    public const DEFAULT_MAX_ITEMS_PER_RUN = 5;
    public const DEFAULT_MIN_MARGIN_PCT = 10.0;

    private PDO $db;

    /** @var callable(string, int): array<string, mixed> */
    private $marketDataProvider;

    private SafetyGuard $safetyGuard;
    private LoggerInterface $logger;
    private float $maxPct;
    private int $maxItemsPerRun;
    private float $minMarginPct;

    /**
     * @param callable(string, int): array<string, mixed>|null $marketDataProvider
     *        fn(title, accountId) => array no formato CompetitorSpy::spyProduct()
     *        (usa 'price_analysis.min'). Injetável para testes — nunca rede real em teste.
     */
    public function __construct(
        ?PDO $db = null,
        ?callable $marketDataProvider = null,
        ?SafetyGuard $safetyGuard = null,
        ?LoggerInterface $logger = null,
        ?float $maxPct = null,
        ?int $maxItemsPerRun = null,
        ?float $minMarginPct = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->marketDataProvider = $marketDataProvider ?? static function (string $title, int $accountId): array {
            return (new CompetitorSpy($accountId))->spyProduct($title, 15);
        };
        $this->safetyGuard = $safetyGuard ?? new SafetyGuard();
        $this->logger = $logger ?? new Logger('reprice-simulation');
        $this->maxPct = $maxPct ?? $this->envFloat('REPRICE_MAX_PCT', self::DEFAULT_MAX_PCT);
        $this->maxItemsPerRun = $maxItemsPerRun ?? max(
            1,
            (int)$this->envFloat('REPRICE_MAX_ITEMS_PER_RUN', (float)self::DEFAULT_MAX_ITEMS_PER_RUN)
        );
        $this->minMarginPct = $minMarginPct ?? $this->envFloat('REPRICE_MIN_MARGIN_PCT', self::DEFAULT_MIN_MARGIN_PCT);
    }

    /**
     * Roda a simulação para uma conta e retorna o relatório completo.
     *
     * @return array<string, mixed>
     */
    public function simulate(int $accountId, ?int $limit = null): array
    {
        $maxItems = $this->maxItemsPerRun;
        if ($limit !== null && $limit > 0) {
            $maxItems = min($limit, $maxItems);
        }

        // Dry-run é sempre permitido pelo SafetyGuard; mas se a conta está na
        // blacklist, o apply real seria bloqueado — logo nada "seria aplicado".
        $applyBlocked = $this->safetyGuard->isForbidden($accountId);

        $report = [
            'mode' => 'simulation',
            'generated_at' => date('c'),
            'account_id' => $accountId,
            'config' => [
                'reprice_max_pct' => $this->maxPct,
                'reprice_max_items_per_run' => $this->maxItemsPerRun,
                'reprice_min_margin_pct' => $this->minMarginPct,
                'effective_item_limit' => $maxItems,
            ],
            'safety' => [
                'forbidden_account' => $applyBlocked,
                'safe_mode' => $this->safetyGuard->isSafeMode(),
            ],
            'summary' => [
                'candidatos' => 0,
                'seria_aplicado' => 0,
                'skipped' => 0,
            ],
            'items' => [],
        ];

        if ($applyBlocked) {
            $this->logger->warning('Conta em FORBIDDEN_ACCOUNTS: simulação read-only permitida, apply seria bloqueado', [
                'account_id' => $accountId,
            ]);
        }

        $candidates = $this->fetchCandidates($accountId, $maxItems);

        foreach ($candidates as $item) {
            $entry = $this->evaluateCandidate($item, $applyBlocked);
            $report['items'][] = $entry;
            $report['summary']['candidatos']++;
            if ($entry['seria_aplicado']) {
                $report['summary']['seria_aplicado']++;
            } else {
                $report['summary']['skipped']++;
            }

            $this->logger->info('reprice.decision', [
                'account_id' => $accountId,
                'item_id' => $entry['item_id'],
                'preco_atual' => $entry['preco_atual'],
                'preco_sugerido' => $entry['preco_sugerido'],
                'delta_pct' => $entry['delta_pct'],
                'motivo' => $entry['motivo'],
                'seria_aplicado' => $entry['seria_aplicado'],
                'motivo_skip' => $entry['motivo_skip'],
            ]);
        }

        $this->logger->info('reprice.simulation.finished', [
            'account_id' => $accountId,
            'summary' => $report['summary'],
        ]);

        return $report;
    }

    /**
     * Itens candidatos: mesma fonte do SniperAgent (items.auto_reprice = 1),
     * com cost_price para o piso de margem.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchCandidates(int $accountId, int $maxItems): array
    {
        $stmt = $this->db->prepare("
            SELECT account_id, ml_item_id, title, price, min_price, max_price, cost_price
            FROM items
            WHERE auto_reprice = 1
              AND status = 'active'
              AND account_id = :account_id
            ORDER BY ml_item_id
            LIMIT :limit
        ");
        $stmt->bindValue('account_id', $accountId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $maxItems, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Avalia um item e monta a entrada do relatório. Read-only: nenhuma escrita.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function evaluateCandidate(array $item, bool $applyBlocked): array
    {
        $itemId = (string)($item['ml_item_id'] ?? '');
        $title = (string)($item['title'] ?? '');
        $price = (float)($item['price'] ?? 0);
        $minAllowed = (float)($item['min_price'] ?? 0);
        $maxAllowed = (float)($item['max_price'] ?? 0);
        $cost = isset($item['cost_price']) && $item['cost_price'] !== null ? (float)$item['cost_price'] : 0.0;

        $entry = [
            'item_id' => $itemId,
            'title' => $title,
            'preco_atual' => round($price, 2),
            'preco_sugerido' => null,
            'delta_pct' => null,
            'motivo' => null,
            'seria_aplicado' => false,
            'motivo_skip' => null,
        ];

        if ($minAllowed <= 0) {
            $entry['motivo_skip'] = 'min_price_nao_definido';
            return $entry;
        }

        try {
            $market = ($this->marketDataProvider)($title, (int)($item['account_id'] ?? 0));
        } catch (\Throwable $e) {
            $entry['motivo_skip'] = 'erro_dados_mercado: ' . $e->getMessage();
            return $entry;
        }

        if (isset($market['error']) || empty($market['price_analysis'])) {
            $reason = isset($market['error']) ? (string)$market['error'] : 'price_analysis vazio';
            $entry['motivo_skip'] = 'dados_mercado_indisponiveis: ' . $reason;
            return $entry;
        }

        $marketMin = (float)($market['price_analysis']['min'] ?? 0);
        if ($marketMin <= 0) {
            $entry['motivo_skip'] = 'market_min_invalido';
            return $entry;
        }

        // Mesma regra de decisão do SniperAgent (métodos puros, sem escrita)
        $targetPrice = SniperAgent::computeTargetPrice($price, $marketMin, $minAllowed, $maxAllowed);
        $deltaPct = $price > 0 ? (($targetPrice - $price) / $price) * 100 : 0.0;

        $entry['preco_sugerido'] = round($targetPrice, 2);
        $entry['delta_pct'] = round($deltaPct, 2);

        if (!SniperAgent::requiresPriceChange($price, $targetPrice)) {
            $entry['motivo'] = 'Preço já alinhado ao mercado (|delta| <= R$ 0,05)';
            $entry['motivo_skip'] = 'sem_mudanca_significativa';
            return $entry;
        }

        $entry['motivo'] = sprintf(
            'Sniper %s: R$ %.2f → R$ %.2f (min mercado R$ %.2f)',
            $targetPrice < $price ? 'Shot (baixar)' : 'Profit (subir)',
            $price,
            $targetPrice,
            $marketMin
        );

        // Tetos duros — violação = skip + motivo, nunca clamp silencioso
        if (abs($deltaPct) > $this->maxPct) {
            $entry['motivo_skip'] = sprintf(
                'delta_acima_do_teto: |%.2f%%| > REPRICE_MAX_PCT=%.2f%%',
                $deltaPct,
                $this->maxPct
            );
            return $entry;
        }

        if ($cost > 0) {
            $floor = $cost * (1 + $this->minMarginPct / 100);
            if ($targetPrice < $floor) {
                $entry['motivo_skip'] = sprintf(
                    'abaixo_do_piso_de_margem: R$ %.2f < custo R$ %.2f × (1 + %.2f%%) = R$ %.2f',
                    $targetPrice,
                    $cost,
                    $this->minMarginPct,
                    $floor
                );
                return $entry;
            }
        }

        if ($applyBlocked) {
            $entry['motivo_skip'] = 'conta_proibida: apply bloqueado pelo SafetyGuard (FORBIDDEN_ACCOUNTS)';
            return $entry;
        }

        $entry['seria_aplicado'] = true;
        return $entry;
    }

    private function envFloat(string $key, float $default): float
    {
        $raw = getenv($key);
        if ($raw === false || $raw === '') {
            $raw = $_ENV[$key] ?? null;
        }
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $default;
        }
        return (float)$raw;
    }
}
