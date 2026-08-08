<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HiddenSeo;

use App\Services\HiddenSeo\ItemPerformanceService;
use App\Services\TechSheetHiddenSuggestionService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\HiddenSeo\ItemPerformanceService
 * @covers \App\Services\TechSheetHiddenSuggestionService::resolveValueIdFromCategoryAttr
 */
class ItemPerformanceServiceTest extends TestCase
{
    public function testParsePerformanceDetectsPendingTechSpecsAndGtin(): void
    {
        $ref = new \ReflectionClass(ItemPerformanceService::class);
        /** @var ItemPerformanceService $svc */
        $svc = $ref->newInstanceWithoutConstructor();

        $raw = [
            'score' => 69,
            'level' => 'Good',
            'level_wording' => 'Profissional',
            'buckets' => [[
                'key' => 'CHARACTERISTICS',
                'variables' => [
                    [
                        'key' => 'TECHNICAL_SPECIFICATIONS_MAIN',
                        'status' => 'PENDING',
                        'title' => 'Características',
                        'rules' => [[
                            'key' => 'TS_MAIN_QUANTITY',
                            'status' => 'PENDING',
                            'mode' => 'OPPORTUNITY',
                            'wordings' => ['title' => 'Completá las características principales.'],
                        ]],
                    ],
                    [
                        'key' => 'GTIN',
                        'status' => 'PENDING',
                        'title' => 'GTIN',
                        'rules' => [[
                            'key' => 'HAS_GTIN',
                            'status' => 'PENDING',
                            'mode' => 'OPPORTUNITY',
                            'wordings' => ['title' => 'Completar código universal'],
                        ]],
                    ],
                ],
            ]],
        ];

        $parsed = $svc->parsePerformancePayload($raw);
        $this->assertSame(69.0, $parsed['score']);
        $this->assertSame('Profissional', $parsed['level_wording']);
        $this->assertTrue($parsed['pending_tech_specs']);
        $this->assertTrue($parsed['pending_gtin']);
        $this->assertNotEmpty($parsed['pending_rules']);
        $this->assertSame('TS_MAIN_QUANTITY', $parsed['pending_rules'][0]['key']);
    }

    public function testParseCompletedHasNoPendingFlags(): void
    {
        $ref = new \ReflectionClass(ItemPerformanceService::class);
        /** @var ItemPerformanceService $svc */
        $svc = $ref->newInstanceWithoutConstructor();
        $parsed = $svc->parsePerformancePayload([
            'score' => 100,
            'level' => 'Good',
            'buckets' => [[
                'variables' => [[
                    'key' => 'GTIN',
                    'status' => 'COMPLETED',
                    'rules' => [['key' => 'HAS_GTIN', 'status' => 'COMPLETED']],
                ]],
            ]],
        ]);
        $this->assertFalse($parsed['pending_tech_specs']);
        $this->assertFalse($parsed['pending_gtin']);
        $this->assertSame([], $parsed['pending_rules']);
    }

    public function testResolveBooleanValueId(): void
    {
        $ref = new \ReflectionClass(TechSheetHiddenSuggestionService::class);
        /** @var TechSheetHiddenSuggestionService $svc */
        $svc = $ref->newInstanceWithoutConstructor();
        $id = $svc->resolveValueIdFromCategoryAttr([
            'id' => 'HANDLE_RISER',
            'value_type' => 'boolean',
            'values' => [
                ['id' => '242085', 'name' => 'Não'],
                ['id' => '242084', 'name' => 'Sim'],
            ],
        ], 'Sim');
        $this->assertSame('242084', $id);
    }
}
