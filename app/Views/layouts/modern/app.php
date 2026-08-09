<?php
// CSP_NONCE is a PHP constant defined in public/index.php, accessible from any scope.
$cspNonce = defined('CSP_NONCE') ? CSP_NONCE : (($GLOBALS['cspNonce'] ?: null) ?? ($_SESSION['csp_nonce'] ?? ''));
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> - ML Manager</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Font Awesome (usado em Ficha Técnica, SEO Killer e vários módulos do dashboard) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">

    <!-- Core CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/css/dashboard-modern.css?v=<?= @filemtime(__DIR__ . '/../../../../public/css/dashboard-modern.css') ?: time() ?>" rel="stylesheet">
    <link href="/css/theme.css?v=<?= @filemtime(__DIR__ . '/../../../../public/css/theme.css') ?: time() ?>" rel="stylesheet">
    <link href="/css/components.css?v=<?= @filemtime(__DIR__ . '/../../../../public/css/components.css') ?: time() ?>" rel="stylesheet">

    <!-- Chart.js -->
    <script nonce="<?= $cspNonce ?>" src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

    <!-- API Client (loaded early so view scripts can use requestJson) -->
    <script nonce="<?= $cspNonce ?>" src="/js/csrf-helper.js"></script>
    <script nonce="<?= $cspNonce ?>" src="/js/api-client.js?v=<?= @filemtime(__DIR__ . '/../../../../public/js/api-client.js') ?: time() ?>"></script>
    <script nonce="<?= $cspNonce ?>" src="/js/ml-integration-preflight.js?v=<?= @filemtime(__DIR__ . '/../../../../public/js/ml-integration-preflight.js') ?: time() ?>"></script>
    <script nonce="<?= $cspNonce ?>" src="/js/layout-modern-init.js?v=<?= @filemtime(__DIR__ . '/../../../../public/js/layout-modern-init.js') ?: time() ?>"></script>

    <!-- Global Styles -->
    <style>
        :root {
            --header-height: 64px;
            --sidebar-width: 260px;
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            min-height: 100vh;
            margin: 0;
            -webkit-font-smoothing: antialiased;
        }

        /* Dashboard Layout */
        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 991.98px) {
            .main-content {
                margin-left: 0;
            }
        }

        /* Modern Header */
        .topbar {
            height: var(--header-height);
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .sidebar-toggle {
            display: none;
            width: 40px;
            height: 40px;
            border: none;
            background: transparent;
            border-radius: 8px;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .sidebar-toggle:hover {
            background: #f1f5f9;
            color: #1e293b;
        }

        @media (max-width: 991.98px) {
            .sidebar-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }

        /* Breadcrumb */
        .breadcrumb-nav {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .breadcrumb-nav a {
            color: #64748b;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .breadcrumb-nav a:hover {
            color: #667eea;
        }

        .breadcrumb-nav .separator {
            color: #cbd5e1;
        }

        .breadcrumb-nav .current {
            color: #1e293b;
            font-weight: 500;
        }

        /* Header Actions */
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-btn {
            width: 40px;
            height: 40px;
            border: none;
            background: transparent;
            border-radius: 8px;
            color: #64748b;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: all 0.2s ease;
        }

        .header-btn:hover {
            background: #f1f5f9;
            color: #1e293b;
        }

        .header-btn i {
            font-size: 1.25rem;
        }

        .header-btn .badge-dot {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 8px;
            height: 8px;
            background: #ef4444;
            border-radius: 50%;
            border: 2px solid #fff;
        }

        /* Theme Toggle */
        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem;
            background: #f1f5f9;
            border-radius: 8px;
        }

        .theme-toggle button {
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            border-radius: 6px;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .theme-toggle button.active {
            background: #fff;
            color: #667eea;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* Account Switcher */
        .account-switcher-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0.75rem;
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.875rem;
            color: #475569;
            position: relative;
        }

        .account-switcher-btn:hover {
            border-color: #667eea;
            color: #667eea;
            box-shadow: 0 2px 4px rgba(102, 126, 234, 0.15);
        }

        .account-switcher-btn i {
            font-size: 1.1rem;
        }

        .account-name {
            max-width: 140px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 500;
        }

        .account-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
        }

        [data-theme="dark"] .account-switcher-btn {
            background: #1e293b;
            border-color: #334155;
            color: #cbd5e1;
        }

        [data-theme="dark"] .account-switcher-btn:hover {
            border-color: #667eea;
            color: #818cf8;
        }

        /* User Menu */
        .user-menu-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.375rem 0.75rem 0.375rem 0.375rem;
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .user-menu-btn:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .user-menu-btn img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }

        .user-menu-btn .user-info {
            display: none;
        }

        @media (min-width: 768px) {
            .user-menu-btn .user-info {
                display: block;
                text-align: left;
            }

            .user-menu-btn .user-name {
                font-size: 0.875rem;
                font-weight: 500;
                color: #1e293b;
            }

            .user-menu-btn .user-role {
                font-size: 0.75rem;
                color: #64748b;
            }
        }

        /* Page Content */
        .page-content {
            flex: 1;
            padding: 1.5rem;
            max-width: 1600px;
            width: 100%;
            margin: 0 auto;
        }

        @media (max-width: 767.98px) {
            .page-content {
                padding: 1rem;
            }
        }

        /* Cards */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
        }

        .card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .card-header {
            background: transparent;
            border-bottom: 1px solid #f1f5f9;
            padding: 1rem 1.25rem;
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Buttons */
        .btn {
            font-weight: 500;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: var(--primary-gradient);
            border: none;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        /* Form Controls */
        .form-control,
        .form-select {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Tables */
        .table {
            font-size: 0.875rem;
        }

        .table th {
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #e2e8f0;
        }

        .table td {
            vertical-align: middle;
            color: #1e293b;
        }

        /* Toast Container */
        .toast-container {
            z-index: 1080 !important;
        }

        .toast {
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }

        /* Dark Theme */
        [data-theme="dark"] body {
            background: #0f172a;
            color: #e2e8f0;
        }

        [data-theme="dark"] .topbar {
            background: #1e293b;
            border-color: #334155;
        }

        [data-theme="dark"] .sidebar-toggle:hover {
            background: #334155;
            color: #fff;
        }

        [data-theme="dark"] .breadcrumb-nav a {
            color: #94a3b8;
        }

        [data-theme="dark"] .breadcrumb-nav .current {
            color: #f1f5f9;
        }

        [data-theme="dark"] .header-btn:hover {
            background: #334155;
            color: #fff;
        }

        [data-theme="dark"] .theme-toggle {
            background: #334155;
        }

        [data-theme="dark"] .theme-toggle button.active {
            background: #1e293b;
            color: #667eea;
        }

        [data-theme="dark"] .user-menu-btn {
            background: #1e293b;
            border-color: #334155;
        }

        [data-theme="dark"] .user-menu-btn .user-name {
            color: #f1f5f9;
        }

        [data-theme="dark"] .card {
            background: #1e293b;
        }

        [data-theme="dark"] .card-header {
            border-color: #334155;
        }

        [data-theme="dark"] .form-control,
        [data-theme="dark"] .form-select {
            background: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }

        [data-theme="dark"] .table th {
            color: #94a3b8;
            border-color: #334155;
        }

        [data-theme="dark"] .table td {
            color: #e2e8f0;
            border-color: #334155;
        }

        [data-theme="dark"] .dropdown-menu {
            background: #1e293b;
            border-color: #334155;
        }

        [data-theme="dark"] .dropdown-item {
            color: #e2e8f0;
        }

        [data-theme="dark"] .dropdown-item:hover {
            background: #334155;
        }

        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeIn 0.3s ease;
        }

        /* Loading States */
        .skeleton {
            background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s infinite;
            border-radius: 4px;
        }

        @keyframes skeleton-loading {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
            }
        }

        /* Global Page Loader */
        .page-loader {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .page-loader.active {
            opacity: 1;
            visibility: visible;
        }

        .loader-content {
            text-align: center;
        }

        .loader-spinner {
            width: 50px;
            height: 50px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .loader-text {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 500;
        }

        /* Loading Bar (top) */
        .loading-bar {
            position: fixed;
            top: 0;
            left: 0;
            height: 3px;
            background: var(--primary-gradient);
            z-index: 10000;
            width: 0;
            transition: width 0.3s ease;
        }

        .loading-bar.active {
            animation: loadingBar 2s ease-in-out infinite;
        }

        @keyframes loadingBar {
            0% {
                width: 0;
                left: 0;
            }

            50% {
                width: 70%;
                left: 0;
            }

            100% {
                width: 100%;
                left: 0;
            }
        }

        /* Button Loading State */
        .btn.loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
        }

        .btn.loading::after {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            top: 50%;
            left: 50%;
            margin-left: -9px;
            margin-top: -9px;
            border: 2px solid currentColor;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        .btn-primary.loading::after {
            border-color: rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
        }

        /* Card Loading Overlay */
        .card-loading {
            position: relative;
            pointer-events: none;
        }

        .card-loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: inherit;
        }

        .card-loading::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 30px;
            height: 30px;
            margin: -15px 0 0 -15px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            z-index: 11;
        }

        /* Inline Loader */
        .inline-loader {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .inline-loader .dot {
            width: 6px;
            height: 6px;
            background: #667eea;
            border-radius: 50%;
            animation: dotPulse 1.4s ease-in-out infinite both;
        }

        .inline-loader .dot:nth-child(1) {
            animation-delay: -0.32s;
        }

        .inline-loader .dot:nth-child(2) {
            animation-delay: -0.16s;
        }

        .inline-loader .dot:nth-child(3) {
            animation-delay: 0;
        }

        @keyframes dotPulse {

            0%,
            80%,
            100% {
                transform: scale(0);
                opacity: 0.5;
            }

            40% {
                transform: scale(1);
                opacity: 1;
            }
        }

        /* Dark theme loading states */
        [data-theme="dark"] .page-loader {
            background: rgba(15, 23, 42, 0.95);
        }

        [data-theme="dark"] .loader-spinner {
            border-color: #334155;
            border-top-color: #667eea;
        }

        [data-theme="dark"] .card-loading::after {
            background: rgba(30, 41, 59, 0.8);
        }

        [data-theme="dark"] .card-loading::before {
            border-color: #334155;
            border-top-color: #667eea;
        }
    </style>
</head>

<body data-theme="<?= $_COOKIE['theme'] ?? 'light' ?>">
    <!-- Loading Bar -->
    <div class="loading-bar" id="loadingBar"></div>

    <!-- Page Loader -->
    <div class="page-loader" id="pageLoader">
        <div class="loader-content">
            <div class="loader-spinner"></div>
            <p class="loader-text">Carregando...</p>
        </div>
    </div>

    <?php
    // Include sidebar
    include __DIR__ . '/sidebar.php';

    // Generate breadcrumb from URI
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/dashboard', PHP_URL_PATH);
    $segments = array_filter(explode('/', $uri));
    $breadcrumbs = [];
    $path = '';
    foreach ($segments as $segment) {
        $path .= '/' . $segment;
        $label = ucfirst(str_replace(['-', '_'], ' ', $segment));
        $breadcrumbs[] = ['path' => $path, 'label' => $label];
    }
    ?>

    <div class="dashboard-wrapper">
        <main class="main-content">
            <!-- Modern Header -->
            <header class="topbar">
                <div class="topbar-left">
                    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Abrir menu">
                        <i class="bi bi-list"></i>
                    </button>

                    <!-- Breadcrumb -->
                    <nav class="breadcrumb-nav d-none d-md-flex" aria-label="Breadcrumb">
                        <a href="/dashboard"><i class="bi bi-house"></i></a>
                        <?php foreach ($breadcrumbs as $i => $crumb): ?>
                            <span class="separator">/</span>
                            <?php if ($i === count($breadcrumbs) - 1): ?>
                                <span class="current"><?= htmlspecialchars($crumb['label']) ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($crumb['path']) ?>"><?= htmlspecialchars($crumb['label']) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                </div>

                <div class="topbar-right">
                    <!-- Account Switcher -->
                    <?php
                    $userService = $userService ?? new \App\Services\UserService();
                    $mlAccounts = $userService->getUserAccounts();
                    $activeAccountId = $_SESSION['active_ml_account_id'] ?? null;
                    $activeAccount = null;
                    foreach ($mlAccounts as $acc) {
                        if ((int)$acc['id'] === (int)$activeAccountId) {
                            $activeAccount = $acc;
                            break;
                        }
                    }
                    if (count($mlAccounts) > 0): ?>
                        <div class="dropdown">
                            <button class="account-switcher-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Trocar conta">
                                <i class="bi bi-shop-window"></i>
                                <span class="account-name d-none d-md-inline"><?= htmlspecialchars($activeAccount['nickname'] ?? 'Selecionar Conta') ?></span>
                                <?php if ($activeAccount && $activeAccount['status'] !== 'active'): ?>
                                    <span class="account-status-dot bg-danger" title="Conta desconectada"></span>
                                <?php elseif ($activeAccount): ?>
                                    <span class="account-status-dot bg-success" title="Conectada"></span>
                                <?php endif; ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-lg" style="min-width: 280px;">
                                <li>
                                    <div class="px-3 py-2 border-bottom">
                                        <small class="text-muted text-uppercase fw-semibold" style="font-size:.7rem; letter-spacing:.5px;">Conta Ativa</small>
                                    </div>
                                </li>
                                <?php foreach ($mlAccounts as $acc):
                                    $isActive = (int)$acc['id'] === (int)$activeAccountId;
                                    $isConnected = $acc['status'] === 'active';
                                ?>
                                    <li>
                                        <button class="dropdown-item py-2 d-flex align-items-center gap-2 <?= $isActive ? 'active' : '' ?>"
                                            data-switch-account-id="<?= (int)$acc['id'] ?>"
                                            <?= $isActive ? 'disabled' : '' ?>>
                                            <span class="d-inline-block rounded-circle <?= $isConnected ? 'bg-success' : 'bg-danger' ?>" style="width:8px;height:8px;"></span>
                                            <span class="flex-grow-1">
                                                <span class="fw-medium"><?= htmlspecialchars($acc['nickname'] ?? 'Conta #' . $acc['id']) ?></span>
                                                <?php if (!$isConnected): ?>
                                                    <br><small class="text-danger">Desconectada</small>
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($isActive): ?>
                                                <i class="bi bi-check-circle-fill text-primary"></i>
                                            <?php endif; ?>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item py-2" href="/dashboard/accounts"><i class="bi bi-gear me-2"></i>Gerenciar Contas</a></li>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <!-- Search Button -->
                    <button class="header-btn" id="globalSearch" title="Buscar (Ctrl+K)">
                        <i class="bi bi-search"></i>
                    </button>

                    <!-- Theme Toggle -->
                    <div class="theme-toggle">
                        <button id="themeLight" title="Tema Claro" class="<?= ($_COOKIE['theme'] ?? 'light') === 'light' ? 'active' : '' ?>">
                            <i class="bi bi-sun"></i>
                        </button>
                        <button id="themeDark" title="Tema Escuro" class="<?= ($_COOKIE['theme'] ?? 'light') === 'dark' ? 'active' : '' ?>">
                            <i class="bi bi-moon"></i>
                        </button>
                    </div>

                    <!-- Notifications -->
                    <button class="header-btn" id="notificationsBtn" title="Notificações">
                        <i class="bi bi-bell"></i>
                        <span class="badge-dot"></span>
                    </button>

                    <!-- User Menu -->
                    <div class="dropdown">
                        <button class="user-menu-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['user_name'] ?? 'User') ?>&background=667eea&color=fff" alt="Avatar">
                            <div class="user-info">
                                <div class="user-name"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Usuário') ?></div>
                                <div class="user-role"><?= htmlspecialchars($_SESSION['user_role'] ?? 'Vendedor') ?></div>
                            </div>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg">
                            <li>
                                <div class="px-3 py-2 border-bottom">
                                    <div class="fw-semibold"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Usuário') ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($_SESSION['user_email'] ?? '') ?></small>
                                </div>
                            </li>
                            <li><a class="dropdown-item py-2" href="/dashboard/profile"><i class="bi bi-person me-2"></i>Meu Perfil</a></li>
                            <li><a class="dropdown-item py-2" href="/dashboard/settings"><i class="bi bi-gear me-2"></i>Configurações</a></li>
                            <li><a class="dropdown-item py-2" href="/dashboard/help"><i class="bi bi-question-circle me-2"></i>Ajuda</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li>
                                <form method="POST" action="/auth/logout" class="m-0">
                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="dropdown-item py-2 text-danger">
                                        <i class="bi bi-box-arrow-right me-2"></i>Sair
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <div class="page-content animate-fade-in">
                <!-- Toast Container -->
                <div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

                <!-- Flash Messages -->
                <?php
                if (session_status() === PHP_SESSION_NONE) session_start();
                $flashMessages = \App\Core\Flash::get();
                if (!empty($flashMessages)):
                    $flashMessagesJson = htmlspecialchars(json_encode($flashMessages, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                ?>
                    <div id="flash-messages-data" data-flash="<?= $flashMessagesJson ?>" hidden></div>
                <?php endif; ?>

                <!-- Main Content -->
                <?= $content ?? '' ?>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script nonce="<?= $cspNonce ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?= $cspNonce ?>" src="/js/dashboard-modern.js?v=<?= @filemtime(__DIR__ . '/../../../../public/js/dashboard-modern.js') ?: time() ?>"></script>

    <!-- Mobile Bottom Navigation -->
    <?php if (file_exists(__DIR__ . '/bottom_nav.php')): ?>
        <?php include __DIR__ . '/bottom_nav.php'; ?>
    <?php endif; ?>
</body>

</html>
