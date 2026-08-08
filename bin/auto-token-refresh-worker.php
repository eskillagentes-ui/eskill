#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Auto Token Refresh & Data Sync Worker
 *
 * Worker automático que:
 * 1. Renova tokens do ML/MP automaticamente
 * 2. Sincroniza dados reais das APIs
 * 3. Atualiza cache local
 *
 * Executar via cron:
 * 0,30 * * * * /usr/bin/php /path/to/auto-token-refresh-worker.php >> /var/log/token-sync.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Carregar variáveis de ambiente
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

\App\Helpers\TimezoneHelper::applyFromEnv();

use App\Database;
use App\Services\UnifiedTokenRefreshService;
use App\Services\MercadoLivreClient;
use App\Services\ItemService;
use App\Services\OrderService;
use App\Services\QuestionService;
use App\Services\MercadoLivreOrchestratorService;

class AutoTokenRefreshWorker
{
    private $db;
    private $tokenService;
    private $startTime;
    private $stats = [
        'tokens_refreshed' => 0,
        'tokens_failed' => 0,
        'items_synced' => 0,
        'orders_synced' => 0,
        'questions_synced' => 0,
        'payments_synced' => 0,
        'errors' => []
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->tokenService = new UnifiedTokenRefreshService();
        $this->startTime = microtime(true);
    }

    /**
     * Executa worker completo
     */
    public function run(): void
    {
        $this->log("🚀 Auto Token Refresh Worker - Iniciando", 'info');

        try {
            // Fase 1: Refresh Tokens
            $this->log("📝 Fase 1: Renovando tokens...", 'info');
            $this->refreshTokens();

            // Fase 2: Sincronizar Dados ML
            $this->log("🔄 Fase 2: Sincronizando dados Mercado Livre...", 'info');
            $this->syncMercadoLivreData();

            // Fase 3: Sincronizar Dados MP (se configurado)
            if ($this->isMercadoPagoEnabled()) {
                $this->log("💳 Fase 3: Sincronizando Mercado Pago...", 'info');
                $this->syncMercadoPagoData();
            }

            // Fase 4: Estatísticas
            $this->printSummary();
        } catch (\Exception $e) {
            $this->log("❌ Erro fatal: " . $e->getMessage(), 'error');
            $this->stats['errors'][] = $e->getMessage();
        }

        $this->log("✅ Worker finalizado", 'info');
    }

    /**
     * Executa apenas a fase legada de sync Mercado Pago (compatibilidade temporária).
     */
    public function runMercadoPagoOnly(): void
    {
        $this->log("🚀 Auto Token Refresh Worker - modo compatibilidade MP (apenas Mercado Pago)", 'info');

        try {
            if ($this->isMercadoPagoEnabled()) {
                $this->syncMercadoPagoData();
            } else {
                $this->log("⏭️ Mercado Pago desabilitado; nada a fazer", 'info');
            }
            $this->printSummary();
        } catch (\Throwable $e) {
            $this->log("❌ Erro no modo compatibilidade MP: " . $e->getMessage(), 'error');
            $this->stats['errors'][] = $e->getMessage();
        }
    }

    /**
     * Fase 1: Renovar tokens
     */
    private function refreshTokens(): void
    {
        // Renovar tokens que expiram nas próximas 2 horas
        $result = $this->tokenService->refreshExpiring(120);

        $this->stats['tokens_refreshed'] = $result['refreshed'] ?? 0;
        $this->stats['tokens_failed'] = $result['failed'] ?? 0;

        $this->log(sprintf(
            "   ✅ Tokens: %d renovados, %d falhas",
            $this->stats['tokens_refreshed'],
            $this->stats['tokens_failed']
        ), 'info');
    }

    /**
     * Fase 2: Sincronizar dados do Mercado Livre
     */
    private function syncMercadoLivreData(): void
    {
        // Buscar contas ativas com tokens válidos
        $accounts = $this->getActiveAccounts();

        foreach ($accounts as $account) {
            $this->log("   → Conta: {$account['user_id']} ({$account['nickname']})", 'info');

            try {
                // Sincronizar itens
                $this->syncItems($account['id']);

                // Sincronizar pedidos recentes
                $this->syncOrders($account['id']);

                // Sincronizar perguntas não respondidas
                $this->syncQuestions($account['id']);

                // Delay entre contas para evitar rate limit
                usleep(500000); // 0.5 segundos

            } catch (\Exception $e) {
                $this->log("   ❌ Erro na conta {$account['user_id']}: " . $e->getMessage(), 'error');
                $this->stats['errors'][] = "Conta {$account['user_id']}: {$e->getMessage()}";
            }
        }
    }

    /**
     * Sincroniza itens de uma conta
     */
    private function syncItems(int $accountId): void
    {
        try {
            $client = new MercadoLivreClient($accountId);
            $sellerId = $client->getSellerId();
            if (!$sellerId) {
                $this->log("      ✗ Seller ID não encontrado para sincronizar itens", 'warning');
                return;
            }

            // Buscar itens ativos (primeira página apenas para economizar API calls)
            $response = $this->unwrapMlResponse($client->get("/users/{$sellerId}/items/search", [
                'status' => 'active',
                'limit' => 50
            ]));

            if (!isset($response['results']) || !is_array($response['results'])) {
                return;
            }

            $synced = 0;
            foreach ($response['results'] as $itemId) {
                try {
                    // Buscar detalhes do item
                    $item = $this->unwrapMlResponse($client->get("/items/{$itemId}"));
                    if (isset($item['error'])) {
                        continue;
                    }

                    // Atualizar no banco
                    $this->updateItemInDatabase($accountId, $item);
                    $synced++;

                    usleep(100000); // 0.1s entre itens

                } catch (\Exception $e) {
                    // Ignorar erros em itens individuais
                    continue;
                }
            }

            $this->stats['items_synced'] += $synced;
            $this->log("      ✓ {$synced} itens sincronizados", 'info');
        } catch (\Exception $e) {
            $this->log("      ✗ Erro ao sincronizar itens: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Sincroniza pedidos recentes
     */
    private function syncOrders(int $accountId): void
    {
        try {
            $client = new MercadoLivreClient($accountId);
            $sellerId = $client->getSellerId();
            if (!$sellerId) {
                $this->log("      ✗ Seller ID não encontrado para sincronizar pedidos", 'warning');
                return;
            }

            // Últimos 7 dias de pedidos
            $dateFrom = date('Y-m-d\T00:00:00.000-00:00', strtotime('-7 days'));

            $response = $this->unwrapMlResponse($client->get('/orders/search', [
                'seller' => $sellerId,
                'order.date_created.from' => $dateFrom,
                'limit' => 50
            ]));

            if (!isset($response['results']) || !is_array($response['results'])) {
                return;
            }

            $synced = 0;
            foreach ($response['results'] as $order) {
                if (!is_array($order)) {
                    continue;
                }
                $this->updateOrderInDatabase($accountId, $order);
                $synced++;
            }

            $this->stats['orders_synced'] += $synced;
            $this->log("      ✓ {$synced} pedidos sincronizados", 'info');
        } catch (\Exception $e) {
            $this->log("      ✗ Erro ao sincronizar pedidos: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Sincroniza perguntas não respondidas
     */
    private function syncQuestions(int $accountId): void
    {
        try {
            $client = new MercadoLivreClient($accountId);
            $sellerId = $client->getSellerId();
            if (!$sellerId) {
                $this->log("      ✗ Seller ID não encontrado para sincronizar perguntas", 'warning');
                return;
            }

            $response = $this->unwrapMlResponse($client->get('/questions/search', [
                'seller_id' => $sellerId,
                'status' => 'UNANSWERED',
                'limit' => 50
            ]));

            if (!isset($response['questions']) || !is_array($response['questions'])) {
                return;
            }

            $synced = 0;
            foreach ($response['questions'] as $question) {
                if (!is_array($question)) {
                    continue;
                }
                $this->updateQuestionInDatabase($accountId, $question, $sellerId);
                $synced++;
            }

            $this->stats['questions_synced'] += $synced;

            if ($synced > 0) {
                $this->log("      ✓ {$synced} perguntas sincronizadas", 'info');
            }
        } catch (\Exception $e) {
            $this->log("      ✗ Erro ao sincronizar perguntas: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Fase 3: Sincronizar Mercado Pago
     */
    private function syncMercadoPagoData(): void
    {
        // MP access token is a global credential stored in ean_settings, not per-account
        $mpToken = $this->getMercadoPagoToken();
        if (empty($mpToken)) {
            $this->log("   ⏭️  Mercado Pago não configurado (mp_access_token ausente)", 'info');
            return;
        }

        $accounts = $this->getActiveAccounts();

        foreach ($accounts as $account) {
            try {
                $this->syncMercadoPagoPayments($account['id'], $mpToken);
                usleep(500000);
            } catch (\Exception $e) {
                $this->log("   ❌ Erro MP conta {$account['user_id']}: " . $e->getMessage(), 'error');
            }
        }
    }

    /**
     * Obtém o token do Mercado Pago de ean_settings
     */
    private function getMercadoPagoToken(): ?string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM ean_settings WHERE setting_key = 'mp_access_token'"
            );
            $stmt->execute();
            $value = $stmt->fetchColumn();
            return $value ? (string) $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Sincroniza pagamentos do Mercado Pago
     */
    private function syncMercadoPagoPayments(int $accountId, string $mpToken): void
    {
        try {
            // API do Mercado Pago
            $dateFrom = date('Y-m-d\T00:00:00.000-00:00', strtotime('-7 days'));

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://api.mercadopago.com/v1/payments/search?begin_date={$dateFrom}&limit=50",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$mpToken}",
                    "Content-Type: application/json"
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                return;
            }

            $data = json_decode($response, true);

            if (!isset($data['results'])) {
                return;
            }

            $synced = 0;
            foreach ($data['results'] as $payment) {
                $this->updatePaymentInDatabase($accountId, $payment);
                $synced++;
            }

            $this->stats['payments_synced'] += $synced;
            $this->log("      ✓ {$synced} pagamentos MP sincronizados", 'info');
        } catch (\Exception $e) {
            $this->log("      ✗ Erro ao sincronizar MP: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Database helpers
     */
    private function getActiveAccounts(): array
    {
        $stmt = $this->db->prepare("
            SELECT id, user_id, nickname
            FROM ml_accounts
            WHERE status = 'active'
            AND token_expires_at > NOW()
            ORDER BY id
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function updateItemInDatabase(int $accountId, array $item): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO items (
                account_id, ml_item_id, title, price, status,
                available_quantity, category_id, data, updated_at
            ) VALUES (
                :account_id, :ml_item_id, :title, :price, :status,
                :available_quantity, :category_id, :data, NOW()
            )
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                price = VALUES(price),
                status = VALUES(status),
                available_quantity = VALUES(available_quantity),
                category_id = VALUES(category_id),
                data = VALUES(data),
                updated_at = NOW()
        ");

        $stmt->execute([
            'account_id' => $accountId,
            'ml_item_id' => $item['id'],
            'title' => $item['title'] ?? '',
            'price' => $item['price'] ?? 0,
            'status' => $item['status'] ?? 'unknown',
            'available_quantity' => $item['available_quantity'] ?? 0,
            'category_id' => $item['category_id'] ?? null,
            'data' => json_encode($item),
        ]);
    }

    private function updateOrderInDatabase(int $accountId, array $order): void
    {
        if (!isset($order['id'])) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO ml_orders (
                ml_order_id, ml_account_id, user_id, order_data,
                status, total_amount, date_created, synced_at
            ) VALUES (
                :ml_order_id, :ml_account_id, :user_id, :order_data,
                :status, :total_amount, :date_created, NOW()
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                order_data = VALUES(order_data),
                total_amount = VALUES(total_amount),
                synced_at = NOW()
        ");

        $orderJson = json_encode($order) ?: '{}';

        $stmt->execute([
            'ml_order_id' => $order['id'],
            'ml_account_id' => $accountId,
            'user_id' => null,
            'order_data' => $orderJson,
            'status' => $order['status'],
            'total_amount' => $order['total_amount'] ?? 0,
            'date_created' => $order['date_created'] ?? date('Y-m-d H:i:s')
        ]);
    }

    private function updateQuestionInDatabase(int $accountId, array $question, ?string $sellerId = null): void
    {
        if (!isset($question['id']) || !isset($question['item_id'])) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO ml_questions (
                question_id, account_id, item_id, status, question_text,
                answer_text, from_user_id, date_created, answer_date, updated_at, seller_id
            ) VALUES (
                :question_id, :account_id, :item_id, :status, :question_text,
                :answer_text, :from_user_id, :date_created, :answer_date, NOW(), :seller_id
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                question_text = VALUES(question_text),
                answer_text = VALUES(answer_text),
                answer_date = VALUES(answer_date),
                updated_at = NOW()
        ");

        $fromUserId = $question['from']['id'] ?? 0;
        if (!is_numeric($fromUserId)) {
            $fromUserId = 0;
        }

        $stmt->execute([
            'account_id' => $accountId,
            'question_id' => $question['id'],
            'item_id' => $question['item_id'],
            'question_text' => $question['text'] ?? '',
            'status' => $question['status'] ?? 'UNANSWERED',
            'answer_text' => $question['answer']['text'] ?? null,
            'from_user_id' => (int)$fromUserId,
            'date_created' => $question['date_created'] ?? date('Y-m-d H:i:s'),
            'answer_date' => $question['answer']['date_created'] ?? null,
            'seller_id' => $sellerId ? (int)$sellerId : 0,
        ]);
    }

    private function updatePaymentInDatabase(int $accountId, array $payment): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO mp_payments (
                account_id, payment_id, status, transaction_amount,
                date_created, synced_at
            ) VALUES (
                :account_id, :payment_id, :status, :transaction_amount,
                :date_created, NOW()
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                synced_at = NOW()
        ");

        $stmt->execute([
            'account_id' => $accountId,
            'payment_id' => $payment['id'],
            'status' => $payment['status'],
            'transaction_amount' => $payment['transaction_amount'] ?? 0,
            'date_created' => $payment['date_created'] ?? date('Y-m-d H:i:s')
        ]);
    }

    private function isMercadoPagoEnabled(): bool
    {
        return !empty($_ENV['MERCADO_PAGO_ENABLED']) && $_ENV['MERCADO_PAGO_ENABLED'] === 'true';
    }

    private function unwrapMlResponse(array $response): array
    {
        if (isset($response['error'])) {
            return $response;
        }

        if (isset($response['body']) && is_array($response['body'])) {
            return $response['body'];
        }

        return $response;
    }

    /**
     * Logging
     */
    private function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] [{$level}] {$message}\n";

        // Log para arquivo também
        $logFile = __DIR__ . '/../storage/logs/auto-token-refresh.log';
        @file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }

    /**
     * Resumo final
     */
    private function printSummary(): void
    {
        $duration = round(microtime(true) - $this->startTime, 2);

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "📊 RESUMO DA EXECUÇÃO\n";
        echo str_repeat('=', 60) . "\n";
        echo "⏱️  Duração: {$duration}s\n";
        echo "🔑 Tokens renovados: {$this->stats['tokens_refreshed']}\n";
        echo "❌ Tokens com erro: {$this->stats['tokens_failed']}\n";
        echo "📦 Itens sincronizados: {$this->stats['items_synced']}\n";
        echo "🛒 Pedidos sincronizados: {$this->stats['orders_synced']}\n";
        echo "❓ Perguntas sincronizadas: {$this->stats['questions_synced']}\n";
        echo "💳 Pagamentos MP sincronizados: {$this->stats['payments_synced']}\n";

        if (!empty($this->stats['errors'])) {
            echo "\n⚠️  ERROS:\n";
            foreach ($this->stats['errors'] as $error) {
                echo "   • {$error}\n";
            }
        }

        echo str_repeat('=', 60) . "\n";
    }
}

// Executar worker
try {
    $args = $_SERVER['argv'] ?? [];
    $runLegacy = in_array('--legacy', $args, true);
    $runOnce = in_array('--once', $args, true);
    $skipMercadoPago = in_array('--skip-mercadopago', $args, true) || in_array('--skip-mp', $args, true);
    $forceAll = in_array('--all', $args, true) || in_array('--force-all', $args, true);
    $compact = in_array('--compact', $args, true);
    $accountId = null;
    foreach ($args as $arg) {
        if (is_string($arg) && str_starts_with($arg, '--account=')) {
            $accountId = (int)substr($arg, strlen('--account='));
            break;
        }
    }

    if ($runOnce) {
        echo "[auto-token-refresh-worker] Executando em modo --once\n";
    }

    if ($runLegacy) {
        echo "[auto-token-refresh-worker] Modo legado habilitado via --legacy\n";
        $legacyLockDir = __DIR__ . '/../storage/locks';
        if (!is_dir($legacyLockDir)) {
            @mkdir($legacyLockDir, 0755, true);
        }
        $legacyLockFile = $legacyLockDir . '/auto-token-refresh-legacy.lock';
        $legacyLock = fopen($legacyLockFile, 'c');
        if ($legacyLock === false || !flock($legacyLock, LOCK_EX | LOCK_NB)) {
            echo "[auto-token-refresh-worker] Modo legado já em execução (lock ocupado) — saindo\n";
            if ($legacyLock !== false) {
                fclose($legacyLock);
            }
            exit(0);
        }
        try {
            $worker = new AutoTokenRefreshWorker();
            $worker->run();
        } finally {
            flock($legacyLock, LOCK_UN);
            fclose($legacyLock);
        }
        exit(0);
    }

    // Padrão: só refresh de token (rápido). --cycle mantém o orquestrador completo
    // (poll+fila) que segurava o lock por horas e causava lock_busy no cron.
    $runCycle = in_array('--cycle', $args, true) || in_array('--full', $args, true);

    $orchestrator = new MercadoLivreOrchestratorService(dirname(__DIR__));

    if ($runCycle) {
        echo "[auto-token-refresh-worker] Modo --cycle: orquestrador completo (refresh+poll+fila)\n";
        echo "[auto-token-refresh-worker] Use o padrão (sem --cycle) para só renovar tokens\n";

        $cycle = $orchestrator->runCycle([
            'force_all' => $forceAll,
            'account_id' => $accountId,
            'orders_limit' => 100,
            'items_limit' => 100,
            'questions_limit' => 50,
            'queue_batch_size' => 25,
            'queue_max_batches' => 4,
            'cleanup_old_jobs' => false,
            'cleanup_days' => 30,
        ], true);

        echo json_encode(
            $cycle,
            $compact ? 0 : (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ) . PHP_EOL;

        if (!empty($cycle['skipped'])) {
            exit(0);
        }

        $mpEnabled = filter_var($_ENV['MERCADO_PAGO_ENABLED'] ?? getenv('MERCADO_PAGO_ENABLED') ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($mpEnabled && !$skipMercadoPago) {
            echo "[auto-token-refresh-worker] Executando fase legada de Mercado Pago (compatibilidade temporária)\n";
            $worker = new AutoTokenRefreshWorker();
            $worker->runMercadoPagoOnly();
        } elseif ($mpEnabled && $skipMercadoPago) {
            echo "[auto-token-refresh-worker] Fase Mercado Pago ignorada via flag --skip-mercadopago\n";
        }

        exit((bool)($cycle['success'] ?? false) ? 0 : 1);
    }

    echo "[auto-token-refresh-worker] Modo padrão: apenas refresh de tokens ML\n";
    echo "[auto-token-refresh-worker] Use --cycle para orquestrador completo; --legacy para fluxo antigo\n";

    $refresh = $orchestrator->runTokenRefresh($forceAll, $accountId, true);

    echo json_encode(
        $refresh,
        $compact ? 0 : (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ) . PHP_EOL;

    exit((bool)($refresh['success'] ?? false) ? 0 : 1);
} catch (\Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
