<?php

declare(strict_types=1);

/**
 * Dashboard Principal - Design Moderno e Profissional
 * Visão geral completa do ecossistema de vendas
 */

$pageTitle = 'Dashboard';
$currentDate = date('d/m/Y');
$greeting = (date('H') < 12) ? 'Bom dia' : ((date('H') < 18) ? 'Boa tarde' : 'Boa noite');
$userName = htmlspecialchars($_SESSION['user_name'] ?? 'Usuário');
?>

<style>
    /* Dashboard Hero */
    .dashboard-hero {
        background: var(--primary-gradient);
        border-radius: 16px;
        padding: 2rem;
        color: #fff;
        position: relative;
        overflow: hidden;
        margin-bottom: 1.5rem;
    }

    .dashboard-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 400px;
        height: 400px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
    }

    .dashboard-hero::after {
        content: '';
        position: absolute;
        bottom: -30%;
        right: 20%;
        width: 200px;
        height: 200px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 50%;
    }

    .hero-content {
        position: relative;
        z-index: 1;
    }

    .hero-greeting {
        font-size: 0.875rem;
        opacity: 0.9;
        margin-bottom: 0.25rem;
    }

    .hero-title {
        font-size: 1.75rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .hero-subtitle {
        font-size: 0.9rem;
        opacity: 0.85;
    }

    .hero-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: 1.5rem;
        flex-wrap: wrap;
    }

    .hero-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.625rem 1.25rem;
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 10px;
        color: #fff;
        font-weight: 500;
        font-size: 0.875rem;
        text-decoration: none;
        transition: all 0.2s ease;
        backdrop-filter: blur(10px);
    }

    .hero-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        color: #fff;
        transform: translateY(-2px);
    }

    .hero-btn-primary {
        background: #fff;
        color: #667eea;
        border-color: #fff;
    }

    .hero-btn-primary:hover {
        background: #f8fafc;
        color: #5a67d8;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    @media (max-width: 1199.98px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 575.98px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Metric Card */
    .metric-card {
        background: #fff;
        border-radius: 16px;
        padding: 1.5rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .metric-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
    }

    .metric-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--metric-color, var(--primary-gradient));
    }

    .metric-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        margin-bottom: 1rem;
    }

    .metric-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        color: #fff;
    }

    .metric-icon.primary {
        background: var(--primary-gradient);
    }

    .metric-icon.success {
        background: var(--success-gradient);
    }

    .metric-icon.warning {
        background: var(--warning-gradient);
    }

    .metric-icon.danger {
        background: var(--danger-gradient);
    }

    .metric-icon.info {
        background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
    }

    .metric-change {
        display: flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.25rem 0.5rem;
        border-radius: 50px;
    }

    .metric-change.up {
        background: rgba(34, 197, 94, 0.1);
        color: #16a34a;
    }

    .metric-change.down {
        background: rgba(239, 68, 68, 0.1);
        color: #dc2626;
    }

    .metric-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: #1e293b;
        line-height: 1.2;
        margin-bottom: 0.25rem;
    }

    .metric-label {
        font-size: 0.8125rem;
        color: #64748b;
        font-weight: 500;
    }

    .metric-card[data-color="primary"]::before {
        --metric-color: var(--primary-gradient);
    }

    .metric-card[data-color="success"]::before {
        --metric-color: var(--success-gradient);
    }

    .metric-card[data-color="warning"]::before {
        --metric-color: var(--warning-gradient);
    }

    .metric-card[data-color="danger"]::before {
        --metric-color: var(--danger-gradient);
    }

    .metric-card[data-color="info"]::before {
        --metric-color: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
    }

    /* Content Sections */
    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1rem;
    }

    .section-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .section-title i {
        color: #667eea;
    }

    /* Account Cards */
    .accounts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1rem;
    }

    .account-card {
        background: #fff;
        border-radius: 16px;
        padding: 1.25rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        border: 2px solid transparent;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .account-card:hover {
        border-color: #667eea;
        box-shadow: 0 8px 24px rgba(102, 126, 234, 0.15);
    }

    .account-card.active {
        border-color: #22c55e;
        background: linear-gradient(135deg, rgba(34, 197, 94, 0.05) 0%, rgba(22, 163, 74, 0.02) 100%);
    }

    .account-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .account-avatar {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        object-fit: cover;
        background: #f1f5f9;
    }

    .account-info {
        flex: 1;
        min-width: 0;
    }

    .account-name {
        font-weight: 600;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .account-email {
        font-size: 0.75rem;
        color: #64748b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .account-status {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        font-size: 0.6875rem;
        font-weight: 600;
        padding: 0.25rem 0.625rem;
        border-radius: 50px;
        text-transform: uppercase;
    }

    .account-status.active {
        background: rgba(34, 197, 94, 0.1);
        color: #16a34a;
    }

    .account-status.inactive {
        background: rgba(100, 116, 139, 0.1);
        color: #64748b;
    }

    .account-badges {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.5rem;
        flex-wrap: wrap;
    }

    .account-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        font-size: 0.6875rem;
        font-weight: 600;
        padding: 0.25rem 0.625rem;
        border-radius: 50px;
        text-transform: uppercase;
    }

    .badge-connected {
        background: rgba(34, 197, 94, 0.1);
        color: #16a34a;
    }

    .badge-expiring {
        background: rgba(245, 158, 11, 0.1);
        color: #d97706;
    }

    .badge-disconnected {
        background: rgba(239, 68, 68, 0.1);
        color: #dc2626;
    }

    .badge-unknown {
        background: rgba(148, 163, 184, 0.1);
        color: #64748b;
    }

    .badge-synced {
        background: rgba(59, 130, 246, 0.1);
        color: #2563eb;
    }

    .badge-unsynced {
        background: rgba(239, 68, 68, 0.1);
        color: #dc2626;
    }

    .badge-syncing {
        background: rgba(14, 165, 233, 0.1);
        color: #0ea5e9;
    }

    .account-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    .account-actions .btn {
        flex: 1;
    }

    .account-card.pending {
        opacity: 0.7;
        pointer-events: none;
    }

    .account-metrics {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
        padding-top: 0.75rem;
        border-top: 1px solid #f1f5f9;
    }

    .account-metric {
        text-align: center;
    }

    .account-metric-value {
        font-size: 1rem;
        font-weight: 700;
        color: #1e293b;
    }

    .account-metric-label {
        font-size: 0.6875rem;
        color: #94a3b8;
        text-transform: uppercase;
    }

    /* Charts Section */
    .chart-card {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        height: 100%;
    }

    .chart-header {
        padding: 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .chart-title {
        font-weight: 600;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .chart-title i {
        color: #667eea;
    }

    .chart-body {
        padding: 1.25rem;
    }

    /* Orders List */
    .orders-list {
        max-height: 360px;
        overflow-y: auto;
    }

    .order-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.875rem;
        border-radius: 10px;
        transition: background 0.2s ease;
    }

    .order-item:hover {
        background: #f8fafc;
    }

    .order-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
    }

    .order-icon.paid {
        background: rgba(34, 197, 94, 0.1);
        color: #16a34a;
    }

    .order-icon.pending {
        background: rgba(245, 158, 11, 0.1);
        color: #d97706;
    }

    .order-icon.shipped {
        background: rgba(59, 130, 246, 0.1);
        color: #2563eb;
    }

    .order-icon.cancelled {
        background: rgba(239, 68, 68, 0.1);
        color: #dc2626;
    }

    .order-content {
        flex: 1;
        min-width: 0;
    }

    .order-id {
        font-weight: 600;
        color: #1e293b;
        font-size: 0.875rem;
    }

    .order-date {
        font-size: 0.75rem;
        color: #94a3b8;
    }

    .order-amount {
        font-weight: 600;
        color: #1e293b;
    }

    /* Reputation Card */
    .reputation-card {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        padding: 1.5rem;
        text-align: center;
    }

    .reputation-score {
        position: relative;
        width: 140px;
        height: 140px;
        margin: 0 auto 1.5rem;
    }

    .reputation-circle {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: conic-gradient(#22c55e 0% var(--score-percent, 85%),
                #e2e8f0 var(--score-percent, 85%) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
    }

    .reputation-circle::before {
        content: '';
        position: absolute;
        width: 110px;
        height: 110px;
        background: #fff;
        border-radius: 50%;
    }

    .reputation-value {
        position: relative;
        z-index: 1;
        font-size: 2rem;
        font-weight: 700;
        color: #22c55e;
    }

    .reputation-label {
        font-size: 0.75rem;
        color: #64748b;
        margin-bottom: 0.5rem;
    }

    .reputation-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        color: #fff;
        font-weight: 600;
        font-size: 0.75rem;
        border-radius: 50px;
        text-transform: uppercase;
    }

    .reputation-metrics {
        margin-top: 1.5rem;
    }

    .rep-metric {
        margin-bottom: 1rem;
    }

    .rep-metric:last-child {
        margin-bottom: 0;
    }

    .rep-metric-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
        font-size: 0.8125rem;
    }

    .rep-metric-name {
        color: #64748b;
    }

    .rep-metric-value {
        font-weight: 600;
        color: #1e293b;
    }

    .rep-progress {
        height: 6px;
        background: #e2e8f0;
        border-radius: 3px;
        overflow: hidden;
    }

    .rep-progress-bar {
        height: 100%;
        border-radius: 3px;
        transition: width 0.6s ease;
    }

    .rep-progress-bar.success {
        background: var(--success-gradient);
    }

    .rep-progress-bar.warning {
        background: var(--warning-gradient);
    }

    .rep-progress-bar.danger {
        background: var(--danger-gradient);
    }

    /* Quick Actions */
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 1rem;
    }

    .quick-action {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1rem 1.25rem;
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        text-decoration: none;
        color: inherit;
        transition: all 0.2s ease;
    }

    .quick-action:hover {
        border-color: #667eea;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        transform: translateY(-2px);
        color: inherit;
    }

    .quick-action-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        color: #fff;
        flex-shrink: 0;
    }

    .quick-action-content {
        flex: 1;
        min-width: 0;
    }

    .quick-action-title {
        font-weight: 600;
        color: #1e293b;
        font-size: 0.9375rem;
    }

    .quick-action-desc {
        font-size: 0.75rem;
        color: #64748b;
    }

    /* Empty State */
    .empty-state-card {
        background: #fff;
        border-radius: 16px;
        padding: 3rem;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .empty-state-icon {
        width: 80px;
        height: 80px;
        margin: 0 auto 1.5rem;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .empty-state-icon i {
        font-size: 2rem;
        color: #667eea;
    }

    .empty-state-title {
        font-size: 1.25rem;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 0.5rem;
    }

    .empty-state-desc {
        color: #64748b;
        margin-bottom: 1.5rem;
        max-width: 400px;
        margin-left: auto;
        margin-right: auto;
    }

    /* Skeleton Loading */
    .skeleton-metric {
        background: #fff;
        border-radius: 16px;
        padding: 1.5rem;
    }

    .skeleton-value {
        height: 2rem;
        width: 60%;
        margin-bottom: 0.5rem;
    }

    .skeleton-label {
        height: 1rem;
        width: 40%;
    }

    /* Dark Theme */
    [data-theme="dark"] .dashboard-hero {
        background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
    }

    [data-theme="dark"] .metric-card,
    [data-theme="dark"] .account-card,
    [data-theme="dark"] .chart-card,
    [data-theme="dark"] .reputation-card,
    [data-theme="dark"] .quick-action,
    [data-theme="dark"] .empty-state-card {
        background: #1e293b;
        border-color: #334155;
    }

    [data-theme="dark"] .metric-value,
    [data-theme="dark"] .metric-label,
    [data-theme="dark"] .account-name,
    [data-theme="dark"] .chart-title,
    [data-theme="dark"] .order-id,
    [data-theme="dark"] .order-amount,
    [data-theme="dark"] .quick-action-title,
    [data-theme="dark"] .rep-metric-value,
    [data-theme="dark"] .section-title,
    [data-theme="dark"] .empty-state-title {
        color: #f1f5f9;
    }

    [data-theme="dark"] .account-email,
    [data-theme="dark"] .order-date,
    [data-theme="dark"] .quick-action-desc,
    [data-theme="dark"] .rep-metric-name,
    [data-theme="dark"] .reputation-label,
    [data-theme="dark"] .empty-state-desc {
        color: #94a3b8;
    }

    [data-theme="dark"] .chart-header,
    [data-theme="dark"] .account-metrics {
        border-color: #334155;
    }

    [data-theme="dark"] .order-item:hover {
        background: #334155;
    }

    [data-theme="dark"] .reputation-circle::before {
        background: #1e293b;
    }

    [data-theme="dark"] .rep-progress {
        background: #334155;
    }
</style>

<!-- Dashboard Hero -->
<div class="dashboard-hero">
    <div class="hero-content">
        <p class="hero-greeting"><?= $greeting ?>, <?= $userName ?>!</p>
        <h1 class="hero-title">Bem-vindo ao seu Dashboard</h1>
        <p class="hero-subtitle">Acompanhe suas vendas, gerencie pedidos e otimize seu negócio no Mercado Livre</p>

        <div class="hero-actions">
            <a href="/auth/authorize" class="hero-btn hero-btn-primary">
                <i class="bi bi-plus-lg"></i>
                Conectar Conta
            </a>
            <a href="/dashboard/items" class="hero-btn">
                <i class="bi bi-box-seam"></i>
                Meus Produtos
            </a>
            <a href="/dashboard/orders" class="hero-btn">
                <i class="bi bi-receipt"></i>
                Ver Pedidos
            </a>
        </div>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid" id="stats-grid">
    <!-- Loading skeletons -->
    <div class="metric-card skeleton-metric">
        <div class="skeleton skeleton-value"></div>
        <div class="skeleton skeleton-label"></div>
    </div>
    <div class="metric-card skeleton-metric">
        <div class="skeleton skeleton-value"></div>
        <div class="skeleton skeleton-label"></div>
    </div>
    <div class="metric-card skeleton-metric">
        <div class="skeleton skeleton-value"></div>
        <div class="skeleton skeleton-label"></div>
    </div>
    <div class="metric-card skeleton-metric">
        <div class="skeleton skeleton-value"></div>
        <div class="skeleton skeleton-label"></div>
    </div>
</div>

<!-- Accounts Section -->
<div class="section-header">
    <h2 class="section-title">
        <i class="bi bi-people"></i>
        Contas Conectadas
    </h2>
    <a href="/auth/authorize" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>
        Nova Conta
    </a>
</div>

<div class="accounts-grid mb-4" id="accounts-grid">
    <!-- Loading state -->
    <div class="account-card">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="skeleton" style="width: 48px; height: 48px; border-radius: 12px;"></div>
            <div class="flex-1">
                <div class="skeleton mb-2" style="height: 1rem; width: 120px;"></div>
                <div class="skeleton" style="height: 0.75rem; width: 160px;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
    <!-- Orders Chart -->
    <div class="col-lg-4">
        <div class="chart-card">
            <div class="chart-header">
                <span class="chart-title">
                    <i class="bi bi-pie-chart"></i>
                    Pedidos por Status
                </span>
            </div>
            <div class="chart-body">
                <canvas id="ordersChart" height="220"></canvas>
            </div>
        </div>
    </div>

    <!-- Revenue Chart -->
    <div class="col-lg-4">
        <div class="chart-card">
            <div class="chart-header">
                <span class="chart-title">
                    <i class="bi bi-graph-up"></i>
                    Receita (7 dias)
                </span>
            </div>
            <div class="chart-body">
                <canvas id="revenueChart" height="220"></canvas>
            </div>
        </div>
    </div>

    <!-- Reputation -->
    <div class="col-lg-4">
        <div class="reputation-card" id="reputation-card">
            <p class="reputation-label">Sua Reputação</p>
            <div class="reputation-score">
                <div class="reputation-circle" style="--score-percent: 0%">
                    <span class="reputation-value" id="rep-score">--</span>
                </div>
            </div>
            <div class="reputation-badge" id="rep-badge">
                <i class="bi bi-award"></i>
                <span>Carregando...</span>
            </div>

            <div class="reputation-metrics">
                <div class="rep-metric">
                    <div class="rep-metric-header">
                        <span class="rep-metric-name">Reclamações</span>
                        <span class="rep-metric-value" id="rep-claims">--%</span>
                    </div>
                    <div class="rep-progress">
                        <div class="rep-progress-bar success" id="bar-claims" style="width: 0%"></div>
                    </div>
                </div>
                <div class="rep-metric">
                    <div class="rep-metric-header">
                        <span class="rep-metric-name">Cancelamentos</span>
                        <span class="rep-metric-value" id="rep-cancel">--%</span>
                    </div>
                    <div class="rep-progress">
                        <div class="rep-progress-bar success" id="bar-cancel" style="width: 0%"></div>
                    </div>
                </div>
                <div class="rep-metric">
                    <div class="rep-metric-header">
                        <span class="rep-metric-name">Atrasos</span>
                        <span class="rep-metric-value" id="rep-delay">--%</span>
                    </div>
                    <div class="rep-progress">
                        <div class="rep-progress-bar success" id="bar-delay" style="width: 0%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Orders -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="chart-card">
            <div class="chart-header">
                <span class="chart-title">
                    <i class="bi bi-clock-history"></i>
                    Pedidos Recentes
                </span>
                <a href="/dashboard/orders" class="btn btn-sm btn-outline-primary">Ver Todos</a>
            </div>
            <div class="chart-body p-0">
                <div class="orders-list" id="orders-list">
                    <!-- Loading -->
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="chart-card h-100">
            <div class="chart-header">
                <span class="chart-title">
                    <i class="bi bi-lightning"></i>
                    Ações Rápidas
                </span>
            </div>
            <div class="chart-body">
                <div class="d-grid gap-2">
                    <a href="/dashboard/items/create" class="quick-action">
                        <div class="quick-action-icon" style="background: var(--primary-gradient);">
                            <i class="bi bi-plus-lg"></i>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Novo Anúncio</div>
                            <div class="quick-action-desc">Criar produto</div>
                        </div>
                    </a>
                    <a href="/dashboard/seo-killer" class="quick-action">
                        <div class="quick-action-icon" style="background: var(--success-gradient);">
                            <i class="bi bi-fire"></i>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">SEO Killer</div>
                            <div class="quick-action-desc">Otimizar anúncios</div>
                        </div>
                    </a>
                    <a href="/dashboard/marketing/promotions" class="quick-action">
                        <div class="quick-action-icon" style="background: var(--warning-gradient);">
                            <i class="bi bi-tag"></i>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Promoções</div>
                            <div class="quick-action-desc">Criar ofertas</div>
                        </div>
                    </a>
                    <a href="/dashboard/messages" class="quick-action">
                        <div class="quick-action-icon" style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);">
                            <i class="bi bi-chat-dots"></i>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Mensagens</div>
                            <div class="quick-action-desc">Responder clientes</div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>


<script nonce="<?= defined('CSP_NONCE') ? CSP_NONCE : ($_SESSION['csp_nonce'] ?? '') ?>" src="/js/dashboard-index.js?v=<?= @filemtime(__DIR__ . '/../../../public/js/dashboard-index.js') ?: time() ?>"></script>
