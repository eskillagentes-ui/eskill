<?php

declare(strict_types=1);

// Determine active page based on URL
$currentPath = $_SERVER['REQUEST_URI'] ?? '/';
$isActive = function ($path) use ($currentPath) {
    if ($path === '/dashboard' && $currentPath === '/dashboard') return 'active';
    if ($path !== '/dashboard' && strpos($currentPath, $path) !== false) return 'active';
    return '';
};

// Ensure services are available
if (!class_exists('App\Services\UserService')) {
    require_once __DIR__ . '/../../Services/UserService.php';
}
if (!class_exists('App\Helpers\SessionHelper')) {
    require_once __DIR__ . '/../../Helpers/SessionHelper.php';
}

$userService = new App\Services\UserService();
$currentUser = $userService->getCurrentUser();

// Get ML accounts for account switcher
$userAccounts = [];
$activeAccountId = null;
if ($currentUser) {
    $userAccounts = App\Helpers\SessionHelper::getUserAccounts();
    $activeAccountId = App\Helpers\SessionHelper::getActiveAccountId();
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary no-print">
    <div class="container-fluid">
        <a class="navbar-brand" href="/dashboard">
            <i class="bi bi-shop me-2"></i>Mercado Livre Manager
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <?php if ($currentUser): ?>
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= $isActive('/dashboard') ?>" href="/dashboard">
                            <i class="bi bi-speedometer2 me-1"></i>Dashboard
                        </a>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= $isActive('/dashboard/orders') || $isActive('/dashboard/categories') ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-grid me-1"></i>Gestão
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="/dashboard/orders"><i class="bi bi-cart-check me-2"></i>Pedidos</a></li>
                            <li><a class="dropdown-item" href="/dashboard/categories"><i class="bi bi-diagram-3 me-2"></i>Categorias</a></li>
                        </ul>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= $isActive('/dashboard/analysis') || $isActive('/dashboard/advanced') || $isActive('/dashboard/audit') || $isActive('/brand-analysis') ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-graph-up me-1"></i>Análise
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="/dashboard/analysis"><i class="bi bi-search me-2"></i>Análise Geral</a></li>
                            <li><a class="dropdown-item" href="/dashboard/advanced"><i class="bi bi-bar-chart-fill me-2"></i>Relatórios Avançados</a></li>
                            <li><a class="dropdown-item" href="/brand-analysis"><i class="bi bi-award me-2"></i>Análise de Marca (AWA)</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="/dashboard/audit"><i class="bi bi-shield-check me-2"></i>Auditoria</a></li>
                        </ul>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= $isActive('/dashboard/seo-killer') || $isActive('/seo') ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-fire me-1"></i>SEO
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="/dashboard/seo-killer"><i class="bi bi-speedometer2 me-2"></i>SEO Killer</a></li>
                            <li><a class="dropdown-item" href="/dashboard/listing-visibility"><i class="bi bi-search-heart me-2"></i>Visibilidade ML</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="/dashboard/tech-sheet"><i class="bi bi-card-checklist me-2"></i>Ficha Técnica</a></li>
                            <li><a class="dropdown-item" href="/dashboard/seo-killer#competitor-spy"><i class="bi bi-binoculars me-2"></i>Espião SEO</a></li>
                            <li><a class="dropdown-item" href="/dashboard/seo-killer#performance-tracker"><i class="bi bi-graph-up-arrow me-2"></i>Performance Tracker</a></li>
                            <li><a class="dropdown-item" href="/dashboard/seo-killer#ab-testing"><i class="bi bi-clipboard-data me-2"></i>Testes A/B</a></li>
                            <li><a class="dropdown-item" href="/dashboard/seo-killer#ai-insights"><i class="bi bi-lightbulb me-2"></i>Insights SEO (IA)</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="/research"><i class="bi bi-search me-2"></i>Deep Research</a></li>
                        </ul>
                    </li>
                </ul>

                <ul class="navbar-nav ms-auto d-flex align-items-center">
                    <?php if (count($userAccounts) > 0): ?>
                        <li class="nav-item dropdown me-3">
                            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="accountDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="bi bi-shop-window me-1"></i>
                                <span class="d-none d-md-inline" id="activeAccountName">
                                    <?php
                                    $activeAccount = array_filter($userAccounts, fn($a) => $a['id'] == $activeAccountId);
                                    $activeAccount = reset($activeAccount);
                                    echo htmlspecialchars($activeAccount['nickname'] ?? 'Selecionar Conta');
                                    ?>
                                </span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="accountDropdown">
                                <li>
                                    <h6 class="dropdown-header"><i class="bi bi-people me-2"></i>Contas Mercado Livre</h6>
                                </li>
                                <?php foreach ($userAccounts as $account): ?>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center justify-content-between <?= $account['id'] == $activeAccountId ? 'active' : '' ?>"
                                            href="#"
                                            onclick="switchAccount(<?= $account['id'] ?>); return false;">
                                            <span>
                                                <i class="bi bi-person-badge me-2"></i><?= htmlspecialchars($account['nickname'] ?? '') ?>
                                            </span>
                                            <?php if ($account['id'] == $activeAccountId): ?>
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                            <?php endif; ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item" href="/auth/authorize"><i class="bi bi-plus-circle me-2"></i>Vincular Nova Conta</a></li>
                            </ul>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item me-2">
                        <a href="#" class="nav-link" id="theme-toggle" title="Alternar Tema">
                            <i class="bi bi-moon-stars"></i>
                        </a>
                    </li>
                    <li class="nav-item me-2">
                        <?php
                        if (file_exists(__DIR__ . '/notifications_bell.php')) {
                            require __DIR__ . '/notifications_bell.php';
                        }
                        ?>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i> <?= htmlspecialchars($currentUser['name']) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="/dashboard/profile"><i class="bi bi-person me-2"></i>Meu Perfil</a></li>
                            <li><a class="dropdown-item" href="/dashboard/accounts"><i class="bi bi-person-badge me-2"></i>Contas ML</a></li>
                            <li><a class="dropdown-item" href="/dashboard/settings"><i class="bi bi-gear me-2"></i>Configurações</a></li>
                            <li><a class="dropdown-item" href="/dashboard/activities"><i class="bi bi-clock-history me-2"></i>Atividades</a></li>
                            <li><a class="dropdown-item" href="/dashboard/api-tokens"><i class="bi bi-key me-2"></i>API Tokens</a></li>
                            <li><a class="dropdown-item" href="/dashboard/help"><i class="bi bi-question-circle me-2"></i>Ajuda</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li>
                                <form method="POST" action="/auth/logout" class="m-0">
                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="dropdown-item">
                                        <i class="bi bi-box-arrow-right me-2"></i>Sair
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>
            <?php else: ?>
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/auth/login">Login</a>
                    </li>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>

<?php if ($currentUser && count($userAccounts) > 1): ?>
    <script nonce="<?= CSP_NONCE ?>">
        async function switchAccount(accountId) {
            try {
                const data = await requestJson('/api/dashboard/switch-account', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
                    },
                    body: JSON.stringify({
                        account_id: accountId
                    })
                });

                if (data.success) {
                    // Recarregar a página para atualizar os dados
                    window.location.reload();
                } else {
                    alert('Erro ao trocar conta: ' + (data.error || 'Erro desconhecido'));
                }
            } catch (error) {
                console.error('Erro ao trocar conta:', error);
                alert('Erro ao trocar conta. Tente novamente.');
            }
        }
    </script>
<?php endif; ?>
