<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pricing;

use App\Services\HiddenSeo\SafetyGuard;
use App\Services\Pricing\RepriceSimulationService;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Testes do RepriceSimulationService (Fase 0 — simulação read-only).
 *
 * DB: SQLite in-memory (tabela items mínima). Dados de mercado: closure injetada
 * (mock do provider) — NUNCA rede real / MercadoLivreClient em teste.
 */
class RepriceSimulationServiceTest extends TestCase
{
    private PDO $db;

    /** @var array<string, float> title => market min retornado pelo provider mock */
    private array $marketMinByTitle = [];

    /** @var int quantas vezes o provider mock foi chamado */
    private int $providerCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE items (
                account_id INTEGER NOT NULL,
                ml_item_id TEXT NOT NULL,
                title TEXT NOT NULL,
                price REAL NOT NULL,
                min_price REAL NULL,
                max_price REAL NULL,
                cost_price REAL NULL,
                auto_reprice INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'active'
            )
        ");

        $this->marketMinByTitle = [];
        $this->providerCalls = 0;
    }

    private function insertItem(
        string $mlItemId,
        float $price,
        ?float $minPrice,
        ?float $maxPrice = null,
        ?float $costPrice = null,
        int $accountId = 1336,
        int $autoReprice = 1,
        string $status = 'active'
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO items (account_id, ml_item_id, title, price, min_price, max_price, cost_price, auto_reprice, status)
            VALUES (:account_id, :ml_item_id, :title, :price, :min_price, :max_price, :cost_price, :auto_reprice, :status)
        ");
        $stmt->execute([
            'account_id' => $accountId,
            'ml_item_id' => $mlItemId,
            'title' => 'Produto ' . $mlItemId,
            'price' => $price,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'cost_price' => $costPrice,
            'auto_reprice' => $autoReprice,
            'status' => $status,
        ]);
    }

    private function makeService(
        ?SafetyGuard $safetyGuard = null,
        float $maxPct = 5.0,
        int $maxItemsPerRun = 5,
        float $minMarginPct = 10.0
    ): RepriceSimulationService {
        // Mock do provider de dados de mercado (substitui CompetitorSpy/ML client).
        $provider = function (string $title, int $accountId): array {
            $this->providerCalls++;
            if (!isset($this->marketMinByTitle[$title])) {
                return ['error' => 'sem dados de mercado (mock)'];
            }
            return [
                'search_term' => $title,
                'analyzed' => 10,
                'price_analysis' => [
                    'min' => $this->marketMinByTitle[$title],
                    'max' => $this->marketMinByTitle[$title] * 1.5,
                    'avg' => $this->marketMinByTitle[$title] * 1.2,
                ],
            ];
        };

        return new RepriceSimulationService(
            $this->db,
            $provider,
            $safetyGuard ?? new SafetyGuard(false, [1335], 500),
            new NullLogger(),
            $maxPct,
            $maxItemsPerRun,
            $minMarginPct
        );
    }

    private function setMarketMin(string $mlItemId, float $marketMin): void
    {
        $this->marketMinByTitle['Produto ' . $mlItemId] = $marketMin;
    }

    public function testAplicaQuandoDentroDosTetos(): void
    {
        // Preço 100, mercado min 103 → alvo 102.90 (subir, +2.9% ≤ 5%), sem custo
        $this->insertItem('MLB001', 100.00, 80.00);
        $this->setMarketMin('MLB001', 103.00);

        $report = $this->makeService()->simulate(1336);

        $this->assertCount(1, $report['items']);
        $item = $report['items'][0];
        $this->assertSame('MLB001', $item['item_id']);
        $this->assertSame(100.00, $item['preco_atual']);
        $this->assertSame(102.90, $item['preco_sugerido']);
        $this->assertSame(2.90, $item['delta_pct']);
        $this->assertTrue($item['seria_aplicado']);
        $this->assertNull($item['motivo_skip']);
        $this->assertNotEmpty($item['motivo']);
        $this->assertSame(1, $report['summary']['seria_aplicado']);
    }

    public function testSkipQuandoDeltaAcimaDoTetoSemClamp(): void
    {
        // Preço 100, mercado min 200 → alvo 199.90 (+99.9% > teto 5%)
        $this->insertItem('MLB002', 100.00, 50.00);
        $this->setMarketMin('MLB002', 200.00);

        $report = $this->makeService(null, 5.0)->simulate(1336);

        $item = $report['items'][0];
        $this->assertFalse($item['seria_aplicado']);
        $this->assertStringContainsString('delta_acima_do_teto', (string)$item['motivo_skip']);
        // Nunca clamp silencioso: preço sugerido é o que o engine calculou, não 105.00
        $this->assertSame(199.90, $item['preco_sugerido']);
        $this->assertSame(99.90, $item['delta_pct']);
    }

    public function testSkipQuandoAbaixoDoPisoDeMargem(): void
    {
        // Custo 100, margem mín 10% → piso 110. Mercado min 105 → alvo 104.90 < piso.
        // maxPct alto (50) para isolar o teto de margem.
        $this->insertItem('MLB003', 115.00, 90.00, null, 100.00);
        $this->setMarketMin('MLB003', 105.00);

        $report = $this->makeService(null, 50.0, 5, 10.0)->simulate(1336);

        $item = $report['items'][0];
        $this->assertFalse($item['seria_aplicado']);
        $this->assertStringContainsString('abaixo_do_piso_de_margem', (string)$item['motivo_skip']);
        $this->assertSame(104.90, $item['preco_sugerido']);
    }

    public function testRespeitaPisoDeMargemQuandoCustoDisponivelEAplica(): void
    {
        // Custo 60, piso 66. Mercado min 103 → alvo 102.90 ≥ piso, delta dentro do teto.
        $this->insertItem('MLB004', 100.00, 70.00, null, 60.00);
        $this->setMarketMin('MLB004', 103.00);

        $report = $this->makeService()->simulate(1336);

        $this->assertTrue($report['items'][0]['seria_aplicado']);
    }

    public function testLimiteDeItensPorRun(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $id = sprintf('MLB1%03d', $i);
            $this->insertItem($id, 100.00, 80.00);
            $this->setMarketMin($id, 103.00);
        }

        // Teto de config: 5 itens por run
        $report = $this->makeService(null, 5.0, 5)->simulate(1336);
        $this->assertCount(5, $report['items']);
        $this->assertSame(5, $report['summary']['candidatos']);

        // --limit menor que o teto reduz ainda mais
        $reportLimit = $this->makeService(null, 5.0, 5)->simulate(1336, 3);
        $this->assertCount(3, $reportLimit['items']);

        // --limit maior que o teto NÃO ultrapassa o teto
        $reportCapped = $this->makeService(null, 5.0, 5)->simulate(1336, 100);
        $this->assertCount(5, $reportCapped['items']);
    }

    public function testSimulacaoNaoEscreveEmNada(): void
    {
        $this->insertItem('MLB005', 100.00, 80.00);
        $this->setMarketMin('MLB005', 103.00);

        $antes = $this->db->query("SELECT * FROM items")->fetchAll(PDO::FETCH_ASSOC);

        $report = $this->makeService()->simulate(1336);

        $depois = $this->db->query("SELECT * FROM items")->fetchAll(PDO::FETCH_ASSOC);

        // Banco intacto (preço incluso) — simulação é read-only
        $this->assertSame($antes, $depois);
        $this->assertSame(100.00, (float)$depois[0]['price']);
        // Dados de mercado vieram exclusivamente do mock (sem MercadoLivreClient/rede)
        $this->assertSame(1, $this->providerCalls);
        $this->assertTrue($report['items'][0]['seria_aplicado']);
    }

    public function testContaProibidaNaoAplicariaNada(): void
    {
        // Conta 1335 (FACILYTY prod) está em FORBIDDEN_ACCOUNTS
        $this->insertItem('MLB006', 100.00, 80.00, null, null, 1335);
        $this->setMarketMin('MLB006', 103.00);

        $guard = new SafetyGuard(true, [1335], 500);
        $report = $this->makeService($guard)->simulate(1335);

        // Simulação roda (dry-run sempre permitido), mas apply seria bloqueado
        $this->assertTrue($report['safety']['forbidden_account']);
        $this->assertCount(1, $report['items']);
        $item = $report['items'][0];
        $this->assertFalse($item['seria_aplicado']);
        $this->assertStringContainsString('FORBIDDEN_ACCOUNTS', (string)$item['motivo_skip']);
        // Mesmo assim calcula o preço sugerido (valor de validação da Fase 0)
        $this->assertSame(102.90, $item['preco_sugerido']);
        $this->assertSame(0, $report['summary']['seria_aplicado']);
    }

    public function testContaForaDaBlacklistAplicaNormalmente(): void
    {
        $this->insertItem('MLB007', 100.00, 80.00, null, null, 1336);
        $this->setMarketMin('MLB007', 103.00);

        $guard = new SafetyGuard(true, [1335], 500);
        $report = $this->makeService($guard)->simulate(1336);

        $this->assertFalse($report['safety']['forbidden_account']);
        $this->assertTrue($report['items'][0]['seria_aplicado']);
    }

    public function testSkipQuandoSemMudancaSignificativa(): void
    {
        // min_price = 100 (piso) e mercado abaixo disso → alvo clampado no piso = preço
        // atual → |delta| = 0 ≤ R$ 0,05 → sem mudança (regra do SniperAgent).
        $this->insertItem('MLB008', 100.00, 100.00);
        $this->setMarketMin('MLB008', 99.00);

        $report = $this->makeService()->simulate(1336);

        $item = $report['items'][0];
        $this->assertFalse($item['seria_aplicado']);
        $this->assertSame('sem_mudanca_significativa', $item['motivo_skip']);
        $this->assertSame(100.00, $item['preco_sugerido']);
    }

    public function testSkipQuandoMinPriceNaoDefinido(): void
    {
        $this->insertItem('MLB009', 100.00, null);
        $this->setMarketMin('MLB009', 103.00);

        $report = $this->makeService()->simulate(1336);

        $item = $report['items'][0];
        $this->assertFalse($item['seria_aplicado']);
        $this->assertSame('min_price_nao_definido', $item['motivo_skip']);
        // Sem min_price não há nem consulta ao mercado (economiza chamada na API)
        $this->assertSame(0, $this->providerCalls);
    }

    public function testApenasItensComAutoRepriceDaContaSaoAvaliados(): void
    {
        $this->insertItem('MLB010', 100.00, 80.00, null, null, 1336, 1);
        $this->insertItem('MLB011', 100.00, 80.00, null, null, 1336, 0); // sem flag
        $this->insertItem('MLB012', 100.00, 80.00, null, null, 9999, 1); // outra conta
        $this->insertItem('MLB013', 100.00, 80.00, null, null, 1336, 1, 'paused'); // inativo
        $this->setMarketMin('MLB010', 103.00);

        $report = $this->makeService()->simulate(1336);

        $this->assertCount(1, $report['items']);
        $this->assertSame('MLB010', $report['items'][0]['item_id']);
    }
}
