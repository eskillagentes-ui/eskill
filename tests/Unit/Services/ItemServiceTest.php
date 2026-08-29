<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\ItemService;
use App\Database;
use PDO;

/**
 * Testes para ItemService
 *
 * Verifica: listItems, getItem, updatePrice, updateStock, getItemsStats,
 *           getItemsByStatus, getItemsByCategory, createItem validation
 */
class ItemServiceTest extends TestCase
{
    private ?PDO $db = null;
    private ?string $testItemId = null;
    private bool $hasTable = false;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->db = Database::getInstance();
            $this->db->query("SELECT 1 FROM ml_items LIMIT 1");
            $this->hasTable = true;
        } catch (\Exception $e) {
            $this->hasTable = false;
        }
    }

    protected function tearDown(): void
    {
        if ($this->testItemId && $this->db) {
            $this->db->prepare("DELETE FROM items WHERE ml_item_id = :ml_item_id")
                ->execute(['ml_item_id' => $this->testItemId]);
        }
        parent::tearDown();
    }

    private function requireDb(): void
    {
        if (!$this->hasTable) {
            $this->markTestSkipped('Tabela ml_items não existe no banco de teste');
        }
    }

    private function seedTestItem(): void
    {
        $this->requireDb();
        // `ml_items` é uma VIEW somente-leitura sobre `items` (ver
        // database/migrations/2026_07_11_fix_ml_items_view_missing_columns.sql);
        // o seed precisa inserir na tabela real. `items.id` é AUTO_INCREMENT
        // (não a string MLB-...), então usamos `ml_item_id` para o identificador
        // do anúncio — a própria view expõe `ml_item_id` para leitura.
        $this->testItemId = 'MLB-TEST-' . bin2hex(random_bytes(4));

        // Desabilitar FK checks para seeding de teste
        $this->db->exec("SET FOREIGN_KEY_CHECKS=0");

        $stmt = $this->db->prepare("
            INSERT INTO items (
                ml_item_id, account_id, title, category_id, price, currency_id,
                available_quantity, sold_quantity, status, permalink, data, created_at, updated_at
            )
            VALUES (
                :ml_item_id, :acct, :title, :cat, :price, :currency,
                :qty, :sold_qty, :status, :link, :data, NOW(), NOW()
            )
        ");
        $stmt->execute([
            'ml_item_id' => $this->testItemId,
            'acct' => 999,
            'title' => 'Item teste unitário',
            'cat' => 'MLB1234',
            'price' => 99.90,
            'currency' => 'BRL',
            'qty' => 10,
            'sold_qty' => 0,
            'status' => 'active',
            'link' => 'https://produto.mercadolivre.com.br/test',
            'data' => json_encode(['source' => 'phpunit']),
        ]);

        $this->db->exec("SET FOREIGN_KEY_CHECKS=1");
    }

    // ===========================
    // CLASS STRUCTURE
    // ===========================

    public function test_item_service_class_exists(): void
    {
        $this->assertTrue(class_exists(ItemService::class));
    }

    public function test_item_service_has_required_methods(): void
    {
        $requiredMethods = [
            'listItems', 'getItem', 'updateItemPricing', 'createItem',
            'updateItem', 'pauseItem', 'activateItem', 'closeItem',
            'updatePrice', 'updateStock', 'getItemsByStatus',
            'getItemsByCategory', 'getItemsStats', 'syncItem', 'syncItems',
            'getSellerCategories',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertTrue(
                method_exists(ItemService::class, $method),
                "ItemService deve ter método {$method}()"
            );
        }
    }

    // ===========================
    // CONSTRUCTOR
    // ===========================

    public function test_constructor_accepts_null_account(): void
    {
        $service = new ItemService(null);
        $this->assertInstanceOf(ItemService::class, $service);
    }

    public function test_constructor_accepts_account_id(): void
    {
        $service = new ItemService(999);
        $this->assertInstanceOf(ItemService::class, $service);
    }

    // ===========================
    // listItems
    // ===========================

    public function test_listItems_returns_expected_structure(): void
    {
        $this->seedTestItem();
        $service = new ItemService(999);
        $result = $service->listItems(['limit' => 5, 'page' => 1]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('limit', $result);
    }

    public function test_listItems_respects_limit(): void
    {
        $this->seedTestItem();
        $service = new ItemService(999);
        $result = $service->listItems(['limit' => 2]);

        $this->assertLessThanOrEqual(2, count($result['items']));
    }

    public function test_listItems_enforces_max_limit(): void
    {
        $this->seedTestItem();
        $service = new ItemService(999);
        $result = $service->listItems(['limit' => 999]);

        // max limit é 50 no ItemService
        $this->assertLessThanOrEqual(50, $result['limit']);
    }

    public function test_listItems_with_status_filter(): void
    {
        $this->seedTestItem();
        $service = new ItemService(999);
        $result = $service->listItems(['status' => 'active']);

        $this->assertArrayHasKey('items', $result);
        $this->assertIsArray($result['items']);
        foreach ($result['items'] as $item) {
            $this->assertEquals('active', $item['status'] ?? $item['ml_status'] ?? 'active');
        }
    }

    // ===========================
    // getItemsStats
    // ===========================

    public function test_getItemsStats_returns_array(): void
    {
        $this->requireDb();
        $service = new ItemService(999);
        $result = $service->getItemsStats();

        $this->assertIsArray($result);
    }

    // ===========================
    // SECURITY - No hardcoded values
    // ===========================

    public function test_no_hardcoded_credentials(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/ItemService.php'
        );

        $this->assertStringNotContainsString('hardcoded_token', $source);
        $this->assertStringNotContainsString('test-token', $source);
    }

    public function test_uses_prepared_statements(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/ItemService.php'
        );

        // Deve usar prepare() e não concatenar SQL diretamente
        $this->assertStringContainsString('->prepare(', $source);
        $this->assertStringContainsString('->execute(', $source);
    }

    // ===========================
    // INPUT VALIDATION
    // ===========================

    public function test_listItems_offset_calculation(): void
    {
        $this->seedTestItem();
        $service = new ItemService(999);
        $result = $service->listItems(['offset' => 10, 'limit' => 5]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('page', $result);
    }

    public function test_listItems_negative_page_defaults_to_1(): void
    {
        $this->seedTestItem();
        $service = new ItemService(999);
        $result = $service->listItems(['page' => -5]);

        $this->assertGreaterThanOrEqual(1, $result['page']);
    }

    // ===========================
    // STRICT TYPES
    // ===========================

    public function test_has_strict_types(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/ItemService.php'
        );

        $this->assertStringContainsString(
            'declare(strict_types=1)',
            $source,
            'ItemService deve ter declare(strict_types=1)'
        );
    }

    // ===========================
    // createItem VALIDATION
    // ===========================

    public function test_createItem_validates_required_fields(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/ItemService.php'
        );

        $requiredFields = [
            'title', 'category_id', 'price', 'currency_id',
            'available_quantity', 'buying_mode', 'listing_type_id',
            'condition', 'description',
        ];

        foreach ($requiredFields as $field) {
            $this->assertStringContainsString(
                "'{$field}'",
                $source,
                "ItemService::createItem deve validar campo obrigatório '{$field}'"
            );
        }
    }

    public function test_createItem_rejects_empty_data(): void
    {
        $service = new ItemService(999);
        $result = $service->createItem([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertTrue($result['error'], 'createItem com dados vazios deve retornar error=true');
    }

    public function test_createItem_rejects_partial_data(): void
    {
        $service = new ItemService(999);
        $result = $service->createItem(['title' => 'Bagageiro CG 160']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('obrigatório', $result['message']);
    }

    public function test_createItem_identifies_missing_field_in_error(): void
    {
        $service = new ItemService(999);

        // Com apenas title — deve reclamar do próximo campo faltante (category_id)
        $result = $service->createItem(['title' => 'Test']);
        $this->assertStringContainsString('category_id', $result['message']);

        // Com title + category_id — deve reclamar de price
        $result = $service->createItem(['title' => 'Test', 'category_id' => 'MLB1234']);
        $this->assertStringContainsString('price', $result['message']);
    }

    // ===========================
    // PRIVATE METHOD BEHAVIOR (via reflection)
    // ===========================

    public function test_formatItemForList_enriches_item(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'formatItemForList');
        $method->setAccessible(true);

        $input = [
            'id' => 'MLB12345',
            'title' => 'Bagageiro CG 160 Titan',
            'price' => 89.90,
            'thumbnail' => 'https://example.com/img.jpg',
            'permalink' => 'https://produto.mercadolivre.com.br/MLB-12345',
            'sold_quantity' => 42,
        ];

        $result = $method->invoke($service, $input);

        // Deve enriquecer com ml_id
        $this->assertEquals('MLB12345', $result['ml_id']);
        // Deve preservar thumbnail
        $this->assertEquals('https://example.com/img.jpg', $result['thumbnail']);
        // Deve preservar permalink
        $this->assertEquals('https://produto.mercadolivre.com.br/MLB-12345', $result['permalink']);
        // Deve preservar sold_quantity
        $this->assertEquals(42, $result['sold_quantity']);
    }

    public function test_formatItemForList_extracts_thumbnail_from_pictures(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'formatItemForList');
        $method->setAccessible(true);

        $input = [
            'id' => 'MLB99999',
            'title' => 'Retrovisor Bros 160',
            'pictures' => [
                ['url' => 'https://example.com/pic1.jpg'],
                ['url' => 'https://example.com/pic2.jpg'],
            ],
        ];

        $result = $method->invoke($service, $input);

        $this->assertEquals('https://example.com/pic1.jpg', $result['thumbnail']);
    }

    public function test_formatItemForList_reads_metrics_visits(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'formatItemForList');
        $method->setAccessible(true);

        $input = [
            'id' => 'MLB55555',
            'title' => 'Baú 45L',
            'metrics' => [
                'visits' => 150,
                'sold_quantity' => 7,
            ],
        ];

        $result = $method->invoke($service, $input);

        $this->assertEquals(150, $result['visits']);
        $this->assertEquals(7, $result['sold_quantity']);
    }

    public function test_resolveLocalItemsOrder_maps_correctly(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'resolveLocalItemsOrder');
        $method->setAccessible(true);

        $this->assertEquals('ORDER BY price ASC', $method->invoke($service, 'price_asc'));
        $this->assertEquals('ORDER BY price DESC', $method->invoke($service, 'price_desc'));
        $this->assertEquals('ORDER BY created_at ASC', $method->invoke($service, 'date_created_asc'));
        $this->assertEquals('ORDER BY created_at DESC', $method->invoke($service, 'date_created_desc'));
        $this->assertEquals('ORDER BY updated_at DESC', $method->invoke($service, null));
        $this->assertEquals('ORDER BY updated_at DESC', $method->invoke($service, 'unknown_order'));
    }

    public function test_formatMlApiErrorMessage_with_full_context(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'formatMlApiErrorMessage');
        $method->setAccessible(true);

        $error = [
            'message' => 'Token expirado',
            'status' => 401,
            'endpoint' => '/users/123/items/search',
        ];

        $result = $method->invoke($service, $error, 'Falha ao buscar');

        $this->assertStringContainsString('Falha ao buscar', $result);
        $this->assertStringContainsString('Token expirado', $result);
        $this->assertStringContainsString('HTTP 401', $result);
        $this->assertStringContainsString('/users/123/items/search', $result);
    }

    public function test_formatMlApiErrorMessage_with_minimal_context(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'formatMlApiErrorMessage');
        $method->setAccessible(true);

        $result = $method->invoke($service, [], 'Erro genérico');

        $this->assertEquals('Erro genérico', $result);
    }

    public function test_extractSku_from_seller_custom_field(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'extractSku');
        $method->setAccessible(true);

        $item = ['seller_custom_field' => 'AWA-BAG-001'];

        $this->assertEquals('AWA-BAG-001', $method->invoke($service, $item));
    }

    public function test_extractSku_from_attributes(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'extractSku');
        $method->setAccessible(true);

        $item = [
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'AWA'],
                ['id' => 'SELLER_SKU', 'value_name' => 'AWA-RET-002'],
                ['id' => 'COLOR', 'value_name' => 'Preto'],
            ],
        ];

        $this->assertEquals('AWA-RET-002', $method->invoke($service, $item));
    }

    public function test_extractSku_returns_null_when_no_sku(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'extractSku');
        $method->setAccessible(true);

        $item = [
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'AWA'],
            ],
        ];

        $this->assertNull($method->invoke($service, $item));
    }

    public function test_filterItemsByCustomCriteria_low_stock(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'filterItemsByCustomCriteria');
        $method->setAccessible(true);

        $items = [
            ['id' => '1', 'available_quantity' => 2],  // low stock
            ['id' => '2', 'available_quantity' => 10], // not low
            ['id' => '3', 'available_quantity' => 0],  // low stock
            ['id' => '4', 'available_quantity' => 4],  // low stock
        ];

        $result = $method->invoke($service, $items, ['low_stock' => true]);

        $this->assertCount(3, $result);

        $ids = array_column($result, 'id');
        $this->assertContains('1', $ids);
        $this->assertContains('3', $ids);
        $this->assertContains('4', $ids);
        $this->assertNotContains('2', $ids);
    }

    public function test_filterItemsByCustomCriteria_high_sales(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'filterItemsByCustomCriteria');
        $method->setAccessible(true);

        $items = [
            ['id' => '1', 'sold_quantity' => 50],
            ['id' => '2', 'sold_quantity' => 0],
            ['id' => '3'],  // sem campo sold
        ];

        $result = $method->invoke($service, $items, ['high_sales' => true]);

        $this->assertCount(1, $result);
        $this->assertEquals('1', $result[0]['id']);
    }

    public function test_filterItemsByCustomCriteria_no_filters_returns_all(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'filterItemsByCustomCriteria');
        $method->setAccessible(true);

        $items = [
            ['id' => '1', 'available_quantity' => 2],
            ['id' => '2', 'available_quantity' => 10],
        ];

        $result = $method->invoke($service, $items, []);

        $this->assertCount(2, $result);
    }

    public function test_unwrapMlResponse_extracts_body(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'unwrapMlResponse');
        $method->setAccessible(true);

        $response = [
            'body' => [
                'id' => 'MLB12345',
                'title' => 'Bagageiro',
                'price' => 89.90,
            ],
        ];

        $result = $method->invoke($service, $response);

        $this->assertEquals('MLB12345', $result['id']);
        $this->assertEquals('Bagageiro', $result['title']);
    }

    public function test_unwrapMlResponse_returns_raw_on_error(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'unwrapMlResponse');
        $method->setAccessible(true);

        $response = [
            'body' => [
                'error' => 'not_found',
                'message' => 'Item não encontrado',
            ],
        ];

        $result = $method->invoke($service, $response);

        // Quando body tem error, deve retornar o response inteiro
        $this->assertArrayHasKey('body', $result);
    }

    public function test_unwrapMlResponse_returns_raw_when_no_body(): void
    {
        $service = new ItemService(null);

        $method = new \ReflectionMethod(ItemService::class, 'unwrapMlResponse');
        $method->setAccessible(true);

        $response = [
            'id' => 'MLB12345',
            'title' => 'Bagageiro',
        ];

        $result = $method->invoke($service, $response);

        $this->assertEquals('MLB12345', $result['id']);
    }

    // ===========================
    // MONOLOG (NO echo/var_dump/error_log)
    // ===========================

    public function test_uses_structured_logging(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/ItemService.php'
        );

        // Deve usar log_error/log_warning ao invés de error_log/echo/var_dump
        $this->assertStringNotContainsString('error_log(', $source, 'Deve usar log_error/log_warning ao invés de error_log()');
        $this->assertStringNotContainsString('var_dump(', $source);
        $this->assertStringNotContainsString('print_r(', $source);

        // Deve ter chamadas de logging estruturado
        $this->assertStringContainsString('log_warning(', $source);
        $this->assertStringContainsString('log_error(', $source);
    }

    // ===========================
    // DATA INTEGRITY
    // ===========================

    public function test_updateItem_only_allows_safe_fields(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/ItemService.php'
        );

        // Deve ter lista de campos permitidos (whitelist)
        $this->assertStringContainsString('allowedFields', $source);

        // Os campos permitidos devem ser os esperados pela API ML
        $allowedFields = [
            'title', 'price', 'available_quantity', 'description',
            'pictures', 'attributes', 'variations', 'shipping',
            'seller_custom_field',
        ];

        foreach ($allowedFields as $field) {
            $this->assertStringContainsString(
                "'{$field}'",
                $source,
                "updateItem deve permitir campo '{$field}'"
            );
        }
    }

    public function test_deleteItem_closes_before_deleting(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/ItemService.php'
        );

        // deleteItem deve verificar se o item está fechado antes de deletar
        $this->assertStringContainsString("!== 'closed'", $source);
        $this->assertStringContainsString('closeItem', $source);
    }

    public function test_syncItems_uses_batch_multiget(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/ItemService.php'
        );

        // syncItems deve usar multiget para eficiência
        $this->assertStringContainsString('/items?ids=', $source);
        $this->assertStringContainsString('array_chunk', $source);
    }

    public function test_getCatalogDetails_method_exists(): void
    {
        $this->assertTrue(
            method_exists(ItemService::class, 'getCatalogDetails'),
            'ItemService deve ter método getCatalogDetails()'
        );
    }

    public function test_updateItemCost_method_exists(): void
    {
        $this->assertTrue(
            method_exists(ItemService::class, 'updateItemCost'),
            'ItemService deve ter método updateItemCost()'
        );
    }

    public function test_deleteItem_method_exists(): void
    {
        $this->assertTrue(
            method_exists(ItemService::class, 'deleteItem'),
            'ItemService deve ter método deleteItem()'
        );
    }

    public function test_listItems_uses_multiget_instead_of_per_item_details(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/ItemService.php'
        );

        $this->assertStringContainsString('getMultiItemDetails', $source);
        $this->assertStringContainsString('hydrateItemsForList', $source);
        $this->assertStringContainsString('LIST_MULTI_GET_ATTRIBUTES', $source);
        $this->assertStringContainsString('shouldSkipVisits', $source);
        $this->assertStringNotContainsString("buscar detalhes de cada item", $source);
    }

    public function test_getMultiItemDetails_chunks_ids_by_20(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/MercadoLivreClient.php'
        );

        $this->assertStringContainsString('array_chunk($itemIds, 20)', $source);
        $this->assertMatchesRegularExpression("/get\\('\\/items'/", $source);
    }

    public function test_shouldSkipVisits_honors_flag(): void
    {
        $service = new ItemService(null);
        $method = new \ReflectionMethod(ItemService::class, 'shouldSkipVisits');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, []));
        $this->assertTrue($method->invoke($service, ['skip_visits' => true]));
        $this->assertTrue($method->invoke($service, ['skip_visits' => 'true']));
        $this->assertTrue($method->invoke($service, ['skip_visits' => '1']));
        $this->assertFalse($method->invoke($service, ['skip_visits' => false]));
        $this->assertFalse($method->invoke($service, ['skip_visits' => '0']));
        $this->assertFalse($method->invoke($service, ['skip_visits' => '']));
    }

    public function test_hydrateItemsForList_requests_list_attributes_without_description(): void
    {
        $service = new ItemService(null);

        $ids = [];
        for ($i = 1; $i <= 25; $i++) {
            $ids[] = 'MLB' . $i;
        }

        $mock = $this->getMockBuilder(\App\Services\MercadoLivreClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMultiItemDetails'])
            ->getMock();

        $mock->expects($this->once())
            ->method('getMultiItemDetails')
            ->with(
                $ids,
                $this->callback(static function (array $attributes): bool {
                    if (in_array('description', $attributes, true)) {
                        return false;
                    }
                    foreach (['id', 'title', 'price', 'status', 'available_quantity', 'sold_quantity', 'permalink', 'thumbnail', 'listing_type_id', 'category_id'] as $field) {
                        if (!in_array($field, $attributes, true)) {
                            return false;
                        }
                    }
                    return true;
                })
            )
            ->willReturn([
                'MLB1' => [
                    'id' => 'MLB1',
                    'title' => 'Anúncio 1',
                    'price' => 10.5,
                    'status' => 'active',
                    'available_quantity' => 3,
                    'sold_quantity' => 1,
                    'permalink' => 'https://produto.mercadolivre.com.br/MLB-1',
                    'thumbnail' => 'https://http2.mlstatic.com/x.jpg',
                    'listing_type_id' => 'gold_special',
                    'category_id' => 'MLB1234',
                ],
            ]);

        $ref = new \ReflectionClass($service);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($service, $mock);

        $method = $ref->getMethod('hydrateItemsForList');
        $method->setAccessible(true);
        $items = $method->invoke($service, $ids, ['skip_description' => true, 'skip_visits' => true]);

        $this->assertCount(1, $items);
        $this->assertSame('MLB1', $items[0]['id']);
        $this->assertArrayNotHasKey('description', $items[0]);
    }

    public function test_hydrateItemsForList_falls_back_to_local_items_on_403(): void
    {
        $service = new ItemService(null);

        $mock = $this->getMockBuilder(\App\Services\MercadoLivreClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMultiItemDetails', 'getMultiItemVisits'])
            ->getMock();

        $mock->expects($this->once())
            ->method('getMultiItemDetails')
            ->willReturn([
                'error' => 'access_denied',
                'status' => 403,
                'message' => 'At least one policy returned UNAUTHORIZED.',
            ]);
        $mock->expects($this->never())->method('getMultiItemVisits');

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(static function ($params): bool {
                return is_array($params)
                    && ($params[0] ?? null) === 'MLB403FALLBACK'
                    && end($params) === 7;
            }))
            ->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            [
                'ml_item_id' => 'MLB403FALLBACK',
                'title' => '',
                'price' => null,
                'status' => '',
                'available_quantity' => null,
                'sold_quantity' => null,
                'thumbnail' => '',
                'permalink' => '',
                'data' => json_encode([
                    'title' => 'From JSON',
                    'price' => 19.9,
                    'status' => 'active',
                    'available_quantity' => 8,
                    'sold_quantity' => 15,
                    'thumbnail' => 'https://http2.mlstatic.com/local.jpg',
                    'permalink' => 'https://produto.mercadolivre.com.br/MLB-403-FALLBACK',
                ], JSON_UNESCAPED_SLASHES),
            ],
        ]);

        $db = $this->createMock(PDO::class);
        $db->expects($this->once())
            ->method('prepare')
            ->with($this->callback(static function ($sql): bool {
                return is_string($sql)
                    && str_contains($sql, 'FROM items')
                    && str_contains($sql, 'account_id');
            }))
            ->willReturn($stmt);

        $ref = new \ReflectionClass($service);
        $clientProp = $ref->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($service, $mock);

        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $db);

        $accountProp = $ref->getProperty('accountId');
        $accountProp->setAccessible(true);
        $accountProp->setValue($service, 7);

        $method = $ref->getMethod('hydrateItemsForList');
        $method->setAccessible(true);
        $items = $method->invoke($service, ['MLB403FALLBACK'], ['skip_visits' => true]);

        $this->assertCount(1, $items);
        $this->assertSame('MLB403FALLBACK', $items[0]['id']);
        $this->assertSame('From JSON', $items[0]['title']);
        $this->assertSame(19.9, $items[0]['price']);
        $this->assertSame('active', $items[0]['status']);
        $this->assertSame(8, $items[0]['available_quantity']);
        $this->assertSame(15, $items[0]['sold_quantity']);
        $this->assertSame('https://http2.mlstatic.com/local.jpg', $items[0]['thumbnail']);
        $this->assertSame('https://produto.mercadolivre.com.br/MLB-403-FALLBACK', $items[0]['permalink']);
        $this->assertArrayNotHasKey('description', $items[0]);
    }

    public function test_getSellerCategories_does_not_use_public_search(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/ItemService.php'
        );
        $start = strpos($source, 'function getSellerCategories');
        $end = strpos($source, 'function getLocalCategoriesFallback');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $method = substr($source, $start, $end - $start);
        $this->assertStringNotContainsString('/sites/', $method);
        $this->assertStringContainsString('getLocalCategoriesFallback', $method);
    }

    public function test_getSellerCategories_returns_local_catalog_for_account(): void
    {
        $this->seedTestItem();
        $service = new ItemService(999);
        $result = $service->getSellerCategories();

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame('local_catalog', $result['source'] ?? null);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertIsArray($result['categories']);

        $ids = array_map(static fn(array $row): string => (string)($row['id'] ?? ''), $result['categories']);
        $this->assertContains('MLB1234', $ids);
    }

    public function test_getSellerCategories_isolates_accounts(): void
    {
        $this->seedTestItem();
        $otherId = 'MLB-TEST-' . bin2hex(random_bytes(4));
        $this->db->exec('SET FOREIGN_KEY_CHECKS=0');
        $stmt = $this->db->prepare(
            'INSERT INTO items (
                ml_item_id, account_id, title, category_id, price, currency_id,
                available_quantity, sold_quantity, status, permalink, data, created_at, updated_at
            ) VALUES (
                :ml_item_id, :acct, :title, :cat, :price, :currency,
                :qty, :sold_qty, :status, :link, :data, NOW(), NOW()
            )'
        );
        $stmt->execute([
            'ml_item_id' => $otherId,
            'acct' => 998,
            'title' => 'Item outra conta',
            'cat' => 'MLB9999',
            'price' => 10.00,
            'currency' => 'BRL',
            'qty' => 1,
            'sold_qty' => 0,
            'status' => 'active',
            'link' => 'https://produto.mercadolivre.com.br/test-other',
            'data' => json_encode(['source' => 'phpunit']),
        ]);
        $this->db->exec('SET FOREIGN_KEY_CHECKS=1');

        try {
            $result = (new ItemService(999))->getSellerCategories();
            $ids = array_map(static fn(array $row): string => (string)($row['id'] ?? ''), $result['categories'] ?? []);
            $this->assertContains('MLB1234', $ids);
            $this->assertNotContains('MLB9999', $ids);

            $other = (new ItemService(998))->getSellerCategories();
            $otherIds = array_map(static fn(array $row): string => (string)($row['id'] ?? ''), $other['categories'] ?? []);
            $this->assertContains('MLB9999', $otherIds);
            $this->assertNotContains('MLB1234', $otherIds);
        } finally {
            $this->db->prepare('DELETE FROM items WHERE ml_item_id = :ml_item_id')
                ->execute(['ml_item_id' => $otherId]);
        }
    }

    public function test_getSellerCategories_without_account_does_not_leak_catalog(): void
    {
        $this->seedTestItem();
        $result = (new ItemService(null))->getSellerCategories();

        $this->assertFalse($result['success'] ?? true);
        $this->assertSame('missing_seller_id', $result['error'] ?? null);
        $this->assertSame([], $result['categories'] ?? null);
    }

    public function test_listItems_category_filter_uses_local_catalog(): void
    {
        $this->seedTestItem();
        $otherId = 'MLB-TEST-' . bin2hex(random_bytes(4));
        $this->db->exec('SET FOREIGN_KEY_CHECKS=0');
        $stmt = $this->db->prepare(
            'INSERT INTO items (
                ml_item_id, account_id, title, category_id, price, currency_id,
                available_quantity, sold_quantity, status, permalink, data, created_at, updated_at
            ) VALUES (
                :ml_item_id, :acct, :title, :cat, :price, :currency,
                :qty, :sold_qty, :status, :link, :data, NOW(), NOW()
            )'
        );
        $stmt->execute([
            'ml_item_id' => $otherId,
            'acct' => 999,
            'title' => 'Item outra categoria',
            'cat' => 'MLB9999',
            'price' => 10.00,
            'currency' => 'BRL',
            'qty' => 1,
            'sold_qty' => 0,
            'status' => 'active',
            'link' => 'https://produto.mercadolivre.com.br/test-cat',
            'data' => json_encode(['source' => 'phpunit']),
        ]);
        $this->db->exec('SET FOREIGN_KEY_CHECKS=1');

        try {
            $result = (new ItemService(999))->listItems([
                'category' => 'MLB1234',
                'limit' => 12,
                'page' => 1,
                'skip_visits' => true,
            ]);

            $this->assertTrue($result['success'] ?? false);
            $this->assertSame('local_catalog', $result['source'] ?? null);
            $this->assertArrayNotHasKey('warning', $result);
            $this->assertGreaterThanOrEqual(1, $result['total'] ?? 0);

            $ids = [];
            foreach ($result['items'] as $item) {
                $this->assertSame('MLB1234', $item['category_id'] ?? null);
                $ids[] = (string)($item['id'] ?? $item['ml_item_id'] ?? '');
            }
            $this->assertContains($this->testItemId, $ids);
            $this->assertNotContains($otherId, $ids);
        } finally {
            $this->db->prepare('DELETE FROM items WHERE ml_item_id = :ml_item_id')
                ->execute(['ml_item_id' => $otherId]);
        }
    }

    public function test_listItems_category_filter_without_account_does_not_leak(): void
    {
        $this->seedTestItem();
        $result = (new ItemService(null))->listItems([
            'category' => 'MLB1234',
            'limit' => 12,
            'skip_visits' => true,
        ]);

        $this->assertFalse($result['success'] ?? true);
        $this->assertSame('missing_seller_id', $result['error'] ?? null);
        $this->assertSame([], $result['items'] ?? null);
    }
}
