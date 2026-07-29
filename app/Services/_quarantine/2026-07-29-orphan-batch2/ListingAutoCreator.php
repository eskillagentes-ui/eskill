<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\UnifiedAIService;
use PDO;

/**
 * Listing Auto-Creator V9.0
 *
 * Uses AI to generate optimized product listings automatically.
 */
class ListingAutoCreator
{
    private PDO $db;
    private UnifiedAIService $ai;
    private ?MercadoLivreClient $mlClient;
    private ?int $accountId;

    public function __construct(?int $accountId = null)
    {
        $this->db = Database::getInstance();
        $this->ai = new UnifiedAIService($accountId);
        $this->accountId = $accountId;
        $this->mlClient = $accountId ? new MercadoLivreClient($accountId) : null;
        $this->ensureDraftsTable();
    }

    /**
     * Generate a listing draft from product information
     */
    public function generateListingDraft(array $productInfo): array
    {
        // Use AI to generate content
        $contentResult = $this->ai->processAIRequest('generate_content', [
            'product_info' => $productInfo
        ]);

        // Use AI for SEO optimization
        $seoResult = $this->ai->processAIRequest('optimize_seo', [
            'title' => $contentResult['result']['title'] ?? $productInfo['title'],
            'description' => $contentResult['result']['description'] ?? '',
            'category_id' => $productInfo['category_id'] ?? ''
        ]);

        $draft = [
            'title' => $seoResult['result']['optimizations']['title'] ?? $contentResult['result']['title'] ?? $productInfo['title'],
            'description' => $contentResult['result']['description'] ?? '',
            'bullet_points' => $contentResult['result']['bullet_points'] ?? [],
            'seo_keywords' => $seoResult['result']['keyword_analysis']['recommended_keywords'] ?? [],
            'suggested_price' => $productInfo['price'] ?? 0,
            'category_id' => $productInfo['category_id'] ?? '',
            'status' => 'draft',
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Save draft
        $draftId = $this->saveDraft($draft);
        $draft['id'] = $draftId;

        return $draft;
    }

    /**
     * Save draft to database
     */
    private function saveDraft(array $draft): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO listing_drafts
            (title, description, bullet_points, seo_keywords, suggested_price, category_id, status, created_at)
            VALUES (:title, :desc, :bullets, :keywords, :price, :cat, :status, :created)
        ");
        $stmt->execute([
            'title' => $draft['title'],
            'desc' => $draft['description'],
            'bullets' => json_encode($draft['bullet_points']),
            'keywords' => json_encode($draft['seo_keywords']),
            'price' => $draft['suggested_price'],
            'cat' => $draft['category_id'],
            'status' => $draft['status'],
            'created' => $draft['created_at']
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Get pending drafts for review
     */
    public function getPendingDrafts(int $limit = 20): array
    {
        $limitSql = max(1, min((int)$limit, 200));

        $stmt = $this->db->prepare("
            SELECT * FROM listing_drafts WHERE status = 'draft' ORDER BY created_at DESC LIMIT {$limitSql}
        ");
        $stmt->execute();
        $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($drafts as &$d) {
            $d['bullet_points'] = json_decode($d['bullet_points'], true);
            $d['seo_keywords'] = json_decode($d['seo_keywords'], true);
        }
        return $drafts;
    }

    /**
     * Publish a draft to Mercado Livre
     * Creates a real listing via ML API
     */
    public function publishListing(int $draftId, array $options = []): array
    {
        $stmt = $this->db->prepare("SELECT * FROM listing_drafts WHERE id = :id");
        $stmt->execute(['id' => $draftId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$draft) {
            return ['success' => false, 'error' => 'Draft not found'];
        }

        if ($draft['status'] === 'published') {
            return ['success' => false, 'error' => 'Draft already published', 'ml_item_id' => $draft['ml_item_id'] ?? null];
        }

        // Verificar se temos cliente ML configurado
        if (!$this->mlClient) {
            return ['success' => false, 'error' => 'No ML account configured'];
        }

        // Verificar se a conta tem token configurado
        $accessToken = trim((string)$this->mlClient->getAccessToken());
        if ($accessToken === '') {
            return [
                'success' => false,
                'error' => 'ML account not connected or token expired',
                'reason' => 'missing_access_token'
            ];
        }

        try {
            // Preparar payload para API do ML
            $bulletPoints = json_decode($draft['bullet_points'], true) ?: [];
            $description = $draft['description'];

            // Adicionar bullet points à descrição se existirem
            if (!empty($bulletPoints)) {
                $description .= "\n\n" . implode("\n", array_map(fn($bp) => "• {$bp}", $bulletPoints));
            }

            $listingPayload = [
                'title' => mb_substr($draft['title'], 0, 60), // ML limite de 60 chars
                'category_id' => $draft['category_id'],
                'price' => (float)$draft['suggested_price'],
                'currency_id' => 'BRL',
                'available_quantity' => $options['quantity'] ?? 1,
                'buying_mode' => $options['buying_mode'] ?? 'buy_it_now',
                'condition' => $options['condition'] ?? 'new',
                'listing_type_id' => $options['listing_type'] ?? 'gold_special', // gold_special, gold_pro, etc
                'description' => ['plain_text' => $description],
                'pictures' => $this->preparePictures($options['pictures'] ?? []),
                'shipping' => [
                    'mode' => 'me2',
                    'free_shipping' => $options['free_shipping'] ?? false,
                    'local_pick_up' => $options['local_pick_up'] ?? false,
                ],
            ];

            // Adicionar atributos se fornecidos
            if (!empty($options['attributes'])) {
                $listingPayload['attributes'] = $options['attributes'];
            }

            // Chamar API do ML para criar o item
            $response = $this->mlClient->post('/items', $listingPayload);
            $payload = (isset($response['body']) && is_array($response['body'])) ? $response['body'] : $response;

            if (isset($response['error'])) {
                // Salvar erro no draft
                $this->updateDraftStatus($draftId, 'error', null, $response['message'] ?? $response['error'] ?? 'API error');

                return [
                    'success' => false,
                    'error' => $response['message'] ?? $response['error'] ?? 'Failed to create listing',
                    'api_response' => $response
                ];
            }

            $mlItemId = $payload['id'] ?? null;
            $permalink = $payload['permalink'] ?? null;

            if (!$mlItemId) {
                $this->updateDraftStatus($draftId, 'error', null, 'Resposta inválida da API ao criar item');
                return [
                    'success' => false,
                    'error' => 'Resposta inválida da API ao criar item',
                    'api_response' => $response
                ];
            }

            // Atualizar draft com ID do ML
            $this->updateDraftStatus($draftId, 'published', $mlItemId);

            // Salvar item na tabela local items
            $this->saveLocalItem($payload, $draft);

            return [
                'success' => true,
                'draft_id' => $draftId,
                'ml_item_id' => $mlItemId,
                'permalink' => $permalink,
                'status' => 'published',
                '_meta' => [
                    'data_source' => 'mercadolivre_api',
                    'published_at' => date('Y-m-d H:i:s'),
                ]
            ];
        } catch (\Exception $e) {
            log_error('Erro ao publicar listing no ML', [
                'draft_id' => $draftId,
                'error' => $e->getMessage(),
            ]);

            $this->updateDraftStatus($draftId, 'error', null, $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Atualiza status do draft
     */
    private function updateDraftStatus(int $draftId, string $status, ?string $mlItemId = null, ?string $error = null): void
    {
        $sql = "UPDATE listing_drafts SET status = :status, updated_at = NOW()";
        $params = ['status' => $status, 'id' => $draftId];

        if ($mlItemId) {
            $sql .= ", ml_item_id = :ml_item_id";
            $params['ml_item_id'] = $mlItemId;
        }

        if ($error) {
            $sql .= ", last_error = :error";
            $params['error'] = $error;
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Prepara array de imagens para a API
     */
    private function preparePictures(array $pictures): array
    {
        if (empty($pictures)) {
            return [];
        }

        return array_map(function ($pic) {
            if (is_string($pic)) {
                return ['source' => $pic];
            }
            return $pic;
        }, $pictures);
    }

    /**
     * Salva item criado na tabela local
     */
    private function saveLocalItem(array $mlResponse, array $draft): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO items (account_id, ml_item_id, title, price, status, category_id, permalink, created_at, updated_at)
                VALUES (:account_id, :ml_item_id, :title, :price, :status, :category_id, :permalink, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    price = VALUES(price),
                    status = VALUES(status),
                    updated_at = NOW()
            ");
            $stmt->execute([
                'account_id' => $this->accountId,
                'ml_item_id' => $mlResponse['id'] ?? '',
                'title' => $mlResponse['title'] ?? $draft['title'],
                'price' => $mlResponse['price'] ?? $draft['suggested_price'],
                'status' => $mlResponse['status'] ?? 'active',
                'category_id' => $mlResponse['category_id'] ?? $draft['category_id'],
                'permalink' => $mlResponse['permalink'] ?? '',
            ]);
        } catch (\Exception $e) {
            log_error('Erro ao salvar item local após publicação', [
                'ml_item_id' => $mlResponse['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Valida draft antes de publicar
     */
    public function validateDraft(int $draftId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM listing_drafts WHERE id = :id");
        $stmt->execute(['id' => $draftId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$draft) {
            return ['valid' => false, 'errors' => ['Draft not found']];
        }

        $errors = [];
        $warnings = [];

        // Validar título
        if (empty($draft['title'])) {
            $errors[] = 'Título é obrigatório';
        } elseif (mb_strlen($draft['title']) > 60) {
            $warnings[] = 'Título será truncado para 60 caracteres';
        }

        // Validar categoria
        if (empty($draft['category_id'])) {
            $errors[] = 'Categoria é obrigatória';
        }

        // Validar preço
        if (empty($draft['suggested_price']) || $draft['suggested_price'] <= 0) {
            $errors[] = 'Preço deve ser maior que zero';
        }

        // Validar descrição
        if (empty($draft['description'])) {
            $warnings[] = 'Descrição vazia pode reduzir conversões';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'draft' => $draft,
        ];
    }

    private function ensureDraftsTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS listing_drafts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT UNSIGNED,
                title VARCHAR(255),
                description TEXT,
                bullet_points JSON,
                seo_keywords JSON,
                suggested_price DECIMAL(10,2),
                category_id VARCHAR(50),
                status VARCHAR(20) DEFAULT 'draft',
                ml_item_id VARCHAR(50) NULL,
                last_error TEXT NULL,
                created_at DATETIME,
                updated_at DATETIME NULL,
                INDEX idx_status (status),
                INDEX idx_account (account_id),
                INDEX idx_ml_item (ml_item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Adicionar colunas se não existirem (para tabelas já criadas)
        try {
            $this->db->exec("ALTER TABLE listing_drafts ADD COLUMN ml_item_id VARCHAR(50) NULL AFTER status");
        } catch (\Exception $e) {
            // Coluna já existe
        }
        try {
            $this->db->exec("ALTER TABLE listing_drafts ADD COLUMN last_error TEXT NULL AFTER ml_item_id");
        } catch (\Exception $e) {
            // Coluna já existe
        }
        try {
            $this->db->exec("ALTER TABLE listing_drafts ADD COLUMN updated_at DATETIME NULL AFTER created_at");
        } catch (\Exception $e) {
            // Coluna já existe
        }
        try {
            $this->db->exec("ALTER TABLE listing_drafts ADD COLUMN account_id INT UNSIGNED AFTER id");
        } catch (\Exception $e) {
            // Coluna já existe
        }
    }
}
