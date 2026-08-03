<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;
use RuntimeException;


/**
 * AwaSellerIdentificationService
 *
 * Gerencia o enriquecimento jurídico/comercial dos sellers AWA:
 * CNPJ, razão social, fonte do dado, confiança e status de verificação.
 *
 * Regras importantes:
 * - CNPJ não é obrigatório para um seller aparecer no registry;
 * - dado jurídico nunca sobrescreve automaticamente fields de descoberta;
 * - toda alteração manual guarda created_by/notes/fonte.
 */
class AwaSellerIdentificationService
{
    /** Fontes aceitas para registro de identificação */
    public const VALID_SOURCES = [
        'manual',
        'authorized_ml_account',
        'internal_registry',
        'external_registry',
        'website_review',
        'legal_team_validation',
    ];

    /** Status de verificação aceitos */
    public const VALID_STATUSES = [
        'verified',
        'pending',
        'not_available',
        'conflict',
    ];

    /** Ações aceitas para o histórico operacional de auditoria. */
    private const AUDIT_ACTIONS = [
        'awa_seller_identification_upsert',
        'awa_seller_identification_verified',
        'awa_seller_identification_conflict',
        'awa_seller_identification_not_available',
    ];

    private PDO $db;
    private int $accountId;
    private AuditLogService $audit;

    public function __construct(int $accountId, ?AuditLogService $audit = null)
    {
        if ($accountId <= 0) {
            throw new RuntimeException('AwaSellerIdentificationService: account_id inválido.');
        }

        $this->db        = Database::getInstance();
        $this->accountId = $accountId;
        $this->audit     = $audit ?? new AuditLogService();

        AwaSellerSchemaService::ensureSchema($this->db);
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    /**
     * Retorna a identificação jurídica de um seller.
     * Valida que o seller pertence à conta antes de retornar.
     *
     * @return array<string, mixed>|null
     */
    public function getByRegistryId(int $registryId): ?array
    {
        $this->assertSellerBelongsToAccount($registryId);

        $stmt = $this->db->prepare(
            'SELECT * FROM awa_seller_identification
              WHERE seller_registry_id = :reg_id
              LIMIT 1'
        );
        $stmt->execute(['reg_id' => $registryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Retorna a lista de identificações com status e contagens.
     * Útil para relatórios e dashboards de verificação.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByStatus(string $status, int $page = 1, int $perPage = 50): array
    {
        $this->assertValidStatus($status);

        $perPage = max(1, min(200, $perPage));
        $offset  = max(0, ($page - 1) * $perPage);

        $stmt = $this->db->prepare(
            'SELECT i.*, r.nickname, r.seller_id AS ml_seller_id, r.permalink
               FROM awa_seller_identification i
               JOIN awa_seller_registry r ON r.id = i.seller_registry_id
              WHERE r.account_id = :account_id AND i.verification_status = :status
              ORDER BY i.updated_at DESC
              LIMIT :limit OFFSET :offset'
        );

        $stmt->bindValue(':account_id', $this->accountId, PDO::PARAM_INT);
        $stmt->bindValue(':status',     $status);
        $stmt->bindValue(':limit',      $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset',     $offset,  PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Contagem de sellers por status de identificação para metrics.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.verification_status, COUNT(*) AS cnt
               FROM awa_seller_identification i
               JOIN awa_seller_registry r ON r.id = i.seller_registry_id
              WHERE r.account_id = :account_id
              GROUP BY i.verification_status'
        );
        $stmt->execute(['account_id' => $this->accountId]);

        $counts = array_fill_keys(self::VALID_STATUSES, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['verification_status']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Conta sellers do registry SEM identificação registrada.
     */
    public function countUnidentified(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM awa_seller_registry r
              WHERE r.account_id = :account_id
                AND NOT EXISTS (
                    SELECT 1 FROM awa_seller_identification i WHERE i.seller_registry_id = r.id
                )'
        );
        $stmt->execute(['account_id' => $this->accountId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Retorna o histórico de alterações manuais/operacionais da identificação.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAuditHistory(int $registryId, int $limit = 20): array
    {
        $this->assertSellerBelongsToAccount($registryId);

        $limit = max(1, min(100, $limit));
        $rows  = $this->fetchAuditLogsForAccount(min(500, max(50, $limit * 10)));

        $history = [];
        foreach ($rows as $row) {
            $entry = $this->normalizeAuditRow($row, $registryId);
            if ($entry === null) {
                continue;
            }

            $history[] = $entry;
            if (count($history) >= $limit) {
                break;
            }
        }

        return $history;
    }

    // =========================================================================
    // MUTATIONS
    // =========================================================================

    /**
     * Cria ou atualiza a identificação de um seller.
     * UPSERT — garante que há no máximo 1 registro por seller.
     *
     * @param  array<string, mixed> $data   Campos aceitos: cnpj, razao_social, source_type,
     *                                      source_reference, confidence_score,
     *                                      verification_status, notes, created_by
     * @throws RuntimeException se validação falhar
     */
    public function upsert(int $registryId, array $data): void
    {
        $this->assertSellerBelongsToAccount($registryId);
        $this->validateData($data);

        $before = $this->buildIdentificationSnapshot($this->getByRegistryId($registryId));

        $stmt = $this->db->prepare(
            'INSERT INTO awa_seller_identification
                (seller_registry_id, cnpj, razao_social, source_type, source_reference,
                 confidence_score, verification_status, notes, created_by)
             VALUES
                (:reg_id, :cnpj, :razao_social, :source_type, :source_reference,
                 :confidence_score, :verification_status, :notes, :created_by)
             ON DUPLICATE KEY UPDATE
                cnpj                = VALUES(cnpj),
                razao_social        = VALUES(razao_social),
                source_type         = VALUES(source_type),
                source_reference    = VALUES(source_reference),
                confidence_score    = VALUES(confidence_score),
                verification_status = VALUES(verification_status),
                notes               = VALUES(notes),
                verified_at         = IF(
                    VALUES(verification_status) = \'verified\'
                    AND (verified_at IS NULL OR verification_status != \'verified\'),
                    NOW(),
                    verified_at
                )'
        );

        $stmt->execute([
            'reg_id'              => $registryId,
            'cnpj'                => $this->normalizeCnpj($data['cnpj'] ?? null),
            'razao_social'        => $this->nullableString($data['razao_social'] ?? null),
            'source_type'         => $data['source_type'] ?? 'manual',
            'source_reference'    => $this->nullableString($data['source_reference'] ?? null),
            'confidence_score'    => max(0, min(100, (int) ($data['confidence_score'] ?? 50))),
            'verification_status' => $data['verification_status'] ?? 'pending',
            'notes'               => $this->nullableString($data['notes'] ?? null),
            'created_by'          => $this->nullableString($data['created_by'] ?? null),
        ]);

        $after       = $this->buildIdentificationSnapshot($this->getByRegistryId($registryId));
        $auditUserId = $this->extractAuditUserId($data);

        $this->audit->log(
            'awa_seller_identification_upsert',
            $auditUserId,
            $this->accountId,
            [
                'registry_id'         => $registryId,
                'actor'               => $this->resolveAuditActor($auditUserId, $this->nullableString($data['audit_actor'] ?? null)),
                'source_type'         => $data['source_type'] ?? 'manual',
                'verification_status' => $data['verification_status'] ?? 'pending',
                'has_cnpj'            => !empty($data['cnpj']),
                'confidence_score'    => max(0, min(100, (int) ($data['confidence_score'] ?? 50))),
                'before'              => $before,
                'after'               => $after,
            ],
            'awa_seller_identification'
        );
    }

    /**
     * Marca identificação como verificada.
     * Útil para fluxo de aprovação sem precisar enviar payload completo.
     */
    public function verify(int $registryId, ?string $verifiedBy = null, ?int $userId = null): void
    {
        $this->assertSellerBelongsToAccount($registryId);

        $before = $this->buildIdentificationSnapshot($this->getByRegistryId($registryId));

        $stmt = $this->db->prepare(
            'UPDATE awa_seller_identification
                SET verification_status = \'verified\',
                    verified_at         = NOW(),
                    notes               = CONCAT(COALESCE(notes, \'\'),
                        IF(notes IS NOT NULL AND notes != \'\', \' | \', \'\'),
                        :note
                    )
              WHERE seller_registry_id = :reg_id'
        );

        $note = 'Verificado por: ' . ($verifiedBy ?? 'sistema') . ' em ' . date('d/m/Y H:i');
        $stmt->execute(['reg_id' => $registryId, 'note' => $note]);

        $after = $this->buildIdentificationSnapshot($this->getByRegistryId($registryId));

        $this->audit->log(
            'awa_seller_identification_verified',
            $userId,
            $this->accountId,
            [
                'registry_id' => $registryId,
                'actor' => $this->resolveAuditActor($userId, $verifiedBy),
                'verified_by' => $verifiedBy ?? 'sistema',
                'before' => $before,
                'after' => $after,
            ],
            'awa_seller_identification'
        );
    }

    /**
     * Marca identificação como conflito.
     */
    public function flagConflict(int $registryId, string $reason): void
    {
        $this->assertSellerBelongsToAccount($registryId);

        $before = $this->buildIdentificationSnapshot($this->getByRegistryId($registryId));

        $stmt = $this->db->prepare(
            'UPDATE awa_seller_identification
                SET verification_status = \'conflict\',
                    notes               = CONCAT(COALESCE(notes, \'\'),
                        IF(notes IS NOT NULL AND notes != \'\', \' | \', \'\'),
                        :note
                    )
              WHERE seller_registry_id = :reg_id'
        );

        $note = 'Conflito registrado: ' . $reason . ' — ' . date('d/m/Y H:i');
        $stmt->execute(['reg_id' => $registryId, 'note' => $note]);

        $after = $this->buildIdentificationSnapshot($this->getByRegistryId($registryId));

        $this->audit->log(
            'awa_seller_identification_conflict',
            null,
            $this->accountId,
            [
                'registry_id' => $registryId,
                'actor' => 'sistema',
                'reason' => $reason,
                'before' => $before,
                'after' => $after,
            ],
            'awa_seller_identification'
        );
    }

    /**
     * Remove identificação de um seller (soft via status não_available).
     */
    public function markNotAvailable(int $registryId, ?string $reason = null): void
    {
        $this->assertSellerBelongsToAccount($registryId);

        $before = $this->buildIdentificationSnapshot($this->getByRegistryId($registryId));

        $stmt = $this->db->prepare(
            'UPDATE awa_seller_identification
                SET verification_status = \'not_available\',
                    notes               = CONCAT(COALESCE(notes, \'\'),
                        IF(notes IS NOT NULL AND notes != \'\', \' | \', \'\'),
                        :note
                    )
              WHERE seller_registry_id = :reg_id'
        );

        $note = 'Marcado como não disponível: ' . ($reason ?? 's/d') . ' — ' . date('d/m/Y H:i');
        $stmt->execute(['reg_id' => $registryId, 'note' => $note]);

        $after = $this->buildIdentificationSnapshot($this->getByRegistryId($registryId));

        $this->audit->log(
            'awa_seller_identification_not_available',
            null,
            $this->accountId,
            [
                'registry_id' => $registryId,
                'actor' => 'sistema',
                'reason' => $reason ?? 's/d',
                'before' => $before,
                'after' => $after,
            ],
            'awa_seller_identification'
        );
    }

    // =========================================================================
    // CNPJ UTILS
    // =========================================================================

    /**
     * Normaliza CNPJ para armazenamento (somente dígitos ou null).
     * Não realiza validação de dígito verificador no MVP.
     */
    public function normalizeCnpj(?string $cnpj): ?string
    {
        if ($cnpj === null || $cnpj === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $cnpj);
        if ($digits === null || $digits === '') {
            return null;
        }

        // CNPJ deve ter 14 dígitos
        if (strlen($digits) !== 14) {
            return null;
        }

        // Formata como XX.XXX.XXX/XXXX-XX
        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($digits, 0, 2),
            substr($digits, 2, 3),
            substr($digits, 5, 3),
            substr($digits, 8, 4),
            substr($digits, 12, 2)
        );
    }

    // =========================================================================
    // AUTO-IDENTIFICAÇÃO A PARTIR DO PERFIL ML / BRASILAPI
    // =========================================================================

    /**
     * Tenta identificar automaticamente o CNPJ/Razão Social de um seller
     * consultando o perfil público do ML (/users/{id}) e, se CNPJ encontrado,
     * enriquece com a BrasilAPI.
     *
     * @return array<string, mixed>  {found: bool, cnpj?: string, razao_social?: string, source?: string}
     */
    public function autoIdentifyFromMLProfile(int $registryId, MercadoLivreClient $client): array
    {
        $this->assertSellerBelongsToAccount($registryId);

        $stmt = $this->db->prepare(
            'SELECT seller_id FROM awa_seller_registry WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $registryId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['found' => false, 'reason' => 'seller_not_found'];
        }

        $mlSellerId = (int) $row['seller_id'];
        $profile    = $client->get("/users/{$mlSellerId}", [], null, true);

        if (isset($profile['error'])) {
            return ['found' => false, 'reason' => 'ml_api_error', 'error' => $profile['error']];
        }

        // Extrai CNPJ: identification.type=CNPJ ou company.identification
        $cnpjRaw     = null;
        $idType      = strtoupper((string) ($profile['identification']['type'] ?? ''));
        $idNumber    = (string) ($profile['identification']['number'] ?? '');
        $companyIdRaw = (string) ($profile['company']['identification'] ?? '');

        if ($idType === 'CNPJ' && strlen(preg_replace('/\D/', '', $idNumber)) === 14) {
            $cnpjRaw = $idNumber;
        } elseif (strlen(preg_replace('/\D/', '', $companyIdRaw)) === 14) {
            $cnpjRaw = $companyIdRaw;
        }

        $razaoSocial = trim((string) ($profile['company']['corporate_name'] ?? ''));
        if ($razaoSocial === '') {
            $razaoSocial = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
        }
        if ($razaoSocial === '') {
            $razaoSocial = $profile['nickname'] ?? null;
        }

        if ($cnpjRaw === null) {
            // API pública do ML não expõe CNPJ de terceiros. Não grava not_available
            // (isso poluía a UI com "Sem CNPJ"). Preencha manualmente ou via import.
            return [
                'found'      => false,
                'reason'     => 'no_cnpj_in_profile',
                'message'    => 'CNPJ não disponível via API pública do ML para este seller. Use cadastro manual.',
                'id_type'    => $idType ?: null,
                'is_company' => ($profile['company']['cust_type_id'] ?? '') === 'CO',
                'nickname'   => $profile['nickname'] ?? null,
            ];
        }

        $source = 'ml_profile';

        // Enriquece via BrasilAPI
        $cnpjDigits = preg_replace('/\D/', '', $cnpjRaw);
        $brasilData = $this->fetchBrasilApiCnpj((string) $cnpjDigits);
        if (!empty($brasilData['razao_social'])) {
            $razaoSocial = $brasilData['razao_social'];
            $source      = 'brasilapi_cnpj';
        }

        $this->upsert($registryId, [
            'cnpj'                => $cnpjRaw,
            'razao_social'        => $razaoSocial ?: null,
            'source_type'         => 'authorized_ml_account',
            'source_reference'    => "ML User ID {$mlSellerId}",
            'confidence_score'    => 85,
            'verification_status' => 'pending',
            'notes'               => "Auto-identificado via perfil ML. Fonte: {$source}.",
            'created_by'          => 'auto_ml_profile',
            'audit_actor'         => 'system',
        ]);

        return [
            'found'       => true,
            'cnpj'        => $this->normalizeCnpj($cnpjRaw),
            'razao_social' => $razaoSocial ?: null,
            'source'      => $source,
        ];
    }

    /**
     * Roda auto-identificação em lote para todos os sellers sem CNPJ.
     *
     * @return array<string, int>  {processed, found, not_found, errors}
     */
    public function autoIdentifyBatch(MercadoLivreClient $client, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $stmt = $this->db->prepare(
            'SELECT r.id
               FROM awa_seller_registry r
              WHERE r.account_id = :account_id
                AND NOT EXISTS (
                    SELECT 1 FROM awa_seller_identification i
                     WHERE i.seller_registry_id = r.id AND i.cnpj IS NOT NULL
                )
                AND (r.auto_id_checked_at IS NULL OR r.auto_id_checked_at < (NOW() - INTERVAL 7 DAY))
              ORDER BY r.items_count DESC
              LIMIT :limit'
        );
        $stmt->bindValue(':account_id', $this->accountId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit',      $limit,           \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $results = ['processed' => 0, 'found' => 0, 'not_found' => 0, 'errors' => 0];

        foreach ($rows as $row) {
            try {
                $r = $this->autoIdentifyFromMLProfile((int) $row['id'], $client);
                $this->touchAutoIdChecked((int) $row['id']);
                $results['processed']++;
                if ($r['found'] ?? false) {
                    $results['found']++;
                } else {
                    $results['not_found']++;
                }
            } catch (\Throwable $e) {
                $this->touchAutoIdChecked((int) $row['id']);
                $results['errors']++;
            }
        }

        return $results;
    }

    private function touchAutoIdChecked(int $registryId): void
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE awa_seller_registry
                    SET auto_id_checked_at = NOW()
                  WHERE id = :id AND account_id = :account_id'
            );
            $stmt->execute([
                'id' => $registryId,
                'account_id' => $this->accountId,
            ]);
        } catch (\Throwable $e) {
            // Coluna pode não existir em ambientes antigos — ignora.
        }
    }

    /**
     * Consulta a BrasilAPI para enriquecer CNPJ com razão social e atividade.
     * Retorna array vazio em caso de falha (endpoint ou CNPJ inválido).
     *
     * @return array<string, mixed>
     */
    private function fetchBrasilApiCnpj(string $cnpj14): array
    {
        if (strlen($cnpj14) !== 14 || !ctype_digit($cnpj14)) {
            return [];
        }

        $url = "https://brasilapi.com.br/api/cnpj/v1/{$cnpj14}";

        try {
            $ctx  = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $json = @file_get_contents($url, false, $ctx);
            if ($json === false || $json === '') {
                return [];
            }
            $data = json_decode($json, true);
            if (!is_array($data) || isset($data['message'])) {
                return [];
            }
            return $data;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // =========================================================================
    // PROTECTED HELPERS — overrideáveis para testes
    // =========================================================================

    /**
     * Garante que o seller pertence à conta ativa.
     *
     * @throws RuntimeException se o seller não existir ou pertencer a outra conta
     */
    protected function assertSellerBelongsToAccount(int $registryId): void
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM awa_seller_registry
              WHERE id = :id AND account_id = :account_id
              LIMIT 1'
        );
        $stmt->execute(['id' => $registryId, 'account_id' => $this->accountId]);

        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("Seller #{$registryId} não encontrado ou não pertence à conta.");
        }
    }

    /**
     * @param  array<string, mixed> $data
     * @throws RuntimeException
     */
    protected function validateData(array $data): void
    {
        if (isset($data['source_type']) && !in_array($data['source_type'], self::VALID_SOURCES, true)) {
            throw new RuntimeException(
                'source_type inválido. Aceitos: ' . implode(', ', self::VALID_SOURCES)
            );
        }

        if (isset($data['verification_status']) && !in_array($data['verification_status'], self::VALID_STATUSES, true)) {
            throw new RuntimeException(
                'verification_status inválido. Aceitos: ' . implode(', ', self::VALID_STATUSES)
            );
        }

        if (isset($data['confidence_score'])) {
            $score = (int) $data['confidence_score'];
            if ($score < 0 || $score > 100) {
                throw new RuntimeException('confidence_score deve ser entre 0 e 100.');
            }
        }
    }

    protected function assertValidStatus(string $status): void
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new RuntimeException(
                'Status inválido. Aceitos: ' . implode(', ', self::VALID_STATUSES)
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAuditLogsForAccount(int $limit): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, user_id, action, resource, data, created_at
                   FROM audit_logs
                  WHERE ml_account_id = :account_id
                    AND resource = :resource
                  ORDER BY created_at DESC
                  LIMIT :limit'
            );
            $stmt->bindValue(':account_id', $this->accountId, PDO::PARAM_INT);
            $stmt->bindValue(':resource', 'awa_seller_identification');
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = trim((string) $value);
        return $str === '' ? null : $str;
    }

    /**
     * @param  array<string, mixed>|null $record
     * @return array<string, mixed>|null
     */
    private function buildIdentificationSnapshot(?array $record): ?array
    {
        if (!is_array($record)) {
            return null;
        }

        return [
            'cnpj' => $this->nullableString($record['cnpj'] ?? null),
            'razao_social' => $this->nullableString($record['razao_social'] ?? null),
            'source_type' => $this->nullableString($record['source_type'] ?? null),
            'source_reference' => $this->nullableString($record['source_reference'] ?? null),
            'confidence_score' => isset($record['confidence_score']) ? (int) $record['confidence_score'] : null,
            'verification_status' => $this->nullableString($record['verification_status'] ?? null),
            'notes' => $this->nullableString($record['notes'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>      $row
     * @return array<string, mixed>|null
     */
    private function normalizeAuditRow(array $row, int $registryId): ?array
    {
        $action = (string) ($row['action'] ?? '');
        if (!in_array($action, self::AUDIT_ACTIONS, true)) {
            return null;
        }

        $payload = $this->decodeAuditData($row['data'] ?? null);
        if ((int) ($payload['registry_id'] ?? 0) !== $registryId) {
            return null;
        }

        $before = $this->buildIdentificationSnapshot(
            is_array($payload['before'] ?? null) ? $payload['before'] : null
        );
        $after = $this->buildIdentificationSnapshot(
            is_array($payload['after'] ?? null) ? $payload['after'] : null
        );

        return [
            'id' => (int) ($row['id'] ?? 0),
            'action' => $action,
            'label' => $this->formatAuditActionLabel($action),
            'actor' => $this->normalizeAuditActor(
                isset($row['user_id']) ? (int) $row['user_id'] : null,
                $payload
            ),
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'created_at' => $this->nullableString($row['created_at'] ?? null),
            'summary' => $this->buildAuditSummary($action, $payload, $before),
            'before' => $before,
            'after' => $after,
            'changes' => $this->buildAuditChanges($before, $after),
            'metadata' => $this->buildAuditMetadata($payload),
        ];
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function decodeAuditData(array|string|null $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if (!is_string($data) || $data === '') {
            return [];
        }

        $decoded = json_decode($data, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function extractAuditUserId(array $data): ?int
    {
        $rawValue = $data['audit_user_id'] ?? $data['created_by'] ?? null;
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        $userId = (int) $rawValue;

        return $userId > 0 ? $userId : null;
    }

    private function resolveAuditActor(?int $userId, ?string $actor): string
    {
        if ($userId !== null && $userId > 0) {
            return 'Usuário #' . $userId;
        }

        return $actor !== null && $actor !== '' ? $actor : 'sistema';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normalizeAuditActor(?int $rowUserId, array $payload): string
    {
        $payloadUserId = isset($payload['audit_user_id']) ? (int) $payload['audit_user_id'] : null;
        $userId = $payloadUserId !== null && $payloadUserId > 0 ? $payloadUserId : $rowUserId;

        if ($userId !== null && $userId > 0) {
            return 'Usuário #' . $userId;
        }

        $verifiedBy = $this->nullableString($payload['verified_by'] ?? null);
        if ($verifiedBy !== null) {
            return $verifiedBy;
        }

        $actor = $this->nullableString($payload['actor'] ?? null);

        return $actor ?? 'sistema';
    }

    private function formatAuditActionLabel(string $action): string
    {
        return match ($action) {
            'awa_seller_identification_verified' => 'Verificação',
            'awa_seller_identification_conflict' => 'Conflito',
            'awa_seller_identification_not_available' => 'Indisponível',
            default => 'Atualização manual',
        };
    }

    /**
     * @param  array<string, mixed>      $payload
     * @param  array<string, mixed>|null $before
     */
    private function buildAuditSummary(string $action, array $payload, ?array $before): string
    {
        return match ($action) {
            'awa_seller_identification_verified' => 'Identificação marcada como verificada' .
                (($payload['verified_by'] ?? null) ? ' via ' . (string) $payload['verified_by'] : ''),
            'awa_seller_identification_conflict' => 'Identificação marcada com conflito',
            'awa_seller_identification_not_available' => 'Identificação marcada como não disponível',
            default => $before === null
                ? 'Identificação cadastrada manualmente'
                : 'Identificação atualizada manualmente',
        };
    }

    /**
     * @param  array<string, mixed>|null $before
     * @param  array<string, mixed>|null $after
     * @return array<int, array<string, mixed>>
     */
    private function buildAuditChanges(?array $before, ?array $after): array
    {
        if ($before === null && $after === null) {
            return [];
        }

        $fieldLabels = [
            'cnpj' => 'CNPJ',
            'razao_social' => 'Razão social',
            'source_type' => 'Origem',
            'source_reference' => 'Referência',
            'confidence_score' => 'Confiança',
            'verification_status' => 'Status',
            'notes' => 'Observações',
        ];

        $changes = [];
        foreach ($fieldLabels as $field => $label) {
            $beforeValue = $before[$field] ?? null;
            $afterValue  = $after[$field] ?? null;

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $label,
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildAuditMetadata(array $payload): array
    {
        $metadata = [];
        foreach (['source_type', 'source_reference', 'verification_status', 'confidence_score', 'verified_by', 'reason'] as $key) {
            if (array_key_exists($key, $payload)) {
                $metadata[$key] = $payload[$key];
            }
        }

        return $metadata;
    }
}
