<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

final class FinancialsCashflowViewTest extends TestCase
{
    public function testCashflowTabContainsTimelineTableChartAndDetailModal(): void
    {
        $view = file_get_contents(__DIR__ . '/../../../app/Views/dashboard/financials.php');
        $this->assertIsString($view);

        foreach ([
            'id="cashflow-start"',
            'id="cashflow-horizon"',
            'id="cashflow-mode-planilha"',
            'id="cashflow-mode-grafico"',
            'id="cashflow-table-body"',
            'id="cashflowChart"',
            'id="cashflow-detail-modal"',
        ] as $requiredId) {
            $this->assertStringContainsString($requiredId, $view);
        }

        $this->assertStringContainsString('Valores <strong>N/D</strong> não são tratados como zero confirmado.', $view);
        $this->assertStringContainsString("type: 'line'", $view);
    }

    public function testCashflowMarkupDoesNotBringSupplierOrCogsIntoMpWallet(): void
    {
        $view = file_get_contents(__DIR__ . '/../../../app/Views/dashboard/financials.php');
        $start = strpos($view, '<div class="tab-pane fade" id="tab-cashflow"');
        $end = strpos($view, '<div class="tab-pane fade" id="tab-profitability"', $start ?: 0);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $cashflowMarkup = substr($view, (int)$start, (int)$end - (int)$start);

        $this->assertStringNotContainsString('CMV', $cashflowMarkup);
        $this->assertStringNotContainsString('Fornecedor', $cashflowMarkup);
        $this->assertStringContainsString('Dívida ML/MP', $cashflowMarkup);
    }
}
