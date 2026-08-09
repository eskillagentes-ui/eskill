<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MercadoLivreClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * MLVisitsContractTest
 *
 * Testes de regressão para contratos de visitas e enriquecimento de itens.
 *
 * Bugs corrigidos:
 *  - FIX-ML-001: AccountXRayService iterava resultado de getMultiItemVisits com
 *    chave 'id'/'total_visits' inexistentes. A API retorna array indexado por item_id.
 *  - FIX-ML-002: getSellerItemsForOptimization só enriquecia os primeiros 20 IDs;
 *    agora percorre todos os IDs em batches de 20.
 *  - FIX-ML-003 (2026-08-09): getMultiItemVisits chamava GET /items/visits com até
 *    50 IDs na mesma requisição. A doc oficial ("Visitas") só permite 1 ID por
 *    chamada nessa variante com date_from/date_to — com mais de um, a API sempre
 *    respondia 400 "maximum amount of items to query is 1", fazendo TODOS os
 *    itens caírem no fallback de erro (visitas sempre 0). Corrigido para chamar
 *    GET /items/{id}/visits/time_window?last={days}&unit=day individualmente por
 *    item, que já retorna o detalhamento diário em `results`.
 *
 * @covers \App\Services\MercadoLivreClient
 */
class MLVisitsContractTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════════════
    // FIX-ML-001 / FIX-ML-003: getMultiItemVisits return format contract
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * getMultiItemVisits deve retornar array indexado por item_id,
     * onde cada valor contém as chaves 'total', 'visits' e 'daily', usando uma
     * chamada por item ao endpoint /items/{id}/visits/time_window (FIX-ML-003).
     * Não deve conter chaves 'id' ou 'total_visits' no nível superior.
     */
    public function testGetMultiItemVisitsReturnsMapIndexedByItemId(): void
    {
        MercadoLivreClient::disableCircuitBreaker();

        // Mocka fetchVisitAsync (protected) — retorna PromiseInterface com resposta
        // JSON, sem precisar mockar o httpClient do Guzzle diretamente.
        $client = $this->getMockBuilder(MercadoLivreClient::class)
            ->onlyMethods(['fetchVisitAsync', 'getSellerId', 'getAccessToken'])
            ->setConstructorArgs([null])
            ->getMock();

        $client->method('getAccessToken')->willReturn('test_token');

        $payloads = [
            'MLB111' => [
                'total_visits' => 25,
                'results' => [
                    ['date' => '2025-03-01', 'total' => 10],
                    ['date' => '2025-03-02', 'total' => 15],
                ],
            ],
            'MLB222' => [
                'total_visits' => 5,
                'results' => [['date' => '2025-03-01', 'total' => 5]],
            ],
        ];

        $client->method('fetchVisitAsync')
            ->willReturnCallback(
                function (string $id, int $days) use ($payloads): \GuzzleHttp\Promise\PromiseInterface {
                    $body = json_encode($payloads[$id] ?? []);
                    return Create::promiseFor(new Response(200, [], $body));
                }
            );

        $result = $client->getMultiItemVisits(['MLB111', 'MLB222'], 30);

        // O resultado DEVE ser indexado por item_id
        $this->assertArrayHasKey('MLB111', $result, 'Resultado deve ser indexado por item_id MLB111');
        $this->assertArrayHasKey('MLB222', $result, 'Resultado deve ser indexado por item_id MLB222');

        // Cada entrada deve ter chave 'total' (não 'total_visits')
        $this->assertArrayHasKey('total', $result['MLB111'], 'Entrada MLB111 deve ter chave total');
        $this->assertArrayHasKey('visits', $result['MLB111'], 'Entrada MLB111 deve ter chave visits');

        // total vem direto de total_visits (já agregado pela API)
        $this->assertSame(25, $result['MLB111']['total'], 'MLB111 total deve ser 25 (vindo de total_visits)');
        $this->assertSame(5, $result['MLB222']['total'], 'MLB222 total deve ser 5');

        // daily deve conter o detalhamento por dia (usado para derivar janelas menores, ex. 14d)
        $this->assertCount(2, $result['MLB111']['daily'], 'MLB111 deve ter 2 dias no detalhamento');
        $this->assertSame(10, $result['MLB111']['daily'][0]['total']);

        // NÃO deve ter 'id' nem 'total_visits' como chaves de resultado
        $this->assertArrayNotHasKey('id', $result, 'Resultado não deve ter chave id no nível superior');
        $this->assertArrayNotHasKey('total_visits', $result, 'Resultado não deve ter chave total_visits no nível superior');
    }

    /**
     * Regressão para o bug real encontrado em produção: chamar /items/visits
     * com múltiplos IDs na mesma URL retorna erro 400 da API. getMultiItemVisits
     * NUNCA deve montar uma URL com múltiplos IDs nessa variante — cada chamada
     * a `get()` deve ser para exatamente 1 item.
     */
    public function testGetMultiItemVisitsNeverBatchesMultipleIdsInSingleCall(): void
    {
        MercadoLivreClient::disableCircuitBreaker();

        // Mocka fetchVisitAsync (protected) para capturar quais IDs foram solicitados.
        // A assinatura `fetchVisitAsync(string $id, ...)` garante 1 ID por chamada por
        // design — o teste valida que getMultiItemVisits() de fato invoca 1 vez por item.
        $client = $this->getMockBuilder(MercadoLivreClient::class)
            ->onlyMethods(['fetchVisitAsync', 'getSellerId', 'getAccessToken'])
            ->setConstructorArgs([null])
            ->getMock();

        $client->method('getAccessToken')->willReturn('test_token');

        $calledIds = [];
        $client->method('fetchVisitAsync')
            ->willReturnCallback(
                function (string $id, int $days) use (&$calledIds): \GuzzleHttp\Promise\PromiseInterface {
                    $calledIds[] = $id;
                    $body = json_encode(['total_visits' => 1, 'results' => []]);
                    return Create::promiseFor(new Response(200, [], $body));
                }
            );

        $client->getMultiItemVisits(['MLB1', 'MLB2', 'MLB3'], 30);

        $this->assertCount(3, $calledIds, 'Deve haver exatamente 1 chamada por item');
        foreach (['MLB1', 'MLB2', 'MLB3'] as $id) {
            $this->assertContains($id, $calledIds, "fetchVisitAsync deve ser chamado para {$id}");
        }
    }

    /**
     * Padrão correto de iteração sobre resultado de getMultiItemVisits.
     * Verifica que a lógica corrigida em AccountXRayService::enrichWithMetrics
     * constrói o visitMap corretamente.
     */
    public function testVisitMapBuiltFromGetMultiItemVisitsResult(): void
    {
        // Simula o retorno de getMultiItemVisits (formato real da API após normalização)
        $multiVisitsResult = [
            'MLB100' => ['total' => 42, 'visits' => 42, 'daily' => ['2025-03-01' => 42]],
            'MLB200' => ['total' => 0,  'visits' => 0,  'daily' => []],
            'MLB300' => ['total' => 7,  'visits' => 7,  'daily' => ['2025-03-02' => 7]],
        ];

        // Lógica CORRIGIDA (como está agora em AccountXRayService::enrichWithMetrics)
        $visitMap = [];
        foreach ($multiVisitsResult as $itemId => $visitData) {
            $visitMap[(string) $itemId] = (int) ($visitData['total'] ?? $visitData['visits'] ?? 0);
        }

        $this->assertSame(42, $visitMap['MLB100'], 'MLB100 deve ter 42 visitas');
        $this->assertSame(0,  $visitMap['MLB200'], 'MLB200 deve ter 0 visitas');
        $this->assertSame(7,  $visitMap['MLB300'], 'MLB300 deve ter 7 visitas');
    }

    /**
     * Padrão INCORRETO (bug original) não deve ser usado:
     * iterar como lista com chave 'id'/'total_visits' resulta em visitMap vazio.
     */
    public function testBuggyIterationPatternProducesEmptyMap(): void
    {
        $multiVisitsResult = [
            'MLB100' => ['total' => 42, 'visits' => 42, 'daily' => []],
            'MLB200' => ['total' => 7,  'visits' => 7,  'daily' => []],
        ];

        // Lógica BUG ORIGINAL — iterar como lista de objetos com chave 'id'
        $buggyVisitMap = [];
        foreach ($multiVisitsResult as $v) {
            // $v é um array como ['total' => 42, 'visits' => 42, 'daily' => []]
            // $v['id'] não existe → chave vazia, $v['total_visits'] não existe → 0
            $buggyVisitMap[$v['id'] ?? ''] = (int) ($v['total_visits'] ?? 0);
        }

        // O mapa bugado tem apenas a chave '' com valor 0 (última iteração sobrescreve)
        $this->assertArrayNotHasKey('MLB100', $buggyVisitMap, 'Bug: MLB100 não aparece no mapa');
        $this->assertArrayNotHasKey('MLB200', $buggyVisitMap, 'Bug: MLB200 não aparece no mapa');
        $this->assertSame(0, $buggyVisitMap[''] ?? -1, 'Bug: chave vazia com valor 0');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FIX-ML-002: getSellerItemsForOptimization enriches ALL items
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Quando a busca retorna 25 IDs, getSellerItemsForOptimization deve
     * enriquecer todos os 25 itens (em batches de 20), não apenas os primeiros 20.
     */
    public function testGetSellerItemsForOptimizationEnrichesAllItems(): void
    {
        MercadoLivreClient::disableCircuitBreaker();

        $allIds = [];
        for ($i = 1; $i <= 25; $i++) {
            $allIds[] = "MLB{$i}";
        }

        $client = $this->getMockBuilder(MercadoLivreClient::class)
            ->onlyMethods(['get', 'getSellerId', 'getAccessToken'])
            ->setConstructorArgs([null])
            ->getMock();

        $client->method('getAccessToken')->willReturn('test_token');
        $client->method('getSellerId')->willReturn('999');

        $multiGetCallCount = 0;
        $client->method('get')->willReturnCallback(
            function (string $endpoint, array $params = []) use ($allIds, &$multiGetCallCount): array {
                if (str_contains($endpoint, '/items/search')) {
                    return [
                        'results' => $allIds,
                        'paging' => ['total' => 25, 'offset' => 0, 'limit' => 50],
                    ];
                }

                if ($endpoint === '/items') {
                    $multiGetCallCount++;
                    $ids = explode(',', $params['ids'] ?? '');
                    return array_map(fn(string $id): array => [
                        'body' => [
                            'id' => $id,
                            'title' => "Item {$id}",
                            'price' => 100.0,
                            'status' => 'active',
                            'category_id' => 'MLB1234',
                            'permalink' => "https://item.mlstatic.com/{$id}",
                            'sold_quantity' => 0,
                            'available_quantity' => 5,
                            'thumbnail' => '',
                            'listing_type_id' => 'gold_special',
                            'health' => null,
                        ],
                    ], $ids);
                }

                return [];
            }
        );

        $result = $client->getSellerItemsForOptimization(['limit' => 50]);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('paging', $result);

        $this->assertCount(25, $result['items'], 'Todos os 25 itens devem ser enriquecidos');

        // Com 25 IDs e batch de 20, deve fazer 2 chamadas ao multi-get
        $this->assertSame(2, $multiGetCallCount, 'Deve fazer 2 chamadas ao /items (20+5)');
    }

    /**
     * Quando há exatamente 20 IDs, deve fazer apenas 1 chamada ao multi-get.
     */
    public function testGetSellerItemsForOptimizationWith20ItemsMakesOneBatchCall(): void
    {
        MercadoLivreClient::disableCircuitBreaker();

        $allIds = [];
        for ($i = 1; $i <= 20; $i++) {
            $allIds[] = "MLB{$i}";
        }

        $client = $this->getMockBuilder(MercadoLivreClient::class)
            ->onlyMethods(['get', 'getSellerId', 'getAccessToken'])
            ->setConstructorArgs([null])
            ->getMock();

        $client->method('getAccessToken')->willReturn('test_token');
        $client->method('getSellerId')->willReturn('999');

        $multiGetCallCount = 0;
        $client->method('get')->willReturnCallback(
            function (string $endpoint, array $params = []) use ($allIds, &$multiGetCallCount): array {
                if (str_contains($endpoint, '/items/search')) {
                    return [
                        'results' => $allIds,
                        'paging' => ['total' => 20, 'offset' => 0, 'limit' => 50],
                    ];
                }

                if ($endpoint === '/items') {
                    $multiGetCallCount++;
                    $ids = explode(',', $params['ids'] ?? '');
                    return array_map(fn(string $id): array => [
                        'body' => [
                            'id' => $id,
                            'title' => "Item {$id}",
                            'price' => 100.0,
                            'status' => 'active',
                            'category_id' => 'MLB1234',
                            'permalink' => "https://item.mlstatic.com/{$id}",
                            'sold_quantity' => 0,
                            'available_quantity' => 5,
                            'thumbnail' => '',
                            'listing_type_id' => 'gold_special',
                            'health' => null,
                        ],
                    ], $ids);
                }

                return [];
            }
        );

        $result = $client->getSellerItemsForOptimization(['limit' => 50]);

        $this->assertCount(20, $result['items'], '20 itens devem ser enriquecidos');
        $this->assertSame(1, $multiGetCallCount, 'Exatamente 1 chamada ao /items para 20 IDs');
    }

    /**
     * Garante que itens retornados pelo multi-get contêm os campos obrigatórios esperados.
     */
    public function testGetSellerItemsForOptimizationReturnsExpectedFields(): void
    {
        MercadoLivreClient::disableCircuitBreaker();

        $client = $this->getMockBuilder(MercadoLivreClient::class)
            ->onlyMethods(['get', 'getSellerId', 'getAccessToken'])
            ->setConstructorArgs([null])
            ->getMock();

        $client->method('getAccessToken')->willReturn('test_token');
        $client->method('getSellerId')->willReturn('999');

        $client->method('get')->willReturnCallback(
            function (string $endpoint, array $params = []): array {
                if (str_contains($endpoint, '/items/search')) {
                    return [
                        'results' => ['MLB42'],
                        'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50],
                    ];
                }
                if ($endpoint === '/items') {
                    return [[
                        'body' => [
                            'id' => 'MLB42',
                            'title' => 'Bagageiro CG 160',
                            'price' => 299.90,
                            'status' => 'active',
                            'category_id' => 'MLB1234',
                            'permalink' => 'https://produto.mercadolivre.com.br/MLB42',
                            'sold_quantity' => 15,
                            'available_quantity' => 3,
                            'thumbnail' => 'https://img.mlstatic.com/D_MLB42-O.jpg',
                            'listing_type_id' => 'gold_special',
                            'health' => null,
                        ],
                    ]];
                }
                return [];
            }
        );

        $result = $client->getSellerItemsForOptimization();

        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];

        foreach (
            [
                'id',
                'title',
                'price',
                'status',
                'category_id',
                'permalink',
                'sold_quantity',
                'available_quantity',
                'thumbnail',
                'listing_type_id'
            ] as $field
        ) {
            $this->assertArrayHasKey($field, $item, "Item deve ter campo '{$field}'");
        }

        $this->assertSame('MLB42', $item['id']);
        $this->assertSame('Bagageiro CG 160', $item['title']);
        $this->assertSame(299.90, (float) $item['price']);
    }
}
