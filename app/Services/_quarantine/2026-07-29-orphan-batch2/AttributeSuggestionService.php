<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\SEO\VersioningService;
use PDO;
use Exception;

/**
 * 🔧 Attribute Suggestion Service
 *
 * Gerencia sugestões de atributos da Ficha Técnica com preview e aplicação real.
 *
 * Para campos que podem ser atualizados via ML API (ex: attributes[]),
 * oferece fluxo de preview → confirmação → apply.
 *
 * Para campos que não possuem endpoint de atualização no ML,
 * mantém como "sugestão interna" sem tentar aplicar.
 *
 * Campos aplicáveis via ML PUT /items/{id}:
 * - attributes[] (BRAND, MODEL, GTIN, MPN, etc)
 *
 * Campos NÃO aplicáveis (somente sugestão interna):
 * - KEYWORDS (não existe no ML, é interno)
 * - Alguns campos calculados/read-only
 *
 * @package App\Services
 */
class AttributeSuggestionService
{
    private const RATE_LIMIT_DELAY_MS = 200;

    /**
     * Atributos que PODEM ser aplicados via ML API
     * PUT /items/{id} com body: { "attributes": [...] }
     */
    private const APPLICABLE_ATTRIBUTES = [
        'BRAND' => ['endpoint' => 'attributes', 'required' => true],
        'MODEL' => ['endpoint' => 'attributes', 'required' => false],
        'MPN' => ['endpoint' => 'attributes', 'required' => false],
        'GTIN' => ['endpoint' => 'attributes', 'required' => false],
        'LINE' => ['endpoint' => 'attributes', 'required' => false],
        'PACKAGE_LENGTH' => ['endpoint' => 'attributes', 'required' => false],
        'PACKAGE_WIDTH' => ['endpoint' => 'attributes', 'required' => false],
        'PACKAGE_HEIGHT' => ['endpoint' => 'attributes', 'required' => false],
        'PACKAGE_WEIGHT' => ['endpoint' => 'attributes', 'required' => false],
        'COLOR' => ['endpoint' => 'attributes', 'required' => false],
        'SIZE' => ['endpoint' => 'attributes', 'required' => false],
        'MATERIAL' => ['endpoint' => 'attributes', 'required' => false],
        'VOLTAGE' => ['endpoint' => 'attributes', 'required' => false],
        'UNITS_PER_PACK' => ['endpoint' => 'attributes', 'required' => false],
        'WARRANTY_TYPE' => ['endpoint' => 'attributes', 'required' => false],
        'WARRANTY_TIME' => ['endpoint' => 'attributes', 'required' => false],
        'ORIGIN' => ['endpoint' => 'attributes', 'required' => false],
        'ALPHANUMERIC_MODEL' => ['endpoint' => 'attributes', 'required' => false],
        'COMPATIBLE_MODELS' => ['endpoint' => 'attributes', 'required' => false],
    ];

    /**
     * Atributos que NÃO podem ser aplicados via ML API
     * Apenas sugestões internas para referência
     */
    private const INTERNAL_ONLY_ATTRIBUTES = [
        'KEYWORDS' => 'Não existe no ML - apenas referência interna',
        'SEO_SCORE' => 'Campo calculado - não editável',
        'CATALOG_PRODUCT_ID' => 'Gerenciado pelo ML - não editável diretamente',
    ];

    private PDO $db;
    private int $accountId;
    private MercadoLivreClient $mlClient;
    private VersioningService $versioning;

    public function __construct(int $accountId)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->versioning = new VersioningService($accountId);
    }

    /**
     * 🔍 Preview de sugestões de atributos
     *
     * Retorna lista de sugestões pendentes com:
     * - Valor atual do atributo
     * - Valor sugerido
     * - Se é aplicável via ML API ou apenas sugestão interna
     * - Confiança da sugestão
     * - Origem (AI, competitor, pattern, etc)
     */
    public function previewSuggestions(string $itemId): array
    {
        try {
            // Buscar item atual do ML
            $item = $this->mlClient->get("/items/{$itemId}");
            if (!$item || isset($item['error'])) {
                return [
                    'success' => false,
                    'error' => $item['message'] ?? $item['error'] ?? 'Item não encontrado',
                ];
            }

            // Mapear atributos atuais
            $currentAttributes = [];
            foreach (($item['attributes'] ?? []) as $attr) {
                $currentAttributes[$attr['id']] = [
                    'id' => $attr['id'],
                    'name' => $attr['name'] ?? $attr['id'],
                    'value' => $attr['value_name'] ?? null,
                    'value_id' => $attr['value_id'] ?? null,
                ];
            }

            // Buscar sugestões pendentes do banco
            $stmt = $this->db->prepare("
                SELECT
                    id, attribute_id, suggested_value, confidence,
                    source, strategy, notes, created_at
                FROM tech_sheet_suggestions
                WHERE item_id = :item_id
                  AND account_id = :account_id
                  AND status = 'pending'
                ORDER BY confidence DESC, created_at DESC
            ");
            $stmt->execute([
                'item_id' => $itemId,
                'account_id' => $this->accountId,
            ]);
            $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Enriquecer sugestões com preview
            $preview = [];
            foreach ($suggestions as $suggestion) {
                $attrId = $suggestion['attribute_id'];
                $current = $currentAttributes[$attrId] ?? null;

                $isApplicable = isset(self::APPLICABLE_ATTRIBUTES[$attrId]);
                $isInternalOnly = isset(self::INTERNAL_ONLY_ATTRIBUTES[$attrId]);

                $preview[] = [
                    'suggestion_id' => (int)$suggestion['id'],
                    'attribute_id' => $attrId,
                    'attribute_name' => $current['name'] ?? $attrId,
                    'current_value' => $current['value'] ?? null,
                    'suggested_value' => $suggestion['suggested_value'],
                    'has_change' => $this->hasRealChange(
                        $current['value'] ?? '',
                        $suggestion['suggested_value'] ?? ''
                    ),
                    'confidence' => (int)$suggestion['confidence'],
                    'source' => $suggestion['source'],
                    'strategy' => $suggestion['strategy'],
                    'notes' => $suggestion['notes'],
                    'is_applicable' => $isApplicable,
                    'is_internal_only' => $isInternalOnly,
                    'applicability_note' => $isInternalOnly
                        ? self::INTERNAL_ONLY_ATTRIBUTES[$attrId]
                        : ($isApplicable ? 'Pode ser aplicado via ML API' : 'Verificar endpoint'),
                    'created_at' => $suggestion['created_at'],
                ];
            }

            return [
                'success' => true,
                'item_id' => $itemId,
                'title' => $item['title'] ?? '',
                'category_id' => $item['category_id'] ?? '',
                'current_attributes_count' => count($currentAttributes),
                'pending_suggestions_count' => count($preview),
                'suggestions' => $preview,
                'applicable_attributes' => array_keys(self::APPLICABLE_ATTRIBUTES),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * ✅ Aplica sugestão de atributo específico
     *
     * Fluxo:
     * 1. Verifica se atributo é aplicável via ML
     * 2. Cria snapshot do estado atual
     * 3. Aplica via PUT /items/{id}
     * 4. Atualiza status da sugestão
     * 5. Refresh cache local
     *
     * @param string $itemId Item ID
     * @param string $attributeId ID do atributo
     * @param mixed $value Valor a aplicar (se null, usa o sugerido)
     * @param int $userId ID do usuário
     * @return array Resultado da aplicação
     */
    public function applySuggestion(string $itemId, string $attributeId, $value, int $userId): array
    {
        try {
            // Verificar se é aplicável
            if (!isset(self::APPLICABLE_ATTRIBUTES[$attributeId])) {
                if (isset(self::INTERNAL_ONLY_ATTRIBUTES[$attributeId])) {
                    return [
                        'success' => false,
                        'error' => 'Este atributo não pode ser aplicado via ML API',
                        'reason' => self::INTERNAL_ONLY_ATTRIBUTES[$attributeId],
                        'action' => 'internal_only',
                    ];
                }
                return [
                    'success' => false,
                    'error' => 'Atributo não reconhecido como aplicável',
                ];
            }

            // Buscar sugestão se valor não fornecido
            if ($value === null) {
                $stmt = $this->db->prepare("
                    SELECT suggested_value FROM tech_sheet_suggestions
                    WHERE item_id = :item_id
                      AND attribute_id = :attribute_id
                      AND account_id = :account_id
                      AND status = 'pending'
                    ORDER BY confidence DESC
                    LIMIT 1
                ");
                $stmt->execute([
                    'item_id' => $itemId,
                    'attribute_id' => $attributeId,
                    'account_id' => $this->accountId,
                ]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    return [
                        'success' => false,
                        'error' => 'Nenhuma sugestão pendente encontrada para este atributo',
                    ];
                }
                $value = $row['suggested_value'];
            }

            // Obter item atual
            $item = $this->mlClient->get("/items/{$itemId}");
            if (!$item || isset($item['error'])) {
                return [
                    'success' => false,
                    'error' => 'Item não encontrado no ML',
                ];
            }

            // Encontrar atributo atual
            $currentValue = null;
            $currentAttrs = $item['attributes'] ?? [];
            foreach ($currentAttrs as $attr) {
                if ($attr['id'] === $attributeId) {
                    $currentValue = $attr['value_name'] ?? null;
                    break;
                }
            }

            // Verificar se há mudança real
            if (!$this->hasRealChange((string)($currentValue ?? ''), (string)$value)) {
                return [
                    'success' => true,
                    'no_op' => true,
                    'message' => 'Valor já é igual ao sugerido - nenhuma alteração necessária',
                ];
            }

            // Criar snapshot antes de aplicar
            $snapshotId = $this->createAttributeSnapshot($itemId, $attributeId, $currentValue);

            // Preparar payload para ML
            // O ML espera o array de atributos completo ou apenas os que mudam
            $newAttrs = [];
            foreach ($currentAttrs as $attr) {
                if ($attr['id'] === $attributeId) {
                    $newAttrs[] = [
                        'id' => $attributeId,
                        'value_name' => $value,
                    ];
                }
            }

            // Se atributo não existia, adicionar
            $attrExists = false;
            foreach ($currentAttrs as $attr) {
                if ($attr['id'] === $attributeId) {
                    $attrExists = true;
                    break;
                }
            }
            if (!$attrExists) {
                $newAttrs[] = [
                    'id' => $attributeId,
                    'value_name' => $value,
                ];
            }

            // Aplicar via ML API
            $updatePayload = [
                'attributes' => $newAttrs,
            ];

            $response = $this->mlClient->put("/items/{$itemId}", $updatePayload);

            if (isset($response['error'])) {
                // Rollback snapshot se falhou
                $this->deleteSnapshot($snapshotId);

                return [
                    'success' => false,
                    'error' => 'Falha ao aplicar no ML: ' . ($response['message'] ?? $response['error'] ?? 'Erro desconhecido'),
                    'ml_response' => $response,
                ];
            }

            // Atualizar status da sugestão
            $this->updateSuggestionStatus($itemId, $attributeId, 'applied', $userId);

            // Registrar no histórico
            $versionId = $this->recordAttributeChange($itemId, $attributeId, $currentValue, $value, $userId, $snapshotId);

            return [
                'success' => true,
                'applied' => true,
                'item_id' => $itemId,
                'attribute_id' => $attributeId,
                'old_value' => $currentValue,
                'new_value' => $value,
                'version_id' => $versionId,
                'snapshot_id' => $snapshotId,
                'message' => "Atributo {$attributeId} atualizado com sucesso",
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 📋 Lista atributos aplicáveis para um item
     */
    public function getApplicableAttributes(string $itemId): array
    {
        try {
            $item = $this->mlClient->get("/items/{$itemId}");
            if (!$item || isset($item['error'])) {
                return [
                    'success' => false,
                    'error' => 'Item não encontrado',
                ];
            }

            $categoryId = $item['category_id'] ?? '';

            // Buscar atributos da categoria
            $categoryAttrs = [];
            if ($categoryId) {
                $catData = $this->mlClient->get("/categories/{$categoryId}/attributes");
                if (is_array($catData)) {
                    $categoryAttrs = $catData;
                }
            }

            // Mapear quais são aplicáveis
            $applicable = [];
            $notApplicable = [];

            foreach ($categoryAttrs as $attr) {
                $attrId = $attr['id'] ?? '';
                $attrInfo = [
                    'id' => $attrId,
                    'name' => $attr['name'] ?? $attrId,
                    'value_type' => $attr['value_type'] ?? 'string',
                    'tags' => $attr['tags'] ?? [],
                    'required' => in_array('required', $attr['tags'] ?? []),
                    'allow_variations' => in_array('allow_variations', $attr['tags'] ?? []),
                ];

                if (isset(self::APPLICABLE_ATTRIBUTES[$attrId])) {
                    $applicable[] = array_merge($attrInfo, [
                        'can_apply' => true,
                        'endpoint' => 'PUT /items/{id}',
                    ]);
                } elseif (isset(self::INTERNAL_ONLY_ATTRIBUTES[$attrId])) {
                    $notApplicable[] = array_merge($attrInfo, [
                        'can_apply' => false,
                        'reason' => self::INTERNAL_ONLY_ATTRIBUTES[$attrId],
                    ]);
                } else {
                    // Atributo genérico - assumir aplicável via attributes[]
                    $applicable[] = array_merge($attrInfo, [
                        'can_apply' => true,
                        'endpoint' => 'PUT /items/{id}',
                        'note' => 'Atributo genérico da categoria',
                    ]);
                }
            }

            return [
                'success' => true,
                'item_id' => $itemId,
                'category_id' => $categoryId,
                'applicable_count' => count($applicable),
                'not_applicable_count' => count($notApplicable),
                'applicable' => $applicable,
                'not_applicable' => $notApplicable,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verifica se há mudança real entre valores
     */
    private function hasRealChange(string $before, string $after): bool
    {
        $normalize = fn($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
        return $normalize($before) !== $normalize($after);
    }

    /**
     * Cria snapshot do atributo antes de modificar
     */
    private function createAttributeSnapshot(string $itemId, string $attributeId, $currentValue): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO seo_attribute_snapshots (
                account_id, item_id, attribute_id,
                value_before, created_at
            ) VALUES (
                :account_id, :item_id, :attribute_id,
                :value_before, NOW()
            )
        ");

        try {
            $stmt->execute([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'attribute_id' => $attributeId,
                'value_before' => $currentValue,
            ]);
        } catch (\PDOException $e) {
            // Criar tabela se não existir
            $this->ensureSnapshotTableExists();
            $stmt->execute([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'attribute_id' => $attributeId,
                'value_before' => $currentValue,
            ]);
        }

        return (int)$this->db->lastInsertId();
    }

    /**
     * Remove snapshot em caso de falha
     */
    private function deleteSnapshot(int $snapshotId): void
    {
        $stmt = $this->db->prepare("DELETE FROM seo_attribute_snapshots WHERE id = ?");
        $stmt->execute([$snapshotId]);
    }

    /**
     * Atualiza status da sugestão
     */
    private function updateSuggestionStatus(string $itemId, string $attributeId, string $status, int $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE tech_sheet_suggestions
            SET status = :status,
                decided_at = NOW(),
                decided_by = :user_id,
                notes = CONCAT(COALESCE(notes, ''), ' [applied via AttributeSuggestionService]')
            WHERE item_id = :item_id
              AND attribute_id = :attribute_id
              AND account_id = :account_id
              AND status = 'pending'
        ");
        $stmt->execute([
            'status' => $status,
            'user_id' => $userId,
            'item_id' => $itemId,
            'attribute_id' => $attributeId,
            'account_id' => $this->accountId,
        ]);
    }

    /**
     * Registra alteração de atributo no histórico
     */
    private function recordAttributeChange(
        string $itemId,
        string $attributeId,
        $oldValue,
        $newValue,
        int $userId,
        int $snapshotId
    ): int {
        // Use o VersioningService (schema oficial de seo_optimization_history)
        // para manter rollback/histórico consistente.
        $beforeData = [
            'id' => $itemId,
            'attributes' => [[
                'id' => $attributeId,
                'value_name' => $oldValue,
            ]],
            'meta' => [
                'source' => 'attribute_suggestion',
                'snapshot_id' => $snapshotId,
            ],
        ];

        $afterData = [
            'id' => $itemId,
            'attributes' => [[
                'id' => $attributeId,
                'value_name' => $newValue,
            ]],
            'meta' => [
                'source' => 'attribute_suggestion',
                'snapshot_id' => $snapshotId,
            ],
        ];

        return $this->versioning->createSnapshot(
            $itemId,
            'attributes',
            $beforeData,
            $afterData,
            'user',
            $userId
        );
    }

    /**
     * Garante que tabela de snapshots existe
     */
    private function ensureSnapshotTableExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS seo_attribute_snapshots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                item_id VARCHAR(50) NOT NULL,
                attribute_id VARCHAR(100) NOT NULL,
                value_before TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_item_attr (item_id, attribute_id),
                INDEX idx_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
