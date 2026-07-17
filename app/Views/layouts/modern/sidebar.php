<?php

declare(strict_types=1);

/**
 * Modern Sidebar Navigation
 * Clean, professional sidebar with organized menu structure
 */

// Get current URI for active state detection
$currentUri = $_SERVER['REQUEST_URI'] ?? '/dashboard';
$currentUri = parse_url($currentUri, PHP_URL_PATH);

// User info
$sessionUserRole = (string) ($_SESSION['user_role'] ?? '');
$userName = $_SESSION['user_name'] ?? 'Usuário';
$userRole = $_SESSION['user_role'] ?? 'Vendedor';
$userAvatar = $_SESSION['user_avatar'] ?? null;
$isAdmin = $sessionUserRole === 'admin' || ($_SESSION['is_admin'] ?? false);
$isManager = $sessionUserRole === 'manager';
$isViewer = $sessionUserRole === 'viewer';

// Helper function to check active state (guarded to avoid redeclaration)
if (!function_exists('isActive')) {
    function isActive(string $path, bool $exact = false): bool
    {
        $parsedUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = is_string($parsedUri) && $parsedUri !== '' ? $parsedUri : '/';
        if ($exact) {
            return $uri === $path;
        }
        return strpos($uri, $path) !== false;
    }
}

// Badge counts from session
$unreadMessages = $_SESSION['unread_messages'] ?? 0;
$pendingOrders = $_SESSION['pending_orders'] ?? 0;
$unansweredQuestions = $_SESSION['unanswered_questions'] ?? 0;
?>

<aside class="sidebar" id="sidebar">
    <!-- Brand Header -->
    <div class="sidebar-brand">
        <a href="/dashboard" class="brand-link">
            <div class="brand-icon">
                <i class="bi bi-shop-window"></i>
            </div>
            <span class="brand-text">ML Manager</span>
        </a>
        <button class="sidebar-close d-lg-none" id="sidebarClose" aria-label="Fechar menu">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <!-- User Profile Mini -->
    <div class="sidebar-user">
        <div class="user-avatar">
            <?php if ($userAvatar): ?>
                <img src="<?= htmlspecialchars($userAvatar) ?>" alt="<?= htmlspecialchars($userName) ?>">
            <?php else: ?>
                <div class="avatar-placeholder">
                    <?= strtoupper(substr($userName, 0, 1)) ?>
                </div>
            <?php endif; ?>
            <span class="status-dot online"></span>
        </div>
        <div class="user-info">
            <span class="user-name"><?= htmlspecialchars($userName) ?></span>
            <span class="user-role"><?= htmlspecialchars($userRole) ?></span>
        </div>
    </div>

    <!-- Quick Search -->
    <div class="sidebar-search">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Buscar..." id="sidebarSearch">
            <kbd>⌘K</kbd>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <!-- Principal -->
        <div class="nav-section">
            <div class="nav-section-title">Principal</div>

            <a href="/dashboard" class="nav-item <?= isActive('/dashboard', true) ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2"></i>
                <span>Dashboard</span>
            </a>

            <a href="/dashboard/analytics" class="nav-item <?= isActive('/analytics') ? 'active' : '' ?>">
                <i class="bi bi-bar-chart-line"></i>
                <span>Analytics</span>
            </a>

            <a href="/dashboard/account-health" class="nav-item <?= isActive('/account-health') ? 'active' : '' ?>">
                <i class="bi bi-heart-pulse"></i>
                <span>Diagnóstico</span>
                <span class="nav-badge new">Novo</span>
            </a>

            <a href="/dashboard/listing-visibility" class="nav-item <?= isActive('/listing-visibility') ? 'active' : '' ?>">
                <i class="bi bi-search-heart"></i>
                <span>Visibilidade ML</span>
                <span class="nav-badge new">Novo</span>
            </a>

            <a href="/dashboard/raio-x" class="nav-item <?= isActive('/raio-x') ? 'active' : '' ?>">
                <i class="bi bi-radioactive text-danger"></i>
                <span>Raio X da Conta</span>
                <span class="nav-badge" style="background:#dc3545;color:#fff">X</span>
            </a>
        </div>

        <!-- Catálogo & SEO -->
        <div class="nav-section">
            <div class="nav-section-title">Catálogo & SEO</div>

            <a href="/dashboard/items" class="nav-item <?= isActive('/items') && !isActive('/bulk') ? 'active' : '' ?>">
                <i class="bi bi-box-seam"></i>
                <span>Meus Anúncios</span>
            </a>

            <a href="/dashboard/seo-killer" class="nav-item <?= isActive('/seo-killer') ? 'active' : '' ?>">
                <i class="bi bi-fire"></i>
                <span>SEO Killer</span>
                <span class="nav-badge pro">PRO</span>
            </a>

            <a href="/dashboard/seo-killer#technical-sheet" class="nav-item <?= isActive('/ficha-tecnica') || isActive('/tech-sheet') ? 'active' : '' ?>">
                <i class="bi bi-card-checklist"></i>
                <span>Ficha Técnica</span>
            </a>

            <a href="/dashboard/items/bulk" class="nav-item <?= isActive('/items/bulk') && !isActive('/bulk-compatibility') ? 'active' : '' ?>">
                <i class="bi bi-layers"></i>
                <span>Editor em Massa</span>
            </a>

            <a href="/dashboard/items/bulk-compatibility" class="nav-item <?= isActive('/bulk-compatibility') ? 'active' : '' ?>">
                <i class="bi bi-puzzle-fill"></i>
                <span>Compatibilidades</span>
                <span class="badge bg-warning text-dark ms-auto" style="font-size:0.6rem">MASSA</span>
            </a>

            <a href="/dashboard/categories" class="nav-item <?= isActive('/categories') ? 'active' : '' ?>">
                <i class="bi bi-tags"></i>
                <span>Categorias</span>
            </a>

            <a href="/dashboard/ads" class="nav-item <?= isActive('/ads') ? 'active' : '' ?>">
                <i class="bi bi-megaphone"></i>
                <span>Publicidade (Ads)</span>
            </a>
        </div>

        <!-- Vendas -->
        <div class="nav-section">
            <div class="nav-section-title">Vendas</div>

            <a href="/dashboard/orders" class="nav-item <?= isActive('/orders') ? 'active' : '' ?>">
                <i class="bi bi-cart3"></i>
                <span>Pedidos</span>
                <?php if ($pendingOrders > 0): ?>
                    <span class="nav-badge count"><?= $pendingOrders ?></span>
                <?php endif; ?>
            </a>

            <a href="/dashboard/questions" class="nav-item <?= isActive('/questions') ? 'active' : '' ?>">
                <i class="bi bi-chat-left-text"></i>
                <span>Perguntas</span>
                <?php if ($unansweredQuestions > 0): ?>
                    <span class="nav-badge count"><?= $unansweredQuestions ?></span>
                <?php endif; ?>
            </a>

            <a href="/dashboard/messages" class="nav-item <?= isActive('/messages') ? 'active' : '' ?>">
                <i class="bi bi-envelope"></i>
                <span>Mensagens</span>
                <?php if ($unreadMessages > 0): ?>
                    <span class="nav-badge count"><?= $unreadMessages ?></span>
                <?php endif; ?>
            </a>

            <a href="/dashboard/claims" class="nav-item <?= isActive('/claims') ? 'active' : '' ?>">
                <i class="bi bi-exclamation-triangle"></i>
                <span>Reclamações</span>
            </a>

            <a href="/dashboard/returns" class="nav-item <?= isActive('/returns') ? 'active' : '' ?>">
                <i class="bi bi-arrow-return-left"></i>
                <span>Devoluções</span>
            </a>
        </div>

        <!-- Logística -->
        <div class="nav-section">
            <div class="nav-section-title">Logística</div>

            <a href="/dashboard/shipping" class="nav-item <?= isActive('/shipping') ? 'active' : '' ?>">
                <i class="bi bi-truck"></i>
                <span>Frete</span>
            </a>

            <a href="/dashboard/logistics/flex" class="nav-item <?= isActive('/logistics/flex') ? 'active' : '' ?>">
                <i class="bi bi-lightning-charge-fill text-warning"></i>
                <span>Flex (Same-Day)</span>
            </a>

            <a href="/dashboard/logistics/full" class="nav-item <?= isActive('/logistics/full') ? 'active' : '' ?>">
                <i class="bi bi-boxes"></i>
                <span>Full (Restock)</span>
            </a>
        </div>

        <!-- Inteligência -->
        <div class="nav-section">
            <div class="nav-section-title">Inteligência</div>

            <a href="/dashboard/competitors" class="nav-item <?= isActive('/competitors') ? 'active' : '' ?>">
                <i class="bi bi-binoculars"></i>
                <span>Concorrentes</span>
            </a>

            <a href="/dashboard/catalog/competition" class="nav-item <?= isActive('/catalog/competition') ? 'active' : '' ?>">
                <i class="bi bi-trophy"></i>
                <span>Buy Box</span>
            </a>

            <a href="/dashboard/opportunities" class="nav-item <?= isActive('/opportunities') ? 'active' : '' ?>">
                <i class="bi bi-lightbulb"></i>
                <span>Oportunidades</span>
            </a>

            <?php if ($isAdmin || $isManager || $isViewer): ?>
                <a href="/dashboard/awa-sellers" class="nav-item <?= isActive('/awa-sellers') ? 'active' : '' ?>">
                    <i class="bi bi-diagram-3"></i>
                    <span>AWA Sellers</span>
                    <?php if (!$isAdmin && !$isManager): ?>
                        <span class="nav-badge">RO</span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <a href="/brand-search" class="nav-item <?= isActive('/brand-search') ? 'active' : '' ?>">
                <i class="bi bi-search"></i>
                <span>Pesq. Vendedores</span>
                <span class="badge bg-success ms-auto" style="font-size:0.6rem;">NOVO</span>
            </a>
            <a href="/dashboard/seo-killer#ai-insights" class="nav-item <?= isActive('/ai-optimization') || isActive('/ai-insights') ? 'active' : '' ?>">
                <i class="bi bi-robot"></i>
                <span>AI Insights</span>
            </a>
        </div>

        <!-- Financeiro -->
        <div class="nav-section">
            <div class="nav-section-title">Financeiro</div>

            <a href="/dashboard/pricing" class="nav-item <?= isActive('/pricing') ? 'active' : '' ?>">
                <i class="bi bi-calculator"></i>
                <span>Precificador</span>
                <span class="badge bg-success" style="font-size: 0.6rem; margin-left: auto;">NEW</span>
            </a>

            <a href="/dashboard/financials" class="nav-item <?= isActive('/financials') && !isActive('/conciliation') ? 'active' : '' ?>">
                <i class="bi bi-graph-up-arrow"></i>
                <span>Relatórios</span>
            </a>

            <a href="/dashboard/financials/conciliation" class="nav-item <?= isActive('/conciliation') ? 'active' : '' ?>">
                <i class="bi bi-bank"></i>
                <span>Conciliação</span>
            </a>
        </div>

        <!-- Ferramentas -->
        <div class="nav-section collapsible">
            <div class="nav-section-title" data-toggle="collapse" data-target="#toolsMenu">
                Ferramentas
                <i class="bi bi-chevron-down"></i>
            </div>
            <div class="nav-collapse" id="toolsMenu">
                <a href="/dashboard/catalog/clone" class="nav-item <?= isActive('/catalog/clone') && !isActive('/clone-batch') && !isActive('/clone-realtime') && !isActive('/clone-analytics') && !isActive('/clone-compliance') && !isActive('/clone-widget') && !isActive('/clonar-anuncios') ? 'active' : '' ?>">
                    <i class="bi bi-copy"></i>
                    <span>Clonar Catálogo</span>
                </a>

                <a href="/dashboard/catalog/clonar-anuncios" class="nav-item <?= isActive('/clonar-anuncios') ? 'active' : '' ?>">
                    <i class="bi bi-layers"></i>
                    <span>Clonar Anúncios</span>
                    <span class="badge bg-primary ms-auto" style="font-size: 0.6rem;">NOVO</span>
                </a>

                <a href="/dashboard/catalog/clone-batch" class="nav-item <?= isActive('/clone-batch') ? 'active' : '' ?>">
                    <i class="bi bi-files"></i>
                    <span>Clonar em Lote</span>
                    <span class="badge bg-success ms-auto" style="font-size: 0.6rem;">NOVO</span>
                </a>

                <a href="/dashboard/catalog/clone-wizard" class="nav-item <?= isActive('/clone-wizard') ? 'active' : '' ?>">
                    <i class="bi bi-magic"></i>
                    <span>Wizard Concorrente</span>
                    <span class="badge bg-warning text-dark ms-auto" style="font-size: 0.6rem;">NOVO</span>
                </a>

                <a href="/dashboard/catalog/clone-realtime" class="nav-item <?= isActive('/clone-realtime') ? 'active' : '' ?>">
                    <i class="bi bi-broadcast"></i>
                    <span>Clone Real-time</span>
                    <span class="badge bg-info ms-auto" style="font-size: 0.6rem;">NEW</span>
                </a>

                <a href="/dashboard/catalog/clone-analytics" class="nav-item <?= isActive('/clone-analytics') ? 'active' : '' ?>">
                    <i class="bi bi-bar-chart"></i>
                    <span>Clone Analytics</span>
                </a>

                <a href="/dashboard/catalog/clone-compliance" class="nav-item <?= isActive('/clone-compliance') ? 'active' : '' ?>">
                    <i class="bi bi-shield-check"></i>
                    <span>Auditoria</span>
                </a>

                <a href="/dashboard/catalog/clone-widget-embed" class="nav-item <?= isActive('/clone-widget') ? 'active' : '' ?>">
                    <i class="bi bi-code-slash"></i>
                    <span>Widget Embed</span>
                </a>

                <a href="/dashboard/catalog/clone-ab-testing" class="nav-item <?= isActive('/clone-ab-testing') ? 'active' : '' ?>">
                    <i class="bi bi-diagram-3"></i>
                    <span>Testes A/B</span>
                    <span class="badge bg-warning ms-auto" style="font-size: 0.6rem;">BETA</span>
                </a>

                <a href="/dashboard/catalog/clone-roi-analysis" class="nav-item <?= isActive('/clone-roi-analysis') ? 'active' : '' ?>">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Análise ROI</span>
                </a>

                <a href="/dashboard/catalog/clone-seller-recommendations" class="nav-item <?= isActive('/clone-seller-recommendations') ? 'active' : '' ?>">
                    <i class="bi bi-lightbulb"></i>
                    <span>Recomendações ML</span>
                    <span class="badge bg-primary ms-auto" style="font-size: 0.6rem;">AI</span>
                </a>

                <a href="/dashboard/ean" class="nav-item <?= isActive('/ean') && !isActive('/admin') ? 'active' : '' ?>">
                    <i class="bi bi-upc-scan"></i>
                    <span>Gestor EAN</span>
                </a>

                <a href="/dashboard/analysis" class="nav-item <?= isActive('/analysis') ? 'active' : '' ?>">
                    <i class="bi bi-search"></i>
                    <span>Análise de Mercado</span>
                </a>

                <a href="/dashboard/reports" class="nav-item <?= isActive('/reports') ? 'active' : '' ?>">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>Relatórios</span>
                </a>
            </div>
        </div>

        <?php if ($isAdmin || $isViewer): ?>
            <!-- Admin / Auditoria -->
            <div class="nav-section">
                <div class="nav-section-title"><?= $isAdmin ? 'Administração' : 'Auditoria' ?></div>

                <?php if ($isAdmin): ?>
                    <a href="/dashboard/health" class="nav-item <?= isActive('/health') ? 'active' : '' ?>">
                        <i class="bi bi-activity"></i>
                        <span>System Health</span>
                    </a>

                    <a href="/dashboard/settings/users" class="nav-item <?= isActive('/settings/users') ? 'active' : '' ?>">
                        <i class="bi bi-people"></i>
                        <span>Usuários</span>
                    </a>
                <?php endif; ?>

                <a href="/dashboard/audit" class="nav-item <?= isActive('/audit') ? 'active' : '' ?>">
                    <i class="bi bi-shield-check"></i>
                    <span>Auditoria</span>
                </a>

                <a href="/dashboard/logs" class="nav-item <?= isActive('/logs') ? 'active' : '' ?>">
                    <i class="bi bi-journal-text"></i>
                    <span>Logs</span>
                </a>

                <a href="/dashboard/jobs" class="nav-item <?= isActive('/jobs') ? 'active' : '' ?>">
                    <i class="bi bi-list-task"></i>
                    <span>Jobs Monitor</span>
                </a>

                <a href="/dashboard/backups" class="nav-item <?= isActive('/backups') ? 'active' : '' ?>">
                    <i class="bi bi-cloud-download"></i>
                    <span>Backups</span>
                </a>
            </div>
        <?php endif; ?>
    </nav>

    <!-- Footer -->
    <div class="sidebar-footer">
        <a href="/dashboard/settings" class="nav-item <?= isActive('/settings') && !isActive('/users') ? 'active' : '' ?>">
            <i class="bi bi-gear"></i>
            <span>Configurações</span>
        </a>

        <a href="/dashboard/help" class="nav-item <?= isActive('/help') ? 'active' : '' ?>">
            <i class="bi bi-question-circle"></i>
            <span>Ajuda</span>
        </a>

        <form method="POST" action="/auth/logout" class="m-0">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="nav-item logout border-0 bg-transparent w-100 text-start">
                <i class="bi bi-box-arrow-right"></i>
                <span>Sair</span>
            </button>
        </form>
    </div>
</aside>

<!-- Mobile Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<style>
    /* ===== SIDEBAR STYLES ===== */
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        bottom: 0;
        width: 260px;
        background: var(--sidebar-bg, #1a1d21);
        color: var(--sidebar-text, #a0aec0);
        display: flex;
        flex-direction: column;
        z-index: 1000;
        transition: transform 0.3s ease, width 0.3s ease;
        overflow: hidden;
    }

    /* Brand */
    .sidebar-brand {
        padding: 1.25rem 1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }

    .brand-link {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        text-decoration: none;
        color: #fff;
    }

    .brand-icon {
        width: 36px;
        height: 36px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        color: #fff;
    }

    .brand-text {
        font-size: 1.125rem;
        font-weight: 700;
        letter-spacing: -0.02em;
    }

    .sidebar-close {
        background: none;
        border: none;
        color: var(--sidebar-text);
        font-size: 1.25rem;
        cursor: pointer;
        padding: 0.25rem;
    }

    /* User Profile */
    .sidebar-user {
        padding: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }

    .user-avatar {
        position: relative;
        flex-shrink: 0;
    }

    .user-avatar img,
    .avatar-placeholder {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        object-fit: cover;
    }

    .avatar-placeholder {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 1rem;
    }

    .status-dot {
        position: absolute;
        bottom: -2px;
        right: -2px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: 2px solid var(--sidebar-bg, #1a1d21);
    }

    .status-dot.online {
        background: #22c55e;
    }

    .user-info {
        overflow: hidden;
    }

    .user-name {
        display: block;
        font-weight: 600;
        font-size: 0.875rem;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .user-role {
        display: block;
        font-size: 0.75rem;
        color: var(--sidebar-text);
        opacity: 0.7;
    }

    /* Search */
    .sidebar-search {
        padding: 0.75rem 1rem;
    }

    .search-box {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        transition: all 0.2s ease;
    }

    .search-box:focus-within {
        background: rgba(255, 255, 255, 0.1);
        border-color: rgba(102, 126, 234, 0.5);
    }

    .search-box i {
        color: var(--sidebar-text);
        opacity: 0.6;
        font-size: 0.875rem;
    }

    .search-box input {
        flex: 1;
        background: transparent;
        border: none;
        outline: none;
        color: #fff;
        font-size: 0.8125rem;
    }

    .search-box input::placeholder {
        color: var(--sidebar-text);
        opacity: 0.5;
    }

    .search-box kbd {
        font-family: inherit;
        font-size: 0.625rem;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
        padding: 0.125rem 0.375rem;
        color: var(--sidebar-text);
        opacity: 0.6;
    }

    /* Navigation */
    .sidebar-nav {
        flex: 1;
        overflow-y: auto;
        padding: 0.5rem 0;
    }

    .sidebar-nav::-webkit-scrollbar {
        width: 4px;
    }

    .sidebar-nav::-webkit-scrollbar-track {
        background: transparent;
    }

    .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 2px;
    }

    .nav-section {
        margin-bottom: 0.5rem;
    }

    .nav-section-title {
        font-size: 0.6875rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--sidebar-text);
        opacity: 0.5;
        padding: 0.75rem 1rem 0.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .nav-section.collapsible .nav-section-title {
        cursor: pointer;
        transition: opacity 0.2s ease;
    }

    .nav-section.collapsible .nav-section-title:hover {
        opacity: 0.8;
    }

    .nav-section.collapsible .nav-section-title i {
        font-size: 0.75rem;
        transition: transform 0.2s ease;
    }

    .nav-section.collapsible.collapsed .nav-section-title i {
        transform: rotate(-90deg);
    }

    .nav-collapse {
        max-height: 500px;
        overflow: hidden;
        transition: max-height 0.3s ease;
    }

    .nav-section.collapsible.collapsed .nav-collapse {
        max-height: 0;
    }

    .nav-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.625rem 1rem;
        margin: 0.125rem 0.5rem;
        border-radius: 8px;
        color: var(--sidebar-text);
        text-decoration: none;
        font-size: 0.875rem;
        font-weight: 500;
        transition: all 0.2s ease;
        position: relative;
    }

    .nav-item i {
        font-size: 1.125rem;
        width: 1.5rem;
        text-align: center;
        opacity: 0.8;
    }

    .nav-item span {
        flex: 1;
    }

    .nav-item:hover {
        background: rgba(255, 255, 255, 0.06);
        color: #fff;
    }

    .nav-item.active {
        background: rgba(102, 126, 234, 0.15);
        color: #667eea;
    }

    .nav-item.active i {
        opacity: 1;
    }

    .nav-item.active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 3px;
        height: 60%;
        background: #667eea;
        border-radius: 0 2px 2px 0;
    }

    /* Badges */
    .nav-badge {
        font-size: 0.625rem;
        font-weight: 600;
        padding: 0.125rem 0.5rem;
        border-radius: 10px;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .nav-badge.new {
        background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
        color: #fff;
    }

    .nav-badge.pro {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: #fff;
    }

    .nav-badge.count {
        background: #ef4444;
        color: #fff;
        min-width: 1.25rem;
        text-align: center;
    }

    /* Footer */
    .sidebar-footer {
        border-top: 1px solid rgba(255, 255, 255, 0.06);
        padding: 0.5rem 0;
    }

    .sidebar-footer .nav-item.logout {
        color: #f87171;
    }

    .sidebar-footer .nav-item.logout:hover {
        background: rgba(248, 113, 113, 0.1);
        color: #ef4444;
    }

    /* Mobile Overlay */
    .sidebar-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .sidebar-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    /* Responsive */
    @media (max-width: 991.98px) {
        .sidebar {
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }
    }

    /* Light Theme Adjustments */
    [data-theme="light"] .sidebar {
        --sidebar-bg: #ffffff;
        --sidebar-text: #64748b;
        background: #ffffff;
        border-right: 1px solid #e2e8f0;
    }

    [data-theme="light"] .brand-link,
    [data-theme="light"] .user-name {
        color: #1e293b;
    }

    [data-theme="light"] .search-box {
        background: #f1f5f9;
        border-color: #e2e8f0;
    }

    [data-theme="light"] .search-box input {
        color: #1e293b;
    }

    [data-theme="light"] .nav-item:hover {
        background: #f1f5f9;
        color: #1e293b;
    }

    [data-theme="light"] .nav-item.active {
        background: rgba(102, 126, 234, 0.1);
    }

    [data-theme="light"] .sidebar-brand,
    [data-theme="light"] .sidebar-user,
    [data-theme="light"] .sidebar-footer {
        border-color: #e2e8f0;
    }

    [data-theme="light"] .status-dot {
        border-color: #ffffff;
    }
</style>
