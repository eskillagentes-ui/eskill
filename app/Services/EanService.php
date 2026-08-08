<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;
use Exception;
use App\Services\WebhookInboxService;

/**
 * Serviço de gestão de códigos EAN (GTIN-13)
 *
 * Gerencia inventário, compras, atribuição e validação de códigos EAN
 * para anúncios do Mercado Livre.
 *
 * Tabelas: ean_inventory, ean_packages, ean_purchases,
 *          ean_balances, ean_transactions, ean_assignments, ean_settings
 */
class EanService
{
    private PDO $db;
    private MercadoPagoService $mercadoPagoService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->mercadoPagoService = new MercadoPagoService();
    }

    // =========================================================================
    // Pacotes
    // =========================================================================

    /**
     * Listar pacotes de EAN disponíveis para compra
     */
    public function getPackages(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM ean_packages WHERE is_active = 1 ORDER BY sort_order ASC, price ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Saldo
    // =========================================================================

    /**
     * Obter saldo de EANs de um seller
     */
    public function getBalance(int $accountId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ean_balances WHERE account_id = :account_id"
        );
        $stmt->execute(['account_id' => $accountId]);
        $balance = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$balance) {
            return [
                'total_purchased' => 0,
                'total_used' => 0,
                'available' => 0,
                'last_purchase_at' => null,
            ];
        }

        return $balance;
    }

    // =========================================================================
    // EANs do Seller
    // =========================================================================

    /**
     * Listar EANs atribuídos a um seller
     */
    public function getSellerEans(int $accountId, bool $onlyAvailable = false): array
    {
        $sql = "SELECT ei.*, ea.ml_item_id, ea.product_title, ea.product_sku,
                       ea.category_id, ea.assigned_at, ea.id AS assignment_id
                FROM ean_assignments ea
                JOIN ean_inventory ei ON ei.id = ea.ean_id
                WHERE ea.account_id = :account_id";

        if ($onlyAvailable) {
            $sql .= " AND ea.ml_item_id IS NULL";
        }

        $sql .= " ORDER BY ea.assigned_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['account_id' => $accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Histórico
    // =========================================================================

    /**
     * Histórico de compras do seller
     */
    public function getPurchaseHistory(int $accountId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ep.*, pkg.name AS package_name
             FROM ean_purchases ep
             LEFT JOIN ean_packages pkg ON pkg.id = ep.package_id
             WHERE ep.account_id = :account_id
             ORDER BY ep.created_at DESC"
        );
        $stmt->execute(['account_id' => $accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Histórico de transações de saldo
     */
    public function getTransactionHistory(int $accountId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ean_transactions
             WHERE account_id = :account_id
             ORDER BY created_at DESC
             LIMIT 100"
        );
        $stmt->execute(['account_id' => $accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Compra
    // =========================================================================

    /**
     * Iniciar compra de pacote de EANs
     */
    public function initiatePurchase(int $accountId, int $packageId, string $paymentMethod = 'pix'): array
    {
        // Buscar pacote
        $stmt = $this->db->prepare("SELECT * FROM ean_packages WHERE id = :id AND is_active = 1");
        $stmt->execute(['id' => $packageId]);
        $package = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$package) {
            throw new Exception('Pacote não encontrado ou inativo');
        }

        $totalAmount = $package['price'];
        $discountApplied = 0;

        if ($package['discount_percent'] > 0) {
            $discountApplied = round($totalAmount * $package['discount_percent'] / 100, 2);
            $totalAmount -= $discountApplied;
        }

        // Criar registro de compra
        $stmt = $this->db->prepare(
            "INSERT INTO ean_purchases
             (account_id, package_id, quantity, unit_price, total_amount, discount_applied,
              payment_method, payment_status, created_at)
             VALUES
             (:account_id, :package_id, :quantity, :unit_price, :total_amount, :discount_applied,
              :payment_method, 'pending', NOW())"
        );
        $stmt->execute([
            'account_id' => $accountId,
            'package_id' => $packageId,
            'quantity' => $package['quantity'],
            'unit_price' => $package['price_per_ean'],
            'total_amount' => $totalAmount,
            'discount_applied' => $discountApplied,
            'payment_method' => $paymentMethod,
        ]);

        $purchaseId = (int) $this->db->lastInsertId();

        return [
            'purchase_id' => $purchaseId,
            'package' => $package['name'],
            'quantity' => $package['quantity'],
            'total_amount' => $totalAmount,
            'payment_method' => $paymentMethod,
            'status' => 'pending',
        ];
    }

    // =========================================================================
    // Uso de EAN
    // =========================================================================

    /**
     * Usar (vincular) um EAN a um item do ML
     */
    public function useEan(int $accountId, ?string $mlItemId, ?string $title): ?array
    {
        $this->db->beginTransaction();
        try {
            // Buscar próximo EAN disponível atribuído ao seller
            $nextEan = $this->getNextAvailableEan($accountId);

            if (!$nextEan) {
                $this->db->rollBack();
                return null;
            }

            // Vincular ao item
            $stmt = $this->db->prepare(
                "UPDATE ean_assignments
                 SET ml_item_id = :ml_item_id, product_title = :title
                 WHERE id = :id AND account_id = :account_id AND ml_item_id IS NULL"
            );
            $stmt->execute([
                'ml_item_id' => $mlItemId,
                'title' => $title,
                'id' => $nextEan['assignment_id'],
                'account_id' => $accountId,
            ]);

            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                return null;
            }

            // Atualizar saldo
            $this->db->prepare(
                "UPDATE ean_balances SET total_used = total_used + 1, available = available - 1
                 WHERE account_id = :account_id"
            )->execute(['account_id' => $accountId]);

            // Marcar EAN no inventário como sold
            $this->db->prepare(
                "UPDATE ean_inventory SET status = 'sold', sold_at = NOW() WHERE id = :id"
            )->execute(['id' => $nextEan['ean_id']]);

            // Registrar transação
            $this->recordTransaction(
                $accountId,
                'debit',
                1,
                'use',
                $nextEan['assignment_id'],
                "EAN {$nextEan['ean']} vinculado ao item {$mlItemId}"
            );

            $this->db->commit();

            return [
                'ean' => $nextEan['ean'],
                'assignment_id' => $nextEan['assignment_id'],
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Desvincular EAN de um item
     */
    public function unlinkEan(int $accountId, int $assignmentId): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT ea.*, ei.ean FROM ean_assignments ea
                 JOIN ean_inventory ei ON ei.id = ea.ean_id
                 WHERE ea.id = :id AND ea.account_id = :account_id AND ea.ml_item_id IS NOT NULL"
            );
            $stmt->execute(['id' => $assignmentId, 'account_id' => $accountId]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$assignment) {
                $this->db->rollBack();
                throw new Exception('Atribuição não encontrada ou EAN não está vinculado');
            }

            // Desvincular
            $this->db->prepare(
                "UPDATE ean_assignments SET ml_item_id = NULL, product_title = NULL WHERE id = :id"
            )->execute(['id' => $assignmentId]);

            // Restaurar saldo
            $this->db->prepare(
                "UPDATE ean_balances SET total_used = total_used - 1, available = available + 1
                 WHERE account_id = :account_id"
            )->execute(['account_id' => $accountId]);

            // Restaurar inventário
            $this->db->prepare(
                "UPDATE ean_inventory SET status = 'available', sold_at = NULL WHERE id = :ean_id"
            )->execute(['ean_id' => $assignment['ean_id']]);

            // Transação de crédito
            $this->recordTransaction(
                $accountId,
                'credit',
                1,
                'unlink',
                $assignmentId,
                "EAN {$assignment['ean']} desvinculado"
            );

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Obter próximo EAN disponível atribuído ao seller (sem usá-lo)
     */
    public function getNextAvailableEan(int $accountId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT ea.id AS assignment_id, ea.ean_id, ei.ean
             FROM ean_assignments ea
             JOIN ean_inventory ei ON ei.id = ea.ean_id
             WHERE ea.account_id = :account_id
               AND ea.ml_item_id IS NULL
               AND COALESCE(ei.status, 'available') = 'available'
             ORDER BY ea.id ASC
             LIMIT 1"
        );
        $stmt->execute(['account_id' => $accountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Reserva soft: vincula assignment ao item e marca inventário reserved (não debita saldo).
     *
     * @return array{assignment_id:int,ean_id:int,ean:string}|null
     */
    public function reserveEanForItem(int $accountId, string $mlItemId, ?string $title = null): ?array
    {
        $mlItemId = trim($mlItemId);
        if ($mlItemId === '' || $accountId <= 0) {
            return null;
        }

        // Já reservado para este item?
        $existing = $this->db->prepare(
            "SELECT ea.id AS assignment_id, ea.ean_id, ei.ean
             FROM ean_assignments ea
             JOIN ean_inventory ei ON ei.id = ea.ean_id
             WHERE ea.account_id = :account_id AND ea.ml_item_id = :ml_item_id
               AND COALESCE(ei.status, '') = 'reserved'
             LIMIT 1"
        );
        $existing->execute(['account_id' => $accountId, 'ml_item_id' => $mlItemId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'assignment_id' => (int)$row['assignment_id'],
                'ean_id' => (int)$row['ean_id'],
                'ean' => (string)$row['ean'],
            ];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT ea.id AS assignment_id, ea.ean_id, ei.ean
                 FROM ean_assignments ea
                 JOIN ean_inventory ei ON ei.id = ea.ean_id
                 WHERE ea.account_id = :account_id
                   AND ea.ml_item_id IS NULL
                   AND COALESCE(ei.status, 'available') = 'available'
                 ORDER BY ea.id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute(['account_id' => $accountId]);
            $next = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$next) {
                $this->db->rollBack();
                return null;
            }

            $updA = $this->db->prepare(
                "UPDATE ean_assignments
                 SET ml_item_id = :ml_item_id, product_title = :title
                 WHERE id = :id AND account_id = :account_id AND ml_item_id IS NULL"
            );
            $updA->execute([
                'ml_item_id' => $mlItemId,
                'title' => $title,
                'id' => $next['assignment_id'],
                'account_id' => $accountId,
            ]);
            if ($updA->rowCount() === 0) {
                $this->db->rollBack();
                return null;
            }

            $updI = $this->db->prepare(
                "UPDATE ean_inventory
                 SET status = 'reserved', reserved_at = NOW()
                 WHERE id = :id AND COALESCE(status, 'available') = 'available'"
            );
            $updI->execute(['id' => $next['ean_id']]);
            if ($updI->rowCount() === 0) {
                $this->db->rollBack();
                return null;
            }

            $this->db->commit();
            return [
                'assignment_id' => (int)$next['assignment_id'],
                'ean_id' => (int)$next['ean_id'],
                'ean' => (string)$next['ean'],
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            log_error('EanService::reserveEanForItem failed', [
                'account_id' => $accountId,
                'ml_item_id' => $mlItemId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Libera reserva soft (reject / falha ao gravar sugestão).
     */
    public function releaseEanReservation(int $accountId, string $mlItemId, int $assignmentId): bool
    {
        if ($accountId <= 0 || $assignmentId <= 0) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT ea.id, ea.ean_id, ea.ml_item_id, ei.status
                 FROM ean_assignments ea
                 JOIN ean_inventory ei ON ei.id = ea.ean_id
                 WHERE ea.id = :id AND ea.account_id = :account_id
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $assignmentId, 'account_id' => $accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();
                return false;
            }
            if (($row['status'] ?? '') === 'sold') {
                $this->db->rollBack();
                return false;
            }
            if ((string)($row['ml_item_id'] ?? '') !== $mlItemId && $mlItemId !== '') {
                // Ainda libera se assignment_id bate e status reserved
                if (($row['status'] ?? '') !== 'reserved') {
                    $this->db->rollBack();
                    return false;
                }
            }

            $this->db->prepare(
                "UPDATE ean_assignments SET ml_item_id = NULL, product_title = NULL
                 WHERE id = :id AND account_id = :account_id"
            )->execute(['id' => $assignmentId, 'account_id' => $accountId]);

            $this->db->prepare(
                "UPDATE ean_inventory
                 SET status = 'available', reserved_at = NULL
                 WHERE id = :id AND status = 'reserved'"
            )->execute(['id' => $row['ean_id']]);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            log_error('EanService::releaseEanReservation failed', [
                'account_id' => $accountId,
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Após apply OK no ML: marca sold e debita saldo (reserva já tinha ml_item_id).
     */
    public function confirmEanReservationAfterApply(int $accountId, string $mlItemId, int $assignmentId): bool
    {
        if ($accountId <= 0 || $assignmentId <= 0 || trim($mlItemId) === '') {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT ea.id, ea.ean_id, ea.ml_item_id, ei.status, ei.ean
                 FROM ean_assignments ea
                 JOIN ean_inventory ei ON ei.id = ea.ean_id
                 WHERE ea.id = :id AND ea.account_id = :account_id
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $assignmentId, 'account_id' => $accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();
                return false;
            }
            if (($row['status'] ?? '') === 'sold') {
                $this->db->commit();
                return true; // idempotente
            }
            if ((string)($row['ml_item_id'] ?? '') !== $mlItemId) {
                $this->db->prepare(
                    "UPDATE ean_assignments SET ml_item_id = :ml_item_id
                     WHERE id = :id AND account_id = :account_id"
                )->execute([
                    'ml_item_id' => $mlItemId,
                    'id' => $assignmentId,
                    'account_id' => $accountId,
                ]);
            }

            $upd = $this->db->prepare(
                "UPDATE ean_inventory
                 SET status = 'sold', sold_at = NOW()
                 WHERE id = :id AND status IN ('reserved', 'available')"
            );
            $upd->execute(['id' => $row['ean_id']]);
            if ($upd->rowCount() === 0) {
                $this->db->rollBack();
                return false;
            }

            $this->db->prepare(
                "UPDATE ean_balances
                 SET total_used = total_used + 1,
                     available = GREATEST(available - 1, 0)
                 WHERE account_id = :account_id"
            )->execute(['account_id' => $accountId]);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            log_error('EanService::confirmEanReservationAfterApply failed', [
                'account_id' => $accountId,
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Obter EAN vinculado a um item ML
     */
    public function getEanByItem(string $mlItemId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT ea.*, ei.ean FROM ean_assignments ea
             JOIN ean_inventory ei ON ei.id = ea.ean_id
             WHERE ea.ml_item_id = :ml_item_id
             LIMIT 1"
        );
        $stmt->execute(['ml_item_id' => $mlItemId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // =========================================================================
    // Estoque
    // =========================================================================

    /**
     * Verificar estoque baixo
     */
    public function checkLowStock(int $accountId): array
    {
        $balance = $this->getBalance($accountId);
        $threshold = $this->getSetting('low_stock_threshold', 10);

        return [
            'available' => $balance['available'],
            'threshold' => $threshold,
            'is_low' => $balance['available'] <= $threshold,
            'is_critical' => $balance['available'] <= 3,
            'is_empty' => (int) $balance['available'] === 0,
        ];
    }

    // =========================================================================
    // Validação
    // =========================================================================

    /**
     * Validar formato de EAN-13
     */
    public function validateEan(string $ean): bool
    {
        if (!preg_match('/^\d{13}$/', $ean)) {
            return false;
        }

        // Validar check digit
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $ean[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        $checkDigit = (10 - ($sum % 10)) % 10;

        return $checkDigit === (int) $ean[12];
    }

    /**
     * Buscar EAN no inventário
     */
    public function findEan(string $ean): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM ean_inventory WHERE ean = :ean");
        $stmt->execute(['ean' => $ean]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // =========================================================================
    // Pagamento / Webhook
    // =========================================================================

    /**
     * Processar webhook de pagamento (Mercado Pago)
     */
    public function processPaymentWebhook(array $data): array
    {
        $paymentId = $data['data']['id'] ?? $data['id'] ?? null;

        if (!$paymentId) {
            return ['status' => 'no_payment_id'];
        }

        // Buscar compra pelo payment_id
        $stmt = $this->db->prepare(
            "SELECT * FROM ean_purchases WHERE payment_id = :payment_id OR payment_external_id = :payment_id"
        );
        $stmt->execute(['payment_id' => $paymentId]);
        $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$purchase) {
            return ['status' => 'purchase_not_found', 'payment_id' => $paymentId];
        }

        $action = $data['action'] ?? $data['type'] ?? '';

        if (strpos($action, 'approved') !== false || ($data['status'] ?? '') === 'approved') {
            return $this->confirmPayment((int) $purchase['id']);
        }

        return ['status' => 'webhook_processed', 'action' => $action];
    }

    /**
     * Reconcilia compras pendentes consultando status real no Mercado Pago.
     */
    public function reconcilePendingPayments(int $limit = 100, int $minAgeMinutes = 2): array
    {
        $result = [
            'checked' => 0,
            'confirmed' => 0,
            'still_pending' => 0,
            'failed_or_cancelled' => 0,
            'without_payment_id' => 0,
            'errors' => 0,
            'details' => [],
        ];

        if (!$this->mercadoPagoService->hasCredentials()) {
            return [
                'checked' => 0,
                'confirmed' => 0,
                'still_pending' => 0,
                'failed_or_cancelled' => 0,
                'without_payment_id' => 0,
                'errors' => 1,
                'details' => [
                    ['error' => 'Credenciais do Mercado Pago não configuradas'],
                ],
            ];
        }

        $stmt = $this->db->prepare(
            "SELECT *
             FROM ean_purchases
             WHERE payment_status = 'pending'
               AND created_at <= DATE_SUB(NOW(), INTERVAL :min_age MINUTE)
             ORDER BY id ASC
                         LIMIT " . max(1, min(1000, (int)$limit))
        );
        $stmt->bindValue(':min_age', max(0, $minAgeMinutes), PDO::PARAM_INT);
        $stmt->execute();
        $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($purchases as $purchase) {
            $result['checked']++;

            $paymentId = (string)($purchase['payment_id'] ?: $purchase['payment_external_id'] ?: '');
            if ($paymentId === '') {
                $result['without_payment_id']++;
                $result['details'][] = [
                    'purchase_id' => (int)$purchase['id'],
                    'status' => 'skipped_without_payment_id',
                ];
                continue;
            }

            $payment = $this->mercadoPagoService->getPaymentById($paymentId);
            if (!(bool)($payment['success'] ?? false)) {
                $result['errors']++;
                $result['details'][] = [
                    'purchase_id' => (int)$purchase['id'],
                    'payment_id' => $paymentId,
                    'status' => 'error',
                    'error' => (string)($payment['error'] ?? 'Erro desconhecido na consulta MP'),
                ];
                continue;
            }

            $paymentData = $payment['data'] ?? [];
            $status = (string)($paymentData['status'] ?? '');

            if ($status === 'approved') {
                try {
                    $confirm = $this->confirmPayment((int)$purchase['id']);
                    $result['confirmed']++;
                    $result['details'][] = [
                        'purchase_id' => (int)$purchase['id'],
                        'payment_id' => $paymentId,
                        'status' => 'confirmed',
                        'confirm_result' => $confirm['status'] ?? 'confirmed',
                    ];
                } catch (Exception $e) {
                    $result['errors']++;
                    $result['details'][] = [
                        'purchase_id' => (int)$purchase['id'],
                        'payment_id' => $paymentId,
                        'status' => 'error_confirming',
                        'error' => $e->getMessage(),
                    ];
                }
                continue;
            }

            if (in_array($status, ['rejected', 'cancelled', 'refunded', 'charged_back'], true)) {
                $this->db->prepare(
                    "UPDATE ean_purchases
                     SET payment_status = :payment_status,
                         notes = CONCAT(IFNULL(notes, ''), :note_suffix)
                     WHERE id = :id"
                )->execute([
                    'payment_status' => $status === 'refunded' ? 'refunded' : 'failed',
                    'note_suffix' => "\n[reconcile] status MP: {$status} em " . date('Y-m-d H:i:s'),
                    'id' => (int)$purchase['id'],
                ]);

                $result['failed_or_cancelled']++;
                $result['details'][] = [
                    'purchase_id' => (int)$purchase['id'],
                    'payment_id' => $paymentId,
                    'status' => 'updated_failed',
                    'mp_status' => $status,
                ];
                continue;
            }

            $result['still_pending']++;
            $result['details'][] = [
                'purchase_id' => (int)$purchase['id'],
                'payment_id' => $paymentId,
                'status' => 'still_pending',
                'mp_status' => $status,
            ];
        }

        return $result;
    }

    /**
     * Salva um snapshot de execução da reconciliação.
     */
    public function storeReconcileExecution(array $execution): void
    {
        $existing = $this->getSetting('ean_reconcile_status', []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $history = $existing['history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        $normalizedExecution = array_merge([
            'source' => 'unknown',
            'started_at' => null,
            'finished_at' => date('c'),
            'ok' => true,
            'result' => [],
            'retry' => null,
        ], $execution);

        $history[] = $normalizedExecution;
        if (count($history) > 30) {
            $history = array_slice($history, -30);
        }

        $payload = [
            'last_run' => $normalizedExecution,
            'history' => array_values($history),
            'updated_at' => date('c'),
        ];

        $this->setSetting('ean_reconcile_status', $payload, 'json', 'Status e histórico da reconciliação EAN');
    }

    /**
     * Atualiza heartbeat operacional do worker de reconciliação.
     */
    public function updateReconcileWorkerHeartbeat(array $heartbeat): void
    {
        $payload = array_merge([
            'worker' => 'ean-payment-reconcile-worker',
            'pid' => getmypid(),
            'updated_at' => date('c'),
        ], $heartbeat);

        $this->setSetting('ean_reconcile_worker_heartbeat', $payload, 'json', 'Heartbeat do worker de reconciliação EAN');
    }

    /**
     * Retorna status operacional da reconciliação EAN.
     */
    public function getReconcileStatus(): array
    {
        $status = $this->getSetting('ean_reconcile_status', []);
        $heartbeat = $this->getSetting('ean_reconcile_worker_heartbeat', []);
        $lastAutoHeal = $this->getSetting('ean_reconcile_last_auto_heal', []);
        $lastLowRiskRemediation = $this->getSetting('ean_reconcile_last_low_risk_remediation', []);

        if (!is_array($status)) {
            $status = [];
        }
        if (!is_array($heartbeat)) {
            $heartbeat = [];
        }
        if (!is_array($lastAutoHeal)) {
            $lastAutoHeal = [];
        }
        if (!is_array($lastLowRiskRemediation)) {
            $lastLowRiskRemediation = [];
        }

        $pendingCount = 0;
        try {
            $pendingCount = (int)$this->db->query(
                "SELECT COUNT(*) FROM ean_purchases WHERE payment_status = 'pending'"
            )->fetchColumn();
        } catch (Exception $e) {
            $pendingCount = -1;
        }

        $failedWebhookCount = 0;
        try {
            $failedWebhookCount = (int)$this->db->query(
                "SELECT COUNT(*) FROM webhook_event_inbox WHERE provider = 'mercadopago' AND status = 'failed'"
            )->fetchColumn();
        } catch (Exception $e) {
            $failedWebhookCount = -1;
        }

        return [
            'credentials_configured' => $this->mercadoPagoService->hasCredentials(),
            'pending_purchases' => $pendingCount,
            'failed_webhook_events' => $failedWebhookCount,
            'last_run' => $status['last_run'] ?? null,
            'history' => $status['history'] ?? [],
            'worker_heartbeat' => $heartbeat,
            'last_auto_heal' => $lastAutoHeal,
            'last_low_risk_remediation' => $lastLowRiskRemediation,
        ];
    }

    /**
     * Relatório de divergências financeiras para triagem operacional.
     */
    public function getFinancialDivergenceReport(int $hoursBack = 72, int $limit = 200): array
    {
        $hoursBack = max(1, min(720, $hoursBack));
        $limit = max(1, min(1000, $limit));
        $since = date('Y-m-d H:i:s', time() - ($hoursBack * 3600));

        $result = [
            'window' => [
                'hours_back' => $hoursBack,
                'since' => $since,
            ],
            'summary' => [],
            'divergences' => [
                'pending_with_payment_id' => [],
                'paid_without_full_assignments' => [],
                'paid_without_credit_transaction' => [],
                'failed_with_assignments' => [],
            ],
            'generated_at' => date('c'),
        ];

        $pendingStmt = $this->db->prepare(
            "SELECT p.id, p.account_id, p.quantity, p.payment_status, p.payment_id, p.payment_external_id, p.created_at
             FROM ean_purchases p
             WHERE p.created_at >= :since
               AND p.payment_status = 'pending'
               AND (COALESCE(p.payment_id, '') <> '' OR COALESCE(p.payment_external_id, '') <> '')
             ORDER BY p.id DESC
                         LIMIT {$limit}"
        );
        $pendingStmt->bindValue(':since', $since);
        $pendingStmt->execute();
        $result['divergences']['pending_with_payment_id'] = $pendingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $paidNoAssignmentsStmt = $this->db->prepare(
            "SELECT p.id, p.account_id, p.quantity, p.payment_status, p.paid_at, p.created_at,
                    COUNT(a.id) AS assigned_count
             FROM ean_purchases p
             LEFT JOIN ean_assignments a ON a.purchase_id = p.id
             WHERE p.created_at >= :since
               AND p.payment_status = 'paid'
             GROUP BY p.id, p.account_id, p.quantity, p.payment_status, p.paid_at, p.created_at
             HAVING assigned_count < p.quantity
             ORDER BY p.id DESC
                         LIMIT {$limit}"
        );
        $paidNoAssignmentsStmt->bindValue(':since', $since);
        $paidNoAssignmentsStmt->execute();
        $result['divergences']['paid_without_full_assignments'] = $paidNoAssignmentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $paidNoTxStmt = $this->db->prepare(
            "SELECT p.id, p.account_id, p.quantity, p.total_amount, p.paid_at, p.created_at
             FROM ean_purchases p
             WHERE p.created_at >= :since
               AND p.payment_status = 'paid'
               AND NOT EXISTS (
                   SELECT 1
                   FROM ean_transactions t
                   WHERE t.reference_type = 'purchase'
                     AND t.reference_id = p.id
                     AND t.type = 'credit'
               )
             ORDER BY p.id DESC
                         LIMIT {$limit}"
        );
        $paidNoTxStmt->bindValue(':since', $since);
        $paidNoTxStmt->execute();
        $result['divergences']['paid_without_credit_transaction'] = $paidNoTxStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $failedWithAssignmentsStmt = $this->db->prepare(
            "SELECT p.id, p.account_id, p.quantity, p.payment_status, p.created_at,
                    COUNT(a.id) AS assigned_count
             FROM ean_purchases p
             LEFT JOIN ean_assignments a ON a.purchase_id = p.id
             WHERE p.created_at >= :since
               AND p.payment_status IN ('failed', 'refunded', 'cancelled')
             GROUP BY p.id, p.account_id, p.quantity, p.payment_status, p.created_at
             HAVING assigned_count > 0
             ORDER BY p.id DESC
                         LIMIT {$limit}"
        );
        $failedWithAssignmentsStmt->bindValue(':since', $since);
        $failedWithAssignmentsStmt->execute();
        $result['divergences']['failed_with_assignments'] = $failedWithAssignmentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $result['summary'] = [
            'pending_with_payment_id' => count($result['divergences']['pending_with_payment_id']),
            'paid_without_full_assignments' => count($result['divergences']['paid_without_full_assignments']),
            'paid_without_credit_transaction' => count($result['divergences']['paid_without_credit_transaction']),
            'failed_with_assignments' => count($result['divergences']['failed_with_assignments']),
            'total_divergences' =>
            count($result['divergences']['pending_with_payment_id'])
                + count($result['divergences']['paid_without_full_assignments'])
                + count($result['divergences']['paid_without_credit_transaction'])
                + count($result['divergences']['failed_with_assignments']),
        ];

        return $result;
    }

    /**
     * Executa auto-healing apenas de ações seguras e isola divergências para revisão manual.
     */
    public function autoHealSafeDivergences(int $hoursBack = 72, int $limit = 200, bool $retryFailedWebhooks = true): array
    {
        $hoursBack = max(1, min(720, $hoursBack));
        $limit = max(1, min(1000, $limit));

        $startedAt = date('c');
        $before = $this->getFinancialDivergenceReport($hoursBack, $limit);

        $reconcileResult = $this->reconcilePendingPayments($limit, 2);
        $retryResult = null;

        if ($retryFailedWebhooks) {
            $retryResult = $this->retryFailedMercadoPagoWebhookEvents(min(500, $limit));
        }

        $after = $this->getFinancialDivergenceReport($hoursBack, $limit);

        $quarantine = [
            'paid_without_full_assignments' => $after['divergences']['paid_without_full_assignments'] ?? [],
            'paid_without_credit_transaction' => $after['divergences']['paid_without_credit_transaction'] ?? [],
            'failed_with_assignments' => $after['divergences']['failed_with_assignments'] ?? [],
        ];

        $result = [
            'started_at' => $startedAt,
            'finished_at' => date('c'),
            'safe_actions' => [
                'reconcile_pending_payments' => $reconcileResult,
                'retry_failed_webhooks' => $retryResult,
            ],
            'divergences_before' => $before['summary'] ?? [],
            'divergences_after' => $after['summary'] ?? [],
            'quarantine' => [
                'count' => count($quarantine['paid_without_full_assignments'])
                    + count($quarantine['paid_without_credit_transaction'])
                    + count($quarantine['failed_with_assignments']),
                'items' => $quarantine,
            ],
        ];

        $this->setSetting(
            'ean_reconcile_last_auto_heal',
            $result,
            'json',
            'Última execução de auto-healing seguro da reconciliação EAN'
        );

        $this->storeReconcileExecution([
            'source' => 'auto_heal_safe',
            'started_at' => $startedAt,
            'finished_at' => date('c'),
            'ok' => true,
            'config' => [
                'hours_back' => $hoursBack,
                'limit' => $limit,
                'retry_failed_webhooks' => $retryFailedWebhooks,
            ],
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Remedia divergências de baixo risco com opção de dry-run.
     *
     * Baixo risco atual:
     * - paid_without_credit_transaction: adiciona transação de crédito ausente.
     */
    public function remediateLowRiskDivergences(
        int $hoursBack = 72,
        int $limit = 200,
        bool $dryRun = true,
        bool $rollbackOnWorsening = true
    ): array {
        $hoursBack = max(1, min(720, $hoursBack));
        $limit = max(1, min(1000, $limit));

        $runId = uniqid('lrrem_', true);
        $before = $this->getFinancialDivergenceReport($hoursBack, $limit);
        $targets = $before['divergences']['paid_without_credit_transaction'] ?? [];

        $result = [
            'run_id' => $runId,
            'mode' => $dryRun ? 'dry_run' : 'apply',
            'started_at' => date('c'),
            'checked' => count($targets),
            'remediated' => 0,
            'skipped' => 0,
            'rollback_on_worsening' => $rollbackOnWorsening,
            'created_transaction_ids' => [],
            'rolled_back' => false,
            'rolled_back_transactions' => 0,
            'divergences_before' => $before['summary'] ?? [],
            'divergences_after' => [],
            'divergences_after_rollback' => [],
            'details' => [],
            'finished_at' => null,
        ];

        foreach ($targets as $target) {
            $purchaseId = (int)($target['id'] ?? 0);
            if ($purchaseId <= 0) {
                $result['skipped']++;
                $result['details'][] = [
                    'purchase_id' => $purchaseId,
                    'status' => 'skipped_invalid_purchase_id',
                ];
                continue;
            }

            $purchaseStmt = $this->db->prepare(
                "SELECT id, account_id, quantity, payment_status
                 FROM ean_purchases
                 WHERE id = :id
                 LIMIT 1"
            );
            $purchaseStmt->execute(['id' => $purchaseId]);
            $purchase = $purchaseStmt->fetch(PDO::FETCH_ASSOC);

            if (!$purchase) {
                $result['skipped']++;
                $result['details'][] = [
                    'purchase_id' => $purchaseId,
                    'status' => 'skipped_purchase_not_found',
                ];
                continue;
            }

            if ((string)$purchase['payment_status'] !== 'paid') {
                $result['skipped']++;
                $result['details'][] = [
                    'purchase_id' => $purchaseId,
                    'status' => 'skipped_not_paid_anymore',
                ];
                continue;
            }

            $txExistsStmt = $this->db->prepare(
                "SELECT 1
                 FROM ean_transactions
                 WHERE reference_type = 'purchase'
                   AND reference_id = :purchase_id
                   AND type = 'credit'
                 LIMIT 1"
            );
            $txExistsStmt->execute(['purchase_id' => $purchaseId]);
            $txExists = (bool)$txExistsStmt->fetchColumn();

            if ($txExists) {
                $result['skipped']++;
                $result['details'][] = [
                    'purchase_id' => $purchaseId,
                    'status' => 'skipped_already_remediated',
                ];
                continue;
            }

            if ($dryRun) {
                $result['remediated']++;
                $result['details'][] = [
                    'purchase_id' => $purchaseId,
                    'status' => 'would_create_missing_credit_transaction',
                    'quantity' => (int)$purchase['quantity'],
                ];
                continue;
            }

            $transactionId = $this->recordTransaction(
                (int)$purchase['account_id'],
                'credit',
                (int)$purchase['quantity'],
                'purchase',
                $purchaseId,
                "Remediação automática [{$runId}]: transação de crédito ausente para compra paga"
            );

            $result['created_transaction_ids'][] = $transactionId;
            $result['remediated']++;
            $result['details'][] = [
                'purchase_id' => $purchaseId,
                'status' => 'remediated_credit_transaction_created',
                'quantity' => (int)$purchase['quantity'],
                'transaction_id' => $transactionId,
            ];
        }

        $after = $this->getFinancialDivergenceReport($hoursBack, $limit);
        $result['divergences_after'] = $after['summary'] ?? [];

        if (
            !$dryRun
            && $rollbackOnWorsening
            && !empty($result['created_transaction_ids'])
            && (int)($result['divergences_after']['total_divergences'] ?? 0)
            > (int)($result['divergences_before']['total_divergences'] ?? 0)
        ) {
            $rolledBackCount = $this->rollbackLowRiskRemediationTransactions(
                $result['created_transaction_ids'],
                $runId
            );
            $afterRollback = $this->getFinancialDivergenceReport($hoursBack, $limit);

            $result['rolled_back'] = true;
            $result['rolled_back_transactions'] = $rolledBackCount;
            $result['divergences_after_rollback'] = $afterRollback['summary'] ?? [];
            $result['details'][] = [
                'status' => 'rollback_executed_due_to_worsening',
                'rolled_back_transactions' => $rolledBackCount,
            ];
        }

        $result['finished_at'] = date('c');

        $this->setSetting(
            'ean_reconcile_last_low_risk_remediation',
            $result,
            'json',
            'Última execução de remediação de baixo risco da reconciliação EAN'
        );

        $this->storeReconcileExecution([
            'source' => $dryRun ? 'low_risk_remediation_dry_run' : 'low_risk_remediation_apply',
            'started_at' => $result['started_at'],
            'finished_at' => $result['finished_at'],
            'ok' => true,
            'config' => [
                'hours_back' => $hoursBack,
                'limit' => $limit,
                'dry_run' => $dryRun,
                'rollback_on_worsening' => $rollbackOnWorsening,
            ],
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Gera um plano de execução da reconciliação sem aplicar mudanças.
     */
    public function previewReconciliationPlan(int $hoursBack = 72, int $limit = 200): array
    {
        $hoursBack = max(1, min(720, $hoursBack));
        $limit = max(1, min(1000, $limit));

        $status = $this->getReconcileStatus();
        $divergenceReport = $this->getFinancialDivergenceReport($hoursBack, $limit);

        $lowRiskCandidates = array_slice(
            $divergenceReport['divergences']['paid_without_credit_transaction'] ?? [],
            0,
            min($limit, 100)
        );

        $quarantine = [
            'paid_without_full_assignments' => count($divergenceReport['divergences']['paid_without_full_assignments'] ?? []),
            'failed_with_assignments' => count($divergenceReport['divergences']['failed_with_assignments'] ?? []),
        ];

        $totalQuarantine = (int)$quarantine['paid_without_full_assignments'] + (int)$quarantine['failed_with_assignments'];
        $totalLowRisk = count($lowRiskCandidates);
        $totalPendingWithPaymentId = count($divergenceReport['divergences']['pending_with_payment_id'] ?? []);

        return [
            'generated_at' => date('c'),
            'window' => [
                'hours_back' => $hoursBack,
                'limit' => $limit,
            ],
            'readiness' => [
                'credentials_configured' => (bool)($status['credentials_configured'] ?? false),
                'pending_purchases' => (int)($status['pending_purchases'] ?? 0),
                'failed_webhook_events' => (int)($status['failed_webhook_events'] ?? 0),
            ],
            'actions_plan' => [
                'safe_auto_heal' => [
                    'enabled' => true,
                    'expected_targets' => $totalPendingWithPaymentId + (int)($status['failed_webhook_events'] ?? 0),
                    'description' => 'Reconciliar pagamentos pendentes e reprocessar webhooks falhos',
                ],
                'low_risk_remediation' => [
                    'enabled' => true,
                    'mode_default' => 'dry_run',
                    'candidate_count' => $totalLowRisk,
                    'candidates_sample' => array_slice($lowRiskCandidates, 0, 20),
                    'description' => 'Criar transações de crédito faltantes para compras já pagas',
                ],
                'quarantine' => [
                    'count' => $totalQuarantine,
                    'items' => $quarantine,
                    'description' => 'Casos críticos para revisão manual (não auto-remediáveis)',
                ],
            ],
            'risk' => [
                'level' => $totalQuarantine > 0 ? 'medium' : 'low',
                'total_divergences' => (int)($divergenceReport['summary']['total_divergences'] ?? 0),
                'requires_manual_review' => $totalQuarantine > 0,
            ],
        ];
    }

    /**
     * Salva snapshot de preview para auditoria e comparação de drift.
     */
    public function storeReconciliationPreviewSnapshot(array $plan, string $source = 'manual'): void
    {
        $existing = $this->getSetting('ean_reconcile_preview_history', []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $history = $existing['history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        $snapshot = [
            'id' => uniqid('preview_', true),
            'source' => $source,
            'captured_at' => date('c'),
            'summary' => [
                'total_divergences' => (int)($plan['risk']['total_divergences'] ?? 0),
                'risk_level' => (string)($plan['risk']['level'] ?? 'unknown'),
                'pending_purchases' => (int)($plan['readiness']['pending_purchases'] ?? 0),
                'failed_webhook_events' => (int)($plan['readiness']['failed_webhook_events'] ?? 0),
                'requires_manual_review' => (bool)($plan['risk']['requires_manual_review'] ?? false),
            ],
            'plan' => $plan,
        ];

        $history[] = $snapshot;
        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }

        $payload = [
            'last_snapshot' => $snapshot,
            'history' => array_values($history),
            'updated_at' => date('c'),
        ];

        $this->setSetting(
            'ean_reconcile_preview_history',
            $payload,
            'json',
            'Histórico de snapshots do preview da reconciliação EAN'
        );
    }

    /**
     * Retorna histórico de snapshots do preview.
     */
    public function getReconciliationPreviewHistory(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stored = $this->getSetting('ean_reconcile_preview_history', []);

        if (!is_array($stored)) {
            return [];
        }

        $history = $stored['history'] ?? [];
        if (!is_array($history)) {
            return [];
        }

        return array_slice(array_reverse($history), 0, $limit);
    }

    /**
     * Compara último snapshot de preview com último run executado.
     */
    public function getReconciliationDrift(): array
    {
        $previewStored = $this->getSetting('ean_reconcile_preview_history', []);
        $status = $this->getSetting('ean_reconcile_status', []);

        $lastPreview = is_array($previewStored) ? ($previewStored['last_snapshot'] ?? null) : null;
        $lastRun = is_array($status) ? ($status['last_run'] ?? null) : null;

        if (!is_array($lastPreview) || !is_array($lastRun)) {
            return [
                'available' => false,
                'reason' => 'preview_or_last_run_missing',
            ];
        }

        $previewTotal = (int)($lastPreview['summary']['total_divergences'] ?? 0);
        $runResult = is_array($lastRun['result'] ?? null) ? $lastRun['result'] : [];
        $runTotal =
            (int)($runResult['errors'] ?? 0)
            + (int)($runResult['still_pending'] ?? 0)
            + (int)($runResult['without_payment_id'] ?? 0);

        return [
            'available' => true,
            'preview_snapshot_id' => (string)($lastPreview['id'] ?? ''),
            'preview_captured_at' => (string)($lastPreview['captured_at'] ?? ''),
            'last_run_finished_at' => (string)($lastRun['finished_at'] ?? ''),
            'preview_total_divergences' => $previewTotal,
            'last_run_operational_load' => $runTotal,
            'delta' => $runTotal - $previewTotal,
        ];
    }

    /**
     * Registra alerta operacional com deduplicação temporal por tipo+mensagem.
     */
    public function storeOperationalAlert(
        string $type,
        string $severity,
        string $message,
        array $context = [],
        int $dedupeWindowSeconds = 600
    ): bool {
        $severity = strtolower(trim($severity));
        if (!in_array($severity, ['info', 'warning', 'critical'], true)) {
            $severity = 'warning';
        }

        $stored = $this->getSetting('ean_operational_alerts', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $history = $stored['history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        $nowTs = time();
        $dedupeWindowSeconds = max(0, min(86400, $dedupeWindowSeconds));
        if ($dedupeWindowSeconds > 0 && !empty($history)) {
            $last = end($history);
            if (is_array($last)) {
                $lastType = (string)($last['type'] ?? '');
                $lastMessage = (string)($last['message'] ?? '');
                $lastCreated = (string)($last['created_at'] ?? '');
                $lastTs = $lastCreated !== '' ? strtotime($lastCreated) : false;

                if (
                    $lastType === $type
                    && $lastMessage === $message
                    && $lastTs !== false
                    && ($nowTs - $lastTs) < $dedupeWindowSeconds
                ) {
                    return false;
                }
            }
        }

        $alert = [
            'id' => uniqid('alert_', true),
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
            'created_at' => date('c', $nowTs),
        ];

        $history[] = $alert;
        if (count($history) > 300) {
            $history = array_slice($history, -300);
        }

        $payload = [
            'last_alert' => $alert,
            'history' => array_values($history),
            'updated_at' => date('c', $nowTs),
        ];

        $this->setSetting(
            'ean_operational_alerts',
            $payload,
            'json',
            'Alertas operacionais do pipeline EAN/MP/ML'
        );

        return true;
    }

    /**
     * Lista alertas operacionais recentes.
     */
    public function getOperationalAlerts(int $limit = 50, ?string $type = null, ?string $severity = null): array
    {
        $limit = max(1, min(200, $limit));
        $stored = $this->getSetting('ean_operational_alerts', []);

        if (!is_array($stored)) {
            return [];
        }

        $history = $stored['history'] ?? [];
        if (!is_array($history)) {
            return [];
        }

        $alerts = array_reverse($history);

        if ($type !== null && $type !== '') {
            $alerts = array_values(array_filter($alerts, static function ($row) use ($type): bool {
                return (string)($row['type'] ?? '') === $type;
            }));
        }

        if ($severity !== null && $severity !== '') {
            $alerts = array_values(array_filter($alerts, static function ($row) use ($severity): bool {
                return (string)($row['severity'] ?? '') === $severity;
            }));
        }

        return array_slice($alerts, 0, $limit);
    }

    /**
     * Avalia escalonamento de incidentes por reincidência em janela temporal.
     */
    public function evaluateOperationalEscalation(array $issues, int $windowMinutes = 60): array
    {
        $windowMinutes = max(5, min(1440, $windowMinutes));
        $windowSeconds = $windowMinutes * 60;

        $normalizedIssues = array_values(array_unique(array_filter(array_map(
            static fn($issue) => is_string($issue) ? trim($issue) : '',
            $issues
        ))));

        $issueCounts = [];
        foreach ($normalizedIssues as $issue) {
            $issueCounts[$issue] = $this->countRecentHealthIssueOccurrences($issue, $windowSeconds);
        }

        $maxOccurrences = empty($issueCounts) ? 0 : max($issueCounts);
        $level = 0;
        if ($maxOccurrences >= 6) {
            $level = 3;
        } elseif ($maxOccurrences >= 3) {
            $level = 2;
        } elseif ($maxOccurrences >= 1) {
            $level = 1;
        }

        $severity = 'info';
        if ($level === 1) {
            $severity = 'warning';
        } elseif ($level >= 2) {
            $severity = 'critical';
        }

        $escalation = [
            'level' => $level,
            'severity' => $severity,
            'window_minutes' => $windowMinutes,
            'issue_occurrences' => $issueCounts,
            'max_occurrences' => $maxOccurrences,
            'evaluated_at' => date('c'),
        ];

        $this->setSetting(
            'ean_operational_escalation_status',
            $escalation,
            'json',
            'Status de escalonamento operacional do pipeline EAN/MP/ML'
        );

        return $escalation;
    }

    /**
     * Retorna último status de escalonamento operacional.
     */
    public function getOperationalEscalationStatus(): array
    {
        $status = $this->getSetting('ean_operational_escalation_status', []);
        return is_array($status) ? $status : [];
    }

    /**
     * Avalia e atualiza estado do circuit breaker operacional.
     */
    public function evaluateOperationalCircuitBreaker(array $context = []): array
    {
        $stored = $this->getSetting('ean_operational_circuit_breaker', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $nowTs = time();
        $nowIso = date('c', $nowTs);

        $threshold = max(1, min(20, (int)($context['threshold_cycles'] ?? 3)));
        $openMinutes = max(1, min(240, (int)($context['open_minutes'] ?? 15)));
        $predictiveTrigger = (bool)($context['predictive_trigger'] ?? false);
        $criticalTrigger = (bool)($context['critical_trigger'] ?? false);
        $forceClose = (bool)($context['force_close'] ?? false);

        $state = (string)($stored['state'] ?? 'closed');
        $consecutive = (int)($stored['consecutive_trigger_cycles'] ?? 0);
        $openedUntil = (string)($stored['opened_until'] ?? '');
        $openedUntilTs = $openedUntil !== '' ? strtotime($openedUntil) : false;

        if ($forceClose) {
            $state = 'closed';
            $consecutive = 0;
            $openedUntil = null;
            $openedUntilTs = false;
        }

        // Fechar automaticamente quando janela de proteção expirar
        if ($state === 'open' && $openedUntilTs !== false && $nowTs >= $openedUntilTs) {
            $state = 'closed';
            $consecutive = 0;
            $openedUntil = null;
            $openedUntilTs = false;
        }

        $triggeredThisCycle = $predictiveTrigger && $criticalTrigger;
        if ($triggeredThisCycle) {
            $consecutive++;
        } else {
            $consecutive = 0;
        }

        if ($state !== 'open' && $consecutive >= $threshold) {
            $state = 'open';
            $openedUntil = date('c', $nowTs + ($openMinutes * 60));
            $openedUntilTs = strtotime($openedUntil);

            $this->storeOperationalAlert(
                'ean_operational_circuit_breaker',
                'critical',
                'Circuit breaker operacional aberto automaticamente',
                [
                    'threshold_cycles' => $threshold,
                    'open_minutes' => $openMinutes,
                    'consecutive_trigger_cycles' => $consecutive,
                    'opened_until' => $openedUntil,
                ],
                60
            );
        }

        $payload = [
            'state' => $state,
            'threshold_cycles' => $threshold,
            'open_minutes' => $openMinutes,
            'consecutive_trigger_cycles' => $consecutive,
            'triggered_this_cycle' => $triggeredThisCycle,
            'opened_until' => $openedUntil,
            'last_evaluated_at' => $nowIso,
            'last_context' => [
                'predictive_trigger' => $predictiveTrigger,
                'critical_trigger' => $criticalTrigger,
            ],
        ];

        $this->setSetting(
            'ean_operational_circuit_breaker',
            $payload,
            'json',
            'Estado do circuit breaker operacional EAN/MP/ML'
        );

        return $payload;
    }

    /**
     * Retorna estado atual do circuit breaker operacional.
     */
    public function getOperationalCircuitBreakerStatus(): array
    {
        $status = $this->getSetting('ean_operational_circuit_breaker', []);
        return is_array($status) ? $status : [];
    }

    /**
     * Armazena métrica operacional (timeseries) para análise de tendência.
     */
    public function storeOperationalTimeseriesPoint(array $point): void
    {
        $stored = $this->getSetting('ean_operational_timeseries', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $history = $stored['history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        $normalized = [
            'captured_at' => (string)($point['captured_at'] ?? date('c')),
            'heartbeat_age_minutes' => (int)($point['heartbeat_age_minutes'] ?? -1),
            'total_divergences' => (int)($point['total_divergences'] ?? -1),
            'pending_purchases' => (int)($point['pending_purchases'] ?? -1),
            'failed_webhook_events' => (int)($point['failed_webhook_events'] ?? -1),
            'webhook_avg_processing_seconds' => (float)($point['webhook_avg_processing_seconds'] ?? 0),
            'webhook_failure_rate_percent' => (float)($point['webhook_failure_rate_percent'] ?? 0),
            'webhook_pending_count' => (int)($point['webhook_pending_count'] ?? 0),
            'escalation_level' => (int)($point['escalation_level'] ?? 0),
            'issues_count' => (int)($point['issues_count'] ?? 0),
            'ok' => (bool)($point['ok'] ?? false),
        ];

        $history[] = $normalized;
        if (count($history) > 5000) {
            $history = array_slice($history, -5000);
        }

        $payload = [
            'last_point' => $normalized,
            'history' => array_values($history),
            'updated_at' => date('c'),
        ];

        $this->setSetting(
            'ean_operational_timeseries',
            $payload,
            'json',
            'Série temporal operacional do pipeline EAN/MP/ML'
        );
    }

    /**
     * Retorna tendência e previsão simples baseada em série temporal recente.
     */
    public function getOperationalTimeseriesTrend(int $hoursBack = 24, int $limit = 500): array
    {
        $hoursBack = max(1, min(168, $hoursBack));
        $limit = max(10, min(2000, $limit));

        $stored = $this->getSetting('ean_operational_timeseries', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $history = $stored['history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        $thresholdTs = time() - ($hoursBack * 3600);
        $filtered = [];

        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }

            $capturedAt = (string)($row['captured_at'] ?? '');
            $capturedTs = $capturedAt !== '' ? strtotime($capturedAt) : false;
            if ($capturedTs === false || $capturedTs < $thresholdTs) {
                continue;
            }

            $filtered[] = $row;
        }

        if (count($filtered) > $limit) {
            $filtered = array_slice($filtered, -$limit);
        }

        $count = count($filtered);
        if ($count === 0) {
            return [
                'available' => false,
                'reason' => 'no_points_in_window',
                'window_hours' => $hoursBack,
            ];
        }

        $first = $filtered[0];
        $last = $filtered[$count - 1];

        $divFirst = (int)($first['total_divergences'] ?? 0);
        $divLast = (int)($last['total_divergences'] ?? 0);
        $divDelta = $divLast - $divFirst;

        $latFirst = (float)($first['webhook_avg_processing_seconds'] ?? 0.0);
        $latLast = (float)($last['webhook_avg_processing_seconds'] ?? 0.0);
        $latDelta = $latLast - $latFirst;

        $failureFirst = (float)($first['webhook_failure_rate_percent'] ?? 0.0);
        $failureLast = (float)($last['webhook_failure_rate_percent'] ?? 0.0);
        $failureDelta = $failureLast - $failureFirst;

        $projectionFactor = 0.5;
        $projectedDivergences = (int)round($divLast + ($divDelta * $projectionFactor));
        $projectedLatency = round($latLast + ($latDelta * $projectionFactor), 2);
        $projectedFailureRate = round($failureLast + ($failureDelta * $projectionFactor), 2);

        return [
            'available' => true,
            'window_hours' => $hoursBack,
            'points' => $count,
            'trend' => [
                'total_divergences_delta' => $divDelta,
                'webhook_avg_processing_seconds_delta' => round($latDelta, 2),
                'webhook_failure_rate_percent_delta' => round($failureDelta, 2),
            ],
            'projection_next_window' => [
                'total_divergences' => $projectedDivergences,
                'webhook_avg_processing_seconds' => $projectedLatency,
                'webhook_failure_rate_percent' => $projectedFailureRate,
            ],
            'latest' => $last,
            'series' => $filtered,
            'generated_at' => date('c'),
        ];
    }

    /**
     * Executa runbook automático de recuperação com ações seguras.
     */
    public function executeOperationalRunbook(
        array $issues,
        array $options = []
    ): array {
        $normalizedIssues = array_values(array_unique(array_filter(array_map(
            static fn($i) => is_string($i) ? trim($i) : '',
            $issues
        ))));

        $cooldownSeconds = (int)($options['cooldown_seconds'] ?? 600);
        $cooldownSeconds = max(0, min(86400, $cooldownSeconds));
        $force = (bool)($options['force'] ?? false);
        $source = (string)($options['source'] ?? 'health_check');
        $escalationLevel = max(0, min(3, (int)($options['escalation_level'] ?? 0)));
        $circuitBreakerOpen = (bool)($options['circuit_breaker_open'] ?? false);

        $stored = $this->getSetting('ean_operational_runbook_status', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $lastRun = $stored['last_run'] ?? null;
        $lastRunTs = is_array($lastRun) ? strtotime((string)($lastRun['executed_at'] ?? '')) : false;
        $nowTs = time();

        if (!$force && $cooldownSeconds > 0 && $lastRunTs !== false && ($nowTs - $lastRunTs) < $cooldownSeconds) {
            return [
                'executed' => false,
                'reason' => 'cooldown_active',
                'cooldown_seconds' => $cooldownSeconds,
                'remaining_seconds' => max(0, $cooldownSeconds - ($nowTs - $lastRunTs)),
                'issues' => $normalizedIssues,
                'source' => $source,
            ];
        }

        $run = [
            'id' => uniqid('runbook_', true),
            'executed' => true,
            'executed_at' => date('c', $nowTs),
            'source' => $source,
            'escalation_level' => $escalationLevel,
            'circuit_breaker_open' => $circuitBreakerOpen,
            'issues' => $normalizedIssues,
            'actions' => [],
            'summary' => [
                'actions_count' => 0,
                'errors' => 0,
            ],
        ];

        $hasWebhookPressure = $this->hasAnyIssue($normalizedIssues, [
            'webhook_sla_avg_latency_exceeded',
            'webhook_sla_failure_rate_exceeded',
            'webhook_sla_pending_backlog_exceeded',
            'predictive_webhook_sla_risk',
        ]);

        if ($hasWebhookPressure) {
            $retryLimit = (int)($options['retry_limit'] ?? ($escalationLevel >= 2 ? 200 : 100));
            $reconcileLimit = (int)($options['reconcile_limit'] ?? ($escalationLevel >= 2 ? 220 : 120));

            try {
                $retry = $this->retryFailedMercadoPagoWebhookEvents($retryLimit);
                $run['actions'][] = [
                    'name' => 'retry_failed_webhooks',
                    'ok' => true,
                    'result' => $retry,
                ];
            } catch (\Throwable $e) {
                $run['summary']['errors']++;
                $run['actions'][] = [
                    'name' => 'retry_failed_webhooks',
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }

            try {
                $reconcile = $this->reconcilePendingPayments($reconcileLimit, 2);
                $run['actions'][] = [
                    'name' => 'reconcile_pending_payments',
                    'ok' => true,
                    'result' => $reconcile,
                ];
            } catch (\Throwable $e) {
                $run['summary']['errors']++;
                $run['actions'][] = [
                    'name' => 'reconcile_pending_payments',
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (
            !$circuitBreakerOpen
            && $this->hasAnyIssue($normalizedIssues, ['divergence_threshold_exceeded', 'predictive_divergence_risk'])
        ) {
            try {
                $autoHealLimit = (int)($options['auto_heal_limit'] ?? ($escalationLevel >= 2 ? 350 : 200));
                $autoHeal = $this->autoHealSafeDivergences(24, $autoHealLimit, true);
                $run['actions'][] = [
                    'name' => 'auto_heal_safe',
                    'ok' => true,
                    'result' => $autoHeal,
                ];
            } catch (\Throwable $e) {
                $run['summary']['errors']++;
                $run['actions'][] = [
                    'name' => 'auto_heal_safe',
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }

            try {
                $dryPlan = $this->remediateLowRiskDivergences(24, 100, true, true);
                $run['actions'][] = [
                    'name' => 'low_risk_remediation_dry_run',
                    'ok' => true,
                    'result' => $dryPlan,
                ];
            } catch (\Throwable $e) {
                $run['summary']['errors']++;
                $run['actions'][] = [
                    'name' => 'low_risk_remediation_dry_run',
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (
            $circuitBreakerOpen
            && $this->hasAnyIssue($normalizedIssues, ['divergence_threshold_exceeded', 'predictive_divergence_risk'])
        ) {
            $run['actions'][] = [
                'name' => 'circuit_breaker_guard',
                'ok' => true,
                'result' => [
                    'skipped_actions' => ['auto_heal_safe', 'low_risk_remediation_dry_run'],
                    'reason' => 'circuit_breaker_open',
                ],
            ];
        }

        if ($this->hasAnyIssue($normalizedIssues, ['worker_heartbeat_stale'])) {
            $this->updateReconcileWorkerHeartbeat([
                'state' => 'runbook_recovery_signal',
                'recovery_signal_at' => date('c'),
            ]);
            $run['actions'][] = [
                'name' => 'heartbeat_recovery_signal',
                'ok' => true,
                'result' => ['signaled' => true],
            ];
        }

        if ($escalationLevel >= 3) {
            try {
                $preview = $this->previewReconciliationPlan(24, 300);
                $this->storeReconciliationPreviewSnapshot($preview, 'runbook_escalation_level_3');
                $run['actions'][] = [
                    'name' => 'snapshot_preview_level_3',
                    'ok' => true,
                    'result' => [
                        'saved' => true,
                        'risk' => $preview['risk'] ?? [],
                    ],
                ];
            } catch (\Throwable $e) {
                $run['summary']['errors']++;
                $run['actions'][] = [
                    'name' => 'snapshot_preview_level_3',
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $run['summary']['actions_count'] = count($run['actions']);

        $history = $stored['history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = $run;
        if (count($history) > 120) {
            $history = array_slice($history, -120);
        }

        $statusPayload = [
            'last_run' => $run,
            'history' => array_values($history),
            'updated_at' => date('c'),
        ];

        $this->setSetting(
            'ean_operational_runbook_status',
            $statusPayload,
            'json',
            'Status do runbook automático do pipeline EAN/MP/ML'
        );

        $severity = $run['summary']['errors'] > 0 ? 'critical' : 'warning';
        $this->storeOperationalAlert(
            'ean_operational_runbook',
            $severity,
            'Runbook operacional executado automaticamente',
            [
                'run_id' => $run['id'],
                'issues' => $normalizedIssues,
                'actions_count' => $run['summary']['actions_count'],
                'errors' => $run['summary']['errors'],
            ],
            60
        );

        return $run;
    }

    /**
     * Retorna status do runbook operacional.
     */
    public function getOperationalRunbookStatus(int $historyLimit = 20): array
    {
        $historyLimit = max(1, min(100, $historyLimit));
        $stored = $this->getSetting('ean_operational_runbook_status', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $history = $stored['history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        return [
            'last_run' => $stored['last_run'] ?? null,
            'history' => array_slice(array_reverse($history), 0, $historyLimit),
            'updated_at' => $stored['updated_at'] ?? null,
        ];
    }

    private function countRecentHealthIssueOccurrences(string $issue, int $windowSeconds): int
    {
        $alerts = $this->getOperationalAlerts(300, 'ean_reconcile_health_check', null);
        if (empty($alerts)) {
            return 0;
        }

        $threshold = time() - max(60, $windowSeconds);
        $count = 0;

        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $createdAt = (string)($alert['created_at'] ?? '');
            $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
            if ($createdTs === false || $createdTs < $threshold) {
                continue;
            }

            $context = $alert['context'] ?? [];
            $issues = is_array($context) ? ($context['issues'] ?? []) : [];
            if (!is_array($issues)) {
                continue;
            }

            foreach ($issues as $listedIssue) {
                if ((string)$listedIssue === $issue) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    private function hasAnyIssue(array $issues, array $targets): bool
    {
        if (empty($issues) || empty($targets)) {
            return false;
        }

        $map = array_fill_keys($issues, true);
        foreach ($targets as $target) {
            if (isset($map[$target])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove transações de uma execução específica de remediação low-risk.
     */
    private function rollbackLowRiskRemediationTransactions(array $transactionIds, string $runId): int
    {
        $filteredIds = array_values(array_filter(array_map('intval', $transactionIds), static fn($id) => $id > 0));
        if (empty($filteredIds)) {
            return 0;
        }

        $deleted = 0;
        $stmt = $this->db->prepare(
            "DELETE FROM ean_transactions
             WHERE id = :id
               AND description LIKE :run_tag"
        );

        foreach ($filteredIds as $transactionId) {
            $stmt->execute([
                'id' => $transactionId,
                'run_tag' => "%{$runId}%",
            ]);
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }

    /**
     * Reprocessa eventos falhos de webhook Mercado Pago armazenados na inbox.
     */
    public function retryFailedMercadoPagoWebhookEvents(int $limit = 50): array
    {
        $inbox = new WebhookInboxService();
        $events = $inbox->getFailedEvents('mercadopago', $limit);

        $result = [
            'retried' => 0,
            'recovered' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($events as $event) {
            $result['retried']++;
            $eventKey = (string)($event['event_key'] ?? '');
            $payloadRaw = (string)($event['payload_json'] ?? '');
            $payload = json_decode($payloadRaw, true);

            if (!is_array($payload)) {
                $result['failed']++;
                $result['details'][] = [
                    'event_key' => $eventKey,
                    'status' => 'invalid_payload_json',
                ];
                continue;
            }

            try {
                $processResult = $this->processPaymentWebhook($payload);
                $inbox->markProcessed('mercadopago', $eventKey, [
                    'retry' => true,
                    'process_result' => $processResult,
                ]);

                $result['recovered']++;
                $result['details'][] = [
                    'event_key' => $eventKey,
                    'status' => 'recovered',
                ];
            } catch (Exception $e) {
                $inbox->markFailed('mercadopago', $eventKey, $e->getMessage());
                $result['failed']++;
                $result['details'][] = [
                    'event_key' => $eventKey,
                    'status' => 'failed_again',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Confirmar pagamento e atribuir EANs
     */
    public function confirmPayment(int $purchaseId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM ean_purchases WHERE id = :id");
        $stmt->execute(['id' => $purchaseId]);
        $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$purchase) {
            throw new Exception('Compra não encontrada');
        }

        if ($purchase['payment_status'] === 'paid') {
            return ['status' => 'already_paid', 'purchase_id' => $purchaseId];
        }

        $this->db->beginTransaction();

        try {
            // Atualizar status da compra
            $this->db->prepare(
                "UPDATE ean_purchases SET payment_status = 'paid', paid_at = NOW() WHERE id = :id"
            )->execute(['id' => $purchaseId]);

            // Atribuir EANs disponíveis ao seller
            $quantity = $purchase['quantity'];
            $accountId = $purchase['account_id'];

            $qtySql = max(1, min(1000, (int)$quantity));

            $stmt = $this->db->prepare(
                "SELECT id, ean FROM ean_inventory WHERE status = 'available' ORDER BY id ASC LIMIT {$qtySql}"
            );
            $stmt->execute();
            $availableEans = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $assigned = 0;
            foreach ($availableEans as $eanRow) {
                // Marcar como reservado
                $this->db->prepare(
                    "UPDATE ean_inventory SET status = 'reserved', reserved_at = NOW() WHERE id = :id"
                )->execute(['id' => $eanRow['id']]);

                // Criar atribuição
                $this->db->prepare(
                    "INSERT INTO ean_assignments (ean_id, account_id, purchase_id, assigned_at)
                     VALUES (:ean_id, :account_id, :purchase_id, NOW())"
                )->execute([
                    'ean_id' => $eanRow['id'],
                    'account_id' => $accountId,
                    'purchase_id' => $purchaseId,
                ]);
                $assigned++;
            }

            // Atualizar saldo
            $this->db->prepare(
                "INSERT INTO ean_balances (account_id, total_purchased, available)
                 VALUES (:account_id, :qty, :qty)
                 ON DUPLICATE KEY UPDATE
                    total_purchased = total_purchased + :qty2,
                    available = available + :qty3,
                    last_purchase_at = NOW()"
            )->execute([
                'account_id' => $accountId,
                'qty' => $assigned,
                'qty2' => $assigned,
                'qty3' => $assigned,
            ]);

            // Registrar transação
            $this->recordTransaction(
                $accountId,
                'credit',
                $assigned,
                'purchase',
                $purchaseId,
                "Compra confirmada: {$assigned} EANs"
            );

            $this->db->commit();

            return [
                'status' => 'confirmed',
                'purchase_id' => $purchaseId,
                'eans_assigned' => $assigned,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // Admin
    // =========================================================================

    /**
     * Dashboard admin
     */
    public function getAdminDashboard(): array
    {
        $totalInventory = $this->db->query("SELECT COUNT(*) FROM ean_inventory")->fetchColumn();
        $available = $this->db->query("SELECT COUNT(*) FROM ean_inventory WHERE status = 'available'")->fetchColumn();
        $reserved = $this->db->query("SELECT COUNT(*) FROM ean_inventory WHERE status = 'reserved'")->fetchColumn();
        $sold = $this->db->query("SELECT COUNT(*) FROM ean_inventory WHERE status = 'sold'")->fetchColumn();
        $totalRevenue = $this->db->query("SELECT COALESCE(SUM(total_amount), 0) FROM ean_purchases WHERE payment_status = 'paid'")->fetchColumn();
        $pendingPayments = $this->db->query("SELECT COUNT(*) FROM ean_purchases WHERE payment_status = 'pending'")->fetchColumn();
        $totalAccounts = $this->db->query("SELECT COUNT(DISTINCT account_id) FROM ean_balances")->fetchColumn();

        return [
            'inventory' => [
                'total' => (int) $totalInventory,
                'available' => (int) $available,
                'reserved' => (int) $reserved,
                'sold' => (int) $sold,
            ],
            'revenue' => [
                'total' => (float) $totalRevenue,
                'pending_payments' => (int) $pendingPayments,
            ],
            'accounts' => (int) $totalAccounts,
        ];
    }

    /**
     * Listar todas as compras (admin)
     */
    public function listAllPurchases(int $page = 1, ?string $status = null): array
    {
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $where = '';
        $params = [];

        if ($status) {
            $where = "WHERE ep.payment_status = :status";
            $params['status'] = $status;
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM ean_purchases ep {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT ep.*, pkg.name AS package_name
                FROM ean_purchases ep
                LEFT JOIN ean_packages pkg ON pkg.id = ep.package_id
                {$where}
                ORDER BY ep.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'purchases' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Listar inventário (admin)
     */
    public function listInventory(int $page = 1, ?string $status = null, ?string $batch = null): array
    {
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($status) {
            $where[] = "status = :status";
            $params['status'] = $status;
        }
        if ($batch) {
            $where[] = "purchase_batch = :batch";
            $params['batch'] = $batch;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM ean_inventory {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT * FROM ean_inventory {$whereClause} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Adicionar EANs ao inventário (admin)
     */
    public function addToInventory(array $eans, string $batch, float $cost = 0, string $supplier = ''): int
    {
        $added = 0;
        $costPerEan = count($eans) > 0 ? $cost / count($eans) : 0;

        foreach ($eans as $ean) {
            $ean = trim($ean);
            if (empty($ean)) {
                continue;
            }

            // Ignorar duplicados
            $exists = $this->db->prepare("SELECT id FROM ean_inventory WHERE ean = :ean");
            $exists->execute(['ean' => $ean]);
            if ($exists->fetch()) {
                continue;
            }

            $stmt = $this->db->prepare(
                "INSERT INTO ean_inventory (ean, status, purchase_batch, cost, supplier, created_at)
                 VALUES (:ean, 'available', :batch, :cost, :supplier, NOW())"
            );
            $stmt->execute([
                'ean' => $ean,
                'batch' => $batch,
                'cost' => round($costPerEan, 2),
                'supplier' => $supplier,
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * Importar EANs de arquivo CSV/TXT
     */
    public function importFromFile(string $filePath, string $batch, float $cost = 0, string $supplier = ''): array
    {
        if (!file_exists($filePath)) {
            throw new Exception('Arquivo não encontrado');
        }

        $content = file_get_contents($filePath);
        $lines = preg_split('/[\r\n,;]+/', $content);
        $eans = array_filter(array_map('trim', $lines));

        if (empty($eans)) {
            throw new Exception('Nenhum EAN encontrado no arquivo');
        }

        $added = $this->addToInventory($eans, $batch, $cost, $supplier);

        return [
            'total_in_file' => count($eans),
            'added' => $added,
            'skipped' => count($eans) - $added,
            'batch' => $batch,
        ];
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    private function recordTransaction(
        int $accountId,
        string $type,
        int $quantity,
        string $referenceType,
        int $referenceId,
        string $description
    ): int {
        $balance = $this->getBalance($accountId);
        $balanceBefore = $balance['available'] + ($type === 'debit' ? $quantity : -$quantity);
        $balanceAfter = $balance['available'];

        $stmt = $this->db->prepare(
            "INSERT INTO ean_transactions
             (account_id, type, quantity, balance_before, balance_after,
              reference_type, reference_id, description, created_at)
             VALUES
             (:account_id, :type, :qty, :before, :after,
              :ref_type, :ref_id, :description, NOW())"
        );
        $stmt->execute([
            'account_id' => $accountId,
            'type' => $type,
            'qty' => $quantity,
            'before' => $balanceBefore,
            'after' => $balanceAfter,
            'ref_type' => $referenceType,
            'ref_id' => $referenceId,
            'description' => $description,
        ]);

        return (int)$this->db->lastInsertId();
    }

    private function getSetting(string $key, $default = null)
    {
        $stmt = $this->db->prepare(
            "SELECT setting_value, setting_type FROM ean_settings WHERE setting_key = :key"
        );
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $default;
        }

        return match ($row['setting_type']) {
            'int' => (int) $row['setting_value'],
            'float' => (float) $row['setting_value'],
            'boolean' => filter_var($row['setting_value'], FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($row['setting_value'], true),
            default => $row['setting_value'],
        };
    }

    private function setSetting(string $key, $value, string $type = 'string', string $description = ''): void
    {
        $storedValue = $value;

        if ($type === 'json') {
            $storedValue = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($type === 'boolean') {
            $storedValue = $value ? 'true' : 'false';
        }

        $stmt = $this->db->prepare(
            "INSERT INTO ean_settings (setting_key, setting_value, setting_type, description, updated_at)
             VALUES (:setting_key, :setting_value, :setting_type, :description, NOW())
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                setting_type = VALUES(setting_type),
                description = VALUES(description),
                updated_at = NOW()"
        );

        $stmt->execute([
            'setting_key' => $key,
            'setting_value' => (string)$storedValue,
            'setting_type' => $type,
            'description' => $description,
        ]);
    }
}
