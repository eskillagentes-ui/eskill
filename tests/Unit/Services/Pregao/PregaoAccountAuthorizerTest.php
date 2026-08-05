<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoAccountAuthorizer;
use PHPUnit\Framework\TestCase;

final class PregaoAccountAuthorizerTest extends TestCase
{
    /** @var list<array{id:int, nickname:string}> */
    private array $accounts = [
        ['id' => 1335, 'nickname' => 'FACILYTY'],
        ['id' => 2000, 'nickname' => 'SEGUNDA'],
    ];

    public function testPermiteContaSolicitadaQuePertenceAoUsuario(): void
    {
        $authorizer = new PregaoAccountAuthorizer();

        self::assertSame(1335, $authorizer->resolve(1335, 2000, $this->accounts));
    }

    public function testRejeitaContaSolicitadaDeOutroUsuarioSemFallbackSilencioso(): void
    {
        $authorizer = new PregaoAccountAuthorizer();

        self::assertNull($authorizer->resolve(9999, 1335, $this->accounts));
    }

    public function testUsaContaAtivaSomenteQuandoElaPertenceAoUsuario(): void
    {
        $authorizer = new PregaoAccountAuthorizer();

        self::assertSame(2000, $authorizer->resolve(null, 2000, $this->accounts));
        self::assertNull($authorizer->resolve(null, 9999, $this->accounts));
    }

    public function testUsaPrimeiraContaAutorizadaQuandoSessaoNaoTemContaAtiva(): void
    {
        $authorizer = new PregaoAccountAuthorizer();

        self::assertSame(1335, $authorizer->resolve(null, null, $this->accounts));
    }

    public function testFalhaFechadoComListaVaziaOuEntradaMalformada(): void
    {
        $authorizer = new PregaoAccountAuthorizer();

        self::assertNull($authorizer->resolve(1335, null, []));
        self::assertNull($authorizer->resolve(1335, null, [['nickname' => 'sem-id']]));
    }
}
