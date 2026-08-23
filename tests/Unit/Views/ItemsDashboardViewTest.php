<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class ItemsDashboardViewTest extends TestCase
{
    public function testItemsDashboardScriptHasCspNonce(): void
    {
        $source = $this->viewSource();
        self::assertMatchesRegularExpression(
            '/<script nonce="<\?= defined\(\'CSP_NONCE\'\) \? CSP_NONCE : \'\' \?>" src="\/js\/items-dashboard\.js/',
            $source
        );
        self::assertStringNotContainsString(
            '<script src="/js/items-dashboard.js',
            $source
        );
    }

    public function testItemsGridStaysInsidePageContentAfterHtml5Parse(): void
    {
        if (!defined('CSP_NONCE')) {
            define('CSP_NONCE', 'phpunit-nonce');
        }

        ob_start();
        include dirname(__DIR__, 3) . '/app/Views/dashboard/items.php';
        $content = (string) ob_get_clean();

        $html = '<!DOCTYPE html><html><body>'
            . '<div class="dashboard-wrapper"><main class="main-content">'
            . '<div class="page-content animate-fade-in">' . $content . '</div>'
            . '</main></div></body></html>';

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $pageContent = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " page-content ")]'
        )->item(0);
        self::assertInstanceOf(DOMElement::class, $pageContent);

        $grid = $dom->getElementById('itemsGrid');
        $stats = $dom->getElementById('activeCount');
        $count = $dom->getElementById('itemsCount');
        self::assertInstanceOf(DOMElement::class, $grid);
        self::assertTrue($this->isInside($grid, $pageContent));
        self::assertTrue($this->isInside($stats, $pageContent));
        self::assertTrue($this->isInside($count, $pageContent));
    }

    private function viewSource(): string
    {
        $path = dirname(__DIR__, 3) . '/app/Views/dashboard/items.php';
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        return $contents;
    }

    private function isInside(?DOMNode $node, DOMNode $ancestor): bool
    {
        if ($node === null) {
            return false;
        }
        $current = $node;
        while ($current instanceof DOMNode) {
            if ($current === $ancestor) {
                return true;
            }
            $current = $current->parentNode;
        }

        return false;
    }
}
