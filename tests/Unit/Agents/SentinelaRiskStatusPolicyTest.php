<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use App\Services\Agents\SentinelaRiskStatusPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Cobertura da politica de coerencia do Sentinela.
 *
 * Invariantes sob teste:
 *  - status=nd exige value=null e pct=null
 *  - pct<0 sempre invalido
 *  - thresholds de reclamacoes/atrasos/cancelamentos (regras com limits absolutos)
 *  - aggregateStatus eleva ao maximo (vermelho > amarelo > verde)
 *  - aggregateStatus sobe a amarelo quando pct>=50 e status nao-confiavel
 *  - aggregateStatus sobe a vermelho quando pct>80 e status nao-confiavel
 */
final class SentinelaRiskStatusPolicyTest extends TestCase
{
    public function testStatusNdRequiresNullValueAndPct(): void
    {
        $this->assertTrue(
            SentinelaRiskStatusPolicy::isConsistent('catalogo', 'nd', null, null, null),
            'status=nd deve exigir value=null e pct=null'
        );
    }

    public function testStatusNdFailsWithValueSet(): void
    {
        $this->assertFalse(
            SentinelaRiskStatusPolicy::isConsistent('catalogo', 'nd', 10.0, 100.0, 10.0),
            'status=nd nao pode coexistir com value preenchido'
        );
    }

    public function testStatusNdFailsWithPctSet(): void
    {
        $this->assertFalse(
            SentinelaRiskStatusPolicy::isConsistent('catalogo', 'nd', null, null, 50.0),
            'status=nd nao pode coexistir com pct preenchido'
        );
    }

    public function testNegativePctAlwaysInvalid(): void
    {
        $this->assertFalse(
            SentinelaRiskStatusPolicy::isConsistent('reputacao', 'verde', 1.0, 100.0, -1.0)
        );
    }

    public function testReclamacoesBelowLimitIsVerde(): void
    {
        // reclamacoes: limit=2.0, pct=10% deve ser verde
        $this->assertTrue(
            SentinelaRiskStatusPolicy::isConsistent(
                'reclamacoes',
                'verde',
                0.2,        // value
                2.0,        // limit
                10.0        // pct = (0.2/2.0)*100
            )
        );
    }

    public function testReclamacoesAtYellowThresholdIsAllowed(): void
    {
        // yellow threshold is 1.0; value=1.0, limit=2.0 -> pct=50
        $this->assertTrue(
            SentinelaRiskStatusPolicy::isConsistent(
                'reclamacoes',
                'amarelo',
                1.0,
                2.0,
                50.0
            )
        );
    }

    public function testReclamacoesInconsistentValueLimitRejected(): void
    {
        $this->assertFalse(
            SentinelaRiskStatusPolicy::isConsistent(
                'reclamacoes',
                'amarelo',
                1.0,        // value
                999.0,      // limit != configured 2.0
                50.0
            ),
            'limit diferente do configurado deve invalidar a politica'
        );
    }

    public function testAggregateStatusGreenWhenNothingElevated(): void
    {
        $risks = [
            ['status' => 'verde', 'value_num' => 0.2, 'limit_num' => 2.0, 'pct_of_limit' => 10.0, 'risk_key' => 'reclamacoes'],
        ];
        $this->assertSame('verde', SentinelaRiskStatusPolicy::aggregateStatus($risks));
    }

    public function testAggregateStatusElevatesToRed(): void
    {
        $risks = [
            ['status' => 'verde', 'value_num' => 0.2, 'limit_num' => 2.0, 'pct_of_limit' => 10.0, 'risk_key' => 'reclamacoes'],
            ['status' => 'vermelho', 'value_num' => 1.8, 'limit_num' => 2.0, 'pct_of_limit' => 90.0, 'risk_key' => 'atrasos'],
        ];
        $this->assertSame('vermelho', SentinelaRiskStatusPolicy::aggregateStatus($risks));
    }

    public function testAggregateStatusElevatesToAmareloWhenAtLeastOneYellow(): void
    {
        $risks = [
            ['status' => 'verde', 'value_num' => 0.2, 'limit_num' => 2.0, 'pct_of_limit' => 10.0, 'risk_key' => 'reclamacoes'],
            ['status' => 'amarelo', 'value_num' => 1.0, 'limit_num' => 2.0, 'pct_of_limit' => 50.0, 'risk_key' => 'atrasos'],
        ];
        $this->assertSame('amarelo', SentinelaRiskStatusPolicy::aggregateStatus($risks));
    }

    public function testAggregateStatusUntrustedPctOver80MapsToRed(): void
    {
        // status inconsistente (status=verde) mas pct=85 -> vermelho por fallback
        $risks = [
            ['status' => 'verde', 'value_num' => 1.7, 'limit_num' => 2.0, 'pct_of_limit' => 85.0, 'risk_key' => 'reclamacoes'],
        ];
        $this->assertSame('vermelho', SentinelaRiskStatusPolicy::aggregateStatus($risks));
    }
}
