<?php

// Load Composer packages (Guzzle, Monolog, Dotenv, etc.)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Comprehensive autoloader for the SEO system
spl_autoload_register(function ($class) {
    // Define the base directory
    $baseDir = __DIR__;

    // Convert namespace to file path
    $file = $baseDir . '/' . str_replace('\\', '/', $class) . '.php';

    // Handle special cases for files that don't follow the exact namespace pattern
    switch ($class) {
        case 'App\Database':
            $file = $baseDir . '/app/Database.php';
            break;
        case 'App\Services\HiddenAttributesDetector':
            $file = $baseDir . '/app/Services/HiddenAttributesDetector.php';
            break;
        case 'App\Services\AIService':
            $file = $baseDir . '/app/Services/AIService.php';
            break;
        case 'App\Services\MercadoLivreClient':
            $file = $baseDir . '/app/Services/MercadoLivreClient.php';
            break;
        case 'App\Services\StartupValidator':
            $file = $baseDir . '/app/Services/StartupValidator.php';
            break;
        case 'App\Services\RefreshTokenService':
            $file = $baseDir . '/app/Services/RefreshTokenService.php';
            break;
        case 'App\Services\AI\SEO\SEOKillerEngine':
            $file = $baseDir . '/app/Services/AI/SEO/SEOKillerEngine.php';
            break;
        case 'App\Services\ItemService':
            $file = $baseDir . '/app/Services/ItemService.php';
            break;
        case 'App\Services\CategoryService':
            $file = $baseDir . '/app/Services/CategoryService.php';
            break;
        case 'App\Services\AI\Core\AIProviderManager':
            $file = $baseDir . '/app/Services/AI/Core/AIProviderManager.php';
            break;
        // Handle all SEO services
        case 'App\Services\SEO\SynonymExpansionService':
            $file = $baseDir . '/app/Services/SEO/SynonymExpansionService.php';
            break;
        case 'App\Services\SEO\SemanticScoreService':
            $file = $baseDir . '/app/Services/SEO/SemanticScoreService.php';
            break;
        case 'App\Services\SEO\KeywordDistributionService':
            $file = $baseDir . '/app/Services/SEO/KeywordDistributionService.php';
            break;
        case 'App\Services\SEO\KeywordSourceService':
            $file = $baseDir . '/app/Services/SEO/KeywordSourceService.php';
            break;
        case 'App\Services\SEO\DescriptionBuilderService':
            $file = $baseDir . '/app/Services/SEO/DescriptionBuilderService.php';
            break;
        case 'App\Services\SEO\ContextInjectorService':
            $file = $baseDir . '/app/Services/SEO/ContextInjectorService.php';
            break;
        case 'App\Services\SEO\LongTailGeneratorService':
            $file = $baseDir . '/app/Services/SEO/LongTailGeneratorService.php';
            break;
        case 'App\Services\SEO\SearchCoverageService':
            $file = $baseDir . '/app/Services/SEO/SearchCoverageService.php';
            break;
        case 'App\Services\SEO\CompatibilityService':
            $file = $baseDir . '/app/Services/SEO/CompatibilityService.php';
            break;
        case 'App\Services\SEO\SEOStrategiesEngine':
            $file = $baseDir . '/app/Services/SEO/SEOStrategiesEngine.php';
            break;
        case 'App\Services\SEO\SEOMonitoringService':
            $file = $baseDir . '/app/Services/SEO/SEOMonitoringService.php';
            break;
        case 'App\Services\KeywordResearchService':
            $file = $baseDir . '/app/Services/KeywordResearchService.php';
            break;
        default:
            // For other classes, try the standard conversion
            // Convert App\ namespace to app/ directory (lowercase)
            $relativePath = str_replace('\\', '/', $class);
            if (str_starts_with($relativePath, 'App/')) {
                $relativePath = 'app/' . substr($relativePath, 4);
            }
            $file = $baseDir . '/' . $relativePath . '.php';
    }

    if (file_exists($file)) {
        require_once $file;
        return true;
    }

    return false;
});

// Load global function helpers (not classes, so PSR-4 can't load them)
require_once __DIR__ . '/app/Helpers/LogHelper.php';
require_once __DIR__ . '/app/Helpers/CacheHelper.php';
require_once __DIR__ . '/app/Helpers/DateHelper.php';

// Load environment variables after autoloader is registered
if (file_exists(__DIR__ . '/.env')) {
    if (class_exists('Dotenv\Dotenv')) {
        try {
            $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
            $dotenv->load();
        } catch (Exception $e) {
            error_log('Failed to load .env with Dotenv: ' . $e->getMessage());
            // Fallback: load environment variables manually if Dotenv is not available
            $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $_ENV[trim($key)] = trim($value);
                    putenv(trim($key) . '=' . trim($value));
                }
            }
        }
    } else {
        // Fallback: load environment variables manually if Dotenv is not available
        $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
                putenv(trim($key) . '=' . trim($value));
            }
        }
    }
}

// Fuso único do negócio (APP_TIMEZONE=America/Sao_Paulo) para todos os
// scripts CLI/workers/crons que usam este bootstrap — evita divergência de
// timezone entre agentes/crons e a aplicação web (ambos usam TimezoneHelper).
if (class_exists('App\\Helpers\\TimezoneHelper')) {
    \App\Helpers\TimezoneHelper::applyFromEnv();
}
