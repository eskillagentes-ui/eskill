<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MercadoLivre;

use App\Services\MercadoLivre\ListingIrregularityScanService;
use App\Services\MercadoLivre\ListingSearchVisibilityService;
use App\Services\MercadoLivreClient;
use Tests\TestCase;

/**
 * @covers \App\Services\MercadoLivre\ListingSearchVisibilityService
 * @covers \App\Services\MercadoLivre\ListingIrregularityScanService
 */
final class ListingSearchVisibilityServiceTest extends TestCase
{
    public function testPrioritizeSeoActionsPutsWarningsAndSearchRulesFirst(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $service = new ListingSearchVisibilityService($client);

        $actions = [
            [
                'key' => 'HAS_FREE_SHIPPING',
                'mode' => 'OPPORTUNITY',
                'affects_search' => true,
            ],
            [
                'key' => 'BEST_FINANCING',
                'mode' => 'OPPORTUNITY',
                'affects_search' => false,
            ],
            [
                'key' => 'TS_MAIN_QUALITY_INCOMPLETE_REQUIRED',
                'mode' => 'WARNING',
                'affects_search' => true,
            ],
        ];

        $sorted = $service->prioritizeSeoActions($actions);

        $this->assertSame('TS_MAIN_QUALITY_INCOMPLETE_REQUIRED', $sorted[0]['key']);
        $this->assertSame('HAS_FREE_SHIPPING', $sorted[1]['key']);
        $this->assertSame('BEST_FINANCING', $sorted[2]['key']);
    }

    public function testExtractPendingActionsFromPerformancePayload(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $service = new ListingSearchVisibilityService($client);

        $payload = [
            'score' => 69,
            'buckets' => [
                [
                    'key' => 'CHARACTERISTICS',
                    'variables' => [
                        [
                            'key' => 'TITLE',
                            'status' => 'PENDING',
                            'title' => 'Melhorar título',
                            'rules' => [
                                [
                                    'key' => 'TITLE_LENGTH_MIN',
                                    'status' => 'PENDING',
                                    'mode' => 'OPPORTUNITY',
                                    'progress' => 0.5,
                                    'wordings' => [
                                        'title' => 'Some mais detalhes no título',
                                        'label' => 'Melhorar título',
                                        'link' => 'https://example.test',
                                    ],
                                ],
                                [
                                    'key' => 'DONE_RULE',
                                    'status' => 'COMPLETED',
                                    'mode' => 'OPPORTUNITY',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $actions = $service->extractPendingActions($payload);

        $this->assertCount(1, $actions);
        $this->assertSame('TITLE_LENGTH_MIN', $actions[0]['key']);
        $this->assertTrue($actions[0]['affects_search']);
        $this->assertSame('Some mais detalhes no título', $actions[0]['title']);
    }

    public function testClassifySearchActivationBlockedByModeration(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $service = new ListingSearchVisibilityService($client);

        $status = $service->classifySearchActivation(
            90,
            'Profissional',
            [],
            ['active' => true, 'severity' => 'block']
        );

        $this->assertSame('blocked', $status);
    }

    public function testClassifySearchActivationCriticalOnWarning(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $service = new ListingSearchVisibilityService($client);

        $status = $service->classifySearchActivation(
            75,
            'Satisfatória',
            [['mode' => 'WARNING', 'key' => 'TS_MAIN_QUALITY_INCOMPLETE_REQUIRED']],
            ['active' => false, 'severity' => 'none']
        );

        $this->assertSame('critical', $status);
    }

    public function testNormalizeModerationParsesReasonAndRemedy(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $service = new ListingSearchVisibilityService($client);

        $normalized = $service->normalizeModeration([
            [
                'name' => 'POOR_QUALITY_THUMBNAIL',
                'date_created' => '2026-07-01T10:00:00.000-0300',
                'wordings' => [
                    ['type' => 'REASON', 'value' => 'Foto fora do padrão'],
                    ['type' => 'REMEDY', 'value' => 'Troque a capa por fundo branco'],
                ],
                'evidences' => [
                    ['section_name' => 'pictures', 'text_matched' => 'img-1'],
                ],
            ],
        ]);

        $this->assertTrue($normalized['active']);
        $this->assertSame('exposure_loss', $normalized['severity']);
        $this->assertSame('POOR_QUALITY_THUMBNAIL', $normalized['name']);
        $this->assertSame('Foto fora do padrão', $normalized['reason']);
        $this->assertSame('Troque a capa por fundo branco', $normalized['remedy']);
        $this->assertCount(1, $normalized['evidences']);
    }

    public function testAnalyzeListingCombinesPerformanceAndModeration(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $client->expects($this->once())
            ->method('getItemPerformance')
            ->with('MLB123')
            ->willReturn([
                'score' => 55,
                'level' => 'Good',
                'level_wording' => 'Satisfatória',
                'calculated_at' => '2026-07-17T12:00:00Z',
                'buckets' => [
                    [
                        'key' => 'CHARACTERISTICS',
                        'variables' => [
                            [
                                'key' => 'TECHNICAL_SPECIFICATIONS_MAIN',
                                'status' => 'PENDING',
                                'rules' => [
                                    [
                                        'key' => 'TS_MAIN_QUALITY_INCOMPLETE_REQUIRED',
                                        'status' => 'PENDING',
                                        'mode' => 'WARNING',
                                        'wordings' => [
                                            'title' => 'Complete atributos required',
                                            'label' => 'Completar',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $client->expects($this->once())
            ->method('getLastModeration')
            ->with('MLB123')
            ->willReturn([]);

        $service = new ListingSearchVisibilityService($client);
        $report = $service->analyzeListing('MLB123');

        $this->assertSame('MLB123', $report['listing_id']);
        $this->assertSame(55, $report['score']);
        $this->assertSame('critical', $report['search_activation']);
        $this->assertFalse($report['write_enabled']);
        $this->assertGreaterThanOrEqual(1, $report['pending_warnings']);
        $this->assertSame('TS_MAIN_QUALITY_INCOMPLETE_REQUIRED', $report['seo_actions'][0]['key']);
    }

    public function testIrregularityScanBuildsBlockedQueue(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $client->method('getMyItems')->willReturnCallback(static function (array $params): array {
            if (($params['status'] ?? '') === 'under_review') {
                return ['results' => ['MLB111']];
            }
            if (($params['status'] ?? '') === 'pending') {
                return ['results' => ['MLB333']];
            }
            if (($params['status'] ?? '') === 'paused') {
                return ['results' => ['MLB222']];
            }
            if (($params['status'] ?? '') === 'active' && ($params['tags'] ?? '') === 'poor_quality_thumbnail') {
                return ['results' => ['MLB444']];
            }
            return ['results' => []];
        });
        $client->method('getLastModeration')->willReturnCallback(static function (string $itemId): array {
            if ($itemId === 'MLB111') {
                return [[
                    'name' => 'WAITING_PATCH',
                    'wordings' => [
                        ['type' => 'REASON', 'value' => 'Atributo inválido'],
                        ['type' => 'REMEDY', 'value' => 'Corrija o atributo'],
                    ],
                ]];
            }
            if ($itemId === 'MLB444') {
                return [];
            }
            return [[
                'name' => 'PAUSED_PREVENTION_PRICE',
                'wordings' => [
                    ['type' => 'REASON', 'value' => 'Preço incomum'],
                    ['type' => 'REMEDY', 'value' => 'Revise o preço e reative'],
                ],
            ]];
        });

        $visibility = new ListingSearchVisibilityService($client);
        $scan = new ListingIrregularityScanService($client, $visibility);
        $result = $scan->scan(10);

        $this->assertFalse($result['write_enabled']);
        $this->assertSame(1, $result['totals']['under_review']);
        $this->assertSame(1, $result['totals']['pending']);
        $this->assertSame(1, $result['totals']['paused_moderation_penalty']);
        $this->assertSame(1, $result['totals']['active_poor_quality_thumbnail']);
        $this->assertSame(4, $result['totals']['unique']);
        $this->assertCount(4, $result['blocked']);
        $this->assertSame('Corrija o atributo', $result['blocked'][0]['next_step']);

        $thumb = null;
        foreach ($result['blocked'] as $row) {
            if ($row['listing_id'] === 'MLB444') {
                $thumb = $row;
                break;
            }
        }
        $this->assertNotNull($thumb);
        $this->assertSame('exposure_loss', $thumb['severity']);
        $this->assertSame('active_poor_quality_thumbnail', $thumb['source_status']);
    }

    public function testNormalizeModerationAcceptsEvidenceSingularKey(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $service = new ListingSearchVisibilityService($client);

        $normalized = $service->normalizeModeration([
            [
                'name' => 'PAUSED_PREVENTION_PRICE',
                'wordings' => [
                    ['type' => 'REASON', 'value' => 'Preço incomum'],
                    ['type' => 'REMEDY', 'value' => 'Revise e reative'],
                ],
                'evidence' => [
                    ['section_name' => 'item', 'text_matched' => '77393.72'],
                ],
            ],
        ]);

        $this->assertTrue($normalized['active']);
        $this->assertCount(1, $normalized['evidences']);
        $this->assertSame('item', $normalized['evidences'][0]['section_name']);
    }
}
