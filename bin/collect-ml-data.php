#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Coleta Inicial de Dados do Mercado Livre
 *
 * Busca todos os anúncios ativos e pedidos recentes de todas as contas ML
 * vinculadas no sistema. Este script deve ser rodado após vincular a primeira
 * conta para popular o dashboard com dados reais.
 *
 * Uso:
 *   php bin/collect-ml-data.php              # Coleta items + orders
 *   php bin/collect-ml-data.php --items      # Só items
 *   php bin/collect-ml-data.php --orders     # Só orders
 *   php bin/collect-ml-data.php --test       # Testa API (1 item)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Load helpers
if (file_exists(__DIR__ . '/../app/Helpers/LogHelper.php')) {
    require_once __DIR__ . '/../app/Helpers/LogHelper.php';
}

use App\Database;
use App\Services\ItemService;
use App\Services\MercadoLivreClient;

$mode = $argv[1] ?? '--all';

echo "\n══════════════════════════════════════════════\n";
echo "  eskill.com.br — Coleta Inicial ML\n";
echo "══════════════════════════════════════════════\n\n";

// Connect to DB
try {
    $db = Database::getInstance();
    echo "✅ MySQL conectado\n";
} catch (\Exception $e) {
    echo "❌ MySQL falhou: " . $e->getMessage() . "\n";
    echo "   Rode: php bin/migrate.php\n";
    exit(1);
}

// Get active accounts
try {
    $stmt = $db->query("
        SELECT id, nickname, ml_user_id, status,
               access_token IS NOT NULL as has_token,
               token_expires_at
        FROM ml_accounts
        WHERE status = 'active'
        ORDER BY id
    ");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    echo "❌ Tabela ml_accounts não existe — rode: php bin/migrate.php\n";
    exit(1);
}

if (empty($accounts)) {
    echo "\n⚠️  Nenhuma conta ML ativa encontrada.\n";
    echo "   1. Acesse https://eskill.com.br/login\n";
    echo "   2. Vá para https://eskill.com.br/auth/authorize\n";
    echo "   3. Complete o OAuth com o Mercado Livre\n";
    echo "   4. Rode este script novamente\n\n";
    exit(0);
}

echo "\n📋 Contas encontradas: " . count($accounts) . "\n";
foreach ($accounts as $acc) {
    $tokenStatus = $acc['has_token'] ? '🔑' : '🚫';
    $expired = '';
    if ($acc['token_expires_at'] && strtotime($acc['token_expires_at']) < time()) {
        $expired = ' (EXPIRADO)';
    }
    echo "   [{$acc['id']}] {$acc['nickname']} — ml_user_id={$acc['ml_user_id']} {$tokenStatus}{$expired}\n";
}

// Test mode: just test 1 API call
if ($mode === '--test') {
    echo "\n── Teste de API ──\n";
    $testAccount = $accounts[0];
    try {
        $client = new MercadoLivreClient((int) $testAccount['id']);
        $items = $client->getMyItems(['status' => 'active', 'limit' => 1]);

        if (isset($items['error'])) {
            echo "❌ API Error: " . ($items['message'] ?? $items['error']) . "\n";
            exit(1);
        }

        $total = $items['paging']['total'] ?? 0;
        echo "✅ API respondeu — {$total} anúncios ativos encontrados\n";

        if (!empty($items['results'])) {
            $firstId = $items['results'][0];
            $detail = $client->getItemDetails($firstId);
            echo "   Primeiro item: {$firstId}\n";
            echo "   Título: " . ($detail['title'] ?? 'N/A') . "\n";
            echo "   Preço: R\$ " . number_format((float) ($detail['price'] ?? 0), 2, ',', '.') . "\n";
            echo "   Status: " . ($detail['status'] ?? 'N/A') . "\n";
        }
    } catch (\Exception $e) {
        echo "❌ Erro: " . $e->getMessage() . "\n";
        exit(1);
    }
    echo "\n✅ Teste concluído com sucesso!\n\n";
    exit(0);
}

$totalItemsSynced = 0;
$totalOrdersSynced = 0;
$totalErrors = 0;

foreach ($accounts as $account) {
    $accountId = (int) $account['id'];
    $nickname = $account['nickname'];

    if (!$account['has_token']) {
        echo "\n⚠️  [{$nickname}] Sem access_token — pulando\n";
        continue;
    }

    if ($account['token_expires_at'] && strtotime($account['token_expires_at']) < time()) {
        echo "\n⚠️  [{$nickname}] Token expirado — tentando refresh...\n";
        try {
            $authService = new \App\Services\MercadoLivreAuthService();
            $authService->refreshToken($accountId);
            echo "   ✅ Token renovado\n";
        } catch (\Exception $e) {
            echo "   ❌ Refresh falhou: " . $e->getMessage() . "\n";
            echo "   Acesse /auth/authorize?reconnect={$accountId} para reconectar\n";
            continue;
        }
    }

    // Collect Items
    if ($mode === '--all' || $mode === '--items') {
        echo "\n── [{$nickname}] Coletando Items ──\n";
        try {
            $itemService = new ItemService($accountId);
            $result = $itemService->syncItems(200);

            if ($result['success']) {
                echo "   ✅ Sincronizados: {$result['synced']}\n";
                echo "   ⚠️  Erros: {$result['errors']}\n";
                echo "   📊 Total encontrados: {$result['total_found']}\n";
                $totalItemsSynced += $result['synced'];
                $totalErrors += $result['errors'];
            } else {
                echo "   ❌ Erro: " . ($result['error'] ?? 'Desconhecido') . "\n";
                $totalErrors++;
            }
        } catch (\Exception $e) {
            echo "   ❌ Exception: " . $e->getMessage() . "\n";
            $totalErrors++;
        }
    }

    // Collect Orders
    if ($mode === '--all' || $mode === '--orders') {
        echo "\n── [{$nickname}] Coletando Pedidos (últimos 30 dias) ──\n";
        try {
            $client = new MercadoLivreClient($accountId);
            $sellerId = $account['ml_user_id'];

            if (empty($sellerId)) {
                echo "   ⚠️  ml_user_id não encontrado — buscando via API...\n";
                $me = $client->get('/users/me');
                $sellerId = (string) ($me['id'] ?? '');
                if (!empty($sellerId)) {
                    $db->prepare("UPDATE ml_accounts SET ml_user_id = :sid WHERE id = :id")
                        ->execute(['sid' => $sellerId, 'id' => $accountId]);
                    echo "   ✅ ml_user_id salvo: {$sellerId}\n";
                }
            }

            if (!empty($sellerId)) {
                $dateFrom = date('Y-m-d', strtotime('-30 days')) . 'T00:00:00.000-03:00';
                $orders = $client->get("/orders/search", [
                    'seller' => $sellerId,
                    'order.date_created.from' => $dateFrom,
                    'sort' => 'date_desc',
                    'limit' => 50,
                ]);

                $orderCount = $orders['paging']['total'] ?? 0;
                echo "   📊 Total pedidos encontrados: {$orderCount}\n";

                // Save orders to DB
                $savedCount = 0;
                $results = $orders['results'] ?? [];
                foreach ($results as $order) {
                    try {
                        $orderId = (int) ($order['id'] ?? 0);
                        if ($orderId <= 0) {
                            continue;
                        }
                        $stmt = $db->prepare("
                            INSERT INTO ml_orders (ml_order_id, ml_account_id, order_data, status, total_amount, date_created)
                            VALUES (:ml_order_id, :ml_account_id, :order_data, :status, :total, :created)
                            ON DUPLICATE KEY UPDATE status = :status2, total_amount = :total2, order_data = :raw2
                        ");
                        $stmt->execute([
                            'ml_order_id' => $orderId,
                            'ml_account_id' => $accountId,
                            'order_data' => json_encode($order),
                            'status' => $order['status'] ?? 'unknown',
                            'total' => (float) ($order['total_amount'] ?? 0),
                            'created' => $order['date_created'] ?? date('Y-m-d H:i:s'),
                            'status2' => $order['status'] ?? 'unknown',
                            'total2' => (float) ($order['total_amount'] ?? 0),
                            'raw2' => json_encode($order),
                        ]);
                        $savedCount++;
                    } catch (\Throwable $e) {
                        // Skip individual order errors
                        $totalErrors++;
                    }
                }
                echo "   ✅ Salvos no DB: {$savedCount}\n";
                $totalOrdersSynced += $savedCount;
            } else {
                echo "   ❌ Não foi possível determinar ml_user_id (seller_id)\n";
            }
        } catch (\Exception $e) {
            echo "   ❌ Exception: " . $e->getMessage() . "\n";
            $totalErrors++;
        }
    }
}

// Summary
echo "\n══════════════════════════════════════════════\n";
echo "  📊 Resumo da coleta:\n";
echo "     Items sincronizados: {$totalItemsSynced}\n";
echo "     Pedidos salvos: {$totalOrdersSynced}\n";
echo "     Erros: {$totalErrors}\n";
echo "══════════════════════════════════════════════\n\n";

if ($totalItemsSynced > 0 || $totalOrdersSynced > 0) {
    echo "✅ Dados coletados! Acesse https://eskill.com.br/dashboard\n\n";
} else {
    echo "⚠️  Nenhum dado coletado.\n";
    echo "   Verifique: php bin/production-check.php\n\n";
}

exit($totalErrors > 10 ? 1 : 0);
