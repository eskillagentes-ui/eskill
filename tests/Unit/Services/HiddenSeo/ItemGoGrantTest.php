<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HiddenSeo;

use App\Exception\UnsafeOperationException;
use App\Services\HiddenSeo\ItemGoGrant;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\HiddenSeo\ItemGoGrant
 */
final class ItemGoGrantTest extends TestCase
{
    public function testIssueOneActivePerAccountAndConsumeOnApply(): void
    {
        $grants = new ItemGoGrant($this->sqlite(), 1_700_000_000);
        $first = $grants->issue(1335, 'MLB1234567890', 900);
        $this->assertSame('MLB1234567890', $first['mlb_id']);
        $this->assertTrue($grants->hasActive(1335, 'MLB1234567890'));

        $second = $grants->issue(1335, 'MLB9999999999', 900);
        $this->assertSame('MLB9999999999', $second['mlb_id']);
        $this->assertFalse($grants->hasActive(1335, 'MLB1234567890'));
        $this->assertTrue($grants->hasActive(1335, 'MLB9999999999'));

        $this->assertTrue($grants->consume(1335, 'MLB9999999999'));
        $this->assertFalse($grants->hasActive(1335, 'MLB9999999999'));
        $this->assertFalse($grants->consume(1335, 'MLB9999999999'));
    }

    public function testExpiredGrantIsNotActive(): void
    {
        $db = $this->sqlite();
        $issued = new ItemGoGrant($db, 1_700_000_000);
        $issued->issue(1335, 'MLB1234567890', 60);

        $later = new ItemGoGrant($db, 1_700_000_000 + 61);
        $this->assertFalse($later->hasActive(1335, 'MLB1234567890'));
    }

    public function testInvalidMlbRejected(): void
    {
        $grants = new ItemGoGrant($this->sqlite(), 1_700_000_000);
        $this->expectException(UnsafeOperationException::class);
        $grants->issue(1335, 'MLB1,MLB2', 60);
    }

    private function sqlite(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $db;
    }
}
