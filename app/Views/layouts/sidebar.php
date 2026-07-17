<?php

declare(strict_types=1);

/**
 * Sidebar layout - Mission Control Style
 * Menu de navegação profissional com todas as funcionalidades
 */
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$userName = $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Usuário';

// Definição dos grupos de menu - Estrutura profissional completa
$menuGroups = [
    'MAIN' => [
        'label' => 'Principal',
        'icon' => 'bi-house',
        'items' => [
            ['path' => '/dashboard', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
            ['path' => '/dashboard/analytics', 'icon' => 'bi-bar-chart-line', 'label' => 'Analytics BI'],
            ['path' => '/dashboard/ai-center', 'icon' => 'bi-cpu', 'label' => 'Central de IA', 'badge' => 'NEW'],
        ]
    ],
    'SALES' => [
        'label' => 'Vendas',
        'icon' => 'bi-cart',
        'items' => [
            ['path' => '/dashboard/orders', 'icon' => 'bi-box-seam', 'label' => 'Pedidos'],
            ['path' => '/dashboard/customers', 'icon' => 'bi-people', 'label' => 'Clientes'],
            ['path' => '/dashboard/messages', 'icon' => 'bi-chat-dots', 'label' => 'Mensagens'],
            ['path' => '/dashboard/questions', 'icon' => 'bi-question-circle', 'label' => 'Perguntas'],
        ]
    ],
    'CATALOG' => [
        'label' => 'Catálogo',
        'icon' => 'bi-grid',
        'items' => [
            ['path' => '/dashboard/items', 'icon' => 'bi-tags', 'label' => 'Anúncios'],
            ['path' => '/dashboard/items/bulk', 'icon' => 'bi-pencil-square', 'label' => 'Editor em Lote'],
            ['path' => '/dashboard/catalog/clone', 'icon' => 'bi-files', 'label' => 'Clonador'],
            ['path' => '/dashboard/catalog/clone-wizard', 'icon' => 'bi-magic', 'label' => 'Wizard Concorrente', 'badge' => 'NOVO'],
            ['path' => '/dashboard/catalog/clone-realtime', 'icon' => 'bi-broadcast', 'label' => 'Clone Real-time', 'badge' => 'NEW'],
            ['path' => '/dashboard/catalog/clone-analytics', 'icon' => 'bi-bar-chart', 'label' => 'Clone Analytics'],
            ['path' => '/dashboard/catalog/clone-compliance', 'icon' => 'bi-shield-check', 'label' => 'Auditoria'],
            ['path' => '/dashboard/catalog/clone-widget-embed', 'icon' => 'bi-code-slash', 'label' => 'Widget Embed'],
            ['path' => '/dashboard/catalog/clone-metrics', 'icon' => 'bi-graph-up', 'label' => 'Métricas Clone'],
            ['path' => '/dashboard/catalog/competition', 'icon' => 'bi-trophy', 'label' => 'Concorrência'],
        ]
    ],
    'SEO' => [
        'label' => 'SEO & Otimização',
        'icon' => 'bi-search',
        'items' => [
            ['path' => '/dashboard/seo-killer', 'icon' => 'bi-lightning-charge', 'label' => 'SEO Killer', 'badge' => 'PRO'],
            ['path' => '/dashboard/tech-sheet', 'icon' => 'bi-list-check', 'label' => 'Ficha Técnica'],
            ['path' => '/dashboard/listing-visibility', 'icon' => 'bi-search-heart', 'label' => 'Visibilidade ML'],
            ['path' => '/dashboard/quality', 'icon' => 'bi-shield-check', 'label' => 'Validação Anúncios'],
            ['path' => '/dashboard/account-health', 'icon' => 'bi-heart-pulse', 'label' => 'Diagnóstico Conta'],
            ['path' => '/dashboard/seo-killer#ai-insights', 'icon' => 'bi-magic', 'label' => 'Insights SEO (IA)'],
            ['path' => '/research', 'icon' => 'bi-zoom-in', 'label' => 'Deep Research'],
        ]
    ],
    'MARKETING' => [
        'label' => 'Marketing',
        'icon' => 'bi-megaphone',
        'items' => [
            ['path' => '/dashboard/ads', 'icon' => 'bi-badge-ad', 'label' => 'Mercado Ads'],
            ['path' => '/dashboard/marketing/promotions', 'icon' => 'bi-percent', 'label' => 'Promoções'],
            ['path' => '/dashboard/competitors', 'icon' => 'bi-binoculars', 'label' => 'Concorrentes'],
            ['path' => '/dashboard/brand-analysis', 'icon' => 'bi-award', 'label' => 'Análise de Marca'],
            ['path' => '/brand-search', 'icon' => 'bi-search-heart', 'label' => 'Pesquisa de vendedores'],
            ['path' => '/dashboard/awa-sellers', 'icon' => 'bi-shop-window', 'label' => 'AWA Sellers', 'badge' => 'NEW'],
        ]
    ],
    'LOGISTICS' => [
        'label' => 'Logística',
        'icon' => 'bi-truck',
        'items' => [
            ['path' => '/dashboard/shipping', 'icon' => 'bi-box-arrow-up-right', 'label' => 'Expedição'],
            ['path' => '/dashboard/picking', 'icon' => 'bi-clipboard-check', 'label' => 'Picking'],
            ['path' => '/dashboard/logistics/flex', 'icon' => 'bi-bicycle', 'label' => 'Flex'],
            ['path' => '/dashboard/logistics/full', 'icon' => 'bi-building', 'label' => 'Full'],
        ]
    ],
    'FINANCE' => [
        'label' => 'Financeiro',
        'icon' => 'bi-currency-dollar',
        'items' => [
            ['path' => '/dashboard/pricing', 'icon' => 'bi-calculator', 'label' => 'Precificador', 'badge' => 'NEW'],
            ['path' => '/dashboard/financials', 'icon' => 'bi-file-earmark-bar-graph', 'label' => 'DRE / P&L'],
            ['path' => '/dashboard/financials/conciliation', 'icon' => 'bi-bank', 'label' => 'Conciliação'],
            ['path' => '/dashboard/reports', 'icon' => 'bi-file-text', 'label' => 'Relatórios'],
        ]
    ],
    'SUPPORT' => [
        'label' => 'Atendimento',
        'icon' => 'bi-headset',
        'items' => [
            ['path' => '/dashboard/claims', 'icon' => 'bi-exclamation-triangle', 'label' => 'Reclamações'],
            ['path' => '/dashboard/returns', 'icon' => 'bi-arrow-return-left', 'label' => 'Devoluções'],
            ['path' => '/dashboard/whatsapp', 'icon' => 'bi-whatsapp', 'label' => 'WhatsApp'],
        ]
    ],
    'INTEGRATIONS' => [
        'label' => 'Integrações',
        'icon' => 'bi-plugin',
        'items' => [
            ['path' => '/dashboard/accounts', 'icon' => 'bi-person-badge', 'label' => 'Contas ML'],
            ['path' => '/dashboard/shopee', 'icon' => 'bi-shop', 'label' => 'Shopee'],
            ['path' => '/dashboard/ean', 'icon' => 'bi-upc-scan', 'label' => 'Gestão EAN'],
        ]
    ],
    'SYSTEM' => [
        'label' => 'Sistema',
        'icon' => 'bi-gear',
        'items' => [
            ['path' => '/dashboard/openspec', 'icon' => 'bi-file-earmark-code', 'label' => 'OpenSpec', 'badge' => 'NEW'],
            ['path' => '/dashboard/settings', 'icon' => 'bi-sliders', 'label' => 'Configurações'],
            ['path' => '/dashboard/settings/users', 'icon' => 'bi-people-fill', 'label' => 'Usuários'],
            ['path' => '/dashboard/logs', 'icon' => 'bi-journal-code', 'label' => 'Logs'],
            ['path' => '/dashboard/cache', 'icon' => 'bi-hdd-stack', 'label' => 'Cache'],
            ['path' => '/dashboard/health', 'icon' => 'bi-heart-pulse', 'label' => 'Health Check'],
        ]
    ],
];

// Função helper para verificar se o item está ativo
function isMenuActive(string $currentPath, string $itemPath): bool
{
    if ($itemPath === '/dashboard') {
        return $currentPath === '/dashboard' || $currentPath === '/dashboard/';
    }
    return strpos($currentPath, $itemPath) === 0;
}
?>
<nav id="sidebar" class="bg-dark text-white d-flex flex-column flex-shrink-0" style="width: 280px; min-height: 100vh;">
    <!-- Brand -->
    <div class="p-3 border-bottom border-secondary">
        <a href="/dashboard" class="d-flex align-items-center text-white text-decoration-none">
            <i class="bi bi-shop fs-4 me-2"></i>
            <span class="fs-5 fw-bold">ML Manager</span>
            <span class="badge bg-success ms-2" style="font-size: 0.6rem;">PRO</span>
        </a>
    </div>

    <!-- Menu Scrollable -->
    <div class="overflow-auto flex-grow-1 p-2" style="max-height: calc(100vh - 180px);">
        <?php foreach ($menuGroups as $groupKey => $group): ?>
            <div class="mb-2">
                <div class="d-flex align-items-center px-3 py-2 text-muted" style="font-size: 0.7rem;">
                    <i class="<?= $group['icon'] ?? 'bi-folder' ?> me-2"></i>
                    <span class="text-uppercase fw-bold letter-spacing-1"><?= $group['label'] ?></span>
                </div>
                <ul class="nav nav-pills flex-column">
                    <?php foreach ($group['items'] as $item):
                        $isActive = isMenuActive($currentPath, $item['path']);
                    ?>
                        <li class="nav-item">
                            <a href="<?= $item['path'] ?>"
                                class="nav-link py-2 px-3 d-flex align-items-center <?= $isActive ? 'active bg-primary' : 'text-white-50' ?>"
                                style="font-size: 0.85rem; border-radius: 8px; margin: 1px 8px;">
                                <i class="<?= $item['icon'] ?> me-2" style="width: 18px;"></i>
                                <span><?= $item['label'] ?></span>
                                <?php if (isset($item['badge'])): ?>
                                    <span class="badge <?= $item['badge'] === 'NEW' ? 'bg-success' : 'bg-warning text-dark' ?> ms-auto" style="font-size: 0.55rem;"><?= $item['badge'] ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- User Section -->
    <div class="border-top border-secondary p-3">
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" id="dropdownUser1" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                    <i class="bi bi-person-fill"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-semibold" style="font-size: 0.9rem;"><?= htmlspecialchars($userName) ?></div>
                    <small class="text-muted" style="font-size: 0.7rem;">Administrador</small>
                </div>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark text-small shadow w-100" aria-labelledby="dropdownUser1">
                <li><a class="dropdown-item py-2" href="/dashboard/profile"><i class="bi bi-person me-2"></i>Meu Perfil</a></li>
                <li><a class="dropdown-item py-2" href="/dashboard/accounts"><i class="bi bi-person-badge me-2"></i>Contas ML</a></li>
                <li><a class="dropdown-item py-2" href="/dashboard/activities"><i class="bi bi-clock-history me-2"></i>Atividades</a></li>
                <li><a class="dropdown-item py-2" href="/dashboard/api-tokens"><i class="bi bi-key me-2"></i>API Tokens</a></li>
                <li>
                    <hr class="dropdown-divider">
                </li>
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
</nav>

<style>
    #sidebar .nav-link:hover:not(.active) {
        background-color: rgba(255, 255, 255, 0.1);
    }

    #sidebar .nav-link.active {
        font-weight: 500;
    }

    #sidebar .letter-spacing-1 {
        letter-spacing: 0.5px;
    }
</style>
