<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use PHPUnit\Framework\TestCase;

/**
 * Freshness por fonte: o coletor deve persistir o horário real da coleta
 * (collected_at, epoch — mesmo contrato do Ads) em metrics_meta para
 * vendas, visitas, reputação e perguntas. O dashboard apenas lê esse valor;
 * nenhum collector roda durante a leitura do snapshot.
 */
final class PregaoMetricsCollectorFreshnessContractTest extends TestCase
{
    private function methodWindow(string $source, string $start, string $end): string
    {
        $startPos = strpos($source, $start);
        $endPos = strpos($source, $end, (int) $startPos);
        self::assertIsInt($startPos, "método {$start} deve existir");
        self::assertIsInt($endPos, "método {$end} deve existir");
        return substr($source, $startPos, $endPos - $startPos);
    }

    public function testColetorPersisteCollectedAtRealParaVendasVisitasReputacaoEPerguntas(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoMetricsCollector.php'
        );
        self::assertIsString($source);

        $windows = [
            'vendas' => $this->methodWindow(
                $source,
                'private function collectSales',
                'private function collectVisits'
            ),
            'visitas' => $this->methodWindow(
                $source,
                'private function collectVisits',
                'private function fetchAccountVisits'
            ),
            'reputacao' => $this->methodWindow(
                $source,
                'private function collectReputation',
                'private function collectHealth'
            ),
            'perguntas' => $this->methodWindow(
                $source,
                'private function collectQuestions',
                'private function collectSales'
            ),
        ];

        foreach ($windows as $label => $window) {
            self::assertStringContainsString(
                '$collectedAt = time();',
                $window,
                "{$label}: horário real da coleta deve vir de time(), nunca inventado"
            );
            self::assertStringContainsString(
                "'collected_at' => \$collectedAt",
                $window,
                "{$label}: meta de sucesso deve persistir collected_at"
            );
        }

        foreach (['vendas_hoje', 'visitas_7d', 'reputacao', 'perguntas_7d'] as $metaKey) {
            self::assertMatchesRegularExpression(
                '/\'' . preg_quote($metaKey, '/') . '\'\] = \[\s*\'available\' => true,[^;]*\'collected_at\' => \$collectedAt/s',
                $source,
                "meta {$metaKey} disponível deve carregar collected_at"
            );
        }
    }

    public function testHealthConverteDatetimePersistidoParaEpochNaPropriaSessaoMySql(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoMetricsCollector.php'
        );
        self::assertIsString($source);
        $window = $this->methodWindow(
            $source,
            'private function collectHealth',
            'private function collectQuestions'
        );

        self::assertStringContainsString('UNIX_TIMESTAMP(created_at) AS created_at_epoch', $window);
        self::assertStringContainsString("'collected_at' => (int) \$row['created_at_epoch']", $window);
        self::assertStringNotContainsString("'as_of' =>", $window);
    }
}
