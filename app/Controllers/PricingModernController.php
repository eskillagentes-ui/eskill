<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\UserService;

/**
 * Precificador no layout moderno (evita shell legado de layouts/app.php).
 * Rota canônica: /dashboard/pricing-v2 — /dashboard/pricing redireciona via JS até chown do ViewController.
 */
class PricingModernController
{
    private UserService $userService;

    public function __construct()
    {
        $this->userService = new UserService();
    }

    public function index(): void
    {
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        require __DIR__ . '/../Views/pricing/dashboard-modern.php';
    }
}
