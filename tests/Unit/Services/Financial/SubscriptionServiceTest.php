<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\SubscriptionService;
use App\Services\MercadoLivreClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\SubscriptionService
 */
class SubscriptionServiceTest extends TestCase
{
    private function buildService(object $mpClient): SubscriptionService
    {
        $ref = new \ReflectionClass(SubscriptionService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $ml = $this->createMock(MercadoLivreClient::class);
        $clientProp = $ref->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($service, $ml);

        $accountIdProp = $ref->getProperty('accountId');
        $accountIdProp->setAccessible(true);
        $accountIdProp->setValue($service, 1);

        $mpProp = $ref->getProperty('mpClient');
        $mpProp->setAccessible(true);
        $mpProp->setValue($service, $mpClient);

        return $service;
    }

    private function createMockMpClient(): object
    {
        return $this->getMockBuilder(\stdClass::class)
            ->addMethods(['get', 'post', 'put', 'delete'])
            ->getMock();
    }

    public function testGetSubscription(): void
    {
        $mp = $this->createMockMpClient();
        $mp->method('get')->willReturn(['id' => 'SUB-1', 'status' => 'authorized']);

        $result = $this->buildService($mp)->getSubscription('SUB-1');

        $this->assertSame('authorized', $result['status']);
    }

    public function testPauseSubscriptionPutsPausedStatus(): void
    {
        $mp = $this->createMockMpClient();
        $mp->expects($this->once())
            ->method('put')
            ->with(
                $this->stringContains('/preapproval/SUB-1'),
                ['status' => 'paused']
            )
            // Sem 'id' → não dispara save/update local (DB).
            ->willReturn(['status' => 'paused']);

        $result = $this->buildService($mp)->pauseSubscription('SUB-1');

        $this->assertSame('paused', $result['status']);
    }

    public function testActivateAndCancelSubscription(): void
    {
        $mp = $this->createMockMpClient();
        $mp->method('put')->willReturnCallback(function (string $url, array $payload): array {
            return ['status' => $payload['status']];
        });

        $service = $this->buildService($mp);
        $this->assertSame('authorized', $service->activateSubscription('SUB-1')['status']);
        $this->assertSame('cancelled', $service->cancelSubscription('SUB-1')['status']);
    }

    public function testSearchSubscriptionsBuildsQuery(): void
    {
        $mp = $this->createMockMpClient();
        $mp->expects($this->once())
            ->method('get')
            ->with($this->callback(function (string $url): bool {
                return str_contains($url, '/preapproval/search')
                    && str_contains($url, 'status=authorized')
                    && str_contains($url, 'limit=20');
            }))
            ->willReturn(['results' => []]);

        $this->buildService($mp)->searchSubscriptions(['status' => 'authorized', 'limit' => 20]);
    }

    public function testGetRecurringRevenueAnalysisNormalizesMrr(): void
    {
        $mp = $this->createMockMpClient();
        $mp->method('get')->willReturn([
            'results' => [
                [
                    'id' => 'S1',
                    'preapproval_plan_id' => 'P1',
                    'payer_email' => 'a@b.com',
                    'next_payment_date' => '2026-09-01',
                    'auto_recurring' => [
                        'transaction_amount' => 100.0,
                        'frequency' => 1,
                        'frequency_type' => 'months',
                    ],
                ],
                [
                    'id' => 'S2',
                    'auto_recurring' => [
                        'transaction_amount' => 120.0,
                        'frequency' => 1,
                        'frequency_type' => 'years',
                    ],
                ],
            ],
        ]);

        $result = $this->buildService($mp)->getRecurringRevenueAnalysis();

        // 100/mês + 120/ano (=10/mês) => MRR 110
        $this->assertSame(110.0, $result['mrr']);
        $this->assertSame(1320.0, $result['arr']);
        $this->assertSame(2, $result['total_active_subscriptions']);
        $this->assertSame(1, $result['subscriptions_by_plan']['P1']['count']);
    }

    public function testCalculateSubscriptionChurnForMonth(): void
    {
        $mp = $this->createMockMpClient();
        $mp->method('get')->willReturnCallback(function (string $url): array {
            if (str_contains($url, 'status=cancelled')) {
                return [
                    'results' => [
                        [
                            'id' => 'CX',
                            'last_modified' => '2026-07-15',
                            'auto_recurring' => [
                                'transaction_amount' => 50.0,
                                'frequency' => 1,
                                'frequency_type' => 'months',
                            ],
                        ],
                        [
                            'id' => 'OLD',
                            'last_modified' => '2026-06-01',
                            'auto_recurring' => [
                                'transaction_amount' => 50.0,
                                'frequency' => 1,
                                'frequency_type' => 'months',
                            ],
                        ],
                    ],
                ];
            }
            // authorized at start
            return [
                'results' => [
                    ['id' => 'A1', 'auto_recurring' => ['transaction_amount' => 10, 'frequency' => 1, 'frequency_type' => 'months']],
                    ['id' => 'A2', 'auto_recurring' => ['transaction_amount' => 10, 'frequency' => 1, 'frequency_type' => 'months']],
                ],
            ];
        });

        $result = $this->buildService($mp)->calculateSubscriptionChurn('2026-07');

        // 1 cancelado no mês / (2 ativas + 1 cancelada) = 33.33%
        $this->assertSame(1, $result['cancelled_count']);
        $this->assertSame(3, $result['active_at_start']);
        $this->assertEqualsWithDelta(33.33, $result['churn_rate'], 0.1);
        $this->assertSame(50.0, $result['lost_mrr']);
    }

    public function testGetSubscriptionPlan(): void
    {
        $mp = $this->createMockMpClient();
        $mp->method('get')->willReturn(['id' => 'PLAN-1', 'reason' => 'Pro']);

        $result = $this->buildService($mp)->getSubscriptionPlan('PLAN-1');

        $this->assertSame('Pro', $result['reason']);
    }
}
