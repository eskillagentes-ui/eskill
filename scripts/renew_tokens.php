<?php
/**
 * Script de renovação automática de tokens do Mercado Livre
 * 
 * Este script renova os tokens de todas as contas ativas antes de expirarem.
 * 
 * Exemplo de CRON (a cada 2 horas - tokens ML expiram a cada 6h):
 * 0 0,2,4,6,8,10,12,14,16,18,20,22 * * * php /home/eskill/htdocs/eskill.com.br/scripts/renew_tokens.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

use App\Services\MercadoLivreAuthService;
use App\Database;

try {
    $db = Database::getInstance();
    $auth = new MercadoLivreAuthService();
    
    echo "[" . date('Y-m-d H:i:s') . "] Verificando tokens do Mercado Livre...\n";
    
    // Buscar contas que expiram em menos de 3 horas ou marcadas como expiradas/inativas
    $stmt = $db->query("
        SELECT id, nickname, token_expires_at,
               status,
               TIMESTAMPDIFF(HOUR, NOW(), token_expires_at) as hours_left
        FROM ml_accounts 
        WHERE token_expires_at IS NOT NULL
          AND (status IN ('active', 'expired', 'inactive'))
          AND refresh_token IS NOT NULL
          AND refresh_token != ''
    ");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($accounts)) {
        echo "Nenhuma conta ativa encontrada.\n";
        exit(0);
    }
    
    $renewed = 0;
    $failed = 0;
    $skipped = 0;
    
    foreach ($accounts as $account) {
        $hoursLeft = (int) $account['hours_left'];
        $status = $account['status'];
        
        // Renovar se expira em menos de 3 horas, se já está expirado ou se inativo (tentativa de reativar)
        $needsRenewal = $hoursLeft <= 3 || $status === 'expired' || $status === 'inactive';
        
        // Pular contas expiradas há mais de 180 dias (6 meses - refresh_token já morreu)
        if ($hoursLeft < -4320) {
            echo "[{$account['nickname']}] Expirado há mais de 180 dias - Requer reconexão manual\n";
            $skipped++;
            continue;
        }
        
        if ($needsRenewal) {
            echo "[{$account['nickname']}] Token expira em {$hoursLeft}h (status: {$status}) - Renovando... ";
            
            try {
                if ($auth->refreshToken((int) $account['id'])) {
                    echo "OK\n";
                    $renewed++;
                    
                    // Marca como ativo depois de renovar
                    $db->prepare("UPDATE ml_accounts SET status = 'active', updated_at = NOW() WHERE id = ?")
                        ->execute([(int)$account['id']]);
                } else {
                    echo "FALHOU\n";
                    $failed++;
                }
            } catch (\Exception $e) {
                echo "ERRO: " . $e->getMessage() . "\n";
                $failed++;
            }
        } else {
            echo "[{$account['nickname']}] Token válido por {$hoursLeft}h - Pulando\n";
            $skipped++;
        }
    }
    
    echo "\n[" . date('Y-m-d H:i:s') . "] Concluído.\n";
    echo "Renovados: {$renewed}\n";
    echo "Falhos: {$failed}\n";
    echo "Pulados: {$skipped}\n";
    
    // Retornar erro se houve falhas
    exit($failed > 0 ? 1 : 0);
    
} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
