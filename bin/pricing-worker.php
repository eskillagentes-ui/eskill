<?php

declare(strict_types=1);

/**
 * Pricing Rules Worker
 * 
 * Worker para execução automática de regras de precificação.
 * Deve ser executado via cron (a cada hora ou conforme configurado)
 * 
 * Uso: php bin/pricing-worker.php [--account=ID] [--dry-run] [--verbose]
 * 
 * @package App\Jobs
 */

require_once __DIR__ . '/../autoload.php';

\App\Helpers\MlWriteAutomation::exitIfDisabledForCli(basename(__FILE__));

use App\Database;
use App\Services\PricingScenarioService;
use App\Services\MarginCalculatorService;
use App\Services\MercadoLivreClient;

class PricingWorker
{
    private PDO $db;
    private bool $dryRun = false;
    private bool $verbose = false;
    private ?int $accountId = null;
    private bool $competitorOnly = false;
    private bool $alertsOnly = false;
    private array $stats = [
        'rules_processed' => 0,
        'items_analyzed' => 0,
        'prices_updated' => 0,
        'alerts_generated' => 0,
        'errors' => 0
    ];

    public function __construct(array $options = [])
    {
        $this->db = Database::getInstance();
        $this->dryRun = $options['dry-run'] ?? false;
        $this->verbose = $options['verbose'] ?? false;
        $this->accountId = $options['account'] ?? null;
        $this->competitorOnly = $options['competitor-only'] ?? false;
        $this->alertsOnly = $options['alerts-only'] ?? false;
    }

    /**
     * Executa o worker principal
     */
    public function run(): void
    {
        $this->log("=== Pricing Rules Worker Started ===");
        $this->log("Mode: " . ($this->dryRun ? "DRY RUN" : "LIVE"));
        if ($this->competitorOnly) $this->log("Mode: COMPETITOR ONLY");
        if ($this->alertsOnly) $this->log("Mode: ALERTS ONLY");
        $this->log("Time: " . date('Y-m-d H:i:s'));
        $this->log("");

        try {
            if (!$this->competitorOnly && !$this->alertsOnly) {
                // Buscar regras ativas que precisam ser executadas
                $rules = $this->getActiveRules();
                $this->log("Found " . count($rules) . " active rules to process");

                foreach ($rules as $rule) {
                    $this->processRule($rule);
                }
            }

            if (!$this->alertsOnly) {
                // Atualizar cache de concorrentes para itens monitorados
                $this->updateCompetitorCache();
            }

            if (!$this->competitorOnly) {
                // Gerar alertas de margem baixa
                $this->generateMarginAlerts();
            }

            $this->printStats();

        } catch (\Throwable $e) {
            $this->log("FATAL ERROR: " . $e->getMessage(), 'error');
            $this->stats['errors']++;
        }

        $this->log("");
        $this->log("=== Worker Finished ===");
    }

    /**
     * Busca regras ativas que precisam ser executadas
     */
    private function getActiveRules(): array
    {
        $sql = "SELECT * FROM pricing_rules 
                WHERE ativo = 1 
                AND execucao_automatica = 1
                AND (ultima_execucao IS NULL OR ultima_execucao < DATE_SUB(NOW(), INTERVAL intervalo_verificacao HOUR))";
        
        $params = [];
        
        if ($this->accountId) {
            $sql .= " AND account_id = :account_id";
            $params['account_id'] = $this->accountId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Processa uma regra de precificação
     */
    private function processRule(array $rule): void
    {
        $this->stats['rules_processed']++;
        $this->log("Processing rule: {$rule['nome']} (ID: {$rule['id']})");

        try {
            $accountId = (int)$rule['account_id'];
            $scenarioService = new PricingScenarioService($accountId);
            $marginService = new MarginCalculatorService($accountId);
            $mlClient = new MercadoLivreClient($accountId);

            // Buscar itens aplicáveis à regra
            $items = $this->getItemsForRule($rule, $mlClient);
            $this->log("  Found " . count($items) . " items for this rule");

            foreach ($items as $item) {
                $this->stats['items_analyzed']++;
                
                try {
                    $this->analyzeAndApplyRule($item, $rule, $marginService, $mlClient);
                } catch (\Throwable $e) {
                    $this->log("  Error processing item {$item['id']}: " . $e->getMessage(), 'error');
                    $this->stats['errors']++;
                }
            }

            // Atualizar última execução
            if (!$this->dryRun) {
                $stmt = $this->db->prepare("UPDATE pricing_rules SET ultima_execucao = NOW() WHERE id = :id");
                $stmt->execute(['id' => $rule['id']]);
            }

        } catch (\Throwable $e) {
            $this->log("  Rule error: " . $e->getMessage(), 'error');
            $this->stats['errors']++;
        }
    }

    /**
     * Busca itens aplicáveis a uma regra
     */
    private function getItemsForRule(array $rule, MercadoLivreClient $mlClient): array
    {
        // Se tem item_ids específicos
        if (!empty($rule['aplica_item_ids'])) {
            $itemIds = json_decode($rule['aplica_item_ids'], true);
            if (!empty($itemIds)) {
                $itemsData = $mlClient->get("/items", ['ids' => implode(',', array_slice($itemIds, 0, 20))]);
                return array_map(fn($item) => $item['body'] ?? $item, $itemsData);
            }
        }

        // Buscar por categoria
        $params = ['status' => 'active', 'limit' => 50];
        
        $response = $mlClient->getMyItems($params);
        $itemIds = $response['results'] ?? [];

        if (empty($itemIds)) {
            return [];
        }

        $itemsData = $mlClient->get("/items", ['ids' => implode(',', array_slice($itemIds, 0, 20))]);
        $items = array_map(fn($item) => $item['body'] ?? $item, $itemsData);

        // Filtrar por categoria se especificada
        if (!empty($rule['aplica_categoria'])) {
            $items = array_filter($items, fn($item) => 
                ($item['category_id'] ?? '') === $rule['aplica_categoria']
            );
        }

        return array_values($items);
    }

    /**
     * Analisa item e aplica regra se necessário
     */
    private function analyzeAndApplyRule(array $item, array $rule, MarginCalculatorService $marginService, MercadoLivreClient $mlClient): void
    {
        $itemId = $item['id'];
        $precoAtual = (float)$item['price'];
        
        // Buscar custos
        $custos = $marginService->getCustosProduto($itemId);
        if (!$custos) {
            $this->log("    ⚠️ {$itemId}: Sem custos cadastrados, pulando...", 'warning');
            return;
        }

        // Calcular margem atual
        $margemAtual = $marginService->calcularMargem($precoAtual, $custos);
        $margem = $margemAtual['margem_real'] ?? 0;

        // Aplicar estratégia
        $novoPreco = $this->calculateNewPrice($item, $rule, $custos, $marginService);

        if ($novoPreco === null || abs($novoPreco - $precoAtual) < 0.01) {
            $this->log("    ✓ {$itemId}: Preço OK (R$ {$precoAtual}, margem: {$margem}%)", 'info');
            return;
        }

        // Verificar limites de alteração
        $variacao = (($novoPreco - $precoAtual) / $precoAtual) * 100;
        $limiteAumento = (float)$rule['limite_aumento_ranking'];

        if ($variacao > $limiteAumento) {
            $this->log("    ⚠️ {$itemId}: Aumento de {$variacao}% excede limite de {$limiteAumento}%", 'warning');
            $novoPreco = $precoAtual * (1 + $limiteAumento / 100);
        }

        // Aplicar preço
        $this->log("    📊 {$itemId}: R$ {$precoAtual} → R$ " . number_format($novoPreco, 2));

        if (!$this->dryRun) {
            $response = $mlClient->put("/items/{$itemId}", ['price' => $novoPreco]);

            if (!isset($response['error'])) {
                $this->stats['prices_updated']++;
                
                // Registrar no histórico
                $marginService->registrarAlteracaoPreco($itemId, $precoAtual, $novoPreco, [
                    'origem' => 'auto',
                    'motivo' => "Regra: {$rule['nome']}",
                    'estrategia' => $rule['estrategia']
                ]);

                $this->log("    ✅ Preço atualizado com sucesso!", 'success');
            } else {
                $this->log("    ❌ Erro ao atualizar: " . ($response['message'] ?? 'Desconhecido'), 'error');
                $this->stats['errors']++;
            }
        }
    }

    /**
     * Calcula novo preço baseado na estratégia
     */
    private function calculateNewPrice(array $item, array $rule, array $custos, MarginCalculatorService $marginService): ?float
    {
        $precoAtual = (float)$item['price'];
        $estrategia = $rule['estrategia'];
        $margemMinima = (float)$rule['margem_minima'];
        $margemAlvo = (float)$rule['margem_alvo'];

        switch ($estrategia) {
            case 'competitivo':
                // Manter preço competitivo, respeitando margem mínima
                $precoMinimo = $marginService->calcularPrecoMinimo($custos, $margemMinima);
                return max($precoMinimo['preco_minimo'] ?? $precoAtual, $precoAtual * 0.95);

            case 'agressivo':
                // Preço no limite da margem mínima
                $precoMinimo = $marginService->calcularPrecoMinimo($custos, $margemMinima);
                return $precoMinimo['preco_minimo'] ?? $precoAtual;

            case 'premium':
                // Garantir margem alvo
                $precoAlvo = $marginService->calcularPrecoMinimo($custos, $margemAlvo);
                return max($precoAlvo['preco_minimo'] ?? $precoAtual, $precoAtual);

            case 'valor':
                // Equilíbrio entre margem e competitividade
                $precoMinimoMin = $marginService->calcularPrecoMinimo($custos, $margemMinima);
                $precoMinimoAlvo = $marginService->calcularPrecoMinimo($custos, $margemAlvo);
                $medio = (($precoMinimoMin['preco_minimo'] ?? 0) + ($precoMinimoAlvo['preco_minimo'] ?? 0)) / 2;
                return $medio > 0 ? $medio : $precoAtual;

            case 'liquidacao':
                // Preço mínimo absoluto (5% margem)
                $precoMinimo = $marginService->calcularPrecoMinimo($custos, 5);
                return $precoMinimo['preco_minimo'] ?? $precoAtual;

            default:
                return null;
        }
    }

    /**
     * Atualiza cache de preços de concorrentes
     */
    private function updateCompetitorCache(): void
    {
        $this->log("");
        $this->log("Updating competitor price cache...");

        // Buscar itens monitorados com cache expirado
        $sql = "SELECT DISTINCT item_id, account_id, category_id 
                FROM competitor_pricing_cache 
                WHERE (expira_em < NOW() OR expira_em IS NULL)";
        
        $params = [];
        if ($this->accountId) {
            $sql .= " AND account_id = :account_id";
            $params['account_id'] = (int) $this->accountId;
        }
        $sql .= " LIMIT 50";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->log("Found " . count($items) . " items with expired cache");

        // Implementação simplificada - atualizar expira_em
        if (!$this->dryRun && count($items) > 0) {
            $stmt = $this->db->prepare(
                "UPDATE competitor_pricing_cache SET expira_em = DATE_ADD(NOW(), INTERVAL 6 HOUR) WHERE item_id = :item_id"
            );
            foreach ($items as $item) {
                $stmt->execute(['item_id' => $item['item_id']]);
            }
        }
    }

    /**
     * Gera alertas para itens com margem baixa
     */
    private function generateMarginAlerts(): void
    {
        $this->log("");
        $this->log("Checking for low margin items...");

        $sql = "SELECT pc.*, 
                       (SELECT preco_anterior FROM pricing_history WHERE item_id = pc.item_id ORDER BY data_mudanca DESC LIMIT 1) as ultimo_preco
                FROM product_costs pc
                WHERE pc.margem_minima > 0";
        
        if ($this->accountId) {
            $sql .= " AND pc.account_id = :account_id";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->accountId ? ['account_id' => $this->accountId] : []);
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            // Verificar se precisa gerar alerta
            if (!empty($item['preco_minimo_calculado']) && !empty($item['ultimo_preco'])) {
                if ($item['ultimo_preco'] < $item['preco_minimo_calculado']) {
                    $this->stats['alerts_generated']++;
                    $this->log("  ⚠️ Alerta: {$item['item_id']} - Preço abaixo do mínimo calculado");
                }
            }
        }

        $this->log("Generated {$this->stats['alerts_generated']} margin alerts");
    }

    /**
     * Log helper
     */
    private function log(string $message, string $level = 'info'): void
    {
        if (!$this->verbose && $level === 'info' && strpos($message, '===') === false) {
            return;
        }

        $prefix = '';
        switch ($level) {
            case 'error':
                $prefix = "\033[31m[ERROR]\033[0m ";
                break;
            case 'warning':
                $prefix = "\033[33m[WARN]\033[0m ";
                break;
            case 'success':
                $prefix = "\033[32m[OK]\033[0m ";
                break;
        }

        echo $prefix . $message . PHP_EOL;
    }

    /**
     * Imprime estatísticas
     */
    private function printStats(): void
    {
        $this->log("");
        $this->log("=== Statistics ===");
        $this->log("Rules processed: {$this->stats['rules_processed']}");
        $this->log("Items analyzed: {$this->stats['items_analyzed']}");
        $this->log("Prices updated: {$this->stats['prices_updated']}");
        $this->log("Alerts generated: {$this->stats['alerts_generated']}");
        $this->log("Errors: {$this->stats['errors']}");
    }
}

// =========================================
// CLI Execution
// =========================================
if (php_sapi_name() === 'cli') {
    $options = getopt('', ['account:', 'dry-run', 'verbose', 'help', 'competitor-only', 'alerts-only']);

    if (isset($options['help'])) {
        echo "Pricing Rules Worker\n";
        echo "Usage: php pricing-worker.php [options]\n\n";
        echo "Options:\n";
        echo "  --account=ID       Process only specific account\n";
        echo "  --dry-run          Simulate without making changes\n";
        echo "  --verbose          Show detailed output\n";
        echo "  --competitor-only  Only update competitor cache\n";
        echo "  --alerts-only      Only generate margin alerts\n";
        echo "  --help             Show this help\n";
        exit(0);
    }

    $worker = new PricingWorker([
        'account' => isset($options['account']) ? (int)$options['account'] : null,
        'dry-run' => isset($options['dry-run']),
        'verbose' => isset($options['verbose']),
        'competitor-only' => isset($options['competitor-only']),
        'alerts-only' => isset($options['alerts-only']),
    ]);

    $worker->run();
}
