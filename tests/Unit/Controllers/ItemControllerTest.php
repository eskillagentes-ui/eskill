<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use App\Controllers\ItemController;
use App\Controllers\BaseController;

/**
 * Testes estruturais para ItemController
 *
 * Verifica: existencia, heranca, assinaturas de metodos, mapeamento de campos ML,
 * cobertura de filtros e logica de roteamento de HTTP status codes.
 *
 * @covers \App\Controllers\ItemController
 */
class ItemControllerTest extends TestCase
{
    // ===========================
    // CLASS STRUCTURE
    // ===========================

    public function test_class_exists(): void
    {
        $this->assertTrue(
            class_exists(ItemController::class),
            'ItemController deve existir'
        );
    }

    public function test_extends_base_controller(): void
    {
        $this->assertTrue(
            is_subclass_of(ItemController::class, BaseController::class),
            'ItemController deve estender BaseController'
        );
    }

    // ===========================
    // REQUIRED METHODS — CRUD
    // ===========================

    /**
     * @dataProvider crudMethodsProvider
     */
    public function test_has_crud_methods(string $method): void
    {
        $this->assertTrue(
            method_exists(ItemController::class, $method),
            "ItemController deve ter o metodo CRUD: {$method}"
        );
    }

    public static function crudMethodsProvider(): array
    {
        return [
            'index'  => ['index'],
            'show'   => ['show'],
            'create' => ['create'],
            'update' => ['update'],
            'delete' => ['delete'],
        ];
    }

    // ===========================
    // REQUIRED METHODS — ACTIONS
    // ===========================

    /**
     * @dataProvider actionMethodsProvider
     */
    public function test_has_action_methods(string $method): void
    {
        $this->assertTrue(
            method_exists(ItemController::class, $method),
            "ItemController deve ter o metodo de acao: {$method}"
        );
    }

    public static function actionMethodsProvider(): array
    {
        return [
            'pause'             => ['pause'],
            'activate'          => ['activate'],
            'close'             => ['close'],
            'updatePrice'       => ['updatePrice'],
            'updateStock'       => ['updateStock'],
            'updateDescription' => ['updateDescription'],
        ];
    }

    // ===========================
    // REQUIRED METHODS — LISTING
    // ===========================

    /**
     * @dataProvider listingMethodsProvider
     */
    public function test_has_listing_methods(string $method): void
    {
        $this->assertTrue(
            method_exists(ItemController::class, $method),
            "ItemController deve ter o metodo de listagem: {$method}"
        );
    }

    public static function listingMethodsProvider(): array
    {
        return [
            'byStatus'   => ['byStatus'],
            'byCategory' => ['byCategory'],
            'stats'      => ['stats'],
            'categories' => ['categories'],
            'sync'       => ['sync'],
        ];
    }

    // ===========================
    // METHOD SIGNATURES
    // ===========================

    public function test_show_accepts_string_id(): void
    {
        $ref = new \ReflectionMethod(ItemController::class, 'show');
        $params = $ref->getParameters();
        $this->assertNotEmpty($params, 'show() deve aceitar pelo menos um parametro');
        $this->assertEquals('id', $params[0]->getName());
    }

    public function test_update_accepts_string_id(): void
    {
        $ref = new \ReflectionMethod(ItemController::class, 'update');
        $params = $ref->getParameters();
        $this->assertNotEmpty($params, 'update() deve aceitar pelo menos um parametro');
        $this->assertEquals('id', $params[0]->getName());
    }

    /**
     * @dataProvider allMethodsProvider
     */
    public function test_all_methods_return_void(string $method): void
    {
        $ref = new \ReflectionMethod(ItemController::class, $method);
        $returnType = $ref->getReturnType();
        if ($returnType !== null) {
            $this->assertEquals('void', $returnType->getName(),
                "{$method}() deve retornar void (ou omitir tipo de retorno)");
        } else {
            // Sem tipo de retorno declarado — aceitavel
            $this->addToAssertionCount(1);
        }
    }

    public static function allMethodsProvider(): array
    {
        return [
            ['index'], ['show'], ['create'], ['update'], ['delete'],
            ['pause'], ['activate'], ['close'],
            ['updatePrice'], ['updateStock'], ['updateDescription'],
            ['byStatus'], ['byCategory'], ['stats'], ['categories'], ['sync'],
        ];
    }

    // ===========================
    // ML FIELD MAPPING
    // ===========================

    public function test_controller_recognizes_ml_fields(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $mlFields = ['title', 'price', 'available_quantity', 'description',
                     'pictures', 'attributes', 'variations', 'shipping'];
        foreach ($mlFields as $field) {
            $this->assertStringContainsString($field, $source,
                "Controller deve referenciar campo ML: {$field}");
        }
    }

    public function test_sku_maps_to_seller_custom_field(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertStringContainsString('seller_custom_field', $source,
            'Controller deve mapear sku para seller_custom_field');
        $this->assertStringContainsString('sku', $source,
            'Controller deve aceitar campo sku');
    }

    public function test_handles_local_pricing_fields(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $localFields = ['cost_price', 'tax_rate', 'pricing_strategy'];
        foreach ($localFields as $field) {
            $this->assertStringContainsString($field, $source,
                "Controller deve aceitar campo local: {$field}");
        }
    }

    public function test_handles_local_cost_fields(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertStringContainsString('cost_price', $source);
        $this->assertStringContainsString('tax_rate', $source);
    }

    // ===========================
    // HTTP STATUS CODE MAPPING
    // ===========================

    public function test_maps_error_codes_to_http_status(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertStringContainsString('409', $source,
            'Controller deve mapear missing_seller_id para 409 Conflict');
        $this->assertStringContainsString('422', $source,
            'Controller deve mapear local_cache_required para 422 Unprocessable');
        $this->assertStringContainsString('502', $source,
            'Controller deve mapear ml_api_error para 502 Bad Gateway');
    }

    public function test_create_returns_201(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertStringContainsString('201', $source,
            'create() deve retornar HTTP 201 Created');
    }

    public function test_delete_returns_204(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertStringContainsString('204', $source,
            'delete() deve retornar HTTP 204 No Content');
    }

    // ===========================
    // FILTER SUPPORT
    // ===========================

    public function test_supports_all_filters(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $filters = ['status', 'category', 'search', 'order',
                    'allow_local_cache', 'low_stock', 'high_sales',
                    'limit', 'page', 'offset'];
        foreach ($filters as $filter) {
            $this->assertStringContainsString($filter, $source,
                "Controller deve suportar filtro: {$filter}");
        }
    }

    public function test_search_has_q_alias(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertMatchesRegularExpression('/[\'"]q[\'"]/', $source,
            'Controller deve aceitar parametro q como alias para search');
    }

    // ===========================
    // INPUT VALIDATION
    // ===========================

    public function test_price_update_requires_price(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertMatchesRegularExpression('/price.*required|!.*price|empty.*price/i', $source,
            'updatePrice deve validar que price foi informado');
    }

    public function test_stock_update_requires_quantity(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertMatchesRegularExpression('/quantity|available_quantity/i', $source,
            'updateStock deve validar quantity');
    }

    public function test_description_update_requires_plain_text(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertStringContainsString('plain_text', $source,
            'updateDescription deve aceitar plain_text');
    }

    public function test_sync_requires_account_id(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertStringContainsString('SessionHelper::getActiveAccountId', $source,
            'items devem usar a conta ML ativa da sessao, nao um account_id solto no request');
        $this->assertStringContainsString('missing_account', $source,
            'sync deve recusar quando nao ha conta ativa');
    }

    // ===========================
    // SECURITY
    // ===========================

    public function test_no_raw_sql_in_controller(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertDoesNotMatchRegularExpression(
            '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|ALTER)\b.*\b(FROM|INTO|SET|TABLE)\b/i',
            $source,
            'Controller NAO deve conter queries SQL diretamente'
        );
    }

    public function test_uses_json_content_type(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertStringContainsString('json', $source,
            'Controller deve usar resposta JSON');
    }

    public function test_delegates_to_item_service(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertStringContainsString('ItemService', $source,
            'Controller deve delegar logica para ItemService');
        $count = substr_count($source, 'itemService');
        $this->assertGreaterThan(5, $count,
            'Controller deve usar itemService extensivamente (> 5 vezes)');
    }

    // ===========================
    // STRICT TYPES
    // ===========================

    public function test_has_strict_types(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/ItemController.php');
        $this->assertStringContainsString('declare(strict_types=1)', $source,
            'ItemController deve ter declare(strict_types=1)');
    }
}
