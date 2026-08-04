<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentRuntimeReadGateway;
use App\Services\Agents\AgentRuntimeReadGatewayInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/** @covers \App\Services\Agents\AgentRuntimeReadGateway */
final class AgentRuntimeReadGatewayTest extends TestCase
{
    public function testExpoeSomenteGatewayEstreitoReadOnlyFinal(): void
    {
        $reflection = new ReflectionClass(AgentRuntimeReadGateway::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->implementsInterface(AgentRuntimeReadGatewayInterface::class));
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(1, $constructor->getNumberOfParameters());
        self::assertSame(PDO::class, $constructor->getParameters()[0]->getType()?->getName());
        self::assertTrue($constructor->getParameters()[0]->allowsNull());
    }

    public function testItemRetornaProvenanceExataComProvaLocalDeNaoDuplicidade(): void
    {
        $pdo = $this->database();
        $this->seedAccountAndItem($pdo, 10, '123456', 'MLB100', '123456');

        self::assertSame([
            'account_id' => 10,
            'mlb_id' => 'MLB100',
            'seller_id' => '123456',
            'title' => 'Produto local',
            'duplicate' => false,
        ], (new AgentRuntimeReadGateway($pdo))->item(10, 'MLB100'));
    }

    public function testItemMarcaDuplicidadeComProvaEmClonedItems(): void
    {
        $pdo = $this->database();
        $this->seedAccountAndItem($pdo, 10, '123456', 'MLB100', '123456');
        $pdo->exec("INSERT INTO cloned_items (source_account_id, source_item_id, status) VALUES (10, 'MLB100', 'created')");

        self::assertTrue((new AgentRuntimeReadGateway($pdo))->item(10, 'MLB100')['duplicate']);
    }

    public function testItemFalhaFechadoParaContaDivergente(): void
    {
        $pdo = $this->database();
        $this->seedAccountAndItem($pdo, 10, '123456', 'MLB100', '123456', 11);

        $this->expectException(RuntimeException::class);
        (new AgentRuntimeReadGateway($pdo))->item(10, 'MLB100');
    }

    public function testItemFalhaFechadoParaSellerDivergente(): void
    {
        $pdo = $this->database();
        $this->seedAccountAndItem($pdo, 10, '123456', 'MLB100', '999999');

        $this->expectException(RuntimeException::class);
        (new AgentRuntimeReadGateway($pdo))->item(10, 'MLB100');
    }

    public function testItemFalhaFechadoSemTabelaDeProvaDeDuplicidade(): void
    {
        $pdo = $this->database(false);
        $this->seedAccountAndItem($pdo, 10, '123456', 'MLB100', '123456');

        $this->expectException(\Throwable::class);
        (new AgentRuntimeReadGateway($pdo))->item(10, 'MLB100');
    }

    public function testFonteItemNaoContemClientesCircuitosRefreshNemEscritas(): void
    {
        $reflection = new ReflectionClass(AgentRuntimeReadGateway::class);
        $path = $reflection->getFileName();
        self::assertIsString($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        foreach (['ItemService', 'MercadoLivreClient', 'circuit', 'refresh', 'allow_local_cache'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }

        $method = $reflection->getMethod('item');
        $lines = file($path);
        self::assertIsArray($lines);
        $methodSource = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        self::assertSame(3, substr_count(strtoupper($methodSource), 'SELECT'));
        self::assertDoesNotMatchRegularExpression(
            '/\b(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|CALL)\b/i',
            $methodSource
        );
    }

    private function database(bool $withCloneTable = true): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE ml_accounts (id INTEGER PRIMARY KEY, ml_user_id TEXT, status TEXT)');
        $pdo->exec('CREATE TABLE ml_items (id TEXT PRIMARY KEY, account_id INTEGER, title TEXT, raw_data TEXT)');
        if ($withCloneTable) {
            $pdo->exec('CREATE TABLE cloned_items (source_account_id INTEGER, source_item_id TEXT, status TEXT)');
        }
        return $pdo;
    }

    private function seedAccountAndItem(
        PDO $pdo,
        int $accountId,
        string $mlUserId,
        string $mlbId,
        string $sellerId,
        ?int $itemAccountId = null
    ): void {
        $account = $pdo->prepare('INSERT INTO ml_accounts (id, ml_user_id, status) VALUES (?, ?, ?)');
        $account->execute([$accountId, $mlUserId, 'active']);
        $item = $pdo->prepare('INSERT INTO ml_items (id, account_id, title, raw_data) VALUES (?, ?, ?, ?)');
        $item->execute([
            $mlbId,
            $itemAccountId ?? $accountId,
            'Produto local',
            json_encode(['seller_id' => $sellerId]),
        ]);
    }
}
