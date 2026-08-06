<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\CustomerPaymentMethodService;
use App\Services\MercadoLivreClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\CustomerPaymentMethodService
 */
class CustomerPaymentMethodServiceTest extends TestCase
{
    private function buildService(object $mpClient): CustomerPaymentMethodService
    {
        $ref = new \ReflectionClass(CustomerPaymentMethodService::class);
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

    public function testGetCustomerSuccess(): void
    {
        $mp = $this->createMockMpClient();
        $mp->expects($this->once())
            ->method('get')
            ->with($this->stringContains('/v1/customers/CUS-1'))
            ->willReturn(['id' => 'CUS-1', 'email' => 'a@b.com']);

        $result = $this->buildService($mp)->getCustomer('CUS-1');

        $this->assertSame('CUS-1', $result['id']);
        $this->assertSame('a@b.com', $result['email']);
    }

    public function testSearchCustomersPassesEmailAndPaging(): void
    {
        $mp = $this->createMockMpClient();
        $mp->expects($this->once())
            ->method('get')
            ->with($this->callback(function (string $url): bool {
                return str_contains($url, '/v1/customers/search')
                    && str_contains($url, 'email=a%40b.com')
                    && str_contains($url, 'limit=10')
                    && str_contains($url, 'offset=5');
            }))
            ->willReturn(['results' => [], 'paging' => ['total' => 0]]);

        $result = $this->buildService($mp)->searchCustomers([
            'email' => 'a@b.com',
            'limit' => 10,
            'offset' => 5,
        ]);

        $this->assertSame([], $result['results']);
    }

    public function testGetCustomerCards(): void
    {
        $mp = $this->createMockMpClient();
        $mp->method('get')->willReturn(['results' => [['id' => 'CARD-1']]]);

        $result = $this->buildService($mp)->getCustomerCards('CUS-1');

        $this->assertSame('CARD-1', $result['results'][0]['id']);
    }

    public function testSaveCustomerCardPostsToken(): void
    {
        $mp = $this->createMockMpClient();
        $mp->expects($this->once())
            ->method('post')
            ->with(
                $this->stringContains('/v1/customers/CUS-1/cards'),
                ['token' => 'tok_abc']
            )
            ->willReturn(['id' => 'CARD-9']);

        $result = $this->buildService($mp)->saveCustomerCard('CUS-1', 'tok_abc');

        $this->assertSame('CARD-9', $result['id']);
    }

    public function testDeleteCustomerCard(): void
    {
        $mp = $this->createMockMpClient();
        $mp->expects($this->once())
            ->method('delete')
            ->with($this->stringContains('/v1/customers/CUS-1/cards/CARD-1'))
            ->willReturn(['id' => 'CARD-1', 'deleted' => true]);

        $result = $this->buildService($mp)->deleteCustomerCard('CUS-1', 'CARD-1');

        $this->assertTrue($result['deleted']);
    }

    public function testUpdateCustomer(): void
    {
        $mp = $this->createMockMpClient();
        $mp->expects($this->once())
            ->method('put')
            ->with(
                $this->stringContains('/v1/customers/CUS-1'),
                ['first_name' => 'Ana']
            )
            ->willReturn(['id' => 'CUS-1', 'first_name' => 'Ana']);

        $result = $this->buildService($mp)->updateCustomer('CUS-1', ['first_name' => 'Ana']);

        $this->assertSame('Ana', $result['first_name']);
    }
}
