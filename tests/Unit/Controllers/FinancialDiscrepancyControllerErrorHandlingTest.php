<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

final class FinancialDiscrepancyControllerErrorHandlingTest extends TestCase
{
    public function testInternalExceptionsAreLoggedWithoutBeingSerialized(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Controllers/FinancialDiscrepancyController.php');

        self::assertIsString($source);
        self::assertStringNotContainsString("'error' => \$e->getMessage()", $source);
        self::assertStringContainsString('$this->logError($exception, $context);', $source);
        self::assertStringContainsString("\$this->jsonError('Erro interno do servidor', 500);", $source);
    }
}
