<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use PHPUnit\Framework\TestCase;

abstract class SniperAgentDryRunFakeBaseAgent
{
    protected string $code;
    protected object $db;
    protected array $config = [];
    public array $loggedMessages = [];

    public function __construct(string $code)
    {
        $this->code = $code;
    }

    public function setFakeDb(object $db): void
    {
        $this->db = $db;
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    protected function updateLastRun(): void
    {
    }
}

final class SniperAgentDryRunFakeStatement
{
    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchAll(int $mode): array
    {
        return $this->rows;
    }
}

final class SniperAgentDryRunFakeDb
{
    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function query(string $sql): SniperAgentDryRunFakeStatement
    {
        return new SniperAgentDryRunFakeStatement($this->rows);
    }
}

final class SniperAgentDryRunFakeCompetitorSpy
{
    public static float $marketMin = 0.0;

    public function __construct(int $accountId)
    {
    }

    public function spyProduct(string $title, int $limit): array
    {
        return ['price_analysis' => ['min' => self::$marketMin]];
    }
}

final class SniperAgentDryRunFakeItemService
{
    public static int $updatePriceCalls = 0;

    public function __construct(int $accountId)
    {
    }

    public function updatePrice(string $itemId, float $targetPrice): array
    {
        self::$updatePriceCalls++;
        return ['success' => true];
    }
}

final class SniperAgentDryRunTest extends TestCase
{
    private const SANDBOX_CLASS = 'Tests\\Unit\\Agents\\SniperAgentDryRunSandbox\\SniperAgent';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (class_exists(self::SANDBOX_CLASS, false)) {
            return;
        }

        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Agents/SniperAgent.php');
        if (!is_string($source)) {
            throw new \RuntimeException('Não foi possível carregar SniperAgent.php');
        }

        $source = preg_replace('/^<\?php\s*/', '', $source, 1);
        $source = str_replace(
            'namespace App\\Agents;',
            'namespace Tests\\Unit\\Agents\\SniperAgentDryRunSandbox;',
            (string)$source
        );
        $source = str_replace(
            'use App\\Services\\AI\\SEO\\CompetitorSpy;',
            'use Tests\\Unit\\Agents\\SniperAgentDryRunFakeCompetitorSpy as CompetitorSpy;',
            $source
        );
        $source = str_replace(
            'use App\\Services\\ItemService;',
            'use Tests\\Unit\\Agents\\SniperAgentDryRunFakeItemService as ItemService;',
            $source
        );
        $source = str_replace(
            'class SniperAgent extends BaseAgent',
            'class SniperAgent extends \\Tests\\Unit\\Agents\\SniperAgentDryRunFakeBaseAgent',
            $source
        );

        // O código avaliado é uma cópia fixa do arquivo versionado, com apenas namespace,
        // classe-base e imports trocados para doubles isolados; nenhuma entrada externa é avaliada.
        eval($source);
    }

    protected function setUp(): void
    {
        parent::setUp();
        SniperAgentDryRunFakeItemService::$updatePriceCalls = 0;
    }

    public function testDryRunShotLogsConditionalActionWithoutUpdatingPrice(): void
    {
        SniperAgentDryRunFakeCompetitorSpy::$marketMin = 90.0;
        $agent = $this->dryRunAgent([
            'account_id' => 1335,
            'ml_item_id' => 'MLB_SHOT',
            'title' => 'Item Shot',
            'price' => 100.0,
            'min_price' => 80.0,
            'max_price' => 120.0,
        ]);

        $agent->run();

        $this->assertSame(0, SniperAgentDryRunFakeItemService::$updatePriceCalls);
        $this->assertContains(
            '[DRY-RUN] Sniper Shot: Baixaria de R$ 100 para R$ 89.9 (Min Mercado: R$ 90)',
            $agent->loggedMessages
        );
    }

    public function testDryRunProfitLogsConditionalActionWithoutUpdatingPrice(): void
    {
        SniperAgentDryRunFakeCompetitorSpy::$marketMin = 100.0;
        $agent = $this->dryRunAgent([
            'account_id' => 1335,
            'ml_item_id' => 'MLB_PROFIT',
            'title' => 'Item Profit',
            'price' => 80.0,
            'min_price' => 50.0,
            'max_price' => 120.0,
        ]);

        $agent->run();

        $this->assertSame(0, SniperAgentDryRunFakeItemService::$updatePriceCalls);
        $this->assertContains(
            '[DRY-RUN] Sniper Profit: Subiria de R$ 80 para R$ 99.9 (Acompanhando Mercado)',
            $agent->loggedMessages
        );
    }

    /** @param array<string, mixed> $item */
    private function dryRunAgent(array $item): object
    {
        $class = self::SANDBOX_CLASS;
        $agent = new $class();
        $agent->setFakeDb(new SniperAgentDryRunFakeDb([$item]));
        $agent->setDryRun(true);
        return $agent;
    }
}
