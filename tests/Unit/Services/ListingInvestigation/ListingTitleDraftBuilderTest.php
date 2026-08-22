<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ListingInvestigation;

use App\Services\ListingInvestigation\ListingInvestigationService;
use App\Services\ListingInvestigation\ListingTitleDraftBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\ListingInvestigation\ListingTitleDraftBuilder
 * @covers \App\Services\ListingInvestigation\ListingInvestigationService::rulesDraft
 * @covers \App\Services\ListingInvestigation\ListingInvestigationService::refreshOpenDrafts
 */
final class ListingTitleDraftBuilderTest extends TestCase
{
    public function testStuffedModelIsIgnoredAndNaoTemNeverAppears(): void
    {
        $builder = new ListingTitleDraftBuilder();
        $item = [
            'title' => 'Espelho Para Colar Guarda Roupas 100x40 Corpo Inteiro Closet Espelho',
            'domain_id' => 'MLB-MIRRORS',
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'Espelho'],
                ['id' => 'MODEL', 'value_name' => 'espelho quarto, espelho sala, espelho parede, espelho sala de jantar, espelho corpo inteiro, espelho grande'],
                ['id' => 'VOLTAGE', 'value_name' => 'nao tem'],
                ['id' => 'HEIGHT', 'value_name' => '1 m'],
                ['id' => 'WIDTH', 'value_name' => '40 cm'],
            ],
        ];

        $svc = new ListingInvestigationService($this->sqliteCatalog());
        self::assertNull($svc->realModel($item));
        self::assertTrue($svc->looksLikeLongTail((string) $svc->attributeValue($item, 'MODEL')));

        $draft = $builder->build($item, [], $svc->realModel($item));
        self::assertSame('Espelho 100x40', $draft['draft_title']);
        self::assertStringNotContainsString('nao tem', mb_strtolower($draft['draft_title']));
        self::assertStringNotContainsString('não tem', mb_strtolower($draft['draft_title']));
        self::assertStringNotContainsString('Para Colar', $draft['draft_title']);
        self::assertStringNotContainsString('espelho quarto', mb_strtolower($draft['draft_title']));
    }

    public function testDoesNotInventBrandAndUsesRealModelPlusColor(): void
    {
        $builder = new ListingTitleDraftBuilder();
        $item = [
            'title' => 'Grade Portão Segurança Preto Ajustável Sob Pressão Preto',
            'domain_id' => 'MLB-DOOR_AND_STAIR_SAFETY_FENCES',
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'Facilyty'],
                ['id' => 'MODEL', 'value_name' => 'Aramado'],
                ['id' => 'COLOR', 'value_name' => 'Preto'],
            ],
        ];
        $draft = $builder->build($item, [], 'Aramado');
        self::assertSame('Grade de segurança Facilyty Aramado Preto', $draft['draft_title']);
    }

    public function testAutoPartUsesPartNumberWhenModelIsStuffed(): void
    {
        $builder = new ListingTitleDraftBuilder();
        $item = [
            'title' => 'Bagageiro Churrasqueira Biz 100 125 1998 A 2005 Maciço Preto Preto',
            'domain_id' => 'MLB-MOTORCYCLE_LUGGAGE_RACKS',
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'AWA'],
                ['id' => 'MODEL', 'value_name' => 'Suporte Bauleto Traseiro Grelha Reforçado Ferro Chapa Aço Transporte Carga B2B Proos Awa 2008 Honda Biz+'],
                ['id' => 'PART_NUMBER', 'value_name' => 'AWA-2008'],
                ['id' => 'COLOR', 'value_name' => 'Preto'],
            ],
        ];
        $svc = new ListingInvestigationService($this->sqliteCatalog());
        self::assertNull($svc->realModel($item));
        $draft = $builder->build($item, [['code' => 'not_premium', 'label' => 'sem Premium']], null);
        self::assertSame('Bagageiro AWA AWA-2008 Preto', $draft['draft_title']);
        self::assertStringNotContainsString('Biz', $draft['draft_title']);
        self::assertStringNotContainsString('Titan', $draft['draft_title']);
        self::assertStringNotContainsString('nao tem', mb_strtolower($draft['draft_title']));
    }

    public function testRefreshRewritesExistingNaoTemDraftWithoutMlWrite(): void
    {
        $db = $this->sqliteCatalog();
        $this->insertItem($db, 1335, 'MLB-MIRROR', 'Espelho Para Colar Guarda Roupas 100x40', [
            'pictures' => [1],
            'shipping' => ['free_shipping' => false],
            'listing_type_id' => 'gold_special',
            'available_quantity' => 2,
            'domain_id' => 'MLB-MIRRORS',
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'Espelho'],
                ['id' => 'MODEL', 'value_name' => 'espelho quarto, espelho sala, espelho parede'],
                ['id' => 'VOLTAGE', 'value_name' => 'nao tem'],
                ['id' => 'HEIGHT', 'value_name' => '1 m'],
                ['id' => 'WIDTH', 'value_name' => '40 cm'],
            ],
        ], 2);
        $svc = new ListingInvestigationService($db);
        $svc->ensureTable();
        $db->exec(
            "INSERT INTO listing_investigations (account_id, mlb_id, status, blockers, draft_title, draft_notes, model_used)
             VALUES (1335, 'MLB-MIRROR', 'open', '[]', 'Para Colar Guarda Espelho nao tem', 'old', 'rules')"
        );

        $result = $svc->run(1335, 50);
        self::assertFalse($result['ml_write']);
        self::assertTrue($result['apply_blocked']);
        self::assertSame(1, $result['refreshed_count']);
        self::assertSame('Espelho 100x40', $result['refreshed'][0]['draft_title']);
        self::assertStringNotContainsString('nao tem', mb_strtolower((string) $result['refreshed'][0]['draft_title']));
        $stored = (string) $db->query("SELECT draft_title FROM listing_investigations WHERE mlb_id='MLB-MIRROR'")->fetchColumn();
        self::assertSame('Espelho 100x40', $stored);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM listing_investigations')->fetchColumn());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertItem(PDO $db, int $accountId, string $mlb, string $title, array $data, int $qty): void
    {
        $stmt = $db->prepare(
            'INSERT INTO items (account_id, ml_item_id, title, status, available_quantity, sold_quantity, catalog_product_id, data)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([
            $accountId,
            $mlb,
            $title,
            'active',
            $qty,
            $data['catalog_product_id'] ?? null,
            json_encode($data, JSON_THROW_ON_ERROR),
        ]);
    }

    private function sqliteCatalog(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE items (
                account_id INTEGER,
                ml_item_id TEXT,
                title TEXT,
                status TEXT,
                available_quantity INTEGER,
                sold_quantity INTEGER,
                catalog_product_id TEXT,
                data TEXT
            )'
        );

        return $db;
    }
}
