<?php

declare(strict_types=1);

/**
 * Container bindings — DI service registration
 *
 * All services listed here are registered as singletons: one instance
 * per HTTP request. The Container's auto-wiring resolves typed constructor
 * parameters automatically, so only services that need special construction
 * (e.g. session-aware factories) require explicit callbacks.
 *
 * Pattern:
 *   Zero-arg services     → $c->autoSingleton(ClassName::class)
 *   Account-aware service → $c->singleton(ClassName::class, fn($c) => new ClassName(activeAccountId()))
 *
 * Controllers access these via:
 *   $this->get(UserService::class)
 * or via constructor injection (Router auto-wires from this container).
 */

use App\Core\Container;

// ─── Helper: reads active account ID from session (null-safe) ─────────────────
function activeAccountId(): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $id = \App\Helpers\SessionHelper::getActiveAccountId();
    return ($id !== null && $id > 0) ? $id : null;
}

/**
 * @param Container $c
 */
return static function (Container $c): void {

    // ─── Core infrastructure ────────────────────────────────────────────────
    $c->singleton(\App\Core\EventBus::class, fn() => new \App\Core\EventBus());
    $c->singleton(\App\Services\LogService::class, fn() => new \App\Services\LogService());
    $c->singleton(\App\Services\CacheService::class, fn() => new \App\Services\CacheService());
    $c->singleton(\App\Services\CacheManagerService::class, fn() => new \App\Services\CacheManagerService());
    $c->singleton(\App\Services\AlertService::class, fn() => new \App\Services\AlertService());
    $c->singleton(\App\Services\NotificationService::class, fn() => new \App\Services\NotificationService());
    $c->singleton(\App\Services\EmailService::class, fn() => new \App\Services\EmailService());
    $c->singleton(\App\Services\JobService::class, fn() => new \App\Services\JobService());

    // ─── User & auth ────────────────────────────────────────────────────────
    $c->singleton(\App\Services\UserService::class, fn() => new \App\Services\UserService());
    $c->singleton(\App\Services\AuthService::class, fn() => new \App\Services\AuthService());
    $c->singleton(\App\Services\SecurityService::class, fn() => new \App\Services\SecurityService());
    $c->singleton(\App\Services\AuditService::class, fn() => new \App\Services\AuditService());

    // ─── AI / LLM ──────────────────────────────────────────────────────────
    $c->singleton(\App\Services\LLMService::class, fn() => new \App\Services\LLMService());
    $c->singleton(\App\Services\AIPredictiveAnalyticsService::class, fn() => new \App\Services\AIPredictiveAnalyticsService());

    // ─── Mercado Livre client (session-aware factory) ────────────────────────
    // Not a singleton: each call may need a different accountId.
    // Controllers that need the client should request it via $this->get() and
    // the accountId is automatically sourced from the active session.
    $c->bind(\App\Services\MercadoLivreClient::class, fn() => new \App\Services\MercadoLivreClient(activeAccountId()));

    // ─── Market data & analytics ─────────────────────────────────────────────
    $c->bind(\App\Services\RealMarketDataService::class, fn() => new \App\Services\RealMarketDataService(activeAccountId()));
    $c->singleton(\App\Services\AnalyticsService::class, fn() => new \App\Services\AnalyticsService());
    $c->singleton(\App\Services\ReportService::class, fn() => new \App\Services\ReportService());

    // ─── SEO ────────────────────────────────────────────────────────────────
    $c->singleton(\App\Services\AI\SEO\CompetitorSpy::class, fn() => new \App\Services\AI\SEO\CompetitorSpy(activeAccountId()));

    // ─── Clone system ──────────────────────────────────────────────────────
    $c->singleton(\App\Services\CatalogCloneMonitoringService::class, fn() => new \App\Services\CatalogCloneMonitoringService());

    // ─── Background & monitoring ─────────────────────────────────────────────
    $c->singleton(\App\Services\ErrorMonitoringService::class, fn() => new \App\Services\ErrorMonitoringService());
    $c->singleton(\App\Services\RealTimeNotificationService::class, fn() => new \App\Services\RealTimeNotificationService());
    $c->singleton(\App\Services\AdvancedMonitoringService::class, fn() => new \App\Services\AdvancedMonitoringService());
    $c->singleton(\App\Services\WebhookInboxService::class, fn() => new \App\Services\WebhookInboxService());

    // ─── Dashboard ──────────────────────────────────────────────────────────
    $c->singleton(\App\Services\DashboardService::class, fn() => new \App\Services\DashboardService());
};
