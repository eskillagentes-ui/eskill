<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CategoryService;
use PHPUnit\Framework\TestCase;

final class CategoryServiceFailurePayloadTest extends TestCase
{
    public function testDetectsPolicyAgentFailure(): void
    {
        $payload = [
            'code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES',
            'blocked_by' => 'PolicyAgent',
            'status' => 403,
            'success' => false,
            'error' => 'http_error',
        ];
        $this->assertTrue(CategoryService::isFailurePayload($payload));
        $this->assertFalse(CategoryService::isCategoryList($payload));
    }

    public function testAcceptsCategoryList(): void
    {
        $payload = [
            ['id' => 'MLB1747', 'name' => 'Acessórios para Veículos'],
            ['id' => 'MLB5672', 'name' => 'Peças para Motos'],
        ];
        $this->assertFalse(CategoryService::isFailurePayload($payload));
        $this->assertTrue(CategoryService::isCategoryList($payload));
    }

    public function testEmptyListIsNotFailure(): void
    {
        $this->assertFalse(CategoryService::isFailurePayload([]));
        $this->assertFalse(CategoryService::isCategoryList([]));
    }
}
