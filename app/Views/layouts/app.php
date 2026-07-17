<?php

declare(strict_types=1);

/**
 * Layout Principal da Aplicação
 *
 * Template profissional com sidebar, navbar superior e footer
 * Variáveis esperadas:
 * - $pageTitle: título da página
 * - $pageDescription: descrição da página (opcional)
 * - $activePage: página ativa para highlight no menu
 * - $content: conteúdo da página
 */

use App\Services\UserService;
use App\Helpers\SessionHelper;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$cspNonce = $_SESSION['csp_nonce'] ?? '';

$userService = new UserService();
$currentUser = $userService->getCurrentUser();
$userAccounts = $currentUser ? SessionHelper::getUserAccounts() : [];
$activeAccountId = SessionHelper::getActiveAccountId();
$activeAccount = array_filter($userAccounts, fn($a) => $a['id'] == $activeAccountId);
$activeAccount = reset($activeAccount) ?: null;

// Verificar notificações não lidas
$unreadNotifications = 0;
try {
    $db = \App\Database::getInstance();
    if ($currentUser) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
        $stmt->execute([$currentUser['id']]);
        $unreadNotifications = (int) $stmt->fetchColumn();
    }
} catch (\Exception $e) {
    // Log error but don't break the layout — notification count is non-critical
    error_log('[Layout] Failed to fetch notification count: ' . $e->getMessage());
}

// Definir página ativa
$currentPath = $_SERVER['REQUEST_URI'] ?? '/dashboard';
$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <meta name="description" content="<?= htmlspecialchars($pageDescription ?? 'Sistema de Gestão Mercado Livre') ?>">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> - ML Manager</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/icons/icon-32x32.png">
    <link rel="apple-touch-icon" href="/icons/icon-192x192.png">

    <!-- PWA Manifest -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#667eea">

    <!-- Preconnect -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">

    <!-- Custom Theme CSS -->
    <link rel="stylesheet" href="/css/theme.css">
    <link rel="stylesheet" href="/css/style.css">

    <!-- Chart.js -->
    <script nonce="<?= $cspNonce ?>" src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <!-- API Client (loaded early so view scripts can use requestJson) -->
    <script nonce="<?= $cspNonce ?>" src="/js/csrf-helper.js"></script>
    <script nonce="<?= $cspNonce ?>" src="/js/api-client.js?v=<?= @filemtime(__DIR__ . '/../../../public/js/api-client.js') ?: time() ?>"></script>
    <script nonce="<?= $cspNonce ?>" src="/js/ml-integration-preflight.js?v=<?= @filemtime(__DIR__ . '/../../../public/js/ml-integration-preflight.js') ?: time() ?>"></script>
    <script nonce="<?= $cspNonce ?>">
        async function requestJson(url, options = {}) {
            if (window.ApiClient) return window.ApiClient.request(url, options);
            const resp = await fetch(url, {
                credentials: 'include',
                ...options
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        }
    </script>

    <style>
        :root {
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 70px;
            --navbar-height: 60px;
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --ml-yellow: #FFE600;
            --ml-blue: #3483FA;
        }

        body {
            min-height: 100vh;
            background: #f8f9fa;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--primary-gradient);
            z-index: 1040;
            transition: all 0.3s ease;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar-brand {
            padding: 1rem;
            display: flex;
            align-items: center;
            color: white;
            font-weight: 700;
            font-size: 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            min-height: var(--navbar-height);
        }

        .sidebar-brand i {
            font-size: 1.5rem;
            margin-right: 0.75rem;
        }

        .sidebar.collapsed .sidebar-brand span,
        .sidebar.collapsed .nav-text,
        .sidebar.collapsed .menu-header {
            display: none;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .menu-header {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.75rem 1rem 0.5rem;
            margin-top: 0.5rem;
        }

        .nav-item {
            margin: 2px 8px;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.8) !important;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.15);
            color: white !important;
        }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            color: white !important;
            font-weight: 500;
        }

        .nav-link i {
            width: 24px;
            font-size: 1.1rem;
            margin-right: 0.75rem;
            text-align: center;
        }

        .sidebar.collapsed .nav-link {
            justify-content: center;
            padding: 0.75rem;
        }

        .sidebar.collapsed .nav-link i {
            margin-right: 0;
            font-size: 1.25rem;
        }

        /* Main Content */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .sidebar.collapsed+.main-wrapper {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* Top Navbar */
        .top-navbar {
            height: var(--navbar-height);
            background: white;
            border-bottom: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 1030;
            padding: 0 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #333;
            margin: 0;
        }

        .navbar-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Account Switcher */
        .account-switcher {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }

        .account-switcher:hover {
            background: #e9ecef;
        }

        .account-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--ml-blue);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
        }

        /* Notifications */
        .notification-bell {
            position: relative;
            padding: 0.5rem;
            cursor: pointer;
        }

        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: #dc3545;
            color: white;
            font-size: 0.65rem;
            padding: 0.15rem 0.4rem;
            border-radius: 10px;
            font-weight: 600;
        }

        /* Main Content Area */
        .main-content {
            padding: 1.5rem;
            min-height: calc(100vh - var(--navbar-height) - 60px);
        }

        /* Footer */
        .main-footer {
            background: white;
            border-top: 1px solid #e9ecef;
            padding: 1rem 1.5rem;
            color: #6c757d;
            font-size: 0.875rem;
        }

        /* Cards */
        .card {
            border: none;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            border-radius: 12px;
        }

        .card-header {
            background: white;
            border-bottom: 1px solid #f0f0f0;
            padding: 1rem 1.25rem;
            font-weight: 600;
        }

        /* Stats Cards */
        .stat-card {
            border-radius: 12px;
            padding: 1.25rem;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .stat-card.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-card.success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }

        .stat-card.warning {
            background: linear-gradient(135deg, #F2994A 0%, #F2C94C 100%);
        }

        .stat-card.danger {
            background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
        }

        .stat-card.info {
            background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
            margin-top: 0.25rem;
        }

        .stat-icon {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 3rem;
            opacity: 0.3;
        }

        /* Submenu */
        .nav-submenu {
            margin-left: 2.5rem;
            border-left: 2px solid rgba(255, 255, 255, 0.2);
            padding-left: 0.5rem;
        }

        .nav-submenu .nav-link {
            font-size: 0.85rem;
            padding: 0.4rem 0.75rem;
        }

        .collapse-toggle::after {
            content: '\F282';
            font-family: 'bootstrap-icons';
            margin-left: auto;
            transition: transform 0.2s;
        }

        .collapse-toggle[aria-expanded="true"]::after {
            transform: rotate(180deg);
        }

        /* Responsive */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-wrapper {
                margin-left: 0;
            }

            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 1035;
                display: none;
            }

            .sidebar-overlay.show {
                display: block;
            }
        }

        /* Dark Mode */
        [data-bs-theme="dark"] {
            --bs-body-bg: #1a1d21;
            --bs-body-color: #e9ecef;
        }

        [data-bs-theme="dark"] .top-navbar,
        [data-bs-theme="dark"] .main-footer,
        [data-bs-theme="dark"] .card {
            background: #212529;
            border-color: #343a40;
        }

        [data-bs-theme="dark"] .card-header {
            background: #212529;
            border-color: #343a40;
        }

        /* Print */
        @media print {

            .sidebar,
            .top-navbar,
            .main-footer,
            .no-print {
                display: none !important;
            }

            .main-wrapper {
                margin-left: 0 !important;
            }
        }
    </style>

    <?php if (isset($extraHead)) echo $extraHead; ?>
</head>

<body>
    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <i class="bi bi-shop-window"></i>
            <span>ML Manager</span>
        </div>

        <nav class="sidebar-nav">
            <ul class="nav flex-column">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link <?= $currentPath === '/dashboard' || $currentPath === '/dashboard/' ? 'active' : '' ?>" href="/dashboard">
                        <i class="bi bi-speedometer2"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/analytics') !== false ? 'active' : '' ?>" href="/dashboard/analytics">
                        <i class="bi bi-bar-chart-line"></i>
                        <span class="nav-text">Analytics BI</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/ai-center') !== false ? 'active' : '' ?>" href="/dashboard/ai-center">
                        <i class="bi bi-cpu"></i>
                        <span class="nav-text">Central de IA</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/account-health') !== false ? 'active' : '' ?>" href="/dashboard/account-health">
                        <i class="bi bi-heart-pulse"></i>
                        <span class="nav-text">Diagnóstico Conta</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/listing-visibility') !== false ? 'active' : '' ?>" href="/dashboard/listing-visibility">
                        <i class="bi bi-search-heart"></i>
                        <span class="nav-text">Visibilidade ML</span>
                    </a>
                </li>

                <!-- Menu: Vendas -->
                <li class="menu-header">Vendas</li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/orders') !== false ? 'active' : '' ?>" href="/dashboard/orders">
                        <i class="bi bi-box-seam"></i>
                        <span class="nav-text">Pedidos</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/customers') !== false ? 'active' : '' ?>" href="/dashboard/customers">
                        <i class="bi bi-people"></i>
                        <span class="nav-text">Clientes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/messages') !== false ? 'active' : '' ?>" href="/dashboard/messages">
                        <i class="bi bi-chat-dots"></i>
                        <span class="nav-text">Mensagens</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/questions') !== false ? 'active' : '' ?>" href="/dashboard/questions">
                        <i class="bi bi-question-circle"></i>
                        <span class="nav-text">Perguntas</span>
                    </a>
                </li>

                <!-- Menu: Catálogo -->
                <li class="menu-header">Catálogo</li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/items') !== false && strpos($currentPath, '/bulk') === false ? 'active' : '' ?>" href="/dashboard/items">
                        <i class="bi bi-tags"></i>
                        <span class="nav-text">Anúncios</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/items/bulk') !== false ? 'active' : '' ?>" href="/dashboard/items/bulk">
                        <i class="bi bi-pencil-square"></i>
                        <span class="nav-text">Editor em Lote</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/catalog/clone') !== false ? 'active' : '' ?>" href="/dashboard/catalog/clone">
                        <i class="bi bi-files"></i>
                        <span class="nav-text">Clonador</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/categories') !== false ? 'active' : '' ?>" href="/dashboard/categories">
                        <i class="bi bi-diagram-3"></i>
                        <span class="nav-text">Categorias</span>
                    </a>
                </li>

                <!-- Menu: SEO & Otimização -->
                <li class="menu-header">SEO & Otimização</li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/seo-killer') !== false ? 'active' : '' ?>" href="/dashboard/seo-killer">
                        <i class="bi bi-lightning-charge"></i>
                        <span class="nav-text">SEO Killer</span>
                        <span class="badge bg-warning text-dark ms-auto" style="font-size: 0.6rem;">PRO</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/tech-sheet') !== false || strpos($currentPath, '/dashboard/seo/ficha-tecnica') !== false ? 'active' : '' ?>" href="/dashboard/tech-sheet">
                        <i class="bi bi-list-check"></i>
                        <span class="nav-text">Ficha Técnica</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/quality') !== false ? 'active' : '' ?>" href="/dashboard/quality">
                        <i class="bi bi-shield-check"></i>
                        <span class="nav-text">Validação Anúncios</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/dashboard/seo-killer#ai-insights">
                        <i class="bi bi-magic"></i>
                        <span class="nav-text">Insights SEO (IA)</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/research') !== false ? 'active' : '' ?>" href="/research">
                        <i class="bi bi-zoom-in"></i>
                        <span class="nav-text">Deep Research</span>
                    </a>
                </li>

                <!-- Menu: Marketing -->
                <li class="menu-header">Marketing</li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/ads') !== false ? 'active' : '' ?>" href="/dashboard/ads">
                        <i class="bi bi-badge-ad"></i>
                        <span class="nav-text">Mercado Ads</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/marketing/promotions') !== false ? 'active' : '' ?>" href="/dashboard/marketing/promotions">
                        <i class="bi bi-percent"></i>
                        <span class="nav-text">Promoções</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/competitors') !== false || strpos($currentPath, '/dashboard/competitor-monitor') !== false ? 'active' : '' ?>" href="/dashboard/competitors">
                        <i class="bi bi-binoculars"></i>
                        <span class="nav-text">Concorrentes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/brand-analysis') !== false ? 'active' : '' ?>" href="/brand-analysis">
                        <i class="bi bi-award"></i>
                        <span class="nav-text">Análise de Marca</span>
                    </a>
                </li>

                <!-- Menu: Logística -->
                <li class="menu-header">Logística</li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/shipping') !== false ? 'active' : '' ?>" href="/dashboard/shipping">
                        <i class="bi bi-box-arrow-up-right"></i>
                        <span class="nav-text">Expedição</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/picking') !== false ? 'active' : '' ?>" href="/dashboard/picking">
                        <i class="bi bi-clipboard-check"></i>
                        <span class="nav-text">Picking</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/logistics/flex') !== false ? 'active' : '' ?>" href="/dashboard/logistics/flex">
                        <i class="bi bi-bicycle"></i>
                        <span class="nav-text">Flex</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/logistics/full') !== false ? 'active' : '' ?>" href="/dashboard/logistics/full">
                        <i class="bi bi-building"></i>
                        <span class="nav-text">Full</span>
                    </a>
                </li>

                <!-- Menu: Financeiro -->
                <li class="menu-header">Financeiro</li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/pricing') !== false || strpos($currentPath, '/precificador') !== false ? 'active' : '' ?>" href="/dashboard/pricing">
                        <i class="bi bi-calculator"></i>
                        <span class="nav-text">Precificador</span>
                        <span class="badge bg-success ms-auto" style="font-size: 0.6rem;">NEW</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/financials') !== false && strpos($currentPath, '/conciliation') === false ? 'active' : '' ?>" href="/dashboard/financials">
                        <i class="bi bi-file-earmark-bar-graph"></i>
                        <span class="nav-text">DRE / P&L</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/financials/conciliation') !== false ? 'active' : '' ?>" href="/dashboard/financials/conciliation">
                        <i class="bi bi-bank"></i>
                        <span class="nav-text">Conciliação</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/reports') !== false ? 'active' : '' ?>" href="/dashboard/reports">
                        <i class="bi bi-file-text"></i>
                        <span class="nav-text">Relatórios</span>
                    </a>
                </li>

                <!-- Menu: Atendimento -->
                <li class="menu-header">Atendimento</li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/claims') !== false ? 'active' : '' ?>" href="/dashboard/claims">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span class="nav-text">Reclamações</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/returns') !== false ? 'active' : '' ?>" href="/dashboard/returns">
                        <i class="bi bi-arrow-return-left"></i>
                        <span class="nav-text">Devoluções</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/whatsapp') !== false ? 'active' : '' ?>" href="/dashboard/whatsapp">
                        <i class="bi bi-whatsapp"></i>
                        <span class="nav-text">WhatsApp</span>
                    </a>
                </li>

                <!-- Menu: Integrações -->
                <li class="menu-header">Integrações</li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/accounts') !== false ? 'active' : '' ?>" href="/dashboard/accounts">
                        <i class="bi bi-person-badge"></i>
                        <span class="nav-text">Contas ML</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/shopee') !== false ? 'active' : '' ?>" href="/dashboard/shopee">
                        <i class="bi bi-shop"></i>
                        <span class="nav-text">Shopee</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/ean') !== false ? 'active' : '' ?>" href="/dashboard/ean">
                        <i class="bi bi-upc-scan"></i>
                        <span class="nav-text">Gestão EAN</span>
                    </a>
                </li>

                <!-- Menu: Sistema -->
                <li class="menu-header">Sistema</li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/settings') !== false && strpos($currentPath, '/users') === false ? 'active' : '' ?>" href="/dashboard/settings">
                        <i class="bi bi-sliders"></i>
                        <span class="nav-text">Configurações</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/settings/users') !== false ? 'active' : '' ?>" href="/dashboard/settings/users">
                        <i class="bi bi-people-fill"></i>
                        <span class="nav-text">Usuários</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/logs') !== false ? 'active' : '' ?>" href="/dashboard/logs">
                        <i class="bi bi-journal-code"></i>
                        <span class="nav-text">Logs</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/cache') !== false ? 'active' : '' ?>" href="/dashboard/cache">
                        <i class="bi bi-hdd-stack"></i>
                        <span class="nav-text">Cache</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/health') !== false ? 'active' : '' ?>" href="/dashboard/health">
                        <i class="bi bi-heart-pulse"></i>
                        <span class="nav-text">Health Check</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentPath, '/dashboard/audit') !== false ? 'active' : '' ?>" href="/dashboard/audit">
                        <i class="bi bi-shield-check"></i>
                        <span class="nav-text">Auditoria</span>
                    </a>
                </li>
            </ul>
        </nav>
    </aside>

    <!-- Main Wrapper -->
    <div class="main-wrapper">
        <!-- Top Navbar -->
        <header class="top-navbar">
            <div class="d-flex align-items-center">
                <button class="btn btn-link text-dark d-lg-none me-2" id="sidebarToggle">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <button class="btn btn-link text-dark d-none d-lg-block me-2" id="sidebarCollapse" title="Recolher menu">
                    <i class="bi bi-layout-sidebar-inset"></i>
                </button>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
            </div>

            <div class="navbar-actions">
                <!-- Search -->
                <div class="d-none d-md-block">
                    <div class="input-group" style="width: 250px;">
                        <input type="text" class="form-control form-control-sm" placeholder="Buscar..." id="globalSearch">
                        <button class="btn btn-outline-secondary btn-sm" type="button">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>

                <!-- Notifications -->
                <div class="dropdown">
                    <button class="btn btn-link text-dark position-relative" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bell fs-5"></i>
                        <?php if ($unreadNotifications > 0): ?>
                            <span class="notification-badge"><?= $unreadNotifications > 9 ? '9+' : $unreadNotifications ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end" style="width: 320px;">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                            <h6 class="mb-0">Notificações</h6>
                            <a href="#" class="text-decoration-none small" id="markAllRead">Marcar todas como lidas</a>
                        </div>
                        <div id="notificationsList" style="max-height: 300px; overflow-y: auto;">
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-bell-slash fs-1"></i>
                                <p class="mb-0 mt-2">Nenhuma notificação</p>
                            </div>
                        </div>
                        <div class="border-top px-3 py-2 text-center">
                            <a href="/dashboard/notifications" class="text-decoration-none small">Ver todas</a>
                        </div>
                    </div>
                </div>

                <!-- Theme Toggle -->
                <button class="btn btn-link text-dark" id="themeToggle" title="Alternar tema">
                    <i class="bi bi-moon-stars fs-5"></i>
                </button>

                <!-- Account Switcher -->
                <?php if (count($userAccounts) > 0): ?>
                    <div class="dropdown">
                        <div class="account-switcher" data-bs-toggle="dropdown">
                            <div class="account-avatar">
                                <?= strtoupper(substr($activeAccount['nickname'] ?? 'U', 0, 1)) ?>
                            </div>
                            <span class="d-none d-md-inline"><?= htmlspecialchars($activeAccount['nickname'] ?? 'Conta') ?></span>
                            <i class="bi bi-chevron-down small"></i>
                        </div>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <h6 class="dropdown-header">Contas ML</h6>
                            </li>
                            <?php foreach ($userAccounts as $account): ?>
                                <li>
                                    <a class="dropdown-item d-flex justify-content-between align-items-center <?= $account['id'] == $activeAccountId ? 'active' : '' ?>"
                                        href="#" onclick="switchAccount(<?= $account['id'] ?>); return false;">
                                        <?= htmlspecialchars($account['nickname'] ?? '') ?>
                                        <?php if ($account['id'] == $activeAccountId): ?>
                                            <i class="bi bi-check-circle-fill text-success"></i>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="/auth/authorize"><i class="bi bi-plus-circle me-2"></i>Vincular Nova</a></li>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- User Menu -->
                <div class="dropdown">
                    <button class="btn btn-link text-dark dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle fs-5"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <h6 class="dropdown-header"><?= htmlspecialchars($currentUser['name'] ?? 'Usuário') ?></h6>
                        </li>
                        <li><a class="dropdown-item" href="/dashboard/profile"><i class="bi bi-person me-2"></i>Meu Perfil</a></li>
                        <li><a class="dropdown-item" href="/dashboard/api-tokens"><i class="bi bi-key me-2"></i>API Tokens</a></li>
                        <li><a class="dropdown-item" href="/dashboard/activities"><i class="bi bi-clock-history me-2"></i>Atividades</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="/dashboard/help"><i class="bi bi-question-circle me-2"></i>Ajuda</a></li>
                        <li>
                            <form method="POST" action="/auth/logout" class="m-0">
                                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-2"></i>Sair
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Breadcrumb -->
        <?php if (!empty($breadcrumbs)): ?>
            <nav aria-label="breadcrumb" class="px-4 py-2 bg-white border-bottom">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="/dashboard"><i class="bi bi-house"></i></a></li>
                    <?php foreach ($breadcrumbs as $item): ?>
                        <?php if (isset($item['active']) && $item['active']): ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($item['label']) ?></li>
                        <?php else: ?>
                            <li class="breadcrumb-item"><a href="<?= $item['url'] ?>"><?= htmlspecialchars($item['label']) ?></a></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show m-4 mb-0" role="alert">
                <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php unset($_SESSION['success']);
        endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show m-4 mb-0" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php unset($_SESSION['error']);
        endif; ?>

        <!-- Main Content -->
        <main class="main-content">
            <?= $content ?? '' ?>
        </main>

        <!-- Footer -->
        <footer class="main-footer">
            <div class="d-flex flex-wrap justify-content-between align-items-center">
                <span>&copy; <?= date('Y') ?> ML Manager - Desenvolvido por <strong>eSkill</strong></span>
                <div>
                    <a href="/dashboard/help" class="text-muted text-decoration-none me-3">Ajuda</a>
                    <a href="https://developers.mercadolivre.com.br" target="_blank" class="text-muted text-decoration-none">API ML</a>
                </div>
            </div>
        </footer>
    </div>

    <!-- Bootstrap JS -->
    <script nonce="<?= $cspNonce ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- App JS -->
    <script nonce="<?= $cspNonce ?>" src="/js/app.js"></script>

    <script nonce="<?= CSP_NONCE ?>">
        // CSRF Token
        const csrfToken = '<?= $_SESSION['csrf_token'] ?? '' ?>';

        // Sidebar Toggle (Mobile)
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });

        document.getElementById('sidebarOverlay')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.remove('show');
            document.getElementById('sidebarOverlay').classList.remove('show');
        });

        // Sidebar Collapse (Desktop)
        document.getElementById('sidebarCollapse')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', document.getElementById('sidebar').classList.contains('collapsed'));
        });

        // Restore sidebar state
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            document.getElementById('sidebar').classList.add('collapsed');
        }

        // Theme Toggle
        document.getElementById('themeToggle')?.addEventListener('click', () => {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-bs-theme', newTheme);
            localStorage.setItem('theme', newTheme);

            const icon = document.querySelector('#themeToggle i');
            icon.className = newTheme === 'dark' ? 'bi bi-sun fs-5' : 'bi bi-moon-stars fs-5';
        });

        // Restore theme
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', savedTheme);
        if (savedTheme === 'dark') {
            document.querySelector('#themeToggle i').className = 'bi bi-sun fs-5';
        }

        // Account Switcher
        async function switchAccount(accountId) {
            try {
                const data = await requestJson('/api/dashboard/switch-account', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        account_id: accountId
                    })
                });
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Erro ao trocar conta: ' + (data.error || 'Erro desconhecido'));
                }
            } catch (error) {
                console.error('Erro:', error);
                alert('Erro ao trocar conta');
            }
        }

        // Global Search
        document.getElementById('globalSearch')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                const query = e.target.value.trim();
                if (query) {
                    window.location.href = '/dashboard/search?q=' + encodeURIComponent(query);
                }
            }
        });

        // Load notifications
        async function loadNotifications() {
            try {
                const data = await requestJson('/api/notifications?limit=5');

                const container = document.getElementById('notificationsList');
                if (data.data && data.data.length > 0) {
                    container.innerHTML = data.data.map(n => `
                        <a href="#" class="dropdown-item py-2 ${!n.is_read ? 'bg-light' : ''}" onclick="markAsRead(${n.id})">
                            <div class="d-flex">
                                <div class="me-3">
                                    <i class="bi bi-${getNotificationIcon(n.type)} text-${getNotificationColor(n.type)}"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small">${n.title}</div>
                                    <div class="text-muted small text-truncate" style="max-width: 200px;">${n.message || ''}</div>
                                    <div class="text-muted smaller">${timeAgo(n.created_at)}</div>
                                </div>
                            </div>
                        </a>
                    `).join('');
                }
            } catch (e) {
                console.error('Erro ao carregar notificações:', e);
            }
        }

        function getNotificationIcon(type) {
            const icons = {
                'order': 'cart-check',
                'question': 'chat-dots',
                'alert': 'exclamation-triangle',
                'info': 'info-circle',
                'success': 'check-circle'
            };
            return icons[type] || 'bell';
        }

        function getNotificationColor(type) {
            const colors = {
                'order': 'success',
                'question': 'primary',
                'alert': 'warning',
                'info': 'info',
                'success': 'success'
            };
            return colors[type] || 'secondary';
        }

        function timeAgo(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const seconds = Math.floor((now - date) / 1000);

            if (seconds < 60) return 'agora';
            if (seconds < 3600) return Math.floor(seconds / 60) + 'm atrás';
            if (seconds < 86400) return Math.floor(seconds / 3600) + 'h atrás';
            return Math.floor(seconds / 86400) + 'd atrás';
        }

        async function markAsRead(id) {
            try {
                await requestJson(`/api/notifications/${id}/read`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });
                loadNotifications();
            } catch (e) {}
        }

        document.getElementById('markAllRead')?.addEventListener('click', async (e) => {
            e.preventDefault();
            try {
                await requestJson('/api/notifications/mark-all-read', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });
                loadNotifications();
            } catch (e) {}
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            loadNotifications();
        });
    </script>

    <?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>

</html>
