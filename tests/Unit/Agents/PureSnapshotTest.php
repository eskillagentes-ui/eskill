<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use App\Services\Agents\PureSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Cobertura do normalizador PureSnapshot.
 *
 * Invariantes sob teste:
 *  - aceita tipos primitivos puros
 *  - recusa objetos que nao sejam AgentResult
 *  - recusa recursos
 *  - recusa floats nao-finitos (NAN/INF)
 *  - recusa strings que representem callables (Cls::method, function_name)
 *  - recusa arrays que representem callables ([$obj, 'method'])
 *  - copia profunda (nao preserva referencias PHP)
 *  - profundidade maxima
 */
final class PureSnapshotTest extends TestCase
{
    public function testPassesScalars(): void
    {
        $this->assertSame(1, PureSnapshot::normalize(1));
        $this->assertSame(1.5, PureSnapshot::normalize(1.5));
        $this->assertSame('hello', PureSnapshot::normalize('hello'));
        $this->assertSame(true, PureSnapshot::normalize(true));
        $this->assertSame(null, PureSnapshot::normalize(null));
    }

    public function testPreservesNestedArrays(): void
    {
        $out = PureSnapshot::normalize(['a' => 1, 'b' => ['c' => 2]]);
        $this->assertSame(['a' => 1, 'b' => ['c' => 2]], $out);
    }

    public function testRejectsNaNAndInfinity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PureSnapshot::normalize(NAN);
    }

    public function testRejectsInfinity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PureSnapshot::normalize(INF);
    }

    public function testRejectsArbitraryObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $obj = new \stdClass();
        $obj->x = 1;
        PureSnapshot::normalize($obj);
    }

    public function testRejectsCallableStringRepresentation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // 'phpversion' is a valid PHP function name -> should be rejected
        PureSnapshot::normalize('phpversion');
    }

    public function testRejectsStaticMethodCallableString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PureSnapshot::normalize('DateTime::createFromFormat');
    }

    public function testRejectsCallableArrayRepresentation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PureSnapshot::normalize([$this, 'fakeMethod']);
    }

    public function testRejectsResource(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            $this->expectException(InvalidArgumentException::class);
            PureSnapshot::normalize($resource);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testNormalizeArrayPassesThrough(): void
    {
        $this->assertSame(
            ['a' => 1, 'b' => 2],
            PureSnapshot::normalizeArray(['a' => 1, 'b' => 2])
        );
    }

    public function testRejectTooDeepNesting(): void
    {
        // Build a chain 33 levels deep > MAX_DEPTH(32)
        $value = ['leaf' => 1];
        for ($i = 0; $i < 33; $i++) {
            $value = ['inner' => $value];
        }
        $this->expectException(InvalidArgumentException::class);
        PureSnapshot::normalize($value);
    }

    public function testRejectsPlainObjectPassedWhereAgentResultExpected(): void
    {
        // PureSnapshot sem allowAgentResult deve rejeitar AgentResult
        $r = \App\Services\Agents\AgentResult::success('noop', 'ok');
        $this->expectException(InvalidArgumentException::class);
        PureSnapshot::normalize($r);  // no flag allowAgentResult -> reject
    }
}
