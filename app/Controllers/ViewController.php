<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\UserService;

class ViewController
{
    private UserService $userService;

    public function __construct()
    {
        $this->userService = new UserService();
    }

    /**
     * Render a view file.
     *
     * @param string $viewName The relative path to the view in app/Views, without extension.
     */
    public function render(string $viewName): void
    {
        $viewPath = __DIR__ . '/../Views/' . $viewName . '.php';

        if (!file_exists($viewPath)) {
            // Fallback for some paths that might be directly in public or elsewhere, though recommended is app/Views
            // Checking if it's a legacy path pattern
            $legacyPath = __DIR__ . '/../../public/' . $viewName;
            if (file_exists($legacyPath)) {
                require $legacyPath;
                return;
            }

            http_response_code(404);
            if (file_exists(__DIR__ . '/../Views/errors/404.php')) {
                require __DIR__ . '/../Views/errors/404.php';
            } else {
                echo "View not found: " . htmlspecialchars($viewName);
            }
            return;
        }

        $shouldUseLayout = $this->shouldUseModernLayout($viewName);

        if ($shouldUseLayout) {
            $this->ensureAuthenticated();
        }

        $pageTitle = null;
        $activePage = null;
        $useModernLayout = null;
        // CSP_NONCE is a PHP constant defined in public/index.php — accessible from any scope.
        $cspNonce = defined('CSP_NONCE') ? CSP_NONCE : (($GLOBALS['cspNonce'] ?: null) ?? ($_SESSION['csp_nonce'] ?? ''));

        ob_start();
        require $viewPath;
        $content = ob_get_clean();

        if ($useModernLayout === false) {
            echo $content;
            return;
        }

        if ($useModernLayout === true) {
            $shouldUseLayout = true;
        }

        if ($shouldUseLayout) {
            if (empty($pageTitle)) {
                $pageTitle = $this->guessPageTitle($viewName);
            }

            require __DIR__ . '/../Views/layouts/modern/app.php';
            return;
        }

        echo $content;
    }

    private function shouldUseModernLayout(string $viewName): bool
    {
        return str_starts_with($viewName, 'dashboard/');
    }

    private function guessPageTitle(string $viewName): string
    {
        $basename = basename($viewName);
        $formatted = str_replace(['-', '_'], ' ', $basename);
        return ucwords($formatted);
    }

    private function ensureAuthenticated(): void
    {
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }
    }

    // Explicit methods for common views to be used in Router definitions

    public function dashboard(): void
    {
        $this->render('dashboard/index');
    }

    public function analysis(): void
    {
        $this->render('dashboard/analysis');
    }

    public function categories(): void
    {
        $this->render('dashboard/categories');
    }

    public function help(): void
    {
        $this->render('dashboard/help');
    }

    public function activities(): void
    {
        $this->render('dashboard/activities');
    }

    public function apiTokens(): void
    {
        $this->render('dashboard/api-tokens');
    }

    public function ean(): void
    {
        $this->render('dashboard/ean');
    }

    public function eanAdmin(): void
    {
        $this->render('dashboard/ean-admin');
    }

    public function securityDashboard(): void
    {
        $this->render('security/dashboard');
    }

    public function agents(): void
    {
        $this->render('dashboard/agents');
    }

    public function opportunities(): void
    {
        $this->render('dashboard/opportunities');
    }

    public function research(): void
    {
        $this->render('dashboard/research');
    }

    public function seoKiller(): void
    {
        $this->render('dashboard/seo-killer');
    }

    public function techSheet(): void
    {
        $this->render('dashboard/tech-sheet/index');
    }

    /**
     * @deprecated Consolidated into SEO Killer. Redirects to /dashboard/seo-killer
     */
    public function seoIntelligence(): void
    {
        header('Location: /dashboard/seo-killer', true, 301);
        exit;
    }

    /**
     * @deprecated Consolidated into SEO Killer. Redirects to /dashboard/seo-killer
     */
    public function seoIntelligenceDetail(): void
    {
        header('Location: /dashboard/seo-killer', true, 301);
        exit;
    }

    /**
     * @deprecated Consolidated into SEO Killer. Redirects to /dashboard/seo-killer
     */
    public function seoDashboard(): void
    {
        header('Location: /dashboard/seo-killer', true, 301);
        exit;
    }

    public function accounts(): void
    {
        $this->render('dashboard/accounts');
    }

    public function pricingDashboard(): void
    {
        $this->render('pricing/dashboard');
    }

    public function pricingHistory(): void
    {
        $this->render('pricing/history');
    }

    public function tokens(): void
    {
        $this->render('dashboard/tokens');
    }

    public function alerts(): void
    {
        $this->render('dashboard/alerts');
    }

    public function monitoring(): void
    {
        $this->render('dashboard/monitoring');
    }

    public function notifications(): void
    {
        $this->render('dashboard/notifications');
    }

    public function search(): void
    {
        $this->render('dashboard/search');
    }

    public function statistics(): void
    {
        $this->render('dashboard/statistics');
    }
}
